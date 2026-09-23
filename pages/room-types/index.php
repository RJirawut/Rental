<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Set charset to UTF-8
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

requireLogin();

// Run the one-time compatibility sync for installations upgraded in place.
// It only inserts a baseline for room types that have no price history yet.
try {
    ensureRoomTypePriceHistoryTable();
} catch (Throwable $e) {
    error_log('Room type price history sync failed: ' . $e->getMessage());
}

$pageTitle = t('room_types');
$search = trim($_GET['search'] ?? '');
$itemsPerPage = 50;
$requestedPage = max(1, (int)($_GET['page'] ?? 1));

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireValidCsrfToken();
    $id = intval($_POST['id'] ?? 0);
    
    // Check if room type is in use
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM rooms WHERE room_type_id = ?");
    $stmt->execute([$id]);
    $roomCount = $stmt->fetch()['count'];
    
    if ($roomCount > 0) {
        setFlashMessage('error', t('cannot_delete_room_type_in_use'));
    } else {
        $stmt = $pdo->prepare("DELETE FROM room_types WHERE id = ?");
        if ($stmt->execute([$id])) {
            setFlashMessage('success', t('delete_success'));
            logActivity('delete_room_type', 'room_type', $id);
        } else {
            setFlashMessage('error', t('delete_error'));
        }
    }
    
    $redirectQuery = $_GET;
    unset($redirectQuery['page']);
    header('Location: ' . $_SERVER['PHP_SELF'] . (!empty($redirectQuery) ? '?' . http_build_query($redirectQuery) : ''));
    exit;
}

// Search and paginate room types to keep the catalog lightweight as it grows.
$whereSql = '';
$queryParams = [];
if ($search !== '') {
    $whereSql = "WHERE (rt.type_name LIKE ? OR rt.type_name_en LIKE ? OR rt.description LIKE ? OR rt.description_en LIKE ?)";
    $searchValue = "%{$search}%";
    $queryParams = [$searchValue, $searchValue, $searchValue, $searchValue];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM room_types rt {$whereSql}");
$countStmt->execute($queryParams);
$totalRoomTypes = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRoomTypes / $itemsPerPage));
$page = min($requestedPage, $totalPages);
$offset = ($page - 1) * $itemsPerPage;

$stmt = $pdo->prepare("
    SELECT rt.id, rt.type_name, rt.type_name_en, rt.price_daily, rt.price_monthly,
           rt.description, rt.description_en,
           COALESCE(al_user.username, default_admin.username) AS creator_username
    FROM room_types rt
    LEFT JOIN (
        SELECT al.entity_id, u.username
        FROM activity_logs al
        INNER JOIN (
            SELECT entity_id, MAX(id) AS id
            FROM activity_logs
            WHERE entity_type IN ('room_type', 'room_types')
            GROUP BY entity_id
        ) latest_al ON latest_al.id = al.id
        LEFT JOIN users u ON al.user_id = u.id
    ) al_user ON al_user.entity_id = rt.id
    LEFT JOIN (
        SELECT username FROM users WHERE is_active = 1 AND role = 'admin' ORDER BY id ASC LIMIT 1
    ) default_admin ON 1=1
    {$whereSql}
    ORDER BY rt.id ASC
    LIMIT {$itemsPerPage} OFFSET {$offset}
");
$stmt->execute($queryParams);
$roomTypes = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h5 class="mb-0 me-3"><i class="bi bi-layers me-2"></i><?php echo t('room_types'); ?></h5>
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto">
            <span class="badge text-bg-light border rounded-pill px-3 py-2 text-dark">
                <?php echo t('all'); ?> <?php echo number_format($totalRoomTypes); ?>
            </span>
            <a href="form.php" class="btn btn-primary flex-shrink-0 d-flex align-items-center">
                <i class="bi bi-plus-circle me-3"></i><?php echo t('add_new'); ?>
            </a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-2">
                <div class="col-12 col-md-8 col-lg-6">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo t('search'); ?> <?php echo t('room_type'); ?>">
                    </div>
                </div>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-outline-primary w-100"><?php echo t('search'); ?></button>
                </div>
                <?php if ($search !== ''): ?>
                <div class="col-12 col-md-auto">
                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-outline-secondary w-100"><?php echo t('clear'); ?></a>
                </div>
                <?php endif; ?>
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
    </style>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 20%;" class="text-center"><?php echo t('type'); ?></th>
                    <th style="width: 12%;" class="text-center"><?php echo t('daily'); ?></th>
                    <th style="width: 12%;" class="text-center"><?php echo t('monthly'); ?></th>
                    <th style="width: 40%;" class="text-center"><?php echo t('description'); ?></th>
                    <th style="width: 140px;" class="text-center"><?php echo t('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roomTypes as $type):
                    $typeName = ($_SESSION['lang'] === 'en' && !empty($type['type_name_en'])) ? $type['type_name_en'] : $type['type_name'];
                    $description = '';
                    if ($_SESSION['lang'] === 'en' && !empty($type['description_en'])) {
                        $description = $type['description_en'];
                    } elseif (!empty($type['description'])) {
                        $description = $type['description'];
                    }
                ?>
                <tr>
                    <td class="text-center"><strong><?php echo htmlspecialchars($typeName, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td class="text-center"><?php echo formatCurrency($type['price_daily']); ?></td>
                    <td class="text-center"><?php echo formatCurrency($type['price_monthly']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-center">
                        <div class="btn-group">
                            <a href="form.php?id=<?php echo $type['id']; ?>" class="btn btn-sm btn-warning" title="<?php echo t('edit'); ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_delete'); ?>')">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="<?php echo t('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                        <?php if (!empty($type['creator_username'])): ?>
                        <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                            <?php echo t('by'); ?>: <?php echo htmlspecialchars($type['creator_username']); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($roomTypes)): ?>
                <tr>
                    <td colspan="5" class="text-center py-5 text-muted">
                        <i class="bi bi-layers fs-1"></i>
                        <p class="mt-3 mb-0"><?php echo t('no_room_types_found'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <?php $paginationParams = $search !== '' ? ['search' => $search] : []; ?>
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <div class="text-muted">
                <?php echo t('showing'); ?> <?php echo (($page - 1) * $itemsPerPage) + 1; ?> - <?php echo min($page * $itemsPerPage, $totalRoomTypes); ?> <?php echo t('of'); ?> <?php echo number_format($totalRoomTypes); ?> <?php echo t('records'); ?>
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
