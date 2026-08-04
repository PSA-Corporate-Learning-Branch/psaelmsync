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
 * Script to manually trigger the PSA enrolment sync.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/trigger_sync.php');
$PAGE->set_context($context);
$PAGE->set_title('Trigger PSA Enrol Sync');

echo $OUTPUT->header();
echo $OUTPUT->heading('Trigger PSA Enrol Sync');

require_once($CFG->dirroot . '/local/psaelmsync/lib.php');

// Call the sync function.
local_psaelmsync_sync();

echo $OUTPUT->notification('PSA Enrol Sync has been triggered.', 'notifysuccess');

echo $OUTPUT->footer();
