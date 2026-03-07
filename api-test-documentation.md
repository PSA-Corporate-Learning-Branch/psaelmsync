# API Test Page

The API Test page (`api-test.php`) is an admin tool for generating sample enrolment and suspension records in the CData API. It is used to populate the API with realistic test data so the sync task can be exercised without waiting for real ELM records.

## Access

Navigate to **PSALS Sync > API Test** in the plugin's tabbed navigation, or go directly to `/local/psaelmsync/api-test.php`. You must be logged in and hold the `local/psaelmsync:viewlogs` capability.

## Configuration

The page uses the **Enrolment API URL** and **API Token** from the plugin's admin settings (`/admin/settings.php?section=local_psaelmsync`). Point these at your test CData endpoint before using this tool.

A production safety check is built in: if the configured API URL does not contain the word "test", a warning banner is displayed and confirmation dialogs require extra acknowledgement.

## Features

### Generate Test Records

Inserts a configurable number of enrolment records into the CData API. Each record represents a fake user being enrolled in a real Moodle course (selected randomly from courses that have an ELM course ID set in their `idnumber` field).

- **Number of records** — How many total records to insert (1–500).
- **Suspend ratio** — What percentage of the total should be Suspend actions rather than Enrol actions. Suspend records are drawn from the enrolled pool (see below), so this only takes effect after at least one prior run has built up the pool.

Fake users are generated with randomised names, a `@test.gov.bc.ca` email address, a random GUID, and sequential person/enrolment IDs.

### Enrolment Pool

Successfully enrolled test users are saved to a local state file (`.apitest_state.json`, git-ignored). On subsequent runs, the pool lets the tool generate Suspend records for users who were previously enrolled, simulating learners dropping courses.

The pool size and its contents are displayed on the page. Users are removed from the pool when they are suspended.

### Cleanup

The cleanup action:

1. Queries the CData API for all records with a `@test.gov.bc.ca` email address.
2. Deletes each matching record via the API.
3. Removes the local state file, resetting the enrolment pool.

## Typical Workflow

1. Configure the plugin settings to point at your **test** CData endpoint.
2. Open the API Test page and generate a batch of records (e.g. 25).
3. Run the sync task (`sync_task`) manually or wait for the scheduled run — the generated records will be pulled in and processed like real enrolments.
4. Generate more batches to test suspend handling (the suspend ratio will draw from the pool of previously enrolled users).
5. When finished, use **Cleanup** to remove all test records from the API.
