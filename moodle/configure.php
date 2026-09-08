<?php
// CLI helper used by the Proxmox provisioning script.

define('CLI_SCRIPT', true);

$moodledir = getenv('MOODLE_DIR') ?: '/var/www/moodle';
$configfile = rtrim($moodledir, '/') . '/config.php';
if (!is_file($configfile)) {
    fwrite(STDERR, "Brak pliku config.php: {$configfile}\n");
    exit(1);
}

require($configfile);
require_once($CFG->libdir . '/accesslib.php');

// Moodle stores enabled authentication plugins in $CFG->auth as a comma-separated list.
// Work directly with that value so this helper does not depend on authentication service
// classes whose availability differs between Moodle 5.2 builds.
$auths = [];
if (!empty($CFG->auth)) {
    $auths = array_values(array_filter(array_map('trim', explode(',', (string)$CFG->auth))));
}
if (!in_array('manualapproval', $auths, true)) {
    $auths[] = 'manualapproval';
}
set_config('auth', implode(',', array_values(array_unique($auths))));

set_config('registerauth', 'manualapproval');
set_config('authloginviaemail', 0);
set_config('activationdays', 2, 'auth_manualapproval');

$contactphone = trim((string)(getenv('REGISTRATION_CONTACT_PHONE') ?: ''));
set_config('contactphone', $contactphone, 'auth_manualapproval');

$role = $DB->get_record('role', ['shortname' => 'user'], 'id,shortname', MUST_EXIST);
$context = context_system::instance();
assign_capability('moodle/my:manageblocks', CAP_PREVENT, $role->id, $context->id, true);

purge_all_caches();

echo "Włączono auth_manualapproval i ustawiono rejestrację samoobsługową.\n";
echo "moodle/my:manageblocks dla roli 'user': PREVENT.\n";
if ($contactphone !== '') {
    echo "Telefon kontaktowy rejestracji: {$contactphone}\n";
}
