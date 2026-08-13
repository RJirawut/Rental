<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireAdmin();

require_once __DIR__ . '/../../includes/pin-lockscreen-check.php';

// Only handle delete and fetch users if not showing lock screen
if (!$showLockScreen) {
    // Handle delete
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
        requireValidCsrfToken();
        $id = intval($_POST['id'] ?? 0);
        
        // Check if this is the last user
        ensureUserSecurityColumns();
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE is_active = 1 AND account_status = 'active'");
        $userCount = $stmt->fetch()['count'];
        
        if ($userCount <= 1) {
            setFlashMessage('error', t('cannot_delete_last_user'));
        } elseif ($id == $_SESSION['user_id']) {
            setFlashMessage('error', t('cannot_delete_self'));
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            if ($stmt->execute([$id])) {
                setFlashMessage('success', t('delete_success'));
                logActivity('delete_user', 'user', $id);
            } else {
                setFlashMessage('error', t('delete_error'));
            }
        }
        
        $redirectQuery = $_GET;
        header('Location: ' . $_SERVER['PHP_SELF'] . (!empty($redirectQuery) ? '?' . http_build_query($redirectQuery) : ''));
        exit;
    }

    // Search and paginate users so large user tables do not load into the page at once.
    $search = trim($_GET['search'] ?? '');
    $itemsPerPage = 50;
    $requestedPage = max(1, (int)($_GET['page'] ?? 1));
    $whereSql = "WHERE is_active = 1";
    $queryParams = [];

    if ($search !== '') {
        $whereSql .= " AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)";
        $searchValue = "%{$search}%";
        $queryParams = [$searchValue, $searchValue, $searchValue];
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users {$whereSql}");
    $countStmt->execute($queryParams);
    $totalUsers = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalUsers / $itemsPerPage));
    $page = min($requestedPage, $totalPages);
    $offset = ($page - 1) * $itemsPerPage;

    $stmt = $pdo->prepare("SELECT id, username, email, full_name, role, is_active, account_status, created_at
        FROM users {$whereSql} ORDER BY id ASC LIMIT {$itemsPerPage} OFFSET {$offset}");
    $stmt->execute($queryParams);
    $users = $stmt->fetchAll();
} else {
    $users = [];
    $search = '';
    $totalUsers = 0;
    $itemsPerPage = 50;
    $page = 1;
    $totalPages = 1;
}

$pageTitle = t('users');
include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <?php if (!$showLockScreen): ?>
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h5 class="mb-0 me-3"><i class="bi bi-shield-lock me-2"></i><?php echo t('user_list'); ?></h5>
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto">
            <span class="badge text-bg-light border rounded-pill px-3 py-2 text-dark">
                <?php echo t('all'); ?> <?php echo number_format($totalUsers); ?>
            </span>
            <a href="user-form.php" class="btn btn-primary flex-shrink-0">
                <i class="bi bi-plus-circle me-2"></i><?php echo t('add_user'); ?>
            </a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-2">
                <div class="col-12 col-md-8 col-lg-6">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo t('search'); ?> <?php echo t('username'); ?>, <?php echo t('full_name'); ?>, <?php echo t('email'); ?>">
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

    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th style="text-align: center;"><?php echo t('id'); ?></th>
                    <th style="text-align: center;"><?php echo t('username'); ?></th>
                    <th width="200" style="text-align: center; min-width: 200px;"><?php echo t('full_name'); ?></th>
                    <th style="text-align: center;"><?php echo t('email'); ?></th>
                    <th style="text-align: center;"><?php echo t('role'); ?></th>
                    <th style="text-align: center;"><?php echo t('status'); ?></th>
                    <th width="150" style="text-align: center; min-width: 150px;"><?php echo t('created_at'); ?></th>
                    <th width="120" style="text-align: center; min-width: 120px;"><?php echo t('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td style="text-align: center;"><?php echo $user['id']; ?></td>
                    <td style="text-align: center;"><?php echo htmlspecialchars($user['username']); ?></td>
                    <td style="text-align: center;"><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td style="text-align: center;"><?php echo htmlspecialchars($user['email']); ?></td>
                    <td style="text-align: center;">
                        <span class="badge bg-danger"><?php echo t('admin'); ?></span>
                    </td>
                    <td style="text-align: center;">
                        <?php
                        if (($user['account_status'] ?? 'active') === 'suspended') {
                            $statusClass = 'danger';
                            $statusLabel = t('suspended');
                        } else {
                            $statusClass = 'success';
                            $statusLabel = 'ใช้งาน';
                        }
                        ?>
                        <span class="badge bg-<?php echo $statusClass; ?>">
                            <?php echo $statusLabel; ?>
                        </span>
                    </td>
                    <td style="text-align: center;"><?php echo formatDate($user['created_at'], 'd/m/Y H:i'); ?></td>
                    <td style="text-align: center;">
                        <a href="user-form.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php if ($user['is_active'] && $user['id'] != $_SESSION['user_id']): ?>
                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_delete'); ?>')">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                <tr><td colspan="8" class="text-center py-4"><?php echo t('no_data'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <?php $paginationParams = $search !== '' ? ['search' => $search] : []; ?>
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <div class="text-muted">
                <?php echo t('showing'); ?> <?php echo (($page - 1) * $itemsPerPage) + 1; ?> - <?php echo min($page * $itemsPerPage, $totalUsers); ?> <?php echo t('of'); ?> <?php echo number_format($totalUsers); ?> <?php echo t('records'); ?>
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
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../../includes/pin-lockscreen-modal.php';
?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
