<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$settings = getSettings();
$hasTaxId = !empty($settings['tax_id']);
$pageTitle = $hasTaxId ? t('tax_invoice') : t('receipt');

// Filters
$search = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$dateFrom = normalizeDateFilterValue($_GET['date_from'] ?? '');
$dateTo = normalizeDateFilterValue($_GET['date_to'] ?? '');

// Pagination settings
$itemsPerPage = 20;
$pageParam = isset($_GET['page']) ? intval($_GET['page']) : null;

// Sorting settings
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'DESC';
$validSortColumns = ['invoice_number', 'invoice_type', 'room_number', 'tenant_name', 'created_at', 'grand_total'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'created_at';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Helper function to build URL with sort parameters
function buildSortUrl($column, $currentSortBy, $currentSortOrder, $search, $typeFilter, $dateFrom, $dateTo) {
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
    if ($typeFilter) $params['type'] = $typeFilter;
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
$sql = "SELECT i.*, r.room_number,
        COALESCE(dt.guest_name, mt.tenant_name) as tenant_name
        FROM invoices i
        JOIN rooms r ON i.room_id = r.id
        LEFT JOIN daily_tenants dt ON i.tenant_type = 'daily' AND dt.id = i.tenant_id
        LEFT JOIN monthly_tenants mt ON i.tenant_type = 'monthly' AND mt.id = i.tenant_id
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (i.invoice_number LIKE ? OR r.room_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($typeFilter) {
    $sql .= " AND i.invoice_type = ?";
    $params[] = $typeFilter;
}

if ($dateFrom) {
    $sql .= " AND i.created_at >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND i.created_at < ?";
    $params[] = date('Y-m-d', strtotime($dateTo . ' +1 day'));
}

// Apply sorting
$sortColumnMap = [
    'invoice_number' => 'i.invoice_number',
    'invoice_type' => 'i.invoice_type',
    'room_number' => 'r.room_number',
    'tenant_name' => 'tenant_name',
    'created_at' => 'i.created_at',
    'grand_total' => 'i.grand_total'
];
$sql .= " ORDER BY " . $sortColumnMap[$sortBy] . " " . $sortOrder;

// Count total records for pagination
$countSql = str_replace("SELECT i.*, r.room_number,
        COALESCE(dt.guest_name, mt.tenant_name) as tenant_name", 'SELECT COUNT(*) as total', $sql);
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
$invoices = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h5 class="mb-0 me-3"><i class="bi bi-receipt me-2"></i><?php echo $hasTaxId ? t('tax_invoice') : t('receipt'); ?></h5>
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto">
            <span class="badge text-bg-light border rounded-pill px-3 py-2 text-dark">
                <?php echo t('all'); ?> <?php echo number_format($totalRecords); ?>
            </span>
        </div>
    </div>
    
    <!-- Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="<?php echo t('search_invoice_room'); ?>..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-2">
                    <select name="type" class="form-select">
                        <option value=""><?php echo t('all_types'); ?></option>
                        <option value="daily" <?php echo $typeFilter === 'daily' ? 'selected' : ''; ?>><?php echo t('daily'); ?></option>
                        <option value="monthly" <?php echo $typeFilter === 'monthly' ? 'selected' : ''; ?>><?php echo t('monthly'); ?></option>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>" placeholder="<?php echo t('from_date'); ?>">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>" placeholder="<?php echo t('to_date'); ?>">
                </div>
                <div class="col-12 col-md-8 col-lg-3">
                    <button type="submit" class="btn btn-outline-primary me-2"><?php echo t('search'); ?></button>
                    <a href="index.php" class="btn btn-outline-secondary"><?php echo t('clear'); ?></a>
                </div>
            </form>
        </div>
    </div>
    
        
    <style>
        /* Mobile: keep table data on single line */
        .table td, .table th {
            white-space: nowrap;
        }
        /* Allow customer name to wrap if too long */
        .table td:nth-child(5) {
            white-space: normal;
            min-width: 120px;
        }
    </style>
    
    <form id="batchPrintForm" method="POST" action="batch_print.php" target="_blank">
    <div class="mb-3 text-end">
        <button type="button" class="btn btn-primary" onclick="batchPrint()" id="printSelectedBtn" disabled>
            <i class="bi bi-printer me-2"></i><?php echo t('invoice_print_selected'); ?> (<span id="selectedCount">0</span>)
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle" id="invoicesTable">
            <thead class="table-light">
                <tr>
                    <th width="40" class="text-center">
                        <input type="checkbox" class="form-check-input" id="selectAll" onclick="toggleSelectAll()">
                    </th>
                    <th class="text-center">
                        <a href="<?php echo buildSortUrl('invoice_number', $sortBy, $sortOrder, $search, $typeFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('invoice_number'); ?><?php echo getSortIcon('invoice_number', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th class="text-center">
                        <a href="<?php echo buildSortUrl('invoice_type', $sortBy, $sortOrder, $search, $typeFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('type'); ?><?php echo getSortIcon('invoice_type', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th class="text-center">
                        <a href="<?php echo buildSortUrl('room_number', $sortBy, $sortOrder, $search, $typeFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('room'); ?><?php echo getSortIcon('room_number', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th class="text-center">
                        <a href="<?php echo buildSortUrl('tenant_name', $sortBy, $sortOrder, $search, $typeFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('customer'); ?><?php echo getSortIcon('tenant_name', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th class="text-center">
                        <a href="<?php echo buildSortUrl('created_at', $sortBy, $sortOrder, $search, $typeFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('date'); ?><?php echo getSortIcon('created_at', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th class="text-center">
                        <a href="<?php echo buildSortUrl('grand_total', $sortBy, $sortOrder, $search, $typeFilter, $dateFrom, $dateTo); ?>" style="text-decoration: none; color: inherit;">
                            <?php echo t('grand_total'); ?><?php echo getSortIcon('grand_total', $sortBy, $sortOrder); ?>
                        </a>
                    </th>
                    <th width="100" class="text-center"><?php echo t('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input invoice-checkbox" name="invoice_ids[]" value="<?php echo $invoice['id']; ?>" onclick="updateSelectedCount()">
                    </td>
                    <td class="text-center"><?php echo $invoice['invoice_number']; ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?php echo $invoice['invoice_type'] === 'daily' ? 'info' : 'primary'; ?>">
                            <?php echo $invoice['invoice_type'] === 'daily' ? t('daily') : t('monthly'); ?>
                        </span>
                    </td>
                    <td class="text-center"><?php echo $invoice['room_number']; ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($invoice['tenant_name'] ?? 'N/A'); ?></td>
                    <td class="text-center"><?php echo formatDate($invoice['created_at']); ?></td>
                    <td class="text-center fw-bold"><?php echo formatCurrency($invoice['grand_total']); ?></td>
                    <td class="text-center">
                        <a href="view.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-primary" title="<?php echo t('view_details'); ?>">
                            <i class="bi bi-search"></i>
                        </a>
                        <a href="print.php?invoice_id=<?php echo $invoice['id']; ?>" target="_blank" class="btn btn-sm btn-secondary" title="<?php echo t('print'); ?>">
                            <i class="bi bi-printer"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($invoices)): ?>
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-receipt fs-1"></i>
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
                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $typeFilter ? '&type=' . $typeFilter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>&sort_by=<?php echo $sortBy; ?>&sort_order=<?php echo $sortOrder; ?>"><?php echo t('previous'); ?></a>
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
                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo $typeFilter ? '&type=' . $typeFilter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>&sort_by=<?php echo $sortBy; ?>&sort_order=<?php echo $sortOrder; ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor;

                    if ($endPage < $totalPages): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>

                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $typeFilter ? '&type=' . $typeFilter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $dateFrom ? '&date_from=' . $dateFrom : ''; ?><?php echo $dateTo ? '&date_to=' . $dateTo : ''; ?>&sort_by=<?php echo $sortBy; ?>&sort_order=<?php echo $sortOrder; ?>"><?php echo t('next'); ?></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
    </form>
</div>

<script>
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.invoice-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.invoice-checkbox:checked');
    const count = checkboxes.length;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('printSelectedBtn').disabled = count === 0;
}

function batchPrint() {
    const checkboxes = document.querySelectorAll('.invoice-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);
    
    if (ids.length === 0) {
        alert('<?php echo t("invoice_select_at_least_one"); ?>');
        return;
    }
    
    // Open print page with all selected invoice IDs
    const url = 'batch_print.php?ids=' + ids.join(',');
    window.open(url, '_blank');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
