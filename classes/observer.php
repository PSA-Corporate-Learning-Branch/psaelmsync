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
 * Event observer for handling course completion events in PSA ELM sync.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_psaelmsync;

// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalNotNeeded
defined('MOODLE_INTERNAL') || die();

/**
 * Observer class that listens for course completion events and sends data to ELM.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Handle the course_completed event by sending completion data to the ELM API.
     *
     * @param \core\event\course_completed $event The course completed event.
     * @return void
     */
    public static function course_completed(\core\event\course_completed $event) {
        global $DB, $CFG;

        // Get course ID from event data.
        $courseid = $event->courseid;
        // Get user ID from event data.
        $userid = $event->relateduserid;

        // Get the course details so we can look up whether this course
        // is opted in to sending its completion data across to CData
        // and get its ID number.
        $course = get_course($courseid);

        $courseelement = new \core_course_list_element($course);

        if (!$courseelement->has_custom_fields()) {
            debugging('Custom field doesn\'t exist?', DEBUG_DEVELOPER);
            return;
        }

        $fields = $courseelement->get_custom_fields();
        // Iterate through the custom fields.
        foreach ($fields as $field) {
            // Get the shortname of the custom field.
            $shortname = $field->get_field()->get('shortname');
            // Check if this field is completion_opt_in.
            if ($shortname !== 'completion_opt_in') {
                continue;
            }
            // Get the value of the custom field.
            $value = $field->get_value();
            // Check if the value indicates that the course is opted in.
            if ($value != 1) {
                continue;
            }

            // This course is opted into sending the completion back to ELM for processing.
            $elmcourseid = $course->idnumber;
            $coursename = $course->fullname;

            // Get user record.
            $user = $DB->get_record(
                'user',
                ['id' => $userid],
                'id, idnumber, firstname, lastname, email, maildisplay, username'
            );

            // Get enrolment ID and related fields from local_psaelmsync_logs table.
            $sql = "SELECT elm_enrolment_id, class_code, sha256hash, oprid, person_id, activity_id
                      FROM {local_psaelmsync_logs}
                     WHERE course_id = :courseid AND user_id = :userid
                  ORDER BY timestamp DESC LIMIT 1";

            $params = ['courseid' => $courseid, 'userid' => $userid];
            $records = $DB->get_records_sql($sql, $params);

            // Get the first record if it exists.
            $deets = reset($records);

            if (!$deets) {
                self::send_lookup_failure_notification($userid, $courseid, $coursename, $elmcourseid, $user);
                return;
            }

            $elmenrolmentid = $deets->elm_enrolment_id;
            $classcode = $deets->class_code;
            $sha256hash = $deets->sha256hash;

            // Setup other static variables.
            $recordid = time();
            $enrolstatus = 'Complete';
            $datecreated = date('Y-m-d h:i:s');

            // Setup the cURL call to the completion API.
            $apiurl = get_config('local_psaelmsync', 'completion_apiurl');
            $apitoken = get_config('local_psaelmsync', 'completion_apitoken');

            $ch = curl_init($apiurl);

            $data = [
                'COURSE_COMPLETE_DATE' => date('Y-m-d'),
                'COURSE_STATE' => $enrolstatus,
                'ENROLMENT_ID' => (int) $elmenrolmentid,
                'USER_STATE' => 'Active',
                'USER_EFFECTIVE_DATE' => '2017-02-14',
                'COURSE_IDENTIFIER' => (int) $elmcourseid,
                'COURSE_SHORTNAME' => $classcode,
                'EMAIL' => $user->email,
                'GUID' => $user->idnumber,
                'FIRST_NAME' => $user->firstname,
                'LAST_NAME' => $user->lastname,
                'OPRID' => $deets->oprid ?? '',
                'ACTIVITY_ID' => $deets->activity_id ?? 0,
                'PERSON_ID' => $deets->person_id ?? 0,
                'COURSE_LONG_NAME' => $coursename,
            ];

            $jsondata = json_encode($data);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsondata,
                CURLOPT_HTTPHEADER => [
                    "x-cdata-authtoken: " . $apitoken,
                    "Content-Type: application/json",
                ],
            ];
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $curlerror = curl_error($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $responsebody = $response;

            $log = [
                'record_id' => $recordid,
                'record_date_created' => $datecreated,
                'sha256hash' => $sha256hash,
                'course_id' => $courseid,
                'elm_course_id' => $elmcourseid,
                'class_code' => $classcode,
                'course_name' => $course->fullname,
                'user_id' => $userid,
                'user_firstname' => $user->firstname,
                'user_lastname' => $user->lastname,
                'user_guid' => $user->idnumber,
                'user_email' => $user->email,
                'elm_enrolment_id' => $elmenrolmentid,
                'oprid' => $deets->oprid ?? '',
                'person_id' => $deets->person_id ?? '',
                'activity_id' => $deets->activity_id ?? '',
                'action' => 'Complete',
                'status' => 'Success',
                'timestamp' => time(),
                'notes' => '',
            ];

            if ($response === false || $httpcode >= 400) {
                $log['status'] = 'Error';
                $log['notes'] = 'cURL failed: ' . ($curlerror ?: 'HTTP ' . $httpcode);
            }

            if ($log['status'] === 'Error') {
                self::send_api_failure_notification(
                    $userid,
                    $courseid,
                    $coursename,
                    $elmcourseid,
                    $user,
                    $apiurl,
                    $httpcode,
                    $curlerror,
                    $jsondata,
                    $responsebody
                );
            }

            $DB->insert_record('local_psaelmsync_logs', (object) $log);
        }
    }

    /**
     * Send notification emails when enrolment record lookup fails.
     *
     * @param int $userid The Moodle user ID.
     * @param int $courseid The Moodle course ID.
     * @param string $coursename The course full name.
     * @param string $elmcourseid The ELM course ID number.
     * @param object $user The Moodle user record.
     * @return void
     */
    private static function send_lookup_failure_notification($userid, $courseid, $coursename, $elmcourseid, $user) {
        global $DB;

        // Get the list of email addresses from admin settings.
        $adminemails = get_config('local_psaelmsync', 'notificationemails');

        if (empty($adminemails)) {
            return;
        }

        $emails = explode(',', $adminemails);
        $subject = "User Enrolment Data Lookup Failure";

        $message = $user->firstname . " " . $user->lastname
            . ": https://learning.gww.gov.bc.ca/user/view.php?id=" . $userid . "\n";
        $message .= $coursename
            . ": https://learning.gww.gov.bc.ca/course/view.php?id=" . $courseid . "\n";
        $message .= "ELM Course ID: " . $elmcourseid . "\n";
        $message .= "Could not find an associated record in local_psaelmsync_logs for this completion.";

        // Create a dummy user object for sending the email.
        $dummyuser = new \stdClass();
        $dummyuser->email = 'noreply-psalssync@learning.gww.gov.bc.ca';
        $dummyuser->firstname = 'System';
        $dummyuser->lastname = 'Notifier';
        $dummyuser->id = -99;

        foreach ($emails as $adminemail) {
            // Trim to remove any extra whitespace around email addresses.
            $adminemail = trim($adminemail);

            // Try to get a real user record for the recipient by email, fallback to dummy.
            $recipient = $DB->get_record(
                'user',
                ['email' => $adminemail],
                'id, email, username, maildisplay, firstname, lastname, '
                    . 'alternatename, middlename, lastnamephonetic, firstnamephonetic',
                IGNORE_MISSING
            );
            if (!$recipient) {
                $recipient = new \stdClass();
                $recipient->email = $adminemail;
                $recipient->id = -99;
                $recipient->firstname = 'PSA';
                $recipient->lastname = 'Moodle';
            }

            // Send the email.
            email_to_user($recipient, $dummyuser, $subject, $message);
        }
    }

    /**
     * Send notification emails when the completion API call fails.
     *
     * @param int $userid The Moodle user ID.
     * @param int $courseid The Moodle course ID.
     * @param string $coursename The course full name.
     * @param string $elmcourseid The ELM course ID number.
     * @param object $user The Moodle user record.
     * @param string $apiurl The API endpoint URL.
     * @param int $httpcode The HTTP response code.
     * @param string $curlerror The cURL error message.
     * @param string $jsondata The JSON payload sent to the API.
     * @param string $responsebody The response body from the API.
     * @return void
     */
    private static function send_api_failure_notification(
        $userid,
        $courseid,
        $coursename,
        $elmcourseid,
        $user,
        $apiurl,
        $httpcode,
        $curlerror,
        $jsondata,
        $responsebody
    ) {
        global $DB;

        $adminemails = get_config('local_psaelmsync', 'notificationemails');

        if (empty($adminemails)) {
            return;
        }

        $emails = explode(',', $adminemails);
        $subject = "Completion API Sync Failure";

        $message = "A completion sync failed:\n\n";
        $message .= $user->firstname . ' ' . $user->lastname
            . ': https://learning.gww.gov.bc.ca/user/view.php?id=' . $userid . "\n";
        $message .= $coursename
            . ': https://learning.gww.gov.bc.ca/course/view.php?id=' . $courseid . "\n";
        $message .= "ELM Course ID: " . $elmcourseid . "\n";
        $message .= "API URL: " . $apiurl . "\n";
        $message .= "HTTP Code: " . $httpcode . "\n";
        $message .= "Error: " . $curlerror . "\n";
        $message .= "Payload: " . $jsondata . "\n";
        $message .= "Response Body: " . $responsebody . "\n";

        $dummyuser = new \stdClass();
        $dummyuser->email = 'noreply-psalssync@learning.gww.gov.bc.ca';
        $dummyuser->firstname = 'System';
        $dummyuser->lastname = 'Notifier';
        $dummyuser->id = -99;

        foreach ($emails as $adminemail) {
            $adminemail = trim($adminemail);
            // Try to get a real user record for the recipient by email, fallback to dummy.
            $recipient = $DB->get_record(
                'user',
                ['email' => $adminemail],
                'id, email, username, maildisplay, firstname, lastname, '
                    . 'alternatename, middlename, lastnamephonetic, firstnamephonetic',
                IGNORE_MISSING
            );
            if (!$recipient) {
                $recipient = new \stdClass();
                $recipient->email = $adminemail;
                $recipient->id = -99;
                $recipient->firstname = 'PSA';
                $recipient->lastname = 'Moodle';
            }

            email_to_user($recipient, $dummyuser, $subject, $message);
        }
    }
}
