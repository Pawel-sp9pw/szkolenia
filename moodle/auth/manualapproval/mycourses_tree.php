<?php
// AJAX: drzewo kursów zalogowanego użytkownika dla strony "Moje kursy".

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

require_login();

global $DB, $USER;

header('Content-Type: application/json; charset=utf-8');

$courses = enrol_get_users_courses(
    $USER->id,
    true,
    'id,fullname,shortname,idnumber,category,visible'
);

$categories = $DB->get_records('course_categories', null, '', 'id,name,path,parent,idnumber');
$portalroot = $DB->get_record('course_categories', ['idnumber' => 'FUB-PW'], 'id', IGNORE_MISSING);
$portalrootid = $portalroot ? (int)$portalroot->id : 0;

$portalcourses = array_filter($courses, static function($course): bool {
    return str_starts_with((string)$course->idnumber, 'FUB-PW-COURSE-');
});

$completionmap = [];
if ($portalcourses) {
    $portalids = array_map(static fn($course): int => (int)$course->id, $portalcourses);
    [$insql, $params] = $DB->get_in_or_equal($portalids, SQL_PARAMS_NAMED, 'pc');
    $params['userid'] = $USER->id;
    $records = $DB->get_records_sql(
        "SELECT course, timecompleted
           FROM {course_completions}
          WHERE userid = :userid
            AND course {$insql}",
        $params
    );
    foreach ($records as $record) {
        $completionmap[(int)$record->course] = (int)$record->timecompleted;
    }
}

function auth_manualapproval_category_parts(int $categoryid, array $categories, int $skipthrough = 0): array {
    if (!$categoryid || empty($categories[$categoryid])) {
        return [];
    }

    $path = trim((string)$categories[$categoryid]->path, '/');
    if ($path === '') {
        return [];
    }

    $ids = array_values(array_filter(array_map('intval', explode('/', $path))));
    if ($skipthrough) {
        $position = array_search($skipthrough, $ids, true);
        if ($position !== false) {
            $ids = array_slice($ids, $position + 1);
        }
    }

    $parts = [];
    foreach ($ids as $id) {
        if (!empty($categories[$id])) {
            $parts[] = [
                'id' => $id,
                'name' => format_string($categories[$id]->name),
            ];
        }
    }
    return $parts;
}

function auth_manualapproval_tree_add(array &$tree, array $parts, array $course): void {
    $node =& $tree;
    foreach ($parts as $part) {
        $key = (string)$part['id'];
        if (!isset($node['categories'][$key])) {
            $node['categories'][$key] = [
                'name' => $part['name'],
                'categories' => [],
                'courses' => [],
            ];
        }
        $node =& $node['categories'][$key];
    }
    $node['courses'][] = $course;
    unset($node);
}

function auth_manualapproval_tree_normalise(array $node): array {
    $categories = array_values($node['categories'] ?? []);
    usort($categories, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    foreach ($categories as &$category) {
        $category = auth_manualapproval_tree_normalise($category);
    }
    unset($category);

    $courses = $node['courses'] ?? [];
    usort($courses, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

    return [
        'name' => $node['name'] ?? '',
        'categories' => $categories,
        'courses' => $courses,
    ];
}

$portal = ['name' => 'Portal Wiedzy', 'categories' => [], 'courses' => []];
$training = ['name' => 'Szkolenia', 'categories' => [], 'courses' => []];

foreach ($courses as $course) {
    $isportal = str_starts_with((string)$course->idnumber, 'FUB-PW-COURSE-');
    $parts = auth_manualapproval_category_parts(
        (int)$course->category,
        $categories,
        $isportal ? $portalrootid : 0
    );

    $item = [
        'id' => (int)$course->id,
        'name' => format_string($course->fullname),
        'url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        'completed' => $isportal && !empty($completionmap[(int)$course->id]),
        'timecompleted' => $isportal && !empty($completionmap[(int)$course->id])
            ? userdate($completionmap[(int)$course->id], get_string('strftimedatetimeshort'))
            : '',
    ];

    if ($isportal) {
        auth_manualapproval_tree_add($portal, $parts, $item);
    } else {
        auth_manualapproval_tree_add($training, $parts, $item);
    }
}

echo json_encode([
    'portal' => auth_manualapproval_tree_normalise($portal),
    'training' => auth_manualapproval_tree_normalise($training),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
