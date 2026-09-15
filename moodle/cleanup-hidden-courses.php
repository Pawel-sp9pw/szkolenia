<?php
// One-off CLI cleanup for FUB mandatory trainings.
// Removes per-user preferences created by Course overview "Remove from view".

define('CLI_SCRIPT', true);

$moodledir = getenv('MOODLE_DIR') ?: '/var/www/moodle';
require(rtrim($moodledir, '/') . '/config.php');

$prefix = 'block_myoverview_hidden_course_';
$like = $DB->sql_like('name', ':pattern', false, false);
$params = ['pattern' => $DB->sql_like_escape($prefix) . '%'];
$count = $DB->count_records_select('user_preferences', $like, $params);

if ($count > 0) {
    $DB->delete_records_select('user_preferences', $like, $params);
}

purge_all_caches();

echo "Usunięto preferencje ukrytych kursów: {$count}.\n";
echo "Kursy wcześniej usunięte z widoku będą ponownie widoczne na pulpicie.\n";
