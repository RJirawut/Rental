<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/promptpay_qr.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = trim($_GET['token'] ?? ($_POST['payment_token'] ?? ''));
if ($token === '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid payment token.']);
        exit;
    }
    die('Invalid payment link.');
}

ensurePaymentTokenColumn();
ensureDailyTenantPaymentTokenColumn();

$billType = null;
$paymentRecord = getUtilityBillByPaymentToken($token);
if ($paymentRecord) {
    $billType = 'monthly';
} else {
    $paymentRecord = getDailyTenantByPaymentToken($token);
    if ($paymentRecord) {
        $billType = 'daily';
    }
}

if (!$paymentRecord) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payment link is invalid or expired.']);
        exit;
    }
    die('Payment link is invalid or expired.');
}

$alreadyPaid = false;
$total = 0.0;
$paidDate = null;
$paidAmount = 0.0;

if ($billType === 'monthly') {
    $total = (float) $paymentRecord['rent_amount']
        + (float) $paymentRecord['water_amount']
        + (float) $paymentRecord['elec_amount']
        + (float) $paymentRecord['other_fees']
        - (float) $paymentRecord['discount'];
    $alreadyPaid = $paymentRecord['status'] === 'paid';
    $paidDate = $paymentRecord['paid_date'];
    $paidAmount = (float) ($paymentRecord['paid_amount'] ?: $total);
} else {
    $total = (float) ($paymentRecord['invoice_grand_total'] ?? $paymentRecord['total_amount']);
    $alreadyPaid = $paymentRecord['status'] !== 'pending_payment';
    $paidDate = date('Y-m-d');
    $paidAmount = $total;
}

// Handle AJAX Payment Submission (direct payment & status update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if ($alreadyPaid) {
        echo json_encode(['success' => false, 'message' => 'This bill has already been paid.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($billType === 'monthly') {
            $stmt = $pdo->prepare("
                UPDATE utility_bills
                SET status = 'paid', paid_date = CURDATE(), paid_amount = ?
                WHERE id = ?
            ");
            $stmt->execute([$total, $paymentRecord['id']]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE daily_tenants
                SET status = NULL, payment_deadline = NULL
                WHERE id = ?
            ");
            $stmt->execute([$paymentRecord['id']]);
            if (function_exists('syncRoomStatuses')) {
                syncRoomStatuses();
            }
        }

        $pdo->commit();
        logActivity('pay_bill_online', 'utility_bills', $paymentRecord['id'], "Paid {$billType} bill of {$total}");

        echo json_encode([
            'success' => true,
            'message' => ($_SESSION['lang'] ?? 'th') === 'en'
                ? 'Payment completed successfully!'
                : 'ชำระเงินเรียบร้อยแล้ว',
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

$error = null;
if ($billType === 'daily' && !$alreadyPaid && !empty($paymentRecord['payment_deadline'])
    && strtotime($paymentRecord['payment_deadline']) < time()) {
    $error = 'This payment link has expired.';
}

$settings = getSettings();
$dormName = $settings['dorm_name'] ?? 'Rental System';
$primaryColor = $settings['primary_color'] ?? '#0d6efd';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor)) {
    $primaryColor = '#0d6efd';
}
$isEnglish = ($_SESSION['lang'] ?? 'th') === 'en';
$promptpayId = $settings['promptpay_id'] ?? '';
$qrUrl = '';
if ($promptpayId && $total > 0 && !$error && !$alreadyPaid) {
    $payload = generatePromptPayPayload($promptpayId, $total);
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($payload);
}

$tenantName = $billType === 'daily'
    ? ($paymentRecord['guest_name'] ?? '')
    : ($paymentRecord['tenant_name'] ?? '');
$roomNumber = $paymentRecord['room_number'] ?? '';
$paymentHeading = $billType === 'daily'
    ? ($isEnglish ? 'Room Booking Payment' : 'ชำระค่าจองห้องพัก')
    : t('invoice_bill_for_month') . ' ' . formatBillMonth($paymentRecord['bill_month'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'th'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($dormName); ?> - <?php echo t('payment'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%); min-height: 100vh; display: flex; flex-direction: column; }
        .payment-container { max-width: 640px; margin: 2rem auto; width: 100%; padding: 0 1rem; flex: 1; }
        .header-bg { background-color: <?php echo htmlspecialchars($primaryColor); ?>; background-image: linear-gradient(135deg, rgba(255,255,255,.1), rgba(0,0,0,.1)); color: #fff; padding: 2rem 1rem 3rem; border-radius: 1rem 1rem 0 0; text-align: center; }
        .payment-card { background: #fff; border-radius: 1rem; box-shadow: 0 10px 30px rgba(0,0,0,.05); margin-top: -2rem; padding: 2rem; position: relative; }
        .qr-box { background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 1rem; padding: 1.5rem; text-align: center; margin: 1.5rem 0; }
        .qr-image { max-width: 250px; width: 100%; height: auto; margin-bottom: 1rem; border-radius: .5rem; }
        .bill-details { background: #f8f9fa; border-radius: .5rem; padding: 1.5rem; margin-bottom: 1.5rem; }
        .detail-row { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: .5rem; font-size: .95rem; }
        .detail-row.total { font-weight: 600; font-size: 1.1rem; border-top: 1px solid #dee2e6; padding-top: .5rem; margin-top: .5rem; color: <?php echo htmlspecialchars($primaryColor); ?>; }
        .success-icon { font-size: 5rem; color: #198754; animation: scaleIn .5s ease-in-out; }
        @keyframes scaleIn { from { transform: scale(0); } to { transform: scale(1); } }
        .footer { text-align: center; padding: 1rem; color: #6c757d; font-size: .875rem; margin-top: auto; }
        .logo-img { max-height: 80px; margin-bottom: 1rem; border-radius: .5rem; }
        .btn-primary { background-color: <?php echo htmlspecialchars($primaryColor); ?>; border-color: <?php echo htmlspecialchars($primaryColor); ?>; }
        .btn-primary:hover { filter: brightness(.9); }
    </style>
</head>
<body>
<div class="payment-container">
    <div class="header-bg">
        <?php if (!empty($settings['logo'])): ?>
            <img src="<?php echo htmlspecialchars(BASE_URL . 'assets/images/logo/' . $settings['logo']); ?>" alt="Logo" class="logo-img">
        <?php endif; ?>
        <h2 class="mb-0"><?php echo htmlspecialchars($dormName); ?></h2>
    </div>

    <div class="payment-card">
        <?php if ($error): ?>
            <div class="text-center py-5">
                <i class="bi bi-x-circle text-danger" style="font-size: 5rem;"></i>
                <h3 class="mt-3"><?php echo t('error'); ?></h3>
                <p class="text-muted"><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php elseif ($alreadyPaid): ?>
            <div class="text-center py-5">
                <i class="bi bi-check-circle-fill success-icon"></i>
                <h3 class="mt-3"><?php echo $isEnglish ? 'Payment Completed' : 'ชำระเงินเรียบร้อยแล้ว'; ?></h3>
                <?php if ($paidDate): ?><p class="text-muted"><?php echo t('paid_date'); ?>: <?php echo formatDate($paidDate); ?></p><?php endif; ?>
                <div class="mt-4"><h2 class="text-success mb-0"><?php echo formatCurrency($paidAmount ?: $total); ?> <?php echo t('baht'); ?></h2></div>
                <p class="text-muted small mt-3"><?php echo $isEnglish ? 'This bill has been paid and cannot be scanned again.' : 'รายการนี้ชำระเงินแล้ว ไม่สามารถสแกนชำระซ้ำได้'; ?></p>
            </div>
        <?php else: ?>
            <div class="text-center mb-4">
                <h4><?php echo htmlspecialchars($paymentHeading); ?></h4>
                <p class="text-muted mb-0"><?php echo t('room'); ?> <?php echo htmlspecialchars($roomNumber); ?> (<?php echo htmlspecialchars($tenantName); ?>)</p>
            </div>

            <div class="bill-details">
                <?php if ($billType === 'monthly'): ?>
                    <div class="detail-row"><span><?php echo t('rent'); ?></span><span><?php echo formatCurrency($paymentRecord['rent_amount']); ?> <?php echo t('baht'); ?></span></div>
                    <div class="detail-row"><span><?php echo t('water'); ?></span><span><?php echo formatCurrency($paymentRecord['water_amount']); ?> <?php echo t('baht'); ?></span></div>
                    <div class="detail-row"><span><?php echo t('electric'); ?></span><span><?php echo formatCurrency($paymentRecord['elec_amount']); ?> <?php echo t('baht'); ?></span></div>
                    <?php if ((float) $paymentRecord['other_fees'] > 0): ?><div class="detail-row"><span><?php echo t('other_fees'); ?></span><span><?php echo formatCurrency($paymentRecord['other_fees']); ?> <?php echo t('baht'); ?></span></div><?php endif; ?>
                    <?php if ((float) $paymentRecord['discount'] > 0): ?><div class="detail-row text-success"><span><?php echo t('discount'); ?></span><span>-<?php echo formatCurrency($paymentRecord['discount']); ?> <?php echo t('baht'); ?></span></div><?php endif; ?>
                <?php else: ?>
                    <div class="detail-row"><span><?php echo $isEnglish ? 'Stay' : 'เข้าพัก'; ?></span><span><?php echo formatDate($paymentRecord['check_in_date']); ?> - <?php echo formatDate($paymentRecord['check_out_date']); ?></span></div>
                    <div class="detail-row"><span><?php echo $isEnglish ? 'Nights' : 'จำนวนคืน'; ?></span><span><?php echo (int) $paymentRecord['total_days']; ?></span></div>
                <?php endif; ?>
                <div class="detail-row total"><span><?php echo t('grand_total'); ?></span><span><?php echo formatCurrency($total); ?> <?php echo t('baht'); ?></span></div>
            </div>

            <?php if ($qrUrl): ?>
                <div class="qr-box">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/PromptPay_logo.svg/512px-PromptPay_logo.svg.png" alt="PromptPay" style="height: 30px; margin-bottom: 10px;">
                    <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="PromptPay QR Code" class="qr-image">
                    <?php if (!empty($settings['promptpay_name'])): ?><p class="mb-1 fw-bold"><?php echo htmlspecialchars($settings['promptpay_name']); ?></p><?php endif; ?>
                    <p class="mb-0 text-muted small"><?php echo t('scan_qr_to_pay'); ?> · <?php echo htmlspecialchars($promptpayId); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($settings['bank_account_number']) && !empty($settings['bank_name'])): ?>
                <div class="bill-details text-center">
                    <h6 class="mb-2"><i class="bi bi-bank me-2"></i><?php echo t('or_transfer_to'); ?></h6>
                    <p class="mb-1"><strong><?php echo htmlspecialchars($settings['bank_name']); ?></strong></p>
                    <p class="mb-1" style="font-size: 1.2rem; font-family: monospace; letter-spacing: 1px;"><?php echo htmlspecialchars($settings['bank_account_number']); ?></p>
                    <?php if (!empty($settings['bank_account_name'])): ?><p class="mb-0 text-muted"><?php echo htmlspecialchars($settings['bank_account_name']); ?></p><?php endif; ?>
                </div>
            <?php endif; ?>

            <form id="paymentForm" class="mt-4">
                <input type="hidden" name="payment_token" value="<?php echo htmlspecialchars($token); ?>">
                <button type="submit" class="btn btn-primary btn-lg w-100" id="submitBtn"><i class="bi bi-check-circle me-2"></i><?php echo $isEnglish ? 'Confirm Payment' : 'ยืนยันการชำระเงิน'; ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
<footer class="footer">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($dormName); ?>. All rights reserved.</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('paymentForm');
    if (!form) return;
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const submitButton = document.getElementById('submitBtn');
        const originalText = submitButton.innerHTML;
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Processing...';

        fetch('payment.php', { method: 'POST', body: new FormData(this) })
            .then(response => response.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'An error occurred');
                Swal.fire({ icon: 'success', title: '<?php echo $isEnglish ? 'Payment Completed' : 'ชำระเงินเรียบร้อยแล้ว'; ?>', text: data.message, confirmButtonColor: '<?php echo $primaryColor; ?>' })
                    .then(() => window.location.reload());
            })
            .catch(error => {
                Swal.fire({ icon: 'error', title: '<?php echo t('error'); ?>', text: error.message || 'Network error. Please try again.', confirmButtonColor: '<?php echo $primaryColor; ?>' });
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            });
    });
});
</script>
</body>
</html>
