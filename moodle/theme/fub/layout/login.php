<?php

defined('MOODLE_INTERNAL') || die();

$bodyattributes = $OUTPUT->body_attributes();
$leftinstructions = null;

$templatecontext = [
    'sitename' => format_string($SITE->shortname, true, [
        'context' => context_course::instance(SITEID),
        'escape' => false,
    ]),
    'output' => $OUTPUT,
    'bodyattributes' => $bodyattributes,
    'leftinstructions' => $leftinstructions,
];

echo $OUTPUT->render_from_template('theme_boost/login', $templatecontext);
