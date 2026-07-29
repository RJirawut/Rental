<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

requireApiLogin();

$tenantId = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : 0;
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

if (!$tenantId) {
    echo json_encode(['success' => false, 'error' => 'Tenant ID required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT water_curr_reading, elec_curr_reading, bill_month
        FROM utility_bills 
        WHERE tenant_id = ? AND bill_month < ?
        ORDER BY bill_month DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $month]);
    $lastBill = $stmt->fetch();
    
    if ($lastBill) {
        echo json_encode([
            'success' => true,
            'last_readings' => [
                'water_curr_reading' => $lastBill['water_curr_reading'],
                'elec_curr_reading' => $lastBill['elec_curr_reading'],
                'bill_month' => $lastBill['bill_month']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'last_readings' => null
        ]);
    }
} catch (Exception $e) {
    error_log('get-last-meter API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
