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
// The create_user() helper below calls user_create_user(). Moodle lazy-loads
// user/lib.php rather than including it at bootstrap, so declare the dependency here
// instead of relying on a caller having pulled it in first: web requests only had it
// because $OUTPUT->header() requires it, and cron only because the oauth2 auth plugin
// does.
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Get (or lazily create) the per-site secret used to sign round-tripped payloads.
 *
 * Never expose this value in any output. It exists only so the server can prove that a
 * payload it receives back from a browser is one it issued itself.
 *
 * @return string A 64-character hex secret.
 */
function local_psaelmsync_get_payload_key() {
    $key = get_config('local_psaelmsync', 'payload_hmac_key');
    if (empty($key)) {
        $key = bin2hex(random_bytes(32));
        set_config('payload_hmac_key', $key, 'local_psaelmsync');
    }
    return $key;
}

/**
 * Sign a record payload so it can be safely round-tripped through the browser.
 *
 * The enrolment data rendered into the bulk-processing form drives account creation and
 * enrolment, so it must not be accepted back from the client unless the server issued it.
 *
 * @param array $data The record fields to sign.
 * @return string A signed token of the form "<base64 payload>.<hmac>".
 */
function local_psaelmsync_sign_payload(array $data) {
    $payload = base64_encode(json_encode($data));
    $mac = hash_hmac('sha256', $payload, local_psaelmsync_get_payload_key());
    return $payload . '.' . $mac;
}

/**
 * Verify and decode a payload previously signed by local_psaelmsync_sign_payload().
 *
 * @param string $token The signed token submitted by the client.
 * @return array|null The decoded record fields, or null if the token is missing,
 *                    malformed, tampered with, or not valid JSON.
 */
function local_psaelmsync_verify_payload($token) {
    if (!is_string($token) || substr_count($token, '.') !== 1) {
        return null;
    }

    [$payload, $mac] = explode('.', $token, 2);
    $expected = hash_hmac('sha256', $payload, local_psaelmsync_get_payload_key());

    // Constant-time comparison: never leak how much of the signature matched.
    if (!hash_equals($expected, $mac)) {
        return null;
    }

    $decoded = json_decode(base64_decode($payload), true);
    return is_array($decoded) ? $decoded : null;
}

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
    if (!$apiurl || !$apitoken) {
        mtrace('PSA Enrol Sync: API URL or Token not set.');
        return;
    }

    // High-water mark: only fetch records newer than the last one we processed.
    $lastrecordenrolid = get_config('local_psaelmsync', 'last_record_enrol_id');
    if ($lastrecordenrolid === false) {
        $lastrecordenrolid = 45975;
    }
    $apiurlfiltered = $apiurl
        . '?%24orderby=ENROLMENT_ID+asc%2CCOURSE_STATE+asc'
        . '&%24filter=record_enrol_id+gt+' . (int)$lastrecordenrolid
        . '+and+USER_STATE+eq+%27ACTIVE%27';

    // Make API call.
    // NOTE: curl::get() is get($url, $params, $options). Passing $options in the
    // second slot appends them to the query string and sets no curl option at all,
    // which silently disabled FAILONERROR and made HTTP 401/403/502 look like success.
    $options = [
        'RETURNTRANSFER' => 1,
        'HEADER' => 0,
        'FAILONERROR' => 1,
        'CONNECTTIMEOUT' => 10,
        'TIMEOUT' => 120,
    ];
    $header = ['x-cdata-authtoken: ' . $apitoken];
    $curl = new curl();
    $curl->setHeader($header);

    // Follow OData paging. Without this, a response larger than the endpoint's page
    // size was silently truncated while the high-water mark still advanced past the
    // records we never saw, skipping them permanently.
    $apirecords = [];
    $nexturl = $apiurlfiltered;
    $page = 0;
    $maxpages = 50;

    while ($nexturl !== null && $page < $maxpages) {
        $page++;
        $response = $curl->get($nexturl, [], $options);

        $info = $curl->get_info();
        $httpcode = (int)($info['http_code'] ?? 0);
        if ($curl->get_errno() || $httpcode >= 400) {
            // Abort the whole run and process nothing. The high-water mark is not
            // advanced below, so the next run retries this window from the same point.
            mtrace('PSA Enrol Sync: API request failed on page ' . $page
                . ' (HTTP ' . $httpcode . ', errno ' . $curl->get_errno() . '): '
                . $apiurl);
            local_psaelmsync_notify_api_failure($httpcode, $curl->get_errno(), $page);
            return;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['value']) || !is_array($data['value'])) {
            mtrace('PSA Enrol Sync: Unexpected API response shape on page ' . $page
                . ' (' . json_last_error_msg() . ').');
            return;
        }

        $apirecords = array_merge($apirecords, $data['value']);
        $nexturl = isset($data['@odata.nextLink']) ? $data['@odata.nextLink'] : null;
    }

    if ($nexturl !== null) {
        mtrace('PSA Enrol Sync: WARNING stopped at the ' . $maxpages
            . '-page safety limit with more pages available. Remaining records will be'
            . ' picked up next run only if the high-water mark did not advance past them.');
    }

    if (empty($apirecords)) {
        mtrace('PSA Enrol Sync: No records returned from API.');
        return;
    }

    // Set up variables for type count logging.
    $typecounts = [];
    $recordcount = 0;
    $enrolcount = 0;
    $suspendcount = 0;
    $errorcount = 0;
    $skippedcount = 0;
    $maxrecordenrolid = (int)$lastrecordenrolid;
    $minfailedid = null;

    // This is the primary loop where we start to look at each record.
    foreach ($apirecords as $record) {
        $recordcount++;
        // Track the highest record_enrol_id seen in this batch.
        $recordenrolid = (int)($record['record_enrol_id'] ?? 0);
        if ($recordenrolid > $maxrecordenrolid) {
            $maxrecordenrolid = $recordenrolid;
        }
        // Process each record and return the enrolment type for logging.
        $action = process_enrolment_record($record);
        $typecounts[] = $action;

        // Remember the lowest ID that failed so we do not advance past it below.
        // 'Skipped' is a deliberate outcome (ignored course, already processed) and
        // does not need retrying, so it is not treated as a failure here.
        if (
            $action === 'Error' && $recordenrolid > 0
            && ($minfailedid === null || $recordenrolid < $minfailedid)
        ) {
            $minfailedid = $recordenrolid;
        }
    }

    // Update the high-water mark so the next run starts after this batch, but never
    // move it past a record that failed: the mark is the only thing that decides what
    // gets fetched next time, so advancing over a failure skips that learner forever.
    $newmark = ($minfailedid !== null) ? ($minfailedid - 1) : $maxrecordenrolid;
    if ($newmark > (int)$lastrecordenrolid) {
        set_config('last_record_enrol_id', $newmark, 'local_psaelmsync');
    }
    if ($minfailedid !== null) {
        mtrace('PSA Enrol Sync: holding high-water mark at ' . $newmark
            . ' because record_enrol_id ' . $minfailedid . ' failed;'
            . ' records from that point will be retried next run.');
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
}

/**
 * Process a single enrolment record from the CData API response.
 *
 * Looks up or creates the user, enrols or suspends in the matching
 * Moodle course, and logs the outcome.
 *
 * @param array $record A single enrolment record from the CData API.
 * @return string The action taken: Enrol, Suspend, Error, or Skipped.
 */
function process_enrolment_record($record) {

    global $DB;

    $recordid = floor(microtime(true) * 1000);
    $enrolmentid = (int) $record['ENROLMENT_ID'];
    $recordenrolid = (int) ($record['record_enrol_id'] ?? 0);
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

    // Compute hash for transition auditing (stored but no longer queried).
    $hashcontent = $recorddatecreated . $elmcourseid
        . $classcode . $enrolmentstatus . $userguid . $useremail;
    $hash = hash('sha256', $hashcontent);

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
            'Error',
            $recordenrolid
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
                'Error',
                $recordenrolid
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
            'Error',
            $recordenrolid
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
        // Previously this used MUST_EXIST, so a course with no manual enrolment
        // instance threw an uncaught dml_missing_record_exception and killed the whole
        // run. And when enrol_get_plugin() returned null the block was skipped
        // entirely, yet execution still fell through to log 'Success' and send a
        // welcome email to someone who had not been enrolled.
        $enrol = enrol_get_plugin('manual');
        $instance = $enrol ? $DB->get_record(
            'enrol',
            ['courseid' => $course->id, 'enrol' => 'manual'],
            '*',
            IGNORE_MISSING
        ) : false;

        if (!$enrol || !$instance) {
            $message = !$enrol
                ? 'The manual enrolment plugin is disabled site-wide.'
                : "Course {$course->id} ({$classcode}) has no manual enrolment instance.";
            mtrace('PSA Enrol Sync: ' . $message);

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
                'Enrol',
                'Error',
                $recordenrolid
            );

            send_failure_notification(
                'enrolfail',
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

            return 'Error';
        }

        // Check if user is already actively enrolled before enrolling.
        $alreadyenrolled = $DB->record_exists_sql(
            "SELECT 1 FROM {user_enrolments} ue
              WHERE ue.enrolid = :enrolid AND ue.userid = :userid AND ue.status = :status",
            ['enrolid' => $instance->id, 'userid' => $userid, 'status' => ENROL_USER_ACTIVE]
        );

        $enrol->enrol_user(
            $instance,
            $userid,
            $instance->roleid,
            0,
            0,
            ENROL_USER_ACTIVE
        );

        // Confirm the enrolment actually landed before reporting success. enrol_user()
        // returns void, so without this check a silent failure is logged as 'Success'
        // and the record is never retried.
        $isenrolled = $DB->record_exists(
            'user_enrolments',
            ['enrolid' => $instance->id, 'userid' => $userid]
        );
        if (!$isenrolled) {
            $message = "enrol_user() did not create a user_enrolments row for user"
                . " {$userid} in course {$course->id} ({$classcode}).";
            mtrace('PSA Enrol Sync: ' . $message);

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
                'Enrol',
                'Error',
                $recordenrolid
            );

            send_failure_notification(
                'enrolfail',
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

            return 'Error';
        }

        // Only send welcome email for genuinely new enrolments.
        if (empty($alreadyenrolled)) {
            send_welcome_email($user, $course);
        }

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
            'Success',
            $recordenrolid
        );
    } else if ($enrolmentstatus == 'Suspend') {
        // Suspend the user in the course. Returns true, or a reason string on failure;
        // previously this returned nothing and every outcome was logged as 'Success',
        // including "the user was never enrolled" and "the course has no manual
        // enrolment instance".
        $suspendresult = suspend_user_in_course($userid, $course->id, $elmcourseid);

        if ($suspendresult !== true) {
            mtrace('PSA Enrol Sync: ' . $suspendresult);

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
                'Suspend',
                'Error',
                $recordenrolid
            );

            send_failure_notification(
                'enrolfail',
                $firstname,
                $lastname,
                $courselongname,
                $useremail,
                $userpersonid,
                $useroprid,
                $useractivityid,
                $classcode,
                $suspendresult
            );

            return 'Error';
        }

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
            'Success',
            $recordenrolid
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
    // Explicitly set optional profile fields to empty string to avoid
    // inheriting non-empty column defaults from the database schema.
    $user->institution = '';
    $user->department = '';
    $user->phone1 = '';
    $user->phone2 = '';
    $user->address = '';
    $user->city = '';
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
 * @return true|string True on success, or a message describing why it failed.
 */
function suspend_user_in_course($userid, $courseid, $elmcourseid) {
    global $DB;

    $enrol = enrol_get_plugin('manual');
    if (!$enrol) {
        return 'The manual enrolment plugin is disabled site-wide.';
    }

    $enrolinstance = $DB->get_record(
        'enrol',
        ['courseid' => $courseid, 'enrol' => 'manual'],
        '*',
        IGNORE_MISSING
    );
    if (!$enrolinstance) {
        return "Course {$courseid} has no manual enrolment instance.";
    }

    $userenrolment = $DB->get_record(
        'user_enrolments',
        ['enrolid' => $enrolinstance->id, 'userid' => $userid],
        '*',
        IGNORE_MISSING
    );
    if (!$userenrolment) {
        return "User {$userid} has no manual enrolment in course {$courseid} to suspend.";
    }

    if ((int)$userenrolment->status === ENROL_USER_SUSPENDED) {
        // Already suspended: not an error, nothing to do.
        return true;
    }

    // Use the enrol API rather than writing to user_enrolments directly, so that the
    // enrolment caches are invalidated and \core\event\user_enrolment_updated fires.
    // The previous direct update_record() bypassed both.
    $enrol->update_user_enrol($enrolinstance, $userid, ENROL_USER_SUSPENDED);

    // Confirm it took effect rather than assuming it did.
    $updated = $DB->get_field(
        'user_enrolments',
        'status',
        ['enrolid' => $enrolinstance->id, 'userid' => $userid]
    );
    if ((int)$updated !== ENROL_USER_SUSPENDED) {
        return "Suspension of user {$userid} in course {$courseid} did not take effect.";
    }

    return true;
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
 * @param int $recordenrolid The sequential record ID from the CData API.
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
    $status,
    $recordenrolid = 0
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
    $log->record_enrol_id = $recordenrolid;
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
        } else if ($type == 'enrolfail') {
            $subject = "Enrolment Failure Notification";
            $message = "A user could not be enrolled or suspended.\n\n";
            $message .= "{$errormessage}\n";
            $message .= "Name: {$firstname} {$lastname}\n";
            $message .= "Course: {$coursename}\n";
            $message .= "Class Code: {$classcode}\n";
            $message .= "Email: {$email}\n";
            $message .= "Learner ID: {$personid}\n";
            $message .= "IDIR: {$oprid}\n";
            $message .= "Activity ID: {$activityid}\n";
            $message .= "Please investigate the issue.";
        } else {
            // Unrecognised type: still alert rather than sending an empty message.
            $subject = "PSALS Sync Failure Notification";
            $message = "A failure occurred during enrolment processing.\n\n";
            $message .= "Type: {$type}\n";
            $message .= "{$errormessage}\n";
            $message .= "Name: {$firstname} {$lastname}\n";
            $message .= "Course: {$coursename}\n";
            $message .= "Email: {$email}\n";
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
 * Alert administrators that the enrolment API call itself failed.
 *
 * Distinct from send_failure_notification(), which reports a problem with one learner
 * record. This reports that no records could be fetched at all -- previously a silent
 * failure, because the curl options were passed in the wrong argument position and
 * FAILONERROR never took effect.
 *
 * Throttled to at most one alert per hour: the sync task runs every 10 minutes, and an
 * outage that lasts a working day should not generate 70 emails.
 *
 * @param int $httpcode The HTTP status code returned, or 0 if the request never completed.
 * @param int $errno The curl error number, or 0 if none.
 * @param int $page The 1-indexed page of the paged response that failed.
 * @return void
 */
function local_psaelmsync_notify_api_failure($httpcode, $errno, $page) {
    $throttleseconds = 3600;
    $lastalert = (int)get_config('local_psaelmsync', 'last_api_failure_alert');
    if ($lastalert && (time() - $lastalert) < $throttleseconds) {
        mtrace('PSA Enrol Sync: API failure alert suppressed (last sent '
            . (time() - $lastalert) . 's ago).');
        return;
    }

    $adminemails = get_config('local_psaelmsync', 'notificationemails');
    if (empty($adminemails)) {
        return;
    }

    $hint = '';
    if ($httpcode == 401 || $httpcode == 403) {
        $hint = 'The API token may have been rotated, or this host may have been'
            . ' removed from the CData IP allowlist.';
    } else if ($httpcode == 404) {
        $hint = 'Check the Enrolment API URL in the plugin settings.';
    } else if ($httpcode == 502 || $httpcode == 503 || $httpcode == 504) {
        $hint = 'The CData endpoint appears to be down or unreachable.';
    } else if ($httpcode === 0) {
        $hint = 'The request did not complete -- DNS, TLS, network or timeout.';
    }

    $subject = 'PSALS Sync: enrolment API request failed';
    $message = "The scheduled enrolment intake could not fetch records.\n\n";
    $message .= "HTTP status: {$httpcode}\n";
    $message .= "curl errno:  {$errno}\n";
    $message .= "Failed on page: {$page}\n";
    if ($hint !== '') {
        $message .= "\n{$hint}\n";
    }
    $message .= "\nNo records were processed and the high-water mark was not advanced,"
        . " so nothing has been skipped. The next scheduled run will retry.\n";
    $message .= "\nFurther alerts are suppressed for one hour.\n";

    $dummyuser = \core_user::get_noreply_user();
    foreach (explode(',', $adminemails) as $email) {
        $email = trim($email);
        if ($email === '' || !validate_email($email)) {
            continue;
        }
        $recipient = clone $dummyuser;
        $recipient->email = $email;
        $recipient->id = -99;
        email_to_user($recipient, $dummyuser, $subject, $message);
    }

    set_config('last_api_failure_alert', time(), 'local_psaelmsync');
}
