<?php
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
    require __DIR__ . '/vendor/autoload.php';
}

use CTApi\CTConfig;
use CTApi\CTLog;
use CTApi\CTClient;
use CTApi\Models\Calendars\Appointment\Address;
use CTApi\Models\Calendars\Appointment\Appointment;
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

if (!is_plugin_active('events-manager/events-manager.php')) {
    logError("We need an activated events manager plugin, doing nothing");
    return;
}
// Make sure it's configured, else do nothing
if(empty($options) || empty($options['url']) || empty($options['apitoken'])){
    logError("No sync options found, (url and/or api token missing), doing nothing");
    return;
}
if (get_current_user_id() == 0) {
    logError("No user specified, doing nothing since events needs an owner");
    return;
}

if (!is_user_logged_in()) {
    logError("User not logged in, doing nothing since events needs an owner");
    return;
}

$startTimestamp= Date('Y-m-d H:i:s');
logInfo("Start sync cycle ".$startTimestamp);
try
{
    $serverURL= $options['url'];
    $apiToken= $options['apitoken'];
    CTConfig::setApiURL($serverURL);
    CTConfig::setApiKey($apiToken);
    CTConfig::validateConfig();
    $calendars= $options['ids'];
    $calendars_categories= $options['ids_categories'];
    $calendars_categories_mapping= [];

    $i = 0;
    while($i < count($calendars))
    {
        if ($i < count($calendars_categories)) {
            if (isset($calendars_categories[$i])) {
                $calendars_categories_mapping[$calendars[$i]]=  $calendars_categories[$i];
            } else {
                $calendars_categories_mapping[$calendars[$i]]=  null;
            }
        } else {
            logInfo("Calendar categories maping out of range ".$i);
            logInfo(serialize($calendars));
            logInfo(serialize($calendars_categories));
            $calendars_categories_mapping[$calendars[$i]]=  null;
        }
        $i++;
    } 
    logDebug("Categories mapping via calendar ID's: ".serialize($calendars_categories_mapping));

    $pastDays= $options['import_past'];
    $futureDays=  $options['import_future'];
    $resourcetype_for_categories= $options['resourcetype_for_categories'];
    // Make sure to have a valid expression, and not something like "now - -1"
    if ($pastDays < 0) {
        $fromDate= Date('Y-m-d', strtotime('+'.($pastDays*-1).' days'));
    } else {
        $fromDate= Date('Y-m-d', strtotime('-'.$pastDays.' days'));
    }
    // Make sure to have a valid expression, and not something like "now - -1"
    if ($futureDays < 0) {
        $toDate= Date('Y-m-d', strtotime('-'.($futureDays*-1).' days'));
    } else {
        $toDate= Date('Y-m-d', strtotime('+'.$futureDays.' days'));
    }
    
    $api= new CTClient();
    logInfo("Searching calendar entries from ".$fromDate." until ".$toDate. " in calendars [".implode(",", $calendars)."]");
    $result= AppointmentRequest::forCalendars($calendars)
        ->where('from', $fromDate)
        ->where('to', $toDate)
        ->get();
    foreach ($result as $key => $ctCalEntry) {
        processCalendarEntry($ctCalEntry, $calendars_categories_mapping, $resourcetype_for_categories);
    }
    // Now we will have to handle all wp events which are no longer visible
    // from CT (Either deleted or moved in another calendar)
    // But don't remove old entries
    cleanupOldEntries($fromDate, $startTimestamp);
    $endTimestamp= Date('Y-m-d H:i:s');
    $sdt= new DateTime($startTimestamp);
    $edt= new DateTime($endTimestamp);
    set_transient('churchtools_wpcalendarsync_lastupdated',$startTimestamp.' to '.$endTimestamp, 0);
    $interval = $edt->diff($sdt);
    set_transient('churchtools_wpcalendarsync_lastsyncduration',$interval->format('%H:%I:%S'), 0);
}
catch (Exception $e)
{
    $errorMessage= $e->getMessage();
    logError($errorMessage);
    $hasError= true;
    session_destroy();
}
logInfo("End sync cycle ".Date('Y-m-d H:i:s'));

/**
 * 
 * Process a single calendar entry from ct and create or update wp event
 * 
 * @param Appointment $ctCalEntry a CT calendar entry to be analyzed and processed
 * @param array $calendars_categories_mapping Array with category for calendar mapping
 * @param int $resourcetype_for_categories Us this resource type for categories mapping
 * 
 */
function processCalendarEntry(Appointment $ctCalEntry, array $calendars_categories_mapping, int $resourcetype_for_categories) {
    $isRepeating= $ctCalEntry->getRepeatId() != "0";
    if (!$ctCalEntry->getIsInternal()) {
        logDebug("Caption: ".$ctCalEntry->getCaption()." StartDate: ".$ctCalEntry->getStartDate()." EndDate: ".$ctCalEntry->getEndDate().
            " Is allday: ".$ctCalEntry->getAllDay().($isRepeating ? " Is repeating" : " Is not repeating"));
        //logDebug("Object: ".serialize($ctCalEntry));
        global $wpctsync_tablename;
        global $wpdb;
        if ($isRepeating) {
            $sql= $wpdb->prepare('SELECT * FROM `'.$wpctsync_tablename.'` WHERE `ct_id` = %d and ct_repeating=1 '
                . 'and event_start=%s ;', array($ctCalEntry->getId(), date_format( date_create($ctCalEntry->getStartDate()), 'Y-m-d H:i:s' )));
        } else {
            $sql= $wpdb->prepare('SELECT * FROM `'.$wpctsync_tablename.'` WHERE `ct_id` = %d ;', array($ctCalEntry->getId()));
        }
        // logDebug(serialize($sql));
        $result= $wpdb->get_results($sql);
        $addMode= false;
        $ct_image_id= null; // CT file id of image
        $wp_image_id= null; // WP attachment id of post image
        $ct_flyer_id= null; // CT file id of flyer
        $wp_flyer_id= null; // WP flyer attachment id
        $newCtImageID= null;
        // logDebug(serialize($result));
        if (sizeof($result) == 1) {
            // We did already map it
            logDebug("Found mapping for ct id: ".$ctCalEntry->getId()." so already synched in the past");
            $event= em_get_event($result[0]->wp_id);
            if ($event->ID != null ){
                // OK, still existing, make sure it's not in trash
                // logDebug(serialize($event));
                if ($event->status == -1) {
                    // Is deleted
                    logInfo("Event is in wp trash, removing from mapping ct id: ". $ctCalEntry->getId());
                    if ($isRepeating) {
                        $wpdb->query($wpdb->prepare("DELETE FROM ".$wpctsync_tablename." WHERE ct_id=".$ctCalEntry->getId()." and event_start='".date_format( date_create($ctCalEntry->getStartDate()), 'Y-m-d H:i:s' ))."';");
                    } else {
                        $wpdb->query($wpdb->prepare("DELETE FROM ".$wpctsync_tablename." WHERE ct_id=".$ctCalEntry->getId()));
                    }
                    $event= new EM_Event(false);
                    $addMode= true;
                } else {
                    logDebug("Event status in wordpress ". $event->event_status);
                    $addMode= false;
                    $ct_image_id= $result[0]->ct_image_id;
                    $wp_image_id= $result[0]->wp_image_id;
                    $ct_flyer_id= $result[0]->ct_flyer_id;
                    $wp_flyer_id= $result[0]->wp_flyer_id;
                }
            } else {
                // No longer found, deleted?
                logInfo("Event no longer found in wp, removing from mapping, ct id: ".$ctCalEntry->getId());
                if ($isRepeating) {
                    $wpdb->query($wpdb->prepare("DELETE FROM ".$wpctsync_tablename." WHERE ct_id=".$ctCalEntry->getId()." and event_start='".date_format( date_create($ctCalEntry->getStartDate()), 'Y-m-d H:i:s' ))."';");
                } else {
                    $wpdb->query($wpdb->prepare("DELETE FROM ".$wpctsync_tablename." WHERE ct_id=".$ctCalEntry->getId()));
                }
                $addMode= true;
            }
        } else {
            logDebug("No mapping for event ".$ctCalEntry->getId()." found, so create a new one");
            $event= new EM_Event(false);
            $addMode= true;
        }
        //logDebug("Query result: ".serialize($result));
        //logDebug("Query result size: ".sizeof($result));
        // logDebug(serialize($ctCalEntry));
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
            $event->event_all_day= 1;
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
            $event->event_all_day= 0;
        }
        $imageURL= null;
        $imageName= null;
        if ($ctCalEntry->getImage() != null) {
            // Handle image from ct calendar entry
            $newCtImageID= $ctCalEntry->getImage()->getId();
            if ($addMode || $ct_image_id == null || $ct_image_id != $newCtImageID) {
                $imageURL= $ctCalEntry->getImage()->getFileUrl();
                $imageName= $ctCalEntry->getImage()->getName();

                logDebug("Found image in CT: ". $ctCalEntry->getImage()->getFileUrl()." filename: ".$ctCalEntry->getImage()->getName());
//                if (has_post_thumbnail($event->id)) {
//                    logDebug("Has thumbnail");
//                    $image= get_post_thumbnail_id( $event->id, 'full' );
//                    //$image = wp_get_attachment_image_src( get_post_thumbnail_id( $event->id, 'single-post-thumbnail'));
//                    logDebug(serialize($image));
//                } else {
//                    logDebug("No thumbnail");
//                }
            }
        }
        $saveResult= $event->save();
        if ($saveResult) {
            logInfo("Saved ct event id: ".$ctCalEntry->getId(). " WP event ID ".$event->event_id." post id: ".$event->ID." result: ".$saveResult." serialized: ".serialize($saveResult) );
            if ($imageURL != null) {
                $attachmentID= setEventImage($imageURL, $imageName, $event->ID);
                logDebug("Attached image ".$imageName." from ".$imageURL." as attachement ".$attachmentID);
            }
            if ($addMode) {
                // Keeps track of ct event id and wp event id for subsequent updates+deletions
                $wpdb->insert($wpctsync_tablename, array(
                    'ct_id' => $ctCalEntry->getId(),
                    'wp_id' => $event->event_id,
                    'ct_image_id' => $newCtImageID,
                    'last_seen' => date('Y-m-d H:i:s'),
                    'event_start' => $ctCalEntry->getStartDate(),
                    'event_end' => $ctCalEntry->getEndDate(),
                    'ct_repeating' => $isRepeating ? 1 : 0
                ));
            } else {
                // Update last seen time stamp to flag as "still existing"
                $sql= "UPDATE ".$wpctsync_tablename.
                    " SET last_seen='".date('Y-m-d H:i:s')."' ";
                if ($newCtImageID != null) {
                    $sql.= ", ct_image_id=".$newCtImageID." ";
                }
                $sql.= "WHERE ct_id=".$ctCalEntry->getId();
                if ($isRepeating ) {
                    $sql.= " AND event_start='".date_format( date_create($ctCalEntry->getStartDate()), 'Y-m-d H:i:s' ). " ';";
                }
                $wpdb->query($wpdb->prepare($sql));
                // logDebug(serialize($sql));
            }
            // Handle event categories from resource type
            updateEventCategories($calendars_categories_mapping, $resourcetype_for_categories, $ctCalEntry, $event);
        } else {
            logError("Saving new event failed for ct id: ".$ctCalEntry->getId() );
        }
    } else {
        // Perhaps we need to remove it, since visibility has changed
        global $wpctsync_tablename;
        global $wpdb;
        if ($isRepeating) {
            $result= $wpdb->get_results(sprintf('SELECT * FROM `%2$s` WHERE `ct_id` = %d and event_start='.date_format( date_create($ctCalEntry->getStartDate()), 'Y-m-d H:i:s' ), $ctCalEntry->getId(), $wpctsync_tablename));
        } else {
            $result= $wpdb->get_results(sprintf('SELECT * FROM `%2$s` WHERE `ct_id` = %d ', $ctCalEntry->getId(), $wpctsync_tablename));
        }
        $addMode= false;
        if (sizeof($result) == 1) {
            // We did already map it
            logInfo("Found mapping for ct id: ".$ctCalEntry->getId());
            $event= em_get_event($result[0]->wp_id);
            if ($event->ID != null ){
                // Is in events table, so delete it
                $event->delete();
            }
            if ($isRepeating) {
                logDebug("Deleting mapping entry for ct_id: ".$ctCalEntry->getId()." start date: ".date_format( date_create($ctCalEntry->getStartDate()), 'Y-m-d H:i:s' ));
                $wpdb->query($wpdb->prepare("DELETE FROM ".$wpctsync_tablename." WHERE ct_id=".$ctCalEntry->getId(). "and event_start='".date_format( date_create($ctCalEntry->getStartDate()), 'Y-m-d H:i:s' )."';"));
            } else {
                logDebug("Deleting mapping entry for ct_id: ".$ctCalEntry->getId());            
                $wpdb->query($wpdb->prepare("DELETE FROM ".$wpctsync_tablename." WHERE ct_id=".$ctCalEntry->getId()));
            }
        }        
    }
    
}

/**
 * Find an existing location or create a new one and return the location id to associate with the event
 * 
 * @param CTApi\Models\Calendars\Appointment\Address $appointmentAddress
 * @return type a location id, either a found location or a newly created one
 */
function getCreateLocation(Address $appointmentAddress) {
     if ($appointmentAddress != null) {
         // logDebug("CT Location address is: ".serialize($appointmentAddress));
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

/**
 * Update/create event categories based on the churchtool resources assigned to the appointment
 * 
 * @param int $resourcetype_for_categories
 * @param CTApi\Models\Calendars\Appointment\Appointment $ctCalEntry
 * @param EM_Event $event
 */
function updateEventCategories(array $calendars_categories_mapping, int $resourcetype_for_categories, Appointment $ctCalEntry, EM_Event $event) {

	$desiredCategories= [];
	if ($calendars_categories_mapping[$ctCalEntry->getCalendar()->getId()] != null) {
		// Add category via calendar id source
		logDebug('Found category by calendar ID '.$calendars_categories_mapping[$ctCalEntry->getCalendar()->getId()]);
		array_push($desiredCategories, $calendars_categories_mapping[$ctCalEntry->getCalendar()->getId()]);
	} else {
		logDebug('No category found by calendar ID '. $ctCalEntry->getCalendar()->getId().' '.serialize($calendars_categories_mapping));
	}

    if ($resourcetype_for_categories > 0) {
        logDebug("Using resources of type ".$resourcetype_for_categories." for wordpress categories");
        // So we retrieve the resources booked with this calendar entry
        if ($ctCalEntry->getAllDay() === "true") {
            $sDate= \DateTime::createFromFormat('Y-m-d', $ctCalEntry->getStartDate(), new DateTimeZone('UTC'));
        } else {
            $sDate= \DateTime::createFromFormat('Y-m-d\TH:i:s+', $ctCalEntry->getStartDate(), new DateTimeZone('UTC'));
        }
        $combinedAppointment= CombinedAppointmentRequest::forAppointment($ctCalEntry->getCalendar()->getId(), $ctCalEntry->getId(), $sDate->format('Y-m-d'))->get();
        // logDebug("Got combined appointment ".serialize($combinedAppointment));
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
	}
	if (sizeof($desiredCategories) > 0) {
		$wpDesiredCategories= [];
		foreach ($desiredCategories as $dcKey => $desiredCategory) {
			$taxFilter = array( 'taxonomy' => EM_TAXONOMY_CATEGORY, 'name' => $desiredCategory, 'hide_empty' => false);
			$wpCategories= get_terms($taxFilter);
			// logDebug("Results: ".sizeof($wpCategories));
			if (sizeof($wpCategories) >= 1) {
				logDebug("Found matching wp category: ".$desiredCategory . " wp: ".$wpCategories[0]->term_id);
				array_push($wpDesiredCategories, $wpCategories[0]->term_id);
			} else {
				logInfo("Need to create category: ".$desiredCategory);
				$newTerm= wp_insert_term($desiredCategory, EM_TAXONOMY_CATEGORY);
				if (is_array($newTerm)) {
					array_push($wpDesiredCategories, $newTerm["term_id"]);
				} else {
					logError("Failed inserting new event category ".$desiredCategory." Error: ".$newTerm->get_error_message());
				}
			}
			wp_set_post_terms($event->ID, $wpDesiredCategories, EM_TAXONOMY_CATEGORY);
		}
	}
}

/**
 * Remove no longer existing/found entries
 * We only look for entries in the future (Or more precise the >= $startDate
 * 
 * @param type $startDate       Only look at events starting >= $startDate
 * @param type $processingStart All records with lastSeen < $processingStart are no longer existing
 * 
 */
function cleanupOldEntries($startDate, $processingStart) {
    global $wpctsync_tablename;
    global $wpdb;
    $sql= 'SELECT * FROM `'.$wpctsync_tablename.'` WHERE `event_start` >= \''.$startDate.'\' and last_seen < \''.$processingStart.'\'' ;
    $result = $wpdb->get_results($sql);
    // logDebug("Found ".sizeof($result).' events to delete via '.$sql);
    // Now process all deletions
    foreach ($result as $key => $toDelRecord) {
        $delID= $toDelRecord->id; // PK in sync table
        $ctID= $toDelRecord->ct_id; // CT appintment id in sync table
        $wpEventID= $toDelRecord->wp_id; // WP event id
        // logDebug("Deleting wp event with id ".$wpEventID);
        $toDelEvent= new EM_Event($wpEventID);
        if ($toDelEvent != null) {
            $toDelEvent->delete(false);
        }
        logDebug("Deleting mapping entry for ct_id: ".$ctID." via PK id: ".$delID);
        $wpdb->query($wpdb->prepare("DELETE FROM ".$wpctsync_tablename." WHERE id=".$delID));
    }
}

/*
 * $fileURL Download the image file from there
 * $fileName Name of the file to download
 * $postID  Attach to this post
 * 
 * return attachmentID
 * 
 * $file is the path to your uploaded file (for example as set in the $_FILE posted file array)
 * $filename is the name of the file
 * first we need to upload the file into the wp upload folder.
 */
function setEventImage($fileURL, $fileName, $postID) {
    $upload_file = wp_upload_bits( $fileName, null, file_get_contents($fileURL) );
    logDebug("Result of fileupload :".serialize($upload_file));
    if ( ! $upload_file['error'] ) {
      // if succesfull insert the new file into the media library (create a new attachment post type).
      $wp_filetype = wp_check_filetype($fileName, null );

      $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_parent'    => $postID,
        'post_title'     => preg_replace( '/\.[^.]+$/', '', $fileName ),
        'post_content'   => '',
        'post_status'    => 'inherit'
      );

      $attachment_id = wp_insert_attachment( $attachment, $upload_file['file'], $postID );

      if ( ! is_wp_error( $attachment_id ) ) {
         // if attachment post was successfully created, insert it as a thumbnail to the post $post_id.
         require_once(ABSPATH . "wp-admin" . '/includes/image.php');

         $attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload_file['file'] );

         wp_update_attachment_metadata( $attachment_id,  $attachment_data );
         set_post_thumbnail( $postID, $attachment_id );
       }
    }
    return $attachment_id;
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

