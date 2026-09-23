<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = t('utility_bills');

// Get current month filter
$lang = $_SESSION['lang'] ?? 'th';
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
$tenantFilter = $_GET['tenant'] ?? '';

// Pagination
$itemsPerPage = 20;
$pageParam = isset($_GET['page']) ? intval($_GET['page']) : null;

// Sorting settings
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'DESC';
$validSortColumns = ['room_number', 'tenant_name', 'bill_month', 'water_units', 'water_amount', 'elec_units', 'elec_amount', 'created_at'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'created_at';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Helper function to build URL with sort parameters
function buildSortUrl($column, $currentSortBy, $currentSortOrder, $monthFilter, $tenantFilter) {
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
    
    if ($monthFilter) $params['month'] = $monthFilter;
    if ($tenantFilter) $params['tenant'] = $tenantFilter;
    
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

// Build query - show all utility bills from tenant start date
$selectFields = "SELECT ub.*, mt.tenant_name, r.room_number, mt.contract_start,
        COALESCE(al_user.username, ub_user.username, default_admin.username) AS creator_username";
$sql = "$selectFields
        FROM utility_bills ub
        JOIN monthly_tenants mt ON ub.tenant_id = mt.id
        JOIN rooms r ON ub.room_id = r.id
        LEFT JOIN users ub_user ON ub.created_by = ub_user.id
        LEFT JOIN (
            SELECT al.entity_id, u.username
            FROM activity_logs al
            INNER JOIN (
                SELECT entity_id, MAX(created_at) AS max_created_at
                FROM activity_logs
                WHERE entity_type IN ('utility_bill', 'utility_bills')
                GROUP BY entity_id
            ) latest_al ON latest_al.entity_id = al.entity_id AND latest_al.max_created_at = al.created_at
            LEFT JOIN users u ON al.user_id = u.id
        ) al_user ON al_user.entity_id = ub.id
        LEFT JOIN (
            SELECT username FROM users WHERE is_active = 1 AND role = 'admin' ORDER BY id ASC LIMIT 1
        ) default_admin ON 1=1
        WHERE ub.bill_month >= DATE_FORMAT(mt.contract_start, '%Y-%m')";
$params = [];

if ($monthFilter) {
    $sql .= " AND ub.bill_month = ?";
    $params[] = $monthFilter;
}

if ($tenantFilter) {
    $sql .= " AND ub.tenant_id = ?";
    $params[] = $tenantFilter;
}

// Count and summary totals for the full filtered result.
$countSql = str_replace(
    $selectFields,
    'SELECT COUNT(*) as total, COALESCE(SUM(ub.water_amount), 0) as total_water, COALESCE(SUM(ub.elec_amount), 0) as total_elec',
    $sql
);
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$summary = $stmt->fetch();
$totalRecords = (int) ($summary['total'] ?? 0);
$totalWater = (float) ($summary['total_water'] ?? 0);
$totalElec = (float) ($summary['total_elec'] ?? 0);
$totalPages = max(1, (int) ceil($totalRecords / $itemsPerPage));
$page = $pageParam === null ? 1 : max(1, min($pageParam, $totalPages));
$offset = ($page - 1) * $itemsPerPage;

// Apply sorting
$sortColumnMap = [
    'room_number' => 'r.room_number',
    'tenant_name' => 'mt.tenant_name',
    'bill_month' => 'ub.bill_month',
    'water_units' => 'ub.water_units',
    'water_amount' => 'ub.water_amount',
    'elec_units' => 'ub.elec_units',
    'elec_amount' => 'ub.elec_amount',
    'created_at' => 'ub.created_at'
];
$sql .= " ORDER BY " . $sortColumnMap[$sortBy] . " " . $sortOrder;
$sql .= " LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($sql);
$paramIndex = 1;
foreach ($params as $param) {
    $stmt->bindValue($paramIndex++, $param);
}
$stmt->bindValue($paramIndex++, $itemsPerPage, PDO::PARAM_INT);
$stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
$stmt->execute();
$bills = $stmt->fetchAll();

// Check if filtered tenant already has a bill for the filtered month
$existingBillId = null;
if ($monthFilter && $tenantFilter) {
    $stmt = $pdo->prepare("SELECT id FROM utility_bills WHERE tenant_id = ? AND bill_month = ? LIMIT 1");
    $stmt->execute([$tenantFilter, $monthFilter]);
    $existingBill = $stmt->fetch();
    if ($existingBill) {
        $existingBillId = $existingBill['id'];
    }
}

// Get tenants for filter
$stmt = $pdo->query("SELECT mt.id, mt.tenant_name, r.room_number FROM monthly_tenants mt JOIN rooms r ON mt.room_id = r.id WHERE mt.status IN ('active', 'pending') ORDER BY r.room_number");
$tenants = $stmt->fetchAll();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireValidCsrfToken();
    $id = intval($_POST['id'] ?? 0);
    
    $stmt = $pdo->prepare("DELETE FROM utility_bills WHERE id = ? AND status = 'unpaid'");
    if ($stmt->execute([$id])) {
        setFlashMessage('success', t('delete_success'));
        logActivity('delete_utility_bill', 'utility_bill', $id);
    } else {
        setFlashMessage('error', t('delete_failed_paid'));
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?month=' . $monthFilter);
    exit;
}

// Handle mark as paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'paid') {
    requireValidCsrfToken();
    $id = intval($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT tenant_id, bill_month FROM utility_bills WHERE id = ?");
    $stmt->execute([$id]);
    $billRow = $stmt->fetch();

    $stmt = $pdo->prepare("UPDATE utility_bills SET status = 'paid', paid_date = ? WHERE id = ?");
    if ($stmt->execute([date('Y-m-d'), $id])) {
        if ($billRow) {
            sendMonthlyReceiptEmail((int) $billRow['tenant_id'], $billRow['bill_month'], 'paid');
        }
        setFlashMessage('success', t('payment_recorded'));
        logActivity('mark_paid', 'utility_bill', $id);
    } else {
        setFlashMessage('error', t('save_error'));
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?month=' . $monthFilter);
    exit;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h5 class="mb-0 me-3"><i class="bi bi-droplet me-2"></i><?php echo t('utility_bills'); ?></h5>
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto">
            <span class="badge text-bg-light border rounded-pill px-3 py-2 text-dark">
                <?php echo t('all'); ?> <?php echo number_format($totalRecords); ?>
            </span>
            <?php if ($existingBillId): ?>
            <a href="form.php?id=<?php echo $existingBillId; ?>" class="btn btn-warning flex-shrink-0">
                <i class="bi bi-pencil me-2"></i><?php echo t('edit'); ?>
            </a>
            <?php else: ?>
            <a href="form.php" class="btn btn-primary flex-shrink-0">
                <i class="bi bi-plus-circle me-2"></i><?php echo t('record_meter'); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Summary -->
    <style>
    @media (max-width: 767.98px) {
        .stat-cards-scroll {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            gap: 0.35rem;
            padding-bottom: 0.3rem;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .stat-cards-scroll::-webkit-scrollbar { display: none; }
        .stat-cards-scroll .stat-card-item {
            flex: 0 0 auto;
            width: 125px;
            min-width: 110px;
        }
        .stat-cards-scroll .card {
            min-height: 52px !important;
            border-radius: 12px !important;
        }
        .stat-cards-scroll .card-body {
            padding: 0.35rem 0.25rem !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            min-height: 52px;
        }
        .stat-cards-scroll .card-body h6 {
            font-size: 0.68rem;
            line-height: 1.2;
            margin-bottom: 0.15rem !important;
            word-break: break-word;
            white-space: normal;
        }
        .stat-cards-scroll .card-body h4 {
            font-size: 0.95rem !important;
            line-height: 1;
            margin-top: 0;
            margin-bottom: 0;
            white-space: nowrap;
        }
    }
    @media (min-width: 768px) {
        .stat-cards-scroll { display: flex; flex-wrap: wrap; gap: 0.75rem; }
        .stat-cards-scroll .stat-card-item { flex: 1 1 0; min-width: 0; }
    }
    </style>
    <div class="stat-cards-scroll mb-4">
        <div class="stat-card-item">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h6><?php echo t('total_water'); ?></h6>
                    <h4><?php echo formatCurrency($totalWater); ?></h4>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h6><?php echo t('total_electric'); ?></h6>
                    <h4><?php echo formatCurrency($totalElec); ?></h4>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-12 col-md-4">
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
                <div class="col-12 col-md-4">
                    <label class="form-label"><?php echo t('tenant'); ?></label>
                    <select name="tenant" class="form-select">
                        <option value=""><?php echo t('all'); ?></option>
                        <?php foreach ($tenants as $t): ?>
                        <option value="<?php echo $t['id']; ?>" <?php echo $tenantFilter == $t['id'] ? 'selected' : ''; ?>><?php echo $t['room_number']; ?> - <?php echo $t['tenant_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-outline-primary w-100"><?php echo t('search'); ?></button>
                </div>
            </form>
        </div>
    </div>
    
    <style>
        /* Remove left border-radius from delete button inside btn-group */
        .btn-group form.d-inline .btn {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
    </style>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th style="white-space: nowrap; text-align: center; min-width: 80px;">
                        <a href="<?php echo buildSortUrl('room_number', $sortBy, $sortOrder, $monthFilter, $tenantFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('room'); ?><?php echo getSortIcon('room_number', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="200" style="white-space: nowrap; min-width: 200px; text-align: center;">
                        <a href="<?php echo buildSortUrl('tenant_name', $sortBy, $sortOrder, $monthFilter, $tenantFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('tenant'); ?><?php echo getSortIcon('tenant_name', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th style="white-space: nowrap; text-align: center; min-width: 80px;">
                        <a href="<?php echo buildSortUrl('water_units', $sortBy, $sortOrder, $monthFilter, $tenantFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('water_units'); ?><?php echo getSortIcon('water_units', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="100" style="white-space: nowrap; text-align: center; min-width: 90px;">
                        <a href="<?php echo buildSortUrl('water_amount', $sortBy, $sortOrder, $monthFilter, $tenantFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('water'); ?><?php echo getSortIcon('water_amount', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th style="white-space: nowrap; text-align: center; min-width: 80px;">
                        <a href="<?php echo buildSortUrl('elec_units', $sortBy, $sortOrder, $monthFilter, $tenantFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('electric_units'); ?><?php echo getSortIcon('elec_units', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="100" style="white-space: nowrap; text-align: center; min-width: 90px;">
                        <a href="<?php echo buildSortUrl('elec_amount', $sortBy, $sortOrder, $monthFilter, $tenantFilter); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('electric'); ?><?php echo getSortIcon('elec_amount', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="100" style="white-space: nowrap; text-align: center; min-width: 90px;"><?php echo t('status'); ?></th>
                    <th width="150" style="white-space: nowrap; text-align: center; min-width: 130px;"><?php echo t('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bills as $bill): ?>
                <tr>
                    <td style="text-align: center;"><?php echo $bill['room_number']; ?></td>
                    <td style="text-align: center;"><?php echo htmlspecialchars($bill['tenant_name']); ?></td>
                    <td style="text-align: center;"><?php echo $bill['water_units']; ?> <?php echo t('units'); ?></td>
                    <td style="text-align: center;"><?php echo formatCurrency($bill['water_amount']); ?></td>
                    <td style="text-align: center;"><?php echo $bill['elec_units']; ?> <?php echo t('units'); ?></td>
                    <td style="text-align: center;"><?php echo formatCurrency($bill['elec_amount']); ?></td>
                    <td style="text-align: center;">
                        <?php if ($bill['status'] === 'paid'): ?>
                            <span class="badge bg-success"><?php echo t('paid'); ?></span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark"><?php echo t('unpaid'); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($bill['creator_username'])): ?>
                            <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                                <?php echo t('by'); ?>: <?php echo htmlspecialchars($bill['creator_username']); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <div class="btn-group">
                            <a href="form.php?id=<?php echo $bill['id']; ?>" class="btn btn-sm btn-warning" title="<?php echo t('edit'); ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if ($bill['status'] === 'unpaid'): ?>
                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_delete'); ?>')">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $bill['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="<?php echo t('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($bills)): ?>
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-droplet fs-1"></i>
                        <p class="mt-2 mb-0"><?php echo t('no_data_this_month'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php renderUnifiedPagination($page, $totalPages, (int)$totalRecords, $itemsPerPage, [
        'month' => $monthFilter,
        'tenant' => $tenantFilter,
        'sort_by' => $sortBy,
        'sort_order' => $sortOrder,
    ], t('utility_bills')); ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
