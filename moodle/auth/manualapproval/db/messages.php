<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'newregistration' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],
];
