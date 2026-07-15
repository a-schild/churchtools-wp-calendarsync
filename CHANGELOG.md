# churchtools-wp-calendarsync changelog

## Unreleased
- **Bug fix: de-duplication scan timed out (HTTP 500 from the reverse proxy after ~120 s) on large calendars/media libraries** — two causes, both removed: (1) it resolved each synced event's featured image by loading an `EM_Event` object per event (`em_get_event()`) — now a **single SQL join** (mapping → the Events Manager events table → `postmeta._thumbnail_id`), with an EM-API fallback (the flyer cleanup got the same bulk `event_id → post_id` lookup); (2) it ran **one `LIKE` scan of `postmeta` per image** to find each image's `-N`/`-scaled` siblings — now a **single bulk query** of all attachment files, grouped in memory by a normalised base key (`ctwpsync_image_base_key()`). The scan also no longer reads file contents (`md5_file`) — it estimates duplicate counts from base name + file size (a cheap stat); byte-verification happens only during the (batched) cleanup. The scan now returns in seconds
- **New: the clean-up runs in time-boxed batches with an auto-continuing UI** — each Clean up request now stops after ~45 s of deleting and returns `more: true`; the settings page automatically fires the next batch (showing running totals: "Cleaning up… N deleted so far") until it's done, so a large clean-up can never be killed mid-way by a proxy timeout. If a batch request does fail, the totals are preserved and clicking **Clean up** again resumes. Includes a no-progress safety stop so it can never loop forever
- **Bug fix: "Request failed" on the de-duplication buttons even with generous PHP limits** — on servers with `WP_DEBUG_DISPLAY` on (typical of test/staging sites), any PHP notice/warning/deprecation emitted during the scan — including ones raised deep inside Events Manager on PHP 8.x — is printed **before** the JSON response, which corrupts it; the browser then reports a generic "Request failed" (a JSON parse error) regardless of memory/time. The image and flyer AJAX handlers now buffer output during the work (`ob_start()`), discard/keep it out of the response so the JSON is always valid, and **log the captured text** (at ERROR) plus a "starting" line (at INFO) so the cause is recorded. If it still fails after this, the plugin log distinguishes the case (a "starting" line with no completion + no fatal = the request was killed by the web server/proxy, e.g. an nginx/FPM read timeout below the PHP limits)
- **Robustness: de-duplication now survives (and diagnoses) resource limits instead of failing with a bare "Request failed"** — the image/flyer Scan and Clean up AJAX handlers now (1) raise the memory limit (`wp_raise_memory_limit('admin')`) and keep the 300 s time budget, (2) wrap the work in a `try/catch` that returns the actual error message to the UI, and (3) register a shutdown handler (`ctwpsync_register_dedupe_fatal_logger()`) that records any true fatal — out-of-memory or a host execution-time kill — to the plugin log **with the peak memory and `memory_limit`**, so an abort is diagnosable rather than silent. The image scan is also much lighter: candidate files are first grouped by file **size** (cheap) and only byte-compared (`md5_file`) within same-size sets, so unique-size images are never read from disk
- **Improvement: de-duplication scans and clean-ups are now written to the plugin log at INFO level** — each image/flyer Scan or Clean up records a one-line result summary (sets checked, duplicate sets, re-pointed/rewritten, attachments deleted/to-delete, skipped) plus any per-attachment "left in place / failed" notes, so the outcome is visible in the Sync Log (and downloadable) instead of only in the transient on-screen panel. Logging honours the configured log level (shown when level is Info or Debug) via a new `ctwpsync_get_logger()` helper that mirrors the sync logger
- **Improvement: the settings page is now organised into tabs** — the previously long single-column page is grouped into five WordPress-native tabs (**Connection**, **Sync Options**, **Status**, **Tools**, **Logs**) that switch without a page reload. Configuration (URL/token, calendars, sync window, categories, image embedding), read-only sync status + EM 7.1 migration, the de-duplication tools, and the log level + viewer are now separated instead of interleaved. A single form still wraps everything, so **Save** (in a persistent bottom action bar with **Sync Now**) submits all settings regardless of the active tab. The last-used tab is remembered across the save reload; because required fields can sit on an inactive tab, the form uses `novalidate` and validates in JS, jumping to the Connection tab when the URL/token is missing or validation fails
- **Bug fix: event images were duplicated in the media library (`name-1.jpg`, `name-2.jpg`, … each with a full set of generated thumbnails)** — the same ChurchTools image, when used by many events or by every occurrence of a repeating event, was re-downloaded and re-uploaded on each sync. WordPress renamed each collision (`wp_upload_bits()` → `wp_unique_filename()`) and regenerated every size, so the library filled up with near-identical copies
  - Root causes: the mapping table's `wp_image_id` column was never written (so the plugin could not reliably reuse an event's own attachment), the only de-duplication was a fragile exact-path/filename lookup that missed across month folders and re-adds, and `addMode` (new events, repeating occurrences, self-healing re-adds) bypassed the "image unchanged" skip guard
  - Images imported by the plugin are now stamped with a `_ctwpsync_ct_image_id` post-meta, and before downloading, the sync looks up that id library-wide (`get_attachment_id_by_ct_image_id()`). If the CT image was already imported, the existing **single shared attachment** is reused for every event that references it — regardless of upload month — so no duplicate is created. `wp_image_id` is now persisted in the mapping on insert and update
- **Bug fix: the same duplication affected flyer/event-file attachments too** — flyers (event files with "flyer" in the name) were re-downloaded and re-sideloaded for every event / repeating occurrence, so `media_handle_sideload()` produced `flyer-1.pdf`, `flyer-2.pdf`, … copies. (The per-event guard already reused a flyer when its CT id was unchanged, since `wp_flyer_id` is persisted — but `addMode` re-adds and cross-event sharing bypassed it.) Flyer attachments are now stamped with a `_ctwpsync_ct_flyer_id` post-meta (a separate id space from images) and looked up library-wide via `get_attachment_id_by_ct_flyer_id()` before sideloading; an already-imported flyer is reused and linked instead of re-uploaded. The existing "already mapped" path also backfills the meta.
- **New: optional flyer de-duplication in the cleanup tool** — a separate "Flyers (optional)" step under the Image de-duplication section, with its own **Scan flyers** / **Clean up flyer duplicates** buttons (`ctwpsync_dedupe_flyers()`, AJAX action `ctwpsync_dedupe_flyers`). Because flyers are links in event descriptions rather than featured images, per CT flyer it keeps the lowest still-existing attachment, stamps `_ctwpsync_ct_flyer_id`, **rewrites that flyer's URL in every affected event's `post_content` to the canonical URL**, sets `wp_flyer_id`, and only then deletes a duplicate — and only if nothing (any post's content or featured image) still references it, so no link dangles. It is kept separate from the image cleanup because it edits published event content (back up first)
- **New: "Image de-duplication" cleanup tool on the settings page** — the prevention fix above only stops *new* duplicates; images imported by older versions have no `_ctwpsync_ct_image_id` meta. The settings page has a **Scan for duplicates** button (dry run — reports what would change, changes nothing) and a **Clean up duplicates** button (with a confirm). Detection starts from every image attachment the plugin is responsible for (stamped, recorded in the mapping's `wp_image_id`, or currently a synced event's featured image); for each it finds sibling attachments sharing the same base filename (WordPress' `-N`/`-scaled` variants) in the same folder, **confirms they are byte-identical via `md5_file`** (so unrelated images that merely share a name are never merged), keeps the lowest attachment id as the canonical, re-points every featured image + the mapping's `wp_image_id`, stamps the canonical's `_ctwpsync_ct_image_id`, and deletes the redundant copies (files + sub-sizes). A copy whose URL still appears in post content is left in place and reported. Backed by the nonce- + `manage_options`-protected `ctwpsync_dedupe_images` AJAX action. **This finds orphaned duplicates** — previous downloads no event still points at — which is the usual result of the old re-download bug (an earlier mapping-only version of the scan reported "no duplicates" because those copies aren't anyone's current featured image)

## 2026-07-15
- Release v1.4.0
- **Improvement: log lines are now timestamped** — each entry is prefixed with `[YYYY-MM-DD HH:MM:SS]` in the site timezone (matching the dashboard), making it easy to see when things happened and how long steps took. The redundant timestamps previously embedded in the "Start/End sync cycle" messages were removed
- **New: dashboard warning when a sync is aborted mid-run** — if a sync cycle is killed by the host before it finishes (PHP execution-time or memory limit, or an uncaught fatal), nothing normally reaches the log. It is now detected two ways: a shutdown handler catches PHP-level fatals/timeouts (and logs the reason, including the "Maximum execution time exceeded" message), and a next-run check catches a true `SIGKILL` where even the shutdown handler can't run (by noticing the previous run recorded a start but no finish). A dedicated, immediate admin notice explains the last sync didn't finish and suggests reducing the future-days window or raising PHP limits. It clears on the next successful sync and is only shown when the plugin is fully configured. State stored in `ctwpsync_last_sync_started_ts` / `ctwpsync_last_sync_finished_ts` / `ctwpsync_last_run_aborted`
- **New: configurable log level and an in-admin log viewer** — the settings page now has a "Logging" option to choose verbosity (Errors only / Info / Debug, stored as `log_level` in `ctwpsync_options`, default Info) and a "Sync Log" panel showing the most recent log entries with Refresh, Download full log, and Clear buttons. The viewer reads only the tail of the (rotating, 5 MB) log via nonce- and `manage_options`-protected AJAX endpoints and always uses the fixed hardened log path. The `CTWPSYNC_DEBUG` constant still forces Debug and additionally enables the churchtools-api library's own vendor log
- **Robustness: concurrency guard prevents overlapping syncs** — the sync now bails at the start if the `churchtools_wpcalendarsync_in_progress` marker is set, so wp-cron can't spawn a second sync on top of a slow one (which would worsen rate limiting). The marker is a **lease with a heartbeat**: it is refreshed (same value, TTL reset to 600 s) on every appointment, so a legitimately long sync keeps the guard held for its full duration, while a sync hard-killed by the host (e.g. PHP-FPM `request_terminate_timeout`) stops refreshing and the lease self-heals ~600 s after the last heartbeat — no manual cleanup needed. Expiry is enforced by the object cache (real eviction) or lazily by `get_transient()` on the next cycle (DB-backed transients)
- **Robustness: bounded per-request time and refreshed the execution budget so large/initial syncs are not killed mid-run**
  - The ChurchTools HTTP client now has explicit timeouts (`connect_timeout` 10 s, `timeout` 30 s); previously the library set none, so a hung server could block a sync indefinitely
  - Image downloads (`file_get_contents`) now use a 30 s stream-context timeout instead of the 60 s PHP `default_socket_timeout`, so one slow image can't stall a sync with many images
  - `set_time_limit(300)` is now refreshed per appointment (not just once at the start), so the accumulated work of a large initial sync — which makes several API calls and an image download per entry — doesn't exhaust the 5-minute limit. Note: this does not override server-side hard limits (PHP-FPM `request_terminate_timeout`, `memory_limit`); a very large first sync on constrained hosting may still need a smaller `import_future` window
- **Improvement: sync status times shown in the site timezone instead of UTC** — "Last sync", "Next scheduled sync", the in-progress "started" marker and the failure-warning timestamp previously displayed UTC (WordPress runs PHP in UTC), which was confusing. They now render in the WordPress site timezone via `get_date_from_gmt()`/`wp_date()`, and the status panel notes which timezone is in use. Internal timestamps used for database comparisons (`last_seen`, sync window) remain UTC and unchanged, so cleanup logic is unaffected
- **New: dashboard warning when syncs keep failing** — an admin notice now appears once the last 4 sync cycles have all failed (e.g. persistent rate limiting or connection errors), showing the last error message and time, with a link to the settings page. It is only shown when the plugin is fully configured (Events Manager active, URL + API token set, at least one calendar selected), so it never nags during initial setup. The consecutive-failure counter resets on the first successful sync. Threshold configurable via the `CTWPSYNC_FAILURE_WARN_THRESHOLD` constant
- **Bug fix: sync silently stopped on rate-limited ChurchTools servers (HTTP 429 "Too many requests")** — every sync cycle failed on the appointment fetch and never updated events, while the dashboard kept showing a stale "Last sync" time with no visible error
  - ChurchTools enforces API rate limiting; a full sync (paginated appointment fetches across several calendars over a wide date window) can burst past the limit and get a `429` response. The bundled `churchtools-api` library has **no retry logic**, so a single `429` aborted the entire sync
  - The plugin now installs a Guzzle retry/backoff middleware on the ChurchTools client before syncing: it retries on `429` and `5xx` (up to 5 times), honours the server's `Retry-After` header (capped at 30 s so it can't exceed the cron time limit), and otherwise backs off exponentially (1/2/4/8/16 s). The library disables Guzzle's `http_errors`, so the `429` is seen as a normal response and handled cleanly
  - Each retry is now logged (`INFO`: e.g. "HTTP 429 (rate limited): retry 1/5 with backoff"; the backoff wait is logged at `DEBUG`), and giving up after the last retry logs an `ERROR`, so rate limiting is visible in the log even when a sync recovers
  - If a rate-limited sync is a recurring problem, reducing the `import_future` window shrinks each sync's request burst
- **Bug fix: "Sync Now" button did nothing** — the button reported "Sync started in background" but the sync silently no-opped, leaving Status at *Idle* and Last sync at *Never*
  - The one-time sync event (`ctwpsync_single_sync_event`) runs in an unauthenticated wp-cron loopback request with no current user. `churchtools-dosync.php` bails out early ("No user specified" / "User not logged in") because created events need an owner, so the sync never ran
  - The scheduled event now captures the admin's user ID at schedule time and restores it with `wp_set_current_user()` before running, exactly like the hourly cron event (`do_this_ctwpsync_hourly`) already does

## 2026-07-14
- Release v1.3.6
- **Metadata: corrected the minimum PHP version in `composer.json`** — the constraint was `^8.1`, which would allow installation on PHP 8.1 where the plugin's `readonly` classes (`SyncLogger`, `SyncConfig`) are a fatal parse error. Now `^8.2`, matching the `Requires PHP: 8.2` plugin header
- **Metadata: bumped `Tested up to` from `6.3.1` to `7.0.1`** to reflect current WordPress compatibility

## 2026-07-14
- Release v1.3.5
- **Security: updated vulnerable Guzzle HTTP dependencies** (resolves 5 Dependabot advisories affecting the release ZIP, which installs dependencies from `composer.lock` at build time)
  - `guzzlehttp/guzzle` `7.10.0` → `7.14.1` — fixes "Dot-Only Cookie Domains Match All Hosts" and "Silent HTTPS-Proxy Downgrade to Cleartext"
  - `guzzlehttp/psr7` `2.8.0` → `2.12.5` — fixes CRLF injection in HTTP start-line serialization, CRLF injection via URI host component, and host confusion via authority reinterpretation
  - `guzzlehttp/promises` `2.3.0` → `2.5.1` (required for guzzle ≥ 7.12)
  - `composer audit` now reports no advisories

## 2026-07-14
- Release v1.3.4
- **Security: stored XSS prevention in event description and title**
  - The ChurchTools appointment "information" field is now passed through `wp_kses_post()` before being stored as the event `post_content`, which Events Manager renders as HTML. This prevents a ChurchTools user (who may not be a WordPress admin) from injecting `<script>`/event-handler markup that would execute on the public event page
  - The event title (`event_name`) from the CT caption is now sanitized with `sanitize_text_field()`
- **Security: image download restricted to real image types**
  - `downloadEventImage()` now validates both the file extension (jpg/jpeg/png/gif/webp) and the actual file contents (`getimagesizefromstring()`) before writing to the uploads directory, mirroring the PDF-only check already used for flyers. Defense in depth against writing unexpected/executable files using a ChurchTools-supplied filename
- **Security: SSRF hardening of admin AJAX endpoints**
  - The connection-test, calendar-fetch and resource-type-fetch AJAX handlers now validate that the supplied URL is a well-formed `http(s)` URL (rejecting `file://`, `gopher://`, etc.) before making a server-side request. These endpoints already required `manage_options`; this is defense in depth
- **Security: log files moved out of the web-accessible directory**
  - Logs now live in a hardened `wp-content/uploads/ctwpsync-logs/` folder (guarded by `index.php` + `.htaccess` + `web.config`) with an unguessable, per-site filename, so they can no longer be fetched by URL — including on nginx/IIS, where the plugin's `.htaccess` rule does not apply
  - The churchtools-api library file log is now only enabled when the `CTWPSYNC_DEBUG` constant is set to true (it wrote fixed, non-relocatable `*.log` files into the vendor directory); the plugin's own logger is used otherwise
  - Debug-level logging of the plugin logger is likewise gated behind `CTWPSYNC_DEBUG`
  - The log file now rotates once it reaches 5 MB (keeps one previous generation) to prevent unbounded growth
  - One-time migration moves any pre-existing `wpcalsync.log` and vendor `churchtools-api*.log` files into the new protected location
- **Bug fix / hardening: corrected a manually-quoted `%s` placeholder** in the repeating-event lookup query (`event_start = %s`) that tripped a `_doing_it_wrong` notice on modern WordPress
- **Build: development test scripts moved to `tests/` and excluded from the release ZIP** (also excludes `.idea/`, `.gitignore`, and `*.log`)

## 2026-07-14
- Release v1.3.3
- **Bug fix: cron cleanup never removed events scheduled with args (unbounded event duplication → OOM)**
  - Thanks to [@lichtteil](https://github.com/lichtteil) (Mario) for the diagnosis and fix ([#24](https://github.com/a-schild/churchtools-wp-calendarsync/pull/24))
  - `wp_clear_scheduled_hook()` without args only removes events whose args hash matches an empty array. Since all `ctwpsync_hourly_event` events are scheduled *with* args, activation, deactivation and the hourly→57-minutes migration never removed anything
  - A leftover pre-1.1.0 `hourly` event therefore made the migration branch fire on every request, adding one new event per request. On a production multisite this grew the autoloaded `cron` option to ~14.5 MB (~39,000 duplicate events) in 30 days and took the site down with out-of-memory fatals during bootstrap
  - All cleanup paths now use `wp_unschedule_hook()`, which removes events regardless of their args
  - Event args now carry the user ID instead of a full serialized `WP_User` object, which had bloated every event entry and persisted the user's password hash and capability map inside the autoloaded `cron` option. `do_this_ctwpsync_hourly()` still accepts the legacy `WP_User` arg from pending events
  - Added a one-time self-healing migration: any event with a wrong schedule, object args, or duplicates triggers a full `wp_unschedule_hook()` + reschedule of a single clean event, preserving the sync user from existing event args

## 2026-03-02
- Release v1.3.1
- **Bug fix: location fallback when `getMeetingAt()` is empty**
  - Location matching and creation now falls back to street, then city, then "undefined" when the primary location name is empty
  - Fixes events with incomplete address data causing a null/empty location name
- **Security: XSS prevention in event link/flyer injection**
  - `#LINK:text:#` and `#FLYER:text:#` marker text is now HTML-escaped before insertion into event content
  - Link URLs are now passed through `esc_url()` in both the marker replacement and the fallback append case
- **Security: SSRF protection for image downloads**
  - Image downloads via `file_get_contents()` now validate that the URL starts with the configured ChurchTools base URL
  - Prevents a compromised ChurchTools instance from making the WordPress server request internal network resources
- **Security: Path traversal protection for flyer temp files**
  - Flyer filenames from ChurchTools are now sanitized with `basename()` and `sanitize_file_name()` before being used in temp path construction
- **Security: XSS prevention in calendar ID rendering**
  - Calendar IDs from the ChurchTools API are now passed through `escapeHtml()` when rendered in the admin dashboard JavaScript
- **Security: Log file protected from web access**
  - Added `.htaccess` rule to deny direct HTTP access to `*.log` files in the plugin directory

## 2026-03-04
- Release v1.3.2
- **Bug fix: image URL override filter now skips non-event objects**
  - `ctwpsync_override_event_image` now checks `instanceof EM_Event` before applying logic
  - Prevents errors when Events Manager calls the filter for locations or other objects
- **Bug fix: guard `event_attributes` before `array_key_exists()`**
  - Added `is_array()` check on `$em_event->event_attributes` to prevent fatal errors when the attribute is not an array

## 2026-02-04
- Release v1.3.0
- **Background sync with "Sync Now" button**
  - Save no longer triggers a sync (preventing timeout issues)
  - New "Sync Now" button triggers sync via WordPress cron in the background
  - Sync runs independently of the UI, preventing page timeouts
- **Sync status display**
  - New "Sync Status" section shows current status (Idle/In Progress)
  - Shows last sync time and duration
  - Shows next scheduled sync time with countdown in minutes
  - Shows sync schedule (every 57 minutes)
  - Status auto-updates via AJAX polling after triggering a sync
- **Changed sync interval to 57 minutes**
  - Avoids always running at the top of the hour (xx:00)
  - Spreads server load more evenly over time
  - Automatic migration from old hourly schedule on plugin load
- **Fix AJAX calls with saved token**
  - Validate Connection, Load Calendars, and Load Resource Types buttons now work when an API token is already saved
  - JavaScript now passes `use_saved_token` flag to PHP when token field is empty but token is saved
  - PHP AJAX handlers retrieve saved token from options when flag is set
- **Events Manager dependency checks**
  - Added admin notice on all pages when Events Manager is not active
  - Block plugin activation if Events Manager is not installed
  - Auto-deactivate plugin when Events Manager is deactivated
- **Logging improvements**
  - Replaced serialize() with json_encode() for readable log output
  - Improved DateTime logging to use formatted strings
- **DateTime validation**
  - Added checks for invalid date formats from DateTime::createFromFormat()
  - Events with unparseable dates are now skipped with error logging
- **Removed session_destroy()**
  - Removed problematic session_destroy() call from exception handler
- **Input validation**
  - Added URL format validation using esc_url_raw() and filter_var()
  - Added range clamping for import_past (-365 to 365 days) and import_future (-365 to 730 days)
  - Added validation for calendar IDs (must be numeric and positive)
  - Added sanitize_text_field() for text inputs
- **Improved error handling**
  - Added try-catch for ChurchTools API calls (AppointmentRequest, CombinedAppointmentRequest, FileRequest)
  - Added error checking for file_get_contents when downloading images
  - Better error messages for failed event saves including event name and EM errors
- **Bug fix**
  - Fixed ResourceTypeRequest namespace to use correct path from upstream library

## 2026-02-03
- **Security fixes**
  - Fixed SQL injection vulnerabilities - all queries now use proper prepared statements
  - Fixed XSS vulnerabilities - all output now properly escaped with esc_attr/esc_html
  - Added CSRF protection with wp_nonce_field verification on form submission
  - API token no longer exposed in HTML source - kept server-side only
  - Added capability checks before saving settings
- **Add ChurchTools appointment tag support**
  - New option to sync ChurchTools appointment tags as WordPress event categories
  - Tags are added alongside existing category sources (calendar mapping, resource bookings)
  - Enable via checkbox in plugin settings: "Sync ChurchTools appointment tags as event categories"
  - Requires churchtools-api library with appointment-tag-support branch
- **Add connection validation**
  - New "Validate Connection" button in settings to test ChurchTools URL and API token
  - Shows connected user name on success or error message on failure
  - Settings are automatically validated before saving - invalid credentials prevent save
- **Improved settings UI with dynamic calendar and resource type selection**
  - "Load Calendars from ChurchTools" button fetches available calendars
  - Select calendars via checkboxes instead of entering IDs manually
  - Assign categories per calendar in a table view
  - "Load Resource Types" button populates dropdown for resource type selection
  - Automatic migration of existing settings from old format to new format
  - Safety checks for empty/missing credentials and calendar configurations

## 2025-12-06
- Prepare php 8.2+ only support

## 2025-12-06
- Release v1.2.0
- **Option added to deliver images directly from churchtools**
  - Thanks to @JonFStr

## 2025-10-29
- Release v1.1.0
- **Events Manager 7.2+ Compatibility**
  - Added migration to set `event_archetype` field to "event" for all existing events
  - Set `event_archetype` field automatically for all new events
  - Fixed taxonomy constant `EM_TAXONOMY_CATEGORY` not being defined in EM 7.2+
  - Fixed `get_terms()` call to use correct array format for WordPress 4.5+
- **PHP 8+ Compatibility Fixes**
  - Fixed fatal error: `sizeof()` cannot accept `WP_Error` objects (line 578)
  - Fixed deprecation warning: `trim()` now handles null values correctly (line 231)
- **Cron Job Improvements**
  - Fixed duplicate cron events being scheduled
  - Added automatic cleanup of duplicate scheduled events on plugin load
  - Clear all existing cron events before scheduling new ones during activation
- **Build & Release**
  - Updated GitHub Actions workflow to include version in zip filename
  - Plugin files now packaged in proper folder structure inside zip
  - Improved release automation

## 2025-09-05
- Release v1.0.16
  Packaging problem in github actions
- Release v1.0.15
- Wrap code in transaction to prevent duplicate events
- Handle events correctly, when end time is in another DST state
  This time it works, problem is
  https://github.com/5pm-HDH/churchtools-api/issues/227

## 2025-09-04
- Release v1.0.14
- Handle events correctly, when end time is in another DST state
  (Thanks to #Fearless-88)

## 2025-06-25
- Release v1.0.13
- Small fix for all day events (Thanks to #Fearless-88)

## 2025-06-21
- Release v1.0.12
- Small fix to attach images if found

## 2025-06-21
- Release v1.0.11
- Added support for Event Manager 7.x
- Check for existing images before uploading
  Prevents duplicate uploads
- Upload images in folder with year+month of event

## 2025-04-04
- Release v1.0.10
- Set log level for flyer handling to debug when not modifying wp database
- Release v1.0.9
- Make sure to not reupload file if already present in WP
- Set log level to info

## 2024-05-13
- Release v1.0.8
- Merged in link replacement pull request from JonFStr (Thanks)
  You can now also use this macro to embedd the link in the post content
  #LINK:Mehr infos unter diesem Link:#
- Make sure link starts with http:// or https://, otherwise we prefix it with https://
- Add one file attachment, if we find a event file attachement which has "flyer"
  in the file name
  The link to the flyer can also be used with a placeholder #FLYER:Mehr infos auf dem Flyer:#

## 2024-02-15
- Release v1.0.7
  Fix issue with double quotes introduced in 1.0.6

## 2023-11-10
- Release v1.0.6
- DB column should not be unique, otherwise we can't save repeating events in mapping
- Release v1.0.5
- Fix branch condition to process categories when no resource type mapping is enabled

## 2023-11-09
- Release v1.0.4
- Allow NULL values in more columns

## 2023-11-07
- Release v1.0.3
- Fix versioning
- Fix sql data type error on new installations
- Release v1.0.2

## 2023-10-16
- Correctly handle repeating events
- Release v1.0.1

## 2023-09-29
- Change licence from Apache 2.0 to GNU GPL 2
- Add events manager plugin detection and error handling if missing/inactive
- Pass user to cron job
- More plugin metadata added
- Assign categories based on source calendar

(c) 2023 Aarboard a.schild@aarboard.ch
