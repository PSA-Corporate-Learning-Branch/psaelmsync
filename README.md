# PSALS Sync

A Moodle local plugin that synchronizes course enrollment data between an external ELM (Enterprise Learning Management) system and Moodle LMS via CData integration.

## Requirements

- Moodle 3.11+ (tested up to 4.5)
- PHP 7.4+ (tested up to 8.4)

## Features

- **Enrollment Intake:** Processes enrollment and suspension records from CData API
- **User Provisioning:** Automatically creates new users when they don't exist in Moodle
- **Completion Reporting:** Sends course completion data back to ELM when learners complete courses
- **Deduplication:** SHA256 hash-based deduplication prevents reprocessing identical records
- **Admin Dashboards:** Searchable interfaces for monitoring all sync operations
- **Error Notifications:** Emails administrators when problems occur
- **Feed Monitoring:** Detects and alerts when the enrollment feed appears blocked

## Installation

1. Copy the plugin to `/local/psaelmsync/` in your Moodle installation
2. Visit Site Administration → Notifications to complete the installation
3. Configure the plugin settings (see Configuration below)

## Configuration

Navigate to **Site Administration → Plugins → Local plugins → PSALS Sync**

| Setting | Description |
|---------|-------------|
| **Enabled** | Enable/disable the sync scheduled task |
| **API URL** | CData endpoint URL for enrollment intake |
| **API Token** | Base64-encoded authentication token for intake API |
| **Date Filter Minutes** | Lookback window for API queries (default: 120 minutes) |
| **Completion API URL** | CData endpoint URL for posting completion data |
| **Completion API Token** | Authentication token for completion API |
| **Notification Emails** | Comma-separated admin emails for error alerts |
| **Notification Hours** | Hours of inactivity before alerting admins (default: 1) |

## How It Works

### Enrollment Intake

The scheduled task runs every 10 minutes during business hours (6 AM - 6 PM weekdays):

1. Fetches records from CData API using a rolling time window
2. For each record:
   - Generates SHA256 hash for deduplication
   - Looks up course by `idnumber` (ELM course ID)
   - Looks up user by `idnumber` (GUID)
   - Creates user if not found (using OAuth2 auth)
   - Enrolls or suspends user in course
   - Sends welcome email for new enrollments
   - Logs the result

### Completion Reporting

When a learner completes a course:

1. Moodle fires the `\core\event\course_completed` event
2. Plugin checks if course has `completion_opt_in` custom field set to 1
3. Looks up the original enrollment record
4. POSTs completion data to the completion API
5. Logs success or failure

### User Matching

- Users are matched by GUID stored in the Moodle `user.idnumber` field
- If GUID exists but email differs, processing is blocked and admins are notified (indicates potential account mismatch)

### Course Matching

- Courses are matched by ELM course ID stored in the Moodle `course.idnumber` field

## Admin Dashboards

Access dashboards from the plugin settings page or directly:

- **Learner Dashboard** (`/local/psaelmsync/dashboard.php`) - Searchable log of all sync entries with filters for timestamp, status, course, user, GUID, email, and action
- **Course Dashboard** (`/local/psaelmsync/dashboard-courses.php`) - Per-course enrollment statistics
- **Intake History** (`/local/psaelmsync/dashboard-intake.php`) - Run-by-run statistics showing records processed, enrolled, suspended, skipped, and errors

## Manual Operations

- **Manual Intake** (`/local/psaelmsync/manual-intake.php`) - Trigger enrollment sync manually
- **Manual Completion** (`/local/psaelmsync/manual-complete.php`) - Trigger completion processing manually
- **External Trigger** (`/local/psaelmsync/trigger_sync.php`) - Endpoint for external cron systems

## Database Tables

### local_psaelmsync_logs

Comprehensive log of every enrollment, suspension, and completion attempt.

| Field | Description |
|-------|-------------|
| sha256hash | Deduplication hash |
| record_id | Unique record ID from CData |
| record_date_created | ISO8601 timestamp from CData |
| course_id | Moodle course ID |
| elm_course_id | External ELM course ID |
| course_name | Course full name |
| class_code | Course shortname |
| user_id | Moodle user ID |
| user_firstname, user_lastname | User name from ELM |
| user_guid | External user identifier |
| user_email | User email |
| elm_enrolment_id | ELM enrollment record ID |
| action | Enrol, Suspend, Complete, or Imported |
| status | Success or Error |
| timestamp | Unix timestamp of processing |

### local_psaelmsync_runs

Summary statistics for each sync run.

| Field | Description |
|-------|-------------|
| apiurl | Exact API URL called |
| starttime, endtime | Unix timestamps |
| recordcount | Total records from API |
| enrolcount | Successful enrollments |
| suspendcount | Successful suspensions |
| errorcount | Failed operations |
| skippedcount | Duplicates skipped |

## Capabilities

- `local/psaelmsync:viewlogs` - Required to access admin dashboards

## Running Tasks via CLI

```bash
# Run enrollment intake
php admin/cli/scheduled_task.php --execute=\\local_psaelmsync\\task\\sync_task

# Run completion processing
php admin/cli/scheduled_task.php --execute=\\local_psaelmsync\\task\\process_course_completion
```

## Troubleshooting

### No enrollments processing

1. Check if the plugin is enabled in settings
2. Verify API URL and token are correct
3. Check the Intake History dashboard for error counts
4. Review Moodle logs for API connection errors

### Users not being created

- New users require OAuth2 authentication to be configured
- Check notification emails for user creation failures

### Completions not reporting

1. Verify the course has `completion_opt_in` custom field set to 1
2. Check the completion API URL and token
3. Review the dashboard for completion errors

### Email mismatch errors

This occurs when a GUID exists in Moodle but with a different email than what ELM is sending. This typically indicates an account that was recreated in ELM with a new GUID. Manual intervention is required to resolve.

## Version History

- **1.4** - Current stable release
- Initial deployment: September 3, 2024
- Historical import: ~8,400 existing enrollments imported September 4, 2024 with action "Imported"

## License

This plugin is licensed under the GNU GPL v3 or later.
