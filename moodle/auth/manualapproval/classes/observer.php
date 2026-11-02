<?php
namespace auth_manualapproval;

defined('MOODLE_INTERNAL') || die();

class observer {
    public static function course_created(\core\event\course_created $event): void {
        portal_wiedzy::ensure_course_enrolment((int)$event->objectid);
    }

    public static function course_updated(\core\event\course_updated $event): void {
        // Obsługuje również ręczne przeniesienie istniejącego kursu
        // do kategorii Portal Wiedzy.
        portal_wiedzy::ensure_course_enrolment((int)$event->objectid);
    }
}
