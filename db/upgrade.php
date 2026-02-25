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
 * Database upgrade steps for the PSA ELM Sync plugin.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Perform the upgrade steps from the given old version to the current one.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool True on success.
 */
function xmldb_local_psaelmsync_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager(); // Loads the database manager.

    // Upgrade step for adding elm_course_id to local_psaelmsync_logs.
    if ($oldversion < 2024090606) {
        // Define the new field elm_course_id.
        $table = new xmldb_table('local_psaelmsync_logs');
        $field = new xmldb_field('elm_course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'course_id');

        // Conditionally launch the add field statement.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // PSA ELM Sync savepoint reached.
        upgrade_plugin_savepoint(true, 2024090606, 'local', 'psaelmsync');
    }

    // Upgrade step for increasing action field length from 20 to 100.
    if ($oldversion < 2026012702) {
        $table = new xmldb_table('local_psaelmsync_logs');
        $fieldaction = new xmldb_field(
            'action',
            XMLDB_TYPE_CHAR,
            '100',
            null,
            XMLDB_NOTNULL,
            null,
            null,
            'elm_enrolment_id',
        );
        // Change the field precision.
        $dbman->change_field_precision($table, $fieldaction);

        $fieldoprid = new xmldb_field('oprid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null, 'user_email');
        $fieldpersonid = new xmldb_field(
            'person_id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            0,
            'user_email',
        );
        $fieldactivityid = new xmldb_field(
            'activity_id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            0,
            'user_email',
        );
        // Conditionally launch the add field statements.
        if (!$dbman->field_exists($table, $fieldoprid)) {
            $dbman->add_field($table, $fieldoprid);
        }
        if (!$dbman->field_exists($table, $fieldpersonid)) {
            $dbman->add_field($table, $fieldpersonid);
        }
        if (!$dbman->field_exists($table, $fieldactivityid)) {
            $dbman->add_field($table, $fieldactivityid);
        }

        // PSA ELM Sync savepoint reached.
        upgrade_plugin_savepoint(true, 2026012702, 'local', 'psaelmsync');
    }

    return true;
}
