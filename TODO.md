# Pending Improvements for ChurchTools WP Calendar Sync

This file contains identified issues and improvements from code review that
should be addressed in future updates.

> Items reference functions by name rather than fixed line numbers, since line
> numbers shift as the code changes. See `CHANGELOG.md` for the full release
> history.

## Completed (2026-07-14)
- [x] Security hardening (v1.3.1–1.3.4) - stored XSS prevention in event
  description/title (`wp_kses_post` / `sanitize_text_field`), image download
  restricted to real image types, SSRF hardening of admin AJAX URL params,
  logs moved to a hardened `uploads/ctwpsync-logs/` dir with unguessable name,
  `CTWPSYNC_DEBUG`-gated debug/library logging
- [x] Log rotation - log file rotates at 5 MB, keeping one previous generation
- [x] Dependency CVEs (v1.3.5) - updated Guzzle stack (guzzle 7.14.1,
  psr7 2.12.5, promises 2.5.1); `composer audit` reports no advisories
- [x] Metadata (v1.3.6, pending release) - `composer.json` PHP constraint
  `^8.1` → `^8.2` to match the plugin header; `Tested up to` 6.3.1 → 7.0.1
- [x] Cron event duplication fix (v1.3.3) - `wp_unschedule_hook()` removes
  events scheduled with args; user ID instead of `WP_User` object in event
  args; self-healing migration for legacy/duplicate events
- [x] Build wordpress package via github actions
- [x] Settings link from the plugins list (`plugin_action_links`)

## Completed (2026-02-04)
- [x] AJAX with saved token - Fixed Validate Connection, Load Calendars, Load Resource Types buttons to work with saved API token
- [x] Logging improvements - Replaced serialize() with json_encode() and formatted output
- [x] DateTime validation - Added checks for invalid date formats from createFromFormat()
- [x] Session destruction removed - Removed problematic session_destroy() from catch block
- [x] Input validation - Added URL validation, range clamping for import days, calendar ID validation
- [x] Error handling for external API calls - Added try-catch for AppointmentRequest, CombinedAppointmentRequest, FileRequest
- [x] File download error handling - Added error checking for file_get_contents calls

## Completed (2026-02-03)
- [x] SQL Injection vulnerabilities - Fixed with prepared statements
- [x] XSS vulnerabilities - Fixed with proper escaping
- [x] CSRF protection - Added nonce verification
- [x] API token exposure - Token no longer in HTML source
- [x] Capability checks - Added manage_options check
- [x] Connection testing error logging - Added error_log() calls to AJAX callbacks with [ChurchTools Sync] prefix
- [x] UI error tooltips - Error indicators now show detailed messages on hover
- [x] PHP 8.2 return type fixes - Fixed nullable return type violations in migration functions
- [x] Options validation - Added check for false/non-array options before SyncConfig::fromOptions()

---

## High Priority

### Reduce the number of ChurchTools API calls per sync
**File:** `churchtools-dosync.php` — `ctwpsync_getUpdatedCalendarEvents()` /
`processCalendarEntry()`

**Issue:** A full sync makes a large burst of API requests: the paginated
`AppointmentRequest::forCalendars()` fetch, plus **per appointment** a
`CombinedAppointmentRequest::forAppointment()` call, a `FileRequest::forEvent()`
call, and an image/flyer download. Over a wide window (e.g. `import_future` of
~13 months) across several calendars this is easily hundreds–thousands of calls
in one run. On rate-limited ChurchTools servers this trips HTTP 429 ("Too many
requests"), which is why sync could stall (mitigated in v1.3.7 by
retry/backoff + a concurrency guard, but the call volume itself is unaddressed).

**Recommendation (investigate later):**
- Avoid the per-appointment `CombinedAppointmentRequest`/`FileRequest` calls
  where the data is already available from the calendar/appointment payload, or
  fetch files/combined data in bulk instead of one-by-one.
- Skip image/flyer re-downloads when the CT image/flyer ID already matches the
  stored `ct_image_id`/`ct_flyer_id` in the mapping table (only fetch on change).
- Consider a smaller default `import_future` window and/or client-side
  throttling between requests to stay under the rate limit proactively.
- Measure the actual request count for a representative sync before/after.

---

## Medium Priority

### Refactor Large Function
**File:** `churchtools-dosync.php` — `processCalendarEntry()`

**Issue:** The function handles many responsibilities (content, location,
attachments, categories) in one large block.

**Recommendation:** Break into smaller functions:
- `processEventContent()`
- `processEventLocation()`
- `processEventAttachments()`
- `processEventCategories()`

### Performance: Location Lookup
**File:** `churchtools-dosync.php` — `getCreateLocation()`

**Issue:** Loads ALL Events Manager locations and loops through them (O(n) for
every event).

**Recommendation:** Use a database query with proper parameters or implement
caching.

### Performance: N+1 Query in Category Sync
**File:** `churchtools-dosync.php` — `updateEventCategories()`

**Issue:** `get_terms()` is called once per desired category, per calendar
entry.

**Recommendation:** Batch fetch categories at the start of the sync and match
against the cached list.

---

## Low Priority

### Code Style Consistency
**File:** `churchtools-dosync.php`

**Issues:**
- Mixed spacing around operators
- Inconsistent variable naming (camelCase vs snake_case)
- Inconsistent brace placement

### Documentation
**Files:** Multiple

**Issue:** Some functions lack proper PHPDoc blocks.

---

## Notes

- These improvements were identified during a security-focused code review.
- Critical security issues have been addressed; see `CHANGELOG.md` for the full
  history.
- A previously-listed "Hardcoded German String" item (`"Keine ausreichende
  Berechtigung"`) was removed: that string is matched against ChurchTools' own
  response body when file rights are missing, not a UI label — wrapping it in
  `__()` would translate it and break the check.
