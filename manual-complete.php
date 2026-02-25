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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Manual completion - Progressive disclosure UI for manually
 * posting course completions to CData.
 *
 * Flow:
 * 1. Search/select a course (filtered to completion_opt_in courses)
 * 2. Search/select a user enrolled in that course
 * 3. View completion status and details
 * 4. Post completion to CData if not already completed
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

require_login();

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/manual-complete.php');
$PAGE->set_context($context);
$PAGE->set_title(
    get_string('pluginname', 'local_psaelmsync') . ' - Manual Completion'
);
$PAGE->set_heading('Manual Completion');

// Get config.
$completionapiurl = get_config('local_psaelmsync', 'completion_apiurl');
$completionapitoken = get_config('local_psaelmsync', 'completion_apitoken');

// Get parameters for progressive disclosure.
$coursesearch = optional_param('course_search', '', PARAM_TEXT);
$selectedcourseid = optional_param('course_id', 0, PARAM_INT);
$usersearch = optional_param('user_search', '', PARAM_TEXT);
$selecteduserid = optional_param('user_id', 0, PARAM_INT);

// Feedback messages.
$feedback = '';
$feedbacktype = 'info';

/**
 * Get courses that have completion_opt_in enabled.
 *
 * @param string $search Optional search string to filter courses.
 * @return array Array of course records.
 */
function get_completion_courses($search = '') {
    global $DB;

    // Get courses with completion_opt_in custom field set to 1.
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
 *
 * @param int $courseid The Moodle course ID.
 * @param string $search Optional search string to filter users.
 * @return array Array of user records.
 */
function get_enrolled_users_search($courseid, $search = '') {
    global $DB;

    $context = \context_course::instance($courseid);
    $enrolledusers = get_enrolled_users(
        $context,
        '',
        0,
        'u.id, u.firstname, u.lastname, u.email, u.idnumber'
    );

    if (empty($search)) {
        return array_slice($enrolledusers, 0, 50, true);
    }

    $search = strtolower($search);
    $filtered = array_filter($enrolledusers, function ($user) use ($search) {
        return strpos(strtolower($user->firstname), $search) !== false
            || strpos(strtolower($user->lastname), $search) !== false
            || strpos(strtolower($user->email), $search) !== false
            || strpos(strtolower($user->idnumber), $search) !== false;
    });

    return array_slice($filtered, 0, 50, true);
}

/**
 * Check if user has completed the course in Moodle.
 *
 * @param int $courseid The Moodle course ID.
 * @param int $userid The Moodle user ID.
 * @return array Completion status with 'completed' key and optional 'timecompleted'.
 */
function check_moodle_completion($courseid, $userid) {
    global $DB;

    $completion = $DB->get_record('course_completions', [
        'course' => $courseid,
        'userid' => $userid,
    ]);

    if ($completion && $completion->timecompleted) {
        return [
            'completed' => true,
            'timecompleted' => $completion->timecompleted,
        ];
    }

    return ['completed' => false];
}

/**
 * Check if completion has been logged/sent to CData (local logs).
 *
 * @param int|string $elmcourseid The ELM course identifier.
 * @param int $userid The Moodle user ID.
 * @return object|null The log record or null if not found.
 */
function check_completion_logged($elmcourseid, $userid) {
    global $DB;

    $log = $DB->get_record_sql(
        "SELECT * FROM {local_psaelmsync_logs}
         WHERE elm_course_id = :courseid
         AND user_id = :userid
         AND action IN ('Complete', 'Manual Complete')
         ORDER BY timestamp DESC
         LIMIT 1",
        ['courseid' => $elmcourseid, 'userid' => $userid]
    );

    return $log ?: null;
}

/**
 * Query CData API to check completion status.
 *
 * @param string $guid The user GUID.
 * @param int|string $elmcourseid The ELM course identifier.
 * @return array Array with 'success', 'error', 'data', 'http_code' keys.
 */
function check_cdata_completion($guid, $elmcourseid) {
    $apiurl = get_config('local_psaelmsync', 'completion_apiurl');
    $apitoken = get_config('local_psaelmsync', 'completion_apitoken');

    if (empty($apiurl) || empty($apitoken)) {
        return [
            'success' => false,
            'error' => 'Completion API URL or token not configured',
            'data' => null,
            'http_code' => 0,
        ];
    }

    // Query for this specific user/course combination.
    $filter = "GUID+eq+%27" . urlencode($guid)
        . "%27+and+COURSE_IDENTIFIER+eq+" . urlencode($elmcourseid);
    $queryurl = $apiurl . "?%24filter=" . $filter;

    $ch = curl_init($queryurl);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "x-cdata-authtoken: " . $apitoken,
            "Content-Type: application/json",
        ],
        CURLOPT_TIMEOUT => 10,
    ];
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $curlerror = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Handle cURL errors.
    if ($response === false) {
        return [
            'success' => false,
            'error' => 'cURL error: ' . $curlerror,
            'data' => null,
            'http_code' => 0,
        ];
    }

    // Handle HTTP errors.
    if ($httpcode >= 400) {
        $errormsg = "HTTP {$httpcode}";
        if ($httpcode == 502) {
            $errormsg .= ' (Bad Gateway - CData server may be down)';
        } else if ($httpcode == 401) {
            $errormsg .= ' (Unauthorized - check API token)';
        } else if ($httpcode == 403) {
            $errormsg .= ' (Forbidden - check VPN/IP whitelist)';
        }
        return [
            'success' => false,
            'error' => $errormsg,
            'data' => null,
            'http_code' => $httpcode,
            'response' => substr($response, 0, 500),
        ];
    }

    // Parse response.
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'Invalid JSON response: ' . json_last_error_msg(),
            'data' => null,
            'http_code' => $httpcode,
            'response' => substr($response, 0, 500),
        ];
    }

    // Check if we got any records.
    $records = $data['value'] ?? [];
    $completedrecord = null;

    foreach ($records as $record) {
        if (
            isset($record['COURSE_STATE'])
            && $record['COURSE_STATE'] === 'Complete'
        ) {
            $completedrecord = $record;
            break;
        }
    }

    return [
        'success' => true,
        'error' => null,
        'data' => $completedrecord,
        'all_records' => $records,
        'http_code' => $httpcode,
    ];
}

/**
 * Get the enrolment record from logs (needed for completion POST).
 *
 * @param int|string $elmcourseid The ELM course identifier.
 * @param int $userid The Moodle user ID.
 * @return object|null The enrolment log record or null if not found.
 */
function get_enrolment_record($elmcourseid, $userid) {
    global $DB;

    $record = $DB->get_record_sql(
        "SELECT elm_enrolment_id, class_code,
                sha256hash, oprid, person_id, activity_id
         FROM {local_psaelmsync_logs}
         WHERE elm_course_id = :courseid
         AND user_id = :userid
         AND action IN ('Enrol', 'Manual Enrol')
         ORDER BY timestamp DESC
         LIMIT 1",
        ['courseid' => $elmcourseid, 'userid' => $userid]
    );

    return $record ?: null;
}

/**
 * Post completion to CData API.
 *
 * @param object $user The Moodle user object.
 * @param object $course The Moodle course object.
 * @param object $enrolmentrecord The enrolment log record.
 * @return array Array with 'success', 'error', 'http_code' keys.
 */
function post_completion_to_cdata($user, $course, $enrolmentrecord) {
    global $DB;

    $apiurl = get_config('local_psaelmsync', 'completion_apiurl');
    $apitoken = get_config('local_psaelmsync', 'completion_apitoken');

    $elmcourseid = $course->idnumber;
    $elmenrolmentid = $enrolmentrecord->elm_enrolment_id;
    $classcode = $enrolmentrecord->class_code;
    $sha256hash = $enrolmentrecord->sha256hash;

    $data = [
        'COURSE_COMPLETE_DATE' => date('Y-m-d'),
        'COURSE_STATE' => 'Complete',
        'ENROLMENT_ID' => (int) $elmenrolmentid,
        'USER_STATE' => 'Active',
        'USER_EFFECTIVE_DATE' => '2017-02-14',
        'COURSE_IDENTIFIER' => (int) $elmcourseid,
        'COURSE_SHORTNAME' => $classcode,
        'EMAIL' => $user->email,
        'GUID' => $user->idnumber,
        'FIRST_NAME' => $user->firstname,
        'LAST_NAME' => $user->lastname,
        'OPRID' => $enrolmentrecord->oprid ?? '',
        'ACTIVITY_ID' => $enrolmentrecord->activity_id ?? 0,
        'PERSON_ID' => $enrolmentrecord->person_id ?? 0,
    ];

    $jsondata = json_encode($data);

    $ch = curl_init($apiurl);
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

    // Log the completion attempt.
    $log = [
        'record_id' => time(),
        'record_date_created' => date('Y-m-d H:i:s'),
        'sha256hash' => $sha256hash,
        'course_id' => $course->id,
        'elm_course_id' => $elmcourseid,
        'class_code' => $classcode,
        'course_name' => $course->fullname,
        'user_id' => $user->id,
        'user_firstname' => $user->firstname,
        'user_lastname' => $user->lastname,
        'user_guid' => $user->idnumber,
        'user_email' => $user->email,
        'elm_enrolment_id' => $elmenrolmentid,
        'oprid' => $enrolmentrecord->oprid ?? '',
        'person_id' => $enrolmentrecord->person_id ?? '',
        'activity_id' => $enrolmentrecord->activity_id ?? '',
        'action' => 'Manual Complete',
        'status' => 'Success',
        'timestamp' => time(),
        'notes' => '',
    ];

    if ($response === false || $httpcode >= 400) {
        $log['status'] = 'Error';
        $log['notes'] = 'cURL failed: '
            . ($curlerror ?: 'HTTP ' . $httpcode)
            . ' Response: ' . substr($response, 0, 500);
        $DB->insert_record('local_psaelmsync_logs', (object)$log);
        return [
            'success' => false,
            'error' => $curlerror ?: 'HTTP ' . $httpcode,
            'response' => $response,
            'http_code' => $httpcode,
            'payload' => $data,
        ];
    }

    $DB->insert_record('local_psaelmsync_logs', (object)$log);
    return [
        'success' => true,
        'response' => $response,
        'http_code' => $httpcode,
        'payload' => $data,
    ];
}

// Handle completion POST.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['post_completion'])
) {
    require_sesskey();

    $postcourseid = required_param('course_id', PARAM_INT);
    $postuserid = required_param('user_id', PARAM_INT);

    $course = $DB->get_record(
        'course',
        ['id' => $postcourseid],
        '*',
        MUST_EXIST
    );
    $user = $DB->get_record(
        'user',
        ['id' => $postuserid],
        '*',
        MUST_EXIST
    );

    // Get enrolment record.
    $enrolmentrecord = get_enrolment_record(
        $course->idnumber,
        $user->id
    );

    if (!$enrolmentrecord) {
        $feedback = "Cannot post completion: No enrolment record found"
            . " in sync logs for this user/course combination.";
        $feedbacktype = 'danger';
    } else {
        $result = post_completion_to_cdata(
            $user,
            $course,
            $enrolmentrecord
        );

        if ($result['success']) {
            $feedback = "Completion posted successfully for "
                . s($user->firstname) . " " . s($user->lastname)
                . " in " . s($course->fullname) . ".";
            $feedbacktype = 'success';
        } else {
            $feedback = "Failed to post completion. HTTP "
                . s($result['http_code']) . ": "
                . s($result['error']);
            if ($result['response']) {
                $feedback .= "<br><small>Response: "
                    . htmlspecialchars(
                        substr($result['response'], 0, 500)
                    )
                    . "</small>";
            }
            $feedbacktype = 'danger';
        }
    }

    // Keep selections after POST.
    $selectedcourseid = $postcourseid;
    $selecteduserid = $postuserid;
}

// Load selected course and user if set.
$selectedcourse = null;
$selecteduser = null;

if ($selectedcourseid) {
    $selectedcourse = $DB->get_record(
        'course',
        ['id' => $selectedcourseid]
    );
}
if ($selecteduserid) {
    $selecteduser = $DB->get_record(
        'user',
        ['id' => $selecteduserid]
    );
}

echo $OUTPUT->header();
// phpcs:disable Generic.WhiteSpace.ScopeIndent
// phpcs:disable Squiz.WhiteSpace.ScopeClosingBrace
// phpcs:disable Squiz.ControlStructures.ElseIfDeclaration.NotAllowed
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
            <a class="nav-link"
               href="/local/psaelmsync/manual-intake.php">
                Manual Intake</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active"
               href="/local/psaelmsync/manual-complete.php"
               aria-current="page">
                Manual Complete</a>
        </li>
    </ul>
</nav>

<?php if (!empty($feedback)) : ?>
<div class="alert alert-<?php echo s($feedbacktype); ?>"
     role="alert">
    <?php
    // Contains intentional HTML; user data is escaped at construction.
    echo $feedback;
    ?>
    <button type="button" class="close"
            data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<?php endif; ?>

<div class="completion-wizard">

    <!-- Step 1: Select Course -->
    <div class="wizard-step <?php
        echo $selectedcourse ? 'completed' : 'active'; ?>">
        <h5><span class="step-number">1</span> Select Course</h5>

        <?php if ($selectedcourse) : ?>
            <div class="selected-item">
                <div>
                    <strong><?php
                        echo htmlspecialchars($selectedcourse->fullname);
                    ?></strong>
                    <br><small class="text-muted">
                        <?php
                        echo htmlspecialchars(
                            $selectedcourse->shortname
                        );
                        ?>
                        | ELM ID: <?php
                        echo htmlspecialchars(
                            $selectedcourse->idnumber
                        );
                        ?>
                    </small>
                </div>
                <a href="<?php echo $PAGE->url; ?>"
                   class="btn btn-sm btn-outline-secondary">
                    Change</a>
            </div>
        <?php else : ?>
            <p class="text-muted mb-2">
                Search for courses with completion reporting enabled.
            </p>
            <form method="get"
                  action="<?php echo $PAGE->url; ?>"
                  class="mb-2" role="search">
                <label for="course-search" class="sr-only">
                    Search for courses</label>
                <div class="input-group">
                    <input type="text" id="course-search"
                           name="course_search"
                           class="form-control"
                           placeholder="Search by name, shortname, or ELM ID..."
                           value="<?php echo s($coursesearch); ?>"
                           autofocus>
                    <div class="input-group-append">
                        <button type="submit"
                                class="btn btn-primary">
                            Search</button>
                    </div>
                </div>
            </form>

            <?php
            $courses = get_completion_courses($coursesearch);
            if (!empty($courses)) :
            ?>
            <div class="search-results">
                <?php foreach ($courses as $course) : ?>
                <a href="<?php echo $PAGE->url;
                    ?>?course_id=<?php echo $course->id; ?>"
                   class="search-result-item text-decoration-none text-dark">
                    <div>
                        <strong><?php
                            echo htmlspecialchars($course->fullname);
                        ?></strong>
                        <br><small class="text-muted">
                            <?php
                            echo htmlspecialchars($course->shortname);
                            ?>
                            | ELM ID: <?php
                            echo htmlspecialchars($course->idnumber);
                            ?>
                        </small>
                    </div>
                    <span class="badge badge-secondary">Select</span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php elseif (!empty($coursesearch)) : ?>
            <div class="alert alert-info mb-0">
                No courses found matching
                "<?php echo s($coursesearch); ?>"
            </div>
            <?php else : ?>
            <div class="alert alert-secondary mb-0">
                Enter a search term to find courses, or leave blank
                to see all completion-enabled courses.
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Step 2: Select User -->
    <div class="wizard-step <?php
        if (!$selectedcourse) {
            echo 'disabled';
        } else if ($selecteduser) {
            echo 'completed';
        } else {
            echo 'active';
        }
    ?>">
        <h5><span class="step-number">2</span> Select Learner</h5>

        <?php if (!$selectedcourse) : ?>
            <p class="text-muted mb-0">Select a course first.</p>
        <?php elseif ($selecteduser) : ?>
            <div class="selected-item">
                <div>
                    <strong><?php
                        echo htmlspecialchars(
                            $selecteduser->firstname
                            . ' ' . $selecteduser->lastname
                        );
                    ?></strong>
                    <br><small class="text-muted">
                        <?php
                        echo htmlspecialchars($selecteduser->email);
                        ?>
                        | GUID: <?php
                        echo htmlspecialchars(
                            $selecteduser->idnumber
                        );
                        ?>
                    </small>
                </div>
                <a href="<?php echo $PAGE->url;
                    ?>?course_id=<?php
                    echo $selectedcourse->id; ?>"
                   class="btn btn-sm btn-outline-secondary">
                    Change</a>
            </div>
        <?php else : ?>
            <p class="text-muted mb-2">
                Search for users enrolled in this course.
            </p>
            <form method="get"
                  action="<?php echo $PAGE->url; ?>"
                  class="mb-2" role="search">
                <input type="hidden" name="course_id"
                       value="<?php echo $selectedcourse->id; ?>">
                <label for="user-search" class="sr-only">
                    Search for enrolled users</label>
                <div class="input-group">
                    <input type="text" id="user-search"
                           name="user_search"
                           class="form-control"
                           placeholder="Search by name, email, or GUID..."
                           value="<?php echo s($usersearch); ?>"
                           autofocus>
                    <div class="input-group-append">
                        <button type="submit"
                                class="btn btn-primary">
                            Search</button>
                    </div>
                </div>
            </form>

            <?php
            $users = get_enrolled_users_search(
                $selectedcourse->id,
                $usersearch
            );
            if (!empty($users)) :
            ?>
            <div class="search-results">
                <?php foreach ($users as $user) : ?>
                <a href="<?php echo $PAGE->url;
                    ?>?course_id=<?php echo $selectedcourse->id;
                    ?>&amp;user_id=<?php echo $user->id; ?>"
                   class="search-result-item text-decoration-none text-dark">
                    <div>
                        <strong><?php
                            echo htmlspecialchars(
                                $user->firstname
                                . ' ' . $user->lastname
                            );
                        ?></strong>
                        <br><small class="text-muted">
                            <?php
                            echo htmlspecialchars($user->email);
                            ?>
                            <?php if ($user->idnumber) : ?>
                            | GUID: <?php
                            echo htmlspecialchars($user->idnumber);
                            ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <span class="badge badge-secondary">Select</span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php elseif (!empty($usersearch)) : ?>
            <div class="alert alert-info mb-0">
                No enrolled users found matching
                "<?php echo s($usersearch); ?>"
            </div>
            <?php else : ?>
            <div class="alert alert-secondary mb-0">
                Enter a search term to find enrolled users,
                or leave blank to see all enrolled users (first 50).
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Step 3: Review and Complete -->
    <div class="wizard-step <?php
        echo (!$selectedcourse || !$selecteduser)
            ? 'disabled' : 'active'; ?>">
        <h5>
            <span class="step-number">3</span>
            Review &amp; Post Completion
        </h5>

        <?php if (!$selectedcourse || !$selecteduser) : ?>
            <p class="text-muted mb-0">
                Select a course and learner first.
            </p>
        <?php else :
            // Get all the details we need.
            $moodlecompletion = check_moodle_completion(
                $selectedcourse->id,
                $selecteduser->id
            );
            $completionlog = check_completion_logged(
                $selectedcourse->idnumber,
                $selecteduser->id
            );
            $enrolmentrecord = get_enrolment_record(
                $selectedcourse->idnumber,
                $selecteduser->id
            );

            // Query CData API for actual completion status.
            $cdatastatus = null;
            if (
                !empty($selecteduser->idnumber)
                && !empty($selectedcourse->idnumber)
            ) {
                $cdatastatus = check_cdata_completion(
                    $selecteduser->idnumber,
                    $selectedcourse->idnumber
                );
            }
        ?>
            <div class="completion-details">
                <div class="detail-grid">
                    <div class="detail-panel">
                        <h6>Learner Details</h6>
                        <div class="detail-row">
                            <span class="label">Name:</span>
                            <span><?php
                                echo htmlspecialchars(
                                    $selecteduser->firstname
                                    . ' '
                                    . $selecteduser->lastname
                                );
                            ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Email:</span>
                            <span><?php
                                echo htmlspecialchars(
                                    $selecteduser->email
                                );
                            ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">GUID:</span>
                            <span><code><?php
                                echo htmlspecialchars(
                                    $selecteduser->idnumber
                                    ?: 'Not set'
                                );
                            ?></code></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Moodle ID:</span>
                            <span>
                                <a href="/user/view.php?id=<?php
                                    echo $selecteduser->id;
                                ?>" target="_blank"><?php
                                    echo $selecteduser->id;
                                ?><span class="sr-only">
                                    (opens in new window)
                                </span></a>
                            </span>
                        </div>
                    </div>
                    <div class="detail-panel">
                        <h6>Course Details</h6>
                        <div class="detail-row">
                            <span class="label">Course:</span>
                            <span><?php
                                echo htmlspecialchars(
                                    $selectedcourse->fullname
                                );
                            ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Shortname:</span>
                            <span><?php
                                echo htmlspecialchars(
                                    $selectedcourse->shortname
                                );
                            ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">ELM Course ID:</span>
                            <span><code><?php
                                echo htmlspecialchars(
                                    $selectedcourse->idnumber
                                    ?: 'Not set'
                                );
                            ?></code></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Moodle ID:</span>
                            <span>
                                <a href="/course/view.php?id=<?php
                                    echo $selectedcourse->id;
                                ?>" target="_blank"><?php
                                    echo $selectedcourse->id;
                                ?><span class="sr-only">
                                    (opens in new window)
                                </span></a>
                            </span>
                        </div>
                    </div>
                </div>

                <hr>

                <div class="detail-grid">
                    <div class="detail-panel">
                        <h6>Moodle Completion Status</h6>
                        <?php if ($moodlecompletion['completed']) : ?>
                            <div class="text-success">
                                <strong>Completed</strong>
                                <br><small>on <?php
                                    echo date(
                                        'Y-m-d H:i',
                                        $moodlecompletion['timecompleted']
                                    );
                                ?></small>
                            </div>
                        <?php else : ?>
                            <div class="text-warning">
                                <strong>
                                    Not completed in Moodle
                                </strong>
                                <br><small>
                                    User has not met completion criteria
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="detail-panel">
                        <h6>CData API Status</h6>
                        <?php if (!$cdatastatus) : ?>
                            <div class="text-muted">
                                <strong>Cannot check</strong>
                                <br><small>
                                    User GUID or Course ID not set
                                </small>
                            </div>
                        <?php elseif (!$cdatastatus['success']) : ?>
                            <div class="text-danger">
                                <strong>API Error</strong>
                                <br><small><?php
                                    echo htmlspecialchars(
                                        $cdatastatus['error']
                                    );
                                ?></small>
                                <?php if (!empty($cdatastatus['response'])) : ?>
                                <br><small class="text-muted"><?php
                                    echo htmlspecialchars(
                                        substr(
                                            $cdatastatus['response'],
                                            0,
                                            200
                                        )
                                    );
                                ?></small>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($cdatastatus['data']) : ?>
                            <div class="text-success">
                                <strong>Completed in CData</strong>
                                <?php
                                $cdate = $cdatastatus['data']['COURSE_COMPLETE_DATE'] ?? '';
                                if (!empty($cdate)) : ?>
                                <br><small>Date: <?php
                                    echo htmlspecialchars($cdate);
                                ?></small>
                                <?php endif; ?>
                                <?php
                                $cstate = $cdatastatus['data']['COURSE_STATE'] ?? '';
                                if (!empty($cstate)) : ?>
                                <br><small>State: <?php
                                    echo htmlspecialchars($cstate);
                                ?></small>
                                <?php endif; ?>
                            </div>
                        <?php else : ?>
                            <div class="text-warning">
                                <strong>
                                    Not completed in CData
                                </strong>
                                <br><small>
                                    No completion record found
                                </small>
                            </div>
                        <?php endif; ?>

                        <?php if ($completionlog) : ?>
                        <div class="mt-2 pt-2 border-top">
                            <small class="text-muted">
                                Local log:</small>
                            <br><small>
                                <?php
                                echo htmlspecialchars(
                                    $completionlog->action
                                );
                                ?>
                                (<?php
                                if ($completionlog->status === 'Success') {
                                    echo '<span class="text-success">'
                                        . 'Success</span>';
                                } else {
                                    echo '<span class="text-danger">'
                                        . 'Error</span>';
                                }
                                ?>)
                                on <?php
                                echo date(
                                    'Y-m-d H:i',
                                    $completionlog->timestamp
                                );
                                ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($enrolmentrecord) : ?>
                <div class="detail-panel mt-3">
                    <h6>Enrolment Record (from sync logs)</h6>
                    <div class="detail-row">
                        <span class="label">
                            ELM Enrolment ID:</span>
                        <span><code><?php
                            echo htmlspecialchars(
                                $enrolmentrecord->elm_enrolment_id
                            );
                        ?></code></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Class Code:</span>
                        <span><?php
                            echo htmlspecialchars(
                                $enrolmentrecord->class_code
                            );
                        ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Status and Action -->
                <?php if (!$enrolmentrecord) : ?>
                    <div class="status-indicator no-enrolment">
                        <strong>Cannot post completion</strong>
                        <p class="mb-0 mt-1">
                            No enrolment record found in sync logs.
                            This user may have been enrolled before the
                            sync system was active, or through a
                            different method.
                        </p>
                    </div>
                <?php elseif (!$selecteduser->idnumber) : ?>
                    <div class="status-indicator no-enrolment">
                        <strong>Cannot post completion</strong>
                        <p class="mb-0 mt-1">
                            User has no GUID set. This is required
                            for CData integration.
                        </p>
                    </div>
                <?php elseif ($cdatastatus && !$cdatastatus['success']) : ?>
                    <div class="status-indicator no-enrolment">
                        <strong>CData API unavailable</strong>
                        <p class="mb-2 mt-1">
                            Cannot verify completion status:
                            <?php
                            echo htmlspecialchars(
                                $cdatastatus['error']
                            );
                            ?>
                        </p>
                        <p class="mb-2"><small>
                            You can still attempt to post, but the
                            API may reject the request.
                        </small></p>
                        <form method="post"
                              action="<?php echo $PAGE->url; ?>">
                            <input type="hidden" name="course_id"
                                   value="<?php
                                   echo $selectedcourse->id; ?>">
                            <input type="hidden" name="user_id"
                                   value="<?php
                                   echo $selecteduser->id; ?>">
                            <input type="hidden" name="sesskey"
                                   value="<?php echo sesskey(); ?>">
                            <button type="submit"
                                    name="post_completion"
                                    class="btn btn-warning"
                                    onclick="return confirm(
                                        'The CData API is currently unavailable. The POST may fail. Continue anyway?'
                                    );">
                                Attempt to Post Completion
                            </button>
                        </form>
                    </div>
                <?php elseif ($cdatastatus && $cdatastatus['data']) : ?>
                    <div class="status-indicator completed">
                        <strong>
                            Already completed in CData
                        </strong>
                        <p class="mb-0 mt-1">
                            CData shows this user already completed
                            this course<?php
                            $completedate = $cdatastatus['data']['COURSE_COMPLETE_DATE'] ?? '';
                            if (!empty($completedate)) {
                                echo ' on '
                                    . htmlspecialchars($completedate);
                            }
                            ?>.
                        </p>
                        <form method="post"
                              action="<?php echo $PAGE->url; ?>"
                              class="mt-2">
                            <input type="hidden" name="course_id"
                                   value="<?php
                                   echo $selectedcourse->id; ?>">
                            <input type="hidden" name="user_id"
                                   value="<?php
                                   echo $selecteduser->id; ?>">
                            <input type="hidden" name="sesskey"
                                   value="<?php echo sesskey(); ?>">
                            <button type="submit"
                                    name="post_completion"
                                    class="btn btn-warning btn-sm"
                                    onclick="return confirm(
                                        'CData already shows this as completed. Are you sure you want to send it again?'
                                    );">
                                Re-send Completion
                            </button>
                        </form>
                    </div>
                <?php elseif ($completionlog && $completionlog->status === 'Success') : ?>
                    <div class="status-indicator completed">
                        <strong>
                            Previously sent (not in CData)
                        </strong>
                        <p class="mb-0 mt-1">
                            Local logs show this was posted on
                            <?php
                            echo date(
                                'Y-m-d H:i',
                                $completionlog->timestamp
                            );
                            ?>, but CData doesn't have a completion
                            record. It may not have been processed.
                        </p>
                        <form method="post"
                              action="<?php echo $PAGE->url; ?>"
                              class="mt-2">
                            <input type="hidden" name="course_id"
                                   value="<?php
                                   echo $selectedcourse->id; ?>">
                            <input type="hidden" name="user_id"
                                   value="<?php
                                   echo $selecteduser->id; ?>">
                            <input type="hidden" name="sesskey"
                                   value="<?php echo sesskey(); ?>">
                            <button type="submit"
                                    name="post_completion"
                                    class="btn btn-warning btn-sm"
                                    onclick="return confirm(
                                        'Re-send this completion to CData?'
                                    );">
                                Re-send Completion
                            </button>
                        </form>
                    </div>
                <?php else : ?>
                    <div class="status-indicator not-completed">
                        <strong>Ready to post completion</strong>
                        <?php if (!$moodlecompletion['completed']) : ?>
                        <p class="mb-2 mt-1 text-warning">
                            <strong>Warning:</strong>
                            User has not completed this course in
                            Moodle. Posting will mark them as
                            complete in ELM.
                        </p>
                        <?php else : ?>
                        <p class="mb-2 mt-1">
                            User completed in Moodle but completion
                            has not been sent to CData.
                        </p>
                        <?php endif; ?>

                        <form method="post"
                              action="<?php echo $PAGE->url; ?>">
                            <input type="hidden" name="course_id"
                                   value="<?php
                                   echo $selectedcourse->id; ?>">
                            <input type="hidden" name="user_id"
                                   value="<?php
                                   echo $selecteduser->id; ?>">
                            <input type="hidden" name="sesskey"
                                   value="<?php echo sesskey(); ?>">
                            <button type="submit"
                                    name="post_completion"
                                    class="btn btn-success">
                                Post Completion to CData
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

            </div>

            <div class="mt-3">
                <?php
                $logsurl = '/local/psaelmsync/dashboard.php?search='
                    . urlencode($selecteduser->idnumber);
                $reporturl = '/report/completion/index.php?course='
                    . $selectedcourse->id;
                ?>
                <a href="<?php echo $logsurl; ?>"
                   class="btn btn-sm btn-outline-secondary"
                   target="_blank">
                    View all sync logs for this user
                    <span class="sr-only">
                        (opens in new window)
                    </span>
                </a>
                <a href="<?php echo $reporturl; ?>"
                   class="btn btn-sm btn-outline-secondary"
                   target="_blank">
                    Course completion report
                    <span class="sr-only">
                        (opens in new window)
                    </span>
                </a>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php
echo $OUTPUT->footer();
