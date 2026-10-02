<?php
// FUB: raport osób, które potwierdziły zapoznanie z dokumentem Portalu Wiedzy.

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('authmanualapprovalportalreport');
require_capability('moodle/site:config', context_system::instance());

$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_title(get_string('portalreport', 'auth_manualapproval'));
$PAGE->set_heading(get_string('portalreport', 'auth_manualapproval'));

$courses = $DB->get_records_select(
    'course',
    'idnumber LIKE :pattern',
    ['pattern' => 'FUB-PW-COURSE-%'],
    'fullname ASC',
    'id,fullname,idnumber'
);

$selectedcourse = null;
if ($courseid) {
    $selectedcourse = $courses[$courseid] ?? null;
    if (!$selectedcourse) {
        throw new moodle_exception('invalidcourseid');
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('portalreport', 'auth_manualapproval'));
echo html_writer::tag('p', get_string('portalreport_help', 'auth_manualapproval'), ['class' => 'text-muted']);

$options = [0 => get_string('choosecourse', 'auth_manualapproval')];
foreach ($courses as $course) {
    $options[$course->id] = format_string($course->fullname);
}

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/auth/manualapproval/report_portal.php'))->out(false),
    'class' => 'mb-4',
]);
echo html_writer::start_div('d-flex gap-2 align-items-end flex-wrap');
echo html_writer::start_div();
echo html_writer::label(get_string('course'), 'id_courseid', false, ['class' => 'form-label']);
echo html_writer::select($options, 'courseid', $courseid, false, [
    'id' => 'id_courseid',
    'class' => 'form-select',
]);
echo html_writer::end_div();
echo html_writer::tag('button', get_string('showreport', 'auth_manualapproval'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');

if ($selectedcourse) {
    $sql = "
        SELECT u.id,
               u.firstname,
               u.lastname,
               u.username,
               u.email,
               cc.timecompleted
          FROM {course_completions} cc
          JOIN {user} u ON u.id = cc.userid
         WHERE cc.course = :courseid
           AND cc.timecompleted > 0
           AND u.deleted = 0
           AND u.suspended = 0
      ORDER BY u.lastname ASC, u.firstname ASC, u.username ASC
    ";
    $records = $DB->get_records_sql($sql, ['courseid' => $selectedcourse->id]);

    echo $OUTPUT->heading(format_string($selectedcourse->fullname), 3);
    echo html_writer::tag(
        'p',
        get_string('portalreport_count', 'auth_manualapproval', count($records)),
        ['class' => 'mb-3']
    );

    if (!$records) {
        echo $OUTPUT->notification(get_string('portalreport_none', 'auth_manualapproval'), 'info');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-striped';
        $table->head = [
            get_string('lastname'),
            get_string('firstname'),
            get_string('username'),
            get_string('email'),
            get_string('acknowledgedat', 'auth_manualapproval'),
        ];

        foreach ($records as $record) {
            $table->data[] = [
                s($record->lastname),
                s($record->firstname),
                s($record->username),
                s($record->email),
                userdate((int)$record->timecompleted, get_string('strftimedatetimeshort')),
            ];
        }

        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
