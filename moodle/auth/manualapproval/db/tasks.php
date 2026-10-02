<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\\auth_manualapproval\\task\\sync_portal_wiedzy_cohort',
        'blocking' => 0,
        'minute' => '17',
        'hour' => '2',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
