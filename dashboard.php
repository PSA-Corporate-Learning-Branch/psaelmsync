<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/local/psaelmsync/classes/log_table.php');
require_login();

global $DB;

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

// Get search param early so we can include it in the page URL
$search = optional_param('search', '', PARAM_TEXT);

$pageurl = new moodle_url('/local/psaelmsync/dashboard.php');
if (!empty($search)) {
    $pageurl->param('search', $search);
}

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_psaelmsync') . ' - ' . get_string('logs', 'local_psaelmsync'));
$PAGE->set_heading(get_string('logs', 'local_psaelmsync'));

echo $OUTPUT->header();
?>
<!-- Tabbed Navigation -->
<nav aria-label="PSA ELM Sync sections">
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link" href="/admin/settings.php?section=local_psaelmsync">Settings</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="/local/psaelmsync/dashboard.php" aria-current="page">Learner Dashboard</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="/local/psaelmsync/dashboard-courses.php">Course Dashboard</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="/local/psaelmsync/dashboard-intake.php">Intake Run History</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="/local/psaelmsync/manual-intake.php">Manual Intake</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="/local/psaelmsync/manual-complete.php">Manual Complete</a>
        </li>
    </ul>
</nav>
<!-- Search Form -->
<form method="get" action="dashboard.php" class="mt-3" role="search">
    <label for="search-logs" class="sr-only"><?= get_string('search', 'local_psaelmsync') ?></label>
    <div class="input-group">
        <input type="text" id="search-logs" name="search" value="<?= s(optional_param('search', '', PARAM_RAW)) ?>" class="form-control" placeholder="<?= get_string('search', 'local_psaelmsync') ?>">
        <div class="input-group-append">
            <button class="btn btn-primary" type="submit"><?= get_string('search', 'local_psaelmsync') ?></button>
        </div>
    </div>
</form>
<?php
if(!empty($search)):
?>
<p>Searching for a timestamp will show you records 2 minutes on either side of the given time.</p>
<div><a href="/local/psaelmsync/dashboard.php" class="btn btn-link">Clear search</a></div>
<?php endif ?>
<style>
    th.c2,
    th.c3 {
        min-width: 100px;
    }
    th.c7 {
        max-width: 30px;
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
<?php

$table = new \local_psaelmsync\output\log_table('psaelmsync_log_table');

// Define the SQL query for fetching the logs with search functionality.
$conditions = [];
$params = [];

if (!empty($search)) {
   // Check if the search string is a valid ISO8601 date (skip purely numeric strings like enrolment IDs).
   $timestamp = !ctype_digit($search) ? strtotime($search) : false;
   if ($timestamp !== false) {
       // Convert the timestamp to Unix time.
       $conditions[] = 'timestamp BETWEEN :start AND :end';
       $params['start'] = $timestamp - 120;
       $params['end'] = $timestamp + 120; // + 100 seconds to capture the whole run
   } else {
        $conditions[] = $DB->sql_like('status', ':search1', false);
        $conditions[] = $DB->sql_like('course_name', ':search2', false);
        $conditions[] = $DB->sql_like('user_firstname', ':search3', false);
        $conditions[] = $DB->sql_like('user_lastname', ':search4', false);
        $conditions[] = $DB->sql_like('user_guid', ':search5', false);
        $conditions[] = $DB->sql_like('user_email', ':search6', false);
        $conditions[] = $DB->sql_like('elm_enrolment_id', ':search7', false);
        $conditions[] = $DB->sql_like('action', ':search8', false);
        $conditions[] = $DB->sql_like('person_id', ':search9', false);
        $params['search1'] = "%$search%";
        $params['search2'] = "%$search%";
        $params['search3'] = "%$search%";
        $params['search4'] = "%$search%";
        $params['search5'] = "%$search%";
        $params['search6'] = "%$search%";
        $params['search7'] = "%$search%";
        $params['search8'] = "%$search%";
        $params['search9'] = "%$search%";
   }
}

$where = !empty($conditions) ? implode(' OR ', $conditions) : '1=1';
// $table->set_sql('*', '{local_psaelmsync_logs}', $where, $params);
$table->set_sql('*', '{local_psaelmsync_logs}', $where, $params, 'timestamp DESC');


// Define the base URL for the table.
$table->define_baseurl($PAGE->url);

// Set the per page limit.
$perpage = 20; // Adjust the number of logs per page as needed.
$totalrows = $DB->count_records_select('local_psaelmsync_logs', $where, $params);
$table->pagesize($perpage, $totalrows);

// Setup and display the table.
$table->out($perpage, true);

echo $OUTPUT->footer();
