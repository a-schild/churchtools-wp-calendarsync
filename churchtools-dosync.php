<?php
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
    require __DIR__ . '/vendor/autoload.php';
}

use CTApi\CTConfig;
use CTApi\CTLog;
CTLog::enableFileLog(); // enable logfile

$myFile = fopen("F:/x/kgn/calsync.log", "w");
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
    $fromDate= Date('y-m-d', strtotime('-'.$pastDays.' days'));
    $toDate= Date('y-m-d', strtotime('+'.$futureDays.' days'));
    $api= new CTApi\CTClient();
    $result= CTApi\Requests\AppointmentRequest::forCalendars($calendars)
        ->where('from', $fromDate)
        ->where('to', $toDate)
        ->get();
    foreach ($result as $key => $value) {
        if (!$value->getIsInternal()) {
            fwrite($myFile, "\n");
            fwrite($myFile, "\nCaption: ".$value->getCaption());
            fwrite($myFile, "\nStartdate: ".$value->getStartDate());
            fwrite($myFile, "\nEndDate: ".$value->getEndDate());
            fwrite($myFile, "\n".$value->getAllDay());
            fwrite($myFile, "\nCaption: ".serialize($value));
            $event= new EM_Event(false);
            // $event->event_timezone= "UTC+0"; // Only marks it as UTC, not usefull
            $event->event_timezone= wp_timezone_string(); // Fix it to the correct location
            $event->event_name= $value->getCaption();
            $event->post_content= $value->getInformation();
            $event->set_status(0); // Publish entry would be 1
            fwrite($myFile, serialize($value->getAllDay()));
            if ($value->getAllDay() === "true") {
                $sDate= $value->getStartDate();
                $eDate= $value->getEndDate();
                fwrite($myFile, "\nStart date: ". $sDate);
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
                fwrite($myFile, "\nStart date: ". $sDate->format('Y-m-d'));
                $event->event_start_date= $sDate->format('Y-m-d');
                $event->event_end_date= $eDate->format('Y-m-d');
                fwrite($myFile, "\nStart time: ".$sDate->format('H:i:s'));
                $event->event_start_time= $sDate->format('H:i:s');
                $event->event_end_time= $eDate->format('H:i:s');
                $event->event_all_day= false;
            }
//            fwrite($myFile, serialize($event));
//            fwrite($myFile, "\nStart: ".serialize($sDate)."\n");
//            fwrite($myFile, "\nEnd: ". serialize($eDate)."\n");
            $saveResult= $event->save();
            fwrite($myFile, "\nSave result: ".serialize($saveResult)."\n");
            fwrite($myFile, serialize($event));
        }
    }
}
catch (Exception $e)
{
    $errorMessage= $e->getMessage();
    fwrite($myFile, $errorMessage);
    $hasError= true;
    session_destroy();
}

fclose($myFile);
