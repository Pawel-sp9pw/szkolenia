<?php
// This file is part of Moodle - http://moodle.org/

require(__DIR__ . '/../../../config.php');

require_login();
admin_externalpage_setup('authmanualapprovalpending');
require_capability('moodle/user:update', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);

if ($action === 'approve' && $userid > 0) {
    require_sesskey();

    $user = $DB->get_record('user', [
        'id' => $userid,
        'auth' => 'manualapproval',
        'confirmed' => 0,
        'deleted' => 0,
    ], '*', MUST_EXIST);

    $DB->set_field('user', 'confirmed', 1, ['id' => $user->id]);
    \core\event\user_updated::create_from_userid($user->id)->trigger();

    redirect(
        new moodle_url('/auth/manualapproval/pending.php'),
        get_string('userapproved', 'auth_manualapproval', fullname($user)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_title(get_string('pendingaccounts', 'auth_manualapproval'));
$PAGE->set_heading(get_string('pendingaccounts', 'auth_manualapproval'));

$users = $DB->get_records('user', [
    'auth' => 'manualapproval',
    'confirmed' => 0,
    'deleted' => 0,
], 'timecreated ASC');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pendingaccounts', 'auth_manualapproval'));
echo $OUTPUT->notification(get_string('pendinghelp', 'auth_manualapproval'), 'info');

if (!$users) {
    echo $OUTPUT->notification(get_string('nopendingaccounts', 'auth_manualapproval'), 'notifysuccess');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('fullname'),
    get_string('username'),
    get_string('company', 'auth_manualapproval'),
    get_string('position', 'auth_manualapproval'),
    get_string('registeredat', 'auth_manualapproval'),
    get_string('actions'),
];

foreach ($users as $user) {
    $approveurl = new moodle_url('/auth/manualapproval/pending.php', [
        'action' => 'approve',
        'userid' => $user->id,
        'sesskey' => sesskey(),
    ]);
    $profileurl = new moodle_url('/user/editadvanced.php', ['id' => $user->id, 'course' => SITEID]);

    $actions = $OUTPUT->single_button($approveurl, get_string('approve', 'auth_manualapproval'), 'post');
    $actions .= html_writer::div(html_writer::link($profileurl, get_string('viewprofile', 'auth_manualapproval')), 'mt-2');

    $table->data[] = [
        fullname($user),
        s($user->username),
        s($user->institution),
        s($user->department),
        userdate($user->timecreated),
        $actions,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
