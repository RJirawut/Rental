<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
ensureRepairRequestsTable();

$pageTitle = t('repairs');

// Handle Status Update & Note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    requireValidCsrfToken();
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'pending';
    $adminNote = trim($_POST['admin_note'] ?? '');

    if (!in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'], true)) {
        $status = 'pending';
    }

    // Get old status and repair data for email
    $stmt = $pdo->prepare("SELECT status, ticket_number, room_number, reporter_name, phone, email, title, description FROM repair_requests WHERE id = ?");
    $stmt->execute([$id]);
    $repairData = $stmt->fetch();
    $oldStatus = $repairData['status'] ?? 'pending';

    $resolvedAt = ($status === 'completed') ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare("
        UPDATE repair_requests 
        SET status = ?, admin_note = ?, resolved_at = IF(? IS NOT NULL, ?, resolved_at)
        WHERE id = ?
    ");
    if ($stmt->execute([$status, $adminNote, $resolvedAt, $resolvedAt, $id])) {
        logActivity('update_repair_status', 'repair_requests', $id, "Status changed to {$status}");

        // Send email notification for completed or cancelled status
        if (($status === 'completed' || $status === 'cancelled') && $status !== $oldStatus) {
            if (!empty($repairData['email']) && isValidEmailFormat($repairData['email'])) {
                sendRepairStatusUpdateEmail($repairData, $oldStatus, $status, $lang);
            }
        }

        setFlashMessage('success', t('update_status_success'));
    } else {
        setFlashMessage('error', t('update_status_error'));
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireValidCsrfToken();
    $id = (int)($_POST['id'] ?? 0);

    // Get image to delete
    $stmt = $pdo->prepare("SELECT image FROM repair_requests WHERE id = ?");
    $stmt->execute([$id]);
    $img = $stmt->fetchColumn();

    $stmt = $pdo->prepare("DELETE FROM repair_requests WHERE id = ?");
    if ($stmt->execute([$id])) {
        if (!empty($img) && file_exists(__DIR__ . '/../../' . $img)) {
            @unlink(__DIR__ . '/../../' . $img);
        }
        logActivity('delete_repair', 'repair_requests', $id);
        setFlashMessage('success', t('delete_success'));
    } else {
        setFlashMessage('error', t('delete_error'));
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// Filters & Pagination
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Sorting
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'DESC';
$allowedSortColumns = [
    'ticket_number',
    'room_number',
    'reporter_name',
    'phone',
    'title',
    'description',
    'priority',
    'status',
    'created_at'
];
if (!in_array($sortBy, $allowedSortColumns, true)) {
    $sortBy = 'created_at';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Helper function to build URL with sort parameters
function buildSortUrl($column, $currentSortBy, $currentSortOrder, $search, $statusFilter) {
    $params = [];
    if ($column === $currentSortBy) {
        $newSortOrder = $currentSortOrder === 'ASC' ? 'DESC' : 'ASC';
    } else {
        $newSortOrder = in_array($column, ['ticket_number', 'room_number', 'reporter_name', 'title'], true) ? 'ASC' : 'DESC';
    }
    $params['sort_by'] = $column;
    $params['sort_order'] = $newSortOrder;
    
    if ($search) $params['search'] = $search;
    if ($statusFilter && $statusFilter !== 'all') $params['status'] = $statusFilter;
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

$where = "1=1";
$params = [];

if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'in_progress', 'completed', 'cancelled'], true)) {
    $where .= " AND rr.status = ?";
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where .= " AND (rr.ticket_number LIKE ? OR rr.room_number LIKE ? OR rr.reporter_name LIKE ? OR rr.phone LIKE ? OR rr.title LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

// Count total
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM repair_requests rr WHERE $where");
$stmtCount->execute($params);
$totalRecords = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $limit));
$page = min($page, $totalPages);

if ($sortBy === 'created_at' && !isset($_GET['sort_by'])) {
    $orderBy = "ORDER BY CASE rr.status
        WHEN 'pending' THEN 1 
        WHEN 'in_progress' THEN 2 
        WHEN 'completed' THEN 3 
        WHEN 'cancelled' THEN 4 
    END, rr.created_at DESC";
} else {
    $orderBy = "ORDER BY rr.$sortBy $sortOrder";
}

// Fetch records
$stmt = $pdo->prepare("
    SELECT rr.*, latest_user.username AS operator_name
    FROM repair_requests rr
    LEFT JOIN (
        SELECT al.entity_id, al.user_id
        FROM activity_logs al
        INNER JOIN (
            SELECT entity_id, MAX(id) AS latest_id
            FROM activity_logs
            WHERE entity_type = 'repair_requests'
            GROUP BY entity_id
        ) latest_activity ON latest_activity.latest_id = al.id
    ) latest_repair_activity ON latest_repair_activity.entity_id = rr.id
    LEFT JOIN users latest_user ON latest_user.id = latest_repair_activity.user_id
    WHERE $where
    $orderBy
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for badges
$counts = $pdo->query("
    SELECT status, COUNT(*) as cnt 
    FROM repair_requests 
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$pendingCount = (int)($counts['pending'] ?? 0);
$inProgressCount = (int)($counts['in_progress'] ?? 0);
$completedCount = (int)($counts['completed'] ?? 0);
$cancelledCount = (int)($counts['cancelled'] ?? 0);

$publicUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');

include __DIR__ . '/../../includes/header.php';
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

<div class="content-wrapper repair-page">
    <div class="card repair-hero mb-4 border-0 shadow-sm">
        <div class="card-body p-4 p-lg-5">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div class="d-flex justify-content-between align-items-center mb-0 flex-wrap gap-2 w-100">
        <div>
            <h5 class="mb-1"><i class="bi bi-tools me-2"></i><?php echo t('repairs'); ?></h5>
        </div>
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto">
            <span class="badge text-bg-light border rounded-pill px-3 py-2 text-dark">
                <?php echo t('all'); ?> <?php echo number_format($totalRecords); ?>
            </span>
            <button type="button" class="btn btn-outline-primary rounded-pill d-flex align-items-center" onclick="copyPublicLink('<?php echo htmlspecialchars($publicUrl); ?>')">
                <i class="bi bi-link-45deg me-1"></i><?php echo t('copy_repair_form_link'); ?>
            </button>
            <a href="<?php echo htmlspecialchars($publicUrl); ?>" target="_blank" class="btn btn-primary rounded-pill d-flex align-items-center">
                <i class="bi bi-box-arrow-up-right me-1"></i><?php echo t('open_repair_form'); ?>
            </a>
        </div>
    </div>

            </div>
        </div>
    </div>

    <div class="stat-cards-scroll mb-4">
        <div class="stat-card-item">
            <div class="card repair-stat-card repair-stat-pending h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="repair-stat-label"><?php echo t('status_pending'); ?></div>
                            <div class="repair-stat-value"><?php echo number_format($pendingCount); ?></div>
                        </div>
                        <div class="repair-stat-icon"><i class="bi bi-hourglass-split"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card repair-stat-card repair-stat-progress h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="repair-stat-label"><?php echo t('status_in_progress'); ?></div>
                            <div class="repair-stat-value"><?php echo number_format($inProgressCount); ?></div>
                        </div>
                        <div class="repair-stat-icon"><i class="bi bi-arrow-repeat"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card repair-stat-card repair-stat-completed h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="repair-stat-label"><?php echo t('status_completed'); ?></div>
                            <div class="repair-stat-value"><?php echo number_format($completedCount); ?></div>
                        </div>
                        <div class="repair-stat-icon"><i class="bi bi-check2-circle"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card repair-stat-card repair-stat-cancelled h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="repair-stat-label"><?php echo t('status_cancelled'); ?></div>
                            <div class="repair-stat-value"><?php echo number_format($cancelledCount); ?></div>
                        </div>
                        <div class="repair-stat-icon"><i class="bi bi-x-circle"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & Filter -->
    <div class="card repair-toolbar-card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="<?php echo t('search_repair_placeholder'); ?>" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-2">
                    <select name="status" class="form-select">
                        <option value="all"><?php echo t('all'); ?></option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>><?php echo t('status_pending'); ?></option>
                        <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>><?php echo t('status_in_progress'); ?></option>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>><?php echo t('status_completed'); ?></option>
                        <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>><?php echo t('status_cancelled'); ?></option>
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

    <div class="card repair-table-card mb-4">
        <div class="card-body">
            <!-- Table -->
            <div class="table-responsive">
                <table class="table table-hover align-middle repair-table">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center">#</th>
                            <th class="text-center text-nowrap">
                                <a href="<?php echo buildSortUrl('ticket_number', $sortBy, $sortOrder, $search, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('repair_id'); ?><?php echo getSortIcon('ticket_number', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-center text-nowrap">
                                <a href="<?php echo buildSortUrl('room_number', $sortBy, $sortOrder, $search, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('room'); ?><?php echo getSortIcon('room_number', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-nowrap">
                                <a href="<?php echo buildSortUrl('reporter_name', $sortBy, $sortOrder, $search, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('reporter'); ?><?php echo getSortIcon('reporter_name', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-center text-nowrap">
                                <a href="<?php echo buildSortUrl('phone', $sortBy, $sortOrder, $search, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('phone'); ?><?php echo getSortIcon('phone', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-nowrap">
                                <a href="<?php echo buildSortUrl('title', $sortBy, $sortOrder, $search, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('issue'); ?><?php echo getSortIcon('title', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-center text-nowrap">
                                <a href="<?php echo buildSortUrl('priority', $sortBy, $sortOrder, $search, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('urgency'); ?><?php echo getSortIcon('priority', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-center text-nowrap">
                                <a href="<?php echo buildSortUrl('status', $sortBy, $sortOrder, $search, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('status'); ?><?php echo getSortIcon('status', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-center text-nowrap">
                                <a href="<?php echo buildSortUrl('created_at', $sortBy, $sortOrder, $search, $statusFilter); ?>" style="text-decoration: none; color: inherit;">
                                    <?php echo t('report_date'); ?><?php echo getSortIcon('created_at', $sortBy, $sortOrder); ?>
                                </a>
                            </th>
                            <th class="text-center text-nowrap"><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($repairs)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">
                                    <i class="bi bi-tools fs-1"></i>
                                    <p class="mt-2 mb-0"><?php echo t('no_repair_requests'); ?></p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($repairs as $index => $r): ?>
                                <tr>
                                    <td class="text-center fw-bold text-muted px-2" style="width: 1%; white-space: nowrap;"><?php echo $offset + $index + 1; ?></td>
                                    <td class="text-center">
                                        <span class="font-monospace fw-bold"><?php echo htmlspecialchars($r['ticket_number']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php echo htmlspecialchars($r['room_number']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($r['reporter_name']); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo htmlspecialchars($r['phone']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($r['title']); ?>
                                        <?php if (!empty($r['image'])): ?>
                                            <a href="<?php echo BASE_URL . htmlspecialchars($r['image']); ?>" target="_blank" class="badge bg-light text-dark border text-decoration-none ms-1">
                                                <i class="bi bi-image"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        if ($r['priority'] === 'very_urgent') {
                                            echo '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>' . t('very_urgent') . '</span>';
                                        } elseif ($r['priority'] === 'urgent') {
                                            echo '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-circle me-1"></i>' . t('urgent') . '</span>';
                                        } else {
                                            echo '<span class="badge bg-info text-dark">' . t('normal') . '</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        if ($r['status'] === 'pending') {
                                            echo '<span class="badge bg-warning text-dark">' . t('status_pending') . '</span>';
                                        } elseif ($r['status'] === 'in_progress') {
                                            echo '<span class="badge bg-info text-dark">' . t('status_in_progress') . '</span>';
                                        } elseif ($r['status'] === 'completed') {
                                            echo '<span class="badge bg-success">' . t('status_completed') . '</span>';
                                        } elseif ($r['status'] === 'cancelled') {
                                            echo '<span class="badge bg-secondary">' . t('status_cancelled') . '</span>';
                                        }
                                        if (!empty($r['operator_name'])) {
                                            echo '<div class="small text-muted mt-1" style="font-size: 0.75rem;">' . t('by') . ': ' . htmlspecialchars($r['operator_name']) . '</div>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo formatDate($r['created_at'], 'd/m/Y H:i'); ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="repair-actions">
                                            <a href="view.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-primary" title="<?php echo t('view'); ?>">
                                                <i class="bi bi-search"></i>
                                            </a>
                                            <?php if ($r['status'] === 'pending'): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_in_progress') ?: 'เปลี่ยนสถานะเป็นกำลังดำเนินการ?'; ?>')">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                <input type="hidden" name="status" value="in_progress">
                                                <input type="hidden" name="admin_note" value="<?php echo htmlspecialchars($r['admin_note'] ?? ''); ?>">
                                                <button type="submit" class="btn btn-sm btn-info text-white" title="<?php echo t('status_in_progress'); ?>">
                                                    <i class="bi bi-arrow-repeat"></i>
                                                </button>
                                            </form>
                                            <?php elseif ($r['status'] === 'in_progress'): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_completed') ?: 'เปลี่ยนสถานะเป็นเสร็จสิ้น?'; ?>')">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                <input type="hidden" name="status" value="completed">
                                                <input type="hidden" name="admin_note" value="<?php echo htmlspecialchars($r['admin_note'] ?? ''); ?>">
                                                <button type="submit" class="btn btn-sm btn-success" title="<?php echo t('status_completed'); ?>">
                                                    <i class="bi bi-check2-circle"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if ($r['status'] === 'pending' || $r['status'] === 'in_progress'): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_cancelled'); ?>')">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                <input type="hidden" name="status" value="cancelled">
                                                <input type="hidden" name="admin_note" value="<?php echo htmlspecialchars($r['admin_note'] ?? ''); ?>">
                                                <button type="submit" class="btn btn-sm btn-secondary" title="<?php echo t('status_cancelled'); ?>">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_delete'); ?>')">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="<?php echo t('delete'); ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Single Modal for Update Status -->
            <div class="modal" id="modalRepair" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content text-start">
                        <form method="POST" action="" id="repairForm">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="id" id="repairId">

                            <div class="modal-header">
                                <h5 class="modal-title mb-0">
                                    <i class="bi bi-tools me-2"></i><?php echo t('manage_repair_request'); ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body">
                                <div class="p-3 bg-light rounded mb-3">
                                    <div class="row g-2">
                                        <div class="col-6"><strong><?php echo t('modal_room'); ?></strong> <span id="modalRoom"></span></div>
                                        <div class="col-6"><strong><?php echo t('modal_reporter'); ?></strong> <span id="modalReporter"></span></div>
                                        <div class="col-6"><strong><?php echo t('modal_phone'); ?></strong> <span id="modalPhone"></span></div>
                                        <div class="col-6"><strong><?php echo t('modal_date'); ?></strong> <span id="modalDate"></span></div>
                                        <div class="col-12 mt-2"><strong><?php echo t('modal_title'); ?></strong> <span id="modalTitle"></span></div>
                                        <div class="col-12"><strong><?php echo t('modal_description'); ?></strong><br><span id="modalDescription"></span></div>
                                        <div class="col-12 mt-2" id="modalImageContainer"></div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?php echo t('update_status_label'); ?></label>
                                    <select name="status" class="form-select" id="modalStatus">
                                        <option value="pending">รอดำเนินการ</option>
                                        <option value="in_progress">กำลังดำเนินการ</option>
                                        <option value="completed">เสร็จสิ้น</option>
                                        <option value="cancelled">ยกเลิกรายการ</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?php echo t('admin_note_label'); ?></label>
                                    <textarea name="admin_note" class="form-control" rows="3" id="modalAdminNote" placeholder="<?php echo t('admin_note_placeholder'); ?>"></textarea>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo t('save_data'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php renderUnifiedPagination($page, $totalPages, (int)$totalRecords, $limit, [
                'status' => $statusFilter,
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ], t('repairs')); ?>
        </div>
    </div>
</div>

<script>
// Store repair data for modal
const repairsData = <?php echo json_encode($repairs); ?>;
const BASE_URL = '<?php echo BASE_URL; ?>';

let repairModalInstance = null;

document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('modalRepair');
    if (!modalEl) return;

    // Bootstrap modals are more stable when attached directly to <body>.
    if (modalEl.parentElement !== document.body) {
        document.body.appendChild(modalEl);
    }

    repairModalInstance = bootstrap.Modal.getOrCreateInstance(modalEl, {
        backdrop: true,
        keyboard: true
    });
});

function copyPublicLink(url) {
    navigator.clipboard.writeText(url).then(() => {
        Swal.fire({
            icon: 'success',
            title: '<?php echo t('copy_link_success'); ?>',
            text: '<?php echo t('copy_link_text'); ?>',
            timer: 2000,
            showConfirmButton: false
        });
    });
}

function openRepairModal(id) {
    const repairId = Number(id);
    const repair = repairsData.find(r => Number(r.id) === repairId);
    if (!repair) return;

    document.getElementById('repairId').value = repair.id;
    document.getElementById('modalRoom').textContent = repair.room_number;
    document.getElementById('modalReporter').textContent = repair.reporter_name;
    document.getElementById('modalPhone').textContent = repair.phone;
    document.getElementById('modalDate').textContent = new Date(repair.created_at).toLocaleString('th-TH');
    document.getElementById('modalTitle').textContent = repair.title;
    document.getElementById('modalDescription').textContent = repair.description;
    document.getElementById('modalStatus').value = repair.status;
    document.getElementById('modalAdminNote').value = repair.admin_note || '';

    const imageContainer = document.getElementById('modalImageContainer');
    if (repair.image) {
        imageContainer.innerHTML = `<a href="${BASE_URL}${repair.image}" target="_blank"><img src="${BASE_URL}${repair.image}" alt="Repair photo" class="img-thumbnail" style="max-height: 150px;"></a>`;
    } else {
        imageContainer.innerHTML = '';
    }

    const modalEl = document.getElementById('modalRepair');
    const modalInstance = repairModalInstance || bootstrap.Modal.getOrCreateInstance(modalEl, {
        backdrop: true,
        keyboard: true
    });

    // Reuse the same modal instance so updates do not reinitialize the modal.
    if (!modalEl.classList.contains('show')) {
        modalInstance.show();
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
