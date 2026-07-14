<?php
/**
 * Focused test for the end time issue during DST transition
 */

// Mock WordPress timezone function
function wp_timezone_string() {
    return 'Europe/Zurich'; // Switzerland timezone with DST
}

echo "=== FOCUSED END TIME ISSUE TEST ===\n";
echo "Problem: Event ending at 09:00 CET shows as 10:00 in WordPress\n";
echo "Event: 25.10.2025 20:00 CEST -> 26.10.2025 09:00 CET\n\n";

// DST transition: October 26, 2025 at 3:00 AM (clocks fall back to 2:00 AM)
$startDateStr = '2025-10-25T20:00:00+02:00'; // Summer time (CEST)
$endDateStr = '2025-10-26T09:00:00+01:00';   // Winter time (CET)

echo "ChurchTools provides:\n";
echo "Start: $startDateStr\n";
echo "End: $endDateStr\n\n";

echo "=== CURRENT CODE PATH ===\n";

// Simulate the current code from lines 320-334
$sDate = new DateTime($startDateStr);
$eDate = new DateTime($endDateStr);

// Convert to WordPress timezone
$wpTimezone = new DateTimeZone(wp_timezone_string());
$sDate->setTimezone($wpTimezone);
$eDate->setTimezone($wpTimezone);

echo "After timezone conversion:\n";
echo "Start: " . $sDate->format('Y-m-d H:i:s T') . "\n";
echo "End: " . $eDate->format('Y-m-d H:i:s T') . "\n";

// This is what gets stored in WordPress Event Manager
$event_start_date = $sDate->format('Y-m-d');
$event_end_date = $eDate->format('Y-m-d');
$event_start_time = $sDate->format('H:i:s');
$event_end_time = $eDate->format('H:i:s');

echo "\nWhat WordPress Event Manager stores:\n";
echo "event_start_date: $event_start_date\n";
echo "event_end_date: $event_end_date\n";
echo "event_start_time: $event_start_time\n";
echo "event_end_time: $event_end_time\n";

// Check if this is the problematic result
if ($event_end_time === '10:00:00') {
    echo "\n❌ CONFIRMED: End time is 10:00:00 (WRONG)\n";
} else if ($event_end_time === '09:00:00') {
    echo "\n✅ LOOKS GOOD: End time is 09:00:00 (CORRECT)\n";
    echo "If you're still seeing 10:00 in WordPress, the issue might be elsewhere.\n";
} else {
    echo "\n⚠️  UNEXPECTED: End time is $event_end_time\n";
}

echo "\n=== ADDITIONAL ANALYSIS ===\n";

// Check what WordPress timezone is actually set to
echo "WordPress timezone: " . wp_timezone_string() . "\n";

// Check if there might be server timezone interference
echo "Server timezone: " . date_default_timezone_get() . "\n";
echo "Server time: " . date('Y-m-d H:i:s T') . "\n";

// Test if the issue is in how DateTime handles the specific DST transition
echo "\nDetailed end date parsing:\n";
$testEndDate = new DateTime($endDateStr);
echo "Original timezone: " . $testEndDate->getTimezone()->getName() . "\n";
echo "Original offset: " . $testEndDate->format('P') . "\n";
echo "UTC timestamp: " . $testEndDate->getTimestamp() . "\n";

$testEndDate->setTimezone($wpTimezone);
echo "After WP timezone conversion: " . $testEndDate->format('Y-m-d H:i:s T P') . "\n";
echo "Final time for WP: " . $testEndDate->format('H:i:s') . "\n";

// Let's also test what happens if we use the database storage format
echo "\n=== DATABASE FORMAT TEST ===\n";
$dbEndDate = new DateTime($endDateStr);
$dbEndDate->setTimezone($wpTimezone);
$dbFormatted = $dbEndDate->format('Y-m-d H:i:s');
echo "Database format: $dbFormatted\n";

// Parse it back
$parsedBack = new DateTime($dbFormatted, $wpTimezone);
echo "Parsed back: " . $parsedBack->format('Y-m-d H:i:s T') . "\n";
echo "Time component: " . $parsedBack->format('H:i:s') . "\n";
?>