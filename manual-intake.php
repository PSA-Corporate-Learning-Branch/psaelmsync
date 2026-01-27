<?php
/**
 * Manual intake - Diff-style UI for reviewing and processing CData enrolment records.
 *
 * Provides a clear comparison between CData records and Moodle state,
 * with status categorization and enhanced filtering.
 *
 * Author: Allan Haggett <allan.haggett@gov.bc.ca>
 */

global $CFG, $DB, $PAGE, $OUTPUT;

require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/local/psaelmsync/lib.php');

require_login();

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/manual-intake.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_psaelmsync') . ' - ' . get_string('queryapi', 'local_psaelmsync'));
$PAGE->set_heading(get_string('queryapi', 'local_psaelmsync'));

$apiurl = get_config('local_psaelmsync', 'apiurl');
$apitoken = get_config('local_psaelmsync', 'apitoken');

// Initialize variables
$data = null;
$feedback = '';
$feedback_type = 'info';

// Get filter values (persisted across requests)
$filter_from = optional_param('from', '', PARAM_TEXT);
$filter_to = optional_param('to', '', PARAM_TEXT);
$filter_email = optional_param('email', '', PARAM_TEXT);
$filter_guid = optional_param('guid', '', PARAM_TEXT);
$filter_course = optional_param('course', '', PARAM_TEXT);
$filter_state = optional_param('state', '', PARAM_ALPHA);
$filter_status = optional_param('status', '', PARAM_ALPHA);
$apiurlfiltered = '';

/**
 * Determine the status category for a record based on Moodle state comparison.
 */
function determine_record_status($record, $user, $course, $hash_exists, $is_enrolled) {
    // Already processed
    if ($hash_exists) {
        return [
            'status' => 'done',
            'label' => 'Already Processed',
            'icon' => '✓',
            'class' => 'secondary',
            'can_process' => false,
            'reason' => 'This record has already been processed.'
        ];
    }

    // Course not found
    if (!$course) {
        return [
            'status' => 'blocked',
            'label' => 'Course Not Found',
            'icon' => '✗',
            'class' => 'danger',
            'can_process' => false,
            'reason' => "Course with ELM ID {$record['COURSE_IDENTIFIER']} does not exist in Moodle."
        ];
    }

    // Check if action is already done (enrolled when should be enrolled, etc.)
    if ($record['COURSE_STATE'] === 'Enrol' && $is_enrolled) {
        return [
            'status' => 'done',
            'label' => 'Already Enrolled',
            'icon' => '✓',
            'class' => 'secondary',
            'can_process' => false,
            'reason' => 'User is already enrolled in this course.'
        ];
    }

    if ($record['COURSE_STATE'] === 'Suspend' && !$is_enrolled) {
        return [
            'status' => 'done',
            'label' => 'Not Enrolled',
            'icon' => '✓',
            'class' => 'secondary',
            'can_process' => false,
            'reason' => 'User is not enrolled, nothing to suspend.'
        ];
    }

    // User doesn't exist - will be created
    if (!$user) {
        return [
            'status' => 'new_user',
            'label' => 'New User',
            'icon' => '+',
            'class' => 'info',
            'can_process' => true,
            'reason' => 'User will be created and enrolled.'
        ];
    }

    // Email mismatch
    if (strtolower($user->email) !== strtolower($record['EMAIL'])) {
        return [
            'status' => 'mismatch',
            'label' => 'Email Mismatch',
            'icon' => '⚠',
            'class' => 'warning',
            'can_process' => false,
            'reason' => 'The email in CData does not match the Moodle account. Manual investigation required.'
        ];
    }

    // Ready to process
    return [
        'status' => 'ready',
        'label' => 'Ready',
        'icon' => '●',
        'class' => 'success',
        'can_process' => true,
        'reason' => "Ready to {$record['COURSE_STATE']}."
    ];
}

/**
 * Check if user is enrolled in course by idnumber.
 */
function check_user_enrolled($courseidnumber, $userid) {
    global $DB;

    $course = $DB->get_record('course', ['idnumber' => $courseidnumber]);
    if (!$course) {
        return false;
    }

    $user_courses = enrol_get_users_courses($userid, true, ['id']);
    foreach ($user_courses as $user_course) {
        if ($user_course->id == $course->id) {
            return true;
        }
    }
    return false;
}

/**
 * Create a new user (local helper matching lib.php pattern).
 */
function create_new_user_local($user_email, $first_name, $last_name, $user_guid) {
    global $DB;

    $user = new stdClass();
    $user->username = strtolower($user_email);
    $user->email = $user_email;
    $user->idnumber = $user_guid;
    $user->firstname = $first_name;
    $user->lastname = $last_name;
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

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process'])) {
    require_sesskey();

    $record_date_created = required_param('record_date_created', PARAM_TEXT);
    $course_state = required_param('course_state', PARAM_TEXT);
    $elm_course_id = required_param('elm_course_id', PARAM_TEXT);
    $elm_enrolment_id = required_param('elm_enrolment_id', PARAM_TEXT);
    $user_guid = required_param('guid', PARAM_TEXT);
    $class_code = required_param('class_code', PARAM_TEXT);
    $user_email = required_param('email', PARAM_TEXT);
    $first_name = required_param('first_name', PARAM_TEXT);
    $last_name = required_param('last_name', PARAM_TEXT);

    $hash_content = $record_date_created . $elm_course_id . $class_code . $course_state . $user_guid . $user_email;
    $hash = hash('sha256', $hash_content);

    $hashcheck = $DB->get_record('local_psaelmsync_logs', ['sha256hash' => $hash], '*', IGNORE_MULTIPLE);

    if ($hashcheck) {
        $feedback = 'This record has already been processed.';
        $feedback_type = 'warning';
    } else {
        $course = $DB->get_record('course', ['idnumber' => $elm_course_id]);

        if (!$course) {
            $feedback = "Course with ELM ID {$elm_course_id} not found in Moodle.";
            $feedback_type = 'danger';
        } else {
            $user = $DB->get_record('user', ['idnumber' => $user_guid]);

            if (!$user) {
                $user = create_new_user_local($user_email, $first_name, $last_name, $user_guid);

                if (!$user) {
                    $useremailcheck = $DB->get_record('user', ['email' => $user_email]);
                    if ($useremailcheck) {
                        $feedback = "Failed to create user. An account with email {$user_email} already exists. ";
                        $feedback .= "<a href='/user/view.php?id={$useremailcheck->id}' target='_blank'>View existing account</a>";
                    } else {
                        $feedback = "Failed to create a new user for GUID {$user_guid}.";
                    }
                    $feedback_type = 'danger';
                }
            }

            if ($user) {
                // Check for email mismatch
                if (strtolower($user->email) !== strtolower($user_email)) {
                    $feedback = "Email mismatch: Moodle has '{$user->email}' but CData has '{$user_email}'. ";
                    $useremailcheck = $DB->get_record('user', ['email' => $user_email]);
                    if ($useremailcheck) {
                        $feedback .= "Another account exists with the CData email: ";
                        $feedback .= "<a href='/user/view.php?id={$useremailcheck->id}' target='_blank'>View account</a>";
                    }
                    $feedback_type = 'danger';
                } else {
                    $manual_enrol = enrol_get_plugin('manual');
                    $enrol_instances = enrol_get_instances($course->id, true);
                    $manual_instance = null;

                    foreach ($enrol_instances as $instance) {
                        if ($instance->enrol === 'manual') {
                            $manual_instance = $instance;
                            break;
                        }
                    }

                    if ($manual_instance && !empty($manual_instance->roleid)) {
                        if ($course_state === 'Enrol') {
                            $manual_enrol->enrol_user($manual_instance, $user->id, $manual_instance->roleid, 0, 0, ENROL_USER_ACTIVE);

                            $is_enrolled = $DB->record_exists('user_enrolments', ['userid' => $user->id, 'enrolid' => $manual_instance->id]);

                            if ($is_enrolled) {
                                $log = new stdClass();
                                $log->record_id = time();
                                $log->sha256hash = $hash;
                                $log->record_date_created = $record_date_created;
                                $log->course_id = $course->id;
                                $log->elm_course_id = $elm_course_id;
                                $log->class_code = $class_code;
                                $log->course_name = $course->fullname;
                                $log->user_id = $user->id;
                                $log->user_firstname = $user->firstname;
                                $log->user_lastname = $user->lastname;
                                $log->user_guid = $user->idnumber;
                                $log->user_email = $user->email;
                                $log->elm_enrolment_id = $elm_enrolment_id;
                                $log->action = 'Manual Enrol';
                                $log->status = 'Success';
                                $log->timestamp = time();

                                $DB->insert_record('local_psaelmsync_logs', $log);

                                $feedback = "Successfully enrolled {$user->email} in {$course->fullname}.";
                                $feedback_type = 'success';

                                send_welcome_email($user, $course);
                            } else {
                                $feedback = "Failed to enrol {$user->email} in the course.";
                                $feedback_type = 'danger';
                            }
                        } elseif ($course_state === 'Suspend') {
                            $manual_enrol->update_user_enrol($manual_instance, $user->id, ENROL_USER_SUSPENDED);

                            $log = new stdClass();
                            $log->record_id = time();
                            $log->sha256hash = $hash;
                            $log->record_date_created = $record_date_created;
                            $log->course_id = $course->id;
                            $log->elm_course_id = $elm_course_id;
                            $log->class_code = $class_code;
                            $log->course_name = $course->fullname;
                            $log->user_id = $user->id;
                            $log->user_firstname = $user->firstname;
                            $log->user_lastname = $user->lastname;
                            $log->user_guid = $user->idnumber;
                            $log->user_email = $user->email;
                            $log->elm_enrolment_id = $elm_enrolment_id;
                            $log->action = 'Manual Suspend';
                            $log->status = 'Success';
                            $log->timestamp = time();

                            $DB->insert_record('local_psaelmsync_logs', $log);

                            $feedback = "Successfully suspended {$user->email} from {$course->fullname}.";
                            $feedback_type = 'success';
                        } else {
                            $feedback = "Invalid course state: {$course_state}";
                            $feedback_type = 'danger';
                        }
                    } else {
                        $feedback = "No manual enrolment instance found for this course.";
                        $feedback_type = 'danger';
                    }
                }
            }
        }
    }
}

// Build API query if filters are provided
if (!empty($filter_email) || !empty($filter_guid) || !empty($filter_from) || !empty($filter_course)) {
    $filters = [];

    if (!empty($filter_email)) {
        $filters[] = "email+eq+%27" . urlencode($filter_email) . "%27";
    }
    if (!empty($filter_guid)) {
        $filters[] = "GUID+eq+%27" . urlencode($filter_guid) . "%27";
    }
    if (!empty($filter_course)) {
        // Support both course ID and shortname search
        if (is_numeric($filter_course)) {
            $filters[] = "COURSE_IDENTIFIER+eq+" . urlencode($filter_course);
        } else {
            $filters[] = "COURSE_SHORTNAME+eq+%27" . urlencode($filter_course) . "%27";
        }
    }
    if (!empty($filter_from) && !empty($filter_to)) {
        $filters[] = "date_created+gt+%27" . urlencode($filter_from) . "%27";
        $filters[] = "date_created+lt+%27" . urlencode($filter_to) . "%27";
    } elseif (!empty($filter_from)) {
        $filters[] = "date_created+gt+%27" . urlencode($filter_from) . "%27";
    }
    if (!empty($filter_state)) {
        $filters[] = "COURSE_STATE+eq+%27" . urlencode($filter_state) . "%27";
    }

    $apiurlfiltered = $apiurl . "?%24orderby=COURSE_STATE_DATE,date_created+asc";
    if (!empty($filters)) {
        $apiurlfiltered .= "&%24filter=" . implode("+and+", $filters);
    }

    $options = array(
        'RETURNTRANSFER' => 1,
        'HEADER' => 0,
    );
    $header = array('x-cdata-authtoken: ' . $apitoken);
    $curl = new curl();
    $curl->setHeader($header);
    $response = $curl->get($apiurlfiltered, $options);

    // Check for cURL-level errors
    if ($curl->get_errno()) {
        $feedback = 'cURL Error: ' . $curl->error;
        $feedback_type = 'danger';
    } else {
        // Check HTTP status code
        $info = $curl->get_info();
        $http_code = $info['http_code'] ?? 0;

        if ($http_code >= 400) {
            $feedback = "API Error: HTTP {$http_code}";
            if ($http_code == 502) {
                $feedback .= ' (Bad Gateway - the CData server may be down or unreachable)';
            } elseif ($http_code == 401) {
                $feedback .= ' (Unauthorized - check API token)';
            } elseif ($http_code == 403) {
                $feedback .= ' (Forbidden - check IP whitelist/VPN)';
            } elseif ($http_code == 404) {
                $feedback .= ' (Not Found - check API URL)';
            } elseif ($http_code == 500) {
                $feedback .= ' (Internal Server Error)';
            }
            $feedback .= '<br><small class="text-muted">Response: ' . htmlspecialchars(substr($response, 0, 500)) . '</small>';
            $feedback_type = 'danger';
        } else {
            $data = json_decode($response, true);

            // Check for JSON decode errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                $feedback = 'API Error: Invalid JSON response - ' . json_last_error_msg();
                $feedback .= '<br><small class="text-muted">Response: ' . htmlspecialchars(substr($response, 0, 500)) . '</small>';
                $feedback_type = 'danger';
                $data = null;
            }
        }
    }
}

// Process records to add status information
$processed_records = [];
if (!empty($data['value'])) {
    foreach ($data['value'] as $record) {
        $user = $DB->get_record('user', ['idnumber' => $record['GUID']]);
        $course = $DB->get_record('course', ['idnumber' => (int)$record['COURSE_IDENTIFIER']], 'id, fullname, shortname');

        $hash_content = $record['date_created'] . $record['COURSE_IDENTIFIER'] . $record['COURSE_SHORTNAME'] . $record['COURSE_STATE'] . $record['GUID'] . $record['EMAIL'];
        $hash = hash('sha256', $hash_content);
        $hash_exists = $DB->record_exists('local_psaelmsync_logs', ['sha256hash' => $hash]);

        $is_enrolled = false;
        if ($user && $course) {
            $is_enrolled = check_user_enrolled($record['COURSE_IDENTIFIER'], $user->id);
        }

        $status_info = determine_record_status($record, $user, $course, $hash_exists, $is_enrolled);

        // Apply status filter if set
        if (!empty($filter_status) && $status_info['status'] !== $filter_status) {
            continue;
        }

        $processed_records[] = [
            'record' => $record,
            'user' => $user,
            'course' => $course,
            'is_enrolled' => $is_enrolled,
            'status_info' => $status_info,
            'hash_exists' => $hash_exists
        ];
    }
}

echo $OUTPUT->header();
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
</style>

<!-- Tabbed Navigation -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings.php?section=local_psaelmsync">Settings</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard.php">Learner Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard-courses.php">Course Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/dashboard-intake.php">Intake Run Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="/local/psaelmsync/manual-intake.php">Manual Intake</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/local/psaelmsync/manual-complete.php">Manual Complete</a>
    </li>
</ul>

<?php if (!empty($feedback)): ?>
<div class="alert alert-<?php echo $feedback_type; ?> alert-dismissible fade show" role="alert">
    <?php echo $feedback; ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
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
                    <input type="datetime-local" id="from" name="from" class="form-control form-control-sm"
                           value="<?php echo s($filter_from); ?>">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="to">To Date</label>
                    <input type="datetime-local" id="to" name="to" class="form-control form-control-sm"
                           value="<?php echo s($filter_to); ?>">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control form-control-sm"
                           value="<?php echo s($filter_email); ?>" placeholder="user@example.com">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="guid">GUID</label>
                    <input type="text" id="guid" name="guid" class="form-control form-control-sm"
                           value="<?php echo s($filter_guid); ?>" placeholder="5F421FC1A510...">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="course">Course ID/Shortname</label>
                    <input type="text" id="course" name="course" class="form-control form-control-sm"
                           value="<?php echo s($filter_course); ?>" placeholder="40972 or ITEM-2625-1">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="state">CData State</label>
                    <select id="state" name="state" class="form-control form-control-sm">
                        <option value="">All</option>
                        <option value="Enrol" <?php echo $filter_state === 'Enrol' ? 'selected' : ''; ?>>Enrol</option>
                        <option value="Suspend" <?php echo $filter_state === 'Suspend' ? 'selected' : ''; ?>>Suspend</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="row mt-2">
            <div class="col-md-2">
                <div class="form-group">
                    <label for="status">Record Status</label>
                    <select id="status" name="status" class="form-control form-control-sm">
                        <option value="">All</option>
                        <option value="ready" <?php echo $filter_status === 'ready' ? 'selected' : ''; ?>>Ready to Process</option>
                        <option value="new_user" <?php echo $filter_status === 'new_user' ? 'selected' : ''; ?>>New User</option>
                        <option value="mismatch" <?php echo $filter_status === 'mismatch' ? 'selected' : ''; ?>>Email Mismatch</option>
                        <option value="blocked" <?php echo $filter_status === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                        <option value="done" <?php echo $filter_status === 'done' ? 'selected' : ''; ?>>Already Done</option>
                    </select>
                </div>
            </div>
            <div class="col-md-10">
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Search CData</button>
                    <a href="<?php echo $PAGE->url; ?>" class="btn btn-secondary btn-sm">Clear Filters</a>
                </div>
            </div>
        </div>
    </form>
</div>

<?php if (!empty($apiurlfiltered)): ?>
<div class="query-debug">
    <strong>API Query:</strong>
    <code><?php echo htmlspecialchars($apiurlfiltered); ?></code>
    <a href="<?php echo $apiurlfiltered; ?>" class="btn btn-sm btn-outline-secondary ml-2" target="_blank">Open in browser</a>
    <small class="text-muted">(VPN + IP whitelist required)</small>
</div>
<?php endif; ?>

<?php if (!empty($processed_records)): ?>
    <?php
    // Calculate summary counts
    $status_counts = ['ready' => 0, 'new_user' => 0, 'mismatch' => 0, 'blocked' => 0, 'done' => 0];
    foreach ($processed_records as $pr) {
        $status_counts[$pr['status_info']['status']]++;
    }
    ?>

    <div class="results-summary">
        <div class="summary-item"><strong><?php echo count($processed_records); ?></strong> records found</div>
        <?php if ($status_counts['ready'] > 0): ?>
            <div class="summary-item text-success"><strong><?php echo $status_counts['ready']; ?></strong> ready</div>
        <?php endif; ?>
        <?php if ($status_counts['new_user'] > 0): ?>
            <div class="summary-item text-info"><strong><?php echo $status_counts['new_user']; ?></strong> new users</div>
        <?php endif; ?>
        <?php if ($status_counts['mismatch'] > 0): ?>
            <div class="summary-item text-warning"><strong><?php echo $status_counts['mismatch']; ?></strong> mismatches</div>
        <?php endif; ?>
        <?php if ($status_counts['blocked'] > 0): ?>
            <div class="summary-item text-danger"><strong><?php echo $status_counts['blocked']; ?></strong> blocked</div>
        <?php endif; ?>
        <?php if ($status_counts['done'] > 0): ?>
            <div class="summary-item text-secondary"><strong><?php echo $status_counts['done']; ?></strong> already done</div>
        <?php endif; ?>
    </div>

    <table class="table table-bordered record-table">
        <thead>
            <tr>
                <th style="width: 30px;"></th>
                <th>Status</th>
                <th>User</th>
                <th>Course</th>
                <th>CData State</th>
                <th>Date Created</th>
                <th style="width: 100px;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($processed_records as $index => $pr):
            $record = $pr['record'];
            $user = $pr['user'];
            $course = $pr['course'];
            $status_info = $pr['status_info'];
            $is_enrolled = $pr['is_enrolled'];
        ?>
            <tr class="record-row" data-index="<?php echo $index; ?>">
                <td class="text-center"><span class="expand-icon">▶</span></td>
                <td>
                    <span class="badge badge-<?php echo $status_info['class']; ?> status-badge">
                        <?php echo $status_info['icon']; ?> <?php echo $status_info['label']; ?>
                    </span>
                </td>
                <td>
                    <?php echo htmlspecialchars($record['FIRST_NAME'] . ' ' . $record['LAST_NAME']); ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($record['EMAIL']); ?></small>
                </td>
                <td>
                    <?php if ($course): ?>
                        <a href="/course/view.php?id=<?php echo $course->id; ?>" target="_blank">
                            <?php echo htmlspecialchars($course->fullname); ?>
                        </a>
                        <br><small class="text-muted"><?php echo htmlspecialchars($record['COURSE_SHORTNAME']); ?></small>
                    <?php else: ?>
                        <span class="text-danger">Not found</span>
                        <br><small class="text-muted">ID: <?php echo htmlspecialchars($record['COURSE_IDENTIFIER']); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?php echo $record['COURSE_STATE'] === 'Enrol' ? 'primary' : 'secondary'; ?>">
                        <?php echo htmlspecialchars($record['COURSE_STATE']); ?>
                    </span>
                </td>
                <td>
                    <small><?php echo htmlspecialchars(substr($record['date_created'], 0, 16)); ?></small>
                </td>
                <td>
                    <?php if ($status_info['can_process']): ?>
                        <form method="post" action="<?php echo $PAGE->url; ?>" class="d-inline process-form">
                            <input type="hidden" name="elm_course_id" value="<?php echo htmlspecialchars($record['COURSE_IDENTIFIER']); ?>">
                            <input type="hidden" name="elm_enrolment_id" value="<?php echo htmlspecialchars($record['ENROLMENT_ID']); ?>">
                            <input type="hidden" name="record_date_created" value="<?php echo htmlspecialchars($record['date_created']); ?>">
                            <input type="hidden" name="course_state" value="<?php echo htmlspecialchars($record['COURSE_STATE']); ?>">
                            <input type="hidden" name="class_code" value="<?php echo htmlspecialchars($record['COURSE_SHORTNAME']); ?>">
                            <input type="hidden" name="guid" value="<?php echo htmlspecialchars($record['GUID']); ?>">
                            <input type="hidden" name="email" value="<?php echo htmlspecialchars($record['EMAIL']); ?>">
                            <input type="hidden" name="first_name" value="<?php echo htmlspecialchars($record['FIRST_NAME']); ?>">
                            <input type="hidden" name="last_name" value="<?php echo htmlspecialchars($record['LAST_NAME']); ?>">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <!-- Preserve filter state -->
                            <input type="hidden" name="from" value="<?php echo s($filter_from); ?>">
                            <input type="hidden" name="to" value="<?php echo s($filter_to); ?>">
                            <input type="hidden" name="email_filter" value="<?php echo s($filter_email); ?>">
                            <input type="hidden" name="guid_filter" value="<?php echo s($filter_guid); ?>">
                            <input type="hidden" name="course_filter" value="<?php echo s($filter_course); ?>">
                            <input type="hidden" name="state" value="<?php echo s($filter_state); ?>">
                            <input type="hidden" name="status" value="<?php echo s($filter_status); ?>">
                            <button type="submit" name="process" class="btn btn-sm btn-success">
                                <?php echo $record['COURSE_STATE']; ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="record-details" data-index="<?php echo $index; ?>">
                <td colspan="7">
                    <div class="diff-container">
                        <div class="diff-panel">
                            <h6>CData Record</h6>
                            <div class="diff-row">
                                <span class="label">Email:</span>
                                <span class="value"><?php echo htmlspecialchars($record['EMAIL']); ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">Name:</span>
                                <span class="value"><?php echo htmlspecialchars($record['FIRST_NAME'] . ' ' . $record['LAST_NAME']); ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">GUID:</span>
                                <span class="value"><code><?php echo htmlspecialchars($record['GUID']); ?></code></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">OPRID:</span>
                                <span class="value"><?php echo htmlspecialchars($record['OPRID'] ?? '—'); ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">User State:</span>
                                <span class="value"><?php echo htmlspecialchars($record['USER_STATE'] ?? '—'); ?></span>
                            </div>
                            <hr>
                            <div class="diff-row">
                                <span class="label">Course ID:</span>
                                <span class="value"><?php echo htmlspecialchars($record['COURSE_IDENTIFIER']); ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">Course Shortname:</span>
                                <span class="value"><?php echo htmlspecialchars($record['COURSE_SHORTNAME']); ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">Course Name:</span>
                                <span class="value"><?php echo htmlspecialchars($record['COURSE_LONG_NAME'] ?? '—'); ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">Requested Action:</span>
                                <span class="value"><strong><?php echo htmlspecialchars($record['COURSE_STATE']); ?></strong></span>
                            </div>
                            <hr>
                            <div class="diff-row">
                                <span class="label">State Date:</span>
                                <span class="value"><?php echo htmlspecialchars($record['COURSE_STATE_DATE'] ?? '—'); ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">Record Created:</span>
                                <span class="value"><?php echo htmlspecialchars($record['date_created']); ?></span>
                            </div>
                            <div class="diff-row">
                                <span class="label">Enrolment ID:</span>
                                <span class="value"><?php echo htmlspecialchars($record['ENROLMENT_ID'] ?? '—'); ?></span>
                            </div>
                        </div>

                        <div class="diff-panel">
                            <h6>Moodle State</h6>
                            <?php if ($user): ?>
                                <div class="diff-row">
                                    <span class="label">Email:</span>
                                    <span class="value <?php echo strtolower($user->email) === strtolower($record['EMAIL']) ? 'diff-match' : 'diff-mismatch'; ?>">
                                        <?php echo htmlspecialchars($user->email); ?>
                                        <?php echo strtolower($user->email) === strtolower($record['EMAIL']) ? '✓' : '✗ MISMATCH'; ?>
                                    </span>
                                </div>
                                <div class="diff-row">
                                    <span class="label">Name:</span>
                                    <span class="value"><?php echo htmlspecialchars($user->firstname . ' ' . $user->lastname); ?></span>
                                </div>
                                <div class="diff-row">
                                    <span class="label">GUID:</span>
                                    <span class="value <?php echo $user->idnumber === $record['GUID'] ? 'diff-match' : 'diff-mismatch'; ?>">
                                        <code><?php echo htmlspecialchars($user->idnumber); ?></code>
                                        <?php echo $user->idnumber === $record['GUID'] ? '✓' : '✗'; ?>
                                    </span>
                                </div>
                                <div class="diff-row">
                                    <span class="label">Moodle User ID:</span>
                                    <span class="value">
                                        <a href="/user/view.php?id=<?php echo $user->id; ?>" target="_blank"><?php echo $user->id; ?></a>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="diff-row">
                                    <span class="value diff-new">User does not exist — will be created</span>
                                </div>
                            <?php endif; ?>

                            <hr>

                            <?php if ($course): ?>
                                <div class="diff-row">
                                    <span class="label">Course Found:</span>
                                    <span class="value diff-match">
                                        <a href="/course/view.php?id=<?php echo $course->id; ?>" target="_blank">
                                            <?php echo htmlspecialchars($course->fullname); ?>
                                        </a> ✓
                                    </span>
                                </div>
                                <div class="diff-row">
                                    <span class="label">Moodle Course ID:</span>
                                    <span class="value"><?php echo $course->id; ?></span>
                                </div>
                                <div class="diff-row">
                                    <span class="label">Currently Enrolled:</span>
                                    <span class="value">
                                        <?php if ($user): ?>
                                            <?php echo $is_enrolled ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>'; ?>
                                            <?php if ($is_enrolled): ?>
                                                <a href="/user/index.php?id=<?php echo $course->id; ?>" target="_blank" class="ml-2">(View enrolled users)</a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">N/A (user doesn't exist)</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="diff-row">
                                    <span class="value diff-mismatch">Course not found in Moodle ✗</span>
                                </div>
                            <?php endif; ?>

                            <div class="status-reason">
                                <strong>Status:</strong> <?php echo $status_info['reason']; ?>
                            </div>

                            <?php
                            // Show existing logs for this user/course combo
                            if ($user && $course) {
                                $logs = $DB->get_records('local_psaelmsync_logs',
                                    ['elm_course_id' => $record['COURSE_IDENTIFIER'], 'user_id' => $user->id],
                                    'timestamp DESC',
                                    'id, timestamp, action, status',
                                    0, 5);
                                if (!empty($logs)):
                            ?>
                            <div class="existing-logs">
                                <h6>Recent Sync Logs (this user + course)</h6>
                                <?php foreach ($logs as $log): ?>
                                    <div class="log-entry">
                                        <?php echo date('Y-m-d H:i', $log->timestamp); ?> —
                                        <?php echo htmlspecialchars($log->action); ?> —
                                        <span class="<?php echo $log->status === 'Success' ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo htmlspecialchars($log->status); ?>
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
                        <?php if ($user): ?>
                            <a href="/user/view.php?id=<?php echo $user->id; ?>" class="btn btn-sm btn-outline-primary" target="_blank">View User</a>
                        <?php endif; ?>
                        <?php if ($course): ?>
                            <a href="/course/view.php?id=<?php echo $course->id; ?>" class="btn btn-sm btn-outline-primary" target="_blank">View Course</a>
                            <a href="/user/index.php?id=<?php echo $course->id; ?>" class="btn btn-sm btn-outline-secondary" target="_blank">Course Participants</a>
                        <?php endif; ?>
                        <a href="/local/psaelmsync/dashboard.php?search=<?php echo urlencode($record['GUID']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank">Search Logs by GUID</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif (!empty($apiurlfiltered)): ?>
    <div class="alert alert-info">No records found matching your filters.</div>
<?php else: ?>
    <div class="alert alert-secondary">
        <strong>How to use:</strong> Enter search criteria above to query CData for enrolment records.
        You can search by email, GUID, course, date range, or any combination.
        <br><br>
        <strong>Status meanings:</strong>
        <ul class="mb-0 mt-2">
            <li><span class="badge badge-success">Ready</span> — Can be processed immediately</li>
            <li><span class="badge badge-info">New User</span> — User will be created, then enrolled</li>
            <li><span class="badge badge-warning">Email Mismatch</span> — Email in CData doesn't match Moodle; needs investigation</li>
            <li><span class="badge badge-danger">Blocked</span> — Cannot process (usually course not found)</li>
            <li><span class="badge badge-secondary">Already Done</span> — Already processed or no action needed</li>
        </ul>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle row expansion
    document.querySelectorAll('.record-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            // Don't toggle if clicking on a form button or link
            if (e.target.closest('form') || e.target.closest('a')) {
                return;
            }

            var index = this.dataset.index;
            var detailsRow = document.querySelector('.record-details[data-index="' + index + '"]');

            this.classList.toggle('expanded');
            detailsRow.classList.toggle('show');
        });
    });

    // Prevent form submission from triggering row expansion
    document.querySelectorAll('.process-form').forEach(function(form) {
        form.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
