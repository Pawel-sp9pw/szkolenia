<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'auth_manualapproval/activationdays',
        get_string('activationdays', 'auth_manualapproval'),
        get_string('activationdays_desc', 'auth_manualapproval'),
        2,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'auth_manualapproval/contactphone',
        get_string('contactphone', 'auth_manualapproval'),
        get_string('contactphone_desc', 'auth_manualapproval'),
        '',
        PARAM_TEXT
    ));
}

$ADMIN->add('accounts', new admin_externalpage(
    'authmanualapprovalpending',
    get_string('pendingaccounts', 'auth_manualapproval'),
    new moodle_url('/auth/manualapproval/pending.php'),
    'moodle/user:update'
));
