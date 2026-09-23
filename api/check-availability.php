<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$checkIn = $_GET['check_in'] ?? '';
$checkOut = $_GET['check_out'] ?? '';
$excludeId = intval($_GET['exclude_id'] ?? 0);
$excludeType = $_GET['exclude_type'] ?? '';
if (!in_array($excludeType, ['daily', 'monthly'], true)) {
    $excludeType = '';
}

if (empty($checkIn) || empty($checkOut)) {
    echo json_encode(['error' => 'Missing dates']);
    exit;
}

$rateDate = normalizeDateFilterValue($checkIn);
if ($rateDate === '') {
    echo json_encode(['error' => 'Invalid dates']);
    exit;
}

ensureRoomTypePriceHistoryTable();

// Get all rooms with their availability status
// Logic: Room is occupied if there's an overlapping booking (all non-cancelled)
// Exception: Allow booking if new check-in equals existing check-out (back-to-back)
$sql = "SELECT r.*, rt.type_name, rt.price_daily, rt.price_monthly,
       CASE 
           WHEN EXISTS (
               SELECT 1 FROM daily_tenants dt 
               WHERE dt.room_id = r.id 
               AND (dt.status IS NULL OR dt.status NOT IN ('cancelled', 'checked_out', 'no_show'))
               AND dt.check_in_date < ? AND dt.check_out_date > ?
               AND (? != 'daily' OR dt.id != ?)
               AND NOT (? = dt.check_out_date)  -- Allow back-to-back booking
           ) OR EXISTS (
               SELECT 1 FROM monthly_tenants mt 
               WHERE mt.room_id = r.id 
               AND mt.status IN ('active', 'pending')
               AND mt.contract_start < ? AND mt.contract_end > ?
               AND (? != 'monthly' OR mt.id != ?)
           ) THEN 'occupied'
           ELSE 'available'
       END as availability
       FROM rooms r 
       JOIN room_types rt ON r.room_type_id = rt.id 
       WHERE r.status != 'maintenance'
       ORDER BY r.room_number";

$stmt = $pdo->prepare($sql);
$stmt->execute([$checkOut, $checkIn, $excludeType, $excludeId, $checkIn, $checkOut, $checkIn, $excludeType, $excludeId]);
$rooms = $stmt->fetchAll();

// The displayed rate must match the requested start date, not merely the
// latest room-type price. Cache per room type because many rooms share a rate.
$pricesByRoomType = [];
foreach ($rooms as &$room) {
    $roomTypeId = (int) $room['room_type_id'];
    if (!isset($pricesByRoomType[$roomTypeId])) {
        $pricesByRoomType[$roomTypeId] = getRoomTypePriceForDate($roomTypeId, $rateDate);
    }
    $room['price_daily'] = $pricesByRoomType[$roomTypeId]['price_daily'];
    $room['price_monthly'] = $pricesByRoomType[$roomTypeId]['price_monthly'];
}
unset($room);

echo json_encode(['rooms' => $rooms]);
?>
