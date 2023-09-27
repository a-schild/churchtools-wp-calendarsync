<?php
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
    require __DIR__ . '/vendor/autoload.php';
}

use CTApi\CTConfig;
use CTApi\CTLog;
CTLog::enableFileLog(); // enable logfile


global $wpctsyncDoInfoLog;
$wpctsyncDoInfoLog= true;
global $wpctsyncDoDebugLog;
$wpctsyncDoDebugLog= true;
$hasError= false;
$errorMessage= null;
$options =  get_option('ctwpsync_options');
if(empty($options) || empty($options['url'])){
    return;
}
try
{
    global $wpdb;
    $wpdb_prefix = $wpdb->prefix;
    $wpdb_tablename = $wpdb_prefix.'ctwpsync_mapping';

    $serverURL= $options['url'];
    $apiToken= $options['apitoken'];
    CTApi\CTConfig::setApiURL($serverURL);
    CTApi\CTConfig::setApiKey($apiToken);
    CTApi\CTConfig::validateConfig();
    $calendars= $options['ids'];
    $pastDays= $options['import_past'];
    $futureDays=  $options['import_future'];
    $fromDate= Date('y-m-d', strtotime('-'.$pastDays.' days'));
    $toDate= Date('y-m-d', strtotime('+'.$futureDays.' days'));
    $api= new CTApi\CTClient();
    $result= CTApi\Requests\AppointmentRequest::forCalendars($calendars)
        ->where('from', $fromDate)
        ->where('to', $toDate)
        ->get();
    foreach ($result as $key => $value) {
        if (!$value->getIsInternal()) {
            logDebug("Caption: ".$value->getCaption());
            logDebug("StartDate: ".$value->getStartDate());
            logDebug("EndDate: ".$value->getEndDate());
            logDebug("Is allday: ".$value->getAllDay());
            //logDebug("Object: ".serialize($value));
            $result = $wpdb->get_results(sprintf('SELECT * FROM `%2$s` WHERE `ct_id` = %d ', $value->getId(), $wpdb_tablename));
            $addMode= false;
            if (sizeof($result) == 1) {
                // We did already map it
                logInfo("Found mapping for ct id: ".$value->getId());
                $event= em_get_event($result[0]->wp_id);
                if ($event->ID != null ){
                    // OK, still existing, make sure it's not in trash
                    // logDebug(serialize($event));
                    if ($event->status == -1) {
                        // Is deleted
                        logInfo("Event wp trash, removing from mapping ct id: ". $value->getId());
                        $wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb_tablename." WHERE ct_id=".$value->getId()));
                        $event= new EM_Event(false);
                        $addMode= true;
                    } else {
                        logDebug("Event status ". $event->event_status);
                        $addMode= false;
                    }
                } else {
                    // No longer found, deleted?
                    logInfo("Event no longer found in wp, removing from mapping, ct id: ".$value->getId());
                    $wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb_tablename." WHERE ct_id=".$value->getId()));
                    $addMode= true;
                }
            } else {
                logDebug("No mapping for event found");
                $event= new EM_Event(false);
                $addMode= true;
            }
            logDebug("Query result: ".serialize($result));
            logDebug("Query result size: ".sizeof($result));

            // $event->event_timezone= "UTC+0"; // Only marks it as UTC, not usefull
            $event->event_timezone= wp_timezone_string(); // Fix it to the correct location
            $event->event_name= $value->getCaption();
            $event->post_content= $value->getInformation();
            $event->set_status(0); // Publish entry would be 1
            if ($value->getAllDay() === "true") {
                $sDate= $value->getStartDate();
                $eDate= $value->getEndDate();
                logDebug("StartDate: ".$sDate);
                $event->event_start_date= $sDate;
                $event->event_end_date= $eDate;
                $event->event_all_day= true;
            } else {
                $sDate= \DateTime::createFromFormat('Y-m-d\TH:i:s+', $value->getStartDate(), new DateTimeZone('UTC'));
                // Set to WP location time zone
                $sDate->setTimezone(new DateTimeZone(wp_timezone_string()));
                $eDate= \DateTime::createFromFormat('Y-m-d\TH:i:s+', $value->getEndDate(), new DateTimeZone('UTC'));
                // Set to WP location time zone
                $eDate->setTimezone(new DateTimeZone(wp_timezone_string()));
                logDebug("StartDate: ".$sDate->format('Y-m-d'));
                $event->event_start_date= $sDate->format('Y-m-d');
                $event->event_end_date= $eDate->format('Y-m-d');
                logDebug("StartTime: ".$sDate->format('H:i:s'));
                $event->event_start_time= $sDate->format('H:i:s');
                $event->event_end_time= $eDate->format('H:i:s');
                $event->event_all_day= false;
            }
//            fwrite($myFile, serialize($event));
//            fwrite($myFile, "\nStart: ".serialize($sDate)."\n");
//            fwrite($myFile, "\nEnd: ". serialize($eDate)."\n");
            $saveResult= $event->save();
            // logDebug("Save event result: ".serialize($saveResult));
            logInfo("Saved ct event id: ".$value->getId(). " WP event ID ".$event->event_id." post id: ".$event->ID);
            if ($addMode) {
                // Keeps track of ct event id and wp event id for subsequent updates+deletions
                $wpdb->insert($wpdb->prefix . 'ctwpsync_mapping', array(
                    'ct_id' => $value->getId(),
                    'wp_id' => $event->event_id,
                    'last_seen' => date('Y-m-d H:i:s'),
                    'event_start' => $value->getStartDate(),
                    'event_end' => $value->getEndDate()
                ));            
            } else {
                // Update last seen time stamp to flag as "still existing"
                $wpdb->query($wpdb->prepare("UPDATE ".$wpdb_tablename." SET last_seen='".date('Y-m-d H:i:s')."' WHERE ct_id=".$value->getId()));
            }
        }
    }
}
catch (Exception $e)
{
    $errorMessage= $e->getMessage();
    logError($errorMessage);
    $hasError= true;
    session_destroy();
}

function logDebug($message) {
    global $wpctsyncDoDebugLog;
    if ($wpctsyncDoDebugLog) {
       $logger= plugin_dir_path(__FILE__).'wpcalsync.log';
       // Usage of logging
       // $message = 'SOME ERROR'.PHP_EOL;
       // error_log($message, 3, $logger);
       error_log($message. "\n", 3, $logger);
    }
}

function logInfo($message) {
    global $wpctsyncDoInfoLog;
    if ($wpctsyncDoInfoLog) {
       $logger= plugin_dir_path(__FILE__).'wpcalsync.log';
       // Usage of logging
       // $message = 'SOME ERROR'.PHP_EOL;
       // error_log($message, 3, $logger);
       error_log($message. "\n", 3, $logger);
    }
}

function logError($message) {
    $logger= plugin_dir_path(__FILE__).'wpcalsync.log';
    // Usage of logging
    // $message = 'SOME ERROR'.PHP_EOL;
    // error_log($message, 3, $logger);
    error_log($message. "\n", 3, $logger);
}

