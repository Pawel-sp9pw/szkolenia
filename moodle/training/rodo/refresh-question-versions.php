<?php
// FUB: aktualizacja treści istniejących pytań RODO przez tworzenie nowych wersji.
// Zachowuje stare wersje dla już wykonanych prób quizów.

define('CLI_SCRIPT', true);

$moodledir = getenv('MOODLE_DIR') ?: '/var/www/moodle';
$configfile = rtrim($moodledir, '/') . '/config.php';
if (!is_file($configfile)) {
    fwrite(STDERR, "Brak pliku Moodle config.php: {$configfile}\n");
    exit(1);
}

require($configfile);
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');

$datadir = __DIR__ . '/data';

function fub_rodo_refresh_load_json(string $path): array {
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

function fub_rodo_expected_html(string $text): string {
    return '<p>' . $text . '</p>';
}

function fub_rodo_latest_ready_question(int $bankentryid): ?stdClass {
    global $DB;

    $sql = "SELECT q.*
              FROM {question_versions} qv
              JOIN {question} q ON q.id = qv.questionid
             WHERE qv.questionbankentryid = :bankentryid
               AND qv.status = :status
          ORDER BY qv.version DESC";

    $records = $DB->get_records_sql($sql, [
        'bankentryid' => $bankentryid,
        'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
    ], 0, 1);

    return $records ? reset($records) : null;
}

function fub_rodo_question_matches(stdClass $question, array $source, string $expectedname): bool {
    global $DB;

    if ((string)$question->name !== $expectedname) {
        return false;
    }
    if (trim((string)$question->questiontext) !== fub_rodo_expected_html((string)$source['text'])) {
        return false;
    }
    if (trim((string)$question->generalfeedback) !== fub_rodo_expected_html((string)$source['feedback'])) {
        return false;
    }

    $answers = array_values($DB->get_records('question_answers', ['question' => $question->id], 'id ASC'));
    if (count($answers) !== count($source['answers'])) {
        return false;
    }

    foreach ($source['answers'] as $index => $answertext) {
        $expectedfraction = $index === (int)$source['correct'] ? 1.0 : 0.0;
        if (trim((string)$answers[$index]->answer) !== fub_rodo_expected_html((string)$answertext)) {
            return false;
        }
        if (abs((float)$answers[$index]->fraction - $expectedfraction) > 0.000001) {
            return false;
        }
    }

    return true;
}

function fub_rodo_save_new_version(
    stdClass $current,
    int $categoryid,
    string $idnumber,
    string $name,
    array $source
): stdClass {
    $form = new stdClass();
    $form->category = (string)$categoryid;
    $form->name = $name;
    $form->questiontext = [
        'text' => fub_rodo_expected_html((string)$source['text']),
        'format' => FORMAT_HTML,
        'itemid' => 0,
    ];
    $form->generalfeedback = [
        'text' => fub_rodo_expected_html((string)$source['feedback']),
        'format' => FORMAT_HTML,
        'itemid' => 0,
    ];
    $form->defaultmark = 1;
    $form->penalty = 0.3333333;
    $form->idnumber = $idnumber;
    $form->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

    $form->single = 1;
    $form->shuffleanswers = 1;
    $form->answernumbering = 'abc';
    $form->showstandardinstruction = 0;
    $form->shownumcorrect = 1;

    $form->answer = [];
    $form->fraction = [];
    $form->feedback = [];

    foreach ($source['answers'] as $index => $answertext) {
        $form->answer[$index] = [
            'text' => fub_rodo_expected_html((string)$answertext),
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $form->fraction[$index] = $index === (int)$source['correct'] ? 1.0 : 0.0;
        $form->feedback[$index] = [
            'text' => '',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
    }

    $form->correctfeedback = [
        'text' => '<p>Prawidłowo.</p>',
        'format' => FORMAT_HTML,
        'itemid' => 0,
    ];
    $form->partiallycorrectfeedback = [
        'text' => '<p>Odpowiedź jest częściowo poprawna.</p>',
        'format' => FORMAT_HTML,
        'itemid' => 0,
    ];
    $form->incorrectfeedback = [
        'text' => '<p>Nieprawidłowo. Zapoznaj się z wyjaśnieniem.</p>',
        'format' => FORMAT_HTML,
        'itemid' => 0,
    ];
    $form->hint = [];
    $form->hintclearwrong = [];
    $form->hintshownumcorrect = [];

    return question_bank::get_qtype('multichoice')->save_question($current, $form);
}

$manifest = fub_rodo_refresh_load_json($datadir . '/manifest.json');
$commonquestions = fub_rodo_refresh_load_json($datadir . '/common-questions.json');

$admin = get_admin();
if (!$admin || empty($admin->id)) {
    cli_error('Nie udało się ustalić konta administratora Moodle.');
}
\core\session\manager::set_user($admin);

$changed = 0;
$unchanged = 0;

foreach ($manifest['courses'] as $spec) {
    $key = (string)$spec['key'];
    $specific = fub_rodo_refresh_load_json($datadir . "/{$key}-questions.json");
    $questions = array_merge($commonquestions, $specific);

    $course = $DB->get_record('course', ['idnumber' => $spec['idnumber']]);
    if (!$course) {
        mtrace("Kurs {$spec['idnumber']}: brak w Moodle – pomijam.");
        continue;
    }

    $qbankcm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, false);
    if (!$qbankcm) {
        mtrace("Kurs {$course->shortname}: brak banku pytań – pomijam.");
        continue;
    }

    $context = \context_module::instance($qbankcm->id);
    $category = question_get_default_category($context->id, false);
    if (!$category) {
        mtrace("Kurs {$course->shortname}: brak kategorii pytań – pomijam.");
        continue;
    }

    mtrace("Kurs: {$course->shortname}");

    foreach ($questions as $source) {
        $idnumber = $spec['idnumber'] . '-' . $source['id'];
        $bankentry = $DB->get_record('question_bank_entries', [
            'questioncategoryid' => $category->id,
            'idnumber' => $idnumber,
        ]);

        if (!$bankentry) {
            throw new RuntimeException("Brak pytania {$idnumber} w banku kursu {$course->shortname}.");
        }

        $current = fub_rodo_latest_ready_question((int)$bankentry->id);
        if (!$current) {
            throw new RuntimeException("Brak gotowej wersji pytania {$idnumber}.");
        }

        $expectedname = $spec['idnumber'] . '-' . $source['id'] . ' ' . $source['name'];

        if (fub_rodo_question_matches($current, $source, $expectedname)) {
            $unchanged++;
            continue;
        }

        $newquestion = fub_rodo_save_new_version(
            $current,
            (int)$category->id,
            $idnumber,
            $expectedname,
            $source
        );

        mtrace("  {$source['id']}: nowa wersja pytania (ID {$newquestion->id}).");
        $changed++;
    }

    rebuild_course_cache($course->id, true);
}

purge_all_caches();

mtrace('');
mtrace("Zaktualizowano pytań: {$changed}");
mtrace("Bez zmian: {$unchanged}");
mtrace('Stare wersje pozostały zachowane dla istniejących prób.');
