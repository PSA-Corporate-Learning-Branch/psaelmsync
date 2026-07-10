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
 * Privacy provider for the PSA ELM Sync plugin.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_psaelmsync\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the PSA ELM Sync plugin.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Return metadata about the user data stored by this plugin.
     *
     * @param collection $collection The initialised metadata collection.
     * @return collection The updated metadata collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_psaelmsync_logs',
            [
                'sha256hash' => 'privacy:metadata:local_psaelmsync_logs:sha256hash',
                'record_id' => 'privacy:metadata:local_psaelmsync_logs:record_id',
                'record_date_created' => 'privacy:metadata:local_psaelmsync_logs:record_date_created',
                'course_id' => 'privacy:metadata:local_psaelmsync_logs:course_id',
                'elm_course_id' => 'privacy:metadata:local_psaelmsync_logs:elm_course_id',
                'course_name' => 'privacy:metadata:local_psaelmsync_logs:course_name',
                'class_code' => 'privacy:metadata:local_psaelmsync_logs:class_code',
                'user_id' => 'privacy:metadata:local_psaelmsync_logs:user_id',
                'user_firstname' => 'privacy:metadata:local_psaelmsync_logs:user_firstname',
                'user_lastname' => 'privacy:metadata:local_psaelmsync_logs:user_lastname',
                'user_guid' => 'privacy:metadata:local_psaelmsync_logs:user_guid',
                'user_email' => 'privacy:metadata:local_psaelmsync_logs:user_email',
                'oprid' => 'privacy:metadata:local_psaelmsync_logs:oprid',
                'person_id' => 'privacy:metadata:local_psaelmsync_logs:person_id',
                'activity_id' => 'privacy:metadata:local_psaelmsync_logs:activity_id',
                'elm_enrolment_id' => 'privacy:metadata:local_psaelmsync_logs:elm_enrolment_id',
                'record_enrol_id' => 'privacy:metadata:local_psaelmsync_logs:record_enrol_id',
                'action' => 'privacy:metadata:local_psaelmsync_logs:action',
                'status' => 'privacy:metadata:local_psaelmsync_logs:status',
                'timestamp' => 'privacy:metadata:local_psaelmsync_logs:timestamp',
            ],
            'privacy:metadata:local_psaelmsync_logs'
        );

        $collection->add_external_location_link(
            'cdata_elm',
            [
                'EMAIL' => 'privacy:metadata:cdata_elm:email',
                'GUID' => 'privacy:metadata:cdata_elm:guid',
                'FIRST_NAME' => 'privacy:metadata:cdata_elm:first_name',
                'LAST_NAME' => 'privacy:metadata:cdata_elm:last_name',
                'OPRID' => 'privacy:metadata:cdata_elm:oprid',
                'PERSON_ID' => 'privacy:metadata:cdata_elm:person_id',
                'ACTIVITY_ID' => 'privacy:metadata:cdata_elm:activity_id',
                'ENROLMENT_ID' => 'privacy:metadata:cdata_elm:enrolment_id',
                'COURSE_IDENTIFIER' => 'privacy:metadata:cdata_elm:course_identifier',
                'COURSE_SHORTNAME' => 'privacy:metadata:cdata_elm:course_shortname',
                'COURSE_LONG_NAME' => 'privacy:metadata:cdata_elm:course_long_name',
                'COURSE_COMPLETE_DATE' => 'privacy:metadata:cdata_elm:course_complete_date',
                'COURSE_STATE' => 'privacy:metadata:cdata_elm:course_state',
                'USER_STATE' => 'privacy:metadata:cdata_elm:user_state',
                'USER_EFFECTIVE_DATE' => 'privacy:metadata:cdata_elm:user_effective_date',
            ],
            'privacy:metadata:cdata_elm'
        );

        return $collection;
    }

    /**
     * Get contexts containing information for a user.
     *
     * @param int $userid The user ID.
     * @return contextlist The list of contexts containing user data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $user = self::get_user($userid);

        $params = [
            'contextlevel' => CONTEXT_COURSE,
        ];
        $usersql = self::get_user_match_sql($user, $params, 'coursectx');

        $sql = "SELECT ctx.id
                  FROM {local_psaelmsync_logs} l
                  JOIN {context} ctx
                    ON ctx.contextlevel = :contextlevel
                   AND ctx.instanceid = l.course_id
                 WHERE {$usersql}";
        $contextlist->add_from_sql($sql, $params);

        $systemcontext = \context_system::instance();
        $params = [
            'systemcontextid' => $systemcontext->id,
        ];
        $usersql = self::get_user_match_sql($user, $params, 'systemctx');
        $orphanedsql = self::get_system_context_record_sql($params, 'systemctx');

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.id = :systemcontextid
                   AND EXISTS (
                       SELECT 1
                         FROM {local_psaelmsync_logs} l
                        WHERE {$orphanedsql}
                          AND {$usersql}
                   )";
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get users who have plugin data in the supplied context.
     *
     * @param userlist $userlist The userlist to add user IDs to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_course && !$context instanceof \context_system) {
            return;
        }

        $params = [];
        $contextsql = self::get_context_record_sql($context, $params, 'userctx');
        if ($contextsql === null) {
            return;
        }

        $usermatchsql = self::get_user_table_match_sql('u', 'l');
        $sql = "SELECT DISTINCT u.id AS userid
                  FROM {user} u
                  JOIN {local_psaelmsync_logs} l
                    ON {$usermatchsql}
                 WHERE {$contextsql}";
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all user data for the approved context list.
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        if (empty($contextlist->count())) {
            return;
        }

        $user = self::get_user($contextlist->get_user()->id);
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course && !$context instanceof \context_system) {
                continue;
            }

            $records = self::get_records_for_user_in_context($user, $context);
            if (empty($records)) {
                continue;
            }

            $data = (object) [
                'sync_logs' => array_map([self::class, 'format_log_record'], $records),
            ];
            writer::with_context($context)->export_data(
                [get_string('privacy:subcontext:sync_logs', 'local_psaelmsync')],
                $data
            );
        }
    }

    /**
     * Delete all user data in a context.
     *
     * @param \context $context The context to delete data from.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        $params = [];
        $contextsql = self::get_context_record_sql($context, $params, 'deleteall', '');
        if ($contextsql === null) {
            return;
        }

        $DB->delete_records_select('local_psaelmsync_logs', $contextsql, $params);
    }

    /**
     * Delete all user data for the approved context list.
     *
     * @param approved_contextlist $contextlist The approved contexts and user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (empty($contextlist->count())) {
            return;
        }

        $user = self::get_user($contextlist->get_user()->id);
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_records_for_user_in_context($user, $context);
        }
    }

    /**
     * Delete data for multiple users in a single context.
     *
     * @param approved_userlist $userlist The approved user list.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_course && !$context instanceof \context_system) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            self::delete_records_for_user_in_context(self::get_user($userid), $context);
        }
    }

    /**
     * Get a lightweight user record for privacy matching.
     *
     * @param int $userid The user ID.
     * @return \stdClass The user record or a minimal fallback.
     */
    protected static function get_user(int $userid): \stdClass {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'id, email, idnumber', IGNORE_MISSING);
        if ($user) {
            return $user;
        }

        return (object) [
            'id' => $userid,
            'email' => '',
            'idnumber' => '',
        ];
    }

    /**
     * Build SQL for matching a log row to a Moodle user record.
     *
     * @param \stdClass $user The user record.
     * @param array $params SQL parameters to append to.
     * @param string $prefix Parameter prefix.
     * @param string $alias Log table alias.
     * @return string SQL condition.
     */
    protected static function get_user_match_sql(\stdClass $user, array &$params, string $prefix, string $alias = 'l'): string {
        $fieldprefix = self::get_field_prefix($alias);
        $conditions = ["{$fieldprefix}user_id = :{$prefix}userid"];
        $params["{$prefix}userid"] = $user->id;

        if (!empty($user->email)) {
            $conditions[] = "{$fieldprefix}user_email = :{$prefix}email";
            $params["{$prefix}email"] = $user->email;
        }

        if (!empty($user->idnumber)) {
            $conditions[] = "{$fieldprefix}user_guid = :{$prefix}idnumber";
            $params["{$prefix}idnumber"] = $user->idnumber;
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * Build SQL for matching a log row to the user table.
     *
     * @param string $useralias User table alias.
     * @param string $logalias Log table alias.
     * @return string SQL condition.
     */
    protected static function get_user_table_match_sql(string $useralias, string $logalias): string {
        return "({$logalias}.user_id = {$useralias}.id
             OR ({$logalias}.user_email = {$useralias}.email AND {$useralias}.email <> '')
             OR ({$logalias}.user_guid = {$useralias}.idnumber AND {$useralias}.idnumber <> ''))";
    }

    /**
     * Build SQL for selecting log rows that belong to a privacy context.
     *
     * @param \context $context The context.
     * @param array $params SQL parameters to append to.
     * @param string $prefix Parameter prefix.
     * @param string $alias Log table alias.
     * @return string|null SQL condition for alias l, or null if unsupported.
     */
    protected static function get_context_record_sql(
        \context $context,
        array &$params,
        string $prefix,
        string $alias = 'l'
    ): ?string {
        $fieldprefix = self::get_field_prefix($alias);

        if ($context instanceof \context_course) {
            $params["{$prefix}courseid"] = $context->instanceid;
            return "{$fieldprefix}course_id = :{$prefix}courseid";
        }

        if ($context instanceof \context_system) {
            return self::get_system_context_record_sql($params, $prefix, $alias);
        }

        return null;
    }

    /**
     * Build SQL for log rows that should be exposed from the system context.
     *
     * @param array $params SQL parameters to append to.
     * @param string $prefix Parameter prefix.
     * @param string $alias Log table alias.
     * @return string SQL condition for alias l.
     */
    protected static function get_system_context_record_sql(array &$params, string $prefix, string $alias = 'l'): string {
        $fieldprefix = self::get_field_prefix($alias);
        $params["{$prefix}contextlevel"] = CONTEXT_COURSE;

        return "({$fieldprefix}course_id = 0
             OR NOT EXISTS (
                    SELECT 1
                      FROM {context} coursectx
                     WHERE coursectx.contextlevel = :{$prefix}contextlevel
                       AND coursectx.instanceid = {$fieldprefix}course_id
                ))";
    }

    /**
     * Get a SQL field prefix for a table alias.
     *
     * @param string $alias The table alias, or an empty string for no alias.
     * @return string The SQL field prefix.
     */
    protected static function get_field_prefix(string $alias): string {
        return $alias === '' ? '' : "{$alias}.";
    }

    /**
     * Get log records for a user in a context.
     *
     * @param \stdClass $user The user record.
     * @param \context $context The privacy context.
     * @return array Matching log records.
     */
    protected static function get_records_for_user_in_context(\stdClass $user, \context $context): array {
        global $DB;

        $params = [];
        $contextsql = self::get_context_record_sql($context, $params, 'records');
        if ($contextsql === null) {
            return [];
        }

        $usersql = self::get_user_match_sql($user, $params, 'recordsuser');
        $sql = "SELECT l.*
                  FROM {local_psaelmsync_logs} l
                 WHERE {$contextsql}
                   AND {$usersql}
              ORDER BY l.timestamp ASC, l.id ASC";

        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Delete log records for a user in a context.
     *
     * @param \stdClass $user The user record.
     * @param \context $context The privacy context.
     * @return void
     */
    protected static function delete_records_for_user_in_context(\stdClass $user, \context $context): void {
        global $DB;

        $params = [];
        $contextsql = self::get_context_record_sql($context, $params, 'deleteuser', '');
        if ($contextsql === null) {
            return;
        }

        $usersql = self::get_user_match_sql($user, $params, 'deleteuseruser', '');
        $DB->delete_records_select('local_psaelmsync_logs', "{$contextsql} AND {$usersql}", $params);
    }

    /**
     * Format a log record for data export.
     *
     * @param \stdClass $record The database log record.
     * @return \stdClass The formatted export record.
     */
    protected static function format_log_record(\stdClass $record): \stdClass {
        return (object) [
            'id' => (int) $record->id,
            'record_id' => (int) $record->record_id,
            'record_enrol_id' => (int) $record->record_enrol_id,
            'record_date_created' => $record->record_date_created,
            'processed_at' => transform::datetime($record->timestamp),
            'sha256hash' => self::format_hash($record->sha256hash),
            'course_id' => (int) $record->course_id,
            'elm_course_id' => (int) $record->elm_course_id,
            'course_name' => $record->course_name,
            'class_code' => $record->class_code,
            'user_id' => (int) $record->user_id,
            'user_firstname' => $record->user_firstname,
            'user_lastname' => $record->user_lastname,
            'user_guid' => $record->user_guid,
            'user_email' => $record->user_email,
            'oprid' => $record->oprid,
            'person_id' => (int) $record->person_id,
            'activity_id' => (int) $record->activity_id,
            'elm_enrolment_id' => (int) $record->elm_enrolment_id,
            'action' => $record->action,
            'status' => $record->status,
        ];
    }

    /**
     * Format the stored hash for export.
     *
     * @param string $hash The stored hash.
     * @return string A printable hash value.
     */
    protected static function format_hash(string $hash): string {
        if ($hash === '' || ctype_xdigit($hash)) {
            return $hash;
        }

        return bin2hex($hash);
    }
}
