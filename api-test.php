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
 * API Test page for generating sample enrolment records in the CData test endpoint.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This file mixes HTML and PHP; disable the per-block docblock check.
// phpcs:disable moodle.Commenting.MissingDocblock

require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');

$mapper = new \local_psaelmsync\field_mapper();

require_login();

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/api-test.php');
$PAGE->set_context($context);
$PAGE->set_title(
    get_string('pluginname', 'local_psaelmsync') . ' - '
    . get_string('apitest', 'local_psaelmsync')
);
$PAGE->set_heading(get_string('apitest', 'local_psaelmsync'));

// Test API endpoint — uses the enrolment API URL/token from plugin settings.
$apitesturl = get_config('local_psaelmsync', 'apiurl');
$apitesttoken = get_config('local_psaelmsync', 'apitoken');

// State file stored in Moodle's data directory (not web-accessible).
define('APITEST_STATE_FILE', $CFG->dataroot . '/psaelmsync_apitest_state.json');

// Name pools for generating fake users.
define('APITEST_FIRST_NAMES', [
    'Aiden', 'Baljit', 'Carmen', 'Deepa', 'Elena', 'Fiona', 'Gurpreet',
    'Harjit', 'Indira', 'Jasmine', 'Kamal', 'Liam', 'Meera', 'Naveen',
    'Olivia', 'Priya', 'Quinn', 'Rajesh', 'Simran', 'Tanya', 'Uma',
    'Vikram', 'Wei', 'Xander', 'Yuki', 'Zara', 'Amrit', 'Brenda',
    'Colin', 'Diana', 'Ethan', 'Fatima', 'George', 'Hannah', 'Ivan',
    'Joanne', 'Kevin', 'Linda', 'Marcus', 'Nadia', 'Oscar', 'Pam',
    'Robert', 'Sarah', 'Trevor', 'Wendy',
]);
define('APITEST_LAST_NAMES', [
    'Singh', 'Chen', 'Williams', 'Brown', 'Patel', 'Wilson', 'Anderson',
    'Sharma', 'Thompson', 'Garcia', 'Lee', 'Martin', 'Robinson', 'Clark',
    'Gill', 'Dhillon', 'Grewal', 'Sandhu', 'Bains', 'Sidhu', 'Kaur',
    'Campbell', 'Stewart', 'MacDonald', 'Fraser', 'Morrison', 'Wallace',
    'Mitchell', 'Turner', 'Parker', 'Cooper', 'Ward', 'James', 'Kelly',
    'Wright', 'Scott', 'Green', 'Adams', 'Nelson', 'Hill', 'Moore',
    'Taylor', 'Thomas', 'White', 'Harris', 'Lewis', 'Young', 'King',
]);

/**
 * Load courses from the database that have an idnumber (ELM course ID).
 *
 * @return array List of course arrays with id, fullname, and idnumber.
 */
function apitest_load_courses() {
    global $DB;
    $courses = $DB->get_records_select(
        'course',
        "idnumber IS NOT NULL AND idnumber != ''",
        null,
        'fullname ASC',
        'id, fullname, idnumber'
    );
    $result = [];
    foreach ($courses as $course) {
        $result[] = [
            'course_id' => $course->id,
            'course_name' => $course->fullname,
            'elm_course_id' => $course->idnumber,
        ];
    }
    return $result;
}

/**
 * Load persisted state from the JSON file.
 *
 * @return array State with 'enrolled', 'next_enrolment_id', and 'next_person_id'.
 */
function apitest_load_state() {
    if (file_exists(APITEST_STATE_FILE)) {
        $json = file_get_contents(APITEST_STATE_FILE);
        $state = json_decode($json, true);
        if (is_array($state)) {
            return $state;
        }
    }
    return ['enrolled' => [], 'next_enrolment_id' => 90000000, 'next_person_id' => 800000];
}

/**
 * Save state to the JSON file.
 *
 * @param array $state The state to persist.
 */
function apitest_save_state($state) {
    file_put_contents(APITEST_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

/**
 * Generate a random GUID (32-char uppercase hex).
 *
 * @return string A GUID string.
 */
function apitest_generate_guid() {
    return strtoupper(bin2hex(random_bytes(16)));
}

/**
 * Generate a fake user record.
 *
 * @param array $state State array (modified in place for person_id counter).
 * @return array User data with first_name, last_name, guid, oprid, email, person_id, activity_id.
 */
function apitest_generate_user(&$state) {
    $first = APITEST_FIRST_NAMES[array_rand(APITEST_FIRST_NAMES)];
    $last = APITEST_LAST_NAMES[array_rand(APITEST_LAST_NAMES)];
    $guid = apitest_generate_guid();
    $oprid = strtolower(substr($first[0] . $last, 0, 12)) . rand(100, 999);
    $email = 'test.' . strtolower($first) . '.' . strtolower($last) . rand(1, 999) . '@test.gov.bc.ca';
    $personid = $state['next_person_id'];
    $state['next_person_id']++;
    $activityid = rand(100000, 999999);

    return [
        'first_name' => $first,
        'last_name' => $last,
        'guid' => $guid,
        'oprid' => $oprid,
        'email' => $email,
        'person_id' => $personid,
        'activity_id' => $activityid,
    ];
}

/**
 * Build a POST payload for an Enrol record.
 *
 * @param array $user User data from apitest_generate_user().
 * @param array $course Course data from apitest_load_courses().
 * @param array $state State array (modified in place for enrolment_id counter).
 * @param \local_psaelmsync\field_mapper $mapper Field mapper for API field translation.
 * @param string $shortname Course shortname to use in the record.
 * @return array The API record payload.
 */
function apitest_build_enrol_record($user, $course, &$state, $mapper, $shortname) {
    $enrolmentid = $state['next_enrolment_id'];
    $state['next_enrolment_id']++;
    $now = date('Y-m-d\TH:i:s') . '.000-08:00';

    return $mapper->intake_payload([
        'FIRST_NAME' => $user['first_name'],
        'LAST_NAME' => $user['last_name'],
        'EMAIL' => $user['email'],
        'GUID' => $user['guid'],
        'COURSE_IDENTIFIER' => $course['elm_course_id'],
        'COURSE_SHORTNAME' => $shortname,
        'COURSE_LONG_NAME' => $course['course_name'],
        'COURSE_STATE' => 'Enrol',
        'USER_EFFECTIVE_DATE' => date('Y-m-d'),
        'USER_STATE' => 'ACTIVE',
        'COURSE_STATE_DATE' => $now,
        'PERSON_ID' => (string)$user['person_id'],
        'ACTIVITY_ID' => (string)$user['activity_id'],
        'OPRID' => $user['oprid'],
        'ENROLMENT_ID' => (string)$enrolmentid,
    ]);
}

/**
 * Build a POST payload for a Suspend record from a previously enrolled user.
 *
 * @param array $entry Previously enrolled user data from state.
 * @param array $state State array (modified in place for enrolment_id counter).
 * @param \local_psaelmsync\field_mapper $mapper Field mapper for API field translation.
 * @return array The API record payload.
 */
function apitest_build_suspend_record($entry, &$state, $mapper) {
    $enrolmentid = $state['next_enrolment_id'];
    $state['next_enrolment_id']++;
    $now = date('Y-m-d\TH:i:s') . '.000-08:00';

    return $mapper->intake_payload([
        'FIRST_NAME' => $entry['first_name'],
        'LAST_NAME' => $entry['last_name'],
        'EMAIL' => $entry['email'],
        'GUID' => $entry['guid'],
        'COURSE_IDENTIFIER' => $entry['elm_course_id'],
        'COURSE_SHORTNAME' => $entry['shortname'],
        'COURSE_LONG_NAME' => $entry['course_name'],
        'COURSE_STATE' => 'Suspend',
        'USER_EFFECTIVE_DATE' => date('Y-m-d'),
        'USER_STATE' => 'ACTIVE',
        'COURSE_STATE_DATE' => $now,
        'PERSON_ID' => (string)$entry['person_id'],
        'ACTIVITY_ID' => (string)$entry['activity_id'],
        'OPRID' => $entry['oprid'],
        'ENROLMENT_ID' => (string)$enrolmentid,
    ]);
}

/**
 * POST a single record to the CData test API.
 *
 * @param array $record The record payload.
 * @param string $url The API endpoint URL.
 * @param string $token The API auth token.
 * @return array [bool success, string response].
 */
function apitest_post_record($record, $url, $token) {
    $curl = new curl();
    $curl->setHeader(['x-cdata-authtoken: ' . $token, 'Content-Type: application/json']);
    $response = $curl->post($url, json_encode($record));
    $info = $curl->get_info();
    $httpcode = $info['http_code'] ?? 0;
    $success = ($httpcode >= 200 && $httpcode < 300);
    return [$success, $response];
}

/**
 * Delete all test records (those with @test.gov.bc.ca emails) from the API.
 *
 * @param string $apiurl The API endpoint URL.
 * @param string $token The API auth token.
 * @param \local_psaelmsync\field_mapper $mapper Field mapper for API field translation.
 * @return array List of result message strings.
 */
function apitest_cleanup($apiurl, $token, $mapper) {
    $messages = [];
    $curl = new curl();
    $curl->setHeader(['x-cdata-authtoken: ' . $token]);
    $filter = urlencode($mapper->filter_field('EMAIL') . " like '%@test.gov.bc.ca'");
    $url = $apiurl . '?$filter=' . $filter;
    $response = $curl->get($url);
    $data = json_decode($response, true);
    $records = $data['value'] ?? [];

    if (empty($records)) {
        $messages[] = 'No test records found to clean up.';
        return $messages;
    }

    $total = count($records);
    $deleted = 0;
    foreach ($records as $apirecord) {
        $rec = $mapper->normalize($apirecord);
        $recid = $rec['record_enrol_id'] ?? null;
        if ($recid === null) {
            continue;
        }
        $delurl = $apiurl . "(record_enrol_id='" . $recid . "')";
        $delcurl = new curl();
        $delcurl->setHeader(['x-cdata-authtoken: ' . $token]);
        $delcurl->delete($delurl);
        $info = $delcurl->get_info();
        $httpcode = $info['http_code'] ?? 0;
        if ($httpcode >= 200 && $httpcode < 300) {
            $deleted++;
        } else {
            $messages[] = "Failed to delete record_enrol_id={$recid} (HTTP {$httpcode})";
        }
    }

    $messages[] = "Deleted {$deleted}/{$total} test records from the API.";

    if (file_exists(APITEST_STATE_FILE)) {
        unlink(APITEST_STATE_FILE);
        $messages[] = 'Cleared local state file.';
    }

    return $messages;
}

// Handle form submissions.
$action = optional_param('action', '', PARAM_ALPHA);
$results = [];

if ($action === 'generate' && confirm_sesskey()) {
    $num = required_param('num', PARAM_INT);
    $suspendratio = optional_param('suspendratio', 15, PARAM_INT);
    $ratio = max(0, min(100, $suspendratio)) / 100;

    $courses = apitest_load_courses();
    if (empty($courses)) {
        $results[] = ['type' => 'error', 'message' => 'No courses with idnumber found in Moodle.'];
    } else {
        $state = apitest_load_state();
        $pool = &$state['enrolled'];

        // Determine how many suspends vs enrols.
        $numsuspends = 0;
        if (!empty($pool)) {
            $numsuspends = min((int)($num * $ratio), count($pool));
        }
        $numenrols = $num - $numsuspends;

        // Build suspend records.
        if ($numsuspends > 0) {
            $suspendkeys = array_rand($pool, $numsuspends);
            if (!is_array($suspendkeys)) {
                $suspendkeys = [$suspendkeys];
            }
            foreach ($suspendkeys as $key) {
                $entry = $pool[$key];
                $record = apitest_build_suspend_record($entry, $state, $mapper);
                [$ok, $resp] = apitest_post_record($record, $apitesturl, $apitesttoken);
                $label = "Suspend: {$entry['first_name']} {$entry['last_name']}"
                    . " &rarr; {$entry['elm_course_id']}";
                if ($ok) {
                    $results[] = ['type' => 'success', 'message' => $label];
                } else {
                    $results[] = ['type' => 'error', 'message' => "{$label} — {$resp}"];
                }
                unset($pool[$key]);
            }
            $pool = array_values($pool);
        }

        // Build enrol records.
        for ($i = 0; $i < $numenrols; $i++) {
            $user = apitest_generate_user($state);
            $course = $courses[array_rand($courses)];
            $shortname = 'ITEM-' . rand(10000, 99999) . '-' . rand(1, 9);
            $record = apitest_build_enrol_record($user, $course, $state, $mapper, $shortname);
            [$ok, $resp] = apitest_post_record($record, $apitesturl, $apitesttoken);
            $label = "Enrol: {$user['first_name']} {$user['last_name']}"
                . " &rarr; {$course['elm_course_id']} ({$course['course_name']})";
            if ($ok) {
                $results[] = ['type' => 'success', 'message' => $label];
                $pool[] = [
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'email' => $user['email'],
                    'guid' => $user['guid'],
                    'oprid' => $user['oprid'],
                    'person_id' => $user['person_id'],
                    'activity_id' => $user['activity_id'],
                    'elm_course_id' => $course['elm_course_id'],
                    'course_name' => $course['course_name'],
                    'shortname' => $shortname,
                ];
            } else {
                $results[] = ['type' => 'error', 'message' => "{$label} — {$resp}"];
            }
        }

        apitest_save_state($state);
    }
} else if ($action === 'cleanup' && confirm_sesskey()) {
    $messages = apitest_cleanup($apitesturl, $apitesttoken, $mapper);
    foreach ($messages as $msg) {
        $results[] = ['type' => 'info', 'message' => $msg];
    }
}

// Load state for display.
$state = apitest_load_state();
$poolcount = count($state['enrolled']);

// Check if the URL looks like a production endpoint.
$isproduction = (stripos($apitesturl, 'test') === false);

echo $OUTPUT->header();
?>

<?php if ($isproduction) : ?>
<div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
    <strong>WARNING:</strong>&nbsp;The configured API URL does not contain
    &ldquo;test&rdquo; &mdash; you may be pointing at a
    <strong>PRODUCTION</strong> endpoint. Inserting test data into production
    will create fake enrolment records that affect real learners.
    <br>
    <code><?php echo s($apitesturl); ?></code>
</div>
<?php endif; ?>

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
                Intake Run History</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/manual-intake.php">
                Manual Intake</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/manual-complete.php">
                Manual Complete</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active"
               href="/local/psaelmsync/api-test.php"
               aria-current="page">API Test</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/field-mapping.php">
                Field Mapping</a>
        </li>
    </ul>
</nav>

<!-- Results -->
<?php if (!empty($results)) : ?>
<div class="mb-3">
    <?php
    $successcount = 0;
    $errorcount = 0;
    foreach ($results as $r) {
        if ($r['type'] === 'success') {
            $successcount++;
        } else if ($r['type'] === 'error') {
            $errorcount++;
        }
    }
    ?>
    <?php if ($successcount > 0 || $errorcount > 0) : ?>
    <div class="alert alert-<?php echo $errorcount > 0 ? 'warning' : 'success'; ?>" role="alert">
        <?php echo $successcount; ?> succeeded, <?php echo $errorcount; ?> failed.
        Pool now has <?php echo $poolcount; ?> users available for future suspends.
    </div>
    <?php endif; ?>
    <details>
        <summary>Record details (<?php echo count($results); ?>)</summary>
        <ul class="list-group mt-2">
            <?php foreach ($results as $r) : ?>
            <li class="list-group-item list-group-item-<?php
                echo $r['type'] === 'error' ? 'danger' : ($r['type'] === 'info' ? 'info' : 'success');
            ?>">
                <?php echo $r['message']; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </details>
</div>
<?php endif; ?>

<div class="row">
    <!-- Generate Form -->
    <div class="col-md-7">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title h5 mb-0">
                    <?php echo get_string('apitest_generate', 'local_psaelmsync'); ?>
                </h3>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    <?php echo get_string('apitest_generate_desc', 'local_psaelmsync'); ?>
                </p>
                <form method="post" action="api-test.php" id="apitest-generate-form">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="generate">
                    <div class="form-group row mb-3">
                        <label for="num" class="col-sm-4 col-form-label">
                            <?php echo get_string('apitest_numrecords', 'local_psaelmsync'); ?>
                        </label>
                        <div class="col-sm-8">
                            <input type="number" id="num" name="num"
                                   class="form-control" value="25" min="1" max="500" required>
                        </div>
                    </div>
                    <div class="form-group row mb-3">
                        <label for="suspendratio" class="col-sm-4 col-form-label">
                            <?php echo get_string('apitest_suspendratio', 'local_psaelmsync'); ?>
                        </label>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="number" id="suspendratio" name="suspendratio"
                                       class="form-control" value="15" min="0" max="100">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="form-text text-muted">
                                <?php echo get_string('apitest_suspendratio_desc', 'local_psaelmsync'); ?>
                            </small>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?php echo get_string('apitest_insert', 'local_psaelmsync'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- State & Cleanup -->
    <div class="col-md-5">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title h5 mb-0">
                    <?php echo get_string('apitest_state', 'local_psaelmsync'); ?>
                </h3>
            </div>
            <div class="card-body">
                <p>
                    <?php echo get_string('apitest_poolsize', 'local_psaelmsync', $poolcount); ?>
                </p>
                <p class="text-muted small">
                    <?php echo get_string('apitest_poolexplain', 'local_psaelmsync'); ?>
                </p>
            </div>
        </div>
        <div class="card mb-3 border-danger">
            <div class="card-header bg-danger text-white">
                <h3 class="card-title h5 mb-0">
                    <?php echo get_string('apitest_cleanup', 'local_psaelmsync'); ?>
                </h3>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    <?php echo get_string('apitest_cleanup_desc', 'local_psaelmsync'); ?>
                </p>
                <form method="post" action="api-test.php" id="apitest-cleanup-form">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="cleanup">
                    <button type="submit" class="btn btn-outline-danger">
                        <?php echo get_string('apitest_cleanup_btn', 'local_psaelmsync'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Enrolled Pool Detail -->
<?php if ($poolcount > 0) : ?>
<details class="mb-3">
    <summary><?php echo get_string('apitest_pooldetail', 'local_psaelmsync'); ?></summary>
    <table class="table table-striped table-bordered table-sm mt-2">
        <caption class="sr-only">
            Users in the enrolment pool available for suspend generation
        </caption>
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">GUID</th>
                <th scope="col">Course</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($state['enrolled'] as $entry) : ?>
            <tr>
                <td><?php echo s($entry['first_name'] . ' ' . $entry['last_name']); ?></td>
                <td><?php echo s($entry['email']); ?></td>
                <td><code><?php echo s($entry['guid']); ?></code></td>
                <td><?php echo s($entry['course_name']); ?>
                    (<?php echo s($entry['elm_course_id']); ?>)</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var isProduction = <?php echo $isproduction ? 'true' : 'false'; ?>;

    var generateForm = document.getElementById('apitest-generate-form');
    if (generateForm) {
        generateForm.addEventListener('submit', function(e) {
            var num = document.getElementById('num').value;
            var msg = 'Insert ' + num + ' test record(s) into the API?';
            if (isProduction) {
                msg = 'WARNING: The API URL does not contain "test" — '
                    + 'this looks like a PRODUCTION endpoint!\n\n'
                    + 'You are about to insert ' + num + ' fake enrolment '
                    + 'record(s) into what may be a live system.\n\n'
                    + 'Are you absolutely sure?';
            }
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    }

    var cleanupForm = document.getElementById('apitest-cleanup-form');
    if (cleanupForm) {
        cleanupForm.addEventListener('submit', function(e) {
            var msg = 'Delete all test records (@test.gov.bc.ca) from the API?';
            if (isProduction) {
                msg = 'WARNING: The API URL does not contain "test" — '
                    + 'this looks like a PRODUCTION endpoint!\n\n'
                    + 'Are you absolutely sure you want to delete records?';
            }
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    }
});
</script>

<style>
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

<?php
echo $OUTPUT->footer();
