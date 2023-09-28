<?php
/**
* Plugin Name: Churchtools WP Calendarsync
* Plugin URI: https://www.ref-nidau.ch
* Description: Chutchtools wordpress calendar sync to events manager.
* Version: 0.1
* Author: a-schild
* Author URI: https://www.ref-nidau.ch
**/


add_action ('admin_menu', 'ctwpsync_setup_menu'  );
add_action('save_ctwpsync_settings', 'save_ctwpsync_settings' );
function ctwpsync_setup_menu() {
	add_options_page('ChurchTools Cal Importer','ChurchTools calendar sync','manage_options','churchtools-wpcalendarsync','ctwpsync_dashboard');
	add_action('admin_init', 'register_ctwpsync_settings' );
}
function register_ctwpsync_settings(){
	register_setting( 'ctwpsync-group', 'ctwpsync_url');
	register_setting( 'ctwpsync-group', 'ctwpsync_apitoken');
	register_setting( 'ctwpsync-group', 'ctwpsync_ids');
	register_setting( 'ctwpsync-group', 'ctwpsync_import_past');
	register_setting( 'ctwpsync-group', 'ctwpsync_import_future');
	register_setting( 'ctwpsync-group', 'ctwpsync_resourcetype_for_categories');
    $myPage= isset($_GET['page']) ? $_GET['page'] : "";
	if ( $myPage === str_replace('.php','',basename(__FILE__)) ) {
		if(!empty($_POST['ctwpsync_url'])){
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
	include_once (plugin_dir_path( __FILE__ ) .  'dashboard/dashboard_view.php');
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
	sort($data['ids']);
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
		wp_schedule_event( current_time( 'timestamp' ), 'hourly', 'ctwpsync_hourly_event' );
	}
}
/**
 * Hook the function to run every hour
 */
add_action('ctwpsync_hourly_event', 'do_this_ctwpsync_hourly');
function do_this_ctwpsync_hourly() {
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
	wp_clear_scheduled_hook( 'ctwpsync_daily_event' );
	wp_clear_scheduled_hook( 'ctwpsync_hourly_event' );
}

/* table holding ct event id and wp event id mapping */
function ctwpsync_initplugin()
{
    global $wpdb;
    $table_name = $wpdb->prefix.'ctwpsync_mapping';
    $sql = "CREATE TABLE IF NOT EXISTS ".$table_name."(
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ct_id mediumint(9) NOT NULL,
            wp_id mediumint(9) NOT NULL,
            last_seen datetime NOT NULL,
            event_start datetime NOT NULL,
            event_end datetime NOT NULL,
            UNIQUE KEY id (id),
            UNIQUE KEY ct_id (ct_id)
            );";
    require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
add_action( 'plugins_loaded', 'ctwpsync_initplugin' );
