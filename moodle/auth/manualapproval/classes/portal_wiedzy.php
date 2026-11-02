<?php
namespace auth_manualapproval;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/enrol/cohort/locallib.php');

class portal_wiedzy {
    private const ROOT_CATEGORY_IDNUMBER = 'FUB-PW';
    private const COHORT_IDNUMBER = 'FUB-PORTAL-WIEDZY';

    public static function is_portal_category(int $categoryid): bool {
        global $DB;

        static $rootid = null;
        if ($rootid === null) {
            $rootid = (int)$DB->get_field(
                'course_categories',
                'id',
                ['idnumber' => self::ROOT_CATEGORY_IDNUMBER]
            );
        }

        if ($rootid <= 0) {
            return false;
        }

        if ($categoryid === $rootid) {
            return true;
        }

        $path = (string)$DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if ($path === '') {
            return false;
        }

        $ids = array_values(array_filter(array_map('intval', explode('/', trim($path, '/')))));
        return in_array($rootid, $ids, true);
    }

    public static function ensure_course_enrolment(int $courseid): bool {
        global $DB;

        if ($courseid === SITEID) {
            return false;
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course || !self::is_portal_category((int)$course->category)) {
            return false;
        }

        $cohort = $DB->get_record('cohort', [
            'idnumber' => self::COHORT_IDNUMBER,
            'contextid' => \context_system::instance()->id,
        ]);
        if (!$cohort) {
            return false;
        }

        $existing = $DB->get_record('enrol', [
            'courseid' => $course->id,
            'enrol' => 'cohort',
            'customint1' => $cohort->id,
        ]);

        if ($existing) {
            return false;
        }

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('cohort');
        if (!$plugin) {
            throw new \moodle_exception('Wtyczka enrol_cohort jest niedostępna.');
        }

        $plugin->add_instance($course, [
            'customint1' => $cohort->id,
            'roleid' => $studentrole->id,
            'status' => ENROL_INSTANCE_ENABLED,
        ]);

        $trace = new \null_progress_trace();
        enrol_cohort_sync($trace, $course->id);
        $trace->finished();

        return true;
    }
}
