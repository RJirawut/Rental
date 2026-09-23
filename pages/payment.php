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

if ($billType === 'monthly') {
    $grandTotal = (float) $paymentRecord['rent_amount']
        + (float) $paymentRecord['water_amount']
        + (float) $paymentRecord['elec_amount']
        + (float) $paymentRecord['other_fees']
        - (float) $paymentRecord['discount'];
    $alreadyPaid = $paymentRecord['status'] === 'paid';
    $paidDate = $paymentRecord['paid_date'];
    $total = $grandTotal;
} else {
    $grandTotal = (float) ($paymentRecord['invoice_grand_total'] ?? $paymentRecord['total_amount']);
    $alreadyPaid = $paymentRecord['status'] !== 'pending_payment';
    $paidDate = date('Y-m-d');
    $total = $grandTotal;
}

ensurePaymentConfirmationsTable();
$pendingConfirmation = null;
$stmtPending = $pdo->prepare("SELECT * FROM payment_confirmations WHERE bill_type = ? AND bill_id = ? AND status = 'pending_verify' ORDER BY id DESC LIMIT 1");
$stmtPending->execute([$billType, $paymentRecord['id']]);
$pendingConfirmation = $stmtPending->fetch() ?: null;

if ($pendingConfirmation) {
    $alreadyPaid = false;
}

// Define variables needed by both POST handler and template
$isEnglish = ($_SESSION['lang'] ?? 'th') === 'en';
$tenantName = $billType === 'daily'
    ? ($paymentRecord['guest_name'] ?? '')
    : ($paymentRecord['tenant_name'] ?? '');
$roomNumber = $paymentRecord['room_number'] ?? '';
$paymentLinkExpired = $billType === 'daily'
    && !$alreadyPaid
    && !empty($paymentRecord['payment_deadline'])
    && strtotime($paymentRecord['payment_deadline']) < time();

// Handle AJAX Payment Submission (submit slip for admin verification)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    requireValidCsrfToken();

    if ($alreadyPaid) {
        echo json_encode(['success' => false, 'message' => $isEnglish ? 'This bill has already been paid.' : 'บิลนี้ชำระเงินเรียบร้อยแล้ว']);
        exit;
    }

    if ($pendingConfirmation) {
        echo json_encode(['success' => false, 'message' => $isEnglish ? 'You already have a pending payment verification.' : 'คุณมีรายการแจ้งชำระเงินที่อยู่ระหว่างรอตรวจสอบแล้ว']);
        exit;
    }

    if ($paymentLinkExpired) {
        echo json_encode(['success' => false, 'message' => $isEnglish ? 'This payment link has expired.' : 'ลิงก์ชำระเงินนี้หมดอายุแล้ว']);
        exit;
    }

    if ($total <= 0) {
        echo json_encode(['success' => false, 'message' => $isEnglish ? 'There is no outstanding balance to pay.' : 'ไม่มียอดค้างชำระ']);
        exit;
    }

    $transferDate = normalizeDateFilterValue($_POST['transfer_date'] ?? '');
    $transferTime = trim($_POST['transfer_time'] ?? '');

    if ($transferDate === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $transferTime)) {
        echo json_encode(['success' => false, 'message' => $isEnglish ? 'Please provide a valid transfer date and time.' : 'กรุณาระบุวันที่และเวลาโอนให้ถูกต้อง']);
        exit;
    }

    $slipFile = $_FILES['slip_image'] ?? null;
    $allowedSlipTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $slipMimeType = is_array($slipFile) && isset($slipFile['tmp_name']) && is_uploaded_file($slipFile['tmp_name'])
        ? (new finfo(FILEINFO_MIME_TYPE))->file($slipFile['tmp_name'])
        : false;
    if (!is_array($slipFile)
        || ($slipFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_numeric($slipFile['size'] ?? null)
        || (int) $slipFile['size'] <= 0
        || (int) $slipFile['size'] > 5 * 1024 * 1024
        || !in_array($slipMimeType, $allowedSlipTypes, true)
        || @getimagesize($slipFile['tmp_name']) === false) {
        echo json_encode(['success' => false, 'message' => $isEnglish ? 'Please upload a valid slip image (JPG, PNG, or WEBP; maximum 5 MB).' : 'กรุณาแนบรูปสลิปที่ถูกต้อง (JPG, PNG หรือ WEBP ขนาดไม่เกิน 5 MB)']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmtPendingLock = $pdo->prepare("SELECT id FROM payment_confirmations WHERE bill_type = ? AND bill_id = ? AND status = 'pending_verify' FOR UPDATE");
        $stmtPendingLock->execute([$billType, $paymentRecord['id']]);
        if ($stmtPendingLock->fetch()) {
            throw new RuntimeException('A payment confirmation is already pending');
        }

        $upload = uploadPaymentSlip($_FILES['slip_image'] ?? []);
        if (!$upload['success']) {
            throw new RuntimeException($upload['message']);
        }
        $slipFilename = $upload['filename'];
        $trackingCode = generatePaymentTrackingCode();

        $stmtPc = $pdo->prepare("
            INSERT INTO payment_confirmations
            (tracking_code, bill_type, bill_id, room_number, tenant_name, amount, payment_method, transfer_date, transfer_time, slip_image, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'promptpay', ?, ?, ?, 'pending_verify', NOW())
        ");
        $stmtPc->execute([
            $trackingCode,
            $billType,
            $paymentRecord['id'],
            $roomNumber,
            $tenantName,
            $total,
            $transferDate,
            $transferTime,
            $slipFilename
        ]);

        $pdo->commit();
        logActivity('submit_payment_slip', 'payment_confirmations', $paymentRecord['id'], "Submitted payment confirmation for {$billType} bill #{$paymentRecord['id']} of {$total}");

        echo json_encode([
            'success' => true,
            'tracking_code' => $trackingCode,
            'tracking_url' => BASE_URL . 'pages/payment-notice.php?ticket=' . urlencode($trackingCode),
            'message' => $isEnglish
                ? 'Payment notice submitted successfully! Pending admin verification.'
                : 'แจ้งชำระเงินเรียบร้อยแล้ว อยู่ระหว่างรอผู้ดูแลระบบตรวจสอบ',
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($slipFilename)) {
            @unlink(__DIR__ . '/../uploads/slips/' . $slipFilename);
        }
        error_log('Payment slip submission failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $isEnglish ? 'Unable to submit the payment notice. Please try again.' : 'ไม่สามารถส่งการแจ้งชำระเงินได้ กรุณาลองใหม่อีกครั้ง']);
        exit;
    }
}

$error = null;
if ($paymentLinkExpired) {
    $error = 'This payment link has expired.';
}

$settings = getSettings();
$dormName = $settings['dorm_name'] ?? 'Rental System';
$primaryColor = $settings['primary_color'] ?? '#0d6efd';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor)) {
    $primaryColor = '#0d6efd';
}
$promptpayId = $settings['promptpay_id'] ?? '';
$qrUrl = '';
if ($promptpayId && $total > 0 && !$error && !$alreadyPaid && !$pendingConfirmation) {
    $payload = generatePromptPayPayload($promptpayId, $total);
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($payload);
}

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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
        .tracking-code-block { background: transparent; border: 0; box-shadow: none; padding: 0; margin: 1rem 0; }
        .tracking-code-block .tracking-code-value { display: block; }
        .tracking-code-block .tracking-actions { display: block; margin-top: .5rem; }
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
                <div class="mt-4"><h2 class="text-success mb-0"><?php echo formatCurrency($total); ?> <?php echo t('baht'); ?></h2></div>

            </div>
        <?php elseif ($pendingConfirmation): ?>
            <div class="text-center py-4">
                <i class="bi bi-hourglass-split text-warning" style="font-size: 4.5rem;"></i>
                <h3 class="mt-3 fw-bold"><?php echo $isEnglish ? 'Payment Verification Pending' : 'แจ้งชำระเงินเรียบร้อยแล้ว'; ?></h3>
                <p class="text-muted"><?php echo $isEnglish ? 'Your payment notice is currently pending admin review.' : 'ข้อมูลสลิปการโอนเงินของคุณถูกส่งเรียบร้อยแล้ว อยู่ระหว่างรอผู้ดูแลระบบตรวจสอบ'; ?></p>
                <div class="mb-2">
                    <span class="badge bg-warning text-dark px-3 py-2 fs-6 mt-1"><i class="bi bi-clock me-1"></i><?php echo t('pending_verify'); ?></span>
                </div>
                <?php if (!empty($pendingConfirmation['tracking_code'])): ?>
                    <div class="tracking-code-block text-center">
                        <span class="small d-block text-muted"><?php echo t('tracking_code_label'); ?></span>
                        <strong class="tracking-code-value font-monospace"><?php echo htmlspecialchars($pendingConfirmation['tracking_code']); ?></strong>
                        <div class="tracking-actions"><a class="btn btn-sm btn-outline-primary" href="<?php echo BASE_URL; ?>pages/payment-notice.php?ticket=<?php echo urlencode($pendingConfirmation['tracking_code']); ?>"><?php echo t('track_status'); ?></a></div>
                    </div>
                <?php endif; ?>
                <div class="card border-0 bg-light p-3 mt-3 text-start">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted"><?php echo t('room'); ?>:</span>
                        <span class="fw-bold"><?php echo htmlspecialchars($roomNumber); ?> (<?php echo htmlspecialchars($tenantName); ?>)</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted"><?php echo t('payment_amount'); ?>:</span>
                        <span class="fw-bold text-success"><?php echo formatCurrency($pendingConfirmation['amount']); ?> <?php echo t('baht'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-0">
                        <span class="text-muted"><?php echo t('created_at'); ?>:</span>
                        <span><?php echo formatDate($pendingConfirmation['created_at']); ?></span>
                    </div>
                </div>
                <?php if (!empty($pendingConfirmation['slip_image'])): ?>
                    <div class="mt-3">
                        <p class="small text-muted mb-2"><?php echo t('payment_slip'); ?>:</p>
                        <img src="<?php echo BASE_URL . 'uploads/slips/' . htmlspecialchars($pendingConfirmation['slip_image']); ?>" alt="Slip" class="img-fluid rounded shadow-sm border" style="max-height: 240px; object-fit: contain;">
                    </div>
                <?php endif; ?>
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

            <!-- Pay Now Trigger Button -->
            <div id="payActionBlock" class="mt-4 text-center">
                <button type="button" id="payNowBtn" class="btn btn-primary btn-lg w-100 rounded-pill py-3 shadow-sm fw-bold" onclick="showQrCodeSection()">
                    <i class="bi bi-qr-code-scan me-2 fs-5"></i><?php echo $isEnglish ? 'Pay Now' : 'ชำระเงิน'; ?>
                </button>
            </div>

            <!-- Toggleable Payment Area (QR Code & Bank Info) -->
            <div id="qrCodeArea" class="mt-4" style="display: none;">
                <?php if ($qrUrl): ?>
                    <div class="qr-box shadow-sm border-0 mb-4" style="background: #f8fafc; border-radius: 1rem;">
                        <div>
                            <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="PromptPay QR Code" class="qr-image shadow-sm border p-2 bg-white" style="border-radius: 0.75rem;">
                        </div>
                        <div class="mt-2 fw-bold text-dark fs-5"><?php echo formatCurrency($total); ?> <?php echo t('baht'); ?></div>
                        <?php if (!empty($settings['promptpay_name'])): ?><p class="mb-1 text-secondary fw-semibold"><?php echo htmlspecialchars($settings['promptpay_name']); ?></p><?php endif; ?>
                        <p class="mb-0 text-muted small"><i class="bi bi-info-circle me-1"></i><?php echo t('scan_qr_to_pay'); ?> · <?php echo htmlspecialchars($promptpayId); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($settings['bank_account_number']) && !empty($settings['bank_name'])): ?>
                    <div class="bill-details text-center mb-4">
                        <h6 class="mb-2 text-muted"><i class="bi bi-bank me-2"></i><?php echo t('or_transfer_to'); ?></h6>
                        <p class="mb-1 fw-bold text-dark fs-5"><?php echo htmlspecialchars($settings['bank_name']); ?></p>
                        <p class="mb-1 text-primary fw-bold" style="font-size: 1.3rem; font-family: monospace; letter-spacing: 1.5px;"><?php echo htmlspecialchars($settings['bank_account_number']); ?></p>
                        <?php if (!empty($settings['bank_account_name'])): ?><p class="mb-0 text-muted small"><?php echo htmlspecialchars($settings['bank_account_name']); ?></p><?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Notify Payment Button -->
                <div class="text-center mb-3">
                    <button type="button" id="notifyBtn" class="btn btn-success btn-lg w-100 rounded-pill py-3 shadow-sm fw-bold" onclick="showNotifyForm()">
                        <i class="bi bi-file-earmark-arrow-up me-2 fs-5"></i><?php echo $isEnglish ? 'Notify Payment / Attach Slip' : 'แจ้งชำระเงิน (แนบสลิป)'; ?>
                    </button>
                </div>

                <!-- Slip Upload & Confirmation Form -->
                <form id="paymentForm" class="mt-3 p-4 bg-light rounded-4 border" style="display: none;" enctype="multipart/form-data">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="payment_token" value="<?php echo htmlspecialchars($token); ?>">
                    <h6 class="fw-bold mb-3 text-dark d-flex align-items-center">
                        <i class="bi bi-cloud-upload text-primary fs-5 me-2"></i>
                        <?php echo $isEnglish ? 'Upload Payment Slip' : 'แนบสลิปการโอนเงิน'; ?>
                    </h6>
                    <div class="row g-2 mb-3 text-start">
                        <div class="col-12 col-md-6">
                            <label for="transfer_date" class="form-label small fw-bold text-muted"><?php echo t('transfer_date'); ?> <span class="text-danger">*</span></label>
                            <input type="date" name="transfer_date" id="transfer_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="transfer_time" class="form-label small fw-bold text-muted"><?php echo t('transfer_time'); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="transfer_time" id="transfer_time" class="form-control bg-white" value="<?php echo date('H:i'); ?>" placeholder="14:30" pattern="^([01]\d|2[0-3]):[0-5]\d$" maxlength="5" required>
                        </div>
                    </div>
                    <div class="mb-3 text-start">
                        <label class="form-label small fw-bold text-muted"><?php echo t('upload_slip'); ?> <span class="text-danger">*</span></label>
                        <input type="file" name="slip_image" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm" id="submitBtn">
                        <i class="bi bi-check-circle me-2"></i><?php echo $isEnglish ? 'Confirm Payment Notice' : 'ยืนยันการแจ้งชำระเงิน'; ?>
                    </button>
                    <div class="text-center mt-3">
                        <a href="<?php echo BASE_URL; ?>pages/payment-notice.php" class="small text-decoration-none text-muted">
                            <i class="bi bi-file-earmark-text me-1"></i><?php echo $isEnglish ? 'Or submit via public payment notice form' : 'หรือส่งสลิปผ่านฟอร์มแจ้งชำระเงินทั่วไป'; ?>
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
<footer class="footer">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($dormName); ?>. All rights reserved.</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
function initTimePicker() {
    if (typeof flatpickr !== 'undefined') {
        flatpickr("#transfer_time", {
            enableTime: true,
            noCalendar: true,
            dateFormat: "H:i",
            time_24hr: true,
            allowInput: true
        });
    }
}
document.addEventListener('DOMContentLoaded', initTimePicker);

function showQrCodeSection() {
    const qrArea = document.getElementById('qrCodeArea');
    const payBlock = document.getElementById('payActionBlock');
    if (qrArea) {
        qrArea.style.display = 'block';
        qrArea.scrollIntoView({ behavior: 'smooth' });
    }
    if (payBlock) {
        payBlock.style.display = 'none';
    }
}

function showNotifyForm() {
    const form = document.getElementById('paymentForm');
    const notifyBtn = document.getElementById('notifyBtn');
    if (form) {
        form.style.display = 'block';
        form.scrollIntoView({ behavior: 'smooth' });
    }
    if (notifyBtn) {
        notifyBtn.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('paymentForm');
    if (!form) return;
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const submitButton = document.getElementById('submitBtn');
        const originalText = submitButton.innerHTML;
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Processing...';

        fetch('payment.php?token=<?php echo urlencode($token); ?>', {
            method: 'POST',
            body: new FormData(this),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(response => response.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'An error occurred');
                Swal.fire({ icon: 'info', title: '<?php echo $isEnglish ? 'Payment Notice Submitted' : 'แจ้งชำระเงินเรียบร้อย'; ?>', text: data.message, confirmButtonColor: '<?php echo $primaryColor; ?>' })
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
