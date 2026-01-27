<?php
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

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
    // Setup API Url with a date filter that only pulls in the records from N minutes ago
    $mins = '-' . $datefilter . ' minutes';
    $time_minus_mins = date('Y-m-d H:i:s', strtotime($mins));
    $encoded_time = urlencode($time_minus_mins);
    $apiurlfiltered = $apiurl . '?%24orderby=COURSE_STATE_DATE,date_created+asc';
    $apiurlfiltered .= '&%24filter=date_created+gt+%27' . $encoded_time .'%27';

    // Make API call.
    $options = array(
        'RETURNTRANSFER' => 1,
        'HEADER' => 0,
        'FAILONERROR' => 1,
    );
    $header = array('x-cdata-authtoken: ' . $apitoken);
    $curl = new curl();
    $curl->setHeader($header);
    $response = $curl->get($apiurlfiltered, $options);

    if ($curl->get_errno()) {
        mtrace('PSA Enrol Sync: API request failed: ' . $apiurlfiltered);
        // #TODO log these?
        return;
    }

    $data = json_decode($response, true);

    if (empty($data)) {
        mtrace('PSA Enrol Sync: No data received from API: ' . print_r($response, true));
        // #TODO log these?
        return;
    }

    // Set up variables for type count logging.
    $typecounts = [];
    $recordcount = 0;
    $enrolcount = 0;
    $suspendcount = 0;
    $errorcount = 0;
    $skippedcount = 0;
    
    // This is the primary loop where we start to look at each record
    foreach ($data['value'] as $record) {
        $recordcount++;
        // Process each record. Returns the enrolment_type for logging
        $action = process_enrolment_record($record);
        $typecounts[] = $action;
    }

    // Loop through to pull out how many enrols and drops etc. respectively.
    foreach($typecounts as $t) {
        if($t == 'Enrol') $enrolcount++;
        if($t == 'Suspend') $suspendcount++;
        if($t == 'Error') $errorcount++;
        if($t == 'Skipped') $skippedcount++;
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
            'skippedcount' => $skippedcount
        ];
    $DB->insert_record('local_psaelmsync_runs', (object)$log);

    // Check for the time since the last enrolment or suspend. 
    // If it's been more than N hours then send an email to the 
    // admin list notifying them that the bridge might be blocked
    // on the ELM side.
    check_last_enrolment_or_suspend($notificationhours);
    
}

function process_enrolment_record($record) {
    
    global $DB;
    /**
     * Sample record 
     *{
     *     "COURSE_IDENTIFIER": 40972,
     *     "COURSE_STATE": "Enrol",
     *     "COURSE_STATE_DATE": "2025-05-12T10:50:18.000-07:00",
     *     "GUID": "5F421FC1A510420B9A19A46D24069FFD",
     *     "COURSE_SHORTNAME": "ITEM-2625-1",
     *     "date_created": "2025-05-12T11:00:20.000-07:00",
     *     "date_deleted": null,
     *     "date_updated": null,
     *     "EMAIL": "allan.haggett@gov.bc.ca",
     *     "FIRST_NAME": "Allan",
     *     "LAST_NAME": "Haggett",
     *     "USER_EFFECTIVE_DATE": "2017-02-07",
     *     "USER_STATE": "ACTIVE",
     *     "record_enrol_id": 39861,
     *     "OPRID": "AHAGGETT",
     *     "PERSON_ID": 115000,
     *     "ENROLMENT_ID": 2627101,
     *     "ACTIVITY_ID": 21177,
     *     "COURSE_LONG_NAME": "B.C. Provincial Government Essentials",
     *     "COURSE_START_DATE": null
     * },
     */
    
    // CData doesn't currently supply a unique record ID yet, so we just make one up.
    // In milliseconds.
    $record_id = floor(microtime(true) * 1000); // (int) $record['record_id'];
    // In current state the plan is to use the USER_STATE field to hold the 
    // enrolment ID. At some point hopefully we'll get away from the spaghetti 
    // that is our field mapping. In the meantime, we're just faking an ID.
    $enrolment_id = (int) $record['ENROLMENT_ID'];
    // The rest map to CData fields
    $record_date_created = $record['date_created'];
    $elm_course_id = (int) $record['COURSE_IDENTIFIER'];
    $enrolment_status = $record['COURSE_STATE'];
    $class_code = $record['COURSE_SHORTNAME'];
    $first_name = $record['FIRST_NAME'];
    $last_name = $record['LAST_NAME'];
    $user_email = $record['EMAIL'];
    $user_guid = $record['GUID'];
    
    // We need to create a unique ID here by hashing the relevent info.
    // When we have access to them, we'll want to include $enrolment_id and 
    // record_id in this hash for extra-good unqiueness but right now we're 
    // dynamically generating them (should we just not maybe?) which 
    // would break this(?), so just leaving it out for the time being. I think the 
    // data included does a good enough job for now. Two identical records coming 
    // through is enough of an edge case and wouldn't really have an adverse
    // effect anyhow.
    $hash_content = $record_date_created . $elm_course_id . $class_code . $enrolment_status . $user_guid . $user_email;
    $hash = hash('sha256', $hash_content);
    // This is expensive part of doing it this way where we touch the database  
    // for every single record in the feed, but it's probably the least expensive 
    // but verifyable method that we can come up with; certainly less expensive
    // than updating each record with a callback.
    $hashcheck = $DB->get_record('local_psaelmsync_logs', ['sha256hash' => $hash], '*', IGNORE_MULTIPLE);

    // Does the hash exist in the table? If so we want to skip this record as 
    // we've already processed it, but still counting it as we go.
    if ($hashcheck) {
        $s = 'Skipped';
        return $s;
    }

    // If there's no course with this IDNumber (note: not the Moodle course ID 
    // but ELM's course ID), skip record. We want to log that this is happening 
    // and send an email to the admin list.
    if (!$course = $DB->get_record('course', array('idnumber' => $elm_course_id))) {
        // We haven't done a user lookup yet so 
        $user_id = 0;
        log_record($record_id, 
                    $hash, 
                    $record_date_created, 
                    0, // we don't have it to provide.
                    $elm_course_id, 
                    $class_code, 
                    $enrolment_id, 
                    $user_id, 
                    $first_name, 
                    $last_name, 
                    $user_email, 
                    $user_guid, 
                    'Course not found',
                    'Error');
        
        send_failure_notification('coursefail', $first_name, $last_name, $user_email, $elm_course_id);
        $e = 'Error';
        return $e;
    }

    // Check if user exists by GUID     .
    if ($user = $DB->get_record('user', array('idnumber' => $user_guid),'*')) {

        $user_id = $user->id;

    } else {
        // Attempt to create a new user, handle any exceptions gracefully.
        try {

            $user = create_user($first_name, $last_name, $user_email, $user_guid);
            $user_id = $user->id;

        } catch (Exception $e) {

            $error_message = $e->getMessage();

            // Do an additional lookup at this point to see if the provided email 
            // exists and if it does send that account info along as well as the issue
            // is likely a GUID change.
            if ($useremail = $DB->get_record('user', array('email' => $user_email),'*')) {
                
                $error_message = 'User email is associated with another profile.';
                $error_message .= 'https://learning.gww.gov.bc.ca/user/view.php?id=' . $useremail->id . '';
                $error_message .= 'This is likely a GUID change issue.';

            }

            // Log the error
            log_record($record_id, 
                        $hash, 
                        $record_date_created, 
                        $course->id, 
                        $elm_course_id, 
                        $class_code, 
                        $enrolment_id, 
                        0, // No user ID since creation failed
                        $first_name, 
                        $last_name, 
                        $user_email, 
                        $user_guid, 
                        'User create failure',
                        'Error');
            
            // Send an email notification
            send_failure_notification('userfail', $first_name, $last_name, $user_email, $error_message);

            // Return to skip further processing of this record.
            $e = 'Error';
            return $e;
        }
    }

    // Even if we find a user by the provided GUID, we also need to check
    // to see if the email address associated with the account is consistent
    // with this CData record. If it isn't then we notify admins and error out.
    if (strtolower($user->email) != strtolower($user_email)) {

        // Check if another user already has the new email address
        $useremailcheck = $DB->get_record('user', ['email' => $user_email]);

        // We base usernames on email addresses, so we want to be double-sure
        // and also check to ensure that the username doesn't exist. 
        // These are almost certainly going to be the same, but weird things happen 
        // around here so we just make sure.
        // Generate the new username based on the new email address for comparison
        $new_username = strtolower($user_email);
        // If we need to optimize this process at any point, this lookup might 
        // be considered a bit redundant.
        $username_exists = $DB->record_exists('user', ['username' => $new_username]);

        // We're going to send an email either way, but the message will vary.
        $message = 'We\'ve come across a learner with an account with the given GUID, but ';
        $message .= 'when we lookup the provided email address, it doesn\'t match and ';

        if (!$useremailcheck && !$username_exists) {

            $message .= 'there is no other account by that username/email address.<br>';

        } else { // There IS another account with this email or username
            
            // Should we do further checks here to see if the other account 
            // has ever been logged into? Is it enrolled in anything else?
            // If it's a blank account maybe make the decision to delete it.
            // 
            if ($useremailcheck) {
                $message .= 'there is <a href="/user/view.php?id=' . $useremailcheck->id . '">';
                $message .= 'another account</a> with this email address.<br>';
            }
            // As we base our usernames on email address, this lookup will almost
            // always return the exact same user. If the username exists AND it's 
            // not the same account then add addtional context.
            if ($username_exists && $useremailcheck->id != $username_exists->id) {
                $message .= 'As well, the username derived from the new email ';
                $message .= '<a href="/user/view.php?id=' . $username_exists->id . '">is already in use</a>.<br>';
            }
        }
        $message .= '<hr>';
        $message .= 'CData name/GUID ' . $first_name . ' ' . $last_name . ': ' . $user_guid . '<br>';
        $message .= 'Existing email: <' . $user->email . '>' . '<br>';
        $message .= 'Email from CData record: <' . $user_email . '><br><br>';
        $message .= 'Please investigate further.';

        // Given that there's a conflict here that needs to be resolved by
        // a human, we error out at this point logging it and sending the notification.
        // Log the error
        log_record($record_id, 
                    $hash, 
                    $record_date_created, 
                    $course->id, 
                    $elm_course_id, 
                    $class_code, 
                    $enrolment_id, 
                    $user->id,
                    $first_name, 
                    $last_name, 
                    $user_email, 
                    $user_guid, 
                    'Email Mistatch',
                    'Error');
        
        // Send the email notification
        send_failure_notification('emailmismatch', $first_name, $last_name, $user_email, $message);

        $e = 'Error';
        return $e;
    }

    if ($enrolment_status == 'Enrol') {
        // Enrol the user in the course.
        $enrol = enrol_get_plugin('manual');
        if ($enrol) {
            $instance = $DB->get_record('enrol', array('courseid' => $course->id, 'enrol' => 'manual'), '*', MUST_EXIST);
            $enrol->enrol_user($instance, $user_id, $instance->roleid, 0, 0, ENROL_USER_ACTIVE);
        }

        send_welcome_email($user, $course);

        $action = 'Enrol';
        // #TODO If this is a record where there was an email mismatch and the 
        // account was updated, we should perhaps not log it as a normal
        // 'enrol'? NOTE that this has repercussions for counts we do for 
        // enrol, drop, error, skip and to add a new type of action requires
        // updating the DB with a corresponding field in _runs table.
        // if($emailmismatch > 0) {
        //     $action = 'Enrol Update Email';
        // }
        log_record($record_id, 
                    $hash, 
                    $record_date_created, 
                    $course->id, 
                    $elm_course_id, 
                    $class_code, 
                    $enrolment_id, 
                    $user_id, 
                    $first_name, 
                    $last_name, 
                    $user_email, 
                    $user_guid, 
                    $action, 
                    'Success');

    } elseif ($enrolment_status == 'Suspend') {
        // Suspend the user in the course.
        suspend_user_in_course($user_id, $course->id, $elm_course_id);

        // #TODO send a drop email?? 
        
        $action = 'Suspend';
        // See above comment in enrol about this.
        // if($emailmismatch > 0) {
        //     $action = 'Suspend Update Email';
        // }
        log_record($record_id, 
                    $hash, 
                    $record_date_created, 
                    $course->id,
                    $elm_course_id, 
                    $class_code, 
                    $enrolment_id, 
                    $user_id, 
                    $first_name, 
                    $last_name, 
                    $user_email, 
                    $user_guid, 
                    $action, 
                    'Success');
    }
    
    // We return the enrolment_status so that we can count enrols and suspends
    // when we log the run.
    return $enrolment_status;

}

function create_user($first_name, $last_name, $email, $guid) {
    global $DB;

    $user = new stdClass();
    $user->auth = 'oauth2';
    $uname = strtolower($email);
    $user->username = $uname;
    $user->mnethostid = 1;
    $user->password = hash_internal_user_password(random_string(8));
    $user->firstname = $first_name;
    $user->lastname = $last_name;
    $user->email = $email;
    $user->idnumber = $guid;
    $user->confirmed = 1;
    $user->emailformat = 1; // 1 for HTML, 0 for plain text
    $user->timecreated = time();
    $user->timemodified = time();

    $user->id = user_create_user($user, true, false);

    return $user;
}

function suspend_user_in_course($user_id, $course_id, $elm_course_id) {
    global $DB;

    $enrol_instance = $DB->get_record('enrol', array('courseid' => $course_id, 'enrol' => 'manual'), '*', IGNORE_MISSING);
    if ($enrol_instance) {
        $user_enrolment = $DB->get_record('user_enrolments', array('enrolid' => $enrol_instance->id, 'userid' => $user_id), '*', IGNORE_MISSING);
        if ($user_enrolment) {
            $user_enrolment->status = 1; // 1 indicates suspended status
            $user_enrolment->timemodified = time();
            $DB->update_record('user_enrolments', $user_enrolment);
        }
    }
}


function send_welcome_email($user, $course) {

    $subject = "Welcome to {$course->fullname}";

       // HTML version of the message
    $html_message = <<<EMAIL
       <p>Hi {$user->firstname},</p>
       <p>You have been enrolled in <strong>{$course->fullname}</strong>.</p>
       <p>Please click the following link, signing in using your IDIR credentials:</p>
       <p><a href="https://learning.gww.gov.bc.ca/course/view.php?id={$course->id}" style="font-size: 20px;">Access this course on PSA Moodle</a></p>
       <p>If you have any issues with the course materials, please <a href="http://www.gov.bc.ca/myhr/contact">submit an AskMyHR request</a> and select one of the subcategories under "Learning Centre".</p>
       <p>Regards,<br>PSA Moodle Team</p>
   EMAIL;

    $plaintext_message = <<<EMAIL
        Hi $user->firstname,\n
        You have been enrolled in $course->fullname.\n
        Please click the following link, signing in using your IDIR credentials:\n
        https://learning.gww.gov.bc.ca/course/view.php?id=$course->id\n
        If you have any issues with the course materials, please submit an AskMyHR request at http://www.gov.bc.ca/myhr/contact and select one of the subcategories under "Learning Centre".\n\n
        Regards,
        PSA Moodle Team
    EMAIL;

    // Force HTML email regardless of user preference
    $user->mailformat = 1; // Force HTML format

    email_to_user($user, core_user::get_support_user(), $subject, $plaintext_message, $html_message);
}

function log_record($record_id, $hash, $record_date_created, $course_id, $elm_course_id, $class_code, $enrolment_id, $user_id, $first_name, $last_name, $user_email, $user_guid, $action, $status) {
    global $DB;

    // Ensure course_id is valid before lookup
    $coursefullname = 'Not found!';
    if (!empty($course_id) && is_numeric($course_id)) {
        if ($course = $DB->get_record('course', array('id' => $course_id), 'fullname')) {
            $coursefullname = $course->fullname;
        }
    }

    $log = new stdClass();
    $log->record_id = $record_id;
    $log->sha256hash = $hash;
    $log->record_date_created = $record_date_created;
    $log->course_id = $course_id;
    $log->elm_course_id = $elm_course_id;
    $log->class_code = $class_code;
    $log->course_name = $coursefullname;
    $log->user_id = $user_id;
    $log->user_firstname = $first_name;
    $log->user_lastname = $last_name;
    $log->user_guid = $user_guid; 
    $log->user_email = $user_email;
    $log->elm_enrolment_id = $enrolment_id;
    $log->action = $action;
    $log->status = $status;
    $log->timestamp = time();

    $DB->insert_record('local_psaelmsync_logs', $log);
}

// Function to send failure notification email
function send_failure_notification($type, $first_name, $last_name, $email, $error_message) {
    global $CFG;

    // Get the list of email addresses from admin settings
    $admin_emails = get_config('local_psaelmsync', 'notificationemails');
    
    if (!empty($admin_emails)) {
        $emails = explode(',', $admin_emails);
        if($type == 'userfail') {

            $subject = "User Creation Failure Notification";
            $message = "A failure occurred during user creation.\n\n";
            $message .= "Details:\n";
            $message .= "Name: {$first_name} {$last_name}\n";
            $message .= "Email: {$email}\n";
            $message .= "Error: {$error_message}\n\n";
            $message .= "Please investigate the issue.";

        } elseif($type == 'coursefail') {

            $subject = "Course Lookup Failure Notification";
            $message = "A failure occurred during course lookup.\n\n";
            $message .= "Details:\n";
            $message .= "Course ID: {$error_message}\n";
            $message .= "Name: {$first_name} {$last_name}\n";
            $message .= "Email: {$email}\n\n";
            $message .= "Please investigate the issue.";

        } elseif($type == 'emailmismatch') {

            $subject = "User email mismatch";
            $message = "A discrepancy was found when enrolling a user.\n\n";
            $message .= "{$error_message}\n";
            $message .= "Name: {$first_name} {$last_name}\n";
            $message .= "Email: {$email}\n\n";
            $message .= "Please investigate the issue.";

        }
        
        // Create a dummy user object for sending the email
        $dummyuser = new stdClass();
        $dummyuser->email = 'noreply-psalssync@gov.bc.ca';
        $dummyuser->firstname = 'System';
        $dummyuser->lastname = 'Notifier';
        $dummyuser->id = -99; // Dummy user id
        
        foreach ($emails as $admin_email) {
            // Trim to remove any extra whitespace around email addresses
            $admin_email = trim($admin_email);
            
            // Create a recipient user object
            $recipient = new stdClass();
            $recipient->email = $admin_email;
            $recipient->id = -99; // Dummy user id
            $recipient->firstname = 'Admin';
            $recipient->lastname = 'User';
            $recipient->mailformat = 1; // Force HTML format
            
            // Send the email
            email_to_user($recipient, $dummyuser, $subject, $message);
        }
    }
}

function check_last_enrolment_or_suspend($notificationhours) {
    global $DB;

    // Calculate the threshold time.
    $threshold_time = time() - ($notificationhours * 3600);

    // Check if the current time is between 6 AM and 6 PM on a weekday.
    $current_time = time();
    $day_of_week = date('N', $current_time); // 1 (for Monday) through 7 (for Sunday)
    $hour_of_day = date('G', $current_time); // 0 through 23

    if ($day_of_week >= 6 || $hour_of_day < 6 || $hour_of_day >= 18) {
        // Do not send notifications outside of 6 AM - 6 PM, Monday to Friday.
        return;
    }

    // Query the last enrolment or suspend record.
    $last_action = $DB->get_record_sql("
        SELECT MAX(timestamp) AS lasttime
        FROM {local_psaelmsync_logs}
        WHERE action IN ('Enrol', 'Suspend')
    ");

    if ($last_action && $last_action->lasttime < $threshold_time) {
        // If the last action was before the threshold, send a notification.
        send_inactivity_notification($notificationhours);

    }
}

function send_inactivity_notification($notificationhours) {
    global $CFG;

    $admin_emails = get_config('local_psaelmsync', 'notificationemails');
    
    if (!empty($admin_emails)) {
        $emails = explode(',', $admin_emails);
        $subject = "PSA Enrol Sync Inactivity Notification";
        $message = "No enrolment or suspension records have been processed in the last {$notificationhours} hours. Please check the system.";

        $dummyuser = new stdClass();
        $dummyuser->email = 'noreply-psalssync@gov.bc.ca';
        $dummyuser->firstname = 'System';
        $dummyuser->lastname = 'Notifier';
        $dummyuser->id = -99;

        foreach ($emails as $admin_email) {
            $admin_email = trim($admin_email);

            $recipient = new stdClass();
            $recipient->email = $admin_email;
            $recipient->id = -99;
            $recipient->firstname = 'Admin';
            $recipient->lastname = 'User';

            email_to_user($recipient, $dummyuser, $subject, $message);
        }
    }
}
