<?php
// FUB: importer profilowanych szkoleń RODO dla Moodle 5.2.x.
// Uruchamiany wyłącznie z CLI.

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
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'only' => '',
        'update-content' => false,
    ],
    ['h' => 'help']
);
if ($unrecognized) {
    cli_error("Nierozpoznane opcje:\n  " . implode("\n  ", $unrecognized));
}
if ($options['help']) {
    echo <<<TXT
FUB – importer szkoleń RODO dla Moodle 5.2.x

Opcje:
  --only=medical,registration,admin,it,marketing
      Importuje tylko wskazane kursy.
  --update-content
      Aktualizuje nazwy, opisy i zarządzane sekcje istniejących kursów.
      Nie usuwa istniejących pytań ani prób użytkowników.
  -h, --help
      Pomoc.

TXT;
    exit(0);
}

$datadir = __DIR__ . '/data';

function rodo_load_json(string $path): array {
    if (!is_file($path)) {
        cli_error("Brak pliku danych: {$path}");
    }
    try {
        $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        cli_error("Niepoprawny JSON {$path}: " . $e->getMessage());
    }
    if (!is_array($data)) {
        cli_error("Nieprawidłowa struktura danych: {$path}");
    }
    return $data;
}

$manifest = rodo_load_json($datadir . '/manifest.json');
if (($manifest['schema'] ?? '') !== 'fub-rodo-v2') {
    cli_error('Nieobsługiwany format manifestu RODO.');
}

$commonsections = array_merge(
    rodo_load_json($datadir . '/common-sections-1.json'),
    rodo_load_json($datadir . '/common-sections-2.json')
);
$commonquestions = rodo_load_json($datadir . '/common-questions.json');

$coursemap = [];
foreach ($manifest['courses'] as $spec) {
    $key = (string)$spec['key'];
    $spec['specific_sections'] = rodo_load_json($datadir . "/{$key}-sections.json");
    $spec['specific_questions'] = rodo_load_json($datadir . "/{$key}-questions.json");
    $coursemap[$key] = $spec;
}

$allowedkeys = array_keys($coursemap);
$selected = $allowedkeys;
if (trim((string)$options['only']) !== '') {
    $selected = array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', (string)$options['only'])
    ))));
    foreach ($selected as $key) {
        if (!in_array($key, $allowedkeys, true)) {
            cli_error("Nieznany klucz kursu w --only: {$key}");
        }
    }
}

if (count($commonquestions) !== 15) {
    cli_error('Pakiet musi zawierać dokładnie 15 pytań wspólnych.');
}
foreach ($coursemap as $key => $spec) {
    if (count($spec['specific_questions']) !== 15) {
        cli_error("Kurs {$key} musi zawierać dokładnie 15 pytań profilowanych.");
    }
    if (count($spec['specific_sections']) !== 4) {
        cli_error("Kurs {$key} musi zawierać dokładnie 4 sekcje profilowane.");
    }
}

$admin = get_admin();
if (!$admin || empty($admin->id)) {
    cli_error('Nie udało się ustalić konta administratora Moodle.');
}
\core\session\manager::set_user($admin);

function rodo_get_or_create_category(array $spec): \core_course_category {
    global $DB;

    $idnumber = (string)$spec['idnumber'];
    $record = $DB->get_record('course_categories', ['idnumber' => $idnumber]);

    if ($record) {
        $category = \core_course_category::get($record->id, MUST_EXIST, true);
        $changes = [];
        if ($category->name !== $spec['name']) {
            $changes['name'] = $spec['name'];
        }
        if ($changes) {
            $category->update($changes);
            $category = \core_course_category::get($record->id, MUST_EXIST, true);
        }
        return $category;
    }

    return \core_course_category::create([
        'name' => $spec['name'],
        'idnumber' => $idnumber,
        'description' => $spec['description'] ?? '',
        'descriptionformat' => FORMAT_HTML,
        'visible' => 1,
    ]);
}

function rodo_course_summary(array $spec, string $legalstate): string {
    $audience = s($spec['audience']);
    $time = s($spec['estimated_time']);
    $legal = s($legalstate);
    return
        '<div style="border-left:4px solid #79b82a;padding:14px 18px;background:#f6faf2;margin-bottom:16px">' .
        "<strong>Dla kogo:</strong> {$audience}<br>" .
        "<strong>Orientacyjny czas:</strong> {$time}<br>" .
        '<strong>Zakres:</strong> wspólne podstawy ochrony danych + zagadnienia dopasowane do stanowiska.' .
        '</div>' .
        '<p>Szkolenie zostało przygotowane dla sieci 16 przychodni i odnosi zasady RODO do codziennych sytuacji zawodowych. ' .
        'Materiał obejmuje przykłady, obowiązki pracownika, zasady bezpieczeństwa oraz podstawy prawne.</p>' .
        "<p><strong>Stan prawny materiału:</strong> {$legal}.</p>";
}

function rodo_get_or_create_course(
    array $spec,
    \core_course_category $category,
    bool $updatecontent,
    int $numberedsections,
    string $legalstate
): stdClass {
    global $DB;

    $course = $DB->get_record('course', ['idnumber' => $spec['idnumber']]);
    $fields = (object)[
        'fullname' => $spec['fullname'],
        'shortname' => $spec['shortname'],
        'idnumber' => $spec['idnumber'],
        'category' => $category->id,
        'summary' => rodo_course_summary($spec, $legalstate),
        'summaryformat' => FORMAT_HTML,
        'format' => 'topics',
        'visible' => 1,
        'enablecompletion' => 1,
        'showgrades' => 1,
    ];

    if (!$course) {
        $fields->numsections = $numberedsections;
        $created = create_course($fields);
        return get_course($created->id);
    }

    if ($updatecontent) {
        $fields->id = $course->id;
        update_course($fields);
    }
    return get_course($course->id);
}

function rodo_update_sections(
    stdClass $course,
    array $commonsections,
    array $spec,
    bool $updatecontent
): int {
    global $DB;

    $sections = [[
        'name' => 'Start – o szkoleniu',
        'summary' =>
            '<p><strong>Cel szkolenia:</strong> bezpieczne i zgodne z prawem przetwarzanie danych w codziennej pracy.</p>' .
            '<p>Przejdź kolejno przez wszystkie części. Na końcu znajduje się test: 5 pytań losowanych z puli 30. ' .
            'Do zaliczenia wymagane są 4 poprawne odpowiedzi.</p>' .
            '<div style="border-left:4px solid #2f6fad;padding:12px 16px;background:#f3f7fb;margin:16px 0">' .
            '<strong>Ważne:</strong> szkolenie nie zastępuje wewnętrznych procedur organizacji. W razie wątpliwości ' .
            'należy stosować obowiązujące instrukcje oraz skontaktować się z przełożonym, IOD lub właściwym działem.</div>',
    ]];

    foreach ($commonsections as $section) {
        $sections[] = $section;
    }
    foreach ($spec['specific_sections'] as $section) {
        $sections[] = $section;
    }

    $quizsectionnum = count($sections);
    $sections[] = [
        'name' => 'Test końcowy',
        'summary' =>
            '<p>Test składa się z <strong>5 pytań losowanych z puli 30</strong>. ' .
            'Masz maksymalnie <strong>3 podejścia</strong>. Do zaliczenia wymagane jest <strong>80% (4/5)</strong>.</p>' .
            '<p>Przy każdym podejściu zestaw może być inny. Pytania sprawdzają zastosowanie zasad w praktyce.</p>',
    ];

    course_create_sections_if_missing($course, range(0, count($sections) - 1));

    foreach ($sections as $sectionnum => $sectiondata) {
        $section = $DB->get_record(
            'course_sections',
            ['course' => $course->id, 'section' => $sectionnum],
            '*',
            MUST_EXIST
        );
        if ($updatecontent || trim((string)$section->summary) === '') {
            course_update_section($course, $section, [
                'name' => $sectiondata['name'],
                'summary' => $sectiondata['summary'],
                'summaryformat' => FORMAT_HTML,
                'visible' => 1,
            ]);
        }
    }
    return $quizsectionnum;
}

function rodo_get_question_category(stdClass $course): array {
    $qbankcm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
    if (!$qbankcm) {
        throw new RuntimeException("Nie udało się utworzyć banku pytań dla kursu {$course->shortname}.");
    }

    $context = \context_module::instance($qbankcm->id);
    $category = question_get_default_category($context->id, true);
    if (!$category) {
        throw new RuntimeException('Nie udało się utworzyć domyślnej kategorii pytań.');
    }
    return [$context, $category];
}

function rodo_question_xml(array $spec, array $commonquestions): string {
    $questions = array_merge($commonquestions, $spec['specific_questions']);

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $quiz = $doc->appendChild($doc->createElement('quiz'));

    foreach ($questions as $q) {
        $question = $quiz->appendChild($doc->createElement('question'));
        $question->setAttribute('type', 'multichoice');

        $name = $question->appendChild($doc->createElement('name'));
        $name->appendChild($doc->createElement('text', $spec['idnumber'] . '-' . $q['id'] . ' ' . $q['name']));

        $questiontext = $question->appendChild($doc->createElement('questiontext'));
        $questiontext->setAttribute('format', 'html');
        $questiontext->appendChild($doc->createElement('text'))
            ->appendChild($doc->createCDATASection('<p>' . $q['text'] . '</p>'));

        $feedback = $question->appendChild($doc->createElement('generalfeedback'));
        $feedback->setAttribute('format', 'html');
        $feedback->appendChild($doc->createElement('text'))
            ->appendChild($doc->createCDATASection('<p>' . $q['feedback'] . '</p>'));

        $question->appendChild($doc->createElement('defaultgrade', '1.0000000'));
        $question->appendChild($doc->createElement('penalty', '0.3333333'));
        $question->appendChild($doc->createElement('hidden', '0'));
        $question->appendChild($doc->createElement('idnumber', $spec['idnumber'] . '-' . $q['id']));
        $question->appendChild($doc->createElement('single', 'true'));
        $question->appendChild($doc->createElement('shuffleanswers', 'true'));
        $question->appendChild($doc->createElement('answernumbering', 'abc'));
        $question->appendChild($doc->createElement('showstandardinstruction', '0'));

        foreach ([
            ['correctfeedback', 'Prawidłowo.'],
            ['partiallycorrectfeedback', 'Odpowiedź jest częściowo poprawna.'],
            ['incorrectfeedback', 'Nieprawidłowo. Zapoznaj się z wyjaśnieniem.'],
        ] as [$tag, $message]) {
            $el = $question->appendChild($doc->createElement($tag));
            $el->setAttribute('format', 'html');
            $el->appendChild($doc->createElement('text'))
                ->appendChild($doc->createCDATASection('<p>' . $message . '</p>'));
        }

        foreach ($q['answers'] as $index => $answertext) {
            $answer = $question->appendChild($doc->createElement('answer'));
            $answer->setAttribute('fraction', $index === (int)$q['correct'] ? '100' : '0');
            $answer->setAttribute('format', 'html');
            $answer->appendChild($doc->createElement('text'))
                ->appendChild($doc->createCDATASection('<p>' . $answertext . '</p>'));
            $af = $answer->appendChild($doc->createElement('feedback'));
            $af->setAttribute('format', 'html');
            $af->appendChild($doc->createElement('text'))
                ->appendChild($doc->createCDATASection(''));
        }
    }

    $filename = tempnam(sys_get_temp_dir(), 'rodo-questions-');
    if ($filename === false || $doc->save($filename) === false) {
        throw new RuntimeException('Nie udało się utworzyć tymczasowego Moodle XML.');
    }
    return $filename;
}

function rodo_import_questions(
    stdClass $course,
    \context_module $context,
    stdClass $category,
    array $spec,
    array $commonquestions
): void {
    global $DB;

    $expectedcount = count($commonquestions) + count($spec['specific_questions']);
    $count = $DB->count_records('question_bank_entries', ['questioncategoryid' => $category->id]);

    if ($count === $expectedcount) {
        mtrace("  Bank pytań: {$count}/{$expectedcount} – już gotowy.");
        return;
    }
    if ($count !== 0) {
        throw new RuntimeException(
            "Bank pytań kursu {$course->shortname} zawiera {$count} pozycji, oczekiwano 0 albo {$expectedcount}. " .
            'Importer nie usuwa automatycznie istniejących pytań.'
        );
    }

    $xmlfile = rodo_question_xml($spec, $commonquestions);
    try {
        $format = new qformat_xml();
        $format->setCategory($category);
        $format->setCourse($course);
        $format->setContexts([$context]);
        $format->setFilename($xmlfile);
        $format->setRealfilename($spec['idnumber'] . '-questions.xml');
        $format->setStoponerror(true);
        $format->set_display_progress(false);

        if (!$format->importpreprocess() || !$format->importprocess() || !$format->importpostprocess()) {
            throw new RuntimeException("Import pytań nie powiódł się dla {$spec['idnumber']}.");
        }
    } finally {
        @unlink($xmlfile);
    }

    $newcount = $DB->count_records('question_bank_entries', ['questioncategoryid' => $category->id]);
    if ($newcount !== $expectedcount) {
        throw new RuntimeException("Po imporcie bank zawiera {$newcount} pytań, oczekiwano {$expectedcount}.");
    }
    mtrace("  Bank pytań: zaimportowano {$newcount} pytań.");
}

function rodo_find_existing_quiz(stdClass $course, string $cmidnumber): ?stdClass {
    global $DB;

    $sql = "SELECT cm.*, q.id AS quizid, q.name
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
              JOIN {quiz} q ON q.id = cm.instance
             WHERE cm.course = :courseid
               AND cm.idnumber = :idnumber";
    return $DB->get_record_sql($sql, [
        'courseid' => $course->id,
        'idnumber' => $cmidnumber,
    ]) ?: null;
}

function rodo_create_quiz(
    stdClass $course,
    int $sectionnum,
    array $spec,
    stdClass $questioncategory,
    array $quizdefaults
): void {
    global $DB, $USER;

    $cmidnumber = $spec['idnumber'] . '-QUIZ';
    $existing = rodo_find_existing_quiz($course, $cmidnumber);
    if ($existing) {
        $slots = $DB->count_records('quiz_slots', ['quizid' => $existing->quizid]);
        if ($slots !== (int)$quizdefaults['random_questions']) {
            throw new RuntimeException(
                "Istniejący test {$course->shortname} ma {$slots} slotów; oczekiwano " .
                (int)$quizdefaults['random_questions'] . '. Nie zmieniam testu z istniejącą konfiguracją.'
            );
        }
        mtrace("  Test: już istnieje ({$slots} losowych pytań).");
        return;
    }

    [, , , , $data] = prepare_new_moduleinfo_data($course, 'quiz', $sectionnum);
    $data->add = 'quiz';
    $data->beforemod = 0;
    $data->name = $spec['quiz_name'];
    $data->intro =
        '<p>Test sprawdza praktyczne rozumienie zasad ochrony danych właściwych dla tego kursu.</p>' .
        '<ul><li>5 pytań losowanych z puli 30,</li><li>3 podejścia,</li><li>próg zaliczenia: 80% (4/5),</li>' .
        '<li>kolejność odpowiedzi jest losowana.</li></ul>';
    $data->introformat = FORMAT_HTML;
    $data->cmidnumber = $cmidnumber;

    $data->timeopen = 0;
    $data->timeclose = 0;
    $data->timelimit = 0;
    $data->overduehandling = 'autosubmit';
    $data->graceperiod = 0;
    $data->preferredbehaviour = 'deferredfeedback';
    $data->attempts = (int)$quizdefaults['attempts'];
    $data->grademethod = QUIZ_GRADEHIGHEST;
    $data->grade = (float)$quizdefaults['random_questions'];
    $data->decimalpoints = 0;
    $data->questiondecimalpoints = 0;
    $data->questionsperpage = 1;
    $data->navmethod = QUIZ_NAVMETHOD_FREE;
    $data->shuffleanswers = !empty($quizdefaults['shuffle_answers']) ? 1 : 0;
    $data->quizpassword = '';
    $data->delay1 = 0;
    $data->delay2 = 0;
    $data->browsersecurity = '-';

    $data->completion = COMPLETION_TRACKING_AUTOMATIC;
    $data->completionview = 0;
    $data->completionpassgrade = 1;
    $data->completiongradeitemnumber = 0;
    $data->completionexpected = 0;

    $created = add_moduleinfo($data, $course, null);
    $quizid = (int)$created->instance;

    $quizobj = \mod_quiz\quiz_settings::create($quizid, $USER->id);
    $structure = \mod_quiz\structure::create_for_quiz($quizobj);
    $filtercondition = [
        'filter' => [
            'category' => [
                'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                'values' => [(int)$questioncategory->id],
                'filteroptions' => ['includesubcategories' => false],
            ],
        ],
    ];
    $structure->add_random_questions(0, (int)$quizdefaults['random_questions'], $filtercondition);
    $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();

    $maxgrade = (float)$quizdefaults['random_questions'];
    $gradepass = $maxgrade * ((float)$quizdefaults['pass_percent'] / 100);

    $gradeitem = \grade_item::fetch([
        'courseid' => $course->id,
        'itemtype' => 'mod',
        'itemmodule' => 'quiz',
        'iteminstance' => $quizid,
        'itemnumber' => 0,
    ]);
    if (!$gradeitem) {
        throw new RuntimeException("Nie znaleziono pozycji oceny dla testu {$course->shortname}.");
    }
    $gradeitem->gradepass = $gradepass;
    $gradeitem->update();

    mtrace("  Test: utworzono {$quizdefaults['random_questions']} losowych pytań, próg {$quizdefaults['pass_percent']}%.");
}

$category = rodo_get_or_create_category($manifest['category']);
$quizdefaults = $manifest['defaults']['quiz'];
$legalstate = (string)($manifest['legal_state'] ?? '');

mtrace('============================================================');
mtrace('FUB – importer szkoleń RODO');
mtrace('Kategoria Moodle: ' . $category->name);
mtrace('Wersja treści: ' . ($manifest['managed_version'] ?? 'brak'));
mtrace('============================================================');

$imported = 0;
foreach ($coursemap as $key => $spec) {
    if (!in_array($key, $selected, true)) {
        continue;
    }

    mtrace('');
    mtrace("Kurs: {$spec['fullname']} ({$spec['idnumber']})");

    $existingbefore = $DB->record_exists('course', ['idnumber' => $spec['idnumber']]);
    // Sekcja 0 (Start) + sekcje wspólne + profilowane + test.
    // numsections liczy sekcje numerowane, więc wyłączamy sekcję 0.
    $numberedsections = count($commonsections) + count($spec['specific_sections']) + 1;
    $course = rodo_get_or_create_course(
        $spec,
        $category,
        (bool)$options['update-content'],
        $numberedsections,
        $legalstate
    );

    $managecontent = !$existingbefore || (bool)$options['update-content'];
    $quizsectionnum = rodo_update_sections($course, $commonsections, $spec, $managecontent);

    [$qcontext, $questioncategory] = rodo_get_question_category($course);
    rodo_import_questions($course, $qcontext, $questioncategory, $spec, $commonquestions);
    rodo_create_quiz($course, $quizsectionnum, $spec, $questioncategory, $quizdefaults);

    rebuild_course_cache($course->id, true);
    mtrace("  Gotowe: {$CFG->wwwroot}/course/view.php?id={$course->id}");
    $imported++;
}

purge_all_caches();

mtrace('');
mtrace('============================================================');
mtrace("Zakończono. Obsłużone kursy: {$imported}");
mtrace('Każdy kurs zawiera część wspólną, 4 moduły profilowane i test 5/30.');
mtrace('============================================================');
