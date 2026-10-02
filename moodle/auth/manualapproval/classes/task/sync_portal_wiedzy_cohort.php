<?php
namespace auth_manualapproval\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/cohort/lib.php');

class sync_portal_wiedzy_cohort extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_sync_portal_wiedzy_cohort', 'auth_manualapproval');
    }

    public function execute(): void {
        global $CFG, $DB;

        $systemcontext = \context_system::instance();
        $cohort = $DB->get_record('cohort', [
            'idnumber' => 'FUB-PORTAL-WIEDZY',
            'contextid' => $systemcontext->id,
        ]);

        if (!$cohort) {
            mtrace('FUB-PORTAL-WIEDZY: kohorta nie istnieje, pomijam synchronizację.');
            return;
        }

        $guestid = (int)$CFG->siteguest;
        $users = $DB->get_records_select(
            'user',
            'deleted = 0 AND suspended = 0 AND confirmed = 1 AND id <> :guestid',
            ['guestid' => $guestid],
            '',
            'id'
        );

        $added = 0;
        foreach ($users as $user) {
            if (!cohort_is_member($cohort->id, $user->id)) {
                cohort_add_member($cohort->id, $user->id);
                $added++;
            }
        }

        mtrace("FUB-PORTAL-WIEDZY: dodano użytkowników: {$added}");
    }
}
