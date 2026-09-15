<?php
// Minimalny tryb szkoleniowy FUB dla zwykłych użytkowników.
// Skrypt CLI nie modyfikuje SSL, Nginx ani SMTP.

define('CLI_SCRIPT', true);

$moodledir = getenv('MOODLE_DIR') ?: '/var/www/moodle';
$configfile = rtrim($moodledir, '/') . '/config.php';
if (!is_file($configfile)) {
    fwrite(STDERR, "Brak pliku config.php: {$configfile}\n");
    exit(1);
}

require($configfile);
require_once($CFG->libdir . '/accesslib.php');

// Użytkownik po zalogowaniu ma trafiać bezpośrednio do listy własnych kursów.
set_config('enablemycourses', 1);
set_config('enabledashboard', 0);
set_config('defaulthomepage', HOMEPAGE_MYCOURSES);

// Wyłączamy subsystems, których nie używamy na platformie szkoleniowej.
set_config('enableblogs', 0);
set_config('messaging', 0);

// Forum jako moduł aktywności jest wyłączone globalnie.
$forum = $DB->get_record('modules', ['name' => 'forum'], 'id,name,visible');
if ($forum && (int)$forum->visible !== 0) {
    $DB->set_field('modules', 'visible', 0, ['id' => $forum->id]);
}

$systemcontext = context_system::instance();

// Użytkownicy nie tworzą własnych wydarzeń kalendarza.
$userrole = $DB->get_record('role', ['shortname' => 'user'], 'id,shortname', MUST_EXIST);
assign_capability('moodle/calendar:manageownentries', CAP_PROHIBIT, $userrole->id, $systemcontext->id, true);

// Student nie korzysta z forów ani grupowego kalendarza.
$studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id,shortname', MUST_EXIST);
foreach ([
    'moodle/calendar:managegroupentries',
    'mod/forum:viewdiscussion',
    'mod/forum:startdiscussion',
    'mod/forum:replypost',
] as $capability) {
    assign_capability($capability, CAP_PROHIBIT, $studentrole->id, $systemcontext->id, true);
}

purge_all_caches();

echo "Tryb szkoleniowy FUB skonfigurowany.\n";
echo "Domyślna strona: Moje kursy.\n";
echo "Blogi: wyłączone.\n";
echo "Komunikator: wyłączony.\n";
echo "Fora: wyłączone.\n";
echo "Kalendarz użytkownika: bez możliwości dodawania własnych wydarzeń.\n";
