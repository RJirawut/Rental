<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

requireApiLogin();

$month = $_GET['month'] ?? '';
$search = trim($_GET['search'] ?? '');
$search = mb_substr($search, 0, 100);
$response = ['success' => false, 'tenants' => []];

if (!empty($month)) {
    try {
        // Validate month format (YYYY-MM)
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new Exception('Invalid month format');
        }
        
        // Return only a small result set; the form requests more rows as the user searches.
        $whereSql = "mt.status = 'active' AND ub.id IS NULL";
        $params = [$month];
        if ($search !== '') {
            $whereSql .= " AND (mt.tenant_name LIKE ? OR mt.phone LIKE ? OR r.room_number LIKE ?)";
            $searchValue = "%{$search}%";
            $params[] = $searchValue;
            $params[] = $searchValue;
            $params[] = $searchValue;
        }

        $stmt = $pdo->prepare("SELECT mt.id, mt.tenant_name, mt.phone, mt.contract_start, mt.monthly_rent,
                r.room_number, rt.price_monthly
            FROM monthly_tenants mt 
            JOIN rooms r ON mt.room_id = r.id 
            JOIN room_types rt ON r.room_type_id = rt.id 
            LEFT JOIN utility_bills ub ON mt.id = ub.tenant_id AND ub.bill_month = ?
            WHERE {$whereSql}
            ORDER BY r.room_number, mt.tenant_name
            LIMIT 50");
        $stmt->execute($params);
        
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
