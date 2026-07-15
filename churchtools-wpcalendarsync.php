<?php
/**
 *
 *
 * @link              https://github.com/a-schild/churchtools-wp-calendarsync
 * @since             0.1.0
 * @package           Ctwpsync
 *
 * @wordpress-plugin
 * Plugin Name:       Churchtools WP Calendarsync
 * Plugin URI:        https://github.com/a-schild/churchtools-wp-calendarsync
 * Description:       Churchtools wordpress calendar sync to events manager, requires "Events Manager" plugin. The sync is scheduled every hour to update WP events from churchtool.
 * Version:           1.4.0
 * Author:            André Schild
 * Author URI:        https://github.com/a-schild/churchtools-wp-calendarsync/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       ctwpsync
 * Domain Path:       /languages
 * Tags:              churchtools, events manager, sync, calendar
 * Requires at least: 5.8
 * Requires PHP:      8.2
 * Tested up to:      7.0.1
 * Stable tag:        main
 *
 */

// Load PHP 8.2 classes
require_once plugin_dir_path(__FILE__) . 'includes/Logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/SyncConfig.php';

/**
 * Check if Events Manager plugin is active
 *
 * @return bool True if Events Manager is active
 */
function ctwpsync_is_events_manager_active(): bool {
	if (!function_exists('is_plugin_active')) {
		include_once(ABSPATH . 'wp-admin/includes/plugin.php');
	}
	return is_plugin_active('events-manager/events-manager.php');
}

/**
 * Show admin notice if Events Manager is not active
 */
add_action('admin_notices', 'ctwpsync_admin_notice_events_manager_required');
function ctwpsync_admin_notice_events_manager_required(): void {
	if (ctwpsync_is_events_manager_active()) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><strong>ChurchTools Calendar Sync:</strong> This plugin requires the <a href="https://wordpress.org/plugins/events-manager/">Events Manager</a> plugin to be installed and activated.</p>
	</div>
	<?php
}

/**
 * Whether the plugin is configured enough to actually sync: Events Manager
 * active, a ChurchTools URL + API token set, and at least one calendar selected.
 * Used to suppress the sync-failure warning during initial setup.
 */
function ctwpsync_is_configured(): bool {
	if (!ctwpsync_is_events_manager_active()) {
		return false;
	}
	$options = get_option('ctwpsync_options');
	if (!is_array($options)) {
		return false;
	}
	if (empty($options['url']) || empty($options['apitoken'])) {
		return false;
	}
	// A calendar must be selected. Accept the current 'calendars' format as well
	// as the pre-migration 'ids' array, in case migration has not run yet.
	$has_calendars = !empty($options['calendars']) && is_array($options['calendars']);
	$has_legacy_ids = !empty($options['ids']) && is_array($options['ids']);
	return $has_calendars || $has_legacy_ids;
}

/**
 * Number of consecutive failed sync cycles required before warning the admin.
 */
if (!defined('CTWPSYNC_FAILURE_WARN_THRESHOLD')) {
	define('CTWPSYNC_FAILURE_WARN_THRESHOLD', 4);
}

/**
 * Warn on the WP dashboard when the plugin is set up correctly but the last few
 * sync cycles all failed (e.g. ChurchTools API rate limiting / connection
 * errors). Suppressed until setup is complete so it does not nag new installs.
 */
add_action('admin_notices', 'ctwpsync_admin_notice_sync_failing');
function ctwpsync_admin_notice_sync_failing(): void {
	if (!current_user_can('manage_options')) {
		return;
	}
	$failures = (int) get_option('ctwpsync_consecutive_failures', 0);
	if ($failures < CTWPSYNC_FAILURE_WARN_THRESHOLD) {
		return;
	}
	// If the last run was aborted, the dedicated "aborted" notice below is more
	// specific and recent — defer to it to avoid stacking two red notices.
	if (get_option('ctwpsync_last_run_aborted')) {
		return;
	}
	// Only warn once the plugin is actually configured, per requirement.
	if (!ctwpsync_is_configured()) {
		return;
	}

	$last_error = get_option('ctwpsync_last_sync_error');
	$message = is_array($last_error) && !empty($last_error['message']) ? $last_error['message'] : '';
	$when = is_array($last_error) && !empty($last_error['time']) ? $last_error['time'] : '';
	$settings_url = admin_url('options-general.php?page=churchtools-wpcalendarsync');
	?>
	<div class="notice notice-error is-dismissible">
		<p>
			<strong>ChurchTools Calendar Sync:</strong>
			<?php
			printf(
				/* translators: %d: number of consecutive failed sync cycles */
				esc_html__('The last %d sync cycles failed, so events may be out of date.', 'ctwpsync'),
				(int) $failures
			);
			?>
			<?php if ($message !== ''): ?>
				<br>
				<em><?php echo esc_html__('Last error:', 'ctwpsync'); ?></em>
				<code><?php echo esc_html($message); ?></code>
				<?php if ($when !== ''): ?>
					<?php echo esc_html(sprintf('(%s)', $when)); ?>
				<?php endif; ?>
			<?php endif; ?>
			<br>
			<a href="<?php echo esc_url($settings_url); ?>"><?php echo esc_html__('Open ChurchTools Calendar Sync settings', 'ctwpsync'); ?></a>
		</p>
	</div>
	<?php
}

/**
 * Record that a sync run was aborted before completion — a hard kill (host
 * execution-time / memory limit) or fatal that the normal try/catch could not
 * handle, so nothing was written to the log by the sync itself. Drives the
 * "last sync aborted" admin notice.
 *
 * @param string $reason Human-readable reason shown in the notice/log
 */
function ctwpsync_record_sync_aborted(string $reason): void {
	// Mark a finish so the next-run check does not also flag this same run.
	update_option('ctwpsync_last_sync_finished_ts', time(), false);
	update_option('ctwpsync_last_run_aborted', [
		'message' => $reason,
		'time'    => current_time('mysql'), // site timezone, for admin display
	], false);
	// Clear the stuck "in progress" marker so the status panel doesn't show a
	// sync that will never finish.
	delete_transient('churchtools_wpcalendarsync_in_progress');
	// Best-effort log line (the sync's logger global may still be set). This is
	// what makes the abort visible in the log going forward.
	if (function_exists('logError')) {
		@logError('Sync aborted: ' . $reason);
	}
}

/**
 * Detect (on the next run) a previous sync that recorded a start but never a
 * finish. This covers a hard SIGKILL where the shutdown handler below could not
 * run. Called at the start of a sync, before the current run's start is recorded.
 */
function ctwpsync_check_previous_run_aborted(): void {
	$started  = (int) get_option('ctwpsync_last_sync_started_ts', 0);
	$finished = (int) get_option('ctwpsync_last_sync_finished_ts', 0);
	if ($started > 0 && $started > $finished) {
		ctwpsync_record_sync_aborted('the previous sync was terminated by the host before it finished (e.g. execution-time or memory limit); no completion was recorded');
	}
}

/**
 * Shutdown handler registered at sync start. Fires on PHP-level fatals and the
 * execution-time limit (which PHP still turns into a shutdown), recording the
 * abort before the request dies. A normal end sets $ctwpsync_run_completed, so
 * this only acts on genuine aborts. A true SIGKILL never reaches here; that case
 * is caught by ctwpsync_check_previous_run_aborted() on the next run.
 */
function ctwpsync_detect_aborted_sync(): void {
	if (!empty($GLOBALS['ctwpsync_run_completed'])) {
		return; // completed normally or via a caught exception
	}
	$last = error_get_last();
	$fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
	if ($last && ($last['type'] & $fatalMask)) {
		ctwpsync_record_sync_aborted('a fatal error stopped the sync before completion: ' . $last['message']);
	} else {
		ctwpsync_record_sync_aborted('the sync stopped before completion (most likely the host execution-time or memory limit)');
	}
}

/**
 * Warn on the WP dashboard when the last sync run was aborted mid-cycle (killed
 * by the host / fatal), i.e. it never completed and produced no normal error.
 * Shown immediately (not gated on a failure streak). Suppressed until setup is
 * complete. Cleared automatically on the next successful sync.
 */
add_action('admin_notices', 'ctwpsync_admin_notice_sync_aborted');
function ctwpsync_admin_notice_sync_aborted(): void {
	if (!current_user_can('manage_options')) {
		return;
	}
	$aborted = get_option('ctwpsync_last_run_aborted');
	if (empty($aborted)) {
		return;
	}
	if (!ctwpsync_is_configured()) {
		return;
	}
	$message = is_array($aborted) && !empty($aborted['message']) ? $aborted['message'] : 'it stopped before completing';
	$when = is_array($aborted) && !empty($aborted['time']) ? $aborted['time'] : '';
	$settings_url = admin_url('options-general.php?page=churchtools-wpcalendarsync');
	?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<strong>ChurchTools Calendar Sync:</strong>
			<?php echo esc_html__('The last sync did not finish —', 'ctwpsync'); ?>
			<?php echo esc_html($message); ?><?php if ($when !== ''): ?> <?php echo esc_html(sprintf('(%s)', $when)); ?><?php endif; ?>.
			<br>
			<?php echo esc_html__('This usually means the hosting stopped the process. Consider reducing the "future days" sync window, or ask your host to raise the PHP execution-time / memory limits.', 'ctwpsync'); ?>
			<br>
			<a href="<?php echo esc_url($settings_url); ?>"><?php echo esc_html__('Open ChurchTools Calendar Sync settings', 'ctwpsync'); ?></a>
		</p>
	</div>
	<?php
}

/**
 * Deactivate this plugin if Events Manager is deactivated
 */
add_action('deactivated_plugin', 'ctwpsync_check_events_manager_deactivation');
function ctwpsync_check_events_manager_deactivation(string $plugin): void {
	if ($plugin === 'events-manager/events-manager.php') {
		// Events Manager was deactivated, deactivate this plugin too
		deactivate_plugins(plugin_basename(__FILE__));
		add_action('admin_notices', function() {
			?>
			<div class="notice notice-warning is-dismissible">
				<p><strong>ChurchTools Calendar Sync</strong> has been deactivated because it requires the Events Manager plugin.</p>
			</div>
			<?php
		});
	}
}

add_action('admin_menu', 'ctwpsync_setup_menu');
add_action('save_ctwpsync_settings', 'save_ctwpsync_settings');

// Add Settings link to plugin actions on Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ctwpsync_add_settings_link');
function ctwpsync_add_settings_link(array $links): array {
	$settings_link = '<a href="' . admin_url('options-general.php?page=churchtools-wpcalendarsync') . '">' . __('Settings', 'ctwpsync') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'CTWPSYNC_VERSION', '1.4.0' );

function ctwpsync_setup_menu(): void {
	add_options_page('ChurchTools Calendar Importer', 'ChurchTools Calsync', 'manage_options', 'churchtools-wpcalendarsync', 'ctwpsync_dashboard');
	add_action('admin_init', 'register_ctwpsync_settings');
}

function register_ctwpsync_settings(): void {
	register_setting('ctwpsync-group', 'ctwpsync_url'); // URL to the churchtools installation
	register_setting('ctwpsync-group', 'ctwpsync_apitoken'); // API auth token
	register_setting('ctwpsync-group', 'ctwpsync_ids'); // Calendar IDs to sync from
	register_setting('ctwpsync-group', 'ctwpsync_ids_categories'); // Category for the above calendar id
	register_setting('ctwpsync-group', 'ctwpsync_import_past'); // Days in the past to sync
	register_setting('ctwpsync-group', 'ctwpsync_import_future'); // Days in the future to sync
	register_setting('ctwpsync-group', 'ctwpsync_resourcetype_for_categories'); // Sync categories from resources

	$myPage = sanitize_text_field($_GET['page'] ?? '');
	if ($myPage === str_replace('.php', '', basename(__FILE__))) {
		// Verify nonce before processing POST data
		if (isset($_POST['ctwpsync_nonce']) && wp_verify_nonce($_POST['ctwpsync_nonce'], 'ctwpsync_settings_save')) {
			if (!empty($_POST['ctwpsync_url'])) {
				save_ctwpsync_settings();
			}
		}
	}
}
function ctwpsync_dashboard(): void {
	$saved_data = get_option('ctwpsync_options');
	$lastupdated = get_transient('churchtools_wpcalendarsync_lastupdated');
	$lastsyncduration = get_transient('churchtools_wpcalendarsync_lastsyncduration');

	if (is_plugin_active('events-manager/events-manager.php')) {
		include_once(plugin_dir_path(__FILE__) . 'dashboard/dashboard_view.php');
	} else {
		echo "<div>";
		echo "<h2>ChurchTools Calendar Sync requires an active 'Events Manager' plugin</h2>";
		echo "<p>Please install and activate it first</p>";
		echo "<p><a href='https://de.wordpress.org/plugins/events-manager/'>https://de.wordpress.org/plugins/events-manager/</a></p>";
		echo "</div>";
	}
}

/**
 * Handle the form submission to save settings
 */
function save_ctwpsync_settings(): void {
	// Check user capabilities
	if (!current_user_can('manage_options')) {
		wp_die(__('You do not have sufficient permissions to access this page.'));
	}

	$saved_data = get_option('ctwpsync_options');

	// If API token is empty, keep the existing one
	if (empty($_POST['ctwpsync_apitoken']) && $saved_data && !empty($saved_data['apitoken'])) {
		$_POST['ctwpsync_apitoken'] = $saved_data['apitoken'];
	}

	$config = SyncConfig::fromPost();
	if ($config === null) {
		add_settings_error('ctwpsync_options', 'invalid_url', __('Invalid URL format. Please enter a valid ChurchTools URL.', 'ctwpsync'), 'error');
		return;
	}
	$data = $config->toArray();

	if ($saved_data) {
		update_option('ctwpsync_options', $data);
	} else {
		add_option('ctwpsync_options', $data);
	}

	// Note: Sync is no longer triggered on save. Use the "Sync Now" button instead.
}

/**
 * Add custom cron schedule for 57 minutes
 * Using 57 minutes instead of 60 to avoid always running at xx:00
 */
add_filter('cron_schedules', 'ctwpsync_add_cron_interval');
function ctwpsync_add_cron_interval(array $schedules): array {
	$schedules['every_57_minutes'] = [
		'interval' => 57 * 60, // 57 minutes in seconds
		'display'  => __('Every 57 minutes', 'ctwpsync'),
	];
	return $schedules;
}

/**
 * Get next scheduled time for a hook, regardless of arguments
 * wp_next_scheduled() requires exact args match, this searches the cron array directly
 *
 * @param string $hook_name The hook name to search for
 * @return int|false Timestamp of next scheduled event or false if not found
 */
function ctwpsync_get_next_scheduled(string $hook_name): int|false {
	$cron_array = _get_cron_array();
	if (!is_array($cron_array)) {
		return false;
	}

	foreach ($cron_array as $timestamp => $cron) {
		if (isset($cron[$hook_name])) {
			return $timestamp;
		}
	}

	return false;
}

/**
 * Return the directory used for plugin log files.
 *
 * Logs live under wp-content/uploads (not in the plugin directory, so they
 * survive plugin updates) inside a dedicated folder that is hardened against
 * direct web access. The directory is created on demand.
 *
 * @return string Absolute path to the log directory (no trailing slash)
 */
function ctwpsync_log_dir(): string {
	$upload = wp_upload_dir();
	$dir = trailingslashit($upload['basedir']) . 'ctwpsync-logs';
	if (!is_dir($dir)) {
		wp_mkdir_p($dir);
	}
	ctwpsync_protect_dir($dir);
	return $dir;
}

/**
 * Drop guard files into a directory to block direct web access and listing.
 *
 * Belt and suspenders across server types: index.php (blocks directory
 * listing), .htaccess (Apache) and web.config (IIS). Servers that honour none
 * of these (e.g. nginx) are covered by the unguessable log filename instead.
 *
 * @param string $dir Directory to protect
 */
function ctwpsync_protect_dir(string $dir): void {
	$guards = [
		'index.php'  => "<?php\n// Silence is golden.\n",
		'.htaccess'  => "Require all denied\n",
		'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<configuration>\n  <system.webServer>\n    <authorization>\n"
			. "      <deny users=\"*\" />\n    </authorization>\n"
			. "  </system.webServer>\n</configuration>\n",
	];
	foreach ($guards as $file => $content) {
		$path = $dir . DIRECTORY_SEPARATOR . $file;
		if (!file_exists($path)) {
			@file_put_contents($path, $content);
		}
	}
}

/**
 * Return the absolute path to the plugin log file.
 *
 * The filename carries an unguessable, per-site hash so that the log cannot be
 * fetched by URL even on servers that ignore .htaccess/web.config (nginx).
 *
 * @return string Absolute path to the log file
 */
function ctwpsync_log_file(): string {
	$hash = substr(wp_hash('ctwpsync-log-file'), 0, 16);
	return ctwpsync_log_dir() . DIRECTORY_SEPARATOR . "wpcalsync-{$hash}.log";
}

/**
 * Return the effective plugin log level, accounting for the CTWPSYNC_DEBUG
 * override. This is the single source of truth used both by the sync
 * (churchtools-dosync.php) and the settings-page log panel.
 *
 * @return string One of SyncConfig::LOG_LEVELS (ERROR / INFO / DEBUG)
 */
function ctwpsync_effective_log_level(): string {
	$options = get_option('ctwpsync_options');
	$level = SyncConfig::sanitizeLogLevel(is_array($options) ? ($options['log_level'] ?? 'INFO') : 'INFO');
	if (defined('CTWPSYNC_DEBUG') && CTWPSYNC_DEBUG) {
		$level = 'DEBUG'; // constant forces full verbosity regardless of the setting
	}
	return $level;
}

/**
 * Build a SyncLogger writing to the plugin log at the effective log level, for logging
 * from outside the sync flow (e.g. the admin de-duplication tools). Mirrors the logger
 * construction in churchtools-dosync.php so both write to the same file with the same
 * verbosity rules.
 *
 * @return SyncLogger
 */
function ctwpsync_get_logger(): SyncLogger {
	$level = ctwpsync_effective_log_level();
	return new SyncLogger(
		logFile: ctwpsync_log_file(),
		debugEnabled: $level === 'DEBUG',
		infoEnabled: in_array($level, ['DEBUG', 'INFO'], true),
	);
}

/**
 * Register a shutdown handler that records a fatal error (out-of-memory, host
 * execution-time kill, etc.) to the plugin log, including the peak memory and the
 * memory_limit so an abort can be diagnosed. AJAX callbacks can't otherwise report a
 * fatal — the browser only sees a generic "Request failed". Writes with a bare
 * `error_log()` (minimal allocation) so it still works right after an OOM.
 *
 * @param string $label Short label for the operation (e.g. "Image").
 */
function ctwpsync_register_dedupe_fatal_logger(string $label): void {
	$logFile = ctwpsync_log_file();
	register_shutdown_function(static function () use ($logFile, $label) {
		$err = error_get_last();
		if (!$err || !in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
			return;
		}
		$ts    = function_exists('wp_date') ? @wp_date('Y-m-d H:i:s') : date('Y-m-d H:i:s');
		$peak  = @round(memory_get_peak_usage(true) / 1048576, 1) . ' MB';
		$limit = @ini_get('memory_limit');
		@error_log(
			"[{$ts}] ERR: {$label} de-duplication aborted (fatal): {$err['message']} @ {$err['file']}:{$err['line']} (peak {$peak}, memory_limit {$limit})\n",
			3,
			$logFile
		);
	});
}

/**
 * Read the tail of the plugin log file for display in the admin.
 *
 * Only the last portion of the file is read (the log rotates at 5 MB), so this
 * stays cheap even for large logs. Always reads the fixed, plugin-controlled
 * path from ctwpsync_log_file() — never a caller-supplied path.
 *
 * @param int $maxLines Maximum number of trailing lines to return
 * @return string The trailing log lines (newline-separated), or '' if no log
 */
function ctwpsync_read_log_tail(int $maxLines = 300): string {
	$file = ctwpsync_log_file();
	if (!is_readable($file)) {
		return '';
	}
	$readBytes = 262144; // read at most the last 256 KB
	$fh = @fopen($file, 'rb');
	if (!$fh) {
		return '';
	}
	$size = @filesize($file);
	if ($size !== false && $size > $readBytes) {
		fseek($fh, -$readBytes, SEEK_END);
		fgets($fh); // discard the (probably partial) first line
	}
	$lines = [];
	while (($line = fgets($fh)) !== false) {
		$lines[] = rtrim($line, "\r\n");
	}
	fclose($fh);
	if (count($lines) > $maxLines) {
		$lines = array_slice($lines, -$maxLines);
	}
	return implode("\n", $lines);
}

/**
 * AJAX: return the tail of the sync log for the admin log viewer.
 */
add_action('wp_ajax_ctwpsync_get_log', 'ctwpsync_get_log_callback');
function ctwpsync_get_log_callback(): void {
	if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ctwpsync_validate')) {
		wp_send_json_error('Security check failed');
	}
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Permission denied');
	}
	$log = ctwpsync_read_log_tail(300);
	wp_send_json_success([
		'log'   => $log,
		'empty' => ($log === ''),
	]);
}

/**
 * AJAX: clear the sync log (truncate current file and drop the rotated copy).
 */
add_action('wp_ajax_ctwpsync_clear_log', 'ctwpsync_clear_log_callback');
function ctwpsync_clear_log_callback(): void {
	if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ctwpsync_validate')) {
		wp_send_json_error('Security check failed');
	}
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Permission denied');
	}
	$file = ctwpsync_log_file();
	@file_put_contents($file, '');
	@unlink($file . '.1'); // rotated previous generation, if any
	wp_send_json_success('Log cleared');
}

/**
 * AJAX: stream the full log file as a download. Uses a GET nonce because it is
 * opened as a link. Always serves the fixed plugin log path.
 */
add_action('wp_ajax_ctwpsync_download_log', 'ctwpsync_download_log_callback');
function ctwpsync_download_log_callback(): void {
	if (!wp_verify_nonce($_GET['nonce'] ?? '', 'ctwpsync_validate')) {
		wp_die('Security check failed');
	}
	if (!current_user_can('manage_options')) {
		wp_die('Permission denied');
	}
	$file = ctwpsync_log_file();
	if (!is_readable($file)) {
		wp_die('No log file found');
	}
	nocache_headers();
	header('Content-Type: text/plain; charset=utf-8');
	header('Content-Disposition: attachment; filename="ctwpsync-log-' . gmdate('Ymd-His') . '.txt"');
	header('Content-Length: ' . filesize($file));
	readfile($file);
	exit;
}

/**
 * Normalise a WordPress attachment file path (`_wp_attached_file`, e.g.
 * `2026/11/pic-1-scaled.jpg`) to a "base key" that groups the WordPress `-N` collision
 * variants and `-scaled` working copies of the same upload together
 * (`2026/11|pic|jpg`). Returns null if the path has no usable name/extension.
 *
 * @param string $file Relative attachment file path.
 * @return string|null Base key "dir|name|ext", or null.
 */
function ctwpsync_image_base_key(string $file): ?string {
	if ($file === '') {
		return null;
	}
	$dir  = dirname($file);
	$dir  = ($dir === '.' || $dir === '') ? '' : $dir;
	$ext  = pathinfo($file, PATHINFO_EXTENSION);
	$name = pathinfo($file, PATHINFO_FILENAME);
	$name = preg_replace('/-scaled$/', '', $name);
	$name = preg_replace('/-\d+$/', '', $name);
	if ($name === '' || $ext === '') {
		return null;
	}
	return $dir . '|' . $name . '|' . strtolower($ext);
}

/**
 * Collapse duplicate event-image attachments created before the
 * `_ctwpsync_ct_image_id` de-duplication existed.
 *
 * Older versions re-downloaded and re-uploaded the same ChurchTools image, so
 * WordPress produced `name-1.jpg`, `name-2.jpg`, … copies (each with a full set of
 * generated sub-sizes). Most copies are now *orphaned* — the event points only at the
 * most recent one — so they cannot be found through the event→thumbnail mapping (which
 * is why the old mapping-only scan reported nothing). This routine instead:
 *   - starts from every image attachment the plugin is responsible for (stamped with
 *     `_ctwpsync_ct_image_id`, recorded in the mapping's `wp_image_id`, or currently a
 *     synced event's featured image),
 *   - for each, finds sibling attachments whose uploaded file shares the same base name
 *     in the same folder (WordPress' `-N` / `-scaled` variants),
 *   - confirms they are byte-identical (md5 of the actual file) so unrelated images
 *     that merely share a name are never merged,
 *   - keeps the lowest attachment id as the canonical copy, re-points every featured
 *     image and the mapping's `wp_image_id` to it, and deletes the redundant copies
 *     (files + sub-sizes) — skipping any copy whose URL still appears in post content.
 *
 * EM must be active (hard dependency).
 *
 * Cleanup work is time-boxed: after `$budgetSeconds` of deleting it stops and returns
 * `more => true`, so a front-end proxy timeout can't kill a large clean-up mid-way. The
 * caller re-invokes until `more` is false. The dry-run scan is not time-boxed (it makes
 * no changes and must report the full total).
 *
 * @param bool  $dryRun        When true, report what would change but make no changes.
 * @param float $budgetSeconds Cleanup time budget per call (0 = unlimited).
 * @return array{images:int,dupe_groups:int,events_repointed:int,attachments_deleted:int,skipped:int,more:bool,errors:array<int,string>}
 */
function ctwpsync_dedupe_images(bool $dryRun = true, float $budgetSeconds = 45.0): array {
	global $wpdb;
	$tablename = $wpdb->prefix . 'ctwpsync_mapping';
	$startTime = microtime(true);

	$stats = [
		'images'              => 0,
		'dupe_groups'         => 0,
		'events_repointed'    => 0,
		'attachments_deleted' => 0,
		'skipped'             => 0,
		'more'                => false,
		'errors'              => [],
	];

	if (!function_exists('em_get_event')) {
		$stats['errors'][] = 'Events Manager is not active.';
		return $stats;
	}

	// 1. Collect the image attachments this plugin is responsible for.
	$pluginAtts = [];

	// a) attachments stamped by the current sync code
	$stamped = get_posts([
		'post_type'      => 'attachment',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_ctwpsync_ct_image_id',
	]);
	foreach ($stamped as $id) {
		$pluginAtts[(int) $id] = true;
	}

	// b) attachments recorded in the mapping
	$mapImgIds = $wpdb->get_col("SELECT DISTINCT wp_image_id FROM `{$tablename}` WHERE wp_image_id IS NOT NULL AND wp_image_id > 0");
	foreach ($mapImgIds as $id) {
		$pluginAtts[(int) $id] = true;
	}

	// c) current featured image of every synced event, plus the CT image id it belongs to
	//    (so the surviving copy can be stamped for reuse). Resolved in a single SQL join
	//    — mapping → Events Manager events table (post_id) → postmeta(_thumbnail_id) —
	//    rather than instantiating an EM_Event object per event, which is far too slow on
	//    calendars with many repeating occurrences (it made the scan time out on the proxy).
	$attToCtImage = [];
	$emTable = $wpdb->prefix . 'em_events';
	$hasEmTable = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $emTable)) === $emTable);
	if ($hasEmTable) {
		$frows = $wpdb->get_results(
			"SELECT pm.meta_value AS thumb_id, m.ct_image_id
			 FROM `{$tablename}` m
			 INNER JOIN `{$emTable}` e ON e.event_id = m.wp_id
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = e.post_id AND pm.meta_key = '_thumbnail_id'
			 WHERE m.wp_id > 0"
		);
		foreach ($frows as $fr) {
			$thumb = (int) $fr->thumb_id;
			if ($thumb > 0) {
				$pluginAtts[$thumb] = true;
				if (!empty($fr->ct_image_id) && (int) $fr->ct_image_id > 0 && !isset($attToCtImage[$thumb])) {
					$attToCtImage[$thumb] = (int) $fr->ct_image_id;
				}
			}
		}
	} else {
		// Fallback via the EM API if the events table name differs (slower).
		$eventRows = $wpdb->get_results("SELECT wp_id, ct_image_id FROM `{$tablename}` WHERE wp_id IS NOT NULL AND wp_id > 0");
		foreach ($eventRows as $er) {
			$ev = function_exists('em_get_event') ? em_get_event((int) $er->wp_id) : null;
			if ($ev && !empty($ev->ID)) {
				$thumb = (int) get_post_thumbnail_id((int) $ev->ID);
				if ($thumb > 0) {
					$pluginAtts[$thumb] = true;
					if (!empty($er->ct_image_id) && $er->ct_image_id > 0 && !isset($attToCtImage[$thumb])) {
						$attToCtImage[$thumb] = (int) $er->ct_image_id;
					}
				}
			}
		}
	}

	if (!$pluginAtts) {
		return $stats;
	}

	$uploadBase = wp_get_upload_dir()['basedir'];

	// 2. Distinct "base file" keys of the plugin's own attachments (targeted query, small).
	$pluginBaseKeys = [];
	foreach (array_chunk(array_keys($pluginAtts), 500) as $chunk) {
		$ph   = implode(',', array_fill(0, count($chunk), '%d'));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT meta_value AS f FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id IN ($ph)",
			...$chunk
		));
		foreach ($rows as $r) {
			$bk = ctwpsync_image_base_key((string) $r->f);
			if ($bk !== null) {
				$pluginBaseKeys[$bk] = true;
			}
		}
	}
	if (!$pluginBaseKeys) {
		return $stats;
	}

	// 3. One bulk query over all attachment files, keeping only those in a plugin base
	//    key. This replaces a per-base LIKE scan of postmeta (one full scan per image),
	//    which made the scan run for minutes and get killed by the proxy timeout.
	$byBase   = []; // baseKey => [ ['id' => int, 'rel' => string], ... ]
	$allFiles = $wpdb->get_results(
		"SELECT pm.post_id, pm.meta_value AS f
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_wp_attached_file' AND p.post_type = 'attachment'"
	);
	foreach ($allFiles as $row) {
		$bk = ctwpsync_image_base_key((string) $row->f);
		if ($bk === null || !isset($pluginBaseKeys[$bk])) {
			continue;
		}
		$byBase[$bk][] = ['id' => (int) $row->post_id, 'rel' => (string) $row->f];
	}
	unset($allFiles);

	// 4. Process each base key. The dry-run scan only groups by file size (a cheap stat,
	//    no content read) to estimate the count and stays fast on huge libraries; the
	//    cleanup byte-verifies (md5) before deleting, in time-boxed batches.
	foreach ($byBase as $candidates) {
		$stats['images']++;
		if (count($candidates) <= 1) {
			continue;
		}

		// Group byte-identical files (size pre-filter; md5 only during cleanup).
		$bySize = [];
		foreach ($candidates as $c) {
			$abs = rtrim($uploadBase, '/\\') . '/' . $c['rel'];
			if (!is_file($abs)) {
				continue;
			}
			$size = @filesize($abs);
			if ($size === false) {
				continue;
			}
			$bySize[$size][] = ['id' => (int) $c['id'], 'abs' => $abs];
		}

		if ($dryRun) {
			// Estimate only: same base + same size ⇒ a duplicate set (the -N / -scaled
			// copies are byte-identical). No file contents are read.
			foreach ($bySize as $sizeSet) {
				if (count($sizeSet) > 1) {
					$stats['dupe_groups']++;
					$stats['attachments_deleted'] += count($sizeSet) - 1;
				}
			}
			continue;
		}

		// Cleanup: stop if this request is out of its time budget.
		if ($budgetSeconds > 0 && (microtime(true) - $startTime) > $budgetSeconds) {
			$stats['more'] = true;
			break;
		}

		$identicalGroups = [];
		foreach ($bySize as $sizeSet) {
			if (count($sizeSet) <= 1) {
				continue; // unique file size cannot be a byte-identical duplicate
			}
			$byHash = [];
			foreach ($sizeSet as $c) {
				$hash = @md5_file($c['abs']);
				if ($hash === false) {
					continue;
				}
				$byHash[$hash][] = $c['id'];
			}
			foreach ($byHash as $ids) {
				if (count($ids) > 1) {
					$identicalGroups[] = $ids;
				}
			}
		}

		foreach ($identicalGroups as $attIds) {
			sort($attIds);
			$canonical = (int) $attIds[0];
			$dupes     = array_slice($attIds, 1);
			$stats['dupe_groups']++;

			// Determine the CT image id for this group: an existing stamp on any member,
			// else the mapping's ct_image_id for whichever member is a synced event's
			// featured image. Stamp the canonical so future re-adds reuse it.
			$ctImageId = null;
			foreach ($attIds as $a) {
				$v = get_post_meta($a, '_ctwpsync_ct_image_id', true);
				if ($v !== '' && $v !== false && $v !== null) {
					$ctImageId = (int) $v;
					break;
				}
			}
			if ($ctImageId === null) {
				foreach ($attIds as $a) {
					if (isset($attToCtImage[$a])) {
						$ctImageId = $attToCtImage[$a];
						break;
					}
				}
			}
			if (!$dryRun && $ctImageId !== null) {
				update_post_meta($canonical, '_ctwpsync_ct_image_id', $ctImageId);
				$wpdb->query($wpdb->prepare(
					"UPDATE `{$tablename}` SET wp_image_id = %d WHERE ct_image_id = %d",
					$canonical, $ctImageId
				));
			}

			foreach ($dupes as $dupeId) {
				// Time-box the cleanup: stop before a front-end proxy timeout can kill the
				// request, and let the caller re-invoke to continue with the rest.
				if (!$dryRun && $budgetSeconds > 0 && (microtime(true) - $startTime) > $budgetSeconds) {
					$stats['more'] = true;
					break 3; // exit dupes, identicalGroups and baseKeys loops
				}

				// Re-point featured images that point at this duplicate.
				$featuredPosts = get_posts([
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_thumbnail_id',
					'meta_value'     => $dupeId,
				]);
				foreach ($featuredPosts as $p) {
					if (!$dryRun) {
						set_post_thumbnail((int) $p, $canonical);
					}
					$stats['events_repointed']++;
				}

				// Re-point the mapping's wp_image_id, and record the canonical.
				if (!$dryRun) {
					$wpdb->query($wpdb->prepare(
						"UPDATE `{$tablename}` SET wp_image_id = %d WHERE wp_image_id = %d",
						$canonical, $dupeId
					));
				}

				// If the duplicate's URL is embedded in post content, leave it in place
				// rather than risk a broken link (sized-variant URLs make a safe rewrite
				// unreliable). Report it as skipped.
				$dupeUrl   = wp_get_attachment_url($dupeId);
				$inContent = [];
				if ($dupeUrl) {
					$inContent = $wpdb->get_col($wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_status != 'trash' AND post_content LIKE %s",
						'%' . $wpdb->esc_like($dupeUrl) . '%'
					));
				}
				if (!empty($inContent)) {
					$stats['skipped']++;
					$stats['errors'][] = "Attachment {$dupeId} left in place: its URL appears in post(s) " . implode(',', $inContent);
					continue;
				}

				if (!$dryRun) {
					if (!wp_delete_attachment($dupeId, true)) {
						$stats['errors'][] = "Failed to delete attachment {$dupeId}";
						continue;
					}
				}
				$stats['attachments_deleted']++;
			}
		}
	}

	// Safety: if a time-boxed cleanup batch deleted nothing (e.g. everything remaining is
	// skipped because it is referenced in post content), don't ask the caller to loop.
	if (!$dryRun && $stats['more'] && $stats['attachments_deleted'] === 0) {
		$stats['more'] = false;
	}

	return $stats;
}

/**
 * AJAX: scan for (dry run) or perform the event-image de-duplication.
 * Pass `confirm=1` to actually apply changes; otherwise it only reports.
 */
add_action('wp_ajax_ctwpsync_dedupe_images', 'ctwpsync_dedupe_images_callback');
function ctwpsync_dedupe_images_callback(): void {
	if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ctwpsync_validate')) {
		wp_send_json_error('Security check failed');
	}
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Permission denied');
	}
	@set_time_limit(300);
	if (function_exists('wp_raise_memory_limit')) {
		wp_raise_memory_limit('admin');
	}
	ctwpsync_register_dedupe_fatal_logger('Image');
	$dryRun = (($_POST['confirm'] ?? '') !== '1');
	$logger = ctwpsync_get_logger();
	$logger->info('Image de-duplication ' . ($dryRun ? 'scan' : 'cleanup') . ' starting');

	// Capture any stray PHP notice/warning output (e.g. when WP_DEBUG_DISPLAY is on):
	// printed before the JSON it would corrupt the response and the browser would show a
	// generic "Request failed" (a JSON parse error). We log it instead so the JSON stays valid.
	ob_start();
	try {
		$stats = ctwpsync_dedupe_images($dryRun);
	} catch (\Throwable $e) {
		$noise = trim((string) ob_get_clean());
		$logger->error('Image de-duplication failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		if ($noise !== '') {
			$logger->error('Image de-duplication output before failure: ' . mb_substr($noise, 0, 2000));
		}
		wp_send_json_error('De-duplication failed: ' . $e->getMessage());
	}
	$noise = trim((string) ob_get_clean());
	if ($noise !== '') {
		$logger->error('Image de-duplication produced unexpected output (suppressed to keep the response valid): ' . mb_substr($noise, 0, 2000));
	}

	$logger->info(sprintf(
		'Image de-duplication %s: %d image set(s) checked, %d duplicate set(s), %d featured image(s) re-pointed, %d attachment(s) %s, %d skipped%s',
		$dryRun ? 'scan (dry run)' : 'cleanup batch',
		$stats['images'], $stats['dupe_groups'], $stats['events_repointed'],
		$stats['attachments_deleted'], $dryRun ? 'to delete' : 'deleted',
		$stats['skipped'],
		(!$dryRun && !empty($stats['more'])) ? ' (more remaining — continuing)' : ''
	));
	foreach ($stats['errors'] as $note) {
		$logger->info('Image de-duplication note: ' . $note);
	}

	$stats['dry_run'] = $dryRun;
	wp_send_json_success($stats);
}

/**
 * Collapse duplicate flyer (event-file) attachments created before the
 * `_ctwpsync_ct_flyer_id` de-duplication existed.
 *
 * Unlike images, flyers are not featured images — they are embedded as `<a href>`
 * links in each event's post_content (see addFlyerLink). So for each ChurchTools
 * flyer id referenced by the mapping this routine:
 *   - picks the lowest still-existing attachment id as the canonical copy,
 *   - stamps it with `_ctwpsync_ct_flyer_id` so future syncs reuse it,
 *   - rewrites the duplicate's URL to the canonical URL in the content of every
 *     event that used it, and sets the mapping's `wp_flyer_id` to the canonical,
 *   - deletes the redundant duplicate attachments — but only once nothing (any post
 *     content or featured-image reference) still points at them, so no link is left
 *     dangling.
 *
 * Editing published post content is higher-risk than the image cleanup; run the dry
 * run first and back up. EM must be active (hard dependency).
 *
 * @param bool $dryRun When true, report what would change but make no changes.
 * @return array{flyers:int,dupe_groups:int,events_rewritten:int,attachments_deleted:int,skipped:int,errors:array<int,string>}
 */
function ctwpsync_dedupe_flyers(bool $dryRun = true): array {
	global $wpdb;
	$tablename = $wpdb->prefix . 'ctwpsync_mapping';

	$stats = [
		'flyers'              => 0,
		'dupe_groups'         => 0,
		'events_rewritten'    => 0,
		'attachments_deleted' => 0,
		'skipped'             => 0,
		'errors'              => [],
	];

	if (!function_exists('em_get_event')) {
		$stats['errors'][] = 'Events Manager is not active.';
		return $stats;
	}

	$rows = $wpdb->get_results(
		"SELECT ct_flyer_id, wp_id, wp_flyer_id FROM `{$tablename}` WHERE ct_flyer_id IS NOT NULL AND ct_flyer_id > 0 AND wp_flyer_id IS NOT NULL AND wp_flyer_id > 0"
	);
	if (!$rows) {
		return $stats;
	}

	// Resolve event_id -> post_id in bulk via the Events Manager events table, rather
	// than loading an EM_Event object per row.
	$eventIds = [];
	foreach ($rows as $r) {
		$eventIds[(int) $r->wp_id] = true;
	}
	$eventIds  = array_keys($eventIds);
	$postByEvent = [];
	$emTable = $wpdb->prefix . 'em_events';
	if ($eventIds && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $emTable)) === $emTable) {
		$ph  = implode(',', array_fill(0, count($eventIds), '%d'));
		$ers = $wpdb->get_results($wpdb->prepare("SELECT event_id, post_id FROM `{$emTable}` WHERE event_id IN ($ph)", ...$eventIds));
		foreach ($ers as $er) {
			$postByEvent[(int) $er->event_id] = (int) $er->post_id;
		}
	} else {
		foreach ($eventIds as $eid) {
			$ev = function_exists('em_get_event') ? em_get_event($eid) : null;
			if ($ev && !empty($ev->ID)) {
				$postByEvent[$eid] = (int) $ev->ID;
			}
		}
	}

	// Group by CT flyer id: flyer attachment id => list of post ids that link it.
	$byFlyer = []; // ctFlyerId => [ wpFlyerAttId => [postId, ...] ]
	foreach ($rows as $r) {
		$postId = $postByEvent[(int) $r->wp_id] ?? 0;
		if ($postId <= 0) {
			continue;
		}
		$byFlyer[(int) $r->ct_flyer_id][(int) $r->wp_flyer_id][] = $postId;
	}

	foreach ($byFlyer as $ctFlyerId => $attMap) {
		$stats['flyers']++;

		// Keep only attachment ids that still exist.
		$existingAtts = [];
		foreach (array_keys($attMap) as $attId) {
			if (get_post($attId)) {
				$existingAtts[] = (int) $attId;
			}
		}
		if (!$existingAtts) {
			continue;
		}
		$canonical    = min($existingAtts);
		$canonicalUrl = wp_get_attachment_url($canonical);

		// All post ids in this group (used to scope the "referenced elsewhere" guard).
		$groupPostIds = [];
		foreach ($attMap as $postIds) {
			$groupPostIds = array_merge($groupPostIds, $postIds);
		}
		$groupPostIds = array_values(array_unique($groupPostIds));

		if (!$dryRun) {
			update_post_meta($canonical, '_ctwpsync_ct_flyer_id', $ctFlyerId);
			$wpdb->query($wpdb->prepare(
				"UPDATE `{$tablename}` SET wp_flyer_id = %d WHERE ct_flyer_id = %d",
				$canonical, $ctFlyerId
			));
		}

		// A group with a single attachment has no duplicates to collapse.
		if (count($existingAtts) <= 1) {
			continue;
		}
		$stats['dupe_groups']++;

		foreach ($attMap as $attId => $postIds) {
			$attId = (int) $attId;
			if ($attId === $canonical || !in_array($attId, $existingAtts, true)) {
				continue;
			}
			$oldUrl = wp_get_attachment_url($attId);

			// Rewrite this duplicate's URL to the canonical URL in each event's content.
			if ($oldUrl && $canonicalUrl) {
				foreach (array_unique($postIds) as $postId) {
					$post = get_post($postId);
					if (!$post || strpos($post->post_content, $oldUrl) === false) {
						continue;
					}
					if (!$dryRun) {
						$newContent = str_replace($oldUrl, $canonicalUrl, $post->post_content);
						wp_update_post(['ID' => $postId, 'post_content' => $newContent]);
					}
					$stats['events_rewritten']++;
				}
			}

			// Only delete once the old attachment is referenced nowhere. Check post
			// content (its URL) and featured-image use. In a dry run the content has
			// not been rewritten yet, so discount this group's own posts.
			$contentRefs = $oldUrl ? $wpdb->get_col($wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status != 'trash' AND post_content LIKE %s",
				'%' . $wpdb->esc_like($oldUrl) . '%'
			)) : [];
			$thumbRefs = get_posts([
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_thumbnail_id',
				'meta_value'     => $attId,
			]);
			$contentRefs = array_map('intval', $contentRefs);
			if ($dryRun) {
				$remaining = array_merge(
					array_diff($contentRefs, $groupPostIds),
					array_diff($thumbRefs, $groupPostIds)
				);
			} else {
				// Content already rewritten; anything still pointing here is external.
				$remaining = array_merge($contentRefs, $thumbRefs);
			}
			if (!empty($remaining)) {
				$stats['skipped']++;
				$stats['errors'][] = "Flyer attachment {$attId} left in place: still referenced by post(s) " . implode(',', array_unique($remaining));
				continue;
			}
			if (!$dryRun) {
				if (!wp_delete_attachment($attId, true)) {
					$stats['errors'][] = "Failed to delete flyer attachment {$attId}";
					continue;
				}
			}
			$stats['attachments_deleted']++;
		}
	}

	return $stats;
}

/**
 * AJAX: scan for (dry run) or perform the flyer (event-file) de-duplication.
 * Pass `confirm=1` to actually apply changes; otherwise it only reports.
 */
add_action('wp_ajax_ctwpsync_dedupe_flyers', 'ctwpsync_dedupe_flyers_callback');
function ctwpsync_dedupe_flyers_callback(): void {
	if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ctwpsync_validate')) {
		wp_send_json_error('Security check failed');
	}
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Permission denied');
	}
	@set_time_limit(300);
	if (function_exists('wp_raise_memory_limit')) {
		wp_raise_memory_limit('admin');
	}
	ctwpsync_register_dedupe_fatal_logger('Flyer');
	$dryRun = (($_POST['confirm'] ?? '') !== '1');
	$logger = ctwpsync_get_logger();
	$logger->info('Flyer de-duplication ' . ($dryRun ? 'scan' : 'cleanup') . ' starting');

	// Capture any stray notice/warning output so it can't corrupt the JSON response
	// (see the image callback for why); log it instead.
	ob_start();
	try {
		$stats = ctwpsync_dedupe_flyers($dryRun);
	} catch (\Throwable $e) {
		$noise = trim((string) ob_get_clean());
		$logger->error('Flyer de-duplication failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		if ($noise !== '') {
			$logger->error('Flyer de-duplication output before failure: ' . mb_substr($noise, 0, 2000));
		}
		wp_send_json_error('De-duplication failed: ' . $e->getMessage());
	}
	$noise = trim((string) ob_get_clean());
	if ($noise !== '') {
		$logger->error('Flyer de-duplication produced unexpected output (suppressed to keep the response valid): ' . mb_substr($noise, 0, 2000));
	}

	$logger->info(sprintf(
		'Flyer de-duplication %s: %d flyer(s) checked, %d duplicate set(s), %d event link(s) rewritten, %d attachment(s) %s, %d skipped',
		$dryRun ? 'scan (dry run)' : 'cleanup',
		$stats['flyers'], $stats['dupe_groups'], $stats['events_rewritten'],
		$stats['attachments_deleted'], $dryRun ? 'to delete' : 'deleted',
		$stats['skipped']
	));
	foreach ($stats['errors'] as $note) {
		$logger->info('Flyer de-duplication note: ' . $note);
	}

	$stats['dry_run'] = $dryRun;
	wp_send_json_success($stats);
}

/**
 * One-time migration: move pre-1.3.4 log files out of the web-accessible
 * plugin/vendor directories into the protected uploads log directory.
 *
 * Runs once (guarded by an option flag). If a destination file already exists,
 * the old content is appended and the source removed.
 */
function ctwpsync_migrate_logs(): void {
	if (get_option('ctwpsync_logs_migrated')) {
		return;
	}

	$newDir = ctwpsync_log_dir(); // ensures dir + guard files exist
	$moves = [
		// Old plugin log -> new hashed log file
		plugin_dir_path(__FILE__) . 'wpcalsync.log' => ctwpsync_log_file(),
		// churchtools-api library logs (fixed path inside vendor, not configurable)
		__DIR__ . '/vendor/5pm-hdh/churchtools-api/churchtools-api.log'
			=> $newDir . DIRECTORY_SEPARATOR . 'churchtools-api.log',
		__DIR__ . '/vendor/5pm-hdh/churchtools-api/churchtools-api-warning.log'
			=> $newDir . DIRECTORY_SEPARATOR . 'churchtools-api-warning.log',
	];

	foreach ($moves as $old => $new) {
		if (!@is_file($old)) {
			continue;
		}
		if (@is_file($new)) {
			// Destination exists: append old content, then drop the old file
			$data = @file_get_contents($old);
			if ($data !== false) {
				@file_put_contents($new, $data, FILE_APPEND);
			}
			@unlink($old);
		} elseif (!@rename($old, $new)) {
			// rename can fail across filesystems; fall back to copy + delete
			if (@copy($old, $new)) {
				@unlink($old);
			}
		}
	}

	update_option('ctwpsync_logs_migrated', true);
}
add_action('plugins_loaded', 'ctwpsync_migrate_logs', 4); // before settings/init migrations

/**
 * Schedule the event when the plugin is activated, if not already scheduled
 * and run it immediately for the first time
 */
register_activation_hook(__FILE__, 'ctwpsync_activation');
function ctwpsync_activation(): void {
	// Check if Events Manager is active before allowing activation
	if (!ctwpsync_is_events_manager_active()) {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die(
			'<strong>ChurchTools Calendar Sync</strong> requires the <a href="https://wordpress.org/plugins/events-manager/">Events Manager</a> plugin to be installed and activated first.',
			'Plugin Activation Error',
			['back_link' => true]
		);
	}

	// Clear ALL existing scheduled events for this hook first to prevent duplicates.
	// wp_unschedule_hook() removes events regardless of their args;
	// wp_clear_scheduled_hook() without args only matches events scheduled with no args
	// and silently leaves events that carry args behind.
	wp_unschedule_hook('ctwpsync_hourly_event');

	// Mark that we need to schedule the cron event
	// The actual scheduling happens in ctwpsync_initplugin() where the filter is guaranteed to be active
	update_option('ctwpsync_needs_cron_schedule', true);
}

/**
 * Hook the function to run every 57 minutes
 *
 * We need to pass in the user
 */
add_action('ctwpsync_hourly_event', 'do_this_ctwpsync_hourly', 10, 2);
function do_this_ctwpsync_hourly(bool $is_user_logged_in, $current_user): void {
	// Events are scheduled with a plain user ID; events created by older
	// plugin versions may still carry a full WP_User object as arg
	$user_id = $current_user instanceof WP_User ? (int) $current_user->ID : (int) $current_user;
	wp_set_current_user($user_id);
	do_action('ctwpsync_includeChurchcalSync');
	if (function_exists('ctwpsync_getUpdatedCalendarEvents')) {
		$result = ctwpsync_getUpdatedCalendarEvents();
	}
}

add_action('ctwpsync_includeChurchcalSync', 'ctwpsync_includeChurchcalSync');
function ctwpsync_includeChurchcalSync(): void {
	include(plugin_dir_path(__FILE__) . 'churchtools-dosync.php');
}

/**
 * Clear the scheduled event when the plugin is disabled
 */
register_deactivation_hook(__FILE__, 'ctwpsync_deactivation');
function ctwpsync_deactivation(): void {
	// wp_unschedule_hook() clears events regardless of args (see ctwpsync_activation)
	wp_unschedule_hook('ctwpsync_hourly_event');
}

/**
 * Initialize plugin - create tables and run migrations
 */
function ctwpsync_initplugin(): void {
    global $wpdb;

    $hook_name = 'ctwpsync_hourly_event';
    $cron_scheduled_this_request = false;

    // Check if we need to schedule cron (set during activation)
    // Use atomic check to prevent race conditions with concurrent requests
    if (get_option('ctwpsync_needs_cron_schedule')) {
        // Try to acquire lock (delete returns true if option existed)
        if (delete_option('ctwpsync_needs_cron_schedule')) {
            // Clear any existing events first, regardless of their args
            wp_unschedule_hook($hook_name);

            // Schedule the cron event now that filters are active
            $args = [is_user_logged_in(), get_current_user_id()];
            wp_schedule_event(time(), 'every_57_minutes', $hook_name, $args);
            $cron_scheduled_this_request = true;
        }
    }

    // Skip further processing if we just scheduled during this request
    if ($cron_scheduled_this_request) {
        // Still run table creation below, but skip cron management
    } else {
        // Get all scheduled events for our hook
        $cron_array = _get_cron_array();
        $scheduled_events = array();

        if (is_array($cron_array)) {
            foreach ($cron_array as $timestamp => $cron) {
                if (isset($cron[$hook_name])) {
                    foreach ($cron[$hook_name] as $hash => $event) {
                        $scheduled_events[] = array(
                            'timestamp' => $timestamp,
                            'hash' => $hash,
                            'args' => isset($event['args']) ? $event['args'] : array(),
                            'schedule' => isset($event['schedule']) ? $event['schedule'] : 'unknown',
                        );
                    }
                }
            }
        }

        // Reschedule if any event uses an outdated schedule (pre-1.1.0 'hourly'),
        // still carries a WP_User object instead of a user ID as arg (older versions),
        // or duplicates exist
        $needs_reschedule = count($scheduled_events) > 1;
        foreach ($scheduled_events as $event) {
            if ($event['schedule'] !== 'every_57_minutes' || !isset($event['args'][1]) || !is_int($event['args'][1])) {
                $needs_reschedule = true;
                break;
            }
        }

        if ($needs_reschedule) {
            // Preserve the sync user from existing events when the current
            // request is anonymous (frontend/cron), so the sync keeps its owner
            $user_id = get_current_user_id();
            foreach ($scheduled_events as $event) {
                if ($user_id > 0) {
                    break;
                }
                $arg = isset($event['args'][1]) ? $event['args'][1] : 0;
                $user_id = $arg instanceof WP_User ? (int) $arg->ID : (int) $arg;
            }

            // wp_unschedule_hook() removes all events for the hook regardless of
            // args; wp_clear_scheduled_hook() without args would leave every
            // event that was scheduled with args behind
            wp_unschedule_hook($hook_name);
            wp_schedule_event(time(), 'every_57_minutes', $hook_name, [$user_id > 0, $user_id]);
        } else if (empty($scheduled_events)) {
            // No cron event exists - schedule one
            $args = [is_user_logged_in(), get_current_user_id()];
            wp_schedule_event(time(), 'every_57_minutes', $hook_name, $args);
        }
    }

    $table_name = $wpdb->prefix.'ctwpsync_mapping';
    $sql = "CREATE TABLE ".$table_name." (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ct_id mediumint(9) NOT NULL,
            ct_repeating mediumint(9),
            wp_id mediumint(9) NOT NULL,
            ct_image_id mediumint(9),
            wp_image_id mediumint(9),
            ct_flyer_id mediumint(9),
            wp_flyer_id mediumint(9),
            last_seen datetime NOT NULL,
            event_start datetime NOT NULL,
            event_end datetime NOT NULL,
            UNIQUE KEY id (id)
            );";
    require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
    dbDelta($sql);

    $result = $wpdb->get_results("SHOW COLUMNS FROM `".$table_name."` LIKE 'ct_repeating'");
    $exists = count($result) > 0 ? TRUE : FALSE;
    if ($exists) {
        // OK, new table format
    } else {
        // Need to add repeating column and change unique constraint on ct_id index
        $sql= "alter table `".$table_name."` ADD COLUMN `ct_repeating` mediumint(9) NULL DEFAULT '0';";
        $wpdb->query($sql);
        $sql= "alter table `".$table_name."` DROP INDEX `ct_id`;";
        $wpdb->query($sql);
        $sql= "alter table `".$table_name."` ADD INDEX `ct_id` (`ct_id`);";
        $wpdb->query($sql);
    }

    // Run one-time migration for Events Manager 7.1+ compatibility
    // This updates existing events to use event_type="single" and post_status="publish"
    if (is_plugin_active('events-manager/events-manager.php')) {
        // Check if migration is needed (hasn't been run yet)
        $migration_completed = get_option('ctwpsync_em71_migration_completed');
        if (!$migration_completed) {
            // Include the sync file to make migration function available
            include_once(plugin_dir_path(__FILE__) . 'churchtools-dosync.php');

            // Run the migration
            if (function_exists('ctwpsync_migrate_to_em71')) {
                ctwpsync_migrate_to_em71();
            }
        }

        // Run one-time migration for Events Manager 7.2+ compatibility
        // This sets event_archetype="event" for all existing events
        $migration72_completed = get_option('ctwpsync_em72_migration_completed_v2');
        if (!$migration72_completed) {
            // Include the sync file to make migration function available
            include_once(plugin_dir_path(__FILE__) . 'churchtools-dosync.php');

            // Run the migration
            if (function_exists('ctwpsync_migrate_to_em72')) {
                ctwpsync_migrate_to_em72();
            }
        }
    }
}
add_action( 'plugins_loaded', 'ctwpsync_initplugin' );

/**
 * Called when Events Manager wants to retrieve the URL of an event's image.
 *
 * When the attribute name is set, replace the image URL for the event with the embed URL saved in the custom attribute.
 *
 * @param string $em_image_url Original image URL
 * @param EM_Event $em_event Event in question
 * @return string The image URL (possibly overridden)
 */
function ctwpsync_override_event_image(string $em_image_url, $em_event): string {
    // The filter is called for all Event Manger objects (e.g. also locations); only apply to events
    if(!($em_event instanceof EM_Event)) {
        return $em_image_url;
    }

	// Retrieve attribute name from options
	$options = get_option('ctwpsync_options');
	$attr_name = is_array($options) ? ($options['em_image_attr'] ?? '') : '';

	// Embedding has to be enabled (attribute name set),
	// then local images take precedence, only override URL if it isn't set anyway
    if (!empty($attr_name) && empty($em_image_url) && is_array($em_event->event_attributes) && array_key_exists($attr_name, $em_event->event_attributes)) {
		$em_image_url = $em_event->event_attributes[$attr_name];
		$em_event->image_url = $em_image_url;
	}

	return $em_image_url;
}

add_filter( 'em_object_get_image_url', 'ctwpsync_override_event_image', 10, 2 );

/**
 * Validate that a user-supplied URL is a well-formed http(s) URL.
 *
 * Used to harden the admin AJAX endpoints that make server-side requests to the
 * provided ChurchTools URL. These endpoints are already restricted to users with
 * manage_options, so this is defense in depth against SSRF via unexpected schemes
 * (file://, gopher://, etc.) or malformed input.
 *
 * @param string $url
 * @return bool
 */
function ctwpsync_is_valid_remote_url(string $url): bool {
	if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
		return false;
	}
	$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
	return in_array($scheme, ['http', 'https'], true);
}

/**
 * Register AJAX action for connection validation
 */
add_action('wp_ajax_ctwpsync_validate_connection', 'ctwpsync_validate_connection_callback');

/**
 * AJAX callback to validate ChurchTools connection
 */
function ctwpsync_validate_connection_callback(): void {
	// Verify nonce for security
	if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ctwpsync_validate')) {
		error_log('[ChurchTools Sync] Connection test failed: Security check failed (invalid nonce)');
		wp_send_json_error('Security check failed');
	}

	// Check user permissions
	if (!current_user_can('manage_options')) {
		error_log('[ChurchTools Sync] Connection test failed: Permission denied for user ' . get_current_user_id());
		wp_send_json_error('Permission denied');
	}

	$url = isset($_POST['url']) ? rtrim(trim($_POST['url']), '/') . '/' : '';
	$token = isset($_POST['token']) ? trim($_POST['token']) : '';
	$useSavedToken = isset($_POST['use_saved_token']) && $_POST['use_saved_token'] === '1';

	// If no token provided but use_saved_token flag is set, get from saved options
	if (empty($token) && $useSavedToken) {
		$saved_data = get_option('ctwpsync_options');
		if ($saved_data && !empty($saved_data['apitoken'])) {
			$token = $saved_data['apitoken'];
		}
	}

	if (empty($url) || empty($token)) {
		error_log('[ChurchTools Sync] Connection test failed: URL or API token missing');
		wp_send_json_error('URL and API token are required');
	}

	if (!ctwpsync_is_valid_remote_url($url)) {
		error_log('[ChurchTools Sync] Connection test failed: Invalid URL format');
		wp_send_json_error('Invalid URL format (must be a http(s) URL)');
	}

	error_log('[ChurchTools Sync] Testing connection to: ' . $url);

	// Load autoloader if needed
	if (is_readable(__DIR__ . '/vendor/autoload.php')) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	try {
		// Configure ChurchTools API
		\CTApi\CTConfig::setApiURL($url);
		\CTApi\CTConfig::setApiKey($token);
		\CTApi\CTConfig::validateConfig();

		// Try to get current user to verify connection
		$whoami = \CTApi\Models\Groups\Person\PersonRequest::whoami();

		if ($whoami) {
			$name = trim($whoami->getFirstName() . ' ' . $whoami->getLastName());
			error_log('[ChurchTools Sync] Connection test successful: Connected as ' . $name);
			wp_send_json_success('Connected as: ' . $name);
		} else {
			error_log('[ChurchTools Sync] Connection test warning: Connected but could not retrieve user info');
			wp_send_json_error('Connection successful but could not retrieve user info');
		}
	} catch (\Exception $e) {
		$errorMessage = $e->getMessage();
		$errorClass = get_class($e);
		error_log("[ChurchTools Sync] Connection test failed: [{$errorClass}] {$errorMessage}");
		error_log("[ChurchTools Sync] Stack trace: " . $e->getTraceAsString());
		wp_send_json_error('Connection failed: ' . $errorMessage);
	}
}

/**
 * Register AJAX action for fetching calendars
 */
add_action('wp_ajax_ctwpsync_get_calendars', 'ctwpsync_get_calendars_callback');

/**
 * AJAX callback to fetch available calendars from ChurchTools
 */
function ctwpsync_get_calendars_callback(): void {
	// Verify nonce for security
	if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ctwpsync_validate')) {
		error_log('[ChurchTools Sync] Calendar fetch failed: Security check failed (invalid nonce)');
		wp_send_json_error('Security check failed');
	}

	// Check user permissions
	if (!current_user_can('manage_options')) {
		error_log('[ChurchTools Sync] Calendar fetch failed: Permission denied for user ' . get_current_user_id());
		wp_send_json_error('Permission denied');
	}

	$url = isset($_POST['url']) ? rtrim(trim($_POST['url']), '/') . '/' : '';
	$token = isset($_POST['token']) ? trim($_POST['token']) : '';
	$useSavedToken = isset($_POST['use_saved_token']) && $_POST['use_saved_token'] === '1';

	// If no token provided but use_saved_token flag is set, get from saved options
	if (empty($token) && $useSavedToken) {
		$saved_data = get_option('ctwpsync_options');
		if ($saved_data && !empty($saved_data['apitoken'])) {
			$token = $saved_data['apitoken'];
		}
	}

	if (empty($url) || empty($token)) {
		error_log('[ChurchTools Sync] Calendar fetch failed: URL or API token missing');
		wp_send_json_error('URL and API token are required');
	}

	if (!ctwpsync_is_valid_remote_url($url)) {
		error_log('[ChurchTools Sync] Calendar fetch failed: Invalid URL format');
		wp_send_json_error('Invalid URL format (must be a http(s) URL)');
	}

	error_log('[ChurchTools Sync] Fetching calendars from: ' . $url);

	// Load autoloader if needed
	if (is_readable(__DIR__ . '/vendor/autoload.php')) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	try {
		// Configure ChurchTools API
		\CTApi\CTConfig::setApiURL($url);
		\CTApi\CTConfig::setApiKey($token);

		// Fetch all calendars
		$calendars = \CTApi\Models\Calendars\Calendar\CalendarRequest::all();

		$result = [];
		foreach ($calendars as $cal) {
			$result[] = [
				'id' => $cal->getId(),
				'name' => $cal->getName(),
			];
		}

		error_log('[ChurchTools Sync] Successfully fetched ' . count($result) . ' calendars');
		wp_send_json_success($result);
	} catch (\Exception $e) {
		$errorMessage = $e->getMessage();
		$errorClass = get_class($e);
		error_log("[ChurchTools Sync] Calendar fetch failed: [{$errorClass}] {$errorMessage}");
		wp_send_json_error('Failed to fetch calendars: ' . $errorMessage);
	}
}

/**
 * Register AJAX action for fetching resource types
 */
add_action('wp_ajax_ctwpsync_get_resource_types', 'ctwpsync_get_resource_types_callback');

/**
 * AJAX callback to fetch available resource types from ChurchTools
 */
function ctwpsync_get_resource_types_callback(): void {
	// Verify nonce for security
	if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ctwpsync_validate')) {
		error_log('[ChurchTools Sync] Resource types fetch failed: Security check failed (invalid nonce)');
		wp_send_json_error('Security check failed');
	}

	// Check user permissions
	if (!current_user_can('manage_options')) {
		error_log('[ChurchTools Sync] Resource types fetch failed: Permission denied for user ' . get_current_user_id());
		wp_send_json_error('Permission denied');
	}

	$url = isset($_POST['url']) ? rtrim(trim($_POST['url']), '/') . '/' : '';
	$token = isset($_POST['token']) ? trim($_POST['token']) : '';
	$useSavedToken = isset($_POST['use_saved_token']) && $_POST['use_saved_token'] === '1';

	// If no token provided but use_saved_token flag is set, get from saved options
	if (empty($token) && $useSavedToken) {
		$saved_data = get_option('ctwpsync_options');
		if ($saved_data && !empty($saved_data['apitoken'])) {
			$token = $saved_data['apitoken'];
		}
	}

	if (empty($url) || empty($token)) {
		error_log('[ChurchTools Sync] Resource types fetch failed: URL or API token missing');
		wp_send_json_error('URL and API token are required');
	}

	if (!ctwpsync_is_valid_remote_url($url)) {
		error_log('[ChurchTools Sync] Resource types fetch failed: Invalid URL format');
		wp_send_json_error('Invalid URL format (must be a http(s) URL)');
	}

	error_log('[ChurchTools Sync] Fetching resource types from: ' . $url);

	// Load autoloader if needed
	if (is_readable(__DIR__ . '/vendor/autoload.php')) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	try {
		// Configure ChurchTools API
		\CTApi\CTConfig::setApiURL($url);
		\CTApi\CTConfig::setApiKey($token);

		// Fetch all resource types
		$resourceTypes = \CTApi\Models\Calendars\Resource\ResourceTypeRequest::all();

		$result = [];
		foreach ($resourceTypes as $rt) {
			$result[] = [
				'id' => $rt->getId(),
				'name' => $rt->getNameTranslated() ?? $rt->getName(),
			];
		}

		error_log('[ChurchTools Sync] Successfully fetched ' . count($result) . ' resource types');
		wp_send_json_success($result);
	} catch (\Exception $e) {
		$errorMessage = $e->getMessage();
		$errorClass = get_class($e);
		error_log("[ChurchTools Sync] Resource types fetch failed: [{$errorClass}] {$errorMessage}");
		wp_send_json_error('Failed to fetch resource types: ' . $errorMessage);
	}
}

/**
 * Register AJAX action for triggering sync
 */
add_action('wp_ajax_ctwpsync_trigger_sync', 'ctwpsync_trigger_sync_callback');

/**
 * AJAX callback to trigger a sync in the background via WordPress cron
 */
function ctwpsync_trigger_sync_callback(): void {
	// Verify nonce for security
	if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ctwpsync_validate')) {
		error_log('[ChurchTools Sync] Sync trigger failed: Security check failed (invalid nonce)');
		wp_send_json_error('Security check failed');
	}

	// Check user permissions
	if (!current_user_can('manage_options')) {
		error_log('[ChurchTools Sync] Sync trigger failed: Permission denied for user ' . get_current_user_id());
		wp_send_json_error('Permission denied');
	}

	// Capture the current (logged-in admin) user. The cron event runs in an
	// unauthenticated loopback request with no current user, and the sync in
	// churchtools-dosync.php bails out ("No user specified" / "User not logged
	// in") unless a user is set. Pass the ID so the event can restore it, the
	// same way the hourly event does.
	$user_id = get_current_user_id();

	// Check if a sync is already scheduled within the next minute
	$next_scheduled = wp_next_scheduled('ctwpsync_single_sync_event', [$user_id]);
	if ($next_scheduled && $next_scheduled > time() && $next_scheduled < time() + 60) {
		wp_send_json_success('Sync already scheduled');
		return;
	}

	// Schedule a one-time sync event to run immediately
	$scheduled = wp_schedule_single_event(time(), 'ctwpsync_single_sync_event', [$user_id]);

	if ($scheduled === false) {
		error_log('[ChurchTools Sync] Failed to schedule sync event');
		wp_send_json_error('Failed to schedule sync');
		return;
	}

	error_log('[ChurchTools Sync] Sync event scheduled, spawning cron');

	// Spawn cron to run the scheduled event
	spawn_cron();

	wp_send_json_success('Sync started in background');
}

/**
 * Handle the single sync event triggered by "Sync Now" button
 */
add_action('ctwpsync_single_sync_event', 'ctwpsync_run_single_sync', 10, 1);
function ctwpsync_run_single_sync($current_user = 0): void {
	error_log('[ChurchTools Sync] Running single sync event');
	// Restore the user context captured when the sync was scheduled; the sync
	// needs a logged-in user to own the created events (see do_this_ctwpsync_hourly).
	$user_id = $current_user instanceof WP_User ? (int) $current_user->ID : (int) $current_user;
	if ($user_id > 0) {
		wp_set_current_user($user_id);
	}
	do_action('ctwpsync_includeChurchcalSync');
}

/**
 * Register AJAX action for checking sync status
 */
add_action('wp_ajax_ctwpsync_get_sync_status', 'ctwpsync_get_sync_status_callback');

/**
 * AJAX callback to get current sync status
 */
function ctwpsync_get_sync_status_callback(): void {
	// Verify nonce for security
	if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ctwpsync_validate')) {
		wp_send_json_error('Security check failed');
	}

	// Check user permissions
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Permission denied');
	}

	$sync_in_progress = get_transient('churchtools_wpcalendarsync_in_progress');
	$last_updated = get_transient('churchtools_wpcalendarsync_lastupdated');
	$last_duration = get_transient('churchtools_wpcalendarsync_lastsyncduration');
	$next_scheduled = ctwpsync_get_next_scheduled('ctwpsync_hourly_event');

	wp_send_json_success([
		'in_progress' => $sync_in_progress ? true : false,
		'started_at' => $sync_in_progress ?: null,
		'last_updated' => $last_updated ?: 'Never',
		'last_duration' => $last_duration ?: 'N/A',
		'next_scheduled' => $next_scheduled ? wp_date('Y-m-d H:i:s', $next_scheduled) : null,
		'next_scheduled_minutes' => $next_scheduled ? max(0, floor(($next_scheduled - time()) / 60)) : null,
	]);
}

/**
 * Migrate old settings format to new format
 * Old format: ids (array of ints), ids_categories (array of strings)
 * New format: calendars (array of {id, name, category})
 */
function ctwpsync_migrate_settings(): void {
	$migration_completed = get_option('ctwpsync_settings_migration_completed');
	if ($migration_completed) {
		return;
	}

	$options = get_option('ctwpsync_options');
	if (!$options || !is_array($options)) {
		update_option('ctwpsync_settings_migration_completed', true);
		return;
	}

	// Check if already in new format
	if (isset($options['calendars'])) {
		update_option('ctwpsync_settings_migration_completed', true);
		return;
	}

	// Check if old format exists
	if (!isset($options['ids']) || !is_array($options['ids'])) {
		update_option('ctwpsync_settings_migration_completed', true);
		return;
	}

	// Migrate from old format to new format
	$calendars = [];
	$ids = $options['ids'];
	$categories = $options['ids_categories'] ?? [];

	for ($i = 0; $i < count($ids); $i++) {
		$calendars[] = [
			'id' => (int)$ids[$i],
			'name' => '', // Name will be populated when user loads calendars
			'category' => isset($categories[$i]) ? trim($categories[$i]) : '',
		];
	}

	// Update to new format
	$newOptions = [
		'url' => $options['url'] ?? '',
		'apitoken' => $options['apitoken'] ?? '',
		'calendars' => $calendars,
		'import_past' => $options['import_past'] ?? 0,
		'import_future' => $options['import_future'] ?? 380,
		'resourcetype_for_categories' => $options['resourcetype_for_categories'] ?? -1,
		'em_image_attr' => $options['em_image_attr'] ?? '',
		'enable_tag_categories' => $options['enable_tag_categories'] ?? false,
	];

	update_option('ctwpsync_options', $newOptions);
	update_option('ctwpsync_settings_migration_completed', true);
}

// Run migration on plugin load
add_action('plugins_loaded', 'ctwpsync_migrate_settings', 5); // Priority 5 to run before initplugin

//function ctwpsync_cron_schedules($schedules){
//    if(!isset($schedules["5min"])){
//        $schedules["5min"] = array(
//            'interval' => 5*60,
//            'display' => __('Once every 5 minutes'));
//    }
//    if(!isset($schedules["30min"])){
//        $schedules["30min"] = array(
//            'interval' => 30*60,
//            'display' => __('Once every 30 minutes'));
//    }
//    return $schedules;
//}
//add_filter('cron_schedules','ctwpsync_cron_schedules');
