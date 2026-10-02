<?php
// FUB: importer Portalu Wiedzy do Moodle 5.2.x.
// Odtwarza strukturę folderów jako kategorie, a dokumenty jako kursy z obowiązkowym potwierdzeniem zapoznania.

define('CLI_SCRIPT', true);

$moodledir = getenv('MOODLE_DIR') ?: '/var/www/moodle';
$configfile = rtrim($moodledir, '/') . '/config.php';
if (!is_file($configfile)) {
    fwrite(STDERR, "Brak pliku Moodle config.php: {$configfile}\n");
    exit(1);
}

require($configfile);

require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
require_once($CFG->dirroot . '/enrol/cohort/locallib.php');
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->dirroot . '/mod/resource/lib.php');
require_once($CFG->dirroot . '/mod/choice/lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'source' => '',
        'dry-run' => false,
        'repair-grouping' => false,
        'force-repair' => false,
    ],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error("Nierozpoznane opcje:\n  " . implode("\n  ", $unrecognized));
}

if ($options['help']) {
    echo <<<TXT
FUB – importer Portalu Wiedzy

Opcje:
  --source=/sciezka/do/rozpakowanego/portalu
      Katalog źródłowy zawierający strukturę folderów i dokumenty.
  --dry-run
      Tylko pokazuje plan importu, bez zmian w Moodle.
  --repair-grouping
      Po dodaniu aneksu do dokumentu głównego usuwa stary, osobny kurs aneksu.
      Kurs z istniejącymi potwierdzeniami użytkowników nie zostanie usunięty.
  --force-repair
      Pozwala usunąć osobny kurs aneksu mimo istniejących potwierdzeń.
      Używaj wyłącznie świadomie.
  -h, --help
      Pomoc.

Zasady:
  - główna kategoria Moodle: Portal Wiedzy,
  - foldery pośrednie stają się kategoriami,
  - każdy dokument staje się osobnym kursem,
  - wyjątek: gdy folder zawiera dokładnie jeden dokument główny i pliki zaczynające się od \"Aneks\", aneksy są dołączane do kursu dokumentu głównego,
  - foldery zawsze pozostają kategoriami Moodle,
  - każdy kurs ma potwierdzenie "Zapoznałem/-am się",
  - kurs jest synchronizowany z kohortą FUB-PORTAL-WIEDZY.

TXT;
    exit(0);
}

$source = trim((string)$options['source']);
if ($source === '') {
    cli_error('Podaj --source=/sciezka/do/rozpakowanego/portalu');
}
$source = rtrim(realpath($source) ?: $source, DIRECTORY_SEPARATOR);
if (!is_dir($source)) {
    cli_error("Katalog źródłowy nie istnieje: {$source}");
}

$dryrun = !empty($options['dry-run']);
$repairgrouping = !empty($options['repair-grouping']);
$forcerepair = !empty($options['force-repair']);

const FUB_PW_ROOT_IDNUMBER = 'FUB-PW';
const FUB_PW_COHORT_IDNUMBER = 'FUB-PORTAL-WIEDZY';
const FUB_PW_COHORT_NAME = 'Portal Wiedzy – wszyscy zarejestrowani';

$allowedextensions = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'odt', 'ods', 'odp', 'rtf', 'txt', 'csv',
];

$admin = get_admin();
if (!$admin || empty($admin->id)) {
    cli_error('Nie udało się ustalić konta administratora Moodle.');
}
\core\session\manager::set_user($admin);

function fub_pw_clean_name(string $name): string {
    $name = preg_replace('/\.[^.]+$/u', '', $name);
    $name = preg_replace('/[_]+/u', ' ', $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    return trim((string)$name);
}

function fub_pw_supported_files(string $dir, array $allowedextensions): array {
    $files = [];
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot() || !$item->isFile()) {
            continue;
        }
        $name = $item->getFilename();
        if (str_starts_with($name, '.') || str_starts_with($name, '~$')) {
            continue;
        }
        $ext = core_text::strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedextensions, true)) {
            continue;
        }
        $files[] = $item->getPathname();
    }
    natcasesort($files);
    return array_values($files);
}

function fub_pw_subdirs(string $dir): array {
    $dirs = [];
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot() || !$item->isDir()) {
            continue;
        }
        $name = $item->getFilename();
        if (str_starts_with($name, '.')) {
            continue;
        }
        $dirs[] = $item->getPathname();
    }
    natcasesort($dirs);
    return array_values($dirs);
}

function fub_pw_relpath(string $path, string $source): string {
    $relative = ltrim(substr($path, strlen($source)), DIRECTORY_SEPARATOR);
    return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
}

function fub_pw_match_normalize(string $name): string {
    $name = core_text::strtolower(fub_pw_clean_name($name));
    $name = str_replace(['–', '—', '_', '/', '\\'], ' ', $name);
    $name = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $name);
    $name = preg_replace('/\\s+/u', ' ', (string)$name);
    return trim((string)$name);
}

function fub_pw_relation_subject(string $filename): string {
    $name = fub_pw_match_normalize($filename);

    // Najmocniejszy sygnał: "Aneks/Załącznik ... do <dokumentu>".
    if (preg_match('/\\b(?:aneks|załącznik)\\b.*?\\bdo\\b\\s+(.+)$/u', $name, $m)) {
        return trim($m[1]);
    }

    // Częsty wariant w archiwum: "Zarządzenie ... Aneks nr X do Regulaminu ...".
    if (preg_match('/\\baneks\\b.*?\\bdo\\b\\s+(.+)$/u', $name, $m)) {
        return trim($m[1]);
    }

    // Jeżeli w nazwie jest jednoznaczne wskazanie konkretnego regulaminu,
    // wykorzystujemy część od słowa "regulamin".
    if (preg_match('/\\b(regulamin(?:u|em|ie|owy|owa|owe)?\\b.+)$/u', $name, $m)) {
        return trim($m[1]);
    }

    // Aneks bez "do" - zostawiamy temat po numerze aneksu. Będzie połączony
    // tylko wtedy, gdy dopasowanie do dokumentu głównego jest jednoznaczne.
    if (preg_match('/^aneks\\b(?:\\s+nr)?\\s*[0-9ivxlcdm.\\/-]*\\s*(.+)$/u', $name, $m)) {
        return trim($m[1]);
    }

    return '';
}

function fub_pw_is_related_attachment(string $filename): bool {
    $name = fub_pw_match_normalize($filename);

    if (preg_match('/\\baneks\\b/u', $name)) {
        return true;
    }

    // "Załącznik" sam w sobie często jest niezależnym formularzem, dlatego
    // traktujemy go jako część dokumentu tylko przy jawnym "... do ...".
    return (bool)preg_match('/\\bzałącznik\\b.*\\bdo\\b/u', $name);
}

function fub_pw_match_tokens(string $text): array {
    $stop = [
        'do', 'dla', 'w', 'we', 'z', 'ze', 'i', 'oraz', 'na', 'nr', 'numer',
        'aneks', 'aneksu', 'załącznik', 'załącznika', 'zarządzenie', 'zarządzenia',
        'prezesa', 'fundacja', 'fundacji', 'unia', 'bracka', 'fub',
        'wydanie', 'wydania', 'wersja', 'zmiana', 'zmiany', 'dotyczący', 'dotycząca',
    ];
    $tokens = preg_split('/\\s+/u', fub_pw_match_normalize($text), -1, PREG_SPLIT_NO_EMPTY);
    $result = [];
    foreach ($tokens as $token) {
        if (
            core_text::strlen($token) < 3 ||
            in_array($token, $stop, true) ||
            str_starts_with($token, 'regulamin')
        ) {
            continue;
        }
        if (preg_match('/^[0-9ivxlcdm.-]+$/u', $token)) {
            continue;
        }
        $result[$token] = true;
    }
    return array_keys($result);
}

function fub_pw_relation_score(string $attachment, string $basefile): float {
    $subject = fub_pw_relation_subject($attachment);
    if ($subject === '') {
        return 0.0;
    }

    $subjectnorm = fub_pw_match_normalize($subject);
    $basenorm = fub_pw_match_normalize($basefile);

    if ($subjectnorm !== '' && (
        str_contains($basenorm, $subjectnorm) ||
        (core_text::strlen($basenorm) >= 12 && str_contains($subjectnorm, $basenorm))
    )) {
        return 1.0;
    }

    $subjecttokens = fub_pw_match_tokens($subjectnorm);
    $basetokens = fub_pw_match_tokens($basenorm);
    if (!$subjecttokens || !$basetokens) {
        return 0.0;
    }

    // Dopasowanie fleksyjne/prefiksowe dla nazw typu:
    // "Regulamin Organizacyjny" <-> "do Regulaminu Organizacyjnego".
    $matched = 0;
    foreach ($subjecttokens as $subjecttoken) {
        foreach ($basetokens as $basetoken) {
            if ($subjecttoken === $basetoken) {
                $matched++;
                break;
            }

            $minlen = min(core_text::strlen($subjecttoken), core_text::strlen($basetoken));
            if ($minlen >= 8) {
                $prefixlen = min(10, $minlen);
                if (
                    core_text::substr($subjecttoken, 0, $prefixlen) ===
                    core_text::substr($basetoken, 0, $prefixlen)
                ) {
                    $matched++;
                    break;
                }
            }
        }
    }

    return $matched / max(1, count($subjecttokens));
}

function fub_pw_add_file_courses(array $files, string $relcategory, string $coursekeyprefix, array &$plan): void {
    if (!$files) {
        return;
    }

    $attachments = [];
    $basefiles = [];
    foreach ($files as $file) {
        if (fub_pw_is_related_attachment(basename($file))) {
            $attachments[] = $file;
        } else {
            $basefiles[] = $file;
        }
    }

    $groups = [];
    foreach ($basefiles as $file) {
        $groups[$file] = [];
    }

    $unmatched = [];
    foreach ($attachments as $attachment) {
        $scores = [];
        foreach ($basefiles as $basefile) {
            $score = fub_pw_relation_score(basename($attachment), basename($basefile));
            if ($score > 0) {
                $scores[$basefile] = $score;
            }
        }

        arsort($scores, SORT_NUMERIC);
        $bestfiles = array_keys($scores);
        $bestscore = $bestfiles ? (float)$scores[$bestfiles[0]] : 0.0;
        $secondscore = count($bestfiles) > 1 ? (float)$scores[$bestfiles[1]] : 0.0;

        // Łączymy tylko dopasowania jednoznaczne. Próg 0,60 pozwala uwzględnić
        // dodatkowe słowa typu "Podmiotu Leczniczego", ale nie łączy przypadkowych aneksów.
        if ($bestscore >= 0.60 && ($secondscore < $bestscore || $bestscore >= 0.95)) {
            $groups[$bestfiles[0]][] = $attachment;
        } else {
            $unmatched[] = $attachment;
        }
    }

    foreach ($basefiles as $basefile) {
        $related = $groups[$basefile] ?? [];
        $plan[] = [
            'type' => $related ? 'documentwithannexes' : 'filecourse',
            'relcategory' => $relcategory,
            'coursename' => fub_pw_clean_name(basename($basefile)),
            'coursekey' => trim($coursekeyprefix . '/' . basename($basefile), '/'),
            'files' => array_merge([$basefile], $related),
        ];
    }

    // Jeżeli aneksu nie dało się jednoznacznie przypisać, pozostaje osobnym
    // kursem zamiast ryzykować połączenie z niewłaściwym dokumentem.
    foreach ($unmatched as $file) {
        $plan[] = [
            'type' => 'unmatchedattachment',
            'relcategory' => $relcategory,
            'coursename' => fub_pw_clean_name(basename($file)),
            'coursekey' => trim($coursekeyprefix . '/' . basename($file), '/'),
            'files' => [$file],
        ];
    }
}

function fub_pw_discover(string $dir, string $source, array $allowedextensions, array &$plan): void {
    $files = fub_pw_supported_files($dir, $allowedextensions);
    $subdirs = fub_pw_subdirs($dir);
    $rel = fub_pw_relpath($dir, $source);

    // Każdy folder pozostaje kategorią. Pliki znajdujące się bezpośrednio
    // w folderze są zamieniane na osobne kursy, z wyjątkiem jednoznacznego
    // zestawu: jeden dokument główny + aneksy.
    fub_pw_add_file_courses($files, $rel, $rel, $plan);

    foreach ($subdirs as $subdir) {
        fub_pw_discover($subdir, $source, $allowedextensions, $plan);
    }
}

function fub_pw_category_idnumber(string $relpath): string {
    if ($relpath === '') {
        return FUB_PW_ROOT_IDNUMBER;
    }
    return 'FUB-PW-CAT-' . substr(sha1($relpath), 0, 24);
}

function fub_pw_course_idnumber(string $coursekey): string {
    return 'FUB-PW-COURSE-' . substr(sha1($coursekey), 0, 24);
}

function fub_pw_get_or_create_category(string $relpath, bool $dryrun): ?\core_course_category {
    global $DB;

    if ($dryrun) {
        return null;
    }

    $root = $DB->get_record('course_categories', ['idnumber' => FUB_PW_ROOT_IDNUMBER]);
    if (!$root) {
        $rootcat = \core_course_category::create([
            'name' => 'Portal Wiedzy',
            'idnumber' => FUB_PW_ROOT_IDNUMBER,
            'description' => 'Instrukcje, procedury, regulaminy i dokumenty organizacyjne Fundacji Unia Bracka.',
            'descriptionformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
    } else {
        $rootcat = \core_course_category::get($root->id, MUST_EXIST, true);
    }

    if ($relpath === '') {
        return $rootcat;
    }

    $parent = $rootcat;
    $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $relpath)), 'strlen'));
    $built = [];

    foreach ($parts as $part) {
        $built[] = $part;
        $path = implode('/', $built);
        $idnumber = fub_pw_category_idnumber($path);
        $record = $DB->get_record('course_categories', ['idnumber' => $idnumber]);

        if ($record) {
            $parent = \core_course_category::get($record->id, MUST_EXIST, true);
            continue;
        }

        $parent = \core_course_category::create([
            'name' => $part,
            'idnumber' => $idnumber,
            'parent' => $parent->id,
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
    }

    return $parent;
}

function fub_pw_get_or_create_cohort(bool $dryrun): ?stdClass {
    global $DB;

    if ($dryrun) {
        return null;
    }

    $systemcontext = \context_system::instance();
    $cohort = $DB->get_record('cohort', [
        'idnumber' => FUB_PW_COHORT_IDNUMBER,
        'contextid' => $systemcontext->id,
    ]);

    if (!$cohort) {
        $cohort = (object)[
            'contextid' => $systemcontext->id,
            'name' => FUB_PW_COHORT_NAME,
            'idnumber' => FUB_PW_COHORT_IDNUMBER,
            'description' => 'Kohorta obowiązkowa zapewniająca wszystkim aktywnym użytkownikom dostęp do Portalu Wiedzy.',
            'descriptionformat' => FORMAT_HTML,
            'visible' => 1,
        ];
        $cohort->id = cohort_add_cohort($cohort);
        $cohort = $DB->get_record('cohort', ['id' => $cohort->id], '*', MUST_EXIST);
    }

    return $cohort;
}

function fub_pw_sync_all_users_to_cohort(stdClass $cohort): int {
    global $CFG, $DB;

    $guestid = (int)$CFG->siteguest;
    $users = $DB->get_records_select(
        'user',
        'deleted = 0 AND suspended = 0 AND confirmed = 1 AND id <> :guestid',
        ['guestid' => $guestid],
        '',
        'id'
    );

    $added = 0;
    foreach ($users as $user) {
        if (!cohort_is_member($cohort->id, $user->id)) {
            cohort_add_member($cohort->id, $user->id);
            $added++;
        }
    }
    return $added;
}

function fub_pw_get_or_create_course(
    string $name,
    string $coursekey,
    \core_course_category $category
): stdClass {
    global $DB;

    $idnumber = fub_pw_course_idnumber($coursekey);
    $course = $DB->get_record('course', ['idnumber' => $idnumber]);
    if ($course) {
        return get_course($course->id);
    }

    // Moodle wymaga globalnie unikalnej krótkiej nazwy kursu.
    // Ta sama nazwa dokumentu może występować w kilku gałęziach Portalu Wiedzy,
    // dlatego zawsze dodajemy stabilny skrót ścieżki źródłowej.
    $suffix = substr(sha1($coursekey), 0, 8);
    $prefix = 'PW | ';
    $separator = ' | ';
    $maxnamelen = 100 - core_text::strlen($prefix . $separator . $suffix);
    $shortnamepart = core_text::substr($name, 0, max(1, $maxnamelen));
    $short = $prefix . $shortnamepart . $separator . $suffix;

    $fields = (object)[
        'fullname' => $name,
        'shortname' => $short,
        'idnumber' => $idnumber,
        'category' => $category->id,
        'summary' => '<p>Dokumentacja Portalu Wiedzy. Zapoznaj się z udostępnionymi dokumentami, a następnie potwierdź zapoznanie.</p>',
        'summaryformat' => FORMAT_HTML,
        'format' => 'topics',
        'numsections' => 1,
        'visible' => 1,
        'enablecompletion' => 1,
        'showgrades' => 0,
    ];

    $created = create_course($fields);
    return get_course($created->id);
}

function fub_pw_ensure_cohort_enrolment(stdClass $course, stdClass $cohort): void {
    global $DB;

    $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

    $existing = $DB->get_record('enrol', [
        'courseid' => $course->id,
        'enrol' => 'cohort',
        'customint1' => $cohort->id,
    ]);

    if (!$existing) {
        $plugin = enrol_get_plugin('cohort');
        if (!$plugin) {
            throw new RuntimeException('Wtyczka enrol_cohort jest niedostępna.');
        }
        $plugin->add_instance($course, [
            'customint1' => $cohort->id,
            'roleid' => $studentrole->id,
            'status' => ENROL_INSTANCE_ENABLED,
        ]);
    }

    $trace = new null_progress_trace();
    enrol_cohort_sync($trace, $course->id);
    $trace->finished();
}

function fub_pw_add_resource(stdClass $course, int $sectionnum, string $filepath, string $coursekey): int {
    global $CFG, $DB, $USER;

    $filename = basename($filepath);
    $cmidnumber = 'PW-DOC-' . substr(sha1($coursekey . '|' . $filename), 0, 24);

    $existing = $DB->get_record('course_modules', [
        'course' => $course->id,
        'idnumber' => $cmidnumber,
    ]);
    if ($existing) {
        return (int)$existing->id;
    }

    [, , , , $data] = prepare_new_moduleinfo_data($course, 'resource', $sectionnum);
    $data->add = 'resource';
    $data->beforemod = 0;
    $data->name = fub_pw_clean_name($filename);
    $data->intro = '';
    $data->introformat = FORMAT_HTML;
    $data->cmidnumber = $cmidnumber;
    $data->display = RESOURCELIB_DISPLAY_AUTO;
    $data->showsize = 1;
    $data->showtype = 1;
    $data->showdate = 0;
    $data->completion = COMPLETION_TRACKING_MANUAL;
    $data->completionview = 0;

    $draftitemid = file_get_unused_draft_itemid();
    $usercontext = \context_user::instance($USER->id);
    $fs = get_file_storage();
    $fs->create_file_from_pathname([
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => $filename,
    ], $filepath);
    $data->files = $draftitemid;

    $created = add_moduleinfo($data, $course, null);
    return (int)$created->coursemodule;
}

function fub_pw_add_confirmation(stdClass $course, int $sectionnum, string $coursekey): int {
    global $DB;

    $cmidnumber = 'PW-CONFIRM-' . substr(sha1($coursekey), 0, 24);
    $existing = $DB->get_record('course_modules', [
        'course' => $course->id,
        'idnumber' => $cmidnumber,
    ]);
    if ($existing) {
        return (int)$existing->id;
    }

    [, , , , $data] = prepare_new_moduleinfo_data($course, 'choice', $sectionnum);
    $data->add = 'choice';
    $data->beforemod = 0;
    $data->name = 'Potwierdzenie zapoznania';
    $data->intro =
        '<p>Po zapoznaniu się ze wszystkimi dokumentami w tym kursie potwierdź ten fakt poniżej.</p>' .
        '<p><strong>Potwierdzenie jest rejestrowane na Twoim koncie użytkownika.</strong></p>';
    $data->introformat = FORMAT_HTML;
    $data->cmidnumber = $cmidnumber;

    $data->option = ['Potwierdzam, że zapoznałem/-am się z dokumentami'];
    $data->limit = [0];
    $data->allowupdate = 0;
    $data->allowmultiple = 0;
    $data->showpreview = 0;
    $data->limitanswers = 0;
    $data->showunanswered = 0;
    $data->includeinactive = 0;
    $data->showresults = CHOICE_SHOWRESULTS_NOT;
    $data->publish = CHOICE_PUBLISH_ANONYMOUS;
    $data->showavailable = 0;
    $data->completion = COMPLETION_TRACKING_AUTOMATIC;
    $data->completionview = 0;
    $data->completionsubmit = 1;

    $created = add_moduleinfo($data, $course, null);
    return (int)$created->coursemodule;
}

function fub_pw_count_acknowledgements(int $courseid): int {
    global $DB;

    $sql = "
        SELECT COUNT(1)
          FROM {choice_answers} ca
          JOIN {choice} ch ON ch.id = ca.choiceid
          JOIN {course_modules} cm ON cm.instance = ch.id
          JOIN {modules} m ON m.id = cm.module
         WHERE cm.course = :courseid
           AND m.name = 'choice'
           AND cm.idnumber LIKE :pattern
    ";

    return (int)$DB->count_records_sql($sql, [
        'courseid' => $courseid,
        'pattern' => 'PW-CONFIRM-%',
    ]);
}

function fub_pw_repair_old_attachment_course(
    string $relcategory,
    string $filepath,
    int $targetcourseid,
    bool $forcerepair
): void {
    global $DB;

    $oldkey = trim($relcategory . '/' . basename($filepath), '/');
    $oldidnumber = fub_pw_course_idnumber($oldkey);
    $oldcourse = $DB->get_record('course', ['idnumber' => $oldidnumber]);

    if (!$oldcourse || (int)$oldcourse->id === $targetcourseid) {
        return;
    }

    $acknowledgements = fub_pw_count_acknowledgements((int)$oldcourse->id);
    if ($acknowledgements > 0 && !$forcerepair) {
        mtrace(
            "UWAGA: nie usuwam starego kursu aneksu '{$oldcourse->fullname}' (ID {$oldcourse->id}); " .
            "ma potwierdzenia użytkowników: {$acknowledgements}. Użyj --force-repair tylko po świadomej decyzji."
        );
        return;
    }

    mtrace("Naprawa grupowania: usuwam stary osobny kurs aneksu '{$oldcourse->fullname}' (ID {$oldcourse->id}).");
    delete_course($oldcourse, false);
}

function fub_pw_ensure_course_completion(stdClass $course, int $confirmationcmid): void {
    global $DB;

    $existing = $DB->get_record('course_completion_criteria', [
        'course' => $course->id,
        'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
        'moduleinstance' => $confirmationcmid,
    ]);

    if ($existing) {
        return;
    }

    $criterion = new completion_criteria_activity();
    $data = (object)[
        'id' => $course->id,
        'criteria_activity' => [
            $confirmationcmid => 1,
        ],
    ];
    $criterion->update_config($data);
}

$plan = [];
fub_pw_discover($source, $source, $allowedextensions, $plan);

if (!$plan) {
    cli_error('Nie znaleziono obsługiwanych dokumentów w podanym katalogu.');
}

mtrace('============================================================');
mtrace('FUB – Portal Wiedzy');
mtrace('Źródło: ' . $source);
mtrace('Tryb: ' . ($dryrun ? 'TYLKO PLAN' : 'IMPORT'));
mtrace('Naprawa istniejącego grupowania: ' . ($repairgrouping ? 'TAK' : 'NIE'));
mtrace('Liczba planowanych kursów: ' . count($plan));
mtrace('============================================================');

foreach ($plan as $item) {
    mtrace('');
    mtrace('Kurs: ' . $item['coursename']);
    mtrace('  Kategoria: Portal Wiedzy' . ($item['relcategory'] !== '' ? ' / ' . $item['relcategory'] : ''));
    foreach ($item['files'] as $file) {
        mtrace('  Plik: ' . basename($file));
    }
}

if ($dryrun) {
    mtrace('');
    mtrace('Brak zmian – zakończono dry-run.');
    exit(0);
}

$cohort = fub_pw_get_or_create_cohort(false);
$addedusers = fub_pw_sync_all_users_to_cohort($cohort);
mtrace('');
mtrace("Kohorta: {$cohort->name}; dodano obecnie użytkowników: {$addedusers}");

$imported = 0;
foreach ($plan as $item) {
    $category = fub_pw_get_or_create_category($item['relcategory'], false);
    $course = fub_pw_get_or_create_course($item['coursename'], $item['coursekey'], $category);

    course_create_sections_if_missing($course, [0, 1]);
    $section = $DB->get_record('course_sections', [
        'course' => $course->id,
        'section' => 1,
    ], '*', MUST_EXIST);
    course_update_section($course, $section, [
        'name' => 'Dokumenty i potwierdzenie',
        'summary' => '<p>Zapoznaj się z dokumentami, a następnie użyj aktywności „Potwierdzenie zapoznania”.</p>',
        'summaryformat' => FORMAT_HTML,
        'visible' => 1,
    ]);

    foreach ($item['files'] as $file) {
        fub_pw_add_resource($course, 1, $file, $item['coursekey']);
    }

    // Przy ponownym imporcie po poprawie grupowania aneks został już dodany
    // do kursu dokumentu głównego. Opcjonalnie usuwamy jego dawny osobny kurs.
    if ($repairgrouping && $item['type'] === 'documentwithannexes' && count($item['files']) > 1) {
        foreach (array_slice($item['files'], 1) as $relatedfile) {
            fub_pw_repair_old_attachment_course(
                $item['relcategory'],
                $relatedfile,
                (int)$course->id,
                $forcerepair
            );
        }
    }

    $confirmationcmid = fub_pw_add_confirmation($course, 1, $item['coursekey']);
    fub_pw_ensure_course_completion($course, $confirmationcmid);
    fub_pw_ensure_cohort_enrolment($course, $cohort);

    rebuild_course_cache($course->id, true);
    mtrace("Zaimportowano: {$course->fullname} -> {$CFG->wwwroot}/course/view.php?id={$course->id}");
    $imported++;
}

purge_all_caches();

mtrace('');
mtrace('============================================================');
mtrace("Gotowe. Obsłużone kursy: {$imported}");
mtrace('Dostęp: kohorta FUB-PORTAL-WIEDZY.');
mtrace('Ukończenie kursu: potwierdzenie zapoznania.');
mtrace('============================================================');
