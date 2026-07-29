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

// Fetch tenant with room info
$stmt = $pdo->prepare("SELECT dt.*, r.room_number, rt.type_name, rt.price_daily 
    FROM daily_tenants dt 
    JOIN rooms r ON dt.room_id = r.id 
    JOIN room_types rt ON r.room_type_id = rt.id 
    WHERE dt.id = ?");
$stmt->execute([$id]);
$tenant = $stmt->fetch();

if (!$tenant) {
    setFlashMessage('error', t('not_found'));
    header('Location: index.php');
    exit;
}

$pageTitle = t('view_guest_info');

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo $pageTitle; ?></h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('room'); ?></label>
                            <p class="form-control-plaintext fw-bold"><?php echo $tenant['room_number']; ?> (<?php echo $tenant['type_name']; ?>)</p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('status'); ?></label>
                            <p class="form-control-plaintext">
                                <?php if ($tenant['status'] === 'checked_out'): ?>
                                    <span class="badge bg-secondary"><?php echo t('checked_out'); ?></span>
                                <?php elseif ($tenant['status'] === 'cancelled'): ?>
                                    <span class="badge bg-dark"><?php echo t('cancelled'); ?></span>
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
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('guest_name'); ?></label>
                            <p class="form-control-plaintext fw-bold"><?php echo htmlspecialchars($tenant['guest_name']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('phone'); ?></label>
                            <p class="form-control-plaintext"><?php echo $tenant['phone']; ?></p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('email'); ?></label>
                            <p class="form-control-plaintext"><?php echo $tenant['email'] ? htmlspecialchars($tenant['email']) : '-'; ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('num_guests'); ?></label>
                            <p class="form-control-plaintext"><?php echo $tenant['num_guests']; ?> <?php echo t('persons'); ?></p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label text-muted"><?php echo t('check_in_date'); ?></label>
                            <p class="form-control-plaintext"><?php echo formatDate($tenant['check_in_date']); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted"><?php echo t('check_out_date'); ?></label>
                            <p class="form-control-plaintext"><?php echo formatDate($tenant['check_out_date']); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted"><?php echo t('booking_date'); ?></label>
                            <p class="form-control-plaintext"><?php echo formatDate($tenant['created_at']); ?></p>
                        </div>
                    </div>

                    <?php
                    // Calculate early check-in charge
                    $earlyCheckinDays = 0;
                    $earlyCheckinCharge = 0;
                    if (!empty($tenant['actual_check_in_date']) && $tenant['actual_check_in_date'] < $tenant['check_in_date']) {
                        $actualIn = new DateTime($tenant['actual_check_in_date']);
                        $scheduledIn = new DateTime($tenant['check_in_date']);
                        $earlyCheckinDays = $actualIn->diff($scheduledIn)->days;
                        $earlyCheckinCharge = $earlyCheckinDays * $tenant['daily_rate'];
                    }

                    // Calculate late checkout charge
                    $lateCheckoutDays = 0;
                    $lateCheckoutCharge = 0;

                    if ($tenant['actual_check_out_date'] && $tenant['actual_check_out_date'] > $tenant['check_out_date']) {
                        $expected = new DateTime($tenant['check_out_date']);
                        $actual = new DateTime($tenant['actual_check_out_date']);
                        $lateCheckoutDays = $expected->diff($actual)->days;
                        $lateCheckoutCharge = $lateCheckoutDays * $tenant['daily_rate'];
                    } elseif (!$tenant['actual_check_out_date'] && date('Y-m-d') > $tenant['check_out_date'] && $tenant['status'] === 'checked_in') {
                        $expected = new DateTime($tenant['check_out_date']);
                        $today = new DateTime();
                        $lateCheckoutDays = $expected->diff($today)->days;
                        $lateCheckoutCharge = $lateCheckoutDays * $tenant['daily_rate'];
                    }

                    $totalExtraCharge = $earlyCheckinCharge + $lateCheckoutCharge;
                    $newTotal = (float) $tenant['total_amount'] + $totalExtraCharge;
                    ?>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label text-muted"><?php echo t('total_days'); ?></label>
                            <p class="form-control-plaintext"><?php echo $tenant['total_days']; ?> <?php echo t('nights'); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted"><?php echo t('daily_rate'); ?></label>
                            <p class="form-control-plaintext"><?php echo formatCurrency($tenant['daily_rate']); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-muted"><?php echo t('total_amount'); ?></label>
                            <p class="form-control-plaintext fw-bold text-primary"><?php echo formatCurrency($tenant['total_amount']); ?></p>
                        </div>
                    </div>

                    <?php if ($tenant['customer_tax_id']): ?>
                        <hr>
                        <h6 class="mb-3"><?php echo t('customer_tax_info'); ?></h6>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted"><?php echo t('customer_tax_id'); ?></label>
                                <p class="form-control-plaintext"><?php echo $tenant['customer_tax_id']; ?></p>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted"><?php echo t('customer_branch'); ?></label>
                                <p class="form-control-plaintext"><?php echo $tenant['customer_branch'] ?: '00000'; ?></p>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-12">
                                <label class="form-label text-muted"><?php echo t('customer_address'); ?></label>
                                <p class="form-control-plaintext"><?php echo nl2br(htmlspecialchars($tenant['customer_address'])); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($tenant['notes']): ?>
                        <hr>
                        <div class="row mb-3">
                            <div class="col-12">
                                <label class="form-label text-muted"><?php echo t('notes'); ?></label>
                                <p class="form-control-plaintext"><?php echo nl2br(htmlspecialchars($tenant['notes'])); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($tenant['actual_check_in_date'] || $tenant['actual_check_out_date']): ?>
                    <hr>
                    <div class="row mb-3">
                        <?php if ($tenant['actual_check_in_date']): ?>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('actual_check_in'); ?></label>
                            <p class="form-control-plaintext"><?php echo formatDate($tenant['actual_check_in_date']); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ($tenant['actual_check_out_date']): ?>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('actual_check_out'); ?></label>
                            <p class="form-control-plaintext"><?php echo formatDate($tenant['actual_check_out_date']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($earlyCheckinDays > 0): ?>
                        <hr class="border-warning">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label text-warning"><i class="bi bi-exclamation-triangle"></i> <?php echo t('invoice_early_checkin_charge'); ?></label>
                                <p class="form-control-plaintext text-warning fw-bold"><?php echo $earlyCheckinDays; ?> <?php echo t('days'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-warning"><?php echo t('extra_charge'); ?></label>
                                <p class="form-control-plaintext text-warning fw-bold"><?php echo formatCurrency($earlyCheckinCharge); ?></p>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-warning"><?php echo t('daily_rate'); ?> × <?php echo $earlyCheckinDays; ?> <?php echo t('days'); ?></label>
                                <p class="form-control-plaintext text-warning fw-bold"><?php echo formatCurrency($tenant['daily_rate']); ?> × <?php echo $earlyCheckinDays; ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($lateCheckoutDays > 0): ?>
                        <hr class="border-danger">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label text-danger"><i class="bi bi-exclamation-triangle"></i> <?php echo t('invoice_late_checkout_charge'); ?></label>
                                <p class="form-control-plaintext text-danger fw-bold"><?php echo $lateCheckoutDays; ?> <?php echo t('days'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-danger"><?php echo t('extra_charge'); ?></label>
                                <p class="form-control-plaintext text-danger fw-bold"><?php echo formatCurrency($lateCheckoutCharge); ?></p>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-danger"><?php echo t('daily_rate'); ?> × <?php echo $lateCheckoutDays; ?> <?php echo t('days'); ?></label>
                                <p class="form-control-plaintext text-danger fw-bold"><?php echo formatCurrency($tenant['daily_rate']); ?> × <?php echo $lateCheckoutDays; ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($totalExtraCharge > 0): ?>
                        <hr class="border-danger">
                        <div class="row mb-3">
                            <div class="col-md-4">
                            </div>
                            <div class="col-md-4">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-danger"><i class="bi bi-calculator"></i> <?php echo t('new_total_amount'); ?></label>
                                <p class="form-control-plaintext text-danger fw-bold fs-5"><?php echo formatCurrency($newTotal); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-footer text-center">
                    <a href="form.php?id=<?php echo $tenant['id']; ?>" class="btn btn-warning">
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