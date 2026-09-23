<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
ensurePaymentConfirmationsTable();

$pageTitle = t('monthly_tenants');

$lang = $_SESSION['lang'] ?? 'th';

// Search and filter
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$monthFilter = (isset($_GET['month_year'], $_GET['month_num']) && $_GET['month_year'] !== '' && $_GET['month_num'] !== '')
    ? normalizeMonthFilterValue(sprintf('%04d-%02d', (int) $_GET['month_year'], (int) $_GET['month_num']))
    : normalizeMonthFilterValue($_GET['month'] ?? date('Y-m'));
$localizedMonthNames = localizedMonthNames($lang);
$selectedMonthYear = (int) substr($monthFilter, 0, 4);
$selectedMonthNumber = (int) substr($monthFilter, 5, 2);
$monthFilterYears = range((int) date('Y') + 2, (int) date('Y') - 5);
if (!in_array($selectedMonthYear, $monthFilterYears, true)) {
    $monthFilterYears[] = $selectedMonthYear;
}
rsort($monthFilterYears);
$monthStart = $monthFilter . '-01';
$nextMonthStart = date('Y-m-d', strtotime($monthStart . ' +1 month'));
$monthEnd = date('Y-m-d', strtotime($monthStart . ' last day of this month'));

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$itemsPerPage = 20;
$offset = ($page - 1) * $itemsPerPage;

// Sorting settings
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'DESC';
$validSortColumns = [
    'room_number',
    'tenant_name',
    'phone',
    'contract_start',
    'contract_end',
    'monthly_rent',
    'water_amount',
    'elec_amount',
    'other_fees',
    'discount',
    'total_amount',
    'status',
    'payment_status',
    'created_at'
];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'created_at';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Helper function to build URL with sort parameters
function buildSortUrl($column, $currentSortBy, $currentSortOrder, $search, $statusFilter, $monthFilter) {
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
    if ($monthFilter) $params['month'] = $monthFilter;
    
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

// Build query - show only tenants whose contracts cover the selected month.
// Latest bill/invoice rows are derived once, avoiding repeated correlated subqueries per tenant.
$sql = "SELECT mt.*, r.room_number, r.room_type_id, rt.type_name, rt.type_name_en,
        rt.price_monthly AS room_type_price_monthly,
        ub.rent_amount AS bill_rent_amount,
        ub.water_amount,
        ub.elec_amount,
        ub.other_fees,
        ub.discount,
        ub.total_amount,
        ub.bill_month,
        ub.status as bill_status,
        COALESCE(ub_latest_user.username, ub_creator.username) AS bill_actor_name,
        COALESCE(mt_latest_user.username, mt_creator.username) AS monthly_tenant_actor_name,
        inv.due_date,
        inv.status as invoice_status,
        DATE_FORMAT(mt.updated_at, '%Y-%m') as termination_month
        FROM monthly_tenants mt
        JOIN rooms r ON mt.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN users mt_creator ON mt.created_by = mt_creator.id
        LEFT JOIN (
            SELECT al.entity_id, al.user_id
            FROM activity_logs al
            INNER JOIN (
                SELECT entity_id, MAX(id) AS latest_id
                FROM activity_logs
                WHERE entity_type = 'monthly_tenant'
                    AND action IN ('create_monthly_tenant', 'update_monthly_tenant', 'terminate_monthly_tenant')
                GROUP BY entity_id
            ) latest_activity ON latest_activity.latest_id = al.id
        ) mt_latest_activity ON mt_latest_activity.entity_id = mt.id
        LEFT JOIN users mt_latest_user ON mt_latest_activity.user_id = mt_latest_user.id
        LEFT JOIN (
            SELECT ub1.*
            FROM utility_bills ub1
            JOIN (
                SELECT tenant_id, bill_month, MAX(id) AS max_id
                FROM utility_bills
                WHERE bill_month = ?
                GROUP BY tenant_id, bill_month
            ) latest_ub ON latest_ub.max_id = ub1.id
        ) ub ON ub.tenant_id = mt.id
        LEFT JOIN users ub_creator ON ub.created_by = ub_creator.id
        LEFT JOIN (
            SELECT al.entity_id, al.user_id
            FROM activity_logs al
            INNER JOIN (
                SELECT entity_id, MAX(id) AS latest_id
                FROM activity_logs
                WHERE entity_type IN ('utility_bill', 'utility_bills')
                GROUP BY entity_id
            ) latest_activity ON latest_activity.latest_id = al.id
        ) ub_latest_activity ON ub_latest_activity.entity_id = ub.id
        LEFT JOIN users ub_latest_user ON ub_latest_activity.user_id = ub_latest_user.id
        LEFT JOIN (
            SELECT i1.tenant_id, i1.due_date, i1.status
            FROM invoices i1
            JOIN (
                SELECT tenant_id, MAX(id) AS max_id
                FROM invoices
                WHERE tenant_type = 'monthly'
                    AND invoice_date >= ?
                    AND invoice_date < ?
                GROUP BY tenant_id
            ) latest_inv ON latest_inv.max_id = i1.id
        ) inv ON inv.tenant_id = mt.id
        WHERE mt.contract_start < ?
        AND mt.contract_end >= ?
        AND (mt.status != 'terminated' OR mt.updated_at >= ?)";
$params = [$monthFilter, $monthStart, $nextMonthStart, $nextMonthStart, $monthStart, $monthStart];

if ($search) {
    $sql .= " AND (mt.tenant_name LIKE ? OR mt.phone LIKE ? OR r.room_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($statusFilter === 'terminated') {
    $sql .= " AND mt.status = 'terminated' AND mt.updated_at >= ? AND mt.updated_at < ?";
    $params[] = $monthStart;
    $params[] = $nextMonthStart;
} elseif ($statusFilter === 'expired') {
    $sql .= " AND mt.contract_end >= ? AND mt.contract_end < ? AND (mt.status = 'expired' OR ? > mt.contract_end)";
    $params[] = $monthStart;
    $params[] = $nextMonthStart;
    $params[] = date('Y-m-d');
} elseif ($statusFilter) {
    $sql .= " AND mt.status = ?";
    $params[] = $statusFilter;
}

// Apply sorting
$sortColumnMap = [
    'room_number' => 'r.room_number',
    'tenant_name' => 'mt.tenant_name',
    'phone' => 'mt.phone',
    'contract_start' => 'mt.contract_start',
    'contract_end' => 'mt.contract_end',
    'monthly_rent' => 'mt.monthly_rent',
    'water_amount' => 'COALESCE(ub.water_amount, 0)',
    'elec_amount' => 'COALESCE(ub.elec_amount, 0)',
    'other_fees' => 'COALESCE(ub.other_fees, 0)',
    'discount' => 'COALESCE(ub.discount, 0)',
    'total_amount' => 'COALESCE(ub.total_amount, mt.monthly_rent)',
    'status' => 'mt.status',
    'payment_status' => "CASE
        WHEN ub.status = 'paid' THEN 4
        WHEN ub.bill_month IS NULL THEN 1
        WHEN COALESCE(inv.due_date, DATE_ADD(STR_TO_DATE(CONCAT(ub.bill_month, '-01'), '%Y-%m-%d'), INTERVAL 1 MONTH)) < CURDATE() THEN 3
        ELSE 2
    END",
    'created_at' => 'mt.created_at'
];
$sql .= " ORDER BY " . $sortColumnMap[$sortBy] . " " . $sortOrder;

// Count total records for pagination
$countSql = "SELECT COUNT(*) as total FROM monthly_tenants mt 
        JOIN rooms r ON mt.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id 
        WHERE mt.contract_start < ?
        AND mt.contract_end >= ?
        AND (mt.status != 'terminated' OR mt.updated_at >= ?)";
$countParams = [$nextMonthStart, $monthStart, $monthStart];
if ($search) {
    $countSql .= " AND (mt.tenant_name LIKE ? OR mt.phone LIKE ? OR r.room_number LIKE ?)";
    $countParams[] = "%$search%";
    $countParams[] = "%$search%";
    $countParams[] = "%$search%";
}
if ($statusFilter === 'terminated') {
    $countSql .= " AND mt.status = 'terminated' AND mt.updated_at >= ? AND mt.updated_at < ?";
    $countParams[] = $monthStart;
    $countParams[] = $nextMonthStart;
} elseif ($statusFilter === 'expired') {
    $countSql .= " AND mt.contract_end >= ? AND mt.contract_end < ? AND (mt.status = 'expired' OR ? > mt.contract_end)";
    $countParams[] = $monthStart;
    $countParams[] = $nextMonthStart;
    $countParams[] = date('Y-m-d');
} elseif ($statusFilter) {
    $countSql .= " AND mt.status = ?";
    $countParams[] = $statusFilter;
}
$stmt = $pdo->prepare($countSql);
$stmt->execute($countParams);
$totalRecords = $stmt->fetch()['total'];
$totalPages = ceil($totalRecords / $itemsPerPage);

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

// Get payment due day from settings
$paymentDueDay = $pdo->query("SELECT payment_due_day FROM settings LIMIT 1")->fetchColumn() ?: 5;

// Calculate payment status for each tenant based on settings
foreach ($tenants as &$tenant) {
    $tenant['display_rent'] = $tenant['bill_rent_amount'] !== null
        ? (float) $tenant['bill_rent_amount']
        : calculateMonthlyTenantRentForMonth($tenant, $monthFilter);

    // First determine base payment status from bill data
    if (($tenant['payment_confirmation_status'] ?? null) === 'pending_verify') {
        $tenant['payment_status'] = 'pending_verify';
    } elseif ($tenant['bill_status'] === 'paid') {
        $tenant['payment_status'] = 'paid';
    } elseif ($tenant['bill_month'] === null) {
        $tenant['payment_status'] = 'waiting_meter';
    } elseif ($tenant['bill_month'] !== null && ($tenant['bill_status'] === null || $tenant['bill_status'] !== 'paid')) {
        // Unpaid bill - check if overdue
        $billDate = new DateTime($tenant['bill_month'] . '-01');
        $dueDate = clone $billDate;
        $dueDate->modify('+1 month'); // Next month (April for March bill)
        $dueDate->setDate($dueDate->format('Y'), $dueDate->format('m'), $paymentDueDay);
        
        $today = new DateTime();
        
        if ($dueDate < $today) {
            $tenant['payment_status'] = 'overdue';
        } else {
            $tenant['payment_status'] = 'unpaid'; // Waiting for payment (รอชำระ)
        }
    } else {
        $tenant['payment_status'] = null;
    }
}
unset($tenant);

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'terminate') {
    requireValidCsrfToken();
    $id = intval($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT room_id FROM monthly_tenants WHERE id = ?");
    $stmt->execute([$id]);
    $roomId = (int) ($stmt->fetch()['room_id'] ?? 0);
    
    $stmt = $pdo->prepare("UPDATE monthly_tenants SET status = 'terminated' WHERE id = ?");
    if ($stmt->execute([$id])) {
        if ($roomId > 0) {
            updateRoomStatus($roomId);
        }
        
        setFlashMessage('success', t('terminate_success'));
        logActivity('terminate_monthly_tenant', 'monthly_tenant', $id);
    } else {
        setFlashMessage('error', t('terminate_error'));
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h5 class="mb-0 me-3"><i class="bi bi-calendar-month me-2"></i><?php echo t('monthly_tenants'); ?></h5>
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto">
            <span class="badge text-bg-light border rounded-pill px-3 py-2 text-dark">
                <?php echo t('all'); ?> <?php echo number_format($totalRecords); ?>
            </span>
            <a href="form.php" class="btn btn-primary flex-shrink-0 d-flex align-items-center">
                <i class="bi bi-plus-circle me-3"></i><?php echo t('add_tenant'); ?>
            </a>
        </div>
    </div>
    
    <!-- Search & Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label"><?php echo t('month'); ?></label>
                    <div class="row g-2">
                        <div class="col-7">
                            <select name="month_num" class="form-select">
                                <?php foreach ($localizedMonthNames as $index => $monthName): ?>
                                <option value="<?php echo $index + 1; ?>" <?php echo $selectedMonthNumber === $index + 1 ? 'selected' : ''; ?>><?php echo htmlspecialchars($monthName); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-5">
                            <select name="month_year" class="form-select">
                                <?php foreach ($monthFilterYears as $yearOption): ?>
                                <option value="<?php echo $yearOption; ?>" <?php echo $selectedMonthYear === (int) $yearOption ? 'selected' : ''; ?>><?php echo $yearOption; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label"><?php echo t('search'); ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="<?php echo t('search_name_phone_room'); ?>..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label"><?php echo t('status'); ?></label>
                    <select name="status" class="form-select">
                        <option value=""><?php echo t('all_status'); ?></option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>><?php echo t('contract_active'); ?></option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>><?php echo t('contract_pending'); ?></option>
                        <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>><?php echo t('contract_expired'); ?></option>
                        <option value="terminated" <?php echo $statusFilter === 'terminated' ? 'selected' : ''; ?>><?php echo t('contract_terminated'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
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
    .btn-group > form.d-inline:first-child .btn {
        margin-left: 0;
        border-top-left-radius: 0.25rem;
        border-bottom-left-radius: 0.25rem;
    }
    .btn-group > form.d-inline:last-child .btn {
        border-top-right-radius: 0.25rem;
        border-bottom-right-radius: 0.25rem;
    }
@media (max-width: 768px) {
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .table {
        min-width: 1000px !important;
        white-space: nowrap;
    }
    .table th,
    .table td {
        white-space: nowrap !important;
        vertical-align: middle !important;
    }
}
</style>
<div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th width="80" style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('room_number', $sortBy, $sortOrder, $search, $statusFilter, $monthFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('room'); ?><?php echo getSortIcon('room_number', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="120" style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('tenant_name', $sortBy, $sortOrder, $search, $statusFilter, $monthFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('tenant_name'); ?><?php echo getSortIcon('tenant_name', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="100" style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('monthly_rent', $sortBy, $sortOrder, $search, $statusFilter, $monthFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('monthly_rent'); ?><?php echo getSortIcon('monthly_rent', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="80" style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('water_amount', $sortBy, $sortOrder, $search, $statusFilter, $monthFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('water'); ?><?php echo getSortIcon('water_amount', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="80" style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('elec_amount', $sortBy, $sortOrder, $search, $statusFilter, $monthFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('electric'); ?><?php echo getSortIcon('elec_amount', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="80" style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('other_fees', $sortBy, $sortOrder, $search, $statusFilter, $monthFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('other_fees'); ?><?php echo getSortIcon('other_fees', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="80" style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('discount', $sortBy, $sortOrder, $search, $statusFilter, $monthFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('discount'); ?><?php echo getSortIcon('discount', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="80" style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('total_amount', $sortBy, $sortOrder, $search, $statusFilter, $monthFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('total'); ?><?php echo getSortIcon('total_amount', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="80" style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('status', $sortBy, $sortOrder, $search, $statusFilter, $monthFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('status'); ?><?php echo getSortIcon('status', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="100" style="white-space: nowrap; text-align: center;">
                        <a href="<?php echo buildSortUrl('payment_status', $sortBy, $sortOrder, $search, $statusFilter, $monthFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('payment_status_label'); ?><?php echo getSortIcon('payment_status', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="180" style="white-space: nowrap; text-align: center;">
                        <?php echo t('actions'); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php 
                foreach ($tenants as $index => $tenant): 
                    $contractStartMonth = date('Y-m', strtotime($tenant['contract_start']));
                    $contractEndMonth = date('Y-m', strtotime($tenant['contract_end']));
                    $isTerminationMonth = $tenant['status'] === 'terminated' && $tenant['termination_month'] === $monthFilter;
                    $isContractEndMonth = $contractEndMonth === $monthFilter;

                    if ($isTerminationMonth) {
                        $status = ['label' => t('contract_terminated'), 'class' => 'secondary'];
                    } elseif ($isContractEndMonth && (date('Y-m-d') > $tenant['contract_end'] || $tenant['status'] === 'expired')) {
                        $status = ['label' => t('contract_expired'), 'class' => 'danger'];
                    } elseif ($isContractEndMonth && strtotime($tenant['contract_end']) <= strtotime($monthEnd)) {
                        $status = ['label' => t('contract_expiring'), 'class' => 'warning'];
                    } elseif ($contractStartMonth === $monthFilter && strtotime($tenant['contract_start']) > strtotime($monthStart) && date('Y-m-d') < $tenant['contract_start']) {
                        $status = ['label' => t('contract_pending'), 'class' => 'info'];
                    } else {
                        $status = ['label' => t('contract_active'), 'class' => 'success'];
                    }
                ?>
                <tr>
                    <td style="text-align: center;"><?php echo $tenant['room_number']; ?></td>
                    <td style="text-align: center;"><?php echo htmlspecialchars($tenant['tenant_name']); ?></td>
                    <td style="text-align: center;"><?php echo formatCurrency($tenant['display_rent']); ?></td>
                    <td style="text-align: center;"><?php echo formatCurrency($tenant['water_amount'] ?? 0); ?></td>
                    <td style="text-align: center;"><?php echo formatCurrency($tenant['elec_amount'] ?? 0); ?></td>
                    <td style="text-align: center;"><?php echo formatCurrency($tenant['other_fees'] ?? 0); ?></td>
                    <td style="text-align: center;"><?php echo formatCurrency($tenant['discount'] ?? 0); ?></td>
                    <td style="text-align: center;" class="fw-bold"><?php echo formatCurrency($tenant['total_amount'] ?? $tenant['display_rent']); ?></td>
                    <td style="text-align: center;">
                        <?php 
                        echo '<span class="badge bg-' . $status['class'] . '">' . $status['label'] . '</span>';
                        
                        if (!empty($tenant['monthly_tenant_actor_name'])) {
                            echo '<div class="small text-muted mt-1" style="font-size: 0.75rem;">' . t('by') . ': ' . htmlspecialchars($tenant['monthly_tenant_actor_name']) . '</div>';
                        }
                        ?>
                    </td>
                    <td style="text-align: center;">
                        <?php 
                        $paymentStatus = trim($tenant['payment_status'] ?? '');
                        if ($paymentStatus === 'waiting_meter') {
                            echo '<span class="badge bg-info">' . t('waiting_meter') . '</span>';
                        } elseif ($paymentStatus === 'paid') {
                            echo '<span class="badge bg-success">' . t('paid') . '</span>';
                        } elseif ($paymentStatus === 'overdue') {
                            echo '<span class="badge bg-danger">' . t('overdue') . '</span>';
                        } elseif ($paymentStatus === 'unpaid') {
                            echo '<span class="badge bg-warning text-dark">' . t('unpaid') . '</span>';
                        } else {
                            echo '<span class="badge bg-secondary">-</span>';
                        }
                        
                        if (!empty($tenant['bill_actor_name'])) {
                            echo '<div class="small text-muted mt-1" style="font-size: 0.75rem;">' . t('by') . ': ' . htmlspecialchars($tenant['bill_actor_name']) . '</div>';
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
                            <?php if ($tenant['payment_status'] === 'waiting_meter' || $tenant['payment_status'] === 'unpaid' || $tenant['payment_status'] === 'overdue' || !$tenant['payment_status']): ?>
                            <a href="../utility-bills/form.php?tenant_id=<?php echo $tenant['id']; ?>&month=<?php echo $monthFilter; ?>" class="btn btn-sm btn-info" title="<?php echo t('record_utility_bills'); ?>">
                                <i class="bi bi-droplet"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($tenant['payment_status'] === 'waiting_meter' || $tenant['payment_status'] === 'unpaid' || $tenant['payment_status'] === 'overdue' || !$tenant['payment_status']): ?>
                            <?php if ($tenant['bill_month']): ?>
                            <button class="btn btn-sm btn-success" onclick="markAsPaid(<?php echo $tenant['id']; ?>)" title="<?php echo t('mark_paid'); ?>">
                                <i class="bi bi-credit-card"></i>
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($tenant['bill_month'])): ?>
                            <a href="print.php?id=<?php echo $tenant['id']; ?>&month=<?php echo urlencode($monthFilter); ?>&type=receipt&lang=<?php echo urlencode($_SESSION['lang'] ?? 'th'); ?>" target="_blank" class="btn btn-sm btn-secondary" title="<?php echo t('print'); ?>">
                                <i class="bi bi-printer"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($tenant['status'] === 'active' || $tenant['status'] === 'pending'): ?>
                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_terminate'); ?>')">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="terminate">
                                <input type="hidden" name="id" value="<?php echo $tenant['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="<?php echo t('terminate_contract'); ?>">
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
                    <td colspan="11" class="text-center py-5 text-muted">
                        <i class="bi bi-calendar-month fs-1"></i>
                        <p class="mt-2 mb-0"><?php echo t('no_data'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php renderUnifiedPagination($page, $totalPages, (int)$totalRecords, $itemsPerPage, [
            'month' => $monthFilter,
            'search' => $search,
            'status' => $statusFilter,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ], t('monthly_tenants')); ?>
    </div>
</div>

    <script>
function markAsPaid(tenantId) {
    Swal.fire({
        title: '<?php echo $lang === "en" ? "Confirm" : "ยืนยัน"; ?>',
        text: '<?php echo t('confirm_payment'); ?>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: 'var(--primary-color, #0d6efd)',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<?php echo t("yes"); ?>',
        cancelButtonText: '<?php echo t("no"); ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            const apiUrl = '/Rental/api/mark-paid.php';
            console.log('Calling API:', apiUrl);
            console.log('Data:', { tenant_id: tenantId, month: '<?php echo $monthFilter; ?>' });
            
            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': '<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>',
                },
                body: JSON.stringify({
                    tenant_id: tenantId,
                    month: '<?php echo $monthFilter; ?>'
                })
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                return response.text(); // Get as text first to debug
            })
            .then(text => {
                console.log('Response text:', text);
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: '<?php echo t("success"); ?>',
                            text: '<?php echo t("payment_recorded"); ?>',
                            confirmButtonColor: 'var(--primary-color, #0d6efd)',
                            timer: 1500,
                            showConfirmButton: true
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: '<?php echo t("error"); ?>',
                            text: data.message || 'Error',
                            confirmButtonColor: 'var(--primary-color, #0d6efd)'
                        });
                    }
                } catch (e) {
                    console.error('JSON parse error:', e);
                    console.error('Response was:', text);
                    Swal.fire({
                        icon: 'error',
                        title: '<?php echo t("error"); ?>',
                        text: 'Invalid response from server: ' + text.substring(0, 200),
                        confirmButtonColor: 'var(--primary-color, #0d6efd)'
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: '<?php echo t("error"); ?>',
                    text: 'Error occurred: ' + error.message,
                    confirmButtonColor: 'var(--primary-color, #0d6efd)'
                });
            });
        }
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
