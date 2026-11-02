<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\core\\event\\course_created',
        'callback' => '\\auth_manualapproval\\observer::course_created',
    ],
    [
        'eventname' => '\\core\\event\\course_updated',
        'callback' => '\\auth_manualapproval\\observer::course_updated',
    ],
];
