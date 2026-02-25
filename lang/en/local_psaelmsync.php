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
 * Language strings for the PSA ELM Sync plugin.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action'] = 'Action';
$string['already_enrolled'] = 'Already Enrolled';
$string['apitoken'] = 'API Token';
$string['apitoken_desc'] = 'API Token for CData access. A Base64 encoded value of the username and password.';
$string['apiurl'] = 'Enrolment API URL';
$string['apiurl_desc'] = 'CData Endpoint for enrolments.';
$string['completion_apitoken'] = 'Completion API Token';
$string['completion_apitoken_desc'] = 'API Token for CData access. A Base64 encoded value of the username and password.';
$string['completion_apiurl'] = 'Completion API URL';
$string['completion_apiurl_desc'] = 'CData Endpoint for completions.';
$string['completion_opt_in'] = 'Course Completion';
$string['completions'] = 'Completions';
$string['course'] = 'Course';
$string['course_id'] = 'Course ID';
$string['course_name'] = 'Course Name';
$string['courseenrolstats'] = 'Course Enrolment Statistics';
$string['datefilterminutes'] = 'Rolling window minutes';
$string['datefilterminutes_desc'] = 'Only query for records newer than N minutes from time of intake.';
$string['elm_course_id'] = 'ELM Course ID';
$string['elm_enrolment_id'] = 'ELM Enrolment ID';
$string['email_cdata_lookup'] = 'Email';
$string['enabled'] = 'Enable PSA Enrol Sync';
$string['enabled_desc'] = 'Enable or disable the PSA Enrol Sync scheduled task.';
$string['enrolled'] = 'Enrolled';
$string['enrolments'] = 'Enrolments';
$string['errors'] = 'Errors';
$string['from'] = 'From Date';
$string['guid_cdata_lookup'] = 'GUID';
$string['idnumber'] = 'ID Number';
$string['ignorecourseids'] = 'Course ignore list';
$string['ignorecourseids_desc'] = 'Comma-separated list of ELM COURSE_IDENTIFIER values to ignore during enrolment processing (e.g. 12345,87512,98612). Records for these courses will be skipped entirely without hashing or further checks. Use this for courses destined for a different system.';
$string['intakerunhistory'] = 'Intake Run History';
$string['logs'] = 'PSALS Sync Dashboard';
$string['nocourses'] = 'No courses found with completion opt-in enabled.';
$string['noresults'] = 'No data found for the selected dates.';
$string['not_enrolled'] = 'Not Enrolled';
$string['notificationemails'] = 'Alert emails';
$string['notificationemails_desc'] = 'When issues arise with the processing of records, send an alert to these (comma-separated) addresses.';
$string['notificationhours'] = 'Hours without processing a record';
$string['notificationhours_desc'] = 'After each run we check to see how long it\'s been since we last processed an enrol or suspend. If it\'s been longer than this value it\'ll send a notification to \'notificationemails\'';
$string['person_id'] = 'Person ID';
$string['plugindesc'] = 'Read enrolment data posted to CData from ELM and then return completion data back.';
$string['pluginname'] = 'PSALS Sync';
$string['process_course_completion'] = 'Process course completion.';
$string['psaelmsync:viewlogs'] = 'View Dashboards';
$string['queryapi'] = 'Manual Processing';
$string['record_date_created'] = 'CData Created';
$string['record_id'] = 'Record ID';
$string['search'] = 'Search';
$string['status'] = 'Status';
$string['submit'] = 'Submit';
$string['suspended'] = 'Suspend';
$string['suspends'] = 'Suspends';
$string['sync_task'] = 'PSA Enrolment Intake Task';
$string['syncenrolments'] = 'Sync Enrolments';
$string['timestamp'] = 'Timestamp';
$string['to'] = 'To Date';
$string['trigger_sync'] = 'Trigger Enrolment Intake';
$string['user_details'] = 'User Details';
$string['user_email'] = 'Email';
$string['user_guid'] = 'GUID';
$string['user_id'] = 'User ID';
$string['user_lastname'] = 'Name';
$string['viewintake'] = 'View Intake Dashboard';
$string['viewlogs'] = 'View Learner Dashboard';
