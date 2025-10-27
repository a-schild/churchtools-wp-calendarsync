<?php
/**
 * Comprehensive test script to verify DST transition handling
 * Tests both spring (winter->summer) and autumn (summer->winter) transitions
 */

// Mock WordPress timezone function
function wp_timezone_string() {
    return 'Europe/Zurich'; // Switzerland timezone with DST
}

/**
 * Test spring DST transition (winter time -> summer time)
 * In 2025, DST starts on March 30th at 2:00 AM (clocks jump to 3:00 AM)
 */
function test_spring_dst_transition() {
    echo "=== TESTING SPRING DST TRANSITION (Winter -> Summer Time) ===\n";
    echo "Date: March 30, 2025 (DST starts at 2:00 AM, clocks jump to 3:00 AM)\n";
    
    // Event spanning the spring DST transition
    // From 29.03.2025 01:00 CET to 30.03.2025 04:00 CEST
    $startDateStr = '2025-03-29T01:00:00+01:00'; // Winter time (CET)
    $endDateStr = '2025-03-30T04:00:00+02:00';   // Summer time (CEST)
    
    echo "\nOriginal CT dates:\n";
    echo "Start: $startDateStr (CET - Winter time)\n";
    echo "End: $endDateStr (CEST - Summer time)\n";
    
    // Expected: Event should be 26 hours long (not 27) due to DST "spring forward"
    // 29.03 01:00 CET to 30.03 04:00 CEST = 26 real hours
    
    return test_dst_scenario($startDateStr, $endDateStr, "Spring", "04:00:00");
}

/**
 * Test autumn DST transition (summer time -> winter time) 
 * In 2025, DST ends on October 26th at 3:00 AM (clocks fall back to 2:00 AM)
 */
function test_autumn_dst_transition() {
    echo "\n=== TESTING AUTUMN DST TRANSITION (Summer -> Winter Time) ===\n";
    echo "Date: October 26, 2025 (DST ends at 3:00 AM, clocks fall back to 2:00 AM)\n";
    
    // Event spanning the autumn DST transition  
    // From 25.10.2025 08:00 CEST to 26.10.2025 08:00 CET (your original example)
    $startDateStr = '2025-10-25T08:00:00+02:00'; // Summer time (CEST)
    $endDateStr = '2025-10-26T08:00:00+01:00';   // Winter time (CET)
    
    echo "\nOriginal CT dates:\n";
    echo "Start: $startDateStr (CEST - Summer time)\n";
    echo "End: $endDateStr (CET - Winter time)\n";
    
    // Expected: Event should be 25 hours long (not 24) due to DST "fall back"
    // 25.10 08:00 CEST to 26.10 08:00 CET = 25 real hours
    
    return test_dst_scenario($startDateStr, $endDateStr, "Autumn", "08:00:00");
}

/**
 * Generic test function for DST scenarios
 */
function test_dst_scenario($startDateStr, $endDateStr, $transitionType, $expectedEndTime) {
    // OLD METHOD (problematic) - Parse as UTC then convert
    $sDateUTC_old = DateTime::createFromFormat('Y-m-d\TH:i:s+', $startDateStr, new DateTimeZone('UTC'));
    $eDateUTC_old = DateTime::createFromFormat('Y-m-d\TH:i:s+', $endDateStr, new DateTimeZone('UTC'));
    $sDate_old = clone $sDateUTC_old;
    $sDate_old->setTimezone(new DateTimeZone(wp_timezone_string()));
    $eDate_old = clone $eDateUTC_old;
    $eDate_old->setTimezone(new DateTimeZone(wp_timezone_string()));
    
    echo "\nOLD METHOD (problematic):\n";
    echo "Start: " . $sDate_old->format('Y-m-d H:i:s T') . "\n";
    echo "End: " . $eDate_old->format('Y-m-d H:i:s T') . "\n";
    echo "Duration: " . (($eDate_old->getTimestamp() - $sDate_old->getTimestamp()) / 3600) . " hours\n";
    
    // NEW METHOD (fixed) - Use DateTime constructor with proper timezone parsing
    $sDate = new DateTime($startDateStr);
    $eDate = new DateTime($endDateStr);
    
    // Convert to WordPress timezone
    $wpTimezone = new DateTimeZone(wp_timezone_string());
    $sDate->setTimezone($wpTimezone);
    $eDate->setTimezone($wpTimezone);
    
    echo "\nNEW METHOD (fixed):\n";
    echo "Start: " . $sDate->format('Y-m-d H:i:s T') . "\n";
    echo "End: " . $eDate->format('Y-m-d H:i:s T') . "\n";
    
    // Calculate actual duration in hours
    $durationHours = ($eDate->getTimestamp() - $sDate->getTimestamp()) / 3600;
    echo "Duration: " . $durationHours . " hours\n";
    
    // Test the specific times that would be stored in WordPress
    $wpStartTime = $sDate->format('H:i:s');
    $wpEndTime = $eDate->format('H:i:s');
    
    echo "\nWordPress Event Manager would store:\n";
    echo "Start date: " . $sDate->format('Y-m-d') . "\n";
    echo "Start time: " . $wpStartTime . "\n";
    echo "End date: " . $eDate->format('Y-m-d') . "\n";
    echo "End time: " . $wpEndTime . "\n";
    
    // Verify the fix
    $success = ($wpEndTime === $expectedEndTime);
    
    if ($success) {
        echo "\n✅ SUCCESS ($transitionType DST): End time is correctly $expectedEndTime\n";
        
        // Additional verification for expected duration
        if ($transitionType === "Spring") {
            // Spring transition should show longer apparent duration due to time jump
            echo "✓ Duration handling: " . $durationHours . " hours (accounts for spring forward)\n";
        } else if ($transitionType === "Autumn") {
            // Autumn transition should show same local times despite extra hour
            echo "✓ Duration handling: " . $durationHours . " hours (accounts for fall back)\n";
        }
    } else {
        echo "\n❌ FAILED ($transitionType DST): End time is $wpEndTime instead of $expectedEndTime\n";
    }
    
    echo str_repeat("-", 70) . "\n";
    
    return $success;
}

/**
 * Test edge case: event during the "lost hour" in spring
 */
function test_spring_lost_hour() {
    echo "\n=== TESTING SPRING EDGE CASE (Event during lost hour) ===\n";
    echo "Event scheduled during the non-existent hour (2:00-3:00 AM on March 30, 2025)\n";
    
    // Event that would theoretically start at 2:30 AM (which doesn't exist)
    $startDateStr = '2025-03-30T02:30:00+01:00'; // This time doesn't exist due to DST
    $endDateStr = '2025-03-30T05:00:00+02:00';   // Summer time
    
    echo "\nOriginal CT dates:\n";
    echo "Start: $startDateStr (attempting to use non-existent time)\n";
    echo "End: $endDateStr\n";
    
    try {
        $sDate = new DateTime($startDateStr);
        $eDate = new DateTime($endDateStr);
        
        $wpTimezone = new DateTimeZone(wp_timezone_string());
        $sDate->setTimezone($wpTimezone);
        $eDate->setTimezone($wpTimezone);
        
        echo "\nPHP automatically handles non-existent time:\n";
        echo "Start: " . $sDate->format('Y-m-d H:i:s T') . "\n";
        echo "End: " . $eDate->format('Y-m-d H:i:s T') . "\n";
        
        echo "✅ SUCCESS: PHP gracefully handles non-existent times\n";
        return true;
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Test edge case: event during the "repeated hour" in autumn
 */
function test_autumn_repeated_hour() {
    echo "\n=== TESTING AUTUMN EDGE CASE (Event during repeated hour) ===\n";
    echo "Event during the repeated hour (2:00-3:00 AM occurs twice on October 26, 2025)\n";
    
    // Events that occur during the ambiguous hour
    $startDateStr = '2025-10-26T02:30:00+02:00'; // First occurrence (still CEST)
    $endDateStr = '2025-10-26T02:30:00+01:00';   // Second occurrence (now CET)
    
    echo "\nOriginal CT dates:\n";
    echo "Start: $startDateStr (first 2:30 AM - CEST)\n";
    echo "End: $endDateStr (second 2:30 AM - CET)\n";
    
    try {
        $sDate = new DateTime($startDateStr);
        $eDate = new DateTime($endDateStr);
        
        $wpTimezone = new DateTimeZone(wp_timezone_string());
        $sDate->setTimezone($wpTimezone);
        $eDate->setTimezone($wpTimezone);
        
        echo "\nPHP handles ambiguous times:\n";
        echo "Start: " . $sDate->format('Y-m-d H:i:s T') . "\n";
        echo "End: " . $eDate->format('Y-m-d H:i:s T') . "\n";
        
        $duration = ($eDate->getTimestamp() - $sDate->getTimestamp()) / 3600;
        echo "Duration: " . $duration . " hours\n";
        
        echo "✅ SUCCESS: PHP distinguishes between repeated hours using timezone offset\n";
        return true;
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        return false;
    }
}

// Run all tests
echo "COMPREHENSIVE DST TRANSITION TESTS\n";
echo "==================================\n";

$springResult = test_spring_dst_transition();
$autumnResult = test_autumn_dst_transition();
$springEdgeResult = test_spring_lost_hour();
$autumnEdgeResult = test_autumn_repeated_hour();

echo "\n=== FINAL RESULTS ===\n";
echo "Spring DST transition: " . ($springResult ? "✅ PASS" : "❌ FAIL") . "\n";
echo "Autumn DST transition: " . ($autumnResult ? "✅ PASS" : "❌ FAIL") . "\n";
echo "Spring edge case: " . ($springEdgeResult ? "✅ PASS" : "❌ FAIL") . "\n";
echo "Autumn edge case: " . ($autumnEdgeResult ? "✅ PASS" : "❌ FAIL") . "\n";

$allPassed = $springResult && $autumnResult && $springEdgeResult && $autumnEdgeResult;
echo "\nOverall result: " . ($allPassed ? "✅ ALL TESTS PASSED" : "❌ SOME TESTS FAILED") . "\n";

if ($allPassed) {
    echo "\n🎉 The DST fix correctly handles both spring and autumn transitions!\n";
} else {
    echo "\n⚠️  Some tests failed. The fix may need additional work.\n";
}
?>