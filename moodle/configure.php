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

// Branding ekranu logowania. Korzystamy z raw SCSS motywu Boost zamiast modyfikować core Moodle.
$existingcss = (string)(get_config('theme_boost', 'scss') ?: '');
$startmarker = '/* FUB_LOGIN_BRANDING_START */';
$endmarker = '/* FUB_LOGIN_BRANDING_END */';
$pattern = '/' . preg_quote($startmarker, '/') . '.*?' . preg_quote($endmarker, '/') . '/s';
$existingcss = trim((string)preg_replace($pattern, '', $existingcss));

$brandingcss = <<<'SCSS'
/* FUB_LOGIN_BRANDING_START */
body.pagelayout-login #page .login-layout-left {
    background-image: url('https://uniabracka.pl/wp-content/uploads/2021/02/logo-FUB-min-683x455.jpg') !important;
    background-color: #f6f9f2 !important;
    background-size: 72% auto !important;
    background-position: center center !important;
    background-repeat: no-repeat !important;
}

body.pagelayout-login #page .login-layout-left::after {
    display: none !important;
    content: none !important;
}

body.pagelayout-login #page .login-layout-left-content {
    display: none !important;
}

body.pagelayout-login .login-signup {
    margin-top: 1rem;
}

body.pagelayout-login .login-signup .btn-secondary {
    display: block;
    width: 100%;
    padding: .85rem 1.25rem;
    border: 1px solid #2e9f39;
    border-radius: .55rem;
    background: linear-gradient(135deg, #8fd400 0%, #23a53f 100%);
    color: #fff;
    font-size: 1.05rem;
    font-weight: 700;
    text-align: center;
    box-shadow: 0 .35rem .9rem rgba(35, 133, 54, .2);
    transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
}

body.pagelayout-login .login-signup .btn-secondary:hover,
body.pagelayout-login .login-signup .btn-secondary:focus {
    background: linear-gradient(135deg, #9cdd13 0%, #1f9138 100%);
    border-color: #247f32;
    color: #fff;
    filter: saturate(1.04);
    transform: translateY(-1px);
    box-shadow: 0 .5rem 1.15rem rgba(35, 133, 54, .28);
}

@media (max-width: 767.98px) {
    body.pagelayout-login #page .login-layout-left {
        background-size: 82% auto !important;
        min-height: 12rem;
    }
}
/* FUB_LOGIN_BRANDING_END */
SCSS;

set_config('scss', trim($existingcss . "\n\n" . $brandingcss), 'theme_boost');

// Lokalny override polskiego napisu przycisku rejestracji.
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
echo "Zastosowano branding FUB na stronie logowania i przycisk 'Zarejestruj się'.\n";
echo "moodle/my:manageblocks dla roli 'user': PREVENT.\n";
if ($contactphone !== '') {
    echo "Telefon kontaktowy rejestracji: {$contactphone}\n";
}
