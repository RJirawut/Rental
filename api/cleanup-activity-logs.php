<?php
/**
 * API Endpoint for Cleaning Up Activity Logs
 * Deletes activity logs older than 90 days
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

try {
    // Verify PIN if provided
    $pin = trim($_POST['pin'] ?? '');
    if (!empty($pin)) {
        $pinResult = verifySystemPin($pin);
        if (empty($pinResult['success'])) {
            echo json_encode([
                'success' => false,
                'error' => $pinResult['message'] ?? 'Incorrect PIN',
                'pin_error' => true,
                'logout' => !empty($pinResult['logout'])
            ]);
            exit;
        }
    }

    // Delete activity logs older than 90 days
    $cutoffDate = date('Y-m-d H:i:s', strtotime('-90 days'));
    
    // Count logs to be deleted
    $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM activity_logs WHERE created_at < ?");
    $countStmt->execute([$cutoffDate]);
    $countResult = $countStmt->fetch();
    $logsToDelete = $countResult['count'];
    
    if ($logsToDelete > 0) {
        // Delete the old logs
        $deleteStmt = $pdo->prepare("DELETE FROM activity_logs WHERE created_at < ?");
        $deleteStmt->execute([$cutoffDate]);
        $deletedCount = $deleteStmt->rowCount();
        
        // Optimize table after deletion
        $pdo->exec("OPTIMIZE TABLE activity_logs");
        
        // Log the cleanup action
        logActivity('cleanup_activity_logs', null, null, "Deleted {$deletedCount} activity logs older than 90 days");
        
        echo json_encode([
            'success' => true,
            'message' => sprintf(t('cleared_old_activity_logs_success'), $deletedCount),
            'deleted_count' => $deletedCount
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => t('no_old_activity_logs_found'),
            'deleted_count' => 0
        ]);
    }
    
} catch (PDOException $e) {
    error_log('Activity Logs cleanup failed: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => t('clear_cache_error')
    ]);
}
