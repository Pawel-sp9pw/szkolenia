<?php
// This file is part of Moodle - http://moodle.org/

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

require_login();
admin_externalpage_setup('authmanualapprovalcohorts');
$systemcontext = context_system::instance();
require_capability('moodle/cohort:assign', $systemcontext);

$cohorts = $DB->get_records_select(
    'cohort',
    'idnumber LIKE :prefix AND contextid = :contextid',
    ['prefix' => 'FUB-%', 'contextid' => $systemcontext->id],
    'name ASC',
    'id,name,idnumber,visible'
);

$userid = optional_param('userid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$query = trim(optional_param('q', '', PARAM_RAW_TRIMMED));

if ($action === 'save' && $userid > 0) {
    require_sesskey();

    $user = $DB->get_record('user', [
        'id' => $userid,
        'deleted' => 0,
    ], '*', MUST_EXIST);

    if (isguestuser($user)) {
        throw new moodle_exception('invaliduser');
    }

    $selectedids = optional_param_array('cohortids', [], PARAM_INT);
    $selectedids = array_values(array_unique(array_map('intval', $selectedids)));
    $validids = array_map('intval', array_keys($cohorts));
    $selectedids = array_values(array_intersect($selectedids, $validids));

    $currentids = $DB->get_fieldset_sql(
        'SELECT cm.cohortid
           FROM {cohort_members} cm
           JOIN {cohort} c ON c.id = cm.cohortid
          WHERE cm.userid = :userid
            AND c.idnumber LIKE :prefix
            AND c.contextid = :contextid',
        [
            'userid' => $user->id,
            'prefix' => 'FUB-%',
            'contextid' => $systemcontext->id,
        ]
    );
    $currentids = array_values(array_unique(array_map('intval', $currentids)));

    $toadd = array_diff($selectedids, $currentids);
    $toremove = array_diff($currentids, $selectedids);

    $transaction = $DB->start_delegated_transaction();

    foreach ($toadd as $cohortid) {
        cohort_add_member($cohortid, $user->id);
    }
    foreach ($toremove as $cohortid) {
        cohort_remove_member($cohortid, $user->id);
    }

    $transaction->allow_commit();

    redirect(
        new moodle_url('/auth/manualapproval/cohorts.php', ['userid' => $user->id]),
        get_string('cohortssaved', 'auth_manualapproval', fullname($user)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_title(get_string('manageusercohorts', 'auth_manualapproval'));
$PAGE->set_heading(get_string('manageusercohorts', 'auth_manualapproval'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageusercohorts', 'auth_manualapproval'));
echo $OUTPUT->notification(get_string('manageusercohortshelp', 'auth_manualapproval'), 'info');

if (!$cohorts) {
    echo $OUTPUT->notification(get_string('nocohortsconfigured', 'auth_manualapproval'), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

// Search form.
$searchurl = new moodle_url('/auth/manualapproval/cohorts.php');
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $searchurl->out(false),
    'class' => 'mb-4',
]);
echo html_writer::start_div('input-group');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'q',
    'value' => s($query),
    'class' => 'form-control',
    'placeholder' => get_string('searchuserplaceholder', 'auth_manualapproval'),
    'aria-label' => get_string('searchuserplaceholder', 'auth_manualapproval'),
]);
echo html_writer::tag('button', get_string('search'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');

// Selected user edit form.
if ($userid > 0) {
    $user = $DB->get_record('user', [
        'id' => $userid,
        'deleted' => 0,
    ], '*', MUST_EXIST);

    if (isguestuser($user)) {
        throw new moodle_exception('invaliduser');
    }

    $currentids = $DB->get_fieldset_sql(
        'SELECT cm.cohortid
           FROM {cohort_members} cm
           JOIN {cohort} c ON c.id = cm.cohortid
          WHERE cm.userid = :userid
            AND c.idnumber LIKE :prefix
            AND c.contextid = :contextid',
        [
            'userid' => $user->id,
            'prefix' => 'FUB-%',
            'contextid' => $systemcontext->id,
        ]
    );
    $currentids = array_map('intval', $currentids);

    echo $OUTPUT->heading(get_string('editcohortsfor', 'auth_manualapproval', fullname($user)), 3);
    echo html_writer::div(
        s($user->username) . (!empty($user->email) ? ' · ' . s($user->email) : ''),
        'text-muted mb-3'
    );

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/auth/manualapproval/cohorts.php'))->out(false),
        'class' => 'mb-5',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $user->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    foreach ($cohorts as $cohort) {
        $inputid = 'user-cohort-' . $user->id . '-' . $cohort->id;
        $checkbox = html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'cohortids[]',
            'value' => $cohort->id,
            'id' => $inputid,
            'class' => 'form-check-input me-2',
            'checked' => in_array((int)$cohort->id, $currentids, true) ? 'checked' : null,
        ]);
        $labeltext = s($cohort->name);
        if (!$cohort->visible) {
            $labeltext .= ' (' . get_string('hidden') . ')';
        }
        $label = html_writer::tag('label', $checkbox . $labeltext, [
            'for' => $inputid,
            'class' => 'form-check-label',
        ]);
        echo html_writer::div($label, 'form-check mb-2');
    }

    echo html_writer::tag('button', get_string('savechanges'), [
        'type' => 'submit',
        'class' => 'btn btn-primary mt-2',
    ]);
    echo html_writer::end_tag('form');
}

// Search results. Require at least two characters to avoid dumping the whole user table.
if ($query !== '') {
    if (core_text::strlen($query) < 2) {
        echo $OUTPUT->notification(get_string('searchmintwochars', 'auth_manualapproval'), 'warning');
    } else {
        $like = '%' . $DB->sql_like_escape($query) . '%';
        $fullnameconcat = $DB->sql_concat('firstname', "' '", 'lastname');
        $where = "deleted = 0 AND (
                    " . $DB->sql_like('username', ':username', false, false) . " OR
                    " . $DB->sql_like('firstname', ':firstname', false, false) . " OR
                    " . $DB->sql_like('lastname', ':lastname', false, false) . " OR
                    " . $DB->sql_like($fullnameconcat, ':fullname', false, false) . " OR
                    " . $DB->sql_like('email', ':email', false, false) . "
                  )";
        $params = [
            'username' => $like,
            'firstname' => $like,
            'lastname' => $like,
            'fullname' => $like,
            'email' => $like,
        ];
        $users = $DB->get_records_select('user', $where, $params, 'lastname ASC, firstname ASC', '*', 0, 50);

        if (!$users) {
            echo $OUTPUT->notification(get_string('nousersfound', 'auth_manualapproval'), 'warning');
        } else {
            $table = new html_table();
            $table->head = [
                get_string('fullname'),
                get_string('username'),
                get_string('trainingcohorts', 'auth_manualapproval'),
                get_string('actions'),
            ];

            foreach ($users as $founduser) {
                if (isguestuser($founduser)) {
                    continue;
                }

                $membercohorts = $DB->get_records_sql(
                    'SELECT c.id, c.name
                       FROM {cohort} c
                       JOIN {cohort_members} cm ON cm.cohortid = c.id
                      WHERE cm.userid = :userid
                        AND c.idnumber LIKE :prefix
                        AND c.contextid = :contextid
                   ORDER BY c.name ASC',
                    [
                        'userid' => $founduser->id,
                        'prefix' => 'FUB-%',
                        'contextid' => $systemcontext->id,
                    ]
                );
                $names = array_map(static fn($c) => format_string($c->name), $membercohorts);
                $editurl = new moodle_url('/auth/manualapproval/cohorts.php', [
                    'userid' => $founduser->id,
                    'q' => $query,
                ]);
                $table->data[] = [
                    fullname($founduser),
                    s($founduser->username),
                    $names ? implode(', ', $names) : get_string('nocohortsassigned', 'auth_manualapproval'),
                    html_writer::link($editurl, get_string('editcohorts', 'auth_manualapproval')),
                ];
            }

            echo html_writer::table($table);
        }
    }
}

echo $OUTPUT->footer();
