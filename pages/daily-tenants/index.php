<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

ensureDailyTenantCancelRefundColumn();
ensureDailyTenantPaymentDeadlineColumn();
ensureDailyTenantPendingPaymentStatus();
ensurePaymentConfirmationsTable();

// Auto-delete expired pending payments
cleanupExpiredPendingPayments();

$pageTitle = t('daily_tenants');

$lang = $_SESSION['lang'] ?? 'th';

// Search and filter
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = isset($_GET['date_from_year'], $_GET['date_from_month'], $_GET['date_from_day'])
    ? buildDateFilterValue($_GET['date_from_year'], $_GET['date_from_month'], $_GET['date_from_day'])
    : normalizeDateFilterValue($_GET['date_from'] ?? '');
$dateTo = isset($_GET['date_to_year'], $_GET['date_to_month'], $_GET['date_to_day'])
    ? buildDateFilterValue($_GET['date_to_year'], $_GET['date_to_month'], $_GET['date_to_day'])
    : normalizeDateFilterValue($_GET['date_to'] ?? '');
$localizedMonthNames = localizedMonthNames($lang);
$dateFilterYears = range((int) date('Y') + 2, (int) date('Y') - 5);
foreach ([$dateFrom, $dateTo] as $selectedDate) {
    if ($selectedDate) {
        $selectedYear = (int) substr($selectedDate, 0, 4);
        if (!in_array($selectedYear, $dateFilterYears, true)) {
            $dateFilterYears[] = $selectedYear;
        }
    }
}
rsort($dateFilterYears);

if (!function_exists('renderDailyTenantDateFilter')) {
    function renderDailyTenantDateFilter($name, $value, $monthNames, $yearOptions, $lang) {
        $value = normalizeDateFilterValue($value);
        $parts = ['year' => '', 'month' => '', 'day' => ''];
        if ($value !== '') {
            $parts = [
                'year' => (int) substr($value, 0, 4),
                'month' => (int) substr($value, 5, 2),
                'day' => (int) substr($value, 8, 2),
            ];
        }

        $labels = $lang === 'en'
            ? ['day' => 'Day', 'month' => 'Month', 'year' => 'Year']
            : ['day' => 'วัน', 'month' => 'เดือน', 'year' => 'ปี'];
        ?>
        <div class="row g-1">
            <div class="col-3">
                <select name="<?php echo $name; ?>_day" class="form-select">
                    <option value=""><?php echo $labels['day']; ?></option>
                    <?php for ($d = 1; $d <= 31; $d++): ?>
                    <option value="<?php echo $d; ?>" <?php echo (int) $parts['day'] === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-5">
                <select name="<?php echo $name; ?>_month" class="form-select">
                    <option value=""><?php echo $labels['month']; ?></option>
                    <?php foreach ($monthNames as $index => $monthName): ?>
                    <option value="<?php echo $index + 1; ?>" <?php echo (int) $parts['month'] === $index + 1 ? 'selected' : ''; ?>><?php echo htmlspecialchars($monthName); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4">
                <select name="<?php echo $name; ?>_year" class="form-select">
                    <option value=""><?php echo $labels['year']; ?></option>
                    <?php foreach ($yearOptions as $yearOption): ?>
                    <option value="<?php echo $yearOption; ?>" <?php echo (int) $parts['year'] === (int) $yearOption ? 'selected' : ''; ?>><?php echo $yearOption; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php
    }
}

// Pagination settings
$itemsPerPage = 20;
$pageParam = isset($_GET['page']) ? intval($_GET['page']) : null;

// Sorting settings
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'DESC';
$validSortColumns = ['room_number', 'check_in_date', 'check_out_date', 'guest_name', 'phone', 'total_days', 'total_amount', 'status', 'created_at'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'created_at';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Helper function to build URL with sort parameters
function buildSortUrl($column, $currentSortBy, $currentSortOrder, $search, $statusFilter, $dateFrom, $dateTo) {
    $params = [];
    if ($column === $currentSortBy) {
        // Toggle sort order if clicking the same column
        $newSortOrder = $currentSortOrder === 'ASC' ? 'DESC' : 'ASC';
    } else {
        // Default to DESC for new column
        $newSortOrder = 'DESC';
    }
    $params['sort_by'] = $column;
    $params['sort_order'] = $newSortOrder;
    
    if ($search) $params['search'] = $search;
    if ($statusFilter) $params['status'] = $statusFilter;
    if ($dateFrom) $params['date_from'] = $dateFrom;
    if ($dateTo) $params['date_to'] = $dateTo;
    return '?' . http_build_query($params);
}

// Helper function to get sort icon
function getSortIcon($column, $currentSortBy, $currentSortOrder) {
    if ($column !== $currentSortBy) {
        return '<i class="bi bi-arrow-down-up text-muted small ms-1"></i>';
    }
    return $currentSortOrder === 'ASC' 
        ? '<i class="bi bi-arrow-up text-primary small ms-1"></i>' 
        : '<i class="bi bi-arrow-down text-primary small ms-1"></i>';
}

// Build query
$sql = "SELECT dt.*, r.room_number, rt.type_name, rt.type_name_en, rt.price_daily,
               dt_user.username AS dt_created_by_name
        FROM daily_tenants dt 
        JOIN rooms r ON dt.room_id = r.id 
        JOIN room_types rt ON r.room_type_id = rt.id 
        LEFT JOIN users dt_user ON dt.created_by = dt_user.id
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (dt.guest_name LIKE ? OR dt.phone LIKE ? OR r.room_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($statusFilter) {
    $today = date('Y-m-d');
    switch ($statusFilter) {
        case 'reserved':
            $sql .= " AND (
                (dt.check_in_date > ? AND (dt.status IS NULL OR dt.status = ''))
                OR
                (dt.check_in_date > ? AND dt.status = 'checked_in' AND (dt.actual_check_in_date IS NULL OR dt.actual_check_in_date = ''))
            )";
            $params[] = $today;
            $params[] = $today;
            break;
        case 'staying':
            $sql .= " AND dt.status = 'checked_in' AND ? BETWEEN dt.check_in_date AND dt.check_out_date";
            $params[] = $today;
            break;
        case 'checkin_today':
            $sql .= " AND dt.check_in_date = ? AND dt.status = 'checked_in'";
            $params[] = $today;
            break;
        case 'past_checkin':
            $sql .= " AND dt.check_in_date < ? AND dt.status = 'checked_in' AND (dt.actual_check_in_date IS NULL OR dt.actual_check_in_date = '')";
            $params[] = $today;
            break;
        case 'checkout_today':
            $sql .= " AND dt.check_out_date = ? AND dt.status = 'checked_in'";
            $params[] = $today;
            break;
        case 'overdue_checkout':
            $sql .= " AND dt.check_out_date < ? AND dt.status = 'checked_in'";
            $params[] = $today;
            break;
        case 'checked_out':
            $sql .= " AND dt.status = 'checked_out'";
            break;
        case 'cancelled':
            $sql .= " AND dt.status = 'cancelled'";
            break;
    }
}

if ($dateFrom) {
    $sql .= " AND dt.check_in_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND dt.check_out_date <= ?";
    $params[] = $dateTo;
}

// Apply sorting
$sortColumnMap = [
    'room_number' => 'r.room_number',
    'check_in_date' => 'dt.check_in_date',
    'check_out_date' => 'dt.check_out_date',
    'guest_name' => 'dt.guest_name',
    'phone' => 'dt.phone',
    'total_days' => 'dt.total_days',
    'total_amount' => 'dt.total_amount',
    'status' => 'dt.status',
    'created_at' => 'dt.created_at'
];
$sql .= " ORDER BY " . $sortColumnMap[$sortBy] . " " . $sortOrder;

// Count total records for pagination
$countSql = preg_replace('/^SELECT.*?FROM daily_tenants dt/s', 'SELECT COUNT(*) as total FROM daily_tenants dt', $sql, 1);
$countSql = preg_replace('/ORDER BY.*$/', '', $countSql);
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalRecords = $stmt->fetch()['total'];
$totalPages = ceil($totalRecords / $itemsPerPage);

// Determine page - default to first page if not specified
if ($pageParam === null) {
    // No page specified, show first page
    $page = 1;
} else {
    // Page specified, use it
    $page = max(1, min($pageParam, $totalPages));
}

$offset = ($page - 1) * $itemsPerPage;

// Add pagination limit
$sql .= " LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($sql);
// Bind limit and offset as integers
$paramIndex = 1;
foreach ($params as $param) {
    $stmt->bindValue($paramIndex++, $param);
}
$stmt->bindValue($paramIndex++, $itemsPerPage, PDO::PARAM_INT);
$stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
$stmt->execute();
$tenants = $stmt->fetchAll();

// Handle cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    requireValidCsrfToken();
    $id = intval($_POST['id'] ?? 0);
    $refund = ($_POST['refund'] ?? '') === '1' ? 1 : 0;

    $stmt = $pdo->prepare("SELECT room_id FROM daily_tenants WHERE id = ?");
    $stmt->execute([$id]);
    $roomRow = $stmt->fetch();
    $roomId = (int) ($roomRow['room_id'] ?? 0);
    
    $stmt = $pdo->prepare("UPDATE daily_tenants SET status = 'cancelled', cancel_refunded = ? WHERE id = ?");
    if ($stmt->execute([$refund, $id])) {
        if ($roomId > 0) {
            updateRoomStatus($roomId);
        }
        setFlashMessage('success', $refund ? t('cancel_success_refunded') : t('cancel_success_no_refund'));
        logActivity('cancel_daily_tenant', 'daily_tenant', $id, $refund ? 'refunded' : 'no_refund');
    } else {
        setFlashMessage('danger', t('cancel_error'));
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . ($search ? '?search=' . urlencode($search) : ''));
    exit;
}

// Handle check-in
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin') {
    requireValidCsrfToken();
    $id = intval($_POST['id'] ?? 0);
    $today = date('Y-m-d');
    
    // Get tenant details being checked in
    $stmt = $pdo->prepare("SELECT room_id, check_in_date, check_out_date FROM daily_tenants WHERE id = ?");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();
    
    if ($tenant) {
        $roomId = $tenant['room_id'];
        $newCheckIn = $tenant['check_in_date'];
        $newCheckOut = $tenant['check_out_date'];
        
        // Check if someone else is currently checked in to the same room with overlapping dates
        // Overlap formula: existing.check_in <= new.check_out AND existing.check_out >= new.check_in
        $sql = "SELECT id, guest_name, check_out_date, actual_check_out_date 
            FROM daily_tenants 
            WHERE room_id = ? 
            AND status = 'checked_in' 
            AND id != ?
            AND check_in_date <= ?
            AND check_out_date >= ?
            AND (actual_check_out_date IS NULL OR actual_check_out_date = '')";
        $params = [$roomId, $id, $newCheckOut, $newCheckIn];
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $existingTenant = $stmt->fetch();
        
        if ($existingTenant) {
            setFlashMessage('danger', t('room_occupied_by_other') . ': ' . $existingTenant['guest_name']);
            header('Location: index.php');
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE daily_tenants SET status = 'checked_in', actual_check_in_date = ? WHERE id = ?");
        $stmt->execute([$today, $id]);
        updateRoomStatus($roomId);
        
        sendDailyCheckInNotificationEmail($id);
        
        logActivity('checkin_daily_tenant', 'daily_tenant', $id);
        setFlashMessage('success', t('checkin_success'));
        header('Location: index.php');
        exit;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle actual checkin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'actual_checkin') {
    requireValidCsrfToken();
    $id = intval($_POST['id'] ?? 0);
    
    $stmt = $pdo->prepare("UPDATE daily_tenants SET actual_check_in_date = NOW() WHERE id = ? AND status = 'checked_in' AND actual_check_in_date IS NULL");
    if ($stmt->execute([$id])) {
        setFlashMessage('success', t('actual_checkin_success'));
        logActivity('actual_checkin_daily_tenant', 'daily_tenant', $id);
    } else {
        setFlashMessage('danger', t('actual_checkin_error'));
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    requireValidCsrfToken();
    $id = intval($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT room_id FROM daily_tenants WHERE id = ?");
    $stmt->execute([$id]);
    $roomRow = $stmt->fetch();
    $roomId = (int) ($roomRow['room_id'] ?? 0);
    
    $stmt = $pdo->prepare("UPDATE daily_tenants SET status = 'checked_out', actual_check_out_date = CURDATE() WHERE id = ?");
    if ($stmt->execute([$id])) {
        if ($roomId > 0) {
            updateRoomStatus($roomId);
        }
        
        sendDailyReceiptEmail($id, 'checkout');
        setFlashMessage('success', t('checkout_success'));
        logActivity('checkout_daily_tenant', 'daily_tenant', $id);
    } else {
        setFlashMessage('danger', t('checkout_error'));
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payment') {
    requireValidCsrfToken();
    $id = intval($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT room_id, status FROM daily_tenants WHERE id = ?");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();

    if (!$tenant) {
        setFlashMessage('danger', t('not_found'));
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($tenant['status'] !== 'pending_payment') {
        setFlashMessage('danger', t('payment_already_processed'));
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $roomId = (int) $tenant['room_id'];
    
    $stmt = $pdo->prepare("UPDATE daily_tenants SET status = NULL, payment_deadline = NULL WHERE id = ?");
    if ($stmt->execute([$id])) {
        updateRoomStatus($roomId);
        sendDailyReceiptEmail($id, 'booking');
        setFlashMessage('success', t('payment_success'));
        logActivity('payment_daily_tenant', 'daily_tenant', $id);
    } else {
        setFlashMessage('danger', t('payment_error'));
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Re-send the single-payment link email for a pending booking.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_payment_link') {
    requireValidCsrfToken();
    $id = (int) ($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT status, email FROM daily_tenants WHERE id = ?");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();

    if (!$tenant || $tenant['status'] !== 'pending_payment' || empty($tenant['email'])) {
        setFlashMessage('danger', $lang === 'en' ? 'This payment link cannot be sent.' : 'ไม่สามารถส่งลิงก์ชำระเงินสำหรับรายการนี้ได้');
    } elseif (sendDailyPendingPaymentEmail($id)) {
        setFlashMessage('success', $lang === 'en' ? 'Payment link has been sent.' : 'ส่งลิงก์ชำระเงินใหม่แล้ว');
    } else {
        setFlashMessage('danger', $lang === 'en' ? 'Unable to send payment link.' : 'ไม่สามารถส่งลิงก์ชำระเงินได้');
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h5 class="mb-0 me-3"><i class="bi bi-calendar-day me-2"></i><?php echo t('daily_tenants'); ?></h5>
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto">
            <span class="badge text-bg-light border rounded-pill px-3 py-2 text-dark">
                <?php echo t('all'); ?> <?php echo number_format($totalRecords); ?>
            </span>
            <a href="form.php" class="btn btn-primary flex-shrink-0 d-flex align-items-center">
                <i class="bi bi-plus-circle me-3"></i><?php echo t('add_guest'); ?>
            </a>
        </div>
    </div>
    
    <!-- Search & Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="<?php echo t('search_name_phone_room'); ?>..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <select name="status" class="form-select">
                        <option value=""><?php echo t('all_status'); ?></option>
                        <option value="reserved" <?php echo $statusFilter === 'reserved' ? 'selected' : ''; ?>><?php echo t('reserved'); ?></option>
                        <option value="staying" <?php echo $statusFilter === 'staying' ? 'selected' : ''; ?>><?php echo t('staying'); ?></option>
                        <option value="checkin_today" <?php echo $statusFilter === 'checkin_today' ? 'selected' : ''; ?>><?php echo t('check_in_today'); ?></option>
                        <option value="past_checkin" <?php echo $statusFilter === 'past_checkin' ? 'selected' : ''; ?>><?php echo t('past_checkin_date'); ?></option>
                        <option value="checkout_today" <?php echo $statusFilter === 'checkout_today' ? 'selected' : ''; ?>><?php echo t('checkout_today'); ?></option>
                        <option value="overdue_checkout" <?php echo $statusFilter === 'overdue_checkout' ? 'selected' : ''; ?>><?php echo t('overdue_checkout'); ?></option>
                        <option value="checked_out" <?php echo $statusFilter === 'checked_out' ? 'selected' : ''; ?>><?php echo t('checked_out'); ?></option>
                        <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>><?php echo t('cancelled'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label"><?php echo t('from_date'); ?></label>
                    <?php renderDailyTenantDateFilter('date_from', $dateFrom, $localizedMonthNames, $dateFilterYears, $lang); ?>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label"><?php echo t('to_date'); ?></label>
                    <?php renderDailyTenantDateFilter('date_to', $dateTo, $localizedMonthNames, $dateFilterYears, $lang); ?>
                </div>
                <div class="col-12 col-md-6 col-xl-1">
                    <button type="submit" class="btn btn-outline-primary w-100"><?php echo t('search'); ?></button>
                </div>
            </form>
        </div>
    </div>
    
    <style>
    .btn-group form.d-inline {
        display: inline-flex;
        vertical-align: top;
    }
    .btn-group form.d-inline .btn {
        margin-left: -1px;
        border-radius: 0;
    }
    .btn-group > .btn:first-child,
    .btn-group > form.d-inline:first-child .btn {
        margin-left: 0;
        border-top-left-radius: 0.25rem;
        border-bottom-left-radius: 0.25rem;
    }
    .btn-group > .btn:last-child,
    .btn-group > form.d-inline:last-child .btn {
        border-top-right-radius: 0.25rem;
        border-bottom-right-radius: 0.25rem;
    }
    </style>
    
    <div class="table-responsive">
        <div class="mb-2 small text-danger">
            หมายเหตุ: อักษรสีแดง = <?php echo t('refund_yes'); ?>
        </div>
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th style="text-align: center;">
                        <a href="<?php echo buildSortUrl('room_number', $sortBy, $sortOrder, $search, $statusFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('room'); ?><?php echo getSortIcon('room_number', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('check_in_date', $sortBy, $sortOrder, $search, $statusFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('booking_date'); ?><?php echo getSortIcon('check_in_date', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('guest_name', $sortBy, $sortOrder, $search, $statusFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('guest_name'); ?><?php echo getSortIcon('guest_name', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('phone', $sortBy, $sortOrder, $search, $statusFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('phone'); ?><?php echo getSortIcon('phone', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th style="text-align: center;">
                        <a href="<?php echo buildSortUrl('check_in_date', $sortBy, $sortOrder, $search, $statusFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('check_in'); ?><?php echo getSortIcon('check_in_date', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th style="text-align: center;">
                        <a href="<?php echo buildSortUrl('check_out_date', $sortBy, $sortOrder, $search, $statusFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('check_out'); ?><?php echo getSortIcon('check_out_date', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('total_days', $sortBy, $sortOrder, $search, $statusFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('total_days'); ?><?php echo getSortIcon('total_days', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('total_amount', $sortBy, $sortOrder, $search, $statusFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('total_amount'); ?><?php echo getSortIcon('total_amount', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th style="text-align: center;">
                        <a href="<?php echo buildSortUrl('status', $sortBy, $sortOrder, $search, $statusFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('status'); ?><?php echo getSortIcon('status', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="180" style="white-space: nowrap; text-align: center;"><?php echo t('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $tenant): ?>
                <?php
                $rowTotal = (float) $tenant['total_amount'];
                $displayDays = (int) $tenant['total_days'];
                $isCancelledRefunded = $tenant['status'] === 'cancelled' && !empty($tenant['cancel_refunded']);

                if ($isCancelledRefunded) {
                    $rowTotal = 0;
                }

                $actualStartDate = !empty($tenant['actual_check_in_date']) ? $tenant['actual_check_in_date'] : $tenant['check_in_date'];
                $actualEndDate = $tenant['check_out_date'];

                if (!empty($tenant['actual_check_out_date']) && $tenant['actual_check_out_date'] > $tenant['check_out_date']) {
                    $actualEndDate = $tenant['actual_check_out_date'];
                } elseif (empty($tenant['actual_check_out_date']) && $tenant['status'] === 'checked_in' && date('Y-m-d') > $tenant['check_out_date']) {
                    $actualEndDate = date('Y-m-d');
                }

                if (!empty($actualStartDate) && !empty($actualEndDate)) {
                    $displayDays = max(1, (new DateTime($actualStartDate))->diff(new DateTime($actualEndDate))->days);
                }

                // Early check-in charge
                if (!$isCancelledRefunded && !empty($tenant['actual_check_in_date']) && $tenant['actual_check_in_date'] < $tenant['check_in_date']) {
                    $actualIn = new DateTime($tenant['actual_check_in_date']);
                    $scheduledIn = new DateTime($tenant['check_in_date']);
                    $rowTotal += $actualIn->diff($scheduledIn)->days * (float) $tenant['daily_rate'];
                }
                // Late checkout charge
                if (!$isCancelledRefunded && !empty($tenant['actual_check_out_date']) && $tenant['actual_check_out_date'] > $tenant['check_out_date']) {
                    $expected = new DateTime($tenant['check_out_date']);
                    $actual = new DateTime($tenant['actual_check_out_date']);
                    $rowTotal += $expected->diff($actual)->days * (float) $tenant['daily_rate'];
                } elseif (!$isCancelledRefunded && empty($tenant['actual_check_out_date']) && $tenant['status'] === 'checked_in' && date('Y-m-d') > $tenant['check_out_date']) {
                    $expected = new DateTime($tenant['check_out_date']);
                    $today = new DateTime();
                    $rowTotal += $expected->diff($today)->days * (float) $tenant['daily_rate'];
                }
                ?>
                <tr>
                    <td style="white-space: nowrap; text-align: center;"><?php echo $tenant['room_number']; ?></td>
                    <td style="text-align: center;"><?php echo formatDate($tenant['created_at']); ?></td>
                    <td style="white-space: nowrap; text-align: center;"><?php echo htmlspecialchars($tenant['guest_name']); ?></td>
                    <td style="white-space: nowrap; text-align: center;"><?php echo $tenant['phone']; ?></td>
                    <td style="text-align: center;"><?php echo formatDate($tenant['check_in_date']); ?></td>
                    <td style="text-align: center;"><?php echo formatDate($tenant['check_out_date']); ?></td>
                    <td style="white-space: nowrap; text-align: center;"><?php echo $displayDays; ?> <?php echo t('nights'); ?></td>
                    <td style="white-space: nowrap; text-align: center;">
                        <?php if ($isCancelledRefunded): ?>
                        <span class="text-danger"><?php echo formatCurrency((float) $tenant['total_amount']); ?></span>
                        <?php else: ?>
                        <?php echo formatCurrency($rowTotal); ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php if ($tenant['status'] === 'checked_out'): ?>
                        <span class="badge bg-secondary"><?php echo t('checked_out'); ?></span>
                        <?php elseif ($tenant['status'] === 'cancelled'): ?>
                        <span class="badge bg-dark"><?php echo t('cancelled'); ?></span>
                        <?php elseif ($tenant['status'] === 'pending_payment'): ?>
                        <span class="badge bg-warning"><?php echo t('pending_payment'); ?></span>
                        <?php elseif (empty($tenant['status'])): ?>
                            <?php if ($tenant['check_in_date'] == date('Y-m-d')): ?>
                            <span class="badge bg-warning"><?php echo t('check_in_today'); ?></span>
                            <?php elseif ($tenant['check_in_date'] > date('Y-m-d')): ?>
                            <span class="badge bg-info"><?php echo t('reserved'); ?></span>
                            <?php else: ?>
                            <span class="badge bg-danger"><?php echo t('past_checkin_date'); ?></span>
                            <?php endif; ?>
                        <?php elseif ($tenant['status'] === 'checked_in' && empty($tenant['actual_check_in_date']) && $tenant['check_in_date'] == date('Y-m-d')): ?>
                        <span class="badge bg-warning"><?php echo t('check_in_today'); ?></span>
                        <?php elseif ($tenant['status'] === 'checked_in' && empty($tenant['actual_check_in_date']) && $tenant['check_in_date'] > date('Y-m-d')): ?>
                        <span class="badge bg-info"><?php echo t('upcoming'); ?></span>
                        <?php elseif ($tenant['status'] === 'checked_in' && empty($tenant['actual_check_in_date']) && $tenant['check_in_date'] < date('Y-m-d')): ?>
                        <span class="badge bg-danger"><?php echo t('past_checkin_date'); ?></span>
                        <?php elseif ($tenant['status'] === 'checked_in' && !empty($tenant['actual_check_in_date']) && $tenant['check_out_date'] == date('Y-m-d') && empty($tenant['actual_check_out_date'])): ?>
                        <span class="badge bg-warning"><?php echo t('checkout_today'); ?></span>
                        <?php elseif ($tenant['status'] === 'checked_in' && !empty($tenant['actual_check_in_date']) && $tenant['check_out_date'] < date('Y-m-d') && empty($tenant['actual_check_out_date'])): ?>
                        <span class="badge bg-danger"><?php echo t('overdue_checkout'); ?></span>
                        <?php else: ?>
                        <span class="badge bg-success"><?php echo t('checked_in'); ?></span>
                        <?php endif; ?>

                        <?php
                        if (!empty($tenant['dt_created_by_name'])) {
                            echo '<div class="small text-muted mt-1" style="font-size: 0.75rem;">' . t('by') . ': ' . htmlspecialchars($tenant['dt_created_by_name']) . '</div>';
                        }
                        ?>
                    </td>
                    <td style="text-align: center;">
                        <div class="btn-group">
                            <a href="view.php?id=<?php echo $tenant['id']; ?>" class="btn btn-sm btn-primary" title="<?php echo t('view'); ?>">
                                <i class="bi bi-search"></i>
                            </a>
                            <a href="form.php?id=<?php echo $tenant['id']; ?>" class="btn btn-sm btn-warning" title="<?php echo t('edit'); ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if ($tenant['status'] === 'pending_payment'): ?>
                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo $lang === 'en' ? 'Send a new payment link by email?' : 'ส่งลิงก์ชำระเงินใหม่ทางอีเมลหรือไม่'; ?>')">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="action" value="resend_payment_link">
                                    <input type="hidden" name="id" value="<?php echo $tenant['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-dark" title="<?php echo $lang === 'en' ? 'Resend payment link' : 'ส่งลิงก์ชำระเงินใหม่'; ?>">
                                        <i class="bi bi-send"></i>
                                    </button>
                                </form>
                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_payment'); ?>')">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="action" value="payment">
                                    <input type="hidden" name="id" value="<?php echo $tenant['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="<?php echo t('payment'); ?>">
                                        <i class="bi bi-credit-card"></i>
                                    </button>
                                </form>
                            <?php elseif ($tenant['status'] === 'checked_in'): ?>
                                <?php if (empty($tenant['actual_check_in_date'])): ?>
                                    <form method="POST" action="" class="d-inline daily-checkin-form" data-check-in-date="<?php echo htmlspecialchars($tenant['check_in_date']); ?>" data-check-in-display="<?php echo htmlspecialchars(formatDate($tenant['check_in_date'])); ?>" data-confirm-message="<?php echo htmlspecialchars(t('confirm_actual_checkin')); ?>">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="action" value="actual_checkin">
                                        <input type="hidden" name="id" value="<?php echo $tenant['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success" title="<?php echo t('actual_check_in'); ?>">
                                            <i class="bi bi-box-arrow-in-left"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_checkout'); ?>')">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="action" value="checkout">
                                        <input type="hidden" name="id" value="<?php echo $tenant['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-info" title="<?php echo t('checkout'); ?>">
                                            <i class="bi bi-box-arrow-right"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($tenant['status'] !== 'checked_out'): ?>
                                <!-- Check-in button to change status to checked_in -->
                                <form method="POST" action="" class="d-inline daily-checkin-form" data-check-in-date="<?php echo htmlspecialchars($tenant['check_in_date']); ?>" data-check-in-display="<?php echo htmlspecialchars(formatDate($tenant['check_in_date'])); ?>" data-confirm-message="<?php echo htmlspecialchars(t('confirm_checkin')); ?>">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="action" value="checkin">
                                    <input type="hidden" name="id" value="<?php echo $tenant['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-info" title="<?php echo t('check_in'); ?>">
                                        <i class="bi bi-box-arrow-in-right"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <a href="print.php?id=<?php echo $tenant['id']; ?>&type=receipt" target="_blank" class="btn btn-sm btn-secondary" title="<?php echo t('print'); ?>">
                                <i class="bi bi-printer"></i>
                            </a>
                            <?php if ($tenant['status'] !== 'checked_in' && $tenant['status'] !== 'checked_out' && $tenant['status'] !== 'cancelled' && $tenant['status'] !== 'pending_payment'): ?>
                            <form method="POST" action="" class="d-inline daily-cancel-form">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?php echo $tenant['id']; ?>">
                                <input type="hidden" name="refund" value="">
                                <button type="submit" class="btn btn-sm btn-danger" title="<?php echo t('cancel'); ?>">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tenants)): ?>
                <tr>
                    <td colspan="10" class="text-center py-5 text-muted">
                        <i class="bi bi-calendar-day fs-1"></i>
                        <p class="mt-2 mb-0"><?php echo t('no_data'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="text-muted">
                <?php echo t('showing'); ?> <?php echo (($page - 1) * $itemsPerPage) + 1; ?> - <?php echo min($page * $itemsPerPage, $totalRecords); ?> <?php echo t('of'); ?> <?php echo $totalRecords; ?> <?php echo t('records'); ?>
            </div>
            <nav aria-label="Page navigation">
                <ul class="pagination mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . $statusFilter : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>&sort_by=<?php echo $sortBy; ?>&sort_order=<?php echo $sortOrder; ?>"><?php echo t('previous'); ?></a>
                    </li>

                    <?php
                    $startPage = max(1, $page - 1);
                    $endPage = min($totalPages, $page + 1);

                    if ($totalPages > 3) {
                        if ($page <= 2) {
                            $startPage = 1;
                            $endPage = 3;
                        } elseif ($page >= $totalPages - 1) {
                            $startPage = $totalPages - 2;
                            $endPage = $totalPages;
                        }
                    }

                    if ($startPage > 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif;

                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . $statusFilter : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>&sort_by=<?php echo $sortBy; ?>&sort_order=<?php echo $sortOrder; ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor;

                    if ($endPage < $totalPages): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>

                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . $statusFilter : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>&sort_by=<?php echo $sortBy; ?>&sort_order=<?php echo $sortOrder; ?>"><?php echo t('next'); ?></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const earlyTitle = <?php echo json_encode(t('early_checkin_confirm_title')); ?>;
    const earlyTextTemplate = <?php echo json_encode(t('early_checkin_confirm_text')); ?>;
    const confirmTitle = <?php echo json_encode($lang === 'en' ? 'Confirm' : 'ยืนยัน'); ?>;
    const confirmYes = <?php echo json_encode(t('yes')); ?>;
    const confirmNo = <?php echo json_encode(t('no')); ?>;

    document.querySelectorAll('.daily-checkin-form').forEach(function(form) {
        function handleCheckinSubmit(e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            const scheduledDate = form.dataset.checkInDate || '';
            const scheduledDisplay = form.dataset.checkInDisplay || scheduledDate;
            const defaultMessage = form.dataset.confirmMessage || '';
            const today = new Date().toISOString().slice(0, 10);
            const isEarly = scheduledDate > today;

            Swal.fire({
                title: isEarly ? earlyTitle : confirmTitle,
                text: isEarly ? earlyTextTemplate.replace('%s', scheduledDisplay) : defaultMessage,
                icon: isEarly ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonColor: isEarly ? '#fd7e14' : 'var(--primary-color, #0d6efd)',
                cancelButtonColor: '#6c757d',
                confirmButtonText: confirmYes,
                cancelButtonText: confirmNo
            }).then(function(result) {
                if (result.isConfirmed) {
                    form.removeEventListener('submit', handleCheckinSubmit, true);
                    form.submit();
                }
            });
        }

        form.addEventListener('submit', handleCheckinSubmit, true);
    });

    const cancelTitle = <?php echo json_encode(t('cancel_confirm_title')); ?>;
    const cancelQuestion = <?php echo json_encode(t('cancel_refund_question')); ?>;
    const refundYes = <?php echo json_encode(t('refund_yes')); ?>;
    const refundNo = <?php echo json_encode(t('refund_no')); ?>;
    const abortCancel = <?php echo json_encode(t('close')); ?>;

    document.querySelectorAll('.daily-cancel-form').forEach(function(form) {
        function handleCancelSubmit(e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            Swal.fire({
                title: cancelTitle,
                text: cancelQuestion,
                icon: 'question',
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonColor: '#dc3545',
                denyButtonColor: 'var(--primary-color, #0d6efd)',
                cancelButtonColor: '#6c757d',
                confirmButtonText: refundYes,
                denyButtonText: refundNo,
                cancelButtonText: abortCancel
            }).then(function(result) {
                if (result.isConfirmed) {
                    form.querySelector('input[name="refund"]').value = '1';
                    form.removeEventListener('submit', handleCancelSubmit, true);
                    form.submit();
                } else if (result.isDenied) {
                    form.querySelector('input[name="refund"]').value = '0';
                    form.removeEventListener('submit', handleCancelSubmit, true);
                    form.submit();
                }
            });
        }

        form.addEventListener('submit', handleCancelSubmit, true);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
