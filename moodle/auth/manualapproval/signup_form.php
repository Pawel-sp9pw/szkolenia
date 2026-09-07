<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class auth_manualapproval_signup_form extends moodleform {
    protected function definition(): void {
        global $CFG;

        $mform = $this->_form;

        $mform->addElement('static', 'registrationintro', '', get_string('registrationintro', 'auth_manualapproval'));

        $mform->addElement('text', 'firstname', get_string('firstname'), ['maxlength' => 100, 'size' => 30]);
        $mform->setType('firstname', core_user::get_property_type('firstname'));
        $mform->addRule('firstname', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'lastname', get_string('lastname'), ['maxlength' => 100, 'size' => 30]);
        $mform->setType('lastname', core_user::get_property_type('lastname'));
        $mform->addRule('lastname', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'institution', get_string('company', 'auth_manualapproval'), ['maxlength' => 255, 'size' => 40]);
        $mform->setType('institution', PARAM_TEXT);
        $mform->addRule('institution', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'department', get_string('position', 'auth_manualapproval'), ['maxlength' => 255, 'size' => 40]);
        $mform->setType('department', PARAM_TEXT);
        $mform->addRule('department', get_string('required'), 'required', null, 'client');

        if (!empty($CFG->passwordpolicy)) {
            $mform->addElement('static', 'passwordpolicyinfo', '', print_password_policy());
        }

        $mform->addElement('password', 'password', get_string('password'), [
            'maxlength' => MAX_PASSWORD_CHARACTERS,
            'size' => 20,
            'autocomplete' => 'new-password',
        ]);
        $mform->setType('password', core_user::get_property_type('password'));
        $mform->addRule('password', get_string('required'), 'required', null, 'client');
        $mform->addRule('password', get_string('maximumchars', '', MAX_PASSWORD_CHARACTERS),
            'maxlength', MAX_PASSWORD_CHARACTERS, 'client');

        $mform->addElement('password', 'password2', get_string('passwordagain', 'auth_manualapproval'), [
            'maxlength' => MAX_PASSWORD_CHARACTERS,
            'size' => 20,
            'autocomplete' => 'new-password',
        ]);
        $mform->setType('password2', core_user::get_property_type('password'));
        $mform->addRule('password2', get_string('required'), 'required', null, 'client');

        $manager = new \core_privacy\local\sitepolicy\manager();
        $manager->signup_form($mform);

        $this->set_display_vertical();
        $this->add_action_buttons(true, get_string('createaccount'));
    }

    public function definition_after_data(): void {
        $mform = $this->_form;
        foreach (['firstname', 'lastname', 'institution', 'department'] as $field) {
            $mform->applyFilter($field, 'trim');
        }
    }

    public function validation($data, $files): array {
        global $CFG;

        $errors = parent::validation($data, $files);

        foreach (['firstname', 'lastname', 'institution', 'department'] as $field) {
            if (trim((string)($data[$field] ?? '')) === '') {
                $errors[$field] = get_string('required');
            }
        }

        if (($data['password'] ?? '') !== ($data['password2'] ?? '')) {
            $errors['password2'] = get_string('passwordsdonotmatch', 'auth_manualapproval');
        }

        if (!empty($CFG->passwordpolicy) && !empty($data['password'])) {
            $errmsg = '';
            if (!\core\di::get(\core\authentication\password::class)->check_policy($data['password'], $errmsg)) {
                $errors['password'] = $errmsg;
            }
        }

        return $errors;
    }
}
