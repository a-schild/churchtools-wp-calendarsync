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
 * Version:           1.3.1
 * Author:            André Schild
 * Author URI:        https://github.com/a-schild/churchtools-wp-calendarsync/
 * License:           GPLv2 or later 
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       ctwpsync
 * Domain Path:       /languages
 * Tags:              churchtools, events manager, sync, calendar
 * Requires at least: 5.8
 * Requires PHP:      8.2
 * Tested up to:      6.3.1
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
define( 'CTWPSYNC_VERSION', '1.3.1' );

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

	// Clear ALL existing scheduled events for this hook first to prevent duplicates
	// This is necessary because wp_next_scheduled() doesn't check arguments,
	// so multiple events with different args can be scheduled
	wp_clear_scheduled_hook('ctwpsync_hourly_event');

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
	wp_set_current_user($current_user);
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
	wp_clear_scheduled_hook('ctwpsync_hourly_event');
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
            // Clear any existing events first
            wp_clear_scheduled_hook($hook_name);

            // Schedule the cron event now that filters are active
            $args = [is_user_logged_in(), wp_get_current_user()];
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

        // Migrate from hourly schedule to every_57_minutes schedule
        $needs_migration = false;
        foreach ($scheduled_events as $event) {
            if ($event['schedule'] === 'hourly') {
                $needs_migration = true;
                break;
            }
        }

        if ($needs_migration) {
            // Clear all and reschedule with new interval
            wp_clear_scheduled_hook($hook_name);
            $args = [is_user_logged_in(), wp_get_current_user()];
            wp_schedule_event(time(), 'every_57_minutes', $hook_name, $args);
        } else if (count($scheduled_events) > 1) {
            // Clean up duplicate cron events - keep only the earliest one
            usort($scheduled_events, function($a, $b) {
                return $a['timestamp'] - $b['timestamp'];
            });

            // Remove all except the first (earliest) one
            for ($i = 1; $i < count($scheduled_events); $i++) {
                wp_unschedule_event($scheduled_events[$i]['timestamp'], $hook_name, $scheduled_events[$i]['args']);
            }
        } else if (empty($scheduled_events)) {
            // No cron event exists - schedule one
            $args = [is_user_logged_in(), wp_get_current_user()];
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
	// Retrieve attribute name from options
	$options = get_option('ctwpsync_options');
	$attr_name = is_array($options) ? ($options['em_image_attr'] ?? '') : '';

	// Embedding has to be enabled (attribute name set),
	// then local images take precedence, only override URL if it isn't set anyway
	if (!empty($attr_name) && empty($em_image_url) && array_key_exists($attr_name, $em_event->event_attributes)) {
		$em_image_url = $em_event->event_attributes[$attr_name];
		$em_event->image_url = $em_image_url;
	}

	return $em_image_url;
}

add_filter( 'em_object_get_image_url', 'ctwpsync_override_event_image', 10, 2 );

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

	// Check if a sync is already scheduled within the next minute
	$next_scheduled = wp_next_scheduled('ctwpsync_single_sync_event');
	if ($next_scheduled && $next_scheduled > time() && $next_scheduled < time() + 60) {
		wp_send_json_success('Sync already scheduled');
		return;
	}

	// Schedule a one-time sync event to run immediately
	$scheduled = wp_schedule_single_event(time(), 'ctwpsync_single_sync_event');

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
add_action('ctwpsync_single_sync_event', 'ctwpsync_run_single_sync');
function ctwpsync_run_single_sync(): void {
	error_log('[ChurchTools Sync] Running single sync event');
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
		'next_scheduled' => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : null,
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
