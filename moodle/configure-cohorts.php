<?php
// Creates FUB system cohorts and links them to RODO courses when those courses exist.

if (!defined('MOODLE_INTERNAL')) {
    die();
}

require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->libdir . '/enrollib.php');

$systemcontext = context_system::instance();

$fubcohorts = [
    'FUB-PERSONEL-MEDYCZNY' => 'Personel medyczny',
    'FUB-REJESTRACJA' => 'Rejestracja',
    'FUB-IT' => 'IT',
    'FUB-MARKETING' => 'Marketing',
    'FUB-KSIEGOWOSC' => 'Księgowość',
    'FUB-MEDYCZNY' => 'Medyczny',
    'FUB-TECHNICZNO-MAJATKOWY' => 'Techniczno-majątkowy',
    'FUB-KADRY' => 'Kadry',
    'FUB-CONTROLLING' => 'Controlling',
    'FUB-ADMINISTRACJA' => 'Administracja',
];

$cohortids = [];
foreach ($fubcohorts as $idnumber => $name) {
    $cohort = $DB->get_record('cohort', ['idnumber' => $idnumber]);
    if (!$cohort) {
        $record = (object)[
            'contextid' => $systemcontext->id,
            'name' => $name,
            'idnumber' => $idnumber,
            'description' => 'Kohorta szkoleniowa FUB: ' . $name,
            'descriptionformat' => FORMAT_PLAIN,
            'visible' => 1,
        ];
        $cohortid = cohort_add_cohort($record);
        $cohort = $DB->get_record('cohort', ['id' => $cohortid], '*', MUST_EXIST);
        echo "Utworzono kohortę: {$name}\n";
    } else if ($cohort->name !== $name || (int)$cohort->contextid !== (int)$systemcontext->id) {
        $cohort->name = $name;
        $cohort->contextid = $systemcontext->id;
        cohort_update_cohort($cohort);
    }
    $cohortids[$idnumber] = (int)$cohort->id;
}

// Mapowanie kohort na pięć kursów RODO. Jeżeli kurs nie istnieje jeszcze,
// synchronizacja zostanie utworzona przy kolejnym uruchomieniu configure.php/finalizera.
$coursemap = [
    'FUB-PERSONEL-MEDYCZNY' => 'RODO-MED',
    'FUB-REJESTRACJA' => 'RODO-REJ',
    'FUB-IT' => 'RODO-IT',
    'FUB-MARKETING' => 'RODO-MKT',
    'FUB-KSIEGOWOSC' => 'RODO-ADM',
    'FUB-MEDYCZNY' => 'RODO-ADM',
    'FUB-TECHNICZNO-MAJATKOWY' => 'RODO-ADM',
    'FUB-KADRY' => 'RODO-ADM',
    'FUB-CONTROLLING' => 'RODO-ADM',
    'FUB-ADMINISTRACJA' => 'RODO-ADM',
];

$cohortplugin = enrol_get_plugin('cohort');
$studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id,shortname');
if ($cohortplugin && $studentrole) {
    foreach ($coursemap as $cohortidnumber => $courseidnumber) {
        $course = $DB->get_record('course', ['idnumber' => $courseidnumber]);
        if (!$course) {
            continue;
        }

        $cohortid = $cohortids[$cohortidnumber];
        $existing = $DB->get_record('enrol', [
            'enrol' => 'cohort',
            'courseid' => $course->id,
            'customint1' => $cohortid,
        ]);
        if (!$existing) {
            $instanceid = $cohortplugin->add_instance($course, [
                'status' => ENROL_INSTANCE_ENABLED,
                'roleid' => $studentrole->id,
                'customint1' => $cohortid,
                'customint2' => 0,
            ]);
            if ($instanceid) {
                echo "Powiązano kohortę {$fubcohorts[$cohortidnumber]} z kursem {$courseidnumber}.\n";
            }
        }
    }
}
