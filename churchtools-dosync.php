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
use CTApi\Models\Calendars\CombinedAppointment\CombinedAppointment;
use CTApi\Models\Common\File\FileRequest;

CTLog::enableFileLog(); // enable logfile


global $wpctsyncDoInfoLog;
$wpctsyncDoInfoLog= true;
global $wpctsyncDoDebugLog;
$wpctsyncDoDebugLog= false;

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
	set_time_limit(300); // 5min to process all events
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
	global $wpdb;
	// begin transaction, so we don't have duplicate entries
	// if an execution timeout occurs
	$wpdb->query('START TRANSACTION');
	try
	{
		if (!$ctCalEntry->getIsInternal()) {
			logDebug("Caption: ".$ctCalEntry->getCaption()." StartDate: ".$ctCalEntry->getStartDate()." EndDate: ".$ctCalEntry->getEndDate().
				" Is allday: ".$ctCalEntry->getAllDay().($isRepeating ? " Is repeating" : " Is not repeating"));
			logDebug("Start date time" .serialize($ctCalEntry->getStartDateAsDateTime()));
			logDebug("End date time" .serialize($ctCalEntry->getEndDateAsDateTime()));
			//logDebug("Object: ".serialize($ctCalEntry));
			global $wpctsync_tablename;
			if ($isRepeating) {
				$sql= $wpdb->prepare('SELECT * FROM `'.$wpctsync_tablename.'` WHERE `ct_id` = %d and ct_repeating=1 '
					. 'and event_start=\'%s\' ;', array($ctCalEntry->getId(), date_format( date_create($ctCalEntry->getStartDate()), 'Y-m-d H:i:s' )));
			} else {
				$sql= $wpdb->prepare('SELECT * FROM `'.$wpctsync_tablename.'` WHERE `ct_id` = %d ;', array($ctCalEntry->getId()));
			}
			logDebug(serialize($sql));
			$result= $wpdb->get_results($sql);
			$addMode= false;
			$ct_image_id= null; // CT file id of image
			$wp_image_id= null; // WP attachment id of post image
			$ct_flyer_id= null; // CT file id of flyer
			$wp_flyer_id= null; // WP flyer attachment id
			$newCtImageID= null;
			$newCTFlyerId= null;
			$newWPFlyerId= null;
			// logDebug(serialize($result));
			if (sizeof($result) == 1) {
				// We did already map it
				logDebug("Found mapping for ct id: ".$ctCalEntry->getId()." so already synched in the past");
				$event= em_get_event($result[0]->wp_id);
				// Make sure we are an event
				// Note: Changed from "event" to "single" in Events Manager 7.1+ to avoid confusion with CPTs
				$event->event_type= "single";
				$event->event_archetype= "event"; // Required for Events Manager 7.2+
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
						$event->event_type= "single";
						$event->event_archetype= "event"; // Required for Events Manager 7.2+
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
				$event->event_type= "single";
				$event->event_archetype= "event"; // Required for Events Manager 7.2+
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

			//Cache link and information
			$ctLink = $ctCalEntry->getLink();
			if ($ctLink !== null && strlen(trim($ctLink)) > 0) {
				if (!(str_starts_with($ctLink, "http://") || (str_starts_with($ctLink, "https://")))) {
					$ctLink = "https://" . $ctLink;
				}
			}
			$ctInfo = $ctCalEntry->getInformation() ?: '';
			//When the link is set, attempt to embed it into the information text
			if (!empty($ctLink)) {
				logDebug("Found link to insert ".$ctLink);
				$count = 0;
				//Tries to replace "#LINK:Link-Title:#" with a html link. $count is updated to check whether the call succeeded
				$infoAndLink = preg_replace('/#LINK:(.*?):#/', '<a href="'.$ctLink.'" target="_blank">$1</a>', $ctInfo, 1, $count);
				if ($count == 0) {
					//Did not succeed, simply append the link at the end of the text
					$infoAndLink = $ctInfo . "<br>\n".'<a href="'.$ctLink.'" target="_blank">Link</a>';
					logDebug("Adding link at the bottom of the text ".$ctLink);
				} else {
					logDebug("Replaced text $count time(s) with link ".$ctLink);
				}
				//Set text with link in WP event
				$event->post_content = $infoAndLink;
			} else {
				//Link is empty, just use the text
				$event->post_content = $ctInfo;
			}

			if ($ctCalEntry->getAllDay() === "true") {
				$sDate= \DateTime::createFromFormat('Y-m-d', $ctCalEntry->getStartDate(), new DateTimeZone('UTC'));
			} else {
				$sDate= \DateTime::createFromFormat('Y-m-d\TH:i:s+', $ctCalEntry->getStartDate(), new DateTimeZone('UTC'));
			}
			// Look into event and resources
			$combinedAppointment= CombinedAppointmentRequest::forAppointment($ctCalEntry->getCalendar()->getId(), $ctCalEntry->getId(), $sDate->format('Y-m-d'))->get();
			if ($combinedAppointment != null) {
				logDebug("Found combined appointment");
				$ctEvent= $combinedAppointment->getEvent();
				if ($ctEvent != null) {
					logDebug("Found associated event to appointment");
					// Has associated event
					$eventId= $ctEvent->getId();
					if ($eventId != null ){
						logDebug("Found ct event with ID ".$eventId);
						$eventFiles= FileRequest::forEvent($eventId)->get();
						if ($eventFiles != null) {
							foreach ($eventFiles as $ctFile) {
								if ($ctFile->getName() != null && str_contains(strtolower($ctFile->getName()), "flyer")) {
									logDebug("Found flyer to attach ".$ctFile->getId()." with name ". $ctFile->getName(). " fileURL: ".$ctFile->getFileUrl());
									if ($ctFile->getId() == $ct_flyer_id && $wp_flyer_id != null) {
										// Already found and mapped
										logDebug("Found flyer already attached ".$ctFile->getId()." ct_flyer_id ". $ct_flyer_id . " and wp_flyer_id ".$wp_flyer_id);
										$event->post_content= addFlyerLink($event->post_content, $wp_flyer_id);
									} else {
										// Download from CT and add to media library
										$tmpFlyer= sys_get_temp_dir().DIRECTORY_SEPARATOR.$ctFile->getId();
										if (!is_dir($tmpFlyer)) {
											mkdir($tmpFlyer);
										}
										$fileResult= $ctFile->downloadToPath($tmpFlyer);
										$tmpFlyerFile= $tmpFlyer. DIRECTORY_SEPARATOR . $ctFile->getName();
										logInfo("Downloaded to ".$fileResult." ".$tmpFlyerFile);
										// TODO: Check if we already have this file in WP, otherwise upload it
										// Then attach a link to the file to the event content
										// media_handle_sideload see below
										$newCTFlyerId= $ctFile->getId();
										$newWPFlyerId= uploadFromLocalFile($tmpFlyerFile, $ctFile->getName(), null, null, $sDate->format('Y/m'));
										if ($newWPFlyerId) {
											$event->post_content= addFlyerLink($event->post_content, $newWPFlyerId);
										} else {
											logError("Error in media wp upload");
										}
									}
									// Only the first attachment with Flyer in the name
									break;
								}
							}
						}
					}
				}
			}

			// $event->status= 0; // Publish entry would be 1 (Does not work at the moment...???)
			if ($ctCalEntry->getAllDay() === "true") {
				$sDate= \DateTime::createFromFormat('Y-m-d', $ctCalEntry->getStartDate(), new DateTimeZone('UTC'));
				$eDate= \DateTime::createFromFormat('Y-m-d', $ctCalEntry->getEndDate(), new DateTimeZone('UTC'));
				logDebug("StartDate: ".$sDate->format('Y-m-d H:i:s'));
				$event->event_start_date= $sDate->format('Y-m-d');
				$event->event_end_date= $eDate->format('Y-m-d');
				$event->event_all_day= 1;
			} else {
				// Parse dates with timezone awareness using the complete ISO 8601 format
				// ChurchTools sends dates with timezone offset (e.g., 2025-10-25T08:00:00+02:00)
				// We need to parse them properly to respect the timezone information
				$sDate = new \DateTime($ctCalEntry->getStartDate());
				$eDate = new \DateTime($ctCalEntry->getEndDate());
				logDebug("EndDate without TZ conversion: ".$eDate->format('Y-m-d H:i:s T'));
				
				// Convert to WordPress timezone
				logDebug("Wordpress timezone: ".wp_timezone_string());
				$wpTimezone = new DateTimeZone(wp_timezone_string());
				$sDate->setTimezone($wpTimezone);
				
				// WORKAROUND for DST issue in ChurchTools API library
				// (https://github.com/5pm-HDH/churchtools-api/issues/227)
				// When an event spans DST transition (summer->winter), the end time
				// is incorrectly pre-converted by the library, causing a 1-hour offset.
				// We detect this by checking if the start and end dates have different DST offsets.
				$originalEndDate = new \DateTime($ctCalEntry->getEndDate());
				$startDateInWpTz = clone $sDate;
				$endDateInWpTz = clone $originalEndDate;
				$endDateInWpTz->setTimezone($wpTimezone);
				
				// Check if DST offset changed between start and end
				$startOffset = $startDateInWpTz->getOffset();
				$endOffset = $endDateInWpTz->getOffset();
				
				if ($startOffset != $endOffset) {
					logDebug("DST transition detected - Start offset: ".$startOffset.", End offset: ".$endOffset);
					// The ChurchTools API has already incorrectly adjusted the end time
					// We need to correct this by adding back the difference
					$offsetDiff = $endOffset - $startOffset;
					logDebug("Applying DST correction: adding ".$offsetDiff." seconds to end time");
					$endDateInWpTz->modify("+{$offsetDiff} seconds");
					$eDate = $endDateInWpTz;
					logDebug("Corrected EndDate: ".$eDate->format('Y-m-d H:i:s T'));
				} else {
					$eDate->setTimezone($wpTimezone);
				}
				
				logDebug("StartDate: ".$sDate->format('Y-m-d H:i:s T'));
				logDebug("EndDate: ".$eDate->format('Y-m-d H:i:s T'));
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
			// Fix for Events Manager 5.8+ and 7.x - RSVP must be explicitly set to false
			$event->event_rsvp = false;
			$saveResult= $event->save();
			if ($saveResult) {
				logDebug("Saved ct event id: ".$ctCalEntry->getId(). " WP event ID ".$event->event_id." post id: ".$event->ID." result: ".$saveResult." serialized: ".serialize($saveResult) );

				// Set post_status to 'publish' so the event is displayed on the website
				// This is especially important for Events Manager 7.x
				wp_update_post(array(
					'ID' => $event->post_id,
					'post_status' => 'publish'
				));

				if ($imageURL != null) {
					$attachmentID= setEventImage($imageURL, $imageName, $event->ID, $sDate);
					logDebug("Attached image ".$imageName." from ".$imageURL." as attachement ".$attachmentID);
				}
				if ($addMode) {
					// Keeps track of ct event id and wp event id for subsequent updates+deletions
					if (!$wpdb->insert($wpctsync_tablename, array(
						'ct_id' => $ctCalEntry->getId(),
						'wp_id' => $event->event_id,
						'ct_image_id' => $newCtImageID,
						'ct_flyer_id' => $newCTFlyerId,
						'wp_flyer_id' => $newWPFlyerId,
						'last_seen' => date('Y-m-d H:i:s'),
						'event_start' => $ctCalEntry->getStartDate(),
						'event_end' => $ctCalEntry->getEndDate(),
						'ct_repeating' => $isRepeating ? 1 : 0
						))) 
						{
							logError("Error inserting mapping, duplicates will occure ".$wpdb->last_error);
					}
				} else {
					// Update last seen time stamp to flag as "still existing"
					$sql= "UPDATE ".$wpctsync_tablename.
						" SET last_seen='".date('Y-m-d H:i:s')."' ";
					if ($newCtImageID != null) {
						$sql.= ", ct_image_id=".$newCtImageID." ";
					}
					if ($newCTFlyerId!= null) {
						$sql.= ", ct_flyer_id=".$newCTFlyerId." ";
					}
					if ($newWPFlyerId != null) {
						$sql.= ", wp_flyer_id=".$newWPFlyerId." ";
					}
					$sql.= "WHERE ct_id=".$ctCalEntry->getId();
					if ($isRepeating ) {
						$sql.= " AND event_start='".date_format( date_create($ctCalEntry->getStartDate()), 'Y-m-d H:i:s' ). " ';";
					}
					$wpdb->query($wpdb->prepare($sql));
					logDebug(serialize($sql));
				}
				// Handle event categories from resource type
				updateEventCategories($calendars_categories_mapping, $resourcetype_for_categories, $ctCalEntry, $event, $combinedAppointment);
			} else {
				logError("Saving new event failed for ct id: ".$ctCalEntry->getId() );
			}
		} else {
			// Perhaps we need to remove it, since visibility has changed
			global $wpctsync_tablename;
			global $wpdb;
			if ($isRepeating) {
				$result= $wpdb->get_results(sprintf('SELECT * FROM `%2$s` WHERE `ct_id` = %d and event_start=\''.date_format( date_create($ctCalEntry->getStartDate()), 'Y-m-d H:i:s' )."'", $ctCalEntry->getId(), $wpctsync_tablename));
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
		// commit everything
		$wpdb->query('COMMIT');
	} 
	catch (Exception $e)
	{
		// Make sure to revert if an exception happens
		$wpdb->query('ROLLBACK');
		throw $e;
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
 * 
 */
function updateEventCategories(array $calendars_categories_mapping, int $resourcetype_for_categories, Appointment $ctCalEntry, EM_Event $event, CombinedAppointment $combinedAppointment)  {

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
			if (is_wp_error($wpCategories)) {
				logError("Error getting terms for category ".$desiredCategory.": ".$wpCategories->get_error_message());
			} elseif (sizeof($wpCategories) >= 1) {
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
 * $uploadPart should by a string with the year/month of the event to prevent duplicates
 * 
 * return attachmentID
 * 
 * $file is the path to your uploaded file (for example as set in the $_FILE posted file array)
 * $filename is the name of the file
 * first we need to upload the file into the wp upload folder.
 */
function setEventImage(string $fileURL, string $fileName, int $postID, \DateTime $eventDate) {
	$uploadPart= $eventDate->format('Y/m');
	// Get upload dir
	$upload_dir    = wp_upload_dir();
	// logDebug("Upload dir: ".serialize($upload_dir));
	$upload_folder = $upload_dir['basedir'];
	// logDebug("Upload folder: ".$upload_folder);

	// Set filename, incl path
	$sanFileName= sanitize_file_name($fileName);
	$fullFilename = "{$upload_folder}/{$uploadPart}/{$sanFileName}";
	if (file_exists($fullFilename)) {
		logDebug("File exists: ".$fullFilename);
		$attachment_id = get_attachment_id_by_filename($uploadPart, $fileName);
		if ($attachment_id) {
			logDebug("File attachment exists: ".$fullFilename. " post ".$attachment_id." skipping new upload");
			set_post_thumbnail( $postID, $attachment_id );
			return $attachment_id;
		} else {
			logDebug("File attachment does not exists: ".$fullFilename);
		}
	} else {
		logDebug("File not existing: ".$fullFilename);
	}
	
    $upload_file = wp_upload_bits( $fileName, null, file_get_contents($fileURL) , $uploadPart);
    if ( ! $upload_file['error'] ) {
	  logDebug("Result of fileupload :".serialize($upload_file));
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
    } else {
		logError("Error in file upload ".serialize($upload_file));
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

/**
 * Upload a file to the media library using a URL.
 * 
 * @version 1.3
 * @author  Radley Sustaire
 * @see     https://gist.github.com/RadGH/966f8c756c5e142a5f489e86e751eacb
 *
 * @param string $url           URL to be uploaded
 * @param null|string $title    Override the default post_title
 * @param null|string $content  Override the default post_content (Added in 1.3)
 * @param null|string $alt      Override the default alt text (Added in 1.3)
 * @postDate should be the event date to prevent duplicates
 *
 * @return int|false
 */
function uploadFromLocalFile( $tmpFile, $title = null, $content = null, $alt = null, $postDate= null ) {
	require_once( ABSPATH . "/wp-load.php");
	require_once( ABSPATH . "/wp-admin/includes/image.php");
	require_once( ABSPATH . "/wp-admin/includes/file.php");
	require_once( ABSPATH . "/wp-admin/includes/media.php");
	
    
    $invalidRightsHeader= "Keine ausreichende Berechtigung"; // File starts with this content when rights are missing
    $fileContent = file_get_contents($tmpFile, false, null, 0, 64);
    if (str_starts_with($fileContent, $invalidRightsHeader)) {
        logError("Not enough rights to access file ".$tmpFile);
        // wp_delete_file($tmpFile);
        return false;
    }
    //
    // Get the filename and extension ("photo.png" => "photo", "png")
    $extension = pathinfo($tmpFile, PATHINFO_EXTENSION);
    $fileName= pathinfo($tmpFile, PATHINFO_BASENAME);
	
	// An extension is required or else WordPress will reject the upload
	if (strlen($extension) >0 ) {
		// Look up mime type, example: "/photo.png" -> "image/png"
		$mime = mime_content_type( $tmpFile );
		$mime = is_string($mime) ? sanitize_mime_type( $mime ) : false;
		
		// Only allow certain mime types because mime types do not always end in a valid extension (see the .doc example below)
        // We only allow PDF at the moment
		$mime_extensions = array(
			// mime_type         => extension (no period)
//			'text/plain'         => 'txt',
//			'text/csv'           => 'csv',
//			'application/msword' => 'doc',
//			'image/jpg'          => 'jpg',
//			'image/jpeg'         => 'jpeg',
//			'image/gif'          => 'gif',
//			'image/png'          => 'png',
//			'video/mp4'          => 'mp4',
            'application/pdf'    => 'pdf'
		);
		
		if ( isset( $mime_extensions[$mime] ) ) {
			// Use the mapped extension
			$extension = $mime_extensions[$mime];
		}else{
			// Could not identify extension. Clear temp file and abort.
            logError("Wrong media type in " . $tmpFile);
			// wp_delete_file($tmpFile);
			return false;
		}
	} else {
        logError("Missing file extension in " . $tmpFile . " extension: ".$extension);
    }
	
	// Upload by "sideloading": "the same way as an uploaded file is handled by media_handle_upload"
	$args = array(
		'name' => "$fileName.$extension",
		'tmp_name' => $tmpFile,
	);
	
	// Post data to override the post title, content, and alt text
	$post_data = array();
	if ($title) {
        $post_data['post_title'] = $title;
    }
    if ($content) {
        $post_data['post_content'] = $content;
    }
    if ($postDate) {
        $post_data['post_date'] = $postDate;
    }

    // Do the upload
	$attachment_id = media_handle_sideload( $args, 0, null, $post_data );
	
	// Clear temp file
	// wp_delete_file($tmpFile);
	
	// Error uploading
	if (is_wp_error($attachment_id)) {
        logError("Error in uploading " . $tmpFile);
        return false;
    }

    // Save alt text as post meta if provided
	if ( $alt ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
	}
	
	// Success, return attachment ID
	return (int) $attachment_id;
}

/**
 * Add the flyer link to the post content and return the new post content
 * 
 * @param type $postContent
 * @param type $wpFlyerId
 * @return string
 */
function addFlyerLink($postContent, $wpFlyerId) {
    $flyerLink= wp_get_attachment_url( $wpFlyerId );
    //Tries to replace "#FLYER:Link-Title:#" with a html link. $count is updated to check whether the call succeeded
    $infoAndFlyer = preg_replace('/#FLYER:(.*?):#/', '<a href="'.$flyerLink.'" target="_blank">$1</a>', $postContent, 1, $count);
    if ($count == 0) {
        //Did not succeed, simply append the link at the end of the text
        $infoAndFlyer = $postContent . "<br>\n".'<a href="'.$flyerLink.'" target="_blank">Download Flyer</a>';
        logDebug("Adding link at the bottom of the text ".$flyerLink);
    } else {
        logDebug("Replaced text $count time(s) with link ".$flyerLink);
    }
    logDebug("Wordpress media ID ".$wpFlyerId . " adding link to flyer");
    return $infoAndFlyer;
}

/**
 * Get the post for this uploaded file
 *
 * $subPath   for example 2025/06
 * $param $filename for example "MyPicture.png"
 * 
 */
function get_attachment_id_by_filename($subPath, $filename) {
    $query = new WP_Query([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'meta_query' => [
            [
                'key' => '_wp_attached_file',
                'value' => $subPath."/".sanitize_file_name($filename),
                'compare' => '='
            ]
        ],
        'posts_per_page' => 1,
        'fields' => 'ids'
    ]);
    
    return $query->posts ? $query->posts[0] : false;
}

/**
 * One-time migration for Events Manager 7.1+ compatibility
 *
 * Updates all existing synced events to use:
 * - event_type = "single" (changed from "event" in EM 7.1+)
 * - post_status = "publish" (required for proper display in EM 7.x)
 *
 * This function is called automatically on plugin load if migration hasn't been run yet.
 */
function ctwpsync_migrate_to_em71() {
    global $wpdb;
    global $wpctsync_tablename;

    // Check if migration has already been run
    $migration_completed = get_option('ctwpsync_em71_migration_completed');
    if ($migration_completed) {
        logDebug("Events Manager 7.1+ migration already completed, skipping");
        return;
    }

    logInfo("Starting Events Manager 7.1+ migration for existing events");

    // Get all mapped events
    $mapped_events = $wpdb->get_results("SELECT wp_id FROM {$wpctsync_tablename}");

    if (!$mapped_events || count($mapped_events) == 0) {
        logInfo("No existing events found to migrate");
        update_option('ctwpsync_em71_migration_completed', true);
        return;
    }

    $total_events = count($mapped_events);
    $updated_count = 0;
    $error_count = 0;

    logInfo("Found {$total_events} events to migrate");

    foreach ($mapped_events as $mapped_event) {
        $wp_event_id = $mapped_event->wp_id;

        try {
            // Load the event
            $event = em_get_event($wp_event_id);

            if (!$event || !$event->ID) {
                logDebug("Event {$wp_event_id} not found in WordPress, skipping");
                continue;
            }

            // Update event_type to "single" for Events Manager 7.1+
            $event->event_type = "single";

            // Set RSVP to false (required for EM 5.8+)
            $event->event_rsvp = false;

            // Save the event
            $save_result = $event->save();

            if ($save_result) {
                // Set post_status to 'publish' for Events Manager 7.x
                wp_update_post(array(
                    'ID' => $event->post_id,
                    'post_status' => 'publish'
                ));

                $updated_count++;
                logDebug("Successfully migrated event {$wp_event_id}");
            } else {
                $error_count++;
                logError("Failed to save event {$wp_event_id} during migration");
            }

        } catch (Exception $e) {
            $error_count++;
            logError("Error migrating event {$wp_event_id}: " . $e->getMessage());
        }
    }

    // Mark migration as completed
    update_option('ctwpsync_em71_migration_completed', true);

    logInfo("Events Manager 7.1+ migration completed: {$updated_count} events updated, {$error_count} errors");

    return array(
        'total' => $total_events,
        'updated' => $updated_count,
        'errors' => $error_count
    );
}

/**
 * Migrate existing events to Events Manager 7.2+ format
 * Sets the eventarchetype field to "event" for all existing events
 * This is required for events to be displayed in Events Manager 7.2+
 */
function ctwpsync_migrate_to_em72() {
    global $wpdb;

    // Check if migration has already been run (v2 uses correct field name with underscore)
    $migration_completed = get_option('ctwpsync_em72_migration_completed_v2');
    if ($migration_completed) {
        logDebug("Events Manager 7.2+ migration already completed, skipping");
        return;
    }

    logInfo("Starting Events Manager 7.2+ migration for existing events (event_archetype field)");

    // Get the Events Manager events table name
    $em_events_table = $wpdb->prefix . 'em_events';

    // Check if the event_archetype column exists in the em_events table
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$em_events_table}` LIKE 'event_archetype'");

    if (count($column_exists) == 0) {
        logInfo("event_archetype column does not exist in em_events table, skipping migration");
        update_option('ctwpsync_em72_migration_completed_v2', true);
        return;
    }

    // Update all events where event_archetype is NULL to "event"
    $result = $wpdb->query(
        "UPDATE `{$em_events_table}`
         SET `event_archetype` = 'event'
         WHERE `event_archetype` IS NULL"
    );

    if ($result === false) {
        logError("Failed to update event_archetype field during migration");
        return;
    }

    // Mark migration as completed
    update_option('ctwpsync_em72_migration_completed_v2', true);

    logInfo("Events Manager 7.2+ migration completed: {$result} events updated");

    return array(
        'updated' => $result
    );
}
