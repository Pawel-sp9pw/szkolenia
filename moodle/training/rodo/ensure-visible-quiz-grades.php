<?php
// FUB RODO: zapewnia widoczność ocen testów końcowych dla studentów.
// Uruchamiany po imporcie/aktualizacji kursów RODO.

define('CLI_SCRIPT', true);

$moodledir = getenv('MOODLE_DIR') ?: '/var/www/moodle';
$configfile = rtrim($moodledir, '/') . '/config.php';
if (!is_file($configfile)) {
    fwrite(STDERR, "Brak pliku Moodle config.php: {$configfile}\n");
    exit(1);
}

require($configfile);
require_once($CFG->libdir . '/gradelib.php');

// Ograniczamy zmianę wyłącznie do zarządzanych kursów RODO FUB.
$courses = $DB->get_records_select(
    'course',
    'idnumber LIKE :prefix',
    ['prefix' => 'FUB-RODO-%'],
    'id ASC',
    'id,fullname,shortname,idnumber'
);

$changed = 0;
$checked = 0;

foreach ($courses as $course) {
    $items = $DB->get_records('grade_items', [
        'courseid' => $course->id,
        'itemtype' => 'mod',
        'itemmodule' => 'quiz',
    ]);

    foreach ($items as $item) {
        $checked++;

        // W kursach RODO quiz jest testem końcowym i jego wynik ma być widoczny
        // dla użytkownika w raporcie ocen. Ukrycie grade_item powoduje kreskę "—"
        // także przy ocenie końcowej kursu w raporcie przeglądowym.
        if ((int)$item->hidden !== 0) {
            $DB->set_field('grade_items', 'hidden', 0, ['id' => $item->id]);
            $changed++;
            mtrace("  Odkryto ocenę quizu: {$course->shortname} / {$item->itemname} (grade_item {$item->id})");
        }
    }
}

purge_all_caches();

mtrace("Sprawdzono pozycji quizów: {$checked}.");
mtrace("Odkryto pozycji ocen: {$changed}.");
