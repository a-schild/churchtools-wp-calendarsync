<?php
/**
 * Test for the specific DST issue:
 * Event starts 20:00 on the day before summer time ends
 * Event ends 09:00 in winter time
 * WordPress shows 10:00 instead of 09:00
 */

// Mock WordPress timezone function
function wp_timezone_string() {
    return 'Europe/Zurich'; // Switzerland timezone with DST
}

echo "=== TESTING SPECIFIC DST ISSUE ===\n";
echo "Event: 20:00 day before DST ends -> 09:00 day after DST ends\n";
echo "Expected: WordPress shows 09:00\n";
echo "Actual problem: WordPress shows 10:00\n\n";

// DST ends on October 26, 2025 at 3:00 AM (clocks fall back to 2:00 AM)
// Event: October 25, 20:00 CEST -> October 26, 09:00 CET
$startDateStr = '2025-10-25T20:00:00+02:00'; // Summer time (CEST)
$endDateStr = '2025-10-26T09:00:00+01:00';   // Winter time (CET)

echo "ChurchTools data:\n";
echo "Start: $startDateStr (CEST - Summer time)\n";
echo "End: $endDateStr (CET - Winter time)\n\n";

// CURRENT METHOD (the "fixed" one that still has issues)
echo "CURRENT METHOD:\n";
$sDate = new DateTime($startDateStr);
$eDate = new DateTime($endDateStr);

// Convert to WordPress timezone
$wpTimezone = new DateTimeZone(wp_timezone_string());
$sDate->setTimezone($wpTimezone);
$eDate->setTimezone($wpTimezone);

echo "Start: " . $sDate->format('Y-m-d H:i:s T') . "\n";
echo "End: " . $eDate->format('Y-m-d H:i:s T') . "\n";

$wpStartTime = $sDate->format('H:i:s');
$wpEndTime = $eDate->format('H:i:s');

echo "WordPress would store:\n";
echo "Start time: $wpStartTime\n";
echo "End time: $wpEndTime\n";

// Check if we have the problem
if ($wpEndTime === '10:00:00') {
    echo "❌ PROBLEM CONFIRMED: End time is 10:00:00 instead of 09:00:00\n";
} else if ($wpEndTime === '09:00:00') {
    echo "✅ LOOKS CORRECT: End time is 09:00:00\n";
} else {
    echo "⚠️  UNEXPECTED: End time is $wpEndTime\n";
}

echo "\n--- DETAILED ANALYSIS ---\n";

// Let's examine what happens step by step
echo "Step 1: Parse start date\n";
$sDate = new DateTime($startDateStr);
echo "  Original: " . $sDate->format('Y-m-d H:i:s T (P)') . "\n";
$sDate->setTimezone($wpTimezone);
echo "  After timezone conversion: " . $sDate->format('Y-m-d H:i:s T (P)') . "\n";

echo "\nStep 2: Parse end date\n";
$eDate = new DateTime($endDateStr);
echo "  Original: " . $eDate->format('Y-m-d H:i:s T (P)') . "\n";
$eDate->setTimezone($wpTimezone);
echo "  After timezone conversion: " . $eDate->format('Y-m-d H:i:s T (P)') . "\n";

echo "\nTimestamp analysis:\n";
echo "Start timestamp: " . $sDate->getTimestamp() . "\n";
echo "End timestamp: " . $eDate->getTimestamp() . "\n";
echo "Duration: " . (($eDate->getTimestamp() - $sDate->getTimestamp()) / 3600) . " hours\n";

// Let's also test the old problematic method for comparison
echo "\n--- OLD METHOD (for comparison) ---\n";
$sDateUTC_old = DateTime::createFromFormat('Y-m-d\TH:i:s+', $startDateStr, new DateTimeZone('UTC'));
$eDateUTC_old = DateTime::createFromFormat('Y-m-d\TH:i:s+', $endDateStr, new DateTimeZone('UTC'));

if ($sDateUTC_old && $eDateUTC_old) {
    $sDate_old = clone $sDateUTC_old;
    $sDate_old->setTimezone($wpTimezone);
    $eDate_old = clone $eDateUTC_old;
    $eDate_old->setTimezone($wpTimezone);
    
    echo "Old method start: " . $sDate_old->format('Y-m-d H:i:s T') . "\n";
    echo "Old method end: " . $eDate_old->format('Y-m-d H:i:s T') . "\n";
    echo "Old method would store end time: " . $eDate_old->format('H:i:s') . "\n";
}

// Test with date_create() which might be used in the database lookups
echo "\n--- TESTING date_create() (used in DB queries) ---\n";
$testStart = date_create($startDateStr);
$testEnd = date_create($endDateStr);

if ($testStart && $testEnd) {
    echo "date_create() start: " . $testStart->format('Y-m-d H:i:s T') . "\n";
    echo "date_create() end: " . $testEnd->format('Y-m-d H:i:s T') . "\n";
    echo "date_format() end: " . date_format($testEnd, 'Y-m-d H:i:s') . "\n";
}

?>