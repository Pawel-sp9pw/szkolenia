<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

class auth_plugin_manualapproval extends auth_plugin_base {
    public function __construct() {
        $this->authtype = 'manualapproval';
        $this->config = get_config('auth_manualapproval');
    }

    public function user_login($username, $password) {
        global $CFG, $DB;

        $user = $DB->get_record('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
            'auth' => $this->authtype,
            'deleted' => 0,
        ]);

        if (!$user || empty($user->confirmed) || !empty($user->suspended)) {
            return false;
        }

        return \core\di::get(\core\authentication\password::class)->validate($user, $password);
    }

    public function user_update_password($user, $newpassword) {
        return update_internal_user_password($user, $newpassword);
    }

    public function can_signup() {
        return true;
    }

    public function signup_form() {
        global $CFG;

        require_once($CFG->dirroot . '/auth/manualapproval/signup_form.php');
        return new auth_manualapproval_signup_form(null, null, 'post', '', ['autocomplete' => 'on']);
    }

    public function user_signup($user, $notify = true) {
        global $CFG, $DB, $PAGE, $OUTPUT;

        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->dirroot . '/message/lib.php');

        $plainpassword = $user->password;
        $username = $this->generate_unique_username($user->firstname, $user->lastname);

        $user->username = $username;
        $user->email = $username . '@noemail.invalid';
        $user->auth = $this->authtype;
        $user->confirmed = 0;
        $user->emailstop = 1;
        $user->maildisplay = 0;
        $user->password = \core\di::get(\core\authentication\password::class)->hash($plainpassword);

        if (empty($user->calendartype)) {
            $user->calendartype = $CFG->calendartype;
        }

        $user->id = user_create_user($user, false, false);
        user_add_password_history($user->id, $plainpassword);

        $this->copy_standard_values_to_matching_custom_fields($user);
        profile_save_data($user);

        \core\event\user_created::create_from_userid($user->id)->trigger();
        $this->notify_admins($user);

        if (!$notify) {
            return true;
        }

        $days = (int)get_config('auth_manualapproval', 'activationdays');
        if ($days < 1) {
            $days = 2;
        }
        $phone = trim((string)get_config('auth_manualapproval', 'contactphone'));

        $params = (object)[
            'username' => $username,
            'days' => $days,
            'phone' => $phone,
        ];
        $message = $phone === ''
            ? get_string('signupmessage', 'auth_manualapproval', $params)
            : get_string('signupmessagephone', 'auth_manualapproval', $params);

        $PAGE->navbar->add(get_string('registrationcomplete', 'auth_manualapproval'));
        $PAGE->set_title(get_string('registrationcomplete', 'auth_manualapproval'));
        $PAGE->set_heading($PAGE->course->fullname);

        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string('accountawaitingapproval', 'auth_manualapproval'), 'notifysuccess');
        echo $OUTPUT->box(nl2br(s($message)), 'generalbox');
        echo $OUTPUT->single_button(new moodle_url('/login/index.php'), get_string('continuetologin', 'auth_manualapproval'), 'get');
        echo $OUTPUT->footer();
    }

    public function prevent_local_passwords() {
        return false;
    }

    public function is_internal() {
        return true;
    }

    public function can_change_password() {
        return true;
    }

    public function change_password_url() {
        return null;
    }

    public function can_reset_password() {
        return false;
    }

    public function can_be_manually_set() {
        return true;
    }

    public function is_configured() {
        return true;
    }

    private function generate_unique_username(string $firstname, string $lastname): string {
        global $CFG, $DB;

        $first = $this->normalise_username_part($firstname);
        $last = $this->normalise_username_part($lastname);
        $base = trim($first . '.' . $last, '.');
        if ($base === '') {
            $base = 'uzytkownik';
        }

        $base = core_text::substr($base, 0, 90);
        $candidate = $base;
        $suffix = 2;

        while ($DB->record_exists('user', [
            'username' => $candidate,
            'mnethostid' => $CFG->mnet_localhost_id,
        ])) {
            $suffixtext = (string)$suffix;
            $candidate = core_text::substr($base, 0, 99 - core_text::strlen($suffixtext)) . $suffixtext;
            $suffix++;
        }

        return $candidate;
    }

    private function normalise_username_part(string $value): string {
        $value = trim(core_text::strtolower($value));

        if (function_exists('transliterator_transliterate')) {
            $converted = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        } else if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false && $converted !== '') {
                $value = $converted;
            }
        }

        $value = preg_replace('/[^a-z0-9]+/', '.', $value);
        return trim((string)$value, '.');
    }

    private function copy_standard_values_to_matching_custom_fields(object $user): void {
        global $DB;

        foreach (['institution', 'department'] as $shortname) {
            if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
                $property = 'profile_field_' . $shortname;
                $user->{$property} = $user->{$shortname} ?? '';
            }
        }
    }

    private function notify_admins(object $user): void {
        $admins = get_admins();
        if (!$admins) {
            return;
        }

        $url = new moodle_url('/auth/manualapproval/pending.php');
        $params = (object)[
            'fullname' => fullname($user),
            'username' => $user->username,
            'institution' => $user->institution,
            'department' => $user->department,
        ];

        foreach ($admins as $admin) {
            $message = new \core\message\message();
            $message->component = 'auth_manualapproval';
            $message->name = 'newregistration';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = get_string('notification_subject', 'auth_manualapproval');
            $message->fullmessage = get_string('notification_body', 'auth_manualapproval', $params);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = nl2br(s($message->fullmessage));
            $message->smallmessage = get_string('notification_small', 'auth_manualapproval', $params);
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('pendingaccounts', 'auth_manualapproval');

            try {
                message_send($message);
            } catch (Throwable $e) {
                debugging('Nie udało się wysłać powiadomienia o rejestracji: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
