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

// Usuń poprzednie eksperymentalne style dopisywane bezpośrednio do Boost.
$boostscss = (string)(get_config('theme_boost', 'scss') ?: '');
$startmarker = '/* FUB_LOGIN_BRANDING_START */';
$endmarker = '/* FUB_LOGIN_BRANDING_END */';
$pattern = '/' . preg_quote($startmarker, '/') . '.*?' . preg_quote($endmarker, '/') . '/s';
$boostscss = trim((string)preg_replace($pattern, '', $boostscss));
set_config('scss', $boostscss, 'theme_boost');

// Motyw ustawiamy dopiero wtedy, gdy został już skopiowany do drzewa Moodle.
// Przy świeżej instalacji pierwszy etap provisioning instaluje auth_manualapproval,
// a finalizer dołącza theme_fub i ponownie uruchamia ten helper.
$themefub = rtrim($moodledir, '/') . '/public/theme/fub/version.php';
$themeenabled = is_file($themefub);
if ($themeenabled) {
    set_config('theme', 'fub');
}

// Lokalny polski napis przycisku rejestracji. Dzięki temu właściwy tekst jest
// renderowany po stronie serwera; JS motywu jest dodatkowym zabezpieczeniem.
$langdir = rtrim($CFG->dataroot, '/') . '/lang/pl_local';
if (!is_dir($langdir) && !mkdir($langdir, 0770, true) && !is_dir($langdir)) {
    throw new RuntimeException('Nie udało się utworzyć katalogu lokalnych tłumaczeń: ' . $langdir);
}
$langfile = $langdir . '/moodle.php';
$langcontent = is_file($langfile) ? (string)file_get_contents($langfile) : "<?php\n";
$langstart = '// FUB_LOGIN_LANG_START';
$langend = '// FUB_LOGIN_LANG_END';
$langpattern = '/' . preg_quote($langstart, '/') . '.*?' . preg_quote($langend, '/') . '/s';
$langcontent = (string)preg_replace($langpattern, '', $langcontent);
if (!str_starts_with(ltrim($langcontent), '<?php')) {
    $langcontent = "<?php\n" . $langcontent;
}
$langblock = "\n{$langstart}\n\$string['startsignup'] = 'Zarejestruj się';\n{$langend}\n";
file_put_contents($langfile, rtrim($langcontent) . $langblock);
@chmod($langfile, 0660);

$role = $DB->get_record('role', ['shortname' => 'user'], 'id,shortname', MUST_EXIST);
$context = context_system::instance();
assign_capability('moodle/my:manageblocks', CAP_PREVENT, $role->id, $context->id, true);

purge_all_caches();

echo "Włączono auth_manualapproval i ustawiono rejestrację samoobsługową.\n";
if ($themeenabled) {
    echo "Ustawiono motyw FUB dla logowania i rejestracji.\n";
} else {
    echo "Motyw FUB zostanie włączony w etapie finalizacji.\n";
}
echo "Przycisk rejestracji: Zarejestruj się.\n";
echo "moodle/my:manageblocks dla roli 'user': PREVENT.\n";
if ($contactphone !== '') {
    echo "Telefon kontaktowy rejestracji: {$contactphone}\n";
}
