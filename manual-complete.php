<?php
/**
 * Manual completion - Progressive disclosure UI for manually posting course completions to CData.
 *
 * Flow:
 * 1. Search/select a course (filtered to completion_opt_in courses)
 * 2. Search/select a user enrolled in that course
 * 3. View completion status and details
 * 4. Post completion to CData if not already completed
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

$PAGE->set_url('/local/psaelmsync/manual-complete.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_psaelmsync') . ' - Manual Completion');
$PAGE->set_heading('Manual Completion');

// Get config
$completion_apiurl = get_config('local_psaelmsync', 'completion_apiurl');
$completion_apitoken = get_config('local_psaelmsync', 'completion_apitoken');

// Get parameters for progressive disclosure
$course_search = optional_param('course_search', '', PARAM_TEXT);
$selected_course_id = optional_param('course_id', 0, PARAM_INT);
$user_search = optional_param('user_search', '', PARAM_TEXT);
$selected_user_id = optional_param('user_id', 0, PARAM_INT);

// Feedback messages
$feedback = '';
$feedback_type = 'info';

/**
 * Get courses that have completion_opt_in enabled.
 * @param string $search Optional search string to filter courses.
 * @return array Array of course records.
 */
function get_completion_courses($search = '') {
    global $DB;

    // Get courses with completion_opt_in custom field set to 1
    $sql = "SELECT c.id, c.fullname, c.shortname, c.idnumber
            FROM {course} c
            JOIN {customfield_data} cd ON cd.instanceid = c.id
            JOIN {customfield_field} cf ON cf.id = cd.fieldid
            WHERE cf.shortname = 'completion_opt_in'
            AND cd.intvalue = 1
            AND c.id > 1";

    $params = [];

    if (!empty($search)) {
        $sql .= " AND (
            " . $DB->sql_like('c.fullname', ':search1', false) . "
            OR " . $DB->sql_like('c.shortname', ':search2', false) . "
            OR " . $DB->sql_like('c.idnumber', ':search3', false) . "
        )";
        $params['search1'] = '%' . $search . '%';
        $params['search2'] = '%' . $search . '%';
        $params['search3'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY c.fullname ASC LIMIT 50";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Get users enrolled in a course.
 * @param int $courseid The Moodle course ID.
 * @param string $search Optional search string to filter users.
 * @return array Array of user records.
 */
function get_enrolled_users_search($courseid, $search = '') {
    global $DB;

    $context = \context_course::instance($courseid);
    $enrolledusers = get_enrolled_users($context, '', 0, 'u.id, u.firstname, u.lastname, u.email, u.idnumber');

    if (empty($search)) {
        return array_slice($enrolledusers, 0, 50, true);
    }

    $search = strtolower($search);
    $filtered = array_filter($enrolledusers, function($user) use ($search) {
        return strpos(strtolower($user->firstname), $search) !== false
            || strpos(strtolower($user->lastname), $search) !== false
            || strpos(strtolower($user->email), $search) !== false
            || strpos(strtolower($user->idnumber), $search) !== false;
    });

    return array_slice($filtered, 0, 50, true);
}

/**
 * Check if user has completed the course in Moodle.
 * @param int $courseid The Moodle course ID.
 * @param int $userid The Moodle user ID.
 * @return array Completion status with 'completed' key and optional 'timecompleted'.
 */
function check_moodle_completion($courseid, $userid) {
    global $DB;

    $completion = $DB->get_record('course_completions', [
        'course' => $courseid,
        'userid' => $userid
    ]);

    if ($completion && $completion->timecompleted) {
        return [
            'completed' => true,
            'timecompleted' => $completion->timecompleted
        ];
    }

    return ['completed' => false];
}

/**
 * Check if completion has been logged/sent to CData (local logs).
 * @param int|string $elm_course_id The ELM course identifier.
 * @param int $userid The Moodle user ID.
 * @return object|null The log record or null if not found.
 */
function check_completion_logged($elm_course_id, $userid) {
    global $DB;

    $log = $DB->get_record_sql(
        "SELECT * FROM {local_psaelmsync_logs}
         WHERE elm_course_id = :courseid
         AND user_id = :userid
         AND action IN ('Complete', 'Manual Complete')
         ORDER BY timestamp DESC
         LIMIT 1",
        ['courseid' => $elm_course_id, 'userid' => $userid]
    );

    return $log ?: null;
}

/**
 * Query CData API to check completion status.
 * @param string $guid The user GUID.
 * @param int|string $elm_course_id The ELM course identifier.
 * @return array Array with 'success', 'error', 'data', 'http_code' keys.
 */
function check_cdata_completion($guid, $elm_course_id) {
    $apiurl = get_config('local_psaelmsync', 'completion_apiurl');
    $apitoken = get_config('local_psaelmsync', 'completion_apitoken');

    if (empty($apiurl) || empty($apitoken)) {
        return [
            'success' => false,
            'error' => 'Completion API URL or token not configured',
            'data' => null,
            'http_code' => 0
        ];
    }

    // Query for this specific user/course combination
    $filter = "GUID+eq+%27" . urlencode($guid) . "%27+and+COURSE_IDENTIFIER+eq+" . urlencode($elm_course_id);
    $query_url = $apiurl . "?%24filter=" . $filter;

    $ch = curl_init($query_url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "x-cdata-authtoken: " . $apitoken,
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 10
    ];
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $curlerror = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Handle cURL errors
    if ($response === false) {
        return [
            'success' => false,
            'error' => 'cURL error: ' . $curlerror,
            'data' => null,
            'http_code' => 0
        ];
    }

    // Handle HTTP errors
    if ($httpcode >= 400) {
        $error_msg = "HTTP {$httpcode}";
        if ($httpcode == 502) {
            $error_msg .= ' (Bad Gateway - CData server may be down)';
        } elseif ($httpcode == 401) {
            $error_msg .= ' (Unauthorized - check API token)';
        } elseif ($httpcode == 403) {
            $error_msg .= ' (Forbidden - check VPN/IP whitelist)';
        }
        return [
            'success' => false,
            'error' => $error_msg,
            'data' => null,
            'http_code' => $httpcode,
            'response' => substr($response, 0, 500)
        ];
    }

    // Parse response
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'Invalid JSON response: ' . json_last_error_msg(),
            'data' => null,
            'http_code' => $httpcode,
            'response' => substr($response, 0, 500)
        ];
    }

    // Check if we got any records
    $records = $data['value'] ?? [];
    $completed_record = null;

    foreach ($records as $record) {
        if (isset($record['COURSE_STATE']) && $record['COURSE_STATE'] === 'Complete') {
            $completed_record = $record;
            break;
        }
    }

    return [
        'success' => true,
        'error' => null,
        'data' => $completed_record,
        'all_records' => $records,
        'http_code' => $httpcode
    ];
}

/**
 * Get the enrolment record from logs (needed for completion POST).
 * @param int|string $elm_course_id The ELM course identifier.
 * @param int $userid The Moodle user ID.
 * @return object|null The enrolment log record or null if not found.
 */
function get_enrolment_record($elm_course_id, $userid) {
    global $DB;

    $record = $DB->get_record_sql(
        "SELECT elm_enrolment_id, class_code, sha256hash, oprid, person_id, activity_id
         FROM {local_psaelmsync_logs}
         WHERE elm_course_id = :courseid
         AND user_id = :userid
         AND action IN ('Enrol', 'Manual Enrol')
         ORDER BY timestamp DESC
         LIMIT 1",
        ['courseid' => $elm_course_id, 'userid' => $userid]
    );

    return $record ?: null;
}

/**
 * Post completion to CData API.
 * @param object $user The Moodle user object.
 * @param object $course The Moodle course object.
 * @param object $enrolment_record The enrolment log record from local_psaelmsync_logs.
 * @return array Array with 'success', 'error', 'http_code' keys.
 */
function post_completion_to_cdata($user, $course, $enrolment_record) {
    global $DB;

    $apiurl = get_config('local_psaelmsync', 'completion_apiurl');
    $apitoken = get_config('local_psaelmsync', 'completion_apitoken');

    $elm_course_id = $course->idnumber;
    $elm_enrolment_id = $enrolment_record->elm_enrolment_id;
    $class_code = $enrolment_record->class_code;
    $sha256hash = $enrolment_record->sha256hash;

    $data = [
        'COURSE_COMPLETE_DATE' => date('Y-m-d'),
        'COURSE_STATE' => 'Complete',
        'ENROLMENT_ID' => (int) $elm_enrolment_id,
        'USER_STATE' => 'Active',
        'USER_EFFECTIVE_DATE' => '2017-02-14',
        'COURSE_IDENTIFIER' => (int) $elm_course_id,
        'COURSE_SHORTNAME' => $class_code,
        'EMAIL' => $user->email,
        'GUID' => $user->idnumber,
        'FIRST_NAME' => $user->firstname,
        'LAST_NAME' => $user->lastname,
        'OPRID' => $enrolment_record->oprid ?? '',
        'ACTIVITY_ID' => $enrolment_record->activity_id ?? 0,
        'PERSON_ID' => $enrolment_record->person_id ?? 0
    ];

    $jsonData = json_encode($data);

    $ch = curl_init($apiurl);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => [
            "x-cdata-authtoken: " . $apitoken,
            "Content-Type: application/json"
        ]
    ];
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $curlerror = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log the completion attempt
    $log = [
        'record_id' => time(),
        'record_date_created' => date('Y-m-d H:i:s'),
        'sha256hash' => $sha256hash,
        'course_id' => $course->id,
        'elm_course_id' => $elm_course_id,
        'class_code' => $class_code,
        'course_name' => $course->fullname,
        'user_id' => $user->id,
        'user_firstname' => $user->firstname,
        'user_lastname' => $user->lastname,
        'user_guid' => $user->idnumber,
        'user_email' => $user->email,
        'elm_enrolment_id' => $elm_enrolment_id,
        'oprid' => $enrolment_record->oprid ?? '',
        'person_id' => $enrolment_record->person_id ?? '',
        'activity_id' => $enrolment_record->activity_id ?? '',
        'action' => 'Manual Complete',
        'status' => 'Success',
        'timestamp' => time(),
        'notes' => ''
    ];

    if ($response === false || $httpcode >= 400) {
        $log['status'] = 'Error';
        $log['notes'] = 'cURL failed: ' . ($curlerror ?: 'HTTP ' . $httpcode) . ' Response: ' . substr($response, 0, 500);
        $DB->insert_record('local_psaelmsync_logs', (object)$log);
        return [
            'success' => false,
            'error' => $curlerror ?: 'HTTP ' . $httpcode,
            'response' => $response,
            'http_code' => $httpcode,
            'payload' => $data
        ];
    }

    $DB->insert_record('local_psaelmsync_logs', (object)$log);
    return [
        'success' => true,
        'response' => $response,
        'http_code' => $httpcode,
        'payload' => $data
    ];
}

// Handle completion POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_completion'])) {
    require_sesskey();

    $post_course_id = required_param('course_id', PARAM_INT);
    $post_user_id = required_param('user_id', PARAM_INT);

    $course = $DB->get_record('course', ['id' => $post_course_id], '*', MUST_EXIST);
    $user = $DB->get_record('user', ['id' => $post_user_id], '*', MUST_EXIST);

    // Get enrolment record
    $enrolment_record = get_enrolment_record($course->idnumber, $user->id);

    if (!$enrolment_record) {
        $feedback = "Cannot post completion: No enrolment record found in sync logs for this user/course combination.";
        $feedback_type = 'danger';
    } else {
        $result = post_completion_to_cdata($user, $course, $enrolment_record);

        if ($result['success']) {
            $feedback = "Completion posted successfully for " . s($user->firstname) . " " . s($user->lastname) . " in " . s($course->fullname) . ".";
            $feedback_type = 'success';
        } else {
            $feedback = "Failed to post completion. HTTP " . s($result['http_code']) . ": " . s($result['error']);
            if ($result['response']) {
                $feedback .= "<br><small>Response: " . htmlspecialchars(substr($result['response'], 0, 500)) . "</small>";
            }
            $feedback_type = 'danger';
        }
    }

    // Keep selections after POST
    $selected_course_id = $post_course_id;
    $selected_user_id = $post_user_id;
}

// Load selected course and user if set
$selected_course = null;
$selected_user = null;

if ($selected_course_id) {
    $selected_course = $DB->get_record('course', ['id' => $selected_course_id]);
}
if ($selected_user_id) {
    $selected_user = $DB->get_record('user', ['id' => $selected_user_id]);
}

echo $OUTPUT->header();
?>

<style>
.completion-wizard {
    max-width: 900px;
}
.wizard-step {
    background: #f8f9fa;
    border-radius: 0.5rem;
    padding: 1.5rem;
    margin-bottom: 1rem;
}
.wizard-step.disabled {
    opacity: 0.5;
    pointer-events: none;
}
.wizard-step h5 {
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.step-number {
    background: #6c757d;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    font-weight: bold;
}
.wizard-step.active .step-number {
    background: #007bff;
}
.wizard-step.completed .step-number {
    background: #28a745;
}
.search-results {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
    margin-top: 0.5rem;
}
.search-result-item {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #dee2e6;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.search-result-item:hover {
    background: #e9ecef;
}
.search-result-item:last-child {
    border-bottom: none;
}
.search-result-item.selected {
    background: #cce5ff;
}
.selected-item {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    border-radius: 0.25rem;
    padding: 0.75rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.completion-details {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-top: 1rem;
}
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.detail-panel h6 {
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 0.5rem;
    margin-bottom: 0.75rem;
}
.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 0.25rem 0;
    font-size: 0.9rem;
}
.detail-row .label {
    color: #6c757d;
}
.status-indicator {
    padding: 0.5rem 1rem;
    border-radius: 0.25rem;
    margin-top: 1rem;
}
.status-indicator.completed {
    background: #d4edda;
    border: 1px solid #c3e6cb;
}
.status-indicator.not-completed {
    background: #fff3cd;
    border: 1px solid #ffeeba;
}
.status-indicator.no-enrolment {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
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
</style>

<!-- Tabbed Navigation -->
<nav aria-label="PSA ELM Sync sections">
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
            <a class="nav-link" href="/local/psaelmsync/manual-intake.php">Manual Intake</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="/local/psaelmsync/manual-complete.php" aria-current="page">Manual Complete</a>
        </li>
    </ul>
</nav>

<?php if (!empty($feedback)): ?>
<div class="alert alert-<?php echo s($feedback_type); ?> alert-dismissible fade show" role="alert">
    <?php echo $feedback; // Contains intentional HTML; user data is escaped at construction ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<?php endif; ?>

<div class="completion-wizard">

    <!-- Step 1: Select Course -->
    <div class="wizard-step <?php echo $selected_course ? 'completed' : 'active'; ?>">
        <h5><span class="step-number">1</span> Select Course</h5>

        <?php if ($selected_course): ?>
            <div class="selected-item">
                <div>
                    <strong><?php echo htmlspecialchars($selected_course->fullname); ?></strong>
                    <br><small class="text-muted">
                        <?php echo htmlspecialchars($selected_course->shortname); ?>
                        | ELM ID: <?php echo htmlspecialchars($selected_course->idnumber); ?>
                    </small>
                </div>
                <a href="<?php echo $PAGE->url; ?>" class="btn btn-sm btn-outline-secondary">Change</a>
            </div>
        <?php else: ?>
            <p class="text-muted mb-2">Search for courses with completion reporting enabled.</p>
            <form method="get" action="<?php echo $PAGE->url; ?>" class="mb-2" role="search">
                <label for="course-search" class="sr-only">Search for courses</label>
                <div class="input-group">
                    <input type="text" id="course-search" name="course_search" class="form-control"
                           placeholder="Search by course name, shortname, or ELM ID..."
                           value="<?php echo s($course_search); ?>" autofocus>
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-primary">Search</button>
                    </div>
                </div>
            </form>

            <?php
            $courses = get_completion_courses($course_search);
            if (!empty($courses)):
            ?>
            <div class="search-results">
                <?php foreach ($courses as $course): ?>
                <a href="<?php echo $PAGE->url; ?>?course_id=<?php echo $course->id; ?>" class="search-result-item text-decoration-none text-dark">
                    <div>
                        <strong><?php echo htmlspecialchars($course->fullname); ?></strong>
                        <br><small class="text-muted">
                            <?php echo htmlspecialchars($course->shortname); ?>
                            | ELM ID: <?php echo htmlspecialchars($course->idnumber); ?>
                        </small>
                    </div>
                    <span class="badge badge-secondary">Select</span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php elseif (!empty($course_search)): ?>
            <div class="alert alert-info mb-0">No courses found matching "<?php echo s($course_search); ?>"</div>
            <?php else: ?>
            <div class="alert alert-secondary mb-0">Enter a search term to find courses, or leave blank to see all completion-enabled courses.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Step 2: Select User -->
    <div class="wizard-step <?php echo !$selected_course ? 'disabled' : ($selected_user ? 'completed' : 'active'); ?>">
        <h5><span class="step-number">2</span> Select Learner</h5>

        <?php if (!$selected_course): ?>
            <p class="text-muted mb-0">Select a course first.</p>
        <?php elseif ($selected_user): ?>
            <div class="selected-item">
                <div>
                    <strong><?php echo htmlspecialchars($selected_user->firstname . ' ' . $selected_user->lastname); ?></strong>
                    <br><small class="text-muted">
                        <?php echo htmlspecialchars($selected_user->email); ?>
                        | GUID: <?php echo htmlspecialchars($selected_user->idnumber); ?>
                    </small>
                </div>
                <a href="<?php echo $PAGE->url; ?>?course_id=<?php echo $selected_course->id; ?>" class="btn btn-sm btn-outline-secondary">Change</a>
            </div>
        <?php else: ?>
            <p class="text-muted mb-2">Search for users enrolled in this course.</p>
            <form method="get" action="<?php echo $PAGE->url; ?>" class="mb-2" role="search">
                <input type="hidden" name="course_id" value="<?php echo $selected_course->id; ?>">
                <label for="user-search" class="sr-only">Search for enrolled users</label>
                <div class="input-group">
                    <input type="text" id="user-search" name="user_search" class="form-control"
                           placeholder="Search by name, email, or GUID..."
                           value="<?php echo s($user_search); ?>" autofocus>
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-primary">Search</button>
                    </div>
                </div>
            </form>

            <?php
            $users = get_enrolled_users_search($selected_course->id, $user_search);
            if (!empty($users)):
            ?>
            <div class="search-results">
                <?php foreach ($users as $user): ?>
                <a href="<?php echo $PAGE->url; ?>?course_id=<?php echo $selected_course->id; ?>&user_id=<?php echo $user->id; ?>"
                   class="search-result-item text-decoration-none text-dark">
                    <div>
                        <strong><?php echo htmlspecialchars($user->firstname . ' ' . $user->lastname); ?></strong>
                        <br><small class="text-muted">
                            <?php echo htmlspecialchars($user->email); ?>
                            <?php if ($user->idnumber): ?>
                            | GUID: <?php echo htmlspecialchars($user->idnumber); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <span class="badge badge-secondary">Select</span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php elseif (!empty($user_search)): ?>
            <div class="alert alert-info mb-0">No enrolled users found matching "<?php echo s($user_search); ?>"</div>
            <?php else: ?>
            <div class="alert alert-secondary mb-0">Enter a search term to find enrolled users, or leave blank to see all enrolled users (first 50).</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Step 3: Review and Complete -->
    <div class="wizard-step <?php echo (!$selected_course || !$selected_user) ? 'disabled' : 'active'; ?>">
        <h5><span class="step-number">3</span> Review & Post Completion</h5>

        <?php if (!$selected_course || !$selected_user): ?>
            <p class="text-muted mb-0">Select a course and learner first.</p>
        <?php else:
            // Get all the details we need
            $moodle_completion = check_moodle_completion($selected_course->id, $selected_user->id);
            $completion_log = check_completion_logged($selected_course->idnumber, $selected_user->id);
            $enrolment_record = get_enrolment_record($selected_course->idnumber, $selected_user->id);

            // Query CData API for actual completion status (only if user has GUID)
            $cdata_status = null;
            if (!empty($selected_user->idnumber) && !empty($selected_course->idnumber)) {
                $cdata_status = check_cdata_completion($selected_user->idnumber, $selected_course->idnumber);
            }
        ?>
            <div class="completion-details">
                <div class="detail-grid">
                    <div class="detail-panel">
                        <h6>Learner Details</h6>
                        <div class="detail-row">
                            <span class="label">Name:</span>
                            <span><?php echo htmlspecialchars($selected_user->firstname . ' ' . $selected_user->lastname); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Email:</span>
                            <span><?php echo htmlspecialchars($selected_user->email); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">GUID:</span>
                            <span><code><?php echo htmlspecialchars($selected_user->idnumber ?: 'Not set'); ?></code></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Moodle ID:</span>
                            <span><a href="/user/view.php?id=<?php echo $selected_user->id; ?>" target="_blank" ><?php echo $selected_user->id; ?><span class="sr-only"> (opens in new window)</span></a></span>
                        </div>
                    </div>
                    <div class="detail-panel">
                        <h6>Course Details</h6>
                        <div class="detail-row">
                            <span class="label">Course:</span>
                            <span><?php echo htmlspecialchars($selected_course->fullname); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Shortname:</span>
                            <span><?php echo htmlspecialchars($selected_course->shortname); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">ELM Course ID:</span>
                            <span><code><?php echo htmlspecialchars($selected_course->idnumber ?: 'Not set'); ?></code></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Moodle ID:</span>
                            <span><a href="/course/view.php?id=<?php echo $selected_course->id; ?>" target="_blank" ><?php echo $selected_course->id; ?><span class="sr-only"> (opens in new window)</span></a></span>
                        </div>
                    </div>
                </div>

                <hr>

                <div class="detail-grid">
                    <div class="detail-panel">
                        <h6>Moodle Completion Status</h6>
                        <?php if ($moodle_completion['completed']): ?>
                            <div class="text-success">
                                <strong>Completed</strong>
                                <br><small>on <?php echo date('Y-m-d H:i', $moodle_completion['timecompleted']); ?></small>
                            </div>
                        <?php else: ?>
                            <div class="text-warning">
                                <strong>Not completed in Moodle</strong>
                                <br><small>User has not met completion criteria</small>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="detail-panel">
                        <h6>CData API Status</h6>
                        <?php if (!$cdata_status): ?>
                            <div class="text-muted">
                                <strong>Cannot check</strong>
                                <br><small>User GUID or Course ID not set</small>
                            </div>
                        <?php elseif (!$cdata_status['success']): ?>
                            <div class="text-danger">
                                <strong>API Error</strong>
                                <br><small><?php echo htmlspecialchars($cdata_status['error']); ?></small>
                                <?php if (!empty($cdata_status['response'])): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($cdata_status['response'], 0, 200)); ?></small>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($cdata_status['data']): ?>
                            <div class="text-success">
                                <strong>Completed in CData</strong>
                                <?php if (!empty($cdata_status['data']['COURSE_COMPLETE_DATE'])): ?>
                                <br><small>Date: <?php echo htmlspecialchars($cdata_status['data']['COURSE_COMPLETE_DATE']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($cdata_status['data']['COURSE_STATE'])): ?>
                                <br><small>State: <?php echo htmlspecialchars($cdata_status['data']['COURSE_STATE']); ?></small>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-warning">
                                <strong>Not completed in CData</strong>
                                <br><small>No completion record found</small>
                            </div>
                        <?php endif; ?>

                        <?php if ($completion_log): ?>
                        <div class="mt-2 pt-2 border-top">
                            <small class="text-muted">Local log:</small>
                            <br><small>
                                <?php echo htmlspecialchars($completion_log->action); ?>
                                (<?php echo $completion_log->status === 'Success' ? '<span class="text-success">Success</span>' : '<span class="text-danger">Error</span>'; ?>)
                                on <?php echo date('Y-m-d H:i', $completion_log->timestamp); ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($enrolment_record): ?>
                <div class="detail-panel mt-3">
                    <h6>Enrolment Record (from sync logs)</h6>
                    <div class="detail-row">
                        <span class="label">ELM Enrolment ID:</span>
                        <span><code><?php echo htmlspecialchars($enrolment_record->elm_enrolment_id); ?></code></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Class Code:</span>
                        <span><?php echo htmlspecialchars($enrolment_record->class_code); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Status and Action -->
                <?php if (!$enrolment_record): ?>
                    <div class="status-indicator no-enrolment">
                        <strong>Cannot post completion</strong>
                        <p class="mb-0 mt-1">No enrolment record found in sync logs. This user may have been enrolled before the sync system was active, or through a different method.</p>
                    </div>
                <?php elseif (!$selected_user->idnumber): ?>
                    <div class="status-indicator no-enrolment">
                        <strong>Cannot post completion</strong>
                        <p class="mb-0 mt-1">User has no GUID set. This is required for CData integration.</p>
                    </div>
                <?php elseif ($cdata_status && !$cdata_status['success']): ?>
                    <div class="status-indicator no-enrolment">
                        <strong>CData API unavailable</strong>
                        <p class="mb-2 mt-1">Cannot verify completion status: <?php echo htmlspecialchars($cdata_status['error']); ?></p>
                        <p class="mb-2"><small>You can still attempt to post, but the API may reject the request.</small></p>
                        <form method="post" action="<?php echo $PAGE->url; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $selected_course->id; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $selected_user->id; ?>">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <button type="submit" name="post_completion" class="btn btn-warning"
                                    onclick="return confirm('The CData API is currently unavailable. The POST may fail. Continue anyway?');">
                                Attempt to Post Completion
                            </button>
                        </form>
                    </div>
                <?php elseif ($cdata_status && $cdata_status['data']): ?>
                    <div class="status-indicator completed">
                        <strong>Already completed in CData</strong>
                        <p class="mb-0 mt-1">CData shows this user already completed this course<?php
                            if (!empty($cdata_status['data']['COURSE_COMPLETE_DATE'])) {
                                echo ' on ' . htmlspecialchars($cdata_status['data']['COURSE_COMPLETE_DATE']);
                            }
                        ?>.</p>
                        <form method="post" action="<?php echo $PAGE->url; ?>" class="mt-2">
                            <input type="hidden" name="course_id" value="<?php echo $selected_course->id; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $selected_user->id; ?>">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <button type="submit" name="post_completion" class="btn btn-warning btn-sm"
                                    onclick="return confirm('CData already shows this as completed. Are you sure you want to send it again?');">
                                Re-send Completion
                            </button>
                        </form>
                    </div>
                <?php elseif ($completion_log && $completion_log->status === 'Success'): ?>
                    <div class="status-indicator completed">
                        <strong>Previously sent (not in CData)</strong>
                        <p class="mb-0 mt-1">Local logs show this was posted on <?php echo date('Y-m-d H:i', $completion_log->timestamp); ?>, but CData doesn't have a completion record. It may not have been processed.</p>
                        <form method="post" action="<?php echo $PAGE->url; ?>" class="mt-2">
                            <input type="hidden" name="course_id" value="<?php echo $selected_course->id; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $selected_user->id; ?>">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <button type="submit" name="post_completion" class="btn btn-warning btn-sm"
                                    onclick="return confirm('Re-send this completion to CData?');">
                                Re-send Completion
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="status-indicator not-completed">
                        <strong>Ready to post completion</strong>
                        <?php if (!$moodle_completion['completed']): ?>
                        <p class="mb-2 mt-1 text-warning"><strong>Warning:</strong> User has not completed this course in Moodle. Posting will mark them as complete in ELM.</p>
                        <?php else: ?>
                        <p class="mb-2 mt-1">User completed in Moodle but completion has not been sent to CData.</p>
                        <?php endif; ?>

                        <form method="post" action="<?php echo $PAGE->url; ?>">
                            <input type="hidden" name="course_id" value="<?php echo $selected_course->id; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $selected_user->id; ?>">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <button type="submit" name="post_completion" class="btn btn-success">
                                Post Completion to CData
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

            </div>

            <div class="mt-3">
                <a href="/local/psaelmsync/dashboard.php?search=<?php echo urlencode($selected_user->idnumber); ?>"
                   class="btn btn-sm btn-outline-secondary" target="_blank" >View all sync logs for this user<span class="sr-only"> (opens in new window)</span></a>
                <a href="/report/completion/index.php?course=<?php echo $selected_course->id; ?>"
                   class="btn btn-sm btn-outline-secondary" target="_blank" >Course completion report<span class="sr-only"> (opens in new window)</span></a>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php
echo $OUTPUT->footer();
