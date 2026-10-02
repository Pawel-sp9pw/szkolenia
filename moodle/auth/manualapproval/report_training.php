<?php
// FUB: raport ukończonych szkoleń z oceną i datą ukończenia.

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/gradelib.php');

admin_externalpage_setup('authmanualapprovaltrainingreport');
require_capability('moodle/site:config', context_system::instance());

$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_title(get_string('trainingreport', 'auth_manualapproval'));
$PAGE->set_heading(get_string('trainingreport', 'auth_manualapproval'));

$courses = $DB->get_records_select(
    'course',
    'id <> :siteid AND (idnumber IS NULL OR idnumber NOT LIKE :pattern)',
    ['siteid' => SITEID, 'pattern' => 'FUB-PW-COURSE-%'],
    'fullname ASC',
    'id,fullname,idnumber'
);

if ($courseid && empty($courses[$courseid])) {
    throw new moodle_exception('invalidcourseid');
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('trainingreport', 'auth_manualapproval'));
echo html_writer::tag('p', get_string('trainingreport_help', 'auth_manualapproval'), ['class' => 'text-muted']);

$options = [0 => get_string('alltrainings', 'auth_manualapproval')];
foreach ($courses as $course) {
    $options[$course->id] = format_string($course->fullname);
}

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/auth/manualapproval/report_training.php'))->out(false),
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

$params = ['pwpattern' => 'FUB-PW-COURSE-%'];
$coursewhere = '';
if ($courseid) {
    $coursewhere = ' AND c.id = :courseid';
    $params['courseid'] = $courseid;
}

$sql = "
    SELECT CONCAT(c.id, '-', u.id) AS rowkey,
           c.id AS courseid,
           c.fullname AS coursename,
           u.id AS userid,
           u.firstname,
           u.lastname,
           u.username,
           u.email,
           cc.timecompleted,
           gg.finalgrade,
           gg.timemodified AS gradetimemodified,
           gi.grademax,
           gi.grademin,
           gi.gradepass
      FROM {course} c
      JOIN {grade_items} gi
        ON gi.courseid = c.id
       AND gi.itemtype = 'course'
      JOIN {grade_grades} gg
        ON gg.itemid = gi.id
       AND gg.finalgrade IS NOT NULL
      JOIN {user} u
        ON u.id = gg.userid
 LEFT JOIN {course_completions} cc
        ON cc.course = c.id
       AND cc.userid = u.id
     WHERE c.id <> :siteid
       AND (c.idnumber IS NULL OR c.idnumber NOT LIKE :pwpattern)
       AND u.deleted = 0
       AND (
            cc.timecompleted > 0
            OR (
                gg.finalgrade IS NOT NULL
                AND (gi.gradepass <= 0 OR gg.finalgrade >= gi.gradepass)
            )
       )
       {$coursewhere}
  ORDER BY c.fullname ASC, u.lastname ASC, u.firstname ASC, u.username ASC
";
$params['siteid'] = SITEID;
$records = $DB->get_records_sql($sql, $params);

echo html_writer::tag(
    'p',
    get_string('trainingreport_count', 'auth_manualapproval', count($records)),
    ['class' => 'mb-3']
);

if (!$records) {
    echo $OUTPUT->notification(get_string('trainingreport_none', 'auth_manualapproval'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-striped';
    $table->head = [
        get_string('course'),
        get_string('lastname'),
        get_string('firstname'),
        get_string('username'),
        get_string('email'),
        get_string('grade'),
        get_string('completedat', 'auth_manualapproval'),
    ];

    foreach ($records as $record) {
        $grade = get_string('nograde', 'auth_manualapproval');
        if ($record->finalgrade !== null) {
            $grade = format_float((float)$record->finalgrade, 2);
        }

        $table->data[] = [
            html_writer::link(
                new moodle_url('/course/view.php', ['id' => $record->courseid]),
                format_string($record->coursename)
            ),
            s($record->lastname),
            s($record->firstname),
            s($record->username),
            s($record->email),
            $grade,
            userdate(
                (int)($record->timecompleted ?: $record->gradetimemodified),
                get_string('strftimedatetimeshort')
            ),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
