<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Core sync logic for the PSALS Sync plugin.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Check if a given ELM course ID is on the ignore list.
 *
 * @param int|string $elmcourseid The COURSE_IDENTIFIER value to check.
 * @return bool True if the course should be ignored.
 */
function local_psaelmsync_is_ignored_course($elmcourseid) {
    $ignorelist = get_config('local_psaelmsync', 'ignorecourseids');
    if (empty($ignorelist)) {
        return false;
    }
    $ignoredids = array_map('trim', explode(',', $ignorelist));
    return in_array((string)(int)$elmcourseid, $ignoredids, true);
}

/**
 * The primary function to sync enrolments from the ELM system via the CData API.
 *
 * This function is designed to be run as a scheduled task and will:
 * - Fetch enrolment records from the CData API with a date filter.
 * - Process each record to enrol or suspend users in Moodle courses.
 * - Log the results of each record processing in a custom log table.
 * - Send email notifications for any errors encountered during processing.
 * - Log the overall run details including counts of enrolments, suspensions,
 *   errors, and skipped records.
 * - Check for inactivity and send notifications if no enrolments or suspensions
 *   have been processed within a specified timeframe.
 *
 * @return void
 */
function local_psaelmsync_sync() {

    global $DB;

    $runlogstarttime = floor(microtime(true) * 1000);

    // Fetch API URL and token from config.
    $apiurl = get_config('local_psaelmsync', 'apiurl');
    $apitoken = get_config('local_psaelmsync', 'apitoken');
    $datefilter = get_config('local_psaelmsync', 'datefilterminutes');
    $notificationhours = get_config('local_psaelmsync', 'notificationhours');

    if (!$apiurl || !$apitoken) {
        mtrace('PSA Enrol Sync: API URL or Token not set.');
        return;
    }
    // Setup API URL with a date filter that only pulls in records from N minutes ago.
    $mins = '-' . $datefilter . ' minutes';
    $timeminusmins = date('Y-m-d H:i:s', strtotime($mins));
    $encodedtime = urlencode($timeminusmins);
    $apiurlfiltered = $apiurl
        . '?%24orderby=COURSE_STATE_DATE,date_created+asc';
    $apiurlfiltered .= '&%24filter=date_created+gt+%27'
        . $encodedtime
        . '%27+and+USER_STATE+eq+%27ACTIVE%27';

    // Make API call.
    $options = [
        'RETURNTRANSFER' => 1,
        'HEADER' => 0,
        'FAILONERROR' => 1,
    ];
    $header = ['x-cdata-authtoken: ' . $apitoken];
    $curl = new curl();
    $curl->setHeader($header);
    $response = $curl->get($apiurlfiltered, $options);

    if ($curl->get_errno()) {
        mtrace('PSA Enrol Sync: API request failed: '
            . $apiurlfiltered);
        return;
    }

    $data = json_decode($response, true);

    if (empty($data)) {
        mtrace('PSA Enrol Sync: No data received from API: '
            . var_export($response, true));
        return;
    }

    // Set up variables for type count logging.
    $typecounts = [];
    $recordcount = 0;
    $enrolcount = 0;
    $suspendcount = 0;
    $errorcount = 0;
    $skippedcount = 0;

    // This is the primary loop where we start to look at each record.
    foreach ($data['value'] as $record) {
        $recordcount++;
        // Process each record and return the enrolment type for logging.
        $action = process_enrolment_record($record);
        $typecounts[] = $action;
    }

    // Loop through to pull out how many enrols and drops etc. respectively.
    foreach ($typecounts as $t) {
        if ($t == 'Enrol') {
            $enrolcount++;
        }
        if ($t == 'Suspend') {
            $suspendcount++;
        }
        if ($t == 'Error') {
            $errorcount++;
        }
        if ($t == 'Skipped') {
            $skippedcount++;
        }
    }
    // Log the end of the run time.
    $runlogendtime = floor(microtime(true) * 1000);
    $log = [
        'apiurl' => $apiurlfiltered,
        'starttime' => $runlogstarttime,
        'endtime' => $runlogendtime,
        'recordcount' => $recordcount,
        'enrolcount' => $enrolcount,
        'suspendcount' => $suspendcount,
        'errorcount' => $errorcount,
        'skippedcount' => $skippedcount,
    ];
    $DB->insert_record('local_psaelmsync_runs', (object)$log);

    // Check for the time since the last enrolment or suspend.
    // If it has been more than N hours then send an email to the
    // admin list notifying them that the bridge might be blocked
    // on the ELM side.
    check_last_enrolment_or_suspend($notificationhours);
}

/**
 * Process a single enrolment record from the CData API response.
 *
 * Deduplicates by SHA256 hash, looks up or creates the user, enrols or
 * suspends in the matching Moodle course, and logs the outcome.
 *
 * @param array $record A single enrolment record from the CData API.
 * @return string The action taken: Enrol, Suspend, Error, or Skipped.
 */
function process_enrolment_record($record) {

    global $DB;

    // CData does not currently supply a unique record ID yet, so we generate one.
    // Value is in milliseconds.
    $recordid = floor(microtime(true) * 1000);
    // In current state the plan is to use the USER_STATE field to hold the
    // enrolment ID. At some point hopefully we will get away from the spaghetti
    // that is our field mapping. In the meantime, we are just faking an ID.
    $enrolmentid = (int) $record['ENROLMENT_ID'];
    // The rest map to CData fields.
    $recorddatecreated = $record['date_created'];
    $elmcourseid = (int) $record['COURSE_IDENTIFIER'];

    // Check the ignore list before doing anything else with this record.
    if (local_psaelmsync_is_ignored_course($elmcourseid)) {
        return 'Skipped';
    }

    $enrolmentstatus = $record['COURSE_STATE'];
    $classcode = $record['COURSE_SHORTNAME'];
    $firstname = $record['FIRST_NAME'];
    $lastname = $record['LAST_NAME'];
    $useremail = $record['EMAIL'];
    $userguid = $record['GUID'];
    $useroprid = $record['OPRID'];
    $useractivityid = $record['ACTIVITY_ID'];
    $userpersonid = $record['PERSON_ID'];
    $courselongname = $record['COURSE_LONG_NAME'];

    // We need to create a unique ID here by hashing the relevant info.
    // When we have access to them, we will want to include enrolment ID and
    // record ID in this hash for extra uniqueness but right now we are
    // dynamically generating them which would break this, so just leaving
    // it out for the time being. The data included does a good enough job
    // for now. Two identical records coming through is enough of an edge
    // case and would not really have an adverse effect anyhow.
    $hashcontent = $recorddatecreated . $elmcourseid
        . $classcode . $enrolmentstatus . $userguid . $useremail;
    $hash = hash('sha256', $hashcontent);
    // This is the expensive part of doing it this way where we touch the
    // database for every single record in the feed, but it is probably the
    // least expensive but verifiable method that we can come up with;
    // certainly less expensive than updating each record with a callback.
    $hashcheck = $DB->get_record(
        'local_psaelmsync_logs',
        ['sha256hash' => $hash],
        '*',
        IGNORE_MULTIPLE
    );

    // Does the hash exist in the table? If so we want to skip this record
    // as we have already processed it, but still counting it as we go.
    if ($hashcheck) {
        $s = 'Skipped';
        return $s;
    }

    // If there is no course with this IDNumber (note: not the Moodle course
    // ID but ELM's course ID), skip record. We want to log that this is
    // happening and send an email to the admin list.
    if (!$course = $DB->get_record('course', ['idnumber' => $elmcourseid])) {
        // We have not done a user lookup yet.
        $userid = 0;
        log_record(
            $recordid,
            $hash,
            $recorddatecreated,
            0,
            $elmcourseid,
            $classcode,
            $enrolmentid,
            $userid,
            $firstname,
            $lastname,
            $useremail,
            $userguid,
            $useroprid,
            $userpersonid,
            $useractivityid,
            'Course not found',
            'Error'
        );
        // Send the email notification.
        send_failure_notification(
            'coursefail',
            $firstname,
            $lastname,
            $courselongname,
            $useremail,
            $userpersonid,
            $useroprid,
            $useractivityid,
            $classcode,
            'Course not found'
        );
        $e = 'Error';
        return $e;
    }

    // Check if user exists by GUID.
    if ($user = $DB->get_record('user', ['idnumber' => $userguid], '*')) {
        $userid = $user->id;
    } else {
        // Attempt to create a new user, handle any exceptions gracefully.
        try {
            $user = create_user(
                $firstname,
                $lastname,
                $useremail,
                $userguid
            );
            $userid = $user->id;
        } catch (Exception $e) {
            $errormessage = $e->getMessage();

            // Do an additional lookup at this point to see if the provided
            // email exists and if it does send that account info along as
            // well as the issue is likely a GUID change.
            if ($useremaillookup = $DB->get_record('user', ['email' => $useremail], '*')) {
                $errormessage = 'User email is associated with another profile.';
                $errormessage .= 'https://learning.gww.gov.bc.ca/user/view.php?id='
                    . $useremaillookup->id . '';
                $errormessage .= 'This is likely a GUID change issue.';
            }

            // Log the error.
            log_record(
                $recordid,
                $hash,
                $recorddatecreated,
                $course->id,
                $elmcourseid,
                $classcode,
                $enrolmentid,
                0,
                $firstname,
                $lastname,
                $useremail,
                $userguid,
                $useroprid,
                $userpersonid,
                $useractivityid,
                'User create failure',
                'Error'
            );

            // Send an email notification.
            send_failure_notification(
                'userfail',
                $firstname,
                $lastname,
                $courselongname,
                $useremail,
                $userpersonid,
                $useroprid,
                $useractivityid,
                $classcode,
                $errormessage
            );

            // Return to skip further processing of this record.
            $e = 'Error';
            return $e;
        }
    }

    // Even if we find a user by the provided GUID, we also need to check
    // to see if the email address associated with the account is consistent
    // with this CData record. If it is not then we notify admins and error out.
    if (strtolower($user->email) != strtolower($useremail)) {
        // Check if another user already has the new email address.
        $useremailcheck = $DB->get_record('user', ['email' => $useremail]);

        // We base usernames on email addresses, so we want to be double-sure
        // and also check to ensure that the username does not exist.
        // These are almost certainly going to be the same, but weird things
        // happen around here so we just make sure.
        // Generate the new username based on the new email address.
        $newusername = strtolower($useremail);
        // If we need to optimize this process at any point, this lookup
        // might be considered a bit redundant.
        $usernameexists = $DB->record_exists(
            'user',
            ['username' => $newusername]
        );

        // We are going to send an email either way, but the message will vary.
        $message = 'We\'ve come across a learner with an account with the '
            . 'given GUID, but when we lookup the provided email address, '
            . 'it doesn\'t match and ';

        if (!$useremailcheck && !$usernameexists) {
            $message .= 'there is no other account by that '
                . 'username/email address.<br>';
        } else {
            // There IS another account with this email or username.
            if ($useremailcheck) {
                $message .= 'there is <a href="/user/view.php?id='
                    . $useremailcheck->id . '">';
                $message .= 'another account</a> with this '
                    . 'email address.<br>';
            }
            // As we base our usernames on email address, this lookup will
            // almost always return the exact same user. If the username
            // exists AND it is not the same account then add additional
            // context.
            if (
                $usernameexists
                    && $useremailcheck->id != $usernameexists->id
            ) {
                $message .= 'As well, the username derived from the new '
                    . 'email <a href="/user/view.php?id='
                    . $usernameexists->id
                    . '">is already in use</a>.<br>';
            }
        }
        $message .= '<hr>';
        $message .= 'CData name/GUID ' . $firstname . ' '
            . $lastname . ': ' . $userguid . '<br>';
        $message .= 'Existing email: ' . $user->email . '<br>';
        $message .= 'Email from CData record: '
            . $useremail . '<br><br>';
        $message .= 'Please investigate further.';

        // Given that there is a conflict here that needs to be resolved by
        // a human, we error out at this point logging it and sending the
        // notification.
        log_record(
            $recordid,
            $hash,
            $recorddatecreated,
            $course->id,
            $elmcourseid,
            $classcode,
            $enrolmentid,
            $user->id,
            $firstname,
            $lastname,
            $useremail,
            $userguid,
            $useroprid,
            $userpersonid,
            $useractivityid,
            'Email Mistatch',
            'Error'
        );

        // Send the email notification.
        send_failure_notification(
            'emailmismatch',
            $firstname,
            $lastname,
            $courselongname,
            $useremail,
            $userpersonid,
            $useroprid,
            $useractivityid,
            $classcode,
            $message
        );

        $e = 'Error';
        return $e;
    }

    if ($enrolmentstatus == 'Enrol') {
        // Enrol the user in the course.
        $enrol = enrol_get_plugin('manual');
        if ($enrol) {
            $instance = $DB->get_record(
                'enrol',
                ['courseid' => $course->id, 'enrol' => 'manual'],
                '*',
                MUST_EXIST
            );
            $enrol->enrol_user(
                $instance,
                $userid,
                $instance->roleid,
                0,
                0,
                ENROL_USER_ACTIVE
            );
        }

        send_welcome_email($user, $course);

        $action = 'Enrol';
        log_record(
            $recordid,
            $hash,
            $recorddatecreated,
            $course->id,
            $elmcourseid,
            $classcode,
            $enrolmentid,
            $userid,
            $firstname,
            $lastname,
            $useremail,
            $userguid,
            $useroprid,
            $userpersonid,
            $useractivityid,
            $action,
            'Success'
        );
    } else if ($enrolmentstatus == 'Suspend') {
        // Suspend the user in the course.
        suspend_user_in_course($userid, $course->id, $elmcourseid);

        $action = 'Suspend';
        log_record(
            $recordid,
            $hash,
            $recorddatecreated,
            $course->id,
            $elmcourseid,
            $classcode,
            $enrolmentid,
            $userid,
            $firstname,
            $lastname,
            $useremail,
            $userguid,
            $useroprid,
            $userpersonid,
            $useractivityid,
            $action,
            'Success'
        );
    }

    // We return the enrolment status so that we can count enrols and
    // suspends when we log the run.
    return $enrolmentstatus;
}

/**
 * Create a new Moodle user account with OAuth2 authentication.
 *
 * @param string $firstname The user's first name.
 * @param string $lastname The user's last name.
 * @param string $email The user's email address.
 * @param string $guid The user's GUID from ELM.
 * @return stdClass The created user object with its new ID.
 */
function create_user($firstname, $lastname, $email, $guid) {
    global $DB;

    $user = new stdClass();
    $user->auth = 'oauth2';
    $uname = strtolower($email);
    $user->username = $uname;
    $user->mnethostid = 1;
    $user->password = hash_internal_user_password(random_string(8));
    $user->firstname = $firstname;
    $user->lastname = $lastname;
    $user->email = $email;
    $user->idnumber = $guid;
    $user->confirmed = 1;
    // Use HTML email format.
    $user->emailformat = 1;
    $user->timecreated = time();
    $user->timemodified = time();

    $user->id = user_create_user($user, true, false);

    return $user;
}

/**
 * Suspend a user's enrolment in a specific course.
 *
 * @param int $userid The Moodle user ID.
 * @param int $courseid The Moodle course ID.
 * @param int $elmcourseid The ELM course identifier.
 * @return void
 */
function suspend_user_in_course($userid, $courseid, $elmcourseid) {
    global $DB;

    $enrolinstance = $DB->get_record(
        'enrol',
        ['courseid' => $courseid, 'enrol' => 'manual'],
        '*',
        IGNORE_MISSING
    );
    if ($enrolinstance) {
        $userenrolment = $DB->get_record(
            'user_enrolments',
            ['enrolid' => $enrolinstance->id, 'userid' => $userid],
            '*',
            IGNORE_MISSING
        );
        if ($userenrolment) {
            // Status 1 indicates suspended.
            $userenrolment->status = 1;
            $userenrolment->timemodified = time();
            $DB->update_record('user_enrolments', $userenrolment);
        }
    }
}

/**
 * Send a welcome email to a user who has been enrolled in a course.
 *
 * @param stdClass $user The Moodle user object.
 * @param stdClass $course The Moodle course object.
 * @return void
 */
function send_welcome_email($user, $course) {

    $subject = "Welcome to {$course->fullname}";

    // HTML version of the message.
    $courseurl = 'https://learning.gww.gov.bc.ca/course/view.php?id='
        . $course->id;
    $contacturl = 'http://www.gov.bc.ca/myhr/contact';
    $htmlmessage = '<p>Hi ' . $user->firstname . ',</p>'
        . '<p>You have been enrolled in <strong>'
        . $course->fullname . '</strong>.</p>'
        . '<p>Please click the following link, signing in '
        . 'using your IDIR credentials:</p>'
        . '<p><a href="' . $courseurl
        . '" style="font-size: 20px;">'
        . 'Access this course on PSA Moodle</a></p>'
        . '<p>If you have any issues with the course materials, '
        . 'please <a href="' . $contacturl . '">'
        . 'submit an AskMyHR request</a> and select one of the '
        . 'subcategories under "Corporate Learning".</p>'
        . '<p>Regards,<br>PSA Moodle Team</p>';

    $plaintextmessage = 'Hi ' . $user->firstname . ",\n\n"
        . 'You have been enrolled in '
        . $course->fullname . ".\n\n"
        . 'Please click the following link, signing in '
        . "using your IDIR credentials:\n\n"
        . $courseurl . "\n\n"
        . 'If you have any issues with the course materials, '
        . 'please submit an AskMyHR request at '
        . $contacturl . ' and select one of the subcategories '
        . "under \"Corporate Learning\".\n\n"
        . "Regards,\n"
        . 'PSA Moodle Team';

    // Force HTML email regardless of user preference.
    $user->mailformat = 1;

    email_to_user(
        $user,
        core_user::get_support_user(),
        $subject,
        $plaintextmessage,
        $htmlmessage
    );
}

/**
 * Log an enrolment record to the local_psaelmsync_logs table.
 *
 * @param int $recordid The generated record identifier.
 * @param string $hash The SHA256 hash for deduplication.
 * @param string $recorddatecreated The date the record was created in CData.
 * @param int $courseid The Moodle course ID.
 * @param int $elmcourseid The ELM course identifier.
 * @param string $classcode The course short name or class code.
 * @param int $enrolmentid The ELM enrolment identifier.
 * @param int $userid The Moodle user ID.
 * @param string $firstname The user's first name.
 * @param string $lastname The user's last name.
 * @param string $useremail The user's email address.
 * @param string $userguid The user's GUID from ELM.
 * @param string $useroprid The user's OPRID from ELM.
 * @param int $userpersonid The user's person ID from ELM.
 * @param int $useractivityid The user's activity ID from ELM.
 * @param string $action The action taken (Enrol, Suspend, etc.).
 * @param string $status The result status (Success, Error, etc.).
 * @return void
 */
function log_record(
    $recordid,
    $hash,
    $recorddatecreated,
    $courseid,
    $elmcourseid,
    $classcode,
    $enrolmentid,
    $userid,
    $firstname,
    $lastname,
    $useremail,
    $userguid,
    $useroprid,
    $userpersonid,
    $useractivityid,
    $action,
    $status
) {
    global $DB;

    // Ensure course ID is valid before lookup.
    $coursefullname = 'Not found!';
    if (!empty($courseid) && is_numeric($courseid)) {
        if ($course = $DB->get_record('course', ['id' => $courseid], 'fullname')) {
            $coursefullname = $course->fullname;
        }
    }

    $log = new stdClass();
    $log->record_id = $recordid;
    $log->sha256hash = $hash;
    $log->record_date_created = $recorddatecreated;
    $log->course_id = $courseid;
    $log->elm_course_id = $elmcourseid;
    $log->class_code = $classcode;
    $log->course_name = $coursefullname;
    $log->user_id = $userid;
    $log->user_firstname = $firstname;
    $log->user_lastname = $lastname;
    $log->user_email = $useremail;
    $log->user_guid = $userguid;
    $log->oprid = $useroprid;
    $log->person_id = $userpersonid;
    $log->activity_id = $useractivityid;
    $log->elm_enrolment_id = $enrolmentid;
    $log->action = $action;
    $log->status = $status;
    $log->timestamp = time();

    $DB->insert_record('local_psaelmsync_logs', $log);
}

/**
 * Send a failure notification email to configured administrators.
 *
 * @param string $type The type of failure (userfail, coursefail, emailmismatch).
 * @param string $firstname The user's first name.
 * @param string $lastname The user's last name.
 * @param string $coursename The full name of the course.
 * @param string $email The user's email address.
 * @param int $personid The user's person ID from ELM.
 * @param string $oprid The user's OPRID from ELM.
 * @param int $activityid The user's activity ID from ELM.
 * @param string $classcode The course short name or class code.
 * @param string $errormessage The error message describing the failure.
 * @return void
 */
function send_failure_notification(
    $type,
    $firstname,
    $lastname,
    $coursename,
    $email,
    $personid,
    $oprid,
    $activityid,
    $classcode,
    $errormessage
) {
    global $CFG;

    // Get the list of email addresses from admin settings.
    $adminemails = get_config('local_psaelmsync', 'notificationemails');

    if (!empty($adminemails)) {
        $emails = explode(',', $adminemails);
        if ($type == 'userfail') {
            $subject = "User Creation Failure Notification";
            $message = "A failure occurred during user creation.\n\n";
            $message .= "Details:\n";
            $message .= "Name: {$firstname} {$lastname}\n";
            $message .= "Course: {$coursename}\n";
            $message .= "Class Code: {$classcode}\n";
            $message .= "Email: {$email}\n";
            $message .= "Learner ID: {$personid}\n";
            $message .= "IDIR: {$oprid}\n";
            $message .= "Activity ID: {$activityid}\n";
            $message .= "Error: {$errormessage}\n\n";
            $message .= "Please investigate the issue.";
        } else if ($type == 'coursefail') {
            $subject = "Course Lookup Failure Notification";
            $message = "A failure occurred during course lookup.\n\n";
            $message .= "Details:\n";
            $message .= "Course ID: {$errormessage}\n";
            $message .= "Name: {$firstname} {$lastname}\n";
            $message .= "Course: {$coursename}\n";
            $message .= "Class Code: {$classcode}\n";
            $message .= "Email: {$email}\n";
            $message .= "Learner ID: {$personid}\n";
            $message .= "IDIR: {$oprid}\n";
            $message .= "Activity ID: {$activityid}\n";
            $message .= "Please investigate the issue.";
        } else if ($type == 'emailmismatch') {
            $subject = "User email mismatch";
            $message = "A discrepancy was found when enrolling a user.\n\n";
            $message .= "{$errormessage}\n";
            $message .= "Name: {$firstname} {$lastname}\n";
            $message .= "Course: {$coursename}\n";
            $message .= "Class Code: {$classcode}\n";
            $message .= "Email: {$email}\n";
            $message .= "Learner ID: {$personid}\n";
            $message .= "IDIR: {$oprid}\n";
            $message .= "Activity ID: {$activityid}\n";
            $message .= "Please investigate the issue.";
        }

        // Create a dummy user object for sending the email.
        $dummyuser = new stdClass();
        $dummyuser->email = 'noreply-psalssync@gov.bc.ca';
        $dummyuser->firstname = 'System';
        $dummyuser->lastname = 'Notifier';
        // Dummy user ID.
        $dummyuser->id = -99;

        foreach ($emails as $adminemail) {
            // Trim to remove any extra whitespace around email addresses.
            $adminemail = trim($adminemail);

            // Create a recipient user object.
            $recipient = new stdClass();
            $recipient->email = $adminemail;
            // Dummy user ID.
            $recipient->id = -99;
            $recipient->firstname = 'Admin';
            $recipient->lastname = 'User';
            // Force HTML format.
            $recipient->mailformat = 1;

            // Send the email.
            email_to_user($recipient, $dummyuser, $subject, $message);
        }
    }
}

/**
 * Check the time since the last enrolment or suspend action and send
 * an inactivity notification if the threshold has been exceeded.
 *
 * Only sends notifications between 6 AM and 6 PM on weekdays.
 *
 * @param int $notificationhours The number of hours of inactivity before notifying.
 * @return void
 */
function check_last_enrolment_or_suspend($notificationhours) {
    global $DB;

    // Calculate the threshold time.
    $thresholdtime = time() - ($notificationhours * 3600);

    // Check if the current time is between 6 AM and 6 PM on a weekday.
    $currenttime = time();
    // Day of week: 1 for Monday through 7 for Sunday.
    $dayofweek = date('N', $currenttime);
    // Hour of day: 0 through 23.
    $hourofday = date('G', $currenttime);

    if ($dayofweek >= 6 || $hourofday < 6 || $hourofday >= 18) {
        // Do not send notifications outside of 6 AM to 6 PM, Monday to Friday.
        return;
    }

    // Query the last enrolment or suspend record.
    $lastaction = $DB->get_record_sql("
        SELECT MAX(timestamp) AS lasttime
        FROM {local_psaelmsync_logs}
        WHERE action IN ('Enrol', 'Suspend')
    ");

    if ($lastaction && $lastaction->lasttime < $thresholdtime) {
        // If the last action was before the threshold, send a notification.
        send_inactivity_notification($notificationhours);
    }
}

/**
 * Send an inactivity notification email to configured administrators.
 *
 * @param int $notificationhours The number of hours of inactivity that triggered this notification.
 * @return void
 */
function send_inactivity_notification($notificationhours) {
    global $CFG;

    $adminemails = get_config('local_psaelmsync', 'notificationemails');

    if (!empty($adminemails)) {
        $emails = explode(',', $adminemails);
        $subject = "PSA Enrol Sync Inactivity Notification";
        $message = "No enrolment or suspension records have been "
            . "processed in the last {$notificationhours} hours. "
            . "Please check the system.";

        $dummyuser = new stdClass();
        $dummyuser->email = 'noreply-psalssync@gov.bc.ca';
        $dummyuser->firstname = 'System';
        $dummyuser->lastname = 'Notifier';
        $dummyuser->id = -99;

        foreach ($emails as $adminemail) {
            $adminemail = trim($adminemail);

            $recipient = new stdClass();
            $recipient->email = $adminemail;
            $recipient->id = -99;
            $recipient->firstname = 'Admin';
            $recipient->lastname = 'User';

            email_to_user(
                $recipient,
                $dummyuser,
                $subject,
                $message
            );
        }
    }
}
