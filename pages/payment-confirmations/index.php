<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAdmin();
ensurePaymentConfirmationsTable();

$pageTitle = t('payment_confirmations');

// Handle Actions (Approve / Reject / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($action === 'approve' && $id > 0) {
        $result = approvePaymentConfirmation($id, $_SESSION['user_id'] ?? null, $adminNote);
        if ($result['success']) {
            setFlashMessage('success', t('payment_approved_success'));
        } else {
            setFlashMessage('error', $result['message']);
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }

    if ($action === 'reject' && $id > 0) {
        $result = rejectPaymentConfirmation($id, $_SESSION['user_id'] ?? null, $adminNote);
        if ($result['success']) {
            setFlashMessage('success', t('payment_rejected_success'));
        } else {
            setFlashMessage('error', $result['message']);
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }

    if ($action === 'delete' && $id > 0) {
        if (!isAdmin()) {
            setFlashMessage('error', t('access_denied'));
        } else {
            $stmt = $pdo->prepare("SELECT slip_image FROM payment_confirmations WHERE id = ?");
            $stmt->execute([$id]);
            $pc = $stmt->fetch();
            if ($pc && !empty($pc['slip_image'])) {
                $slipPath = __DIR__ . '/../../uploads/slips/' . $pc['slip_image'];
                if (file_exists($slipPath)) {
                    @unlink($slipPath);
                }
            }
            $stmtDel = $pdo->prepare("DELETE FROM payment_confirmations WHERE id = ?");
            $stmtDel->execute([$id]);
            logActivity('delete_payment_confirmation', 'payment_confirmations', $id, "Deleted payment confirmation #{$id}");
            setFlashMessage('success', t('delete_success'));
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
}

$settings = getSettings();
$enableDaily = (int) ($settings['enable_daily'] ?? 1);
$enableMonthly = (int) ($settings['enable_monthly'] ?? 1);

// Filter inputs
$statusFilter = trim($_GET['status'] ?? 'all');
if ($enableDaily && !$enableMonthly) {
    $typeFilter = 'daily';
} elseif (!$enableDaily && $enableMonthly) {
    $typeFilter = 'monthly';
} else {
    $typeFilter = trim($_GET['type'] ?? 'daily');
    if (!in_array($typeFilter, ['monthly', 'daily'], true)) {
        $typeFilter = 'daily';
    }
}
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Sort parameters
$sortBy = trim($_GET['sort_by'] ?? 'created_at');
$sortOrder = trim($_GET['sort_order'] ?? 'DESC');
$allowedSortColumns = ['room_number', 'tenant_name', 'bill_type', 'amount', 'transfer_date', 'status', 'created_at'];
if (!in_array($sortBy, $allowedSortColumns, true)) {
    $sortBy = 'created_at';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Helper function to build URL with sort parameters
function buildSortUrl($column, $currentSortBy, $currentSortOrder, $search, $statusFilter, $typeFilter) {
    $params = [];
    if ($column === $currentSortBy) {
        $newSortOrder = $currentSortOrder === 'ASC' ? 'DESC' : 'ASC';
    } else {
        $newSortOrder = in_array($column, ['room_number', 'tenant_name'], true) ? 'ASC' : 'DESC';
    }
    $params['sort_by'] = $column;
    $params['sort_order'] = $newSortOrder;
    if ($search) $params['search'] = $search;
    if ($statusFilter && $statusFilter !== 'all') $params['status'] = $statusFilter;
    if ($typeFilter && $typeFilter !== 'all') $params['type'] = $typeFilter;
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

// Query Statistics
$countPending = 0;
$countApproved = 0;
$countRejected = 0;
$totalPendingAmount = 0.0;

try {
    $stmtStats = $pdo->query("
        SELECT
            SUM(CASE WHEN status = 'pending_verify' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN status = 'pending_verify' THEN amount ELSE 0 END) AS pending_amount
        FROM payment_confirmations
    ");
    $stats = $stmtStats->fetch();
    if ($stats) {
        $countPending = (int)($stats['pending_count'] ?? 0);
        $countApproved = (int)($stats['approved_count'] ?? 0);
        $countRejected = (int)($stats['rejected_count'] ?? 0);
        $totalPendingAmount = (float)($stats['pending_amount'] ?? 0);
    }
} catch (Exception $e) {}

// Query Records
$data = getPaymentConfirmations($statusFilter, $typeFilter, $search, $limit, $offset, $sortBy, $sortOrder);
$records = $data['records'];
$totalRecords = $data['total'];
$totalPages = ceil($totalRecords / $limit);

$paymentFormUrl = BASE_URL . 'pages/payment-notice.php';

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
@media (max-width: 767.98px) {
    .stat-cards-scroll {
        display: flex;
        flex-wrap: nowrap;
        overflow-x: auto;
        gap: 0.35rem;
        padding-bottom: 0.4rem;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }
    .stat-cards-scroll::-webkit-scrollbar { display: none; }
    .stat-cards-scroll .stat-card-item {
        flex: 0 0 auto;
        min-width: 135px;
        width: calc(38% - 0.35rem);
    }
    .stat-cards-scroll .card-body {
        padding: 0.45rem 0.3rem;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
    }
    .stat-cards-scroll .repair-stat-card {
        min-height: 78px;
    }
    .stat-cards-scroll .card-body .d-flex {
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 100%;
    }
    .stat-cards-scroll .card-body .d-flex > div:first-child {
        width: 100%;
        text-align: center;
    }
    .stat-cards-scroll .card-body .repair-stat-label {
        font-size: 0.7rem;
        line-height: 1.25;
        margin-bottom: 0.2rem;
        word-break: break-word;
        white-space: normal;
    }
    .stat-cards-scroll .card-body .repair-stat-value {
        font-size: 0.9rem;
        line-height: 1;
        margin-bottom: 0;
        white-space: nowrap;
        text-align: center;
    }
    .stat-cards-scroll .card-body .repair-stat-icon { display: none; }
}
@media (min-width: 768px) {
    .stat-cards-scroll { display: flex; flex-wrap: wrap; gap: 0.75rem; }
    .stat-cards-scroll .stat-card-item { flex: 1 1 0; min-width: 0; }
}
</style>

<script>
function copyPaymentNoticeLink() {
    const url = <?php echo json_encode($paymentFormUrl); ?>;
    navigator.clipboard.writeText(url).then(() => {
        Swal.fire({
            icon: 'success',
            title: <?php echo json_encode(t('copied')); ?> + '!',
            text: <?php echo json_encode(t('copy_payment_form_link')); ?>,
            timer: 2000,
            showConfirmButton: false
        });
    }).catch(err => {
        prompt('Copy payment notice URL:', url);
    });
}

function viewSlip(btnOrUrl, tenantName, roomNumber, amount) {
    let imageUrl = btnOrUrl;
    let adminNote = '';
    let status = '';
    let statusLabel = '';
    if (typeof btnOrUrl === 'object' && btnOrUrl && btnOrUrl.dataset) {
        imageUrl = btnOrUrl.dataset.slipUrl;
        tenantName = btnOrUrl.dataset.tenant;
        roomNumber = btnOrUrl.dataset.room;
        amount = btnOrUrl.dataset.amount;
        adminNote = btnOrUrl.dataset.adminNote || '';
        status = btnOrUrl.dataset.status || '';
        statusLabel = btnOrUrl.dataset.statusLabel || '';
    }

    const slipImg = document.getElementById('slipImage');
    const downloadBtn = document.getElementById('slipDownloadBtn');

    if (slipImg) slipImg.src = imageUrl;
    if (downloadBtn) downloadBtn.href = imageUrl;

    // Populate structured details
    const detailRoom = document.getElementById('slipDetailRoom');
    const detailName = document.getElementById('slipDetailName');
    const detailAmount = document.getElementById('slipDetailAmount');
    const detailStatus = document.getElementById('slipDetailStatus');
    const detailReasonRow = document.getElementById('slipDetailReasonRow');
    const detailReason = document.getElementById('slipDetailReason');

    const bahtTxt = <?php echo json_encode(t('baht')); ?>;
    if (detailRoom) detailRoom.textContent = roomNumber || '-';
    if (detailName) detailName.textContent = tenantName || '-';
    if (detailAmount) detailAmount.textContent = amount + ' ' + bahtTxt;
    if (detailStatus) {
        detailStatus.textContent = statusLabel || '-';
        detailStatus.className = 'badge ' + (status === 'approved' ? 'bg-success' : status === 'rejected' ? 'bg-danger' : 'bg-warning text-dark');
    }
    if (detailReasonRow && detailReason) {
        if (status === 'rejected' && adminNote) {
            detailReasonRow.style.display = '';
            detailReason.textContent = adminNote;
        } else {
            detailReasonRow.style.display = 'none';
        }
    }

    const modalEl = document.getElementById('slipModal');
    if (modalEl) {
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
        }
    }
}

function confirmApprove(btnOrId, tenantName, amount) {
    let id = btnOrId;
    if (typeof btnOrId === 'object' && btnOrId && btnOrId.dataset) {
        id = btnOrId.dataset.id;
        tenantName = btnOrId.dataset.tenant;
        amount = btnOrId.dataset.amount;
    }

    const confirmMsg = <?php echo json_encode(t('approve_payment_confirm')); ?>;
    const bahtTxt = <?php echo json_encode(t('baht')); ?>;
    const approveTitle = <?php echo json_encode(t('approve')); ?>;
    const notePlaceholder = <?php echo json_encode(t('admin_note') . ' (' . t('optional') . ')'); ?>;
    const cancelTxt = <?php echo json_encode(t('cancel')); ?>;

    Swal.fire({
        title: approveTitle + ' ' + tenantName,
        text: confirmMsg + ' (' + amount + ' ' + bahtTxt + ')',
        icon: 'question',
        input: 'text',
        inputPlaceholder: notePlaceholder,
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: approveTitle,
        cancelButtonText: cancelTxt
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('actionInput').value = 'approve';
            document.getElementById('idInput').value = id;
            document.getElementById('noteInput').value = result.value || '';
            document.getElementById('actionForm').submit();
        }
    });
}

function confirmReject(btnOrId, tenantName) {
    let id = btnOrId;
    if (typeof btnOrId === 'object' && btnOrId && btnOrId.dataset) {
        id = btnOrId.dataset.id;
        tenantName = btnOrId.dataset.tenant;
    }

    const rejectTitle = <?php echo json_encode(t('reject')); ?>;
    const rejectConfirmMsg = <?php echo json_encode(t('reject_payment_confirm')); ?>;
    const rejectionReasonPlaceholder = <?php echo json_encode(t('rejection_reason')); ?>;
    const enterReasonErr = <?php echo json_encode(t('enter_rejection_reason')); ?>;
    const cancelTxt = <?php echo json_encode(t('cancel')); ?>;

    Swal.fire({
        title: rejectTitle + ' ' + tenantName,
        text: rejectConfirmMsg,
        icon: 'warning',
        input: 'text',
        inputPlaceholder: rejectionReasonPlaceholder,
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: rejectTitle,
        cancelButtonText: cancelTxt,
        inputValidator: (value) => {
            if (!value || !value.trim()) {
                return enterReasonErr;
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('actionInput').value = 'reject';
            document.getElementById('idInput').value = id;
            document.getElementById('noteInput').value = result.value || '';
            document.getElementById('actionForm').submit();
        }
    });
}

function confirmDelete(btnOrId) {
    let id = btnOrId;
    if (typeof btnOrId === 'object' && btnOrId && btnOrId.dataset) {
        id = btnOrId.dataset.id;
    }

    const deleteTitle = <?php echo json_encode(t('confirm_delete')); ?>;
    const cannotUndoMsg = <?php echo json_encode(t('cannot_undo')); ?>;
    const deleteBtnTxt = <?php echo json_encode(t('delete')); ?>;
    const cancelTxt = <?php echo json_encode(t('cancel')); ?>;

    Swal.fire({
        title: deleteTitle,
        text: cannotUndoMsg,
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: deleteBtnTxt,
        cancelButtonText: cancelTxt
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('actionInput').value = 'delete';
            document.getElementById('idInput').value = id;
            document.getElementById('actionForm').submit();
        }
    });
}
</script>

<div class="content-wrapper app-page">
    <!-- Header Hero Card -->
    <div class="card repair-hero mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-0 flex-wrap gap-2 w-100">
                <div>
                    <h5 class="mb-0 fw-bold"><i class="bi bi-credit-card-2-front me-2"></i><?php echo t('payment_confirmations'); ?></h5>
                </div>
                <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto">
                    <span class="badge text-bg-light border rounded-pill px-3 py-2 text-dark">
                        <?php echo t('all'); ?> <?php echo number_format($totalRecords); ?>
                    </span>
                    <button type="button" class="btn btn-outline-primary rounded-pill d-flex align-items-center" onclick="copyPaymentNoticeLink()">
                        <i class="bi bi-link-45deg me-1"></i><?php echo t('copy_payment_form_link'); ?>
                    </button>
                    <a href="<?php echo htmlspecialchars($paymentFormUrl); ?>" target="_blank" class="btn btn-primary rounded-pill d-flex align-items-center">
                        <i class="bi bi-box-arrow-up-right me-1"></i><?php echo t('open_payment_form'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="stat-cards-scroll mb-4">
        <div class="stat-card-item">
            <div class="card repair-stat-card repair-stat-pending h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="repair-stat-label"><?php echo t('pending_verify'); ?></div>
                            <div class="repair-stat-value"><?php echo number_format($countPending); ?></div>
                        </div>
                        <div class="repair-stat-icon"><i class="bi bi-hourglass-split"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card repair-stat-card repair-stat-completed h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="repair-stat-label"><?php echo t('approved'); ?></div>
                            <div class="repair-stat-value"><?php echo number_format($countApproved); ?></div>
                        </div>
                        <div class="repair-stat-icon"><i class="bi bi-check2-circle"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card repair-stat-card repair-stat-rejected h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="repair-stat-label"><?php echo t('rejected'); ?></div>
                            <div class="repair-stat-value"><?php echo number_format($countRejected); ?></div>
                        </div>
                        <div class="repair-stat-icon"><i class="bi bi-x-circle"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card repair-stat-card repair-stat-progress h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="repair-stat-label"><?php echo t('grand_total'); ?></div>
                            <div class="repair-stat-value" style="font-size: 1.5rem;"><?php echo formatCurrency($totalPendingAmount); ?> <?php echo t('baht'); ?></div>
                        </div>
                        <div class="repair-stat-icon"><i class="bi bi-cash-stack"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Type Tabs (Modern Segmented Control) -->
    <style>
        .pc-segmented-tabs {
            background-color: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 4px;
            border-radius: 50rem;
            display: inline-flex;
            gap: 4px;
        }
        .pc-tab-item {
            border-radius: 50rem;
            padding: 0.5rem 1.4rem;
            font-weight: 600;
            font-size: 0.95rem;
            color: #64748b;
            text-decoration: none;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            line-height: 1.25;
            border: none;
        }
        .pc-tab-item:hover {
            color: #1e293b;
            background-color: rgba(255, 255, 255, 0.6);
        }
        .pc-tab-item.active {
            background-color: #2563eb;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
        }
    </style>
    <?php if ($enableDaily && $enableMonthly): ?>
    <div class="mb-4">
        <div class="pc-segmented-tabs">
            <a class="pc-tab-item <?php echo $typeFilter === 'daily' ? 'active' : ''; ?>" href="?type=daily<?php echo $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">
                <i class="bi bi-calendar-day me-2"></i><?php echo t('daily_tenants'); ?>
            </a>
            <a class="pc-tab-item <?php echo $typeFilter === 'monthly' ? 'active' : ''; ?>" href="?type=monthly<?php echo $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">
                <i class="bi bi-calendar-month me-2"></i><?php echo t('monthly_tenants'); ?>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Search & Filter -->
    <div class="card repair-toolbar-card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-center">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($typeFilter); ?>">
                <div class="col-12 col-sm-6 col-md-4">
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>><?php echo t('all_status'); ?></option>
                        <option value="pending_verify" <?php echo $statusFilter === 'pending_verify' ? 'selected' : ''; ?>><?php echo t('pending_verify'); ?></option>
                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>><?php echo t('approved'); ?></option>
                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>><?php echo t('rejected'); ?></option>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-5">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="<?php echo t('search'); ?>..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
                    </div>
                </div>
                <?php if ($statusFilter !== 'all' || $typeFilter !== 'all' || !empty($search)): ?>
                <div class="col-12 col-md-3 d-flex align-items-center">
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary w-100"><?php echo t('reset'); ?></a>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="card repair-table-card border-0 shadow-sm">

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3 text-nowrap text-center" style="width: 60px;">#</th>
                            <th class="text-nowrap text-center">
                                <a href="<?php echo buildSortUrl('room_number', $sortBy, $sortOrder, $search, $statusFilter, $typeFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('room'); ?><?php echo getSortIcon('room_number', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-nowrap text-center">
                                <a href="<?php echo buildSortUrl('tenant_name', $sortBy, $sortOrder, $search, $statusFilter, $typeFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('tenant'); ?><?php echo getSortIcon('tenant_name', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-nowrap text-center">
                                <a href="<?php echo buildSortUrl('amount', $sortBy, $sortOrder, $search, $statusFilter, $typeFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('payment_amount'); ?><?php echo getSortIcon('amount', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-nowrap text-center">
                                <a href="<?php echo buildSortUrl('created_at', $sortBy, $sortOrder, $search, $statusFilter, $typeFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('transfer_datetime'); ?><?php echo getSortIcon('created_at', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-nowrap text-center"><?php echo t('payment_slip'); ?></th>
                            <th class="text-nowrap text-center">
                                <a href="<?php echo buildSortUrl('status', $sortBy, $sortOrder, $search, $statusFilter, $typeFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('status'); ?><?php echo getSortIcon('status', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="pe-3 text-center text-nowrap" style="width: 160px;"><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    <?php echo t('no_payment_confirmations'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $index => $row): ?>
                                <?php
                                $statusBadgeClass = 'bg-warning text-dark';
                                $statusLabel = t('pending_verify');
                                if ($row['status'] === 'approved') {
                                    $statusBadgeClass = 'bg-success';
                                    $statusLabel = t('approved');
                                } elseif ($row['status'] === 'rejected') {
                                    $statusBadgeClass = 'bg-danger';
                                    $statusLabel = t('rejected');
                                }
                                $slipUrl = !empty($row['slip_image']) ? BASE_URL . 'uploads/slips/' . htmlspecialchars($row['slip_image']) : '';

                                $tenantNameJs = htmlspecialchars(json_encode($row['tenant_name']), ENT_QUOTES, 'UTF-8');
                                $roomNumJs = htmlspecialchars(json_encode($row['room_number'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $slipUrlJs = htmlspecialchars(json_encode($slipUrl), ENT_QUOTES, 'UTF-8');
                                $amountStrJs = htmlspecialchars(json_encode(formatCurrency($row['amount'])), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                <td class="ps-3 fw-bold text-muted text-center"><?php echo $offset + $index + 1; ?></td>
                                    <td class="text-nowrap text-center">
                                        <?php if (!empty($row['room_number'])): ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($row['room_number']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap text-center">
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($row['tenant_name']); ?></span>
                                    </td>
                                    <td class="text-nowrap text-center">
                                        <span class="fw-bold text-success"><?php echo formatCurrency($row['amount']); ?> <?php echo t('baht'); ?></span>
                                    </td>
                                    <td class="text-nowrap text-center">
                                        <?php if (!empty($row['created_at'])): ?>
                                            <div><i class="bi bi-calendar me-1"></i><?php echo formatDate($row['created_at']); ?></div>
                                            <div class="small text-muted"><i class="bi bi-clock me-1"></i><?php echo date('H:i', strtotime($row['created_at'])); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap text-center">
                                        <?php if ($slipUrl): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                data-slip-url="<?php echo htmlspecialchars($slipUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-tenant="<?php echo htmlspecialchars($row['tenant_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-room="<?php echo htmlspecialchars($row['room_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-amount="<?php echo htmlspecialchars(formatCurrency($row['amount']), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-admin-note="<?php echo htmlspecialchars($row['admin_note'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-status-label="<?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                                onclick="viewSlip(this)">
                                                <i class="bi bi-file-image me-1"></i><?php echo t('payment_slip'); ?>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small"><?php echo t('no_image'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap text-center">
                                        <span class="badge <?php echo $statusBadgeClass; ?> px-2 py-1">
                                            <?php echo $statusLabel; ?>
                                        </span>
                                        <?php if (!empty($row['verifier_name'])): ?>
                                            <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                                                <?php echo t('by'); ?>: <?php echo htmlspecialchars($row['verifier_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-3 text-center text-nowrap">
                                        <div class="repair-actions">


                                            <?php if ($row['status'] === 'pending_verify'): ?>
                                                <button type="button" class="btn btn-sm btn-success"
                                                    data-id="<?php echo (int)$row['id']; ?>"
                                                    data-tenant="<?php echo htmlspecialchars($row['tenant_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-amount="<?php echo htmlspecialchars(formatCurrency($row['amount']), ENT_QUOTES, 'UTF-8'); ?>"
                                                    onclick="confirmApprove(this)"
                                                    title="<?php echo t('approve'); ?>">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger"
                                                    data-id="<?php echo (int)$row['id']; ?>"
                                                    data-tenant="<?php echo htmlspecialchars($row['tenant_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    onclick="confirmReject(this)"
                                                    title="<?php echo t('reject'); ?>">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (isAdmin()): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                    data-id="<?php echo (int)$row['id']; ?>"
                                                    onclick="confirmDelete(this)"
                                                    title="<?php echo t('delete'); ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php renderUnifiedPagination($page, $totalPages, (int)$totalRecords, $limit, [
            'status' => $statusFilter,
            'type' => $typeFilter,
            'search' => $search,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ], t('payment_confirmations')); ?>
    </div>
</div>

<!-- Slip Modal -->
<div class="modal fade" id="slipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="slipModalTitle"><i class="bi bi-image me-2"></i><?php echo t('payment_slip'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-3">
                    <img id="slipImage" src="" alt="Slip" class="img-fluid rounded shadow-sm" style="max-height: 40vh; object-fit: contain;">
                </div>
                <table class="table table-sm table-borderless mb-0" style="font-size: 0.9rem;">
                    <tbody>
                        <tr>
                            <td class="text-muted fw-semibold" style="width: 120px;"><i class="bi bi-door-open me-1"></i>หมายเลขห้อง:</td>
                            <td id="slipDetailRoom" class="fw-bold">-</td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold"><i class="bi bi-person me-1"></i>ชื่อ:</td>
                            <td id="slipDetailName" class="fw-bold">-</td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold"><i class="bi bi-cash-coin me-1"></i>ยอดโอน:</td>
                            <td id="slipDetailAmount" class="fw-bold text-success">-</td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold"><i class="bi bi-flag me-1"></i>สถานะ:</td>
                            <td><span id="slipDetailStatus" class="badge bg-warning text-dark">-</span></td>
                        </tr>
                        <tr id="slipDetailReasonRow" style="display: none;">
                            <td class="text-muted fw-semibold"><i class="bi bi-chat-left-text me-1"></i>สาเหตุ:</td>
                            <td id="slipDetailReason" class="text-danger fw-bold">-</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <a id="slipDownloadBtn" href="" download class="btn btn-outline-primary"><i class="bi bi-download me-1"></i> <?php echo t('download'); ?></a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Action Form (Hidden) -->
<form id="actionForm" method="POST" style="display: none;">
    <?php echo csrfInput(); ?>
    <input type="hidden" name="action" id="actionInput" value="">
    <input type="hidden" name="id" id="idInput" value="">
    <input type="hidden" name="admin_note" id="noteInput" value="">
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
