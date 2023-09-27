<?php
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
    require __DIR__ . '/vendor/autoload.php';
}

use CTApi\CTConfig;
use CTApi\CTLog;
use CTApi\Models\Calendars\Appointment\AppointmentRequest;
use CTApi\Models\Calendars\CombinedAppointment\CombinedAppointmentRequest;

CTLog::enableFileLog(); // enable logfile


global $wpctsyncDoInfoLog;
$wpctsyncDoInfoLog= true;
global $wpctsyncDoDebugLog;
$wpctsyncDoDebugLog= true;

global $wpdb;
$wpdb_prefix = $wpdb->prefix;
global $wpctsync_tablename;
$wpctsync_tablename = $wpdb_prefix.'ctwpsync_mapping';


$hasError= false;
$errorMessage= null;
$options =  get_option('ctwpsync_options');
if(empty($options) || empty($options['url'])){
    return;
}
try
{
    $serverURL= $options['url'];
    $apiToken= $options['apitoken'];
    CTApi\CTConfig::setApiURL($serverURL);
    CTApi\CTConfig::setApiKey($apiToken);
    CTApi\CTConfig::validateConfig();
    $calendars= $options['ids'];
    $pastDays= $options['import_past'];
    $futureDays=  $options['import_future'];
    $resourcetype_for_categories= $options['resourcetype_for_categories'];
    if ($pastDays < 0) {
        $fromDate= Date('y-m-d', strtotime('+'.($pastDays*-1).' days'));
    } else {
        $fromDate= Date('y-m-d', strtotime('-'.$pastDays.' days'));
    }
    if ($futureDays < 0) {
        $toDate= Date('y-m-d', strtotime('-'.($futureDays*-1).' days'));
    } else {
        $toDate= Date('y-m-d', strtotime('+'.$futureDays.' days'));
    }
    $api= new CTApi\CTClient();
    $result= AppointmentRequest::forCalendars($calendars)
        ->where('from', $fromDate)
        ->where('to', $toDate)
        ->get();
    foreach ($result as $key => $ctCalEntry) {
        processCalendarEntry($ctCalEntry, $resourcetype_for_categories);
    }
    // Now we will have to handle all wp events which are no longer visible
    // from CT (Either deleted or moved in another calendar)
    // But don't remove old entries
}
catch (Exception $e)
{
    $errorMessage= $e->getMessage();
    logError($errorMessage);
    $hasError= true;
    session_destroy();
}

/**
 * Process a single calendar entry from ct
 * 
 * @param type $ctCalEntry a CT calendar entry to be analyzed and processed
 * 
 */
function processCalendarEntry(CTApi\Models\Calendars\Appointment\Appointment $ctCalEntry, int $resourcetype_for_categories) {
    if (!$ctCalEntry->getIsInternal()) {
        logDebug("Caption: ".$ctCalEntry->getCaption());
        logDebug("StartDate: ".$ctCalEntry->getStartDate());
        logDebug("EndDate: ".$ctCalEntry->getEndDate());
        logDebug("Is allday: ".$ctCalEntry->getAllDay());
        //logDebug("Object: ".serialize($ctCalEntry));
        global $wpctsync_tablename;
        global $wpdb;
        $result = $wpdb->get_results(sprintf('SELECT * FROM `%2$s` WHERE `ct_id` = %d ', $ctCalEntry->getId(), $wpctsync_tablename));
        $addMode= false;
        if (sizeof($result) == 1) {
            // We did already map it
            logInfo("Found mapping for ct id: ".$ctCalEntry->getId());
            $event= em_get_event($result[0]->wp_id);
            if ($event->ID != null ){
                // OK, still existing, make sure it's not in trash
                // logDebug(serialize($event));
                if ($event->status == -1) {
                    // Is deleted
                    logInfo("Event is in wp trash, removing from mapping ct id: ". $ctCalEntry->getId());
                    $wpdb->query($wpdb->prepare("DELETE FROM ".$wpctsync_tablename." WHERE ct_id=".$ctCalEntry->getId()));
                    $event= new EM_Event(false);
                    $addMode= true;
                } else {
                    logDebug("Event status ". $event->event_status);
                    $addMode= false;
                }
            } else {
                // No longer found, deleted?
                logInfo("Event no longer found in wp, removing from mapping, ct id: ".$ctCalEntry->getId());
                $wpdb->query($wpdb->prepare("DELETE FROM ".$wpctsync_tablename." WHERE ct_id=".$ctCalEntry->getId()));
                $addMode= true;
            }
        } else {
            logDebug("No mapping for event found");
            $event= new EM_Event(false);
            $addMode= true;
        }
        //logDebug("Query result: ".serialize($result));
        //logDebug("Query result size: ".sizeof($result));
        logDebug(serialize($ctCalEntry));
        if ($ctCalEntry->getAddress() != null) {
            $locationID= getCreateLocation($ctCalEntry->getAddress());
            if (isset($locationID)) {
                $event->location_id= $locationID;
            }
        }
        $event->event_timezone= wp_timezone_string(); // Fix it to the default WP default timezone
        $event->event_name= $ctCalEntry->getCaption();
        $event->post_content= $ctCalEntry->getInformation();
        // $event->status= 0; // Publish entry would be 1 (Does not work at the moment...???)
        if ($ctCalEntry->getAllDay() === "true") {
            $sDate= $ctCalEntry->getStartDate();
            $eDate= $ctCalEntry->getEndDate();
            logDebug("StartDate: ".$sDate);
            $event->event_start_date= $sDate;
            $event->event_end_date= $eDate;
            $event->event_all_day= true;
        } else {
            $sDate= \DateTime::createFromFormat('Y-m-d\TH:i:s+', $ctCalEntry->getStartDate(), new DateTimeZone('UTC'));
            // Set to WP location time zone
            $sDate->setTimezone(new DateTimeZone(wp_timezone_string()));
            $eDate= \DateTime::createFromFormat('Y-m-d\TH:i:s+', $ctCalEntry->getEndDate(), new DateTimeZone('UTC'));
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
        if ($ctCalEntry->getImage() != null) {
            // Handle image from ct calendar entry
            logDebug("Entry has an image ". $ctCalEntry->getImage()->getFileUrl());
            logError("Image handling not yet implemented, sorry");
        }
        if ($resourcetype_for_categories > 0) {
            logDebug("Using resources of type ".$resourcetype_for_categories." for wordpress categories");
            // So we retrieve the resources booked with this calendar entry
            $sDate= \DateTime::createFromFormat('Y-m-d\TH:i:s+', $ctCalEntry->getStartDate(), new DateTimeZone('UTC'));
            $combinedAppointment= CombinedAppointmentRequest::forAppointment($ctCalEntry->getCalendar()->getId(), $ctCalEntry->getId(), $sDate->format('Y-m-d'))->get();
            // logDebug("Got combined appointment ".serialize($combinedAppointment));
            $desiredCategories= [];
            if ($combinedAppointment != null ) {
                // Now process the resource bookings (if any)
                $allBookings= $combinedAppointment->getBookings();
                foreach ($allBookings as $key => $booking) {
                    $thisResource= $booking->getResource();
                    if ($thisResource->getResourceTypeId() == $resourcetype_for_categories) {
                        // Include this as a category
                        logDebug("Found resource with id ".$thisResource->getId(). " name: ".$thisResource->getName());
                        array_push($desiredCategories, $thisResource->getName());
                    }
                }
            }
            if (sizeof($desiredCategories) > 0) {
                $wpDesiredCategories= [];
                foreach ($desiredCategories as $dcKey => $desiredCategory) {
                    $taxFilter = array( 'taxonomy' => 'event-categories', 'name' => $desiredCategory, 'hide_empty' => false);
                    $wpCategories= get_terms($taxFilter);
                    logDebug("Results: ".sizeof($wpCategories));
                    if (sizeof($wpCategories) >= 1) {
                        logDebug("Found matching wp category: ".$desiredCategory . " wp: ".$wpCategories[0]->term_id);
                        array_push($wpDesiredCategories, $wpCategories[0]->term_id);
                    } else {
                        logInfo("Need to create category: ".$desiredCategory);
                        $newTerm= wp_insert_term($desiredCategory, 'event-categories');
                        if (is_array($newTerm)) {
                            array_push($wpDesiredCategories, $newTerm["term_id"]);
                        } else {
                            logError("Failed inserting new event category ".$desiredCategory." Error: ".$newTerm->get_error_message());
                        }
                    }
//                    foreach ($wpCategories as $key => $wpCategory) {
//                        logDebug("WPCategory: ".serialize($wpCategory->term_id)." ".$wpCategory->name);
//                        if ($wpCategory->name == $desiredCategory) {
//                            array_push($wpDesiredCategories, $wpCategory->term_id);
//                            $catFound= true;
//                            logDebug("Found matching category: ".$desiredCategory);
//                        }
//                    }
//                    if (!$catFound) {
//                        logInfo("Need to create category: ".$desiredCategory);
//                        $newTerm= wp_insert_term($desiredCategory, 'event-categories');
//                        array_push($wpDesiredCategories, $newTerm["term_id"]);
//                    }
                }
            }
        }
        $saveResult= $event->save();
        logInfo("Saved ct event id: ".$ctCalEntry->getId(). " WP event ID ".$event->event_id." post id: ".$event->ID." result: ".$saveResult);
        if ($addMode) {
            // Keeps track of ct event id and wp event id for subsequent updates+deletions
            $wpdb->insert($wpctsync_tablename, array(
                'ct_id' => $ctCalEntry->getId(),
                'wp_id' => $event->event_id,
                'last_seen' => date('Y-m-d H:i:s'),
                'event_start' => $ctCalEntry->getStartDate(),
                'event_end' => $ctCalEntry->getEndDate()
            ));
        } else {
            // Update last seen time stamp to flag as "still existing"
            $wpdb->query($wpdb->prepare("UPDATE ".$wpctsync_tablename." SET last_seen='".date('Y-m-d H:i:s')."' WHERE ct_id=".$ctCalEntry->getId()));
        }
    } else {
        // Perhaps we need to remove it, since visibility has changed
        global $wpctsync_tablename;
        global $wpdb;
        $result = $wpdb->get_results(sprintf('SELECT * FROM `%2$s` WHERE `ct_id` = %d ', $ctCalEntry->getId(), $wpctsync_tablename));
        $addMode= false;
        if (sizeof($result) == 1) {
            // We did already map it
            logInfo("Found mapping for ct id: ".$ctCalEntry->getId());
            $event= em_get_event($result[0]->wp_id);
            if ($event->ID != null ){
                // Is in events table, so delete it
                $event->delete();
            }
            logDebug("Deleting mapping entry for ct_id: ".$ctCalEntry->getId());
            $wpdb->query($wpdb->prepare("DELETE FROM ".$wpctsync_tablename." WHERE ct_id=".$ctCalEntry->getId()));
        }        
    }
    
}

/**
 * Find an existing location or create a new one and return the location id to associate with the event
 * 
 * @param CTApi\Models\Calendars\Appointment\Address $appointmentAddress
 * @return type a location id, either a found location or a newly created one
 */
function getCreateLocation(CTApi\Models\Calendars\Appointment\Address $appointmentAddress) {
     if ($appointmentAddress != null) {
         logDebug("CT Location address is: ".serialize($appointmentAddress));
         $appointmentAddress->getMeetingAt(); // Ortsangabe (Zentrum Ipsach etc.)
         $appointmentAddress->getAddition(); // Weitere Ortsangabe
         $appointmentAddress->getStreet(); // Strasse
         $appointmentAddress->getZip(); // PLZ
         $appointmentAddress->getCity(); // Ort
         $appointmentAddress->getDistrict(); // Kanton
         $appointmentAddress->getCountry(); // Land 2-ISO code
         $appointmentAddress->getLatitude(); // Latitude
         $appointmentAddress->getLongitude(); // Longitude
         
         $emLocations= EM_Locations::get();
         $matchedLocation= null;
         foreach ($emLocations as $key => $emLocation) {
             if ($appointmentAddress->getMeetingAt() == $emLocation->name && 
                 $appointmentAddress->getCity() == $emLocation->town && 
                 $appointmentAddress->getZip() == $emLocation->postcode &&
                 $appointmentAddress->getCountry() == $emLocation->location_country
                 ) {
                    $matchedLocation= $emLocation;
                    break;
             }
         }
         if ($matchedLocation != null) {
            logDebug("Location found: ".$appointmentAddress->getMeetingAt()." ".$appointmentAddress->getZip()." ".$appointmentAddress->getCity()." ".$appointmentAddress->getCountry());
            return $matchedLocation->id;
         } else {
             logInfo("Creating new location for ".$appointmentAddress->getMeetingAt()." ".$appointmentAddress->getZip()." ".$appointmentAddress->getCity()." ".$appointmentAddress->getCountry());
             // Create new location
             $newLocation= new EM_Location();
             $newLocation->name= $appointmentAddress->getMeetingAt();
             $newLocation->location_street= $appointmentAddress->getStreet();
             $newLocation->location_town= $appointmentAddress->getCity();
             $newLocation->location_postcode= $appointmentAddress->getZip();
             $newLocation->location_state= $appointmentAddress->getDistrict();
             $newLocation->location_country= $appointmentAddress->getCountry();
             $newLocation->location_latitude= $appointmentAddress->getLatitude();
             $newLocation->location_longitude= $appointmentAddress->getLongitude();
             $newLocation->save();
             return $newLocation->id;
         }
     }
}

function logDebug($message) {
    global $wpctsyncDoDebugLog;
    if ($wpctsyncDoDebugLog) {
       $logger= plugin_dir_path(__FILE__).'wpcalsync.log';
       // Usage of logging
       // $message = 'SOME ERROR'.PHP_EOL;
       // error_log($message, 3, $logger);
       error_log("DBG: ".$message. "\n", 3, $logger);
    }
}

function logInfo($message) {
    global $wpctsyncDoInfoLog;
    if ($wpctsyncDoInfoLog) {
       $logger= plugin_dir_path(__FILE__).'wpcalsync.log';
       // Usage of logging
       // $message = 'SOME ERROR'.PHP_EOL;
       // error_log($message, 3, $logger);
       error_log("INF: ".$message. "\n", 3, $logger);
    }
}

function logError($message) {
    $logger= plugin_dir_path(__FILE__).'wpcalsync.log';
    // Usage of logging
    // $message = 'SOME ERROR'.PHP_EOL;
    // error_log($message, 3, $logger);
    error_log("ERR: ".$message. "\n", 3, $logger);
}

