# churchtools-wp-calendarsync changelog

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
