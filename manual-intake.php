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
 * Manual intake - Diff-style UI for reviewing and processing CData enrolment records.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This file mixes HTML and PHP; disable the per-block docblock check.
// phpcs:disable moodle.Commenting.MissingDocblock

require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/local/psaelmsync/lib.php');

global $CFG, $DB, $PAGE, $OUTPUT;

require_login();

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/manual-intake.php');
$PAGE->set_context($context);
$PAGE->set_title(
    get_string('pluginname', 'local_psaelmsync')
    . ' - '
    . get_string('queryapi', 'local_psaelmsync')
);
$PAGE->set_heading(get_string('queryapi', 'local_psaelmsync'));

$apiurl = get_config('local_psaelmsync', 'apiurl');
$apitoken = get_config('local_psaelmsync', 'apitoken');

// Initialize variables.
$data = null;
$feedback = '';
$feedbacktype = 'info';

// Get filter values (persisted across requests).
$filterfrom = optional_param('from', '', PARAM_TEXT);
$filterto = optional_param('to', '', PARAM_TEXT);
$filteremail = optional_param('email', '', PARAM_TEXT);
$filterguid = optional_param('guid', '', PARAM_TEXT);
$filtercourse = optional_param('course', '', PARAM_TEXT);
$filterstate = optional_param('state', '', PARAM_ALPHA);
$filterstatus = optional_param('status', '', PARAM_ALPHA);
$filterfirstname = optional_param('firstname', '', PARAM_TEXT);
$filterlastname = optional_param('lastname', '', PARAM_TEXT);
$filteroprid = optional_param('oprid', '', PARAM_TEXT);
$filterpersonid = optional_param('personid', '', PARAM_TEXT);
$apiurlfiltered = '';

/**
 * Determine the status category for a record based on Moodle state comparison.
 *
 * @param array $record The CData enrolment record.
 * @param object|false $user The Moodle user object or false if not found.
 * @param object|false $course The Moodle course object or false if not found.
 * @param bool $hashexists Whether a successful log entry already exists.
 * @param bool $isenrolled Whether the user is currently enrolled.
 * @return array Status info with status, label, icon, class, can_process, reason keys.
 */
function determine_record_status($record, $user, $course, $hashexists, $isenrolled) {
    // Already processed successfully.
    if ($hashexists) {
        return [
            'status' => 'done',
            'label' => 'Already Processed',
            'icon' => '✓',
            'class' => 'secondary',
            'can_process' => false,
            'reason' => 'This record has already been processed.',
        ];
    }

    // Course not found.
    if (!$course) {
        $courseid = $record['COURSE_IDENTIFIER'];
        return [
            'status' => 'blocked',
            'label' => 'Course Not Found',
            'icon' => '✗',
            'class' => 'danger',
            'can_process' => false,
            'reason' => "Course with ELM ID {$courseid} does not exist in Moodle.",
        ];
    }

    // Check if action is already done.
    if ($record['COURSE_STATE'] === 'Enrol' && $isenrolled) {
        return [
            'status' => 'done',
            'label' => 'Already Enrolled',
            'icon' => '✓',
            'class' => 'secondary',
            'can_process' => false,
            'reason' => 'User is already enrolled in this course.',
        ];
    }

    if ($record['COURSE_STATE'] === 'Suspend' && !$isenrolled) {
        return [
            'status' => 'done',
            'label' => 'Not Enrolled',
            'icon' => '✓',
            'class' => 'secondary',
            'can_process' => false,
            'reason' => 'User is not enrolled, nothing to suspend.',
        ];
    }

    // User does not exist and will be created.
    if (!$user) {
        return [
            'status' => 'new_user',
            'label' => 'New User',
            'icon' => '+',
            'class' => 'info',
            'can_process' => true,
            'reason' => 'User will be created and enrolled.',
        ];
    }

    // Email mismatch.
    if (strtolower($user->email) !== strtolower($record['EMAIL'])) {
        return [
            'status' => 'mismatch',
            'label' => 'Email Mismatch',
            'icon' => '⚠',
            'class' => 'warning',
            'can_process' => false,
            'reason' => 'The email in CData does not match the Moodle account.'
                . ' Manual investigation required.',
        ];
    }

    // Ready to process.
    return [
        'status' => 'ready',
        'label' => 'Ready',
        'icon' => '●',
        'class' => 'success',
        'can_process' => true,
        'reason' => "Ready to {$record['COURSE_STATE']}.",
    ];
}

/**
 * Check if user is enrolled in course by idnumber.
 *
 * @param int|string $courseidnumber The course idnumber (ELM course ID).
 * @param int $userid The Moodle user ID.
 * @return bool True if the user is actively enrolled.
 */
function check_user_enrolled($courseidnumber, $userid) {
    global $DB;

    $course = $DB->get_record('course', ['idnumber' => $courseidnumber]);
    if (!$course) {
        return false;
    }

    $usercourses = enrol_get_users_courses($userid, true, ['id']);
    foreach ($usercourses as $usercourse) {
        if ($usercourse->id == $course->id) {
            return true;
        }
    }
    return false;
}

/**
 * Create a new user (local helper matching lib.php pattern).
 *
 * @param string $useremail The user's email address.
 * @param string $firstname The user's first name.
 * @param string $lastname The user's last name.
 * @param string $userguid The user's GUID (stored as idnumber).
 * @return object The created user object.
 */
function create_new_user_local($useremail, $firstname, $lastname, $userguid) {
    global $DB;

    $user = new stdClass();
    $user->username = strtolower($useremail);
    $user->email = $useremail;
    $user->idnumber = $userguid;
    $user->firstname = $firstname;
    $user->lastname = $lastname;
    $user->password = hash_internal_user_password(random_string(8));
    $user->confirmed = 1;
    $user->auth = 'oauth2';
    $user->emailformat = 1;
    $user->mnethostid = 1;
    $user->timecreated = time();
    $user->timemodified = time();

    $user->id = user_create_user($user, true, false);

    return $user;
}

// Process form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process'])) {
    require_sesskey();

    $recorddatecreated = required_param('record_date_created', PARAM_TEXT);
    $coursestate = required_param('course_state', PARAM_TEXT);
    $elmcourseid = required_param('elm_course_id', PARAM_TEXT);
    $elmenrolmentid = required_param('elm_enrolment_id', PARAM_TEXT);
    $userguid = required_param('guid', PARAM_TEXT);
    $classcode = required_param('class_code', PARAM_TEXT);
    $useremail = required_param('email', PARAM_TEXT);
    $firstname = required_param('first_name', PARAM_TEXT);
    $lastname = required_param('last_name', PARAM_TEXT);
    $oprid = optional_param('oprid', '', PARAM_TEXT);
    $personid = optional_param('person_id', '', PARAM_TEXT);
    $activityid = optional_param('activity_id', '', PARAM_TEXT);

    // Check the ignore list before processing.
    if (local_psaelmsync_is_ignored_course($elmcourseid)) {
        $feedback = "Course ID "
            . s($elmcourseid)
            . " is on the ignore list and will not be processed.";
        $feedbacktype = 'warning';
    } else {
        $hashcontent = $recorddatecreated
            . $elmcourseid . $classcode
            . $coursestate . $userguid . $useremail;
        $hash = hash('sha256', $hashcontent);

        $hashcheck = $DB->get_record(
            'local_psaelmsync_logs',
            ['sha256hash' => $hash],
            '*',
            IGNORE_MULTIPLE
        );

        if ($hashcheck && $hashcheck->status === 'Success') {
            $feedback = 'This record has already been processed.';
            $feedbacktype = 'warning';
        } else {
            $course = $DB->get_record(
                'course',
                ['idnumber' => $elmcourseid]
            );

            if (!$course) {
                $feedback = "Course with ELM ID "
                    . s($elmcourseid) . " not found in Moodle.";
                $feedbacktype = 'danger';
            } else {
                $user = $DB->get_record(
                    'user',
                    ['idnumber' => $userguid]
                );

                if (!$user) {
                    $user = create_new_user_local(
                        $useremail,
                        $firstname,
                        $lastname,
                        $userguid
                    );

                    if (!$user) {
                        $useremailcheck = $DB->get_record(
                            'user',
                            ['email' => $useremail]
                        );
                        if ($useremailcheck) {
                            $feedback = "Failed to create user."
                                . " An account with email "
                                . s($useremail)
                                . " already exists. ";
                            $feedback .= "<a href='/user/view.php?id="
                                . $useremailcheck->id
                                . "' target='_blank'>"
                                . "View existing account</a>";
                        } else {
                            $feedback = "Failed to create a new"
                                . " user for GUID "
                                . s($userguid) . ".";
                        }
                        $feedbacktype = 'danger';
                    }
                }

                if ($user) {
                    // Check for email mismatch.
                    if (strtolower($user->email) !== strtolower($useremail)) {
                        $feedback = "Email mismatch: Moodle has '"
                            . s($user->email) . "' but CData has '"
                            . s($useremail) . "'. ";
                        $useremailcheck = $DB->get_record(
                            'user',
                            ['email' => $useremail]
                        );
                        if ($useremailcheck) {
                            $feedback .= "Another account exists"
                                . " with the CData email: ";
                            $feedback .= "<a href='/user/view.php?id="
                                . $useremailcheck->id
                                . "' target='_blank'>"
                                . "View account</a>";
                        }
                        $feedbacktype = 'danger';
                    } else {
                        $manualenrol = enrol_get_plugin('manual');
                        $enrolinstances = enrol_get_instances(
                            $course->id,
                            true
                        );
                        $manualinstance = null;

                        foreach ($enrolinstances as $instance) {
                            if ($instance->enrol === 'manual') {
                                $manualinstance = $instance;
                                break;
                            }
                        }

                        if (
                            $manualinstance
                            && !empty($manualinstance->roleid)
                        ) {
                            if ($coursestate === 'Enrol') {
                                $manualenrol->enrol_user(
                                    $manualinstance,
                                    $user->id,
                                    $manualinstance->roleid,
                                    0,
                                    0,
                                    ENROL_USER_ACTIVE
                                );

                                $isenrolled = $DB->record_exists(
                                    'user_enrolments',
                                    [
                                        'userid' => $user->id,
                                        'enrolid' => $manualinstance->id,
                                    ]
                                );

                                if ($isenrolled) {
                                    $log = new stdClass();
                                    $log->record_id = time();
                                    $log->sha256hash = $hash;
                                    $log->record_date_created =
                                        $recorddatecreated;
                                    $log->course_id = $course->id;
                                    $log->elm_course_id = $elmcourseid;
                                    $log->class_code = $classcode;
                                    $log->course_name = $course->fullname;
                                    $log->user_id = $user->id;
                                    $log->user_firstname =
                                        $user->firstname;
                                    $log->user_lastname =
                                        $user->lastname;
                                    $log->user_guid = $user->idnumber;
                                    $log->user_email = $user->email;
                                    $log->elm_enrolment_id =
                                        $elmenrolmentid;
                                    $log->oprid = $oprid;
                                    $log->person_id = $personid;
                                    $log->activity_id = $activityid;
                                    $log->action = 'Manual Enrol';
                                    $log->status = 'Success';
                                    $log->timestamp = time();

                                    $DB->insert_record(
                                        'local_psaelmsync_logs',
                                        $log
                                    );

                                    $feedback = "Successfully enrolled "
                                        . s($user->email) . " in "
                                        . s($course->fullname) . ".";
                                    $feedbacktype = 'success';

                                    send_welcome_email($user, $course);
                                } else {
                                    $feedback = "Failed to enrol "
                                        . s($user->email)
                                        . " in the course.";
                                    $feedbacktype = 'danger';
                                }
                            } else if ($coursestate === 'Suspend') {
                                $manualenrol->update_user_enrol(
                                    $manualinstance,
                                    $user->id,
                                    ENROL_USER_SUSPENDED
                                );

                                $log = new stdClass();
                                $log->record_id = time();
                                $log->sha256hash = $hash;
                                $log->record_date_created =
                                    $recorddatecreated;
                                $log->course_id = $course->id;
                                $log->elm_course_id = $elmcourseid;
                                $log->class_code = $classcode;
                                $log->course_name = $course->fullname;
                                $log->user_id = $user->id;
                                $log->user_firstname =
                                    $user->firstname;
                                $log->user_lastname =
                                    $user->lastname;
                                $log->user_guid = $user->idnumber;
                                $log->user_email = $user->email;
                                $log->elm_enrolment_id =
                                    $elmenrolmentid;
                                $log->oprid = $oprid;
                                $log->person_id = $personid;
                                $log->activity_id = $activityid;
                                $log->action = 'Manual Suspend';
                                $log->status = 'Success';
                                $log->timestamp = time();

                                $DB->insert_record(
                                    'local_psaelmsync_logs',
                                    $log
                                );

                                $feedback = "Successfully suspended "
                                    . s($user->email) . " from "
                                    . s($course->fullname) . ".";
                                $feedbacktype = 'success';
                            } else {
                                $feedback = "Invalid course state: "
                                    . s($coursestate);
                                $feedbacktype = 'danger';
                            }
                        } else {
                            $feedback = "No manual enrolment instance"
                                . " found for this course.";
                            $feedbacktype = 'danger';
                        }
                    }
                }
            }
        }
    }
}

// Handle bulk processing.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['bulk_process'])
) {
    require_sesskey();

    $selectedrecords = optional_param_array(
        'selected_records',
        [],
        PARAM_RAW
    );
    $successcount = 0;
    $errorcount = 0;
    $errors = [];

    foreach ($selectedrecords as $encodedrecord) {
        $recorddata = json_decode(base64_decode($encodedrecord), true);
        if (!$recorddata) {
            $errorcount++;
            continue;
        }

        $recorddatecreated = $recorddata['date_created'];
        $coursestate = $recorddata['course_state'];
        $elmcourseid = $recorddata['elm_course_id'];
        $elmenrolmentid = $recorddata['elm_enrolment_id'];
        $userguid = $recorddata['guid'];
        $classcode = $recorddata['class_code'];
        $useremail = $recorddata['email'];
        $firstname = $recorddata['first_name'];
        $lastname = $recorddata['last_name'];
        $oprid = $recorddata['oprid'] ?? '';
        $personid = $recorddata['person_id'] ?? '';
        $activityid = $recorddata['activity_id'] ?? '';

        // Skip courses on the ignore list.
        if (local_psaelmsync_is_ignored_course($elmcourseid)) {
            continue;
        }

        $hashcontent = $recorddatecreated
            . $elmcourseid . $classcode
            . $coursestate . $userguid . $useremail;
        $hash = hash('sha256', $hashcontent);

        $hashcheck = $DB->get_record(
            'local_psaelmsync_logs',
            ['sha256hash' => $hash],
            '*',
            IGNORE_MULTIPLE
        );

        if ($hashcheck && $hashcheck->status === 'Success') {
            // Skip already processed successfully.
            continue;
        }

        $course = $DB->get_record(
            'course',
            ['idnumber' => $elmcourseid]
        );
        if (!$course) {
            $errorcount++;
            $errors[] = "Course {$elmcourseid} not found";
            continue;
        }

        $user = $DB->get_record('user', ['idnumber' => $userguid]);
        if (!$user) {
            $user = create_new_user_local(
                $useremail,
                $firstname,
                $lastname,
                $userguid
            );
            if (!$user) {
                $errorcount++;
                $errors[] = "Failed to create user {$useremail}";
                continue;
            }
        }

        // Check for email mismatch.
        if (strtolower($user->email) !== strtolower($useremail)) {
            $errorcount++;
            $errors[] = "Email mismatch for {$useremail}";
            continue;
        }

        $manualenrol = enrol_get_plugin('manual');
        $enrolinstances = enrol_get_instances($course->id, true);
        $manualinstance = null;

        foreach ($enrolinstances as $instance) {
            if ($instance->enrol === 'manual') {
                $manualinstance = $instance;
                break;
            }
        }

        if (!$manualinstance || empty($manualinstance->roleid)) {
            $errorcount++;
            $errors[] = "No manual enrolment for course "
                . $course->shortname;
            continue;
        }

        if ($coursestate === 'Enrol') {
            $manualenrol->enrol_user(
                $manualinstance,
                $user->id,
                $manualinstance->roleid,
                0,
                0,
                ENROL_USER_ACTIVE
            );

            $log = new stdClass();
            $log->record_id = time();
            $log->sha256hash = $hash;
            $log->record_date_created = $recorddatecreated;
            $log->course_id = $course->id;
            $log->elm_course_id = $elmcourseid;
            $log->class_code = $classcode;
            $log->course_name = $course->fullname;
            $log->user_id = $user->id;
            $log->user_firstname = $user->firstname;
            $log->user_lastname = $user->lastname;
            $log->user_guid = $user->idnumber;
            $log->user_email = $user->email;
            $log->elm_enrolment_id = $elmenrolmentid;
            $log->oprid = $oprid;
            $log->person_id = $personid;
            $log->activity_id = $activityid;
            $log->action = 'Manual Enrol (Bulk)';
            $log->status = 'Success';
            $log->timestamp = time();

            $DB->insert_record('local_psaelmsync_logs', $log);
            send_welcome_email($user, $course);
            $successcount++;
        } else if ($coursestate === 'Suspend') {
            $manualenrol->update_user_enrol(
                $manualinstance,
                $user->id,
                ENROL_USER_SUSPENDED
            );

            $log = new stdClass();
            $log->record_id = time();
            $log->sha256hash = $hash;
            $log->record_date_created = $recorddatecreated;
            $log->course_id = $course->id;
            $log->elm_course_id = $elmcourseid;
            $log->class_code = $classcode;
            $log->course_name = $course->fullname;
            $log->user_id = $user->id;
            $log->user_firstname = $user->firstname;
            $log->user_lastname = $user->lastname;
            $log->user_guid = $user->idnumber;
            $log->user_email = $user->email;
            $log->elm_enrolment_id = $elmenrolmentid;
            $log->oprid = $oprid;
            $log->person_id = $personid;
            $log->activity_id = $activityid;
            $log->action = 'Manual Suspend (Bulk)';
            $log->status = 'Success';
            $log->timestamp = time();

            $DB->insert_record('local_psaelmsync_logs', $log);
            $successcount++;
        }
    }

    if ($successcount > 0) {
        $feedback = "Bulk processing complete: "
            . "{$successcount} successful";
        if ($errorcount > 0) {
            $feedback .= ", {$errorcount} errors";
        }
        $feedbacktype = $errorcount > 0 ? 'warning' : 'success';
    } else {
        $feedback = "Bulk processing failed: "
            . "{$errorcount} errors";
        $feedbacktype = 'danger';
    }
    if (!empty($errors)) {
        $errorsummary = implode(
            '; ',
            array_slice($errors, 0, 5)
        );
        $ellipsis = count($errors) > 5 ? '...' : '';
        $feedback .= "<br><small>"
            . $errorsummary . $ellipsis . "</small>";
    }
}

// Build API query if filters are provided.
$hasfilters = !empty($filteremail)
    || !empty($filterguid)
    || !empty($filterfrom)
    || !empty($filtercourse)
    || !empty($filterfirstname)
    || !empty($filterlastname)
    || !empty($filteroprid)
    || !empty($filterpersonid);

if ($hasfilters) {
    $filters = [];

    if (!empty($filteremail)) {
        $filters[] = "email+eq+%27"
            . urlencode($filteremail) . "%27";
    }
    if (!empty($filterguid)) {
        $filters[] = "GUID+eq+%27"
            . urlencode($filterguid) . "%27";
    }
    if (!empty($filterfirstname)) {
        $filters[] = "FIRST_NAME+eq+%27"
            . urlencode($filterfirstname) . "%27";
    }
    if (!empty($filterlastname)) {
        $filters[] = "LAST_NAME+eq+%27"
            . urlencode($filterlastname) . "%27";
    }
    if (!empty($filteroprid)) {
        $filters[] = "OPRID+eq+%27"
            . urlencode($filteroprid) . "%27";
    }
    if (!empty($filterpersonid)) {
        $filters[] = "PERSON_ID+eq+"
            . urlencode($filterpersonid);
    }
    if (!empty($filtercourse)) {
        // Support both course ID and shortname search.
        if (is_numeric($filtercourse)) {
            $filters[] = "COURSE_IDENTIFIER+eq+"
                . urlencode($filtercourse);
        } else {
            $filters[] = "COURSE_SHORTNAME+eq+%27"
                . urlencode($filtercourse) . "%27";
        }
    }
    if (!empty($filterfrom) && !empty($filterto)) {
        $filters[] = "date_created+gt+%27"
            . urlencode($filterfrom) . "%27";
        $filters[] = "date_created+lt+%27"
            . urlencode($filterto) . "%27";
    } else if (!empty($filterfrom)) {
        $filters[] = "date_created+gt+%27"
            . urlencode($filterfrom) . "%27";
    }
    if (!empty($filterstate)) {
        $filters[] = "COURSE_STATE+eq+%27"
            . urlencode($filterstate) . "%27";
    }

    $apiurlfiltered = $apiurl
        . "?%24orderby=COURSE_STATE_DATE,date_created+asc";
    if (!empty($filters)) {
        $apiurlfiltered .= "&%24filter="
            . implode("+and+", $filters);
    }

    $options = [
        'RETURNTRANSFER' => 1,
        'HEADER' => 0,
    ];
    $header = ['x-cdata-authtoken: ' . $apitoken];
    $curl = new curl();
    $curl->setHeader($header);
    $response = $curl->get($apiurlfiltered, $options);

    // Check for cURL-level errors.
    if ($curl->get_errno()) {
        $feedback = 'cURL Error: ' . $curl->error;
        $feedbacktype = 'danger';
    } else {
        // Check HTTP status code.
        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        if ($httpcode >= 400) {
            $feedback = "API Error: HTTP {$httpcode}";
            if ($httpcode == 502) {
                $feedback .= ' (Bad Gateway - the CData'
                    . ' server may be down or unreachable)';
            } else if ($httpcode == 401) {
                $feedback .= ' (Unauthorized'
                    . ' - check API token)';
            } else if ($httpcode == 403) {
                $feedback .= ' (Forbidden'
                    . ' - check IP whitelist/VPN)';
            } else if ($httpcode == 404) {
                $feedback .= ' (Not Found - check API URL)';
            } else if ($httpcode == 500) {
                $feedback .= ' (Internal Server Error)';
            }
            $responsepreview = htmlspecialchars(
                substr($response, 0, 500)
            );
            $feedback .= '<br><small class="text-muted">'
                . 'Response: ' . $responsepreview . '</small>';
            $feedbacktype = 'danger';
        } else {
            $data = json_decode($response, true);

            // Check for JSON decode errors.
            if (json_last_error() !== JSON_ERROR_NONE) {
                $feedback = 'API Error: Invalid JSON response'
                    . ' - ' . json_last_error_msg();
                $responsepreview = htmlspecialchars(
                    substr($response, 0, 500)
                );
                $feedback .= '<br><small class="text-muted">'
                    . 'Response: '
                    . $responsepreview . '</small>';
                $feedbacktype = 'danger';
                $data = null;
            }
        }
    }
}

// Process records to add status information.
$processedrecords = [];
if (!empty($data['value'])) {
    foreach ($data['value'] as $record) {
        // Skip courses on the ignore list entirely.
        if (local_psaelmsync_is_ignored_course($record['COURSE_IDENTIFIER'])) {
            continue;
        }

        $user = $DB->get_record(
            'user',
            ['idnumber' => $record['GUID']]
        );
        $course = $DB->get_record(
            'course',
            ['idnumber' => (int)$record['COURSE_IDENTIFIER']],
            'id, fullname, shortname'
        );

        $hashcontent = $record['date_created']
            . $record['COURSE_IDENTIFIER']
            . $record['COURSE_SHORTNAME']
            . $record['COURSE_STATE']
            . $record['GUID']
            . $record['EMAIL'];
        $hash = hash('sha256', $hashcontent);
        $existinglog = $DB->get_record(
            'local_psaelmsync_logs',
            ['sha256hash' => $hash],
            'id, status',
            IGNORE_MULTIPLE
        );
        $hashexists = $existinglog
            && $existinglog->status === 'Success';

        $isenrolled = false;
        if ($user && $course) {
            $isenrolled = check_user_enrolled(
                $record['COURSE_IDENTIFIER'],
                $user->id
            );
        }

        $statusinfo = determine_record_status(
            $record,
            $user,
            $course,
            $hashexists,
            $isenrolled
        );

        // Apply status filter if set.
        if (
            !empty($filterstatus)
            && $statusinfo['status'] !== $filterstatus
        ) {
            continue;
        }

        $processedrecords[] = [
            'record' => $record,
            'user' => $user,
            'course' => $course,
            'is_enrolled' => $isenrolled,
            'status_info' => $statusinfo,
            'hash_exists' => $hashexists,
        ];
    }
}

echo $OUTPUT->header();
// phpcs:disable Generic.WhiteSpace.ScopeIndent
// phpcs:disable Squiz.WhiteSpace.ScopeClosingBrace
?>

<style>
.manual-intake-filters {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
}
.manual-intake-filters .form-group {
    margin-bottom: 0.5rem;
}
.manual-intake-filters label {
    font-size: 0.85rem;
    font-weight: 500;
    margin-bottom: 0.25rem;
}
.record-table {
    font-size: 0.9rem;
}
.record-table th {
    white-space: nowrap;
    background: #f8f9fa;
}
.record-row {
    cursor: pointer;
}
.record-row:hover {
    background: #f8f9fa;
}
.record-row.expanded {
    background: #e9ecef;
}
.record-details {
    display: none;
    background: #fff;
}
.record-details.show {
    display: table-row;
}
.record-details td {
    padding: 1rem;
    border-top: none;
}
.diff-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.diff-panel {
    background: #f8f9fa;
    border-radius: 0.5rem;
    padding: 1rem;
}
.diff-panel h6 {
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #dee2e6;
}
.diff-row {
    display: flex;
    justify-content: space-between;
    padding: 0.25rem 0;
    font-size: 0.85rem;
}
.diff-row .label {
    color: #6c757d;
}
.diff-row .value {
    font-weight: 500;
}
.diff-match {
    color: #28a745;
}
.diff-mismatch {
    color: #dc3545;
    background: #fff3cd;
    padding: 0 0.25rem;
    border-radius: 0.25rem;
}
.diff-new {
    color: #17a2b8;
    font-style: italic;
}
.status-badge {
    font-size: 0.8rem;
    padding: 0.25rem 0.5rem;
}
.status-reason {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 0.75rem;
    padding: 0.5rem;
    background: #fff;
    border-radius: 0.25rem;
}
.action-buttons {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #dee2e6;
}
.existing-logs {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #dee2e6;
}
.existing-logs h6 {
    font-size: 0.85rem;
    color: #6c757d;
}
.log-entry {
    font-size: 0.8rem;
    padding: 0.25rem 0.5rem;
    background: #e9ecef;
    border-radius: 0.25rem;
    margin-bottom: 0.25rem;
}
.query-debug {
    font-size: 0.8rem;
    background: #f8f9fa;
    padding: 0.5rem;
    border-radius: 0.25rem;
    margin-bottom: 1rem;
    word-break: break-all;
}
.filter-actions {
    display: flex;
    gap: 0.5rem;
    align-items: end;
}
.results-summary {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}
.results-summary .summary-item {
    background: #f8f9fa;
    padding: 0.5rem 1rem;
    border-radius: 0.25rem;
    font-size: 0.85rem;
}
.expand-icon {
    transition: transform 0.2s;
    display: inline-block;
}
.record-row.expanded .expand-icon {
    transform: rotate(90deg);
}
.bulk-action-bar {
    background: #e9ecef;
    padding: 0.75rem 1rem;
    border-radius: 0.25rem;
    display: flex;
    gap: 0.5rem;
    align-items: center;
    flex-wrap: wrap;
}
.checkbox-cell {
    vertical-align: middle;
}
.record-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
#select-all-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
.record-row:focus {
    outline: 2px solid #007bff;
    outline-offset: -2px;
}
</style>

<!-- Tabbed Navigation -->
<nav aria-label="PSA ELM Sync sections">
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link"
               href="/admin/settings.php?section=local_psaelmsync">
                Settings</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/dashboard.php">
                Learner Dashboard</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/dashboard-courses.php">
                Course Dashboard</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/dashboard-intake.php">
                Intake Run Dashboard</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active"
               href="/local/psaelmsync/manual-intake.php"
               aria-current="page">Manual Intake</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/manual-complete.php">
                Manual Complete</a>
        </li>
    </ul>
</nav>

<?php if (!empty($feedback)) : ?>
<div class="alert alert-<?php echo s($feedbacktype); ?> alert-dismissible fade show"
     role="alert">
    <?php
    // Contains intentional HTML links; user data is escaped at construction.
    echo $feedback;
    ?>
    <button type="button" class="close"
            data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<?php endif; ?>

<!-- Enhanced Filter Form -->
<div class="manual-intake-filters">
    <form method="get" action="<?php echo $PAGE->url; ?>">
        <div class="row">
            <div class="col-md-2">
                <div class="form-group">
                    <label for="from">From Date</label>
                    <input type="datetime-local" id="from"
                           name="from"
                           class="form-control form-control-sm"
                           value="<?php echo s($filterfrom); ?>">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="to">To Date</label>
                    <input type="datetime-local" id="to"
                           name="to"
                           class="form-control form-control-sm"
                           value="<?php echo s($filterto); ?>">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="firstname">First Name</label>
                    <input type="text" id="firstname"
                           name="firstname"
                           class="form-control form-control-sm"
                           value="<?php
                               echo s($filterfirstname);
                           ?>" placeholder="Allan">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="lastname">Last Name</label>
                    <input type="text" id="lastname"
                           name="lastname"
                           class="form-control form-control-sm"
                           value="<?php
                               echo s($filterlastname);
                           ?>" placeholder="Haggett">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email"
                           name="email"
                           class="form-control form-control-sm"
                           value="<?php
                               echo s($filteremail);
                           ?>" placeholder="user@example.com">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="guid">GUID</label>
                    <input type="text" id="guid"
                           name="guid"
                           class="form-control form-control-sm"
                           value="<?php
                               echo s($filterguid);
                           ?>" placeholder="5F421FC1A510...">
                </div>
            </div>
        </div>
        <div class="row mt-2">
            <div class="col-md-2">
                <div class="form-group">
                    <label for="oprid">OPRID</label>
                    <input type="text" id="oprid"
                           name="oprid"
                           class="form-control form-control-sm"
                           value="<?php
                               echo s($filteroprid);
                           ?>" placeholder="AHAGGETT">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="personid">PERSON_ID</label>
                    <input type="text" id="personid"
                           name="personid"
                           class="form-control form-control-sm"
                           value="<?php
                               echo s($filterpersonid);
                           ?>" placeholder="115000">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="course">Course ID/Shortname</label>
                    <input type="text" id="course"
                           name="course"
                           class="form-control form-control-sm"
                           value="<?php
                               echo s($filtercourse);
                           ?>" placeholder="40972 or ITEM-2625-1">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="state">CData State</label>
                    <select id="state" name="state"
                            class="form-control form-control-sm">
                        <option value="">All</option>
                        <option value="Enrol" <?php
                            echo $filterstate === 'Enrol'
                                ? 'selected' : '';
                        ?>>Enrol</option>
                        <option value="Suspend" <?php
                            echo $filterstate === 'Suspend'
                                ? 'selected' : '';
                        ?>>Suspend</option>
                    </select>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="status">Record Status</label>
                    <select id="status" name="status"
                            class="form-control form-control-sm">
                        <option value="">All</option>
                        <option value="ready" <?php
                            echo $filterstatus === 'ready'
                                ? 'selected' : '';
                        ?>>Ready to Process</option>
                        <option value="new_user" <?php
                            echo $filterstatus === 'new_user'
                                ? 'selected' : '';
                        ?>>New User</option>
                        <option value="mismatch" <?php
                            echo $filterstatus === 'mismatch'
                                ? 'selected' : '';
                        ?>>Email Mismatch</option>
                        <option value="blocked" <?php
                            echo $filterstatus === 'blocked'
                                ? 'selected' : '';
                        ?>>Blocked</option>
                        <option value="done" <?php
                            echo $filterstatus === 'done'
                                ? 'selected' : '';
                        ?>>Already Done</option>
                    </select>
                </div>
            </div>
            <div class="col-md-2">
                <div class="filter-actions">
                    <button type="submit"
                            class="btn btn-primary btn-sm">
                        Search CData</button>
                    <a href="<?php echo $PAGE->url; ?>"
                       class="btn btn-secondary btn-sm">
                        Clear</a>
                </div>
            </div>
        </div>
    </form>
</div>

<?php if (!empty($apiurlfiltered)) : ?>
<div class="query-debug">
    <strong>API Query:</strong>
    <code><?php echo htmlspecialchars($apiurlfiltered); ?></code>
    <a href="<?php echo $apiurlfiltered; ?>"
       class="btn btn-sm btn-outline-secondary ml-2"
       target="_blank">Open in browser</a>
    <small class="text-muted">
        (VPN + IP whitelist required)</small>
</div>
<?php endif; ?>

<?php if (!empty($processedrecords)) : ?>
    <?php
    // Calculate summary counts.
    $statuscounts = [
        'ready' => 0,
        'new_user' => 0,
        'mismatch' => 0,
        'blocked' => 0,
        'done' => 0,
    ];
    $processablecount = 0;
    foreach ($processedrecords as $pr) {
        $statuscounts[$pr['status_info']['status']]++;
        if ($pr['status_info']['can_process']) {
            $processablecount++;
        }
    }
    ?>

    <div class="results-summary">
        <div class="summary-item">
            <strong><?php
                echo count($processedrecords);
            ?></strong> records found</div>
        <?php if ($statuscounts['ready'] > 0) : ?>
            <div class="summary-item text-success">
                <strong><?php
                    echo $statuscounts['ready'];
                ?></strong> ready</div>
        <?php endif; ?>
        <?php if ($statuscounts['new_user'] > 0) : ?>
            <div class="summary-item text-info">
                <strong><?php
                    echo $statuscounts['new_user'];
                ?></strong> new users</div>
        <?php endif; ?>
        <?php if ($statuscounts['mismatch'] > 0) : ?>
            <div class="summary-item text-warning">
                <strong><?php
                    echo $statuscounts['mismatch'];
                ?></strong> mismatches</div>
        <?php endif; ?>
        <?php if ($statuscounts['blocked'] > 0) : ?>
            <div class="summary-item text-danger">
                <strong><?php
                    echo $statuscounts['blocked'];
                ?></strong> blocked</div>
        <?php endif; ?>
        <?php if ($statuscounts['done'] > 0) : ?>
            <div class="summary-item text-secondary">
                <strong><?php
                    echo $statuscounts['done'];
                ?></strong> already done</div>
        <?php endif; ?>
    </div>

    <?php if ($processablecount > 0) : ?>
    <form method="post"
          action="<?php echo $PAGE->url; ?>"
          id="bulk-process-form">
        <input type="hidden" name="sesskey"
               value="<?php echo sesskey(); ?>">

        <!-- Bulk Action Bar (Top) -->
        <div class="bulk-action-bar mb-2">
            <button type="submit" name="bulk_process"
                    class="btn btn-success btn-sm">
                Process Selected (<span
                    class="selected-count"><?php
                    echo $processablecount;
                ?></span>)
            </button>
            <button type="button"
                    class="btn btn-outline-secondary btn-sm"
                    id="select-all-btn">Select All</button>
            <button type="button"
                    class="btn btn-outline-secondary btn-sm"
                    id="select-none-btn">Select None</button>
            <span class="text-muted ml-2"
                  aria-live="polite">
                <span class="selected-count"><?php
                    echo $processablecount;
                ?></span> of <?php
                    echo $processablecount;
                ?> processable selected</span>
        </div>
    <?php endif; ?>

    <table class="table table-bordered record-table">
        <caption class="sr-only">
            CData enrollment records for processing.
            Click or press Enter on a row to expand details.
        </caption>
        <thead>
            <tr>
                <?php if ($processablecount > 0) : ?>
                <th scope="col" style="width: 40px;"
                    class="text-center">
                    <input type="checkbox"
                           id="select-all-checkbox" checked
                           aria-label="Select or deselect all records">
                </th>
                <?php endif; ?>
                <th scope="col" style="width: 30px;">
                    <span class="sr-only">Expand row</span>
                </th>
                <th scope="col">Status</th>
                <th scope="col">User</th>
                <th scope="col">Course</th>
                <th scope="col">CData State</th>
                <th scope="col">Date Created</th>
                <th scope="col" style="width: 100px;">
                    Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($processedrecords as $index => $pr) :
            $record = $pr['record'];
            $user = $pr['user'];
            $course = $pr['course'];
            $statusinfo = $pr['status_info'];
            $isenrolled = $pr['is_enrolled'];

            // Encode record data for bulk processing.
            $recorddata = base64_encode(json_encode([
                'date_created' => $record['date_created'],
                'course_state' => $record['COURSE_STATE'],
                'elm_course_id' => $record['COURSE_IDENTIFIER'],
                'elm_enrolment_id' => $record['ENROLMENT_ID'],
                'guid' => $record['GUID'],
                'class_code' => $record['COURSE_SHORTNAME'],
                'email' => $record['EMAIL'],
                'first_name' => $record['FIRST_NAME'],
                'last_name' => $record['LAST_NAME'],
                'oprid' => $record['OPRID'] ?? '',
                'person_id' => $record['PERSON_ID'] ?? '',
                'activity_id' => $record['ACTIVITY_ID'] ?? '',
            ]));

            $rowlabel = htmlspecialchars(
                $record['FIRST_NAME'] . ' '
                . $record['LAST_NAME'] . ', '
                . $statusinfo['label']
            );
        ?>
            <tr class="record-row"
                data-index="<?php echo $index; ?>"
                tabindex="0" role="row"
                aria-expanded="false"
                aria-controls="details-<?php echo $index; ?>"
                aria-label="<?php
                    echo $rowlabel;
                ?>. Press Enter to expand.">
                <?php if ($processablecount > 0) : ?>
                <td class="text-center checkbox-cell"
                    onclick="event.stopPropagation();">
                    <?php if ($statusinfo['can_process']) : ?>
                    <input type="checkbox"
                           name="selected_records[]"
                           value="<?php echo $recorddata; ?>"
                           class="record-checkbox" checked>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="text-center">
                    <span class="expand-icon"
                          aria-hidden="true">&#9654;</span>
                </td>
                <td>
                    <span class="badge badge-<?php
                        echo $statusinfo['class'];
                    ?> status-badge">
                        <?php
                            echo $statusinfo['icon'];
                        ?> <?php
                            echo $statusinfo['label'];
                        ?>
                    </span>
                </td>
                <td>
                    <?php
                    echo htmlspecialchars(
                        $record['FIRST_NAME']
                        . ' ' . $record['LAST_NAME']
                    );
                    ?>
                    <br><small class="text-muted"><?php
                        echo htmlspecialchars($record['EMAIL']);
                    ?></small>
                </td>
                <td>
                    <?php if ($course) : ?>
                        <a href="/course/view.php?id=<?php
                            echo $course->id;
                        ?>" target="_blank">
                            <?php
                            echo htmlspecialchars(
                                $course->fullname
                            );
                            ?><span class="sr-only">
                                (opens in new window)</span>
                        </a>
                        <br><small class="text-muted"><?php
                            echo htmlspecialchars(
                                $record['COURSE_SHORTNAME']
                            );
                        ?></small>
                    <?php else : ?>
                        <span class="text-danger">
                            Not found</span>
                        <br><small class="text-muted">ID: <?php
                            echo htmlspecialchars(
                                $record['COURSE_IDENTIFIER']
                            );
                        ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $stateclass = $record['COURSE_STATE'] === 'Enrol'
                        ? 'primary' : 'secondary';
                    ?>
                    <span class="badge badge-<?php
                        echo $stateclass; ?>">
                        <?php
                        echo htmlspecialchars(
                            $record['COURSE_STATE']
                        );
                        ?>
                    </span>
                </td>
                <td>
                    <small><?php
                        echo htmlspecialchars(
                            substr($record['date_created'], 0, 16)
                        );
                    ?></small>
                </td>
                <td>
                    <?php if ($statusinfo['can_process']) : ?>
                        <form method="post"
                              action="<?php echo $PAGE->url; ?>"
                              class="d-inline process-form">
                            <input type="hidden"
                                   name="elm_course_id"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['COURSE_IDENTIFIER']
                                   ); ?>">
                            <input type="hidden"
                                   name="elm_enrolment_id"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['ENROLMENT_ID']
                                   ); ?>">
                            <input type="hidden"
                                   name="record_date_created"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['date_created']
                                   ); ?>">
                            <input type="hidden"
                                   name="course_state"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['COURSE_STATE']
                                   ); ?>">
                            <input type="hidden"
                                   name="class_code"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['COURSE_SHORTNAME']
                                   ); ?>">
                            <input type="hidden"
                                   name="guid"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['GUID']
                                   ); ?>">
                            <input type="hidden"
                                   name="email"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['EMAIL']
                                   ); ?>">
                            <input type="hidden"
                                   name="first_name"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['FIRST_NAME']
                                   ); ?>">
                            <input type="hidden"
                                   name="last_name"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['LAST_NAME']
                                   ); ?>">
                            <input type="hidden"
                                   name="oprid"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['OPRID'] ?? ''
                                   ); ?>">
                            <input type="hidden"
                                   name="person_id"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['PERSON_ID'] ?? ''
                                   ); ?>">
                            <input type="hidden"
                                   name="activity_id"
                                   value="<?php
                                   echo htmlspecialchars(
                                       $record['ACTIVITY_ID'] ?? ''
                                   ); ?>">
                            <input type="hidden" name="sesskey"
                                   value="<?php
                                       echo sesskey();
                                   ?>">
                            <!-- Preserve filter state. -->
                            <input type="hidden" name="from"
                                   value="<?php
                                       echo s($filterfrom);
                                   ?>">
                            <input type="hidden" name="to"
                                   value="<?php
                                       echo s($filterto);
                                   ?>">
                            <input type="hidden"
                                   name="email_filter"
                                   value="<?php
                                       echo s($filteremail);
                                   ?>">
                            <input type="hidden"
                                   name="guid_filter"
                                   value="<?php
                                       echo s($filterguid);
                                   ?>">
                            <input type="hidden"
                                   name="course_filter"
                                   value="<?php
                                       echo s($filtercourse);
                                   ?>">
                            <input type="hidden" name="state"
                                   value="<?php
                                       echo s($filterstate);
                                   ?>">
                            <input type="hidden" name="status"
                                   value="<?php
                                       echo s($filterstatus);
                                   ?>">
                            <button type="submit" name="process"
                                    class="btn btn-sm btn-success">
                                <?php
                                echo $record['COURSE_STATE'];
                                ?>
                            </button>
                        </form>
                    <?php else : ?>
                        <span class="text-muted">
                            &mdash;</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="record-details"
                data-index="<?php echo $index; ?>"
                id="details-<?php echo $index; ?>">
                <td colspan="<?php
                    echo $processablecount > 0 ? 8 : 7;
                ?>">
                    <div class="diff-container">
                        <div class="diff-panel">
                            <h6>CData Record</h6>
                            <div class="diff-row">
                                <span class="label">
                                    Email:</span>
                                <span class="value"><?php
                                    echo htmlspecialchars(
                                        $record['EMAIL']
                                    );
                                ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">
                                    Name:</span>
                                <span class="value"><?php
                                    echo htmlspecialchars(
                                        $record['FIRST_NAME']
                                        . ' '
                                        . $record['LAST_NAME']
                                    );
                                ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">
                                    GUID:</span>
                                <span class="value"><code><?php
                                    echo htmlspecialchars(
                                        $record['GUID']
                                    );
                                ?></code></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">
                                    OPRID:</span>
                                <span class="value"><?php
                                    echo htmlspecialchars(
                                        $record['OPRID'] ?? '—'
                                    );
                                ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">
                                    User State:</span>
                                <?php
                                $userstate = $record['USER_STATE'];
                                if ($userstate === 'INACTIVE') :
                                ?>
                                <span class="badge badge-danger">
                                    <?php
                                    echo htmlspecialchars(
                                        $userstate ?? '—'
                                    );
                                    ?>
                                </span>
                                <?php else : ?>
                                <span class="badge badge-success">
                                    <?php
                                    echo htmlspecialchars(
                                        $userstate ?? '—'
                                    );
                                    ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <hr>
                            <div class="diff-row">
                                <span class="label">
                                    Course ID:</span>
                                <span class="value"><?php
                                    echo htmlspecialchars(
                                        $record['COURSE_IDENTIFIER']
                                    );
                                ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">
                                    Course Shortname:</span>
                                <span class="value"><?php
                                    echo htmlspecialchars(
                                        $record['COURSE_SHORTNAME']
                                    );
                                ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">
                                    Course Name:</span>
                                <span class="value"><?php
                                    echo htmlspecialchars(
                                        $record['COURSE_LONG_NAME']
                                        ?? '—'
                                    );
                                ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">
                                    Requested Action:</span>
                                <span class="value">
                                    <strong><?php
                                    echo htmlspecialchars(
                                        $record['COURSE_STATE']
                                    );
                                    ?></strong>
                                </span>
                            </div>
                            <hr>
                            <div class="diff-row">
                                <span class="label">
                                    State Date:</span>
                                <span class="value"><?php
                                    echo htmlspecialchars(
                                        $record['COURSE_STATE_DATE']
                                        ?? '—'
                                    );
                                ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">
                                    Record Created:</span>
                                <span class="value"><?php
                                    echo htmlspecialchars(
                                        $record['date_created']
                                    );
                                ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">
                                    Enrolment ID:</span>
                                <span class="value"><?php
                                    echo htmlspecialchars(
                                        $record['ENROLMENT_ID']
                                        ?? '—'
                                    );
                                ?></span>
                            </div>
                        </div>

                        <div class="diff-panel">
                            <h6>Moodle State</h6>
                            <?php if ($user) : ?>
                                <?php
                                $emailmatch =
                                    strtolower($user->email)
                                    === strtolower(
                                        $record['EMAIL']
                                    );
                                $emailclass = $emailmatch
                                    ? 'diff-match'
                                    : 'diff-mismatch';
                                ?>
                                <div class="diff-row">
                                    <span class="label">
                                        Email:</span>
                                    <span class="value <?php
                                        echo $emailclass;
                                    ?>">
                                        <?php
                                        echo htmlspecialchars(
                                            $user->email
                                        );
                                        ?>
                                        <?php if ($emailmatch) : ?>
                                            <span
                                                aria-hidden="true">
                                                &#10003;</span>
                                            <span class="sr-only">
                                                Match</span>
                                        <?php else : ?>
                                            <span
                                                aria-hidden="true">
                                                &#10007;</span>
                                            MISMATCH
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="diff-row">
                                    <span class="label">
                                        Name:</span>
                                    <span class="value"><?php
                                        echo htmlspecialchars(
                                            $user->firstname
                                            . ' '
                                            . $user->lastname
                                        );
                                    ?></span>
                                </div>
                                <?php
                                $guidmatch =
                                    $user->idnumber
                                    === $record['GUID'];
                                $guidclass = $guidmatch
                                    ? 'diff-match'
                                    : 'diff-mismatch';
                                ?>
                                <div class="diff-row">
                                    <span class="label">
                                        GUID:</span>
                                    <span class="value <?php
                                        echo $guidclass;
                                    ?>">
                                        <code><?php
                                            echo htmlspecialchars(
                                                $user->idnumber
                                            );
                                        ?></code>
                                        <?php if ($guidmatch) : ?>
                                            <span
                                                aria-hidden="true">
                                                &#10003;</span>
                                            <span class="sr-only">
                                                Match</span>
                                        <?php else : ?>
                                            <span
                                                aria-hidden="true">
                                                &#10007;</span>
                                            <span class="sr-only">
                                                Mismatch</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="diff-row">
                                    <span class="label">
                                        Moodle User ID:</span>
                                    <span class="value">
                                        <a href="/user/view.php?id=<?php
                                            echo $user->id;
                                        ?>" target="_blank"><?php
                                            echo $user->id;
                                        ?><span class="sr-only">
                                            (opens in new window)
                                        </span></a>
                                    </span>
                                </div>
                            <?php else : ?>
                                <div class="diff-row">
                                    <span
                                        class="value diff-new">
                                        User does not exist
                                        &mdash; will be created
                                    </span>
                                </div>
                            <?php endif; ?>

                            <hr>

                            <?php if ($course) : ?>
                                <div class="diff-row">
                                    <span class="label">
                                        Course Found:</span>
                                    <span
                                        class="value diff-match">
                                        <a href="/course/view.php?id=<?php
                                            echo $course->id;
                                        ?>" target="_blank">
                                            <?php
                                            echo htmlspecialchars(
                                                $course->fullname
                                            );
                                            ?>
                                            <span class="sr-only">
                                                (opens in new window)
                                            </span>
                                        </a>
                                        <span
                                            aria-hidden="true">
                                            &#10003;</span>
                                        <span class="sr-only">
                                            Course found</span>
                                    </span>
                                </div>
                                <div class="diff-row">
                                    <span class="label">
                                        Moodle Course ID:</span>
                                    <span class="value"><?php
                                        echo $course->id;
                                    ?></span>
                                </div>
                                <div class="diff-row">
                                    <span class="label">
                                        Currently Enrolled:</span>
                                    <span class="value">
                                        <?php if ($user) : ?>
                                            <?php if ($isenrolled) : ?>
                                                <span
                                                    class="text-success">
                                                    Yes</span>
                                                <a href="/user/index.php?id=<?php
                                                    echo $course->id;
                                                ?>" target="_blank"
                                                   class="ml-2">
                                                    (View enrolled users
                                                    <span
                                                        class="sr-only">
                                                        - opens in
                                                        new window
                                                    </span>)
                                                </a>
                                            <?php else : ?>
                                                <span
                                                    class="text-muted">
                                                    No</span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span
                                                class="text-muted">
                                                N/A (user doesn't
                                                exist)</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php else : ?>
                                <div class="diff-row">
                                    <span
                                        class="value diff-mismatch">
                                        Course not found
                                        in Moodle &#10007;
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="status-reason">
                                <strong>Status:</strong>
                                <?php
                                echo $statusinfo['reason'];
                                ?>
                            </div>

                            <?php
                            // Show existing logs for this user/course combo.
                            if ($user && $course) {
                                $logs = $DB->get_records(
                                    'local_psaelmsync_logs',
                                    [
                                        'elm_course_id' => $record['COURSE_IDENTIFIER'],
                                        'user_id' => $user->id,
                                    ],
                                    'timestamp DESC',
                                    'id, timestamp, action, status',
                                    0,
                                    5
                                );
                                if (!empty($logs)) :
                            ?>
                            <div class="existing-logs">
                                <h6>Recent Sync Logs
                                    (this user + course)</h6>
                                <?php foreach ($logs as $log) : ?>
                                <div class="log-entry">
                                    <?php
                                    echo date(
                                        'Y-m-d H:i',
                                        $log->timestamp
                                    );
                                    ?> &mdash;
                                    <?php
                                    echo htmlspecialchars(
                                        $log->action
                                    );
                                    ?> &mdash;
                                    <?php
                                    $logstatusclass =
                                        $log->status === 'Success'
                                        ? 'text-success'
                                        : 'text-danger';
                                    ?>
                                    <span class="<?php
                                        echo $logstatusclass;
                                    ?>">
                                        <?php
                                        echo htmlspecialchars(
                                            $log->status
                                        );
                                        ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                                    <?php
                                endif;
                            }
                            ?>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <?php if ($user) : ?>
                            <a href="/user/view.php?id=<?php
                                echo $user->id;
                            ?>"
                               class="btn btn-sm btn-outline-primary"
                               target="_blank">
                                View User<span class="sr-only">
                                    (opens in new window)
                                </span></a>
                        <?php endif; ?>
                        <?php if ($course) : ?>
                            <a href="/course/view.php?id=<?php
                                echo $course->id;
                            ?>"
                               class="btn btn-sm btn-outline-primary"
                               target="_blank">
                                View Course<span class="sr-only">
                                    (opens in new window)
                                </span></a>
                            <a href="/user/index.php?id=<?php
                                echo $course->id;
                            ?>"
                               class="btn btn-sm btn-outline-secondary"
                               target="_blank">
                                Course Participants<span
                                    class="sr-only">
                                    (opens in new window)
                                </span></a>
                        <?php endif; ?>
                        <?php
                        $guidurl = urlencode($record['GUID']);
                        $loglink = '/local/psaelmsync/'
                            . 'dashboard.php?search='
                            . $guidurl;
                        ?>
                        <a href="<?php echo $loglink; ?>"
                           class="btn btn-sm btn-outline-secondary"
                           target="_blank">
                            Search Logs by GUID<span
                                class="sr-only">
                                (opens in new window)
                            </span></a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($processablecount > 0) : ?>
        <!-- Bulk Action Bar (Bottom) -->
        <div class="bulk-action-bar mt-2">
            <button type="submit" name="bulk_process"
                    class="btn btn-success btn-sm">
                Process Selected (<span
                    class="selected-count"><?php
                    echo $processablecount;
                ?></span>)
            </button>
            <button type="button"
                    class="btn btn-outline-secondary btn-sm"
                    id="select-all-btn-bottom">
                Select All</button>
            <button type="button"
                    class="btn btn-outline-secondary btn-sm"
                    id="select-none-btn-bottom">
                Select None</button>
            <span class="text-muted ml-2"
                  aria-live="polite">
                <span class="selected-count"><?php
                    echo $processablecount;
                ?></span> of <?php
                    echo $processablecount;
                ?> processable selected</span>
        </div>
    </form>
    <?php endif; ?>

<?php endif; ?>

<?php
if (empty($processedrecords) && !empty($apiurlfiltered)) {
    echo '<div class="alert alert-info">'
        . 'No records found matching your filters.</div>';
} else if (empty($processedrecords) && empty($apiurlfiltered)) {
    echo '<div class="alert alert-secondary">';
    echo '<strong>How to use:</strong> '
        . 'Enter search criteria above to query CData for '
        . 'enrolment records. You can search by email, GUID, '
        . 'course, date range, or any combination.';
    echo '<br><br>';
    echo '<strong>Status meanings:</strong>';
    echo '<ul class="mb-0 mt-2">';
    echo '<li>'
        . '<span class="badge badge-success">Ready</span>'
        . ' &mdash; Can be processed immediately</li>';
    echo '<li>'
        . '<span class="badge badge-info">New User</span>'
        . ' &mdash; User will be created, then enrolled</li>';
    echo '<li>'
        . '<span class="badge badge-warning">'
        . 'Email Mismatch</span>'
        . ' &mdash; Email in CData doesn\'t match Moodle;'
        . ' needs investigation</li>';
    echo '<li>'
        . '<span class="badge badge-danger">Blocked</span>'
        . ' &mdash; Cannot process'
        . ' (usually course not found)</li>';
    echo '<li>'
        . '<span class="badge badge-secondary">'
        . 'Already Done</span>'
        . ' &mdash; Already processed or'
        . ' no action needed</li>';
    echo '</ul>';
    echo '</div>';
}
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Bulk selection functionality.
    var checkboxes =
        document.querySelectorAll('.record-checkbox');
    var selectAllCheckbox =
        document.getElementById('select-all-checkbox');
    var selectedCountSpans =
        document.querySelectorAll('.selected-count');

    function updateSelectedCount() {
        var checked =
            document.querySelectorAll(
                '.record-checkbox:checked'
            );
        var count = checked.length;
        selectedCountSpans.forEach(function(span) {
            span.textContent = count;
        });
        if (selectAllCheckbox) {
            selectAllCheckbox.checked =
                count === checkboxes.length;
            selectAllCheckbox.indeterminate =
                count > 0 && count < checkboxes.length;
        }
    }

    function selectAll() {
        checkboxes.forEach(function(cb) {
            cb.checked = true;
        });
        updateSelectedCount();
    }

    function selectNone() {
        checkboxes.forEach(function(cb) {
            cb.checked = false;
        });
        updateSelectedCount();
    }

    // Select all checkbox in header.
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener(
            'change',
            function() {
                if (this.checked) {
                    selectAll();
                } else {
                    selectNone();
                }
            }
        );
    }

    // Select All / Select None buttons (top and bottom).
    var allBtnIds = [
        'select-all-btn',
        'select-all-btn-bottom'
    ];
    allBtnIds.forEach(function(id) {
        var btn = document.getElementById(id);
        if (btn) {
            btn.addEventListener('click', selectAll);
        }
    });

    var noneBtnIds = [
        'select-none-btn',
        'select-none-btn-bottom'
    ];
    noneBtnIds.forEach(function(id) {
        var btn = document.getElementById(id);
        if (btn) {
            btn.addEventListener('click', selectNone);
        }
    });

    // Individual checkbox changes.
    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', updateSelectedCount);
    });

    // Toggle row expansion.
    function toggleRowExpansion(row) {
        var index = row.dataset.index;
        var selector =
            '.record-details[data-index="'
            + index + '"]';
        var detailsRow =
            document.querySelector(selector);
        var isExpanded =
            row.classList.contains('expanded');

        row.classList.toggle('expanded');
        detailsRow.classList.toggle('show');

        // Update ARIA state.
        row.setAttribute(
            'aria-expanded',
            !isExpanded
        );
    }

    var rows =
        document.querySelectorAll('.record-row');
    rows.forEach(function(row) {
        // Click handler.
        row.addEventListener('click', function(e) {
            // Do not toggle if clicking on form, link, or checkbox.
            if (e.target.closest('.process-form')
                || e.target.closest('a')
                || e.target.closest('.checkbox-cell')
                || e.target.closest('button')) {
                return;
            }
            toggleRowExpansion(this);
        });

        // Keyboard handler for accessibility.
        row.addEventListener('keydown', function(e) {
            // Do not handle if focus is on a form element.
            if (e.target.closest('.process-form')
                || e.target.closest('a')
                || e.target.closest('.checkbox-cell')
                || e.target.closest('button')) {
                return;
            }

            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleRowExpansion(this);
            }
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
