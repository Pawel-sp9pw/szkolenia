<?php
// FUB: źródło raportu potwierdzeń zapoznania w Portalu Wiedzy.

declare(strict_types=1);

namespace auth_manualapproval\reportbuilder\datasource;

use core_course\reportbuilder\datasource\participants;

/**
 * Portal Wiedzy acknowledgements datasource.
 *
 * Uses course completion, because Portal Wiedzy courses are completed only
 * after submitting the mandatory "Potwierdzenie zapoznania" Choice activity.
 *
 * @package auth_manualapproval
 */
class portal_acknowledgements extends participants {

    public static function get_name(): string {
        return get_string('report_portal_acknowledgements', 'auth_manualapproval');
    }

    protected function initialise(): void {
        global $DB;

        parent::initialise();

        $coursealias = $this->get_entity('course')->get_table_alias('course');
        $this->add_base_condition_sql(
            $DB->sql_like("{$coursealias}.idnumber", ':fubpwidnumber', false),
            ['fubpwidnumber' => 'FUB-PW-COURSE-%']
        );
    }

    public function get_default_columns(): array {
        return [
            'course:coursefullnamewithlink',
            'user:fullnamewithlink',
            'user:email',
            'completion:completed',
            'completion:timecompleted',
        ];
    }

    public function get_default_column_sorting(): array {
        return [
            'course:coursefullnamewithlink' => SORT_ASC,
            'user:fullnamewithlink' => SORT_ASC,
        ];
    }

    public function get_default_filters(): array {
        return [
            'course:courseselector',
            'user:fullname',
            'user:email',
            'completion:completed',
            'completion:timecompleted',
        ];
    }

    public function get_default_conditions(): array {
        return parent::get_default_conditions();
    }

    public function get_default_condition_values(): array {
        return parent::get_default_condition_values();
    }
}
