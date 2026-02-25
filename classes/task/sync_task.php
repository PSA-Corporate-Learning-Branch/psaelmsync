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
 * Scheduled task for synchronising enrolment data from the ELM system.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_psaelmsync\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/psaelmsync/lib.php');

/**
 * Scheduled task that runs the PSA ELM enrolment sync process.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_task extends \core\task\scheduled_task {
    /**
     * Return the name of this scheduled task.
     *
     * @return string The localised task name.
     */
    public function get_name() {
        return get_string('sync_task', 'local_psaelmsync');
    }

    /**
     * Execute the enrolment sync task.
     *
     * @return void
     */
    public function execute() {
        // Check if the plugin is enabled before executing the sync.
        if (!get_config('local_psaelmsync', 'enabled')) {
            mtrace('PSA Enrol Sync: Task is disabled.');
            return;
        }

        local_psaelmsync_sync();
    }
}
