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
ensureRoomTypePriceHistoryTable();

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
        // Build a new bill from the rate effective during the requested month.
        // Previously generated bills are handled above and keep their snapshot.
        $stmt = $pdo->prepare("
            SELECT mt.monthly_rent, mt.room_id, r.room_type_id,
                   rt.price_monthly AS room_type_price_monthly
            FROM monthly_tenants mt
            JOIN rooms r ON r.id = mt.room_id
            JOIN room_types rt ON rt.id = r.room_type_id
            WHERE mt.id = ?
        ");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch();
        
        if (!$tenant) {
            echo json_encode(['success' => false, 'message' => 'Tenant not found']);
            exit;
        }
        
        $rentAmount = calculateMonthlyTenantRentForMonth($tenant, $month);

        // Create a paid utility bill with rent only.
        $stmt = $pdo->prepare("
            INSERT INTO utility_bills (
                tenant_id, room_id, bill_month, bill_date, 
                rent_amount, water_amount, elec_amount, other_fees, discount, total_amount, 
                status, paid_date, created_by
            ) VALUES (?, ?, ?, CURDATE(), ?, 0, 0, 0, 0, ?, 'paid', CURDATE(), ?)
        ");
        $success = $stmt->execute([
            $tenantId,
            $tenant['room_id'],
            $month,
            $rentAmount,
            $rentAmount,
            getValidSessionUserId(),
        ]);
        $billId = (int) $pdo->lastInsertId();
        $amount = $rentAmount;
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
