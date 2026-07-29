<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    setFlashMessage('error', t('not_found'));
    header('Location: index.php');
    exit;
}

syncRoomStatuses();

$stmt = $pdo->prepare("SELECT r.*, rt.type_name, rt.type_name_en, rt.price_daily, rt.price_monthly,
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
        ) as reserved_guest_phone
        FROM rooms r
        JOIN room_types rt ON r.room_type_id = rt.id
        WHERE r.id = ?");
$stmt->execute([$id]);
$room = $stmt->fetch();

if (!$room) {
    setFlashMessage('error', t('not_found'));
    header('Location: index.php');
    exit;
}

$pageTitle = t('view_details');

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-door-open me-2"></i>
                        <?php echo t('room'); ?> <?php echo htmlspecialchars($room['room_number']); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('room_number'); ?></label>
                            <p class="form-control-plaintext fw-bold"><?php echo htmlspecialchars($room['room_number']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('floor'); ?></label>
                            <p class="form-control-plaintext fw-bold"><?php echo (int) $room['floor']; ?></p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('type'); ?></label>
                            <p class="form-control-plaintext fw-bold">
                                <?php echo $lang === 'en' ? htmlspecialchars($room['type_name_en'] ?? $room['type_name']) : htmlspecialchars($room['type_name']); ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('status'); ?></label>
                            <p class="form-control-plaintext"><?php echo getRoomStatusBadge($room['status']); ?></p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('daily_rate'); ?></label>
                            <p class="form-control-plaintext fw-bold text-primary"><?php echo formatCurrency($room['price_daily']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('monthly_rate'); ?></label>
                            <p class="form-control-plaintext fw-bold text-success"><?php echo formatCurrency($room['price_monthly']); ?></p>
                        </div>
                    </div>

                    <?php if ($room['status'] === 'reserved' && !empty($room['reserved_guest_name'])): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label text-muted"><?php echo ($lang === 'en') ? "Today's reservation" : 'การจองวันนี้'; ?></label>
                            <p class="form-control-plaintext">
                                <?php echo htmlspecialchars($room['reserved_guest_name']); ?>
                                <?php if (!empty($room['reserved_guest_phone'])): ?>
                                 - <?php echo htmlspecialchars($room['reserved_guest_phone']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($room['notes'])): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label text-muted"><?php echo t('notes'); ?></label>
                            <p class="form-control-plaintext"><?php echo nl2br(htmlspecialchars($room['notes'])); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card-footer text-center">
                    <a href="form.php?id=<?php echo $room['id']; ?>" class="btn btn-warning">
                        <i class="bi bi-pencil"></i> <?php echo t('edit'); ?>
                    </a>
                    <a href="index.php" class="btn btn-secondary ms-2">
                        <i class="bi bi-arrow-left"></i> <?php echo t('back'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
