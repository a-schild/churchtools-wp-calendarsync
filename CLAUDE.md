# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress plugin that syncs calendar events from ChurchTools to WordPress Events Manager. It depends on the [Events Manager plugin](https://wordpress.org/plugins/events-manager/) being installed and active.

## Commands

### Install dependencies
```bash
cd /path/to/wp-content/plugins/churchtools-wpcalendarsync
composer install
```

### Update dependencies
```bash
composer update
```

**PHP executable:** `c:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe`

## Releasing a New Version

1. Update `CHANGELOG.md`
2. Bump the version in `churchtools-wpcalendarsync.php` — update **both** the plugin header (`Version:` line) and the `CTWPSYNC_VERSION` constant (search with grep, line numbers shift over time)
3. Commit and push, then create a git tag in the form `v1.x.y`
4. GitHub Actions will build and publish the release ZIP automatically
5. On the GitHub releases page, add a meaningful changelog/release notes for the new version (summarize the user-facing changes — don't leave the release description empty or auto-generated only)

### Release History

| Version | Date       | Highlights |
|---------|------------|------------|
| 1.4.0   | 2026-07-15 | Reliability & observability release for rate-limited/constrained hosts. **Bug fixes:** (1) sync silently stopped on rate-limited CT servers — a `429 Too many requests` aborted the whole sync every cycle (the `churchtools-api` library has no retry logic); now installs a Guzzle retry/backoff middleware (retries `429`/`5xx` up to 5×, honours `Retry-After` capped 30 s, else exponential 1/2/4/8/16 s) via `CTClient::setClient()`, with each retry logged; (2) "Sync Now" no-opped — the one-time `ctwpsync_single_sync_event` ran with no current user, now captures the admin ID and restores it via `wp_set_current_user()` like `do_this_ctwpsync_hourly`. **Robustness:** per-request Guzzle timeouts (connect 10 s / total 30 s) + 30 s image-download timeout + `set_time_limit(300)` refreshed per appointment; concurrency guard with heartbeat lease (`churchtools_wpcalendarsync_in_progress`) prevents overlapping syncs and self-heals after a kill. **New:** dashboard warnings for ≥`CTWPSYNC_FAILURE_WARN_THRESHOLD` (default 4) consecutive failures (`ctwpsync_consecutive_failures`/`ctwpsync_last_sync_error`) and for a sync aborted mid-run (`ctwpsync_last_run_aborted`, via shutdown handler + next-run start/finish-ts check), both only when `ctwpsync_is_configured()`; configurable log level (`log_level` ERROR/INFO/DEBUG, `CTWPSYNC_DEBUG` forces DEBUG, resolved by `ctwpsync_effective_log_level()`); in-admin log viewer (refresh/download/clear AJAX); timestamped log lines; sync-status times shown in the site timezone |
| 1.3.6   | 2026-07-14 | Metadata: `composer.json` PHP constraint `^8.1`→`^8.2` (matches `Requires PHP: 8.2`; `^8.1` allowed a broken install on 8.1 where `readonly` classes fatal); `Tested up to` `6.3.1`→`7.0.1` |
| 1.3.5   | 2026-07-14 | Security (dependencies): updated Guzzle stack to clear 5 Dependabot advisories — `guzzlehttp/guzzle` 7.10.0→7.14.1, `guzzlehttp/psr7` 2.8.0→2.12.5, `guzzlehttp/promises` 2.3.0→2.5.1; release ZIP installs deps from `composer.lock` at build time so the lockfile bump is the fix |
| 1.3.4   | 2026-07-14 | Security: stored XSS prevention in event description/title (`wp_kses_post`/`sanitize_text_field`); image download restricted to real image types; SSRF hardening of admin AJAX URL params; logs moved to hardened `uploads/ctwpsync-logs/` with unguessable name + rotation + one-time migration (`CTWPSYNC_DEBUG` gates library/debug logging); fixed manually-quoted `%s` in repeating query; test scripts moved to `tests/` and excluded from build |
| 1.3.3   | 2026-07-14 | Bug fix: cron cleanup used `wp_unschedule_hook()` so events scheduled with args are actually removed; user ID (not `WP_User` object) in event args; self-healing migration for duplicate/legacy events (PR #24) |
| 1.3.2   | 2026-03-04 | Bug fix: image filter skips non-EM_Event objects; guard `event_attributes` before `array_key_exists()` |
| 1.3.1   | 2026-03-02 | Bug fix: location fallback for empty `getMeetingAt()`; security fixes (XSS, SSRF, path traversal, log protection) |
| 1.3.0   | 2026-02-04 | Background sync with "Sync Now" button; sync status display; 57-min interval; AJAX token fix; EM dependency checks |
| 1.2.0   | 2025-12-06 | Option to deliver images directly from ChurchTools |
| 1.1.0   | 2025-10-29 | Events Manager 7.2+ compatibility; PHP 8+ fixes; cron deduplication |
| 1.0.x   | 2023–2025  | Initial releases: repeating events, image/flyer support, DST handling, tag support |

## Architecture

### Key Files

| File | Purpose |
|------|---------|
| `churchtools-wpcalendarsync.php` | Main plugin entry point. Registers WP hooks, cron scheduling, AJAX handlers, settings page, activation/deactivation hooks. |
| `churchtools-dosync.php` | Core sync logic (included dynamically at runtime). Fetches appointments from ChurchTools API and creates/updates WP Events Manager events. Contains `ctwpsync_getUpdatedCalendarEvents()` and `processCalendarEntry()`. |
| `includes/SyncConfig.php` | PHP 8.2 readonly class managing sync configuration. Handles de/serialization from WP options and POST data. |
| `includes/Logger.php` | PHP 8.2 readonly class `SyncLogger` with `LogLevel` enum (DEBUG/INFO/ERROR). Logs to `wpcalsync.log` in plugin dir. |
| `dashboard/dashboard_view.php` | Admin settings page UI (included from `ctwpsync_dashboard()`). |

### Database

A custom mapping table `{prefix}ctwpsync_mapping` stores the CT→WP event ID mapping with columns: `ct_id`, `ct_repeating`, `wp_id`, `ct_image_id`, `wp_image_id`, `ct_flyer_id`, `wp_flyer_id`, `last_seen`, `event_start`, `event_end`. Created/migrated via `dbDelta()` in `ctwpsync_initplugin()`.

**Image de-duplication:** attachments imported for event images are stamped with a `_ctwpsync_ct_image_id` post-meta (the CT file id). Before downloading, `downloadEventImage()` looks that id up library-wide via `get_attachment_id_by_ct_image_id()` and reuses the single existing attachment (shared across all events/repeating occurrences using the same CT image, regardless of upload month) instead of letting `wp_upload_bits()` create `name-1.jpg`/`name-2.jpg` copies. `wp_image_id` is persisted in the mapping on insert/update. Pre-existing duplicates (imported before this meta existed) are cleaned up on demand via the settings-page "Image de-duplication" Scan/Clean up buttons → `ctwpsync_dedupe_images()` (AJAX action `ctwpsync_dedupe_images`, in the main plugin file): per CT image it keeps the lowest attachment id, stamps the meta, re-points featured images + `wp_image_id`, and deletes the redundant attachments (skipping any still used by a post outside the event group).

**Flyer de-duplication:** flyers (event files with "flyer" in the name) use the same scheme with a **separate** meta key `_ctwpsync_ct_flyer_id` and `get_attachment_id_by_ct_flyer_id()` — event-file ids and appointment-image ids are different id spaces. `downloadEventImage` handles images (featured image); the flyer branch in `processCalendarEntry()` handles flyers (linked in `post_content` via `addFlyerLink()`, not set as thumbnail). `wp_flyer_id` was already persisted in the mapping. Pre-existing flyer duplicates are collapsed by a **separate** optional cleanup, `ctwpsync_dedupe_flyers()` (AJAX action `ctwpsync_dedupe_flyers`, "Flyers (optional)" buttons under the Image de-duplication section): per CT flyer it keeps the lowest still-existing attachment, stamps the meta, **rewrites the flyer URL in each affected event's `post_content`** to the canonical URL, sets `wp_flyer_id`, and deletes redundant attachments only when nothing (post content or featured-image) still references them. It is kept separate from the image tool because it edits published event content.

### WordPress Options

Settings stored in `ctwpsync_options` (array). Key fields:
- `url` — ChurchTools installation URL
- `apitoken` — ChurchTools API token
- `calendars` — array of `{id, name, category}` objects
- `import_past` / `import_future` — sync window in days
- `resourcetype_for_categories` — resource type ID for WP category mapping (-1 to disable)
- `em_image_attr` — Events Manager custom attribute name for referencing CT image URLs
- `enable_tag_categories` — sync CT appointment tags as WP categories
- `log_level` — plugin log verbosity: `ERROR` / `INFO` (default) / `DEBUG`. Read at sync start in `churchtools-dosync.php` to set `SyncLogger`'s `debugEnabled`/`infoEnabled`. `CTWPSYNC_DEBUG` forces `DEBUG`. Validate with `SyncConfig::sanitizeLogLevel()`

Sync-health options (standalone, not inside `ctwpsync_options`):
- `ctwpsync_consecutive_failures` — count of consecutive failed sync cycles; incremented in the `churchtools-dosync.php` catch block, reset to 0 on success. Drives the dashboard warning (`ctwpsync_admin_notice_sync_failing`) once it reaches `CTWPSYNC_FAILURE_WARN_THRESHOLD` (default 4) and `ctwpsync_is_configured()` is true
- `ctwpsync_last_sync_error` — `['message' => ..., 'time' => ...]` of the most recent failure, shown in the warning; deleted on success
- `ctwpsync_last_sync_started_ts` / `ctwpsync_last_sync_finished_ts` — unix timestamps for abort detection: a run records `started_ts` at the start and `finished_ts` on any clean end (success, caught error, or clean early return). If a later run sees `started_ts > finished_ts`, the previous run was aborted (hard kill). See `ctwpsync_check_previous_run_aborted()` + shutdown handler `ctwpsync_detect_aborted_sync()`
- `ctwpsync_last_run_aborted` — `['message' => ..., 'time' => ...]` set when an abort is detected; drives the immediate `ctwpsync_admin_notice_sync_aborted` notice (takes precedence over the consecutive-failures notice); cleared on the next successful sync

### Cron / Sync Flow

1. Plugin schedules `ctwpsync_hourly_event` every 57 minutes via `wp-cron`
2. Cron fires `do_this_ctwpsync_hourly()` → `do_action('ctwpsync_includeChurchcalSync')` → includes `churchtools-dosync.php`
3. "Sync Now" button triggers `ctwpsync_single_sync_event` as a one-time cron event and calls `spawn_cron()`
4. Sync status tracked via transients: `churchtools_wpcalendarsync_in_progress`, `churchtools_wpcalendarsync_lastupdated`, `churchtools_wpcalendarsync_lastsyncduration`

### Logging

- Plugin log lives in `wp-content/uploads/ctwpsync-logs/wpcalsync-<hash>.log` (hardened dir with `index.php`/`.htaccess`/`web.config`; `<hash>` from `wp_hash()` makes the URL unguessable). Path via `ctwpsync_log_file()`. Rotates at 5 MB keeping one `.1` generation.
- Verbosity is controlled by the `log_level` setting (ERROR/INFO/DEBUG) on the settings page; read at sync start. `CTWPSYNC_DEBUG` (`true`) in `wp-config.php` forces DEBUG **and** additionally enables the `churchtools-api` library's own file log (fixed, non-relocatable `*.log` files in `vendor/`). Off by default.
- Admin log viewer on the settings page (dashboard/dashboard_view.php "Sync Log" section) with Refresh/Download/Clear, backed by AJAX actions `ctwpsync_get_log`, `ctwpsync_clear_log`, `ctwpsync_download_log` (all nonce + `manage_options`). Tail read via `ctwpsync_read_log_tail()`.
- `ctwpsync_migrate_logs()` (priority 4 on `plugins_loaded`) moves pre-1.3.4 logs from the plugin/vendor dirs into the new location once, guarded by the `ctwpsync_logs_migrated` option.

### ChurchTools API Library

Uses a fork of `5pm-hdh/churchtools-api` pinned to branch `appointment-tag-support` from `https://github.com/ref-nidau-ch/churchtools-api`. Loaded via `vendor/autoload.php`. Key namespaces used: `CTApi\CTConfig`, `CTApi\Models\Calendars\*`, `CTApi\Models\Groups\Person\PersonRequest`.

### Settings Migrations

Two migrations run automatically in `ctwpsync_migrate_settings()` (priority 5 on `plugins_loaded`):
- Old format (separate `ids`/`ids_categories` arrays) → new `calendars` array format
- Events Manager 7.1+ and 7.2+ compatibility migrations in `ctwpsync_initplugin()`

### Special Text Markers in Event Descriptions

`churchtools-dosync.php` handles special markers in ChurchTools event descriptions:
- `#LINK:<text>:#` — replaced with a link to the ChurchTools event
- `#FLYER:<text>:#` — replaced with a link to an attached flyer file
