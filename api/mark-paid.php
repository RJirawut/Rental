<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireApiLogin();
requireValidCsrfToken();

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['tenant_id']) || !isset($input['month'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$tenantId = intval($input['tenant_id']);
$month = $input['month'];

if ($tenantId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    // $pdo is already available from database.php
    
    // Check if utility bill exists for this tenant and month
    $stmt = $pdo->prepare("SELECT id, total_amount FROM utility_bills WHERE tenant_id = ? AND bill_month = ?");
    $stmt->execute([$tenantId, $month]);
    $bill = $stmt->fetch();
    
    if ($bill) {
        // Update existing bill to paid
        $stmt = $pdo->prepare("UPDATE utility_bills SET status = 'paid', paid_date = CURDATE() WHERE id = ?");
        $success = $stmt->execute([$bill['id']]);
        $billId = (int) $bill['id'];
        $amount = $bill['total_amount'];
    } else {
        // Get tenant info to create bill
        $stmt = $pdo->prepare("SELECT monthly_rent FROM monthly_tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch();
        
        if (!$tenant) {
            echo json_encode(['success' => false, 'message' => 'Tenant not found']);
            exit;
        }
        
        // Create new utility bill with rent only
        $stmt = $pdo->prepare("
            INSERT INTO utility_bills (
                tenant_id, room_id, bill_month, bill_date, 
                rent_amount, water_amount, elec_amount, other_fees, discount, total_amount, 
                status, paid_date, created_by
            ) 
            SELECT mt.id, mt.room_id, ?, CURDATE(), mt.monthly_rent, 0, 0, 0, 0, mt.monthly_rent, 'paid', CURDATE(), ?
            FROM monthly_tenants mt WHERE mt.id = ?
        ");
        $success = $stmt->execute([$month, $_SESSION['user_id'], $tenantId]);
        $billId = (int) $pdo->lastInsertId();
        $amount = $tenant['monthly_rent'];
    }
    
    if ($success) {
        logActivity('mark_paid', 'utility_bill', $billId, "Marked as paid for {$month}");
        logActivity('mark_monthly_paid', 'monthly_tenant', $tenantId, "Marked as paid for {$month}");
        sendMonthlyReceiptEmail($tenantId, $month, 'paid');

        echo json_encode([
            'success' => true, 
            'message' => 'Payment recorded successfully',
            'amount' => $amount,
            'tenant_id' => $tenantId,
            'month' => $month
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update payment status']);
    }
    
} catch (Exception $e) {
    error_log("Error in mark-paid.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>
