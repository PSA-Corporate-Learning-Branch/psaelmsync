# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PSALS Sync is a Moodle 3.11+ (tested to 4.3) local plugin that synchronizes course enrollment data between an external ELM (Enterprise Learning Management) system and Moodle via CData integration. It:

- Ingests enrollment/suspension records from CData API
- Creates users and enrolls them in courses
- Sends course completion data back to ELM
- Provides admin dashboards for monitoring sync operations

**Requirements:** PHP 7.4+ (tested to 8.2), Moodle 3.11+

## Commands

No build process required - this is a standard Moodle plugin.

**Run scheduled tasks manually:**
```bash
php /path/to/moodle/admin/cli/scheduled_task.php --execute=\\local_psaelmsync\\task\\sync_task
php /path/to/moodle/admin/cli/scheduled_task.php --execute=\\local_psaelmsync\\task\\process_course_completion
```

**Trigger sync via web:**
- Manual intake: `/local/psaelmsync/manual-intake.php`
- Manual completion: `/local/psaelmsync/manual-complete.php`
- External trigger: `/local/psaelmsync/trigger_sync.php`

## Architecture

### Core Files

- `lib.php` - Main sync logic including `local_psaelmsync_sync()` orchestrator, user creation, enrollment processing, email notifications
- `classes/observer.php` - Event listener that posts completion data when courses are completed
- `classes/task/sync_task.php` - Scheduled task for enrollment intake (runs every 10 min, 6AM-6PM weekdays)
- `classes/task/process_course_completion.php` - Scheduled task for completion processing

### Database Tables

- `local_psaelmsync_logs` - Log of every enrollment/suspension/completion with `record_enrol_id` for high-water mark tracking
- `local_psaelmsync_runs` - Summary statistics for each sync run

### Key Workflows

1. **Enrollment Intake:** Read high-water mark → fetch new records from CData API (record_enrol_id > last) → lookup/create user → enroll/suspend → send welcome email → log result → update high-water mark
2. **Completion Reporting:** Course completed event → check completion_opt_in field → find enrolment record → POST to completion API → log result

### Admin Dashboards

- `dashboard.php` - Searchable log viewer (all sync entries)
- `dashboard-courses.php` - Per-course enrollment statistics
- `dashboard-intake.php` - Intake run history

### Configuration

Admin settings at `/admin/settings.php?section=local_psaelmsync`:
- `apiurl` / `apitoken` - CData enrollment intake endpoint
- `completion_apiurl` / `completion_apitoken` - CData completion endpoint
- `notificationemails` - Admin emails for error alerts
- `last_record_enrol_id` - High-water mark (auto-managed, not in admin UI)

## Important Conventions

- **High-water mark:** Uses `record_enrol_id` (sequential API field) stored in plugin config to only fetch new records; SHA256 hash still computed and stored for transition auditing but no longer queried
- **User lookup:** Users matched by GUID stored in `user.idnumber`
- **Course lookup:** Courses matched by ELM course ID stored in `course.idnumber`
- **User creation:** Uses OAuth2 authentication (`auth = 'oauth2'`)
- **Enrollment method:** Manual enrollment plugin
- **Timestamps:** Mix of Unix timestamps (code) and ISO8601 strings (CData API)
- **Email mismatch handling:** If GUID exists but email differs, processing is blocked and admins are alerted

## Event Integration

Listens to `\core\event\course_completed` via `classes/observer.php`. Completion data is only sent for courses with custom field `completion_opt_in = 1`.
