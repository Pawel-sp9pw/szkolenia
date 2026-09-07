<?php
$string['pluginname'] = 'Self-registration with administrator approval';
$string['registrationintro'] = 'Create a training account. You do not need to provide an email address. A username will be generated automatically after submitting the form.';
$string['company'] = 'Company';
$string['position'] = 'Position';
$string['passwordagain'] = 'Repeat password';
$string['passwordsdonotmatch'] = 'The passwords do not match.';
$string['registrationcomplete'] = 'Registration complete';
$string['accountawaitingapproval'] = 'Your account has been created and is waiting for administrator approval.';
$string['signupmessage'] = 'Your username: {$a->username}

Account activation may take up to {$a->days} business days. After that, try to log in using the username above and the password you entered during registration. If you have problems, contact the training administrator by phone.';
$string['signupmessagephone'] = 'Your username: {$a->username}

Account activation may take up to {$a->days} business days. After that, try to log in using the username above and the password you entered during registration. If you have problems, call: {$a->phone}.';
$string['continuetologin'] = 'Continue to login';
$string['activationdays'] = 'Account activation time';
$string['activationdays_desc'] = 'Number of business days shown as the maximum account activation time.';
$string['contactphone'] = 'Contact phone';
$string['contactphone_desc'] = 'Optional phone number displayed after registration.';
$string['pendingaccounts'] = 'Accounts awaiting approval';
$string['pendinghelp'] = 'These accounts were created using the self-registration form. Verify the details, approve the account, then assign the user to the appropriate course or courses.';
$string['nopendingaccounts'] = 'There are no accounts awaiting approval.';
$string['approve'] = 'Approve account';
$string['viewprofile'] = 'Edit profile';
$string['userapproved'] = 'The account for {$a} has been approved.';
$string['registeredat'] = 'Registered';
$string['notification_subject'] = 'New Moodle registration';
$string['notification_body'] = 'A new user is waiting for approval.

Name: {$a->fullname}
Username: {$a->username}
Company: {$a->institution}
Position: {$a->department}

Open the pending accounts page in Moodle, verify the details and approve the account.';
$string['notification_small'] = 'New registration: {$a->fullname} ({$a->username})';
$string['messageprovider:newregistration'] = 'New registration notifications';
$string['privacy:metadata'] = 'The plugin does not store personal data outside the standard Moodle user account fields.';
