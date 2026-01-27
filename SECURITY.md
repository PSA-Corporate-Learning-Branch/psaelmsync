# Security Review Report: PSA ELM Sync Moodle Plugin

**Review Date:** January 2025
**Plugin Path:** `/local/psaelmsync/`

## Executive Summary

This security review analyzed the PSA ELM Sync Moodle plugin which synchronizes enrollment data between an external ELM (Enterprise Learning Management) system and Moodle via CData integration. The plugin generally follows Moodle security best practices for authentication, authorization, and CSRF protection. No critical vulnerabilities were found.

## Summary of Findings

| Severity | Count | Status |
|----------|-------|--------|
| Critical | 0 | None |
| High | 0 | None |
| Medium | 4 | 3 FIXED, 1 Acknowledged |
| Low | 7 | 3 FIXED, 4 Acknowledged |

### Fixed Issues (January 2025)
- **FIXED:** XSS in feedback output - User data now escaped with `s()` when building feedback messages
- **FIXED:** `exit()` in observer - Replaced with `return` to avoid terminating Moodle request
- **FIXED:** PARAM_RAW for search - Changed to PARAM_TEXT in dashboard.php
- **FIXED:** API token display - Changed to `admin_setting_configpasswordunmask` in settings.php

---

## Detailed Findings

### 1. SQL Injection Vulnerabilities

**Assessment: GOOD - No Issues Found**

All database queries use Moodle's parameterized query methods properly:
- `$DB->get_record()` with array parameters
- `$DB->get_records_sql()` with bound parameters
- `$DB->sql_like()` for LIKE queries
- `$DB->insert_record()` with object/array data

---

### 2. XSS (Cross-Site Scripting) Vulnerabilities

**Assessment: MOSTLY GOOD with some concerns**

#### Issue 2.1: Raw Feedback Output with Potential User Data
- **File:** `manual-intake.php`, lines 791-796
- **Severity:** Medium
- **Description:** The `$feedback` variable is output with `echo $feedback;` and contains user-controlled data that may not be properly escaped.
- **Fix:** Use `htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8')` when outputting feedback.

#### Issue 2.2: Raw Feedback Output in manual-complete.php
- **File:** `manual-complete.php`, lines 537-542
- **Severity:** Medium
- **Description:** Same pattern as above.

#### Issue 2.3: Chart Data JSON Injection Risk
- **File:** `dashboard-intake.php`, line 80
- **Severity:** Low
- **Description:** JSON data output directly into JavaScript without additional encoding flags.
- **Fix:** Use `json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`

---

### 3. CSRF Protection

**Assessment: GOOD - No Issues Found**

All form handlers properly check for sesskey:
- `manual-intake.php`: `require_sesskey();` for single and bulk processing
- `manual-complete.php`: `require_sesskey();`
- All forms include hidden sesskey field

---

### 4. Authentication/Authorization

**Assessment: GOOD - No Issues Found**

All pages properly implement:
- `require_login()` at the top of all user-facing pages
- Capability checks requiring `local/psaelmsync:viewlogs`
- Capability restricted to admin role only in `db/access.php`

---

### 5. Input Validation

**Assessment: MOSTLY GOOD with minor concerns**

#### Issue 5.1: PARAM_RAW Usage for Search
- **File:** `dashboard.php`, line 12
- **Severity:** Low
- **Description:** Search parameter uses `PARAM_RAW` instead of `PARAM_TEXT` or `PARAM_NOTAGS`.

#### Issue 5.2: PARAM_TEXT for Email Fields
- **File:** `manual-intake.php`, lines 37-46
- **Severity:** Low
- **Description:** Email filter uses `PARAM_TEXT` instead of `PARAM_EMAIL`.

---

### 6. API Security

**Assessment: ACCEPTABLE with recommendations**

#### Issue 6.1: API Token Storage Display
- **File:** `settings.php`, lines 21-24, 42-46
- **Severity:** Low
- **Description:** API tokens stored with `admin_setting_configtext` which displays the value.
- **Fix:** Use `admin_setting_configpasswordunmask` to hide token in UI.

#### Issue 6.2: API URL Exposed in Debug Output
- **File:** `manual-intake.php`, lines 901-908
- **Severity:** Medium
- **Description:** Full API URL displayed to admin users in query debug section.

**Positive:** API tokens sent via HTTP headers, not URL parameters.

---

### 7. Sensitive Data Exposure

**Assessment: MOSTLY GOOD with minor concerns**

#### Issue 7.1: Error Messages Expose Internal Details
- **File:** `manual-intake.php`, lines 544, 552
- **Severity:** Low
- **Description:** API error responses shown to users could expose internal API structure.

#### Issue 7.2: Debug Information in mtrace
- **File:** `lib.php`, line 50
- **Severity:** Low
- **Description:** Raw API response printed via mtrace.

#### Issue 7.3: Notification Emails Contain Detailed Error Info
- **File:** `classes/observer.php`, lines 199-207
- **Severity:** Low
- **Description:** Error emails include API URL, payload, and response body. Ensure recipients are trusted.

**Positive:** No credentials hardcoded; passwords properly hashed.

---

### 8. File Operations

**Assessment: GOOD - No Issues Found**

No file operations beyond standard PHP includes using static paths.

---

### 9. Additional Security Concerns

#### Issue 9.1: Use of exit() in Observer
- **File:** `classes/observer.php`, line 101
- **Severity:** Medium
- **Description:** Using `exit;` terminates the entire Moodle request, potentially breaking other observers.
- **Fix:** Replace `exit;` with `return;`

#### Issue 9.2: Bulk Processing Data Integrity
- **File:** `manual-intake.php`, lines 327, 333
- **Severity:** Medium
- **Description:** Bulk processing decodes base64-encoded JSON from form data. An admin could manipulate encoded data.
- **Fix:** Consider adding HMAC signature to prevent tampering.

#### Issue 9.3: Direct Native cURL Usage
- **File:** `manual-complete.php`, `classes/observer.php`
- **Severity:** Low
- **Description:** Uses native `curl_init()` instead of Moodle's curl wrapper.
- **Fix:** Use Moodle's `curl` class for consistency with proxy settings.

---

## Recommendations Priority List

### High Priority
1. Replace `exit;` with `return;` in observer.php (line 101)
2. Escape feedback output with `htmlspecialchars()` in manual-intake.php and manual-complete.php

### Medium Priority
3. Use `PARAM_TEXT` instead of `PARAM_RAW` for search parameter
4. Use `admin_setting_configpasswordunmask` for API tokens in settings.php
5. Use Moodle's curl class consistently across all files

### Low Priority
6. Add JSON encoding flags for JavaScript output
7. Log detailed errors server-side instead of showing to users
8. Use `PARAM_EMAIL` for email filter fields

---

## Conclusion

The PSA ELM Sync plugin demonstrates good security practices overall. It properly implements Moodle's authentication, authorization, CSRF protection, and parameterized queries. The main concerns are around output escaping for dynamic feedback messages and the inappropriate use of `exit()` in an event observer. **No critical vulnerabilities such as SQL injection or authentication bypasses were found.**
