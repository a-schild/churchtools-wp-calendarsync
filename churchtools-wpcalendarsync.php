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
 * Version:           1.0.16
 * Author:            André Schild
 * Author URI:        https://github.com/a-schild/churchtools-wp-calendarsync/
 * License:           GPLv2 or later 
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       ctwpsync
 * Domain Path:       /languages
 * Tags:              churchtools, events manager, sync, calendar
 * Requires at least: 5.8
 * Tested up to:      6.3.1
 * Stable tag:        main
 * 
 */


add_action ('admin_menu', 'ctwpsync_setup_menu'  );
add_action('save_ctwpsync_settings', 'save_ctwpsync_settings' );

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'CTWPSYNC_VERSION', '1.0.16' );

function ctwpsync_setup_menu() {
	add_options_page('ChurchTools Calendar Importer','ChurchTools Calsync','manage_options','churchtools-wpcalendarsync','ctwpsync_dashboard');
	add_action('admin_init', 'register_ctwpsync_settings' );
}
function register_ctwpsync_settings(){
	register_setting( 'ctwpsync-group', 'ctwpsync_url');    // URL to the churchtools installation
	register_setting( 'ctwpsync-group', 'ctwpsync_apitoken');   // API auth token
	register_setting( 'ctwpsync-group', 'ctwpsync_ids');        // Calendar ID's to sync from
	register_setting( 'ctwpsync-group', 'ctwpsync_ids_categories'); // Category for the above calendar id
	register_setting( 'ctwpsync-group', 'ctwpsync_import_past');    // Days in the past to sync
	register_setting( 'ctwpsync-group', 'ctwpsync_import_future');  // Days in the future to sync
	register_setting( 'ctwpsync-group', 'ctwpsync_resourcetype_for_categories');    // Sync categories from resources
    $myPage= isset($_GET['page']) ? $_GET['page'] : "";
	if ( $myPage === str_replace('.php','',basename(__FILE__)) ) {
		if(!empty($_POST['ctwpsync_url']) && !empty($_POST['ctwpsync_apitoken'])){
			save_ctwpsync_settings();
		}
	}
}
function ctwpsync_dashboard() {
	$saved_data =  get_option('ctwpsync_options');
    if (gettype($saved_data) == "string") {
        // Under some circumstances this could be returned as a string...
        $saved_data = $saved_data ? unserialize($saved_data) : null ;
    }
	$lastupdated = get_transient('churchtools_wpcalendarsync_lastupdated');
	$lastsyncduration = get_transient('churchtools_wpcalendarsync_lastsyncduration');
	// $saved_data = $saved_data ? unserialize($saved_data) : null ;
    if (is_plugin_active('events-manager/events-manager.php')) {
        include_once (plugin_dir_path( __FILE__ ) .  'dashboard/dashboard_view.php');
    } else {
        echo "<div>";
        echo "<h2>ChurchTools Calendar Sync requires an active 'Events Manager' plugin</h2>";
        echo "<p>Please install and activate it first</p>";
        echo "<p><a href='https://de.wordpress.org/plugins/events-manager/'>https://de.wordpress.org/plugins/events-manager/</a></p>";
        echo "</div>";

    }
}

// this function will handle the ajax call
function save_ctwpsync_settings() {
	$data = [];
	$saved_data =  get_option('ctwpsync_options');
	$data['url'] = rtrim(trim($_POST['ctwpsync_url']),'/').'/';
	$data['apitoken'] = trim($_POST['ctwpsync_apitoken']);
	$ids=trim($_POST['ctwpsync_ids']);
	$data['ids']=[];
	foreach(preg_split('/\D/',$ids) as $id){
		if(intval($id)>0){
			$data['ids'][] = intval($id);
		}
	}
    // Don't sort ID's, otherwise the category assignment gets wrong
	$ids_categories=trim($_POST['ctwpsync_ids_categories']);
	$data['ids_categories']=[];
	foreach(preg_split('/,/',$ids_categories) as $id_cat){
        $data['ids_categories'][] = trim($id_cat);
	}
    // Don't sort ID's, otherwise the category assignment gets wrong
	$data['import_past'] = trim($_POST['ctwpsync_import_past']);
	$data['import_future'] = trim($_POST['ctwpsync_import_future']);
    $data['resourcetype_for_categories'] = trim($_POST['ctwpsync_resourcetype_for_categories']);
	if($saved_data) {
		update_option( 'ctwpsync_options',  $data );
	}else{
		add_option( 'ctwpsync_options',  serialize($data) );
	}
	do_action('ctwpsync_includeChurchcalSync');
	//ctwpsync_getUpdatedCalendarEvents();
}

 /**
 * Schedule the event when the plugin is activated, if not already scheduled
 * and run it immediately for the first time
 */
register_activation_hook( __FILE__, 'ctwpsync_activation' );
function ctwpsync_activation() {
	if ( ! wp_next_scheduled ( 'ctwpsync_hourly_event' ) ) {
        // Store the logged in user, so the cron job works as the same user
        $args = [ is_user_logged_in(), wp_get_current_user() ];
		wp_schedule_event( current_time( 'timestamp' ), 'hourly', 'ctwpsync_hourly_event', $args);
	}
}
/**
 * Hook the function to run every hour
 * 
 * We need to pass in the user
 */
add_action('ctwpsync_hourly_event', 'do_this_ctwpsync_hourly', 10, 2);
function do_this_ctwpsync_hourly($is_user_logged_in, $current_user) {
    wp_set_current_user($current_user);
    do_action('ctwpsync_includeChurchcalSync');
	if( function_exists('ctwpsync_getUpdatedCalendarEvents')) {
		$result = ctwpsync_getUpdatedCalendarEvents();
    }
}
add_action('ctwpsync_includeChurchcalSync', 'ctwpsync_includeChurchcalSync');
function ctwpsync_includeChurchcalSync(){
	include( plugin_dir_path( __FILE__ ) . 'churchtools-dosync.php');
	//include( plugin_dir_path( __FILE__ ) . 'helper.php');
}


/**
 * Clear the scheduled event when the plugin is disabled
 */
register_deactivation_hook( __FILE__, 'ctwpsync_deactivation' );
function ctwpsync_deactivation() {
	wp_clear_scheduled_hook( 'ctwpsync_hourly_event' );
}

/* table holding ct event id and wp event id mapping */
function ctwpsync_initplugin()
{
    global $wpdb;
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
}
add_action( 'plugins_loaded', 'ctwpsync_initplugin' );

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
