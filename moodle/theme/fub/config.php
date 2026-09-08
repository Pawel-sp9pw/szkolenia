<?php

defined('MOODLE_INTERNAL') || die();

$THEME->name = 'fub';
$THEME->sheets = ['fub'];
$THEME->editor_sheets = [];
$THEME->parents = ['boost'];
$THEME->usefallback = true;
$THEME->rendererfactory = 'theme_overridden_renderer_factory';
$THEME->iconsystem = \core\output\icon_system::FONTAWESOME;
$THEME->haseditswitch = true;
$THEME->usescourseindex = true;
$THEME->activityheaderconfig = ['notitle' => true];
$THEME->javascripts_footer = ['fub'];

// Reuse the tested Boost layout files while keeping theme_fub as the active
// theme, so our CSS/JS/template overrides are applied without copying core.
$THEME->layouts = [
    'base' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => []],
    'standard' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'course' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre', 'options' => ['langmenu' => true]],
    'coursecategory' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'incourse' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'frontpage' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre', 'options' => ['nonavbar' => true]],
    'admin' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'mycourses' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre', 'options' => ['nonavbar' => true]],
    'mydashboard' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre', 'options' => ['nonavbar' => true, 'langmenu' => true]],
    'mypublic' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'login' => ['theme' => 'boost', 'file' => 'login.php', 'regions' => [], 'options' => ['langmenu' => true]],
    'popup' => ['theme' => 'boost', 'file' => 'columns1.php', 'regions' => [], 'options' => ['nofooter' => true, 'nonavbar' => true]],
    'frametop' => ['theme' => 'boost', 'file' => 'columns1.php', 'regions' => [], 'options' => ['nofooter' => true, 'nocoursefooter' => true]],
    'embedded' => ['theme' => 'boost', 'file' => 'embedded.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'maintenance' => ['theme' => 'boost', 'file' => 'maintenance.php', 'regions' => []],
    'print' => ['theme' => 'boost', 'file' => 'columns1.php', 'regions' => [], 'options' => ['nofooter' => true, 'nonavbar' => false, 'noactivityheader' => true]],
    'redirect' => ['theme' => 'boost', 'file' => 'embedded.php', 'regions' => []],
    'report' => ['theme' => 'boost', 'file' => 'drawers.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
    'secure' => ['theme' => 'boost', 'file' => 'secure.php', 'regions' => ['side-pre'], 'defaultregion' => 'side-pre'],
];
