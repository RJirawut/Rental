<?php
/**
 * Monthly Outstanding Balance Notification Script
 * This script should be scheduled to run on the 1st of each month
 * It sends email notifications to all monthly tenants with unpaid bills
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/receipt_email.php';

// Check if today is the 1st of the month
$today = date('d');
if ($today !== '01') {
    echo "Today is not the 1st of the month. Skipping monthly notifications.\n";
    exit(0);
}

echo "Running monthly outstanding balance notifications...\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";

$results = sendMonthlyOutstandingBalanceNotifications();

echo "Total unpaid bills found: {$results['processed']}\n";
echo "Notifications queued: {$results['sent']}\n";
echo "Notifications failed: {$results['failed']}\n";
echo "Execution time: {$results['execution_time']} seconds\n";

if (!empty($results['errors'])) {
    echo "Errors:\n";
    foreach ($results['errors'] as $error) {
        echo "  - {$error}\n";
    }
}

echo "Monthly notification process completed at: " . date('Y-m-d H:i:s') . "\n";
