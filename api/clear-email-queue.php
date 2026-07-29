<?php
/**
 * API Endpoint for Clearing Email Queue
 * Deletes all emails from the email queue
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Check if it's an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

// Verify CSRF token
requireValidCsrfToken();

// Verify admin access
requireAdmin();

// Verify PIN if provided
$pin = trim($_POST['pin'] ?? '');
if (!empty($pin)) {
    $pinResult = verifySystemPin($pin);
    if (empty($pinResult['success'])) {
        echo json_encode([
            'success' => false,
            'error' => $pinResult['message'] ?? t('pin_incorrect'),
            'pin_error' => true,
            'logout' => !empty($pinResult['logout'])
        ]);
        exit;
    }
}

try {
    // Delete emails older than 90 days
    $cutoffDate = date('Y-m-d H:i:s', strtotime('-90 days'));

    // Count emails to be deleted
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM email_queue WHERE created_at < ?");
    $stmt->execute([$cutoffDate]);
    $countResult = $stmt->fetch();
    $emailsToDelete = $countResult['count'];

    if ($emailsToDelete > 0) {
        // Delete old emails
        $deleteStmt = $pdo->prepare("DELETE FROM email_queue WHERE created_at < ?");
        $deleteStmt->execute([$cutoffDate]);
        $deletedCount = $deleteStmt->rowCount();

        // Log the cleanup action
        logActivity('clear_email_queue', null, null, "Deleted {$deletedCount} emails older than 90 days from queue");

        echo json_encode([
            'success' => true,
            'message' => "Cleared {$deletedCount} emails older than 90 days",
            'deleted_count' => $deletedCount
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'No emails older than 90 days found',
            'deleted_count' => 0
        ]);
    }

} catch (PDOException $e) {
    error_log('Email queue clear failed: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error clearing email queue: ' . $e->getMessage()
    ]);
}
