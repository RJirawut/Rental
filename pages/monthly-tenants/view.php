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
$stmt = $pdo->prepare("SELECT mt.*, r.room_number, rt.type_name 
    FROM monthly_tenants mt 
    JOIN rooms r ON mt.room_id = r.id 
    JOIN room_types rt ON r.room_type_id = rt.id 
    WHERE mt.id = ?");
$stmt->execute([$id]);
$tenant = $stmt->fetch();

if (!$tenant) {
    setFlashMessage('error', t('not_found'));
    header('Location: index.php');
    exit;
}

// Get billing month from URL if provided, otherwise use current month
$billingMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $billingMonth)) {
    $billingMonth = date('Y-m');
}

// Get outstanding (unpaid) bills for this tenant
$outstanding = getOutstandingBills($id);
$outstandingBills = $outstanding['bills'];
$totalOutstanding = $outstanding['total'];

$pageTitle = t('tenant_info');

include __DIR__ . '/../../includes/header.php';

// Calculate status
$status = calculateMonthlyStatus($tenant['contract_start'], $tenant['contract_end'], $tenant['status']);
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
                                <span class="badge bg-<?php echo $status['class']; ?>"><?php echo $status['label']; ?></span>
                            </p>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('tenant_name'); ?></label>
                            <p class="form-control-plaintext fw-bold"><?php echo htmlspecialchars($tenant['tenant_name']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('phone'); ?></label>
                            <p class="form-control-plaintext"><?php echo $tenant['phone']; ?></p>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label text-muted"><?php echo t('email'); ?></label>
                            <p class="form-control-plaintext"><?php echo $tenant['email'] ? htmlspecialchars($tenant['email']) : '-'; ?></p>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('contract_start'); ?></label>
                            <p class="form-control-plaintext"><?php echo formatDate($tenant['contract_start']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('contract_end'); ?></label>
                            <p class="form-control-plaintext"><?php echo formatDate($tenant['contract_end']); ?></p>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('monthly_rent'); ?></label>
                            <p class="form-control-plaintext fw-bold text-primary"><?php echo formatCurrency($tenant['monthly_rent']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('deposit'); ?></label>
                            <p class="form-control-plaintext"><?php echo formatCurrency($tenant['deposit']); ?></p>
                        </div>
                    </div>
                    
                    <?php if ($tenant['emergency_contact'] || $tenant['emergency_phone']): ?>
                    <hr>
                    <h6 class="mb-3"><?php echo t('emergency_contact_info'); ?></h6>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('emergency_contact_name'); ?></label>
                            <p class="form-control-plaintext"><?php echo $tenant['emergency_contact'] ? htmlspecialchars($tenant['emergency_contact']) : '-'; ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted"><?php echo t('emergency_contact_phone'); ?></label>
                            <p class="form-control-plaintext"><?php echo $tenant['emergency_phone'] ? $tenant['emergency_phone'] : '-'; ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
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

                    <?php if (!empty($outstandingBills)): ?>
                        <?php foreach ($outstandingBills as $bill): ?>
                        <?php $colorClass = $bill['is_overdue'] ? 'text-danger' : 'text-warning'; ?>
                        <?php $borderClass = $bill['is_overdue'] ? 'border-danger' : 'border-warning'; ?>
                        <hr class="<?php echo $borderClass; ?>">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label <?php echo $colorClass; ?>"><i class="bi bi-exclamation-triangle"></i> <?php echo $bill['is_overdue'] ? t('overdue') : t('unpaid'); ?></label>
                                <p class="form-control-plaintext <?php echo $colorClass; ?> fw-bold"><?php echo htmlspecialchars($bill['bill_month']); ?></p>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label <?php echo $colorClass; ?>"><?php echo $bill['is_overdue'] ? t('overdue_days') : t('due_date'); ?></label>
                                <p class="form-control-plaintext <?php echo $colorClass; ?> fw-bold">
                                    <?php if ($bill['is_overdue']): ?>
                                        <?php echo $bill['days_overdue']; ?> <?php echo t('days'); ?>
                                    <?php else: ?>
                                        <?php echo $bill['due_date_formatted']; ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label <?php echo $colorClass; ?>"><?php echo t('total_amount'); ?></label>
                                <p class="form-control-plaintext <?php echo $colorClass; ?> fw-bold"><?php echo formatCurrency($bill['total_amount']); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <?php if (count($outstandingBills) > 1): ?>
                        <hr class="border-danger">
                        <div class="row mb-3">
                            <div class="col-md-4"></div>
                            <div class="col-md-4"></div>
                            <div class="col-md-4">
                                <label class="form-label text-danger"><i class="bi bi-calculator"></i> <?php echo t('total_amount'); ?></label>
                                <p class="form-control-plaintext text-danger fw-bold fs-5"><?php echo formatCurrency($totalOutstanding); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
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
