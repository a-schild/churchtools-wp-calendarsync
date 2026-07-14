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

### Release History

| Version | Date       | Highlights |
|---------|------------|------------|
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

### WordPress Options

Settings stored in `ctwpsync_options` (array). Key fields:
- `url` — ChurchTools installation URL
- `apitoken` — ChurchTools API token
- `calendars` — array of `{id, name, category}` objects
- `import_past` / `import_future` — sync window in days
- `resourcetype_for_categories` — resource type ID for WP category mapping (-1 to disable)
- `em_image_attr` — Events Manager custom attribute name for referencing CT image URLs
- `enable_tag_categories` — sync CT appointment tags as WP categories

### Cron / Sync Flow

1. Plugin schedules `ctwpsync_hourly_event` every 57 minutes via `wp-cron`
2. Cron fires `do_this_ctwpsync_hourly()` → `do_action('ctwpsync_includeChurchcalSync')` → includes `churchtools-dosync.php`
3. "Sync Now" button triggers `ctwpsync_single_sync_event` as a one-time cron event and calls `spawn_cron()`
4. Sync status tracked via transients: `churchtools_wpcalendarsync_in_progress`, `churchtools_wpcalendarsync_lastupdated`, `churchtools_wpcalendarsync_lastsyncduration`

### Logging

- Plugin log lives in `wp-content/uploads/ctwpsync-logs/wpcalsync-<hash>.log` (hardened dir with `index.php`/`.htaccess`/`web.config`; `<hash>` from `wp_hash()` makes the URL unguessable). Path via `ctwpsync_log_file()`. Rotates at 5 MB keeping one `.1` generation.
- Define `CTWPSYNC_DEBUG` (`true`) in `wp-config.php` to enable debug-level logging **and** the `churchtools-api` library's own file log (which writes fixed, non-relocatable `*.log` files into `vendor/`). Off by default.
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
