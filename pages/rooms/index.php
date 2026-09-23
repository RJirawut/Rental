<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Load language after login to get correct session
require_once __DIR__ . '/../../assets/lang/language.php';

$pageTitle = t('rooms');
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$floorFilter = $_GET['floor'] ?? '';
$itemsPerPage = 50;
$requestedPage = max(1, (int)($_GET['page'] ?? 1));

syncRoomStatuses();

// Get all floors for pagination
$stmt = $pdo->query("SELECT DISTINCT floor FROM rooms ORDER BY floor");
$floors = $stmt->fetchAll(PDO::FETCH_COLUMN);

// If no floor selected, use the first floor
if (empty($floorFilter) && !empty($floors)) {
    $floorFilter = $floors[0];
}

// Build query
$sql = "SELECT r.id, r.room_number, r.room_type_id, r.floor, r.status, r.notes, r.created_at, r.updated_at,
        rt.type_name, rt.type_name_en, rt.price_daily, rt.price_monthly,
        (
            SELECT dt.guest_name
            FROM daily_tenants dt
            WHERE dt.room_id = r.id
            AND dt.check_in_date = CURDATE()
            AND dt.actual_check_in_date IS NULL
            AND (
                dt.status IS NULL
                OR dt.status = ''
                OR dt.status = 'checked_in'
            )
            AND (
                dt.actual_check_out_date IS NULL
                OR dt.actual_check_out_date = ''
            )
            ORDER BY dt.created_at DESC
            LIMIT 1
        ) as reserved_guest_name,
        (
            SELECT dt.phone
            FROM daily_tenants dt
            WHERE dt.room_id = r.id
            AND dt.check_in_date = CURDATE()
            AND dt.actual_check_in_date IS NULL
            AND (
                dt.status IS NULL
                OR dt.status = ''
                OR dt.status = 'checked_in'
            )
            AND (
                dt.actual_check_out_date IS NULL
                OR dt.actual_check_out_date = ''
            )
            ORDER BY dt.created_at DESC
            LIMIT 1
        ) as reserved_guest_phone,
        COALESCE(al_user.username, default_admin.username) AS creator_username
        FROM rooms r 
        JOIN room_types rt ON r.room_type_id = rt.id 
        LEFT JOIN (
            SELECT al.entity_id, u.username
            FROM activity_logs al
            INNER JOIN (
                SELECT entity_id, MAX(id) AS id
                FROM activity_logs
                WHERE entity_type IN ('room', 'rooms')
                GROUP BY entity_id
            ) latest_al ON latest_al.id = al.id
            LEFT JOIN users u ON al.user_id = u.id
        ) al_user ON al_user.entity_id = r.id
        LEFT JOIN (
            SELECT username FROM users WHERE is_active = 1 AND role = 'admin' ORDER BY id ASC LIMIT 1
        ) default_admin ON 1=1
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND r.room_number LIKE ?";
    $params[] = "%$search%";
}

if ($statusFilter) {
    $sql .= " AND r.status = ?";
    $params[] = $statusFilter;
}

if ($typeFilter) {
    $sql .= " AND r.room_type_id = ?";
    $params[] = $typeFilter;
}

if ($floorFilter) {
    $sql .= " AND r.floor = ?";
    $params[] = $floorFilter;
}

// Count the filtered result set separately so the status cards and pagination
// remain accurate without loading every matching room into memory.
$countSql = "SELECT r.status, COUNT(*) AS total FROM rooms r WHERE 1=1";
$countParams = [];

if ($search) {
    $countSql .= " AND r.room_number LIKE ?";
    $countParams[] = "%$search%";
}

if ($statusFilter) {
    $countSql .= " AND r.status = ?";
    $countParams[] = $statusFilter;
}

if ($typeFilter) {
    $countSql .= " AND r.room_type_id = ?";
    $countParams[] = $typeFilter;
}

if ($floorFilter) {
    $countSql .= " AND r.floor = ?";
    $countParams[] = $floorFilter;
}

$countSql .= " GROUP BY r.status";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$roomStatusCounts = [];
$totalRooms = 0;
foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $statusRow) {
    $roomStatusCounts[$statusRow['status']] = (int)$statusRow['total'];
    $totalRooms += (int)$statusRow['total'];
}

$totalPages = max(1, (int)ceil($totalRooms / $itemsPerPage));
$page = min($requestedPage, $totalPages);
$offset = ($page - 1) * $itemsPerPage;

$sql .= " ORDER BY r.room_number LIMIT {$itemsPerPage} OFFSET {$offset}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rooms = $stmt->fetchAll();

// Get room types for filter
$stmt = $pdo->query("SELECT id, type_name, type_name_en FROM room_types WHERE is_active = 1 ORDER BY type_name");
$roomTypes = $stmt->fetchAll();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireValidCsrfToken();
    $id = intval($_POST['id'] ?? 0);

    // Check if room is in use
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM daily_tenants WHERE room_id = ? AND status = 'checked_in'");
    $stmt->execute([$id]);
    $dailyCount = $stmt->fetch()['count'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM monthly_tenants WHERE room_id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $monthlyCount = $stmt->fetch()['count'];

    if ($dailyCount > 0 || $monthlyCount > 0) {
        setFlashMessage('error', t('cannot_delete_room_occupied'));
    } else {
        $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
        if ($stmt->execute([$id])) {
            logActivity('delete_room', 'room', $id);
            setFlashMessage('success', t('delete_success'));
        } else {
            setFlashMessage('error', t('delete_error'));
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . ($search ? '?search=' . urlencode($search) : ''));
    exit;
}

$availableCount = (int)($roomStatusCounts['available'] ?? 0);
$reservedCount = (int)($roomStatusCounts['reserved'] ?? 0);
$occupiedCount = (int)($roomStatusCounts['occupied'] ?? 0);
$maintenanceCount = (int)($roomStatusCounts['maintenance'] ?? 0);
$visibleRoomCount = $totalRooms;

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper app-page">
    <div class="card app-hero mb-4 border-0 shadow-sm">
        <div class="card-body p-4 p-lg-5">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h5 class="mb-1"><i class="bi bi-door-open me-2"></i><?php echo t('rooms'); ?></h5>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge text-bg-light border rounded-pill px-3 py-2 text-dark">
                        <?php echo t('all'); ?> <?php echo number_format($visibleRoomCount); ?>
                    </span>
                    <a href="form.php" class="btn btn-primary d-flex align-items-center">
                        <i class="bi bi-plus-circle me-3"></i><?php echo t('add_new'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="stat-cards-scroll mb-4">
        <div class="stat-card-item">
            <div class="card app-stat-card app-stat-available h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="app-stat-label"><?php echo t('available'); ?></div>
                            <div class="app-stat-value"><?php echo number_format($availableCount); ?></div>
                        </div>
                        <div class="app-stat-icon"><i class="bi bi-door-open"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card app-stat-card app-stat-occupied h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="app-stat-label"><?php echo t('occupied'); ?></div>
                            <div class="app-stat-value"><?php echo number_format($occupiedCount); ?></div>
                        </div>
                        <div class="app-stat-icon"><i class="bi bi-person-check"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card app-stat-card app-stat-reserved h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="app-stat-label"><?php echo t('reserved'); ?></div>
                            <div class="app-stat-value"><?php echo number_format($reservedCount); ?></div>
                        </div>
                        <div class="app-stat-icon"><i class="bi bi-calendar-check"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card app-stat-card app-stat-maintenance h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="app-stat-label"><?php echo t('maintenance'); ?></div>
                            <div class="app-stat-value"><?php echo number_format($maintenanceCount); ?></div>
                        </div>
                        <div class="app-stat-icon"><i class="bi bi-tools"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Search & Filter -->
    <div class="card app-toolbar-card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="<?php echo t('search_room_number'); ?>..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <input type="hidden" name="floor" value="<?php echo htmlspecialchars($floorFilter); ?>">
                <div class="col-12 col-md-6 col-lg-2">
                    <select name="status" class="form-select">
                        <option value=""><?php echo t('all_status'); ?></option>
                        <option value="available" <?php echo $statusFilter === 'available' ? 'selected' : ''; ?>><?php echo t('available'); ?></option>
                        <option value="reserved" <?php echo $statusFilter === 'reserved' ? 'selected' : ''; ?>><?php echo t('reserved'); ?></option>
                        <option value="occupied" <?php echo $statusFilter === 'occupied' ? 'selected' : ''; ?>><?php echo t('occupied'); ?></option>
                        <option value="maintenance" <?php echo $statusFilter === 'maintenance' ? 'selected' : ''; ?>><?php echo t('maintenance'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-lg-2">
                    <select name="type" class="form-select">
                        <option value=""><?php echo t('all_types'); ?></option>
                        <?php foreach ($roomTypes as $type): ?>
                        <option value="<?php echo $type['id']; ?>" <?php echo $typeFilter == $type['id'] ? 'selected' : ''; ?>>
                            <?php echo $lang === 'en' ? ($type['type_name_en'] ?? $type['type_name']) : $type['type_name']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-lg-2">
                    <button type="submit" class="btn btn-outline-primary w-100"><?php echo t('search'); ?></button>
                </div>
                <div class="col-12 col-md-6 col-lg-2">
                    <a href="?" class="btn btn-outline-secondary w-100"><?php echo t('clear'); ?></a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Floor Tabs -->
    <?php if (!empty($floors)): ?>
    <div class="mb-4">
        <ul class="nav nav-pills floor-tabs">
            <?php foreach ($floors as $floor): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $floorFilter == $floor ? 'active' : ''; ?>" 
                   href="?floor=<?php echo $floor; ?><?php echo $statusFilter ? '&status=' . $statusFilter : ''; ?><?php echo $typeFilter ? '&type=' . $typeFilter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                    <?php echo t('floor'); ?> <?php echo $floor; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="card app-table-card mb-4">
        <div class="card-body">
            <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 80px;" class="text-center"><?php echo t('room_number'); ?></th>
                    <th class="text-center"><?php echo t('type'); ?></th>
                    <th style="width: 120px;" class="text-center"><?php echo t('daily'); ?></th>
                    <th style="width: 120px;" class="text-center"><?php echo t('monthly'); ?></th>
                    <th style="width: 120px;" class="text-center"><?php echo t('status'); ?></th>
                    <th style="width: 100px;" class="text-center"><?php echo t('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rooms as $room): ?>
                <tr>
                    <td class="text-center"><strong><?php echo $room['room_number']; ?></strong></td>
                    <td class="text-center"><?php echo $lang === 'en' ? ($room['type_name_en'] ?? $room['type_name']) : $room['type_name']; ?></td>
                    <td class="text-center"><?php echo formatCurrency($room['price_daily']); ?></td>
                    <td class="text-center"><?php echo formatCurrency($room['price_monthly']); ?></td>
                    <td class="text-center">
                        <?php echo getRoomStatusBadge($room['status']); ?>
                        <?php if (!empty($room['creator_username'])): ?>
                        <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                            <?php echo t('by'); ?>: <?php echo htmlspecialchars($room['creator_username']); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <div class="app-actions">
                            <a href="view.php?id=<?php echo $room['id']; ?>" class="btn btn-sm btn-primary" title="<?php echo t('view'); ?>">
                                <i class="bi bi-search"></i>
                            </a>
                            <a href="form.php?id=<?php echo $room['id']; ?>" class="btn btn-sm btn-warning" title="<?php echo t('edit'); ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_delete'); ?>')">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $room['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="<?php echo t('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rooms)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-door-open fs-1"></i>
                        <p class="mt-3 mb-0"><?php echo t('no_rooms_found'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <?php
        $paginationParams = [];
        if ($search !== '') $paginationParams['search'] = $search;
        if ($statusFilter !== '') $paginationParams['status'] = $statusFilter;
        if ($typeFilter !== '') $paginationParams['type'] = $typeFilter;
        if ($floorFilter !== '') $paginationParams['floor'] = $floorFilter;
        ?>
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <div class="text-muted">
                <?php echo t('showing'); ?> <?php echo (($page - 1) * $itemsPerPage) + 1; ?> - <?php echo min($page * $itemsPerPage, $totalRooms); ?> <?php echo t('of'); ?> <?php echo number_format($totalRooms); ?> <?php echo t('records'); ?>
            </div>
            <nav aria-label="Page navigation">
                <ul class="pagination mb-0">
                    <?php $paginationParams['page'] = max(1, $page - 1); ?>
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query($paginationParams); ?>"><?php echo t('previous'); ?></a>
                    </li>
                    <?php for ($paginationPage = max(1, $page - 1); $paginationPage <= min($totalPages, $page + 1); $paginationPage++): ?>
                        <?php $paginationParams['page'] = $paginationPage; ?>
                        <li class="page-item <?php echo $paginationPage === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query($paginationParams); ?>"><?php echo $paginationPage; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php $paginationParams['page'] = min($totalPages, $page + 1); ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query($paginationParams); ?>"><?php echo t('next'); ?></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
