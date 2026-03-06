# psaelmsync: High-Water Mark Proposal

## Background

The plugin currently uses two strategies for managing enrolment intake:

1. **Rolling window** -- only call in records for the past N minutes (`datefilterminutes` setting)
2. **SHA256 hash deduplication** -- hash several fields together to form a unique ID and check for that ID on each run to avoid reprocessing

The hash was introduced because the API records did not originally have a unique ID. The API now provides `record_enrol_id`, a sequential field unique to each record.

The current process handles about 4-6 records per second and has worked reasonably well for ~30k records so far.

## Proposed Change

Replace both the rolling window and hash deduplication with a **high-water mark** pattern:

1. At the start of each sync run, retrieve the last-seen `record_enrol_id` from plugin config.
2. Call the API with a filter: `record_enrol_id > last_processed_id`.
3. Process only the returned records (which are guaranteed to be new).
4. At the end of the run, store the highest `record_enrol_id` from the batch back to plugin config.

This eliminates the rolling window and the per-record hash lookup entirely.

## What We Gain

### 1. Eliminates the rolling window

No more remembering to adjust `datefilterminutes` after downtime. System goes down for a week? Doesn't matter -- you pick up right where you left off.

### 2. Eliminates the per-record hash lookup

This is the biggest performance win. Currently every record in the feed hits the DB for a hash check (`lib.php:217-222`). With 30k records and growing, that's a `SELECT` query per record. The high-water mark replaces all of them with a single config read at the start of each run.

### 3. API returns only new records

Currently the rolling window returns records already processed, which are then skipped after the hash check. With `record_enrol_id > X`, the API only sends genuinely new records. Less data over the wire, less processing.

### 4. Simpler code

Can remove:

- Hash generation (`lib.php:210-212`)
- Hash lookup (`lib.php:217-228`)
- `datefilterminutes` admin setting
- The `skippedcount` metric largely goes away (aside from ignored courses)

## Is There Any Benefit to Keeping the Status Quo?

No. The rolling window is a liability, not a feature. The hash works but it's doing expensive work (a DB query per record) to solve a problem that `record_enrol_id` solves inherently.

The only thing the current approach gives you is a narrow re-catch window for records that were previously filtered out at the API or application level (e.g., a record that was `USER_STATE = INACTIVE` when first seen but later flipped to `ACTIVE`). But this is accidental rather than intentional, and only works if the record is still within the rolling window when it changes -- not a reliable safety net.

## Edge Cases and Failure Modes

Even assuming `record_enrol_id` is strictly sequential, there are real edge cases to consider. Roughly ordered by likelihood of actually biting us:

### 1. The `USER_STATE eq 'ACTIVE'` filter creates invisible gaps (IMPORTANT)

This is the most significant edge case. The current API query includes:

```
&$filter=...+and+USER_STATE+eq+%27ACTIVE%27
```

Say the API has these records:

| record_enrol_id | USER_STATE |
|-----------------|------------|
| 500             | ACTIVE     |
| 501             | INACTIVE   |
| 502             | ACTIVE     |

The API returns 500 and 502 (501 is filtered out server-side). We process both, the high-water mark becomes 502. If record 501's `USER_STATE` later changes to `ACTIVE`, we'll never see it -- it's below the high-water mark.

With the rolling window, we'd re-query that time range and pick up 501 on a later run (if still within the window).

**How likely is this?** Depends on whether `USER_STATE` ever changes on existing records, and whether `INACTIVE` records are common. If `INACTIVE` is rare or never flips to `ACTIVE`, this is a non-issue.

**Action required:** Confirm with the CData/ELM team: do records with `USER_STATE = INACTIVE` ever flip to `ACTIVE`? If yes, consider either dropping the filter from the API query (and filtering application-side after advancing the cursor) or accept the small risk.

### 2. The ignore list creates unlogged gaps

In `lib.php:188-190`:

```php
if (local_psaelmsync_is_ignored_course($elmcourseid)) {
    return 'Skipped';
}
```

This returns early **without logging the record**. If the high-water mark is derived from `MAX(record_enrol_id) FROM local_psaelmsync_logs`, and an ignored record sits between two processed ones, the mark advances past it via the higher record. If that course is later removed from the ignore list, those old records are gone.

With the rolling window, they'd get another chance on subsequent runs (if still in the window).

**How likely is this?** Low. You'd have to both ignore a course AND later un-ignore it AND care about the historical records.

**Mitigation:** Store the high-water mark in plugin config rather than deriving it from the logs table (see recommendation below). This way "what we've seen" is decoupled from "what we've logged," and this edge case is eliminated -- the cursor advances past all records in the batch regardless of whether they were logged.

### 3. Deriving the mark from the logs table vs. storing it explicitly

If you use `SELECT MAX(record_enrol_id) FROM local_psaelmsync_logs`, you're coupling the cursor to what was *logged*, not what was *seen*. Any record that's seen but not logged (ignored courses, potential future skip conditions) creates a subtle gap.

**Recommendation:** Store the high-water mark as a plugin config value instead:

```php
// Read at start of run
$lastid = get_config('local_psaelmsync', 'last_record_enrol_id') ?? 0;

// ... process records, track the max record_enrol_id seen ...

// Write at end of run
set_config('last_record_enrol_id', $maxid_from_batch, 'local_psaelmsync');
```

This decouples "what we've seen" from "what we've logged" and eliminates edge case 2 entirely.

### 4. API write concurrency (unlikely but real)

Even with strictly sequential IDs, database transactions matter. If the source system assigns ID 501 in transaction A and ID 502 in transaction B, and transaction B commits first, the API might momentarily show 500 and 502 but not 501. We'd set the mark to 502 and miss 501 when it commits.

**How likely is this?** Very unlikely with typical enrollment workflows (not high-concurrency writes). The window for this race is tiny -- the next run would need to happen during the exact moment the transaction is in flight.

### 5. Table truncation / data loss (operational)

If someone truncates `local_psaelmsync_logs`, a mark derived from the logs table would reset to 0, causing ALL records to be reprocessed. This is actually *more* resilient than the rolling window (which would only recover records within the window). The enrolment code already checks `alreadyenrolled` before sending welcome emails (`lib.php:450-454`), so reprocessing is mostly safe -- just slow.

If the mark is stored in plugin config (recommendation above), a table truncation wouldn't affect the cursor at all.

## Transition Strategy: Keep the Hash as a Safety Net

During the transition:

- Switch the primary dedup strategy to the high-water mark
- Keep computing and storing the hash but **don't query it on every record**
- This allows auditing whether the new approach misses anything the hash would have caught

Once confident after a few weeks, remove the hash entirely.

## What the Code Change Looks Like

The core of `local_psaelmsync_sync()` would change from:

```php
// Current: rolling window
$mins = '-' . $datefilter . ' minutes';
$timeminusmins = date('Y-m-d H:i:s', strtotime($mins));
$encodedtime = urlencode($timeminusmins);
$apiurlfiltered = $apiurl
    . '?%24orderby=COURSE_STATE_DATE,date_created+asc';
$apiurlfiltered .= '&%24filter=date_created+gt+%27'
    . $encodedtime
    . '%27+and+USER_STATE+eq+%27ACTIVE%27';
```

To:

```php
// New: high-water mark
$lastid = get_config('local_psaelmsync', 'last_record_enrol_id') ?? 0;
$apiurlfiltered = $apiurl
    . '?%24orderby=record_enrol_id+asc'
    . '&%24filter=record_enrol_id+gt+' . $lastid
    . '+and+USER_STATE+eq+%27ACTIVE%27';
```

At the end of the run, after processing all records:

```php
// Store the high-water mark from the batch (not the logs table)
if (!empty($maxrecordenrolid)) {
    set_config('last_record_enrol_id', $maxrecordenrolid, 'local_psaelmsync');
}
```

And in `process_enrolment_record()`, drop the hash check block entirely and store the `record_enrol_id` from the API record.

## Conclusion

The high-water mark pattern is simpler, faster, and more resilient than rolling window + hash dedup. It's a well-established pattern (sometimes called a cursor or checkpoint) and the simplicity is the point.

**The only action item before proceeding:** Confirm with the CData/ELM team whether records with `USER_STATE = INACTIVE` ever flip to `ACTIVE`. If they do, either drop the `USER_STATE` filter from the API query (filtering application-side instead) or accept the risk. Everything else is either very unlikely or mitigated by storing the mark in plugin config.
