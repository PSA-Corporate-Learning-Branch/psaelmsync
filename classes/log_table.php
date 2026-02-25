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
 * Log table class for displaying PSA ELM sync log entries.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_psaelmsync\output;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Table class for rendering the PSA ELM sync log records.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_table extends \table_sql {
    /**
     * Constructor for the log table.
     *
     * @param string $uniqueid A unique ID for this table instance.
     */
    public function __construct($uniqueid) {
        parent::__construct($uniqueid);

        $columns = [
            'status',
            'action',
            'timestamp',
            'course_name',
            'person_id',
            'elm_enrolment_id',
            'user_lastname',
            'user_email',
            'user_guid',
            'record_date_created',
        ];
        $this->define_columns($columns);

        $headers = [
            get_string('status', 'local_psaelmsync'),
            get_string('action', 'local_psaelmsync'),
            get_string('timestamp', 'local_psaelmsync'),
            get_string('course_name', 'local_psaelmsync'),
            get_string('person_id', 'local_psaelmsync'),
            get_string('elm_enrolment_id', 'local_psaelmsync'),
            get_string('user_lastname', 'local_psaelmsync'),
            get_string('user_email', 'local_psaelmsync'),
            get_string('user_guid', 'local_psaelmsync'),
            get_string('record_date_created', 'local_psaelmsync'),
        ];
        $this->define_headers($headers);

        $this->sortable(true, 'timestamp', SORT_DESC);
    }

    /**
     * Format the status column value.
     *
     * @param object $values The row data object.
     * @return string The formatted status string.
     */
    public function col_status($values) {
        return ucfirst($values->status);
    }

    /**
     * Format the action column value.
     *
     * @param object $values The row data object.
     * @return string The formatted action string.
     */
    public function col_action($values) {
        return ucfirst($values->action);
    }

    /**
     * Format the user lastname column as a link to the user profile.
     *
     * @param object $values The row data object.
     * @return string The HTML link to the user profile.
     */
    public function col_user_lastname($values) {
        $name = $values->user_firstname . ' ' . $values->user_lastname;
        $userurl = new \moodle_url('/user/view.php', ['id' => $values->user_id]);
        return \html_writer::link($userurl, $name);
    }

    /**
     * Format the user email column value.
     *
     * @param object $values The row data object.
     * @return string The user email address.
     */
    public function col_user_email($values) {
        return $values->user_email;
    }

    /**
     * Format the user GUID column value.
     *
     * @param object $values The row data object.
     * @return string The user GUID.
     */
    public function col_user_guid($values) {
        return $values->user_guid;
    }

    /**
     * Format the course name column as a link to the course participants page.
     *
     * @param object $values The row data object.
     * @return string The HTML link to the course participants page.
     */
    public function col_course_name($values) {
        $courseurl = new \moodle_url('/user/index.php', ['id' => $values->course_id]);
        return \html_writer::link($courseurl, $values->course_name);
    }

    /**
     * Format the user details column as a link to the user profile.
     *
     * @param object $values The row data object.
     * @return string The HTML link containing user details.
     */
    public function col_user_details($values) {
        $userurl = new \moodle_url('/user/profile.php', ['id' => $values->user_id]);
        $userdetails = "{$values->user_firstname} {$values->user_lastname}"
            . "<br>GUID: {$values->user_guid}"
            . "<br>Email: {$values->user_email}";
        return \html_writer::link($userurl, $userdetails);
    }

    /**
     * Format the timestamp column as a human-readable date string.
     *
     * @param object $values The row data object.
     * @return string The formatted date and time string.
     */
    public function col_timestamp($values) {
        return date('Y-m-d H:i:s', $values->timestamp);
    }
}
