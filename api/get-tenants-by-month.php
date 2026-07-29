<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

requireApiLogin();

$month = $_GET['month'] ?? '';
$response = ['success' => false, 'tenants' => []];

if (!empty($month)) {
    try {
        // Validate month format (YYYY-MM)
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new Exception('Invalid month format');
        }
        
        // Get all active tenants without existing bill for selected month
        $stmt = $pdo->prepare("SELECT mt.*, r.room_number, rt.price_monthly 
            FROM monthly_tenants mt 
            JOIN rooms r ON mt.room_id = r.id 
            JOIN room_types rt ON r.room_type_id = rt.id 
            LEFT JOIN utility_bills ub ON mt.id = ub.tenant_id AND ub.bill_month = ?
            WHERE mt.status = 'active' 
            AND ub.id IS NULL
            ORDER BY r.room_number");
        $stmt->execute([$month]);
        
        $tenants = $stmt->fetchAll();
        
        $response['success'] = true;
        $response['tenants'] = $tenants;
        
    } catch (Exception $e) {
        error_log('get-tenants-by-month API error: ' . $e->getMessage());
        http_response_code(400);
        $response['error'] = 'Invalid request';
    }
}

echo json_encode($response);
?>
