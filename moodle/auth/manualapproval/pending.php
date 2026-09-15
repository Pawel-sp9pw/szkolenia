<?php
// This file is part of Moodle - http://moodle.org/

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

require_login();
admin_externalpage_setup('authmanualapprovalpending');
require_capability('moodle/user:update', context_system::instance());

$cohorts = $DB->get_records_select(
    'cohort',
    'idnumber LIKE :prefix AND visible = :visible',
    ['prefix' => 'FUB-%', 'visible' => 1],
    'name ASC',
    'id,name,idnumber'
);

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

    $selectedids = optional_param_array('cohortids', [], PARAM_INT);
    $selectedids = array_values(array_unique(array_map('intval', $selectedids)));
    $validids = array_map('intval', array_keys($cohorts));
    $selectedids = array_values(array_intersect($selectedids, $validids));

    if (!$selectedids) {
        redirect(
            new moodle_url('/auth/manualapproval/pending.php'),
            get_string('selectatleastonecohort', 'auth_manualapproval'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $transaction = $DB->start_delegated_transaction();
    foreach ($selectedids as $cohortid) {
        if (!cohort_is_member($cohortid, $user->id)) {
            cohort_add_member($cohortid, $user->id);
        }
    }

    $DB->set_field('user', 'confirmed', 1, ['id' => $user->id]);
    \core\event\user_updated::create_from_userid($user->id)->trigger();
    $transaction->allow_commit();

    $assignednames = [];
    foreach ($selectedids as $cohortid) {
        if (isset($cohorts[$cohortid])) {
            $assignednames[] = format_string($cohorts[$cohortid]->name);
        }
    }

    $message = get_string('userapproved', 'auth_manualapproval', fullname($user));
    if ($assignednames) {
        $message .= ' ' . get_string('assignedcohorts', 'auth_manualapproval', implode(', ', $assignednames));
    }

    redirect(
        new moodle_url('/auth/manualapproval/pending.php'),
        $message,
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

if (!$cohorts) {
    echo $OUTPUT->notification(get_string('nocohortsconfigured', 'auth_manualapproval'), 'notifyproblem');
}

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
    get_string('trainingcohorts', 'auth_manualapproval'),
    get_string('actions'),
];

foreach ($users as $user) {
    $profileurl = new moodle_url('/user/editadvanced.php', ['id' => $user->id, 'course' => SITEID]);

    $form = html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/auth/manualapproval/pending.php'))->out(false),
        'class' => 'm-0',
    ]);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'approve']);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $user->id]);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    $cohorthtml = '';
    foreach ($cohorts as $cohort) {
        $inputid = 'cohort-' . $user->id . '-' . $cohort->id;
        $checkbox = html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'cohortids[]',
            'value' => $cohort->id,
            'id' => $inputid,
            'class' => 'form-check-input me-1',
        ]);
        $label = html_writer::tag('label', $checkbox . s($cohort->name), [
            'for' => $inputid,
            'class' => 'form-check-label d-block mb-1',
        ]);
        $cohorthtml .= html_writer::div($label, 'form-check');
    }

    $form .= $cohorthtml;
    $form .= html_writer::tag('button', get_string('approveandassign', 'auth_manualapproval'), [
        'type' => 'submit',
        'class' => 'btn btn-primary mt-2',
        'disabled' => $cohorts ? null : 'disabled',
    ]);
    $form .= html_writer::end_tag('form');

    $actions = $form;
    $actions .= html_writer::div(html_writer::link($profileurl, get_string('viewprofile', 'auth_manualapproval')), 'mt-2');

    $table->data[] = [
        fullname($user),
        s($user->username),
        s($user->institution),
        s($user->department),
        userdate($user->timecreated),
        $cohorts ? get_string('selectoneormore', 'auth_manualapproval') : '-',
        $actions,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
