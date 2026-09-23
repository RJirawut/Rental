<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

requireApiLogin();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'check_availability':
        $roomId = intval($_GET['room_id'] ?? 0);
        $checkIn = $_GET['check_in'] ?? '';
        $checkOut = $_GET['check_out'] ?? '';
        $excludeId = intval($_GET['exclude_id'] ?? 0);
        
        if ($roomId && $checkIn && $checkOut) {
            $sql = "SELECT COUNT(*) as count FROM daily_tenants 
                    WHERE room_id = ? 
                    AND status = 'checked_in'
                    AND (
                        (check_in_date <= ? AND check_out_date >= ?) OR
                        (check_in_date <= ? AND check_out_date >= ?) OR
                        (check_in_date >= ? AND check_out_date <= ?)
                    )";
            
            $params = [$roomId, $checkIn, $checkIn, $checkOut, $checkOut, $checkIn, $checkOut];
            
            if ($excludeId) {
                $sql .= " AND id != ?";
                $params[] = $excludeId;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            echo json_encode(['available' => $result['count'] == 0]);
        } else {
            echo json_encode(['available' => false, 'error' => 'Missing parameters']);
        }
        break;
        
    case 'get_room_price':
        $roomId = intval($_GET['room_id'] ?? 0);
        $rateDate = normalizeDateFilterValue($_GET['date'] ?? date('Y-m-d'));
        
        if ($roomId && $rateDate !== '') {
            $stmt = $pdo->prepare("SELECT room_type_id FROM rooms WHERE id = ?");
            $stmt->execute([$roomId]);
            $result = $stmt->fetch();
            
            echo json_encode($result
                ? getRoomTypePriceForDate((int) $result['room_type_id'], $rateDate)
                : ['error' => 'Room not found']);
        } else {
            echo json_encode(['error' => 'Missing room_id']);
        }
        break;
        
    case 'search_rooms':
        $term = $_GET['term'] ?? '';
        
        if ($term) {
            $stmt = $pdo->prepare("SELECT r.*, rt.type_name, rt.price_daily FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id WHERE r.room_number LIKE ? AND r.status = 'available' LIMIT 10");
            $stmt->execute(["%$term%"]);
            $results = $stmt->fetchAll();
            
            echo json_encode($results);
        } else {
            echo json_encode([]);
        }
        break;
        
    case 'get_tenant_bills':
        $tenantId = intval($_GET['tenant_id'] ?? 0);
        
        if ($tenantId) {
            $stmt = $pdo->prepare("SELECT * FROM utility_bills WHERE tenant_id = ? ORDER BY bill_month DESC LIMIT 1");
            $stmt->execute([$tenantId]);
            $result = $stmt->fetch();
            
            echo json_encode($result);
        } else {
            echo json_encode(['error' => 'Missing tenant_id']);
        }
        break;
        
    default:
        echo json_encode(['error' => 'Unknown action']);
}
?>
