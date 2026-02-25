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
 * Course dashboard page displaying per-course enrollment statistics.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This file mixes HTML and PHP; disable the per-block docblock check.
// phpcs:disable moodle.Commenting.MissingDocblock

require_once('../../config.php');
require_login();

global $DB, $OUTPUT, $PAGE;

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/dashboard-courses.php');
$PAGE->set_context($context);
$PAGE->set_title(
    get_string('pluginname', 'local_psaelmsync') . ' - '
    . get_string('courseenrolstats', 'local_psaelmsync')
);
$PAGE->set_heading(get_string('courseenrolstats', 'local_psaelmsync'));

echo $OUTPUT->header();

// Select all courses with IDNumber and their completion_opt_in field.
$courses = $DB->get_records_sql("
    SELECT c.id, c.fullname, c.idnumber,
           COALESCE(cfd.intvalue, 0) AS completion_opt_in
    FROM {course} c
    LEFT JOIN {customfield_data} cfd ON cfd.instanceid = c.id
    LEFT JOIN {customfield_field} cff ON cff.id = cfd.fieldid
    WHERE c.idnumber IS NOT NULL
    AND c.idnumber <> ''
    AND cff.shortname = 'completion_opt_in'
    ORDER BY c.fullname ASC
");

?>

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
            <a class="nav-link active"
               href="/local/psaelmsync/dashboard-courses.php"
               aria-current="page">Course Dashboard</a>
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
            <a class="nav-link"
               href="/local/psaelmsync/manual-complete.php">
                Manual Complete</a>
        </li>
    </ul>
</nav>

<p>This dashboard is a work in progress. It is only meant to
    give a count of the log records that have come through CData
    and does not reflect the actual number of enrolments in a
    given course.</p>

<!-- Results Table -->
<table class="table table-striped table-bordered">
    <caption class="sr-only">
        Course enrollment statistics from CData sync logs
    </caption>
    <thead>
        <tr>
            <th scope="col">
                <?php echo get_string('course', 'local_psaelmsync'); ?>
            </th>
            <th scope="col">
                <?php echo get_string('idnumber', 'local_psaelmsync'); ?>
            </th>
            <th scope="col">
                <?php echo get_string('completion_opt_in', 'local_psaelmsync'); ?>
            </th>
            <th scope="col">
                <?php echo get_string('completions', 'local_psaelmsync'); ?>
            </th>
            <th scope="col">
                <?php echo get_string('enrolments', 'local_psaelmsync'); ?>
            </th>
            <th scope="col">
                <?php echo get_string('suspends', 'local_psaelmsync'); ?>
            </th>
            <th scope="col">
                <?php echo get_string('errors', 'local_psaelmsync'); ?>
            </th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($courses as $course) : ?>
            <?php
            // Count enrol, suspend, and error log entries for each course.
            $enrolments = $DB->count_records(
                'local_psaelmsync_logs',
                ['course_id' => $course->id, 'action' => 'Enrol']
            );
            $suspends = $DB->count_records(
                'local_psaelmsync_logs',
                ['course_id' => $course->id, 'action' => 'Suspend']
            );
            $errors = $DB->count_records(
                'local_psaelmsync_logs',
                ['course_id' => $course->id, 'status' => 'Error']
            );
            $completions = $DB->count_records(
                'local_psaelmsync_logs',
                ['course_id' => $course->id, 'action' => 'Complete']
            );
            $optinlabel = ($course->completion_opt_in == 1)
                ? 'Opted In' : 'Not Opted In';
            ?>
            <tr>
                <td>
                    <a href="/course/view.php?id=<?php echo $course->id; ?>"
                       target="_blank">
                        <?php echo format_string($course->fullname); ?>
                        <span class="sr-only">
                            (opens in new window)
                        </span>
                    </a>
                    <small>(<a
                        href="/user/index.php?id=<?php echo $course->id; ?>"
                        target="_blank"
                        aria-label="Participants for <?php
                            echo format_string($course->fullname);
                        ?> (opens in new window)">
                        Participants
                    </a>)</small>
                </td>
                <td><?php echo format_string($course->idnumber); ?></td>
                <td><?php echo $optinlabel; ?></td>
                <td><?php echo $completions; ?></td>
                <td><?php echo $enrolments; ?></td>
                <td><?php echo $suspends; ?></td>
                <td><?php echo $errors; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

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
