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
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Get users
    $stmt = $pdo->query("SELECT * FROM users WHERE is_active = 1 ORDER BY id ASC");
    $users = $stmt->fetchAll();
} else {
    $users = [];
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
                <?php echo t('all'); ?> <?php echo number_format(count($users)); ?>
            </span>
            <a href="user-form.php" class="btn btn-primary flex-shrink-0">
                <i class="bi bi-plus-circle me-2"></i><?php echo t('add_user'); ?>
            </a>
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
    </div>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../../includes/pin-lockscreen-modal.php';
?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
