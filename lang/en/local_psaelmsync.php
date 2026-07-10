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
$string['apitest'] = 'API Test';
$string['apitest_cleanup'] = 'Cleanup';
$string['apitest_cleanup_btn'] = 'Delete all test records';
$string['apitest_cleanup_desc'] = 'Deletes all records with @test.gov.bc.ca emails from the test API and resets the local state.';
$string['apitest_generate'] = 'Generate test records';
$string['apitest_generate_desc'] = 'Inserts sample enrolment records into the CData test API endpoint. Each run generates new users enrolled in random courses. Subsequent runs will also generate Suspend records for a sample of previously enrolled users.';
$string['apitest_insert'] = 'Insert records';
$string['apitest_numrecords'] = 'Number of records';
$string['apitest_pooldetail'] = 'Enrolled pool detail';
$string['apitest_poolexplain'] = 'Previously enrolled test users are tracked here. On subsequent runs, a percentage of these will be suspended to simulate learners dropping courses.';
$string['apitest_poolsize'] = '{$a} users in the enrolled pool available for suspend generation.';
$string['apitest_state'] = 'Enrolment pool';
$string['apitest_suspendratio'] = 'Suspend ratio';
$string['apitest_suspendratio_desc'] = 'Percentage of records that will be Suspend actions (drawn from the enrolled pool). Only applies when the pool has users from a previous run.';
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
$string['highwatermark_current'] = 'Current value:';
$string['highwatermark_err_negative'] = 'The high-water mark must be a non-negative integer.';
$string['highwatermark_err_unlock'] = 'You must check the unlock checkbox before changing the high-water mark.';
$string['highwatermark_modify'] = 'I need to modify the high-water mark...';
$string['highwatermark_newvalue'] = 'New high-water mark value';
$string['highwatermark_unlock'] = 'Unlock this field for editing';
$string['highwatermark_warning'] = 'WARNING: This value controls which enrolment records are fetched from the API. Lowering it will cause already-processed records to be re-fetched (they will be deduplicated but the sync will take much longer). Raising it will permanently skip any records between the current and new value. Setting it to 0 will re-process every record from the very beginning. Do not change this unless you fully understand the impact.';
$string['idnumber'] = 'ID Number';
$string['ignorecourseids'] = 'Course ignore list';
$string['ignorecourseids_desc'] = 'Comma-separated list of ELM COURSE_IDENTIFIER values to ignore during enrolment processing (e.g. 12345,87512,98612). Records for these courses will be skipped entirely. Use this for courses destined for a different system.';
$string['intakerunhistory'] = 'Intake Run History';
$string['last_record_enrol_id'] = 'Last record enrol ID (high-water mark)';
$string['last_record_enrol_id_desc'] = 'The most recent record_enrol_id fetched from the API. Only records with an ID greater than this value will be fetched on the next sync run. Set to 0 to re-process all records from the beginning.';
$string['logs'] = 'PSALS Sync Dashboard';
$string['nocourses'] = 'No courses found with completion opt-in enabled.';
$string['noresults'] = 'No data found for the selected dates.';
$string['not_enrolled'] = 'Not Enrolled';
$string['notificationemails'] = 'Alert emails';
$string['notificationemails_desc'] = 'When issues arise with the processing of records, send an alert to these (comma-separated) addresses.';
$string['person_id'] = 'Person ID';
$string['plugindesc'] = 'Read enrolment data posted to CData from ELM and then return completion data back.';
$string['pluginname'] = 'PSALS Sync';
$string['privacy:metadata:cdata_elm'] = 'Learner enrolment and completion data is exchanged with the CData/ELM integration.';
$string['privacy:metadata:cdata_elm:activity_id'] = 'The ELM activity identifier for the class.';
$string['privacy:metadata:cdata_elm:course_complete_date'] = 'The learner course completion date sent to ELM.';
$string['privacy:metadata:cdata_elm:course_identifier'] = 'The ELM course identifier.';
$string['privacy:metadata:cdata_elm:course_long_name'] = 'The course long name.';
$string['privacy:metadata:cdata_elm:course_shortname'] = 'The course short name or class code.';
$string['privacy:metadata:cdata_elm:course_state'] = 'The enrolment or completion state for the learner.';
$string['privacy:metadata:cdata_elm:email'] = 'The learner email address.';
$string['privacy:metadata:cdata_elm:enrolment_id'] = 'The ELM enrolment identifier.';
$string['privacy:metadata:cdata_elm:first_name'] = 'The learner first name.';
$string['privacy:metadata:cdata_elm:guid'] = 'The learner GUID.';
$string['privacy:metadata:cdata_elm:last_name'] = 'The learner last name.';
$string['privacy:metadata:cdata_elm:oprid'] = 'The learner IDIR or OPRID identifier.';
$string['privacy:metadata:cdata_elm:person_id'] = 'The learner government Person ID.';
$string['privacy:metadata:cdata_elm:user_effective_date'] = 'The learner effective date sent to ELM.';
$string['privacy:metadata:cdata_elm:user_state'] = 'The learner state sent to ELM.';
$string['privacy:metadata:local_psaelmsync_logs'] = 'Stores learner sync log records for enrolment, suspension, completion, and error handling.';
$string['privacy:metadata:local_psaelmsync_logs:action'] = 'The sync action taken for the learner.';
$string['privacy:metadata:local_psaelmsync_logs:activity_id'] = 'The ELM activity identifier for the class.';
$string['privacy:metadata:local_psaelmsync_logs:class_code'] = 'The course short name or class code.';
$string['privacy:metadata:local_psaelmsync_logs:course_id'] = 'The Moodle course ID related to the sync record.';
$string['privacy:metadata:local_psaelmsync_logs:course_name'] = 'The Moodle course name related to the sync record.';
$string['privacy:metadata:local_psaelmsync_logs:elm_course_id'] = 'The ELM course identifier.';
$string['privacy:metadata:local_psaelmsync_logs:elm_enrolment_id'] = 'The ELM enrolment identifier.';
$string['privacy:metadata:local_psaelmsync_logs:oprid'] = 'The learner IDIR or OPRID identifier.';
$string['privacy:metadata:local_psaelmsync_logs:person_id'] = 'The learner government Person ID.';
$string['privacy:metadata:local_psaelmsync_logs:record_date_created'] = 'The date and time the source ELM record was created.';
$string['privacy:metadata:local_psaelmsync_logs:record_enrol_id'] = 'The sequential ELM enrolment feed record ID.';
$string['privacy:metadata:local_psaelmsync_logs:record_id'] = 'The generated sync record identifier.';
$string['privacy:metadata:local_psaelmsync_logs:sha256hash'] = 'A hash used to identify the original ELM enrolment record.';
$string['privacy:metadata:local_psaelmsync_logs:status'] = 'The result status of the sync action.';
$string['privacy:metadata:local_psaelmsync_logs:timestamp'] = 'The time the sync action was processed in Moodle.';
$string['privacy:metadata:local_psaelmsync_logs:user_email'] = 'The learner email address.';
$string['privacy:metadata:local_psaelmsync_logs:user_firstname'] = 'The learner first name.';
$string['privacy:metadata:local_psaelmsync_logs:user_guid'] = 'The learner GUID.';
$string['privacy:metadata:local_psaelmsync_logs:user_id'] = 'The Moodle user ID related to the sync record.';
$string['privacy:metadata:local_psaelmsync_logs:user_lastname'] = 'The learner last name.';
$string['privacy:subcontext:sync_logs'] = 'PSALS sync logs';
$string['process_course_completion'] = 'Process course completion.';
$string['psaelmsync:viewlogs'] = 'View Dashboards';
$string['queryapi'] = 'Manual Processing';
$string['record_date_created'] = 'CData Created';
$string['record_enrol_id'] = 'Record Enrol ID';
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
