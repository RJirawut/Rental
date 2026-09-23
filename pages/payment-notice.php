<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../assets/lang/language.php';

ensurePaymentConfirmationsTable();
ensureDailyTenantPaymentDeadlineColumn();

$settings = getSettings();
$dormName = $settings['dorm_name'] ?? 'Rental System';
$primaryColor = $settings['primary_color'] ?? '#0d6efd';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor)) {
    $primaryColor = '#0d6efd';
}

$isEnglish = ($_SESSION['lang'] ?? 'th') === 'en';
$errorMsg = null;
$successTrackingCode = $_SESSION['success_payment_tracking_code'] ?? null;
if ($successTrackingCode) {
    unset($_SESSION['success_payment_tracking_code']);
}
$trackedPayment = null;
$searchTrackingCode = trim($_GET['ticket'] ?? $_GET['payment'] ?? '');

if ($searchTrackingCode !== '') {
    $stmtTrack = $pdo->prepare("SELECT * FROM payment_confirmations WHERE tracking_code = ? LIMIT 1");
    $stmtTrack->execute([$searchTrackingCode]);
    $trackedPayment = $stmtTrack->fetch() ?: null;
}

// Return only rooms with a current payable bill for the selected tenant type.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_rooms') {
    header('Content-Type: application/json');
    $enableDaily = (int) ($settings['enable_daily'] ?? 1);
    $enableMonthly = (int) ($settings['enable_monthly'] ?? 1);
    $billType = trim($_GET['bill_type'] ?? ($enableDaily ? 'daily' : 'monthly'));
    if (!$enableDaily && $billType === 'daily') $billType = 'monthly';
    if (!$enableMonthly && $billType === 'monthly') $billType = 'daily';
    echo json_encode([
        'success' => true,
        'rooms' => getPaymentNoticeRooms($billType),
    ]);
    exit;
}

// Handle AJAX lookup for pending bills by room
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_bills') {
    header('Content-Type: application/json');
    $enableDaily = (int) ($settings['enable_daily'] ?? 1);
    $enableMonthly = (int) ($settings['enable_monthly'] ?? 1);
    $roomNumber = trim($_GET['room_number'] ?? '');
    $billType = trim($_GET['bill_type'] ?? ($enableDaily ? 'daily' : 'monthly'));
    if (!$enableDaily && $billType === 'daily') $billType = 'monthly';
    if (!$enableMonthly && $billType === 'monthly') $billType = 'daily';

    if (empty($roomNumber)) {
        echo json_encode(['success' => false, 'bills' => []]);
        exit;
    }

    $bills = [];
    if ($billType === 'monthly') {
        $stmt = $pdo->prepare("
            SELECT ub.id, ub.bill_month, ub.status,
                   ub.total_amount AS amount_due,
                   mt.tenant_name, r.room_number
            FROM utility_bills ub
            JOIN monthly_tenants mt ON ub.tenant_id = mt.id
            JOIN rooms r ON ub.room_id = r.id
            WHERE r.room_number = ?
              AND ub.status IN ('unpaid', 'overdue')
              AND ub.total_amount > 0
              AND NOT EXISTS (
                  SELECT 1
                  FROM payment_confirmations pc
                  WHERE pc.bill_type = 'monthly'
                    AND pc.bill_id = ub.id
                    AND pc.status = 'pending_verify'
              )
            ORDER BY ub.bill_month DESC
        ");
        $stmt->execute([$roomNumber]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $total = (float) $r['amount_due'];
            if ($total <= 0) {
                continue;
            }
            $bills[] = [
                'id' => $r['id'],
                'label' => formatBillMonth($r['bill_month']) . ' - ' . number_format($total, 2) . ' ' . t('baht'),
                'total' => $total,
                'tenant_name' => $r['tenant_name']
            ];
        }
    } else {
        $stmt = $pdo->prepare("
            SELECT dt.id, dt.guest_name, r.room_number, dt.check_in_date, dt.check_out_date,
                   COALESCE(latest_invoice.grand_total, dt.total_amount) AS amount_due, dt.status
            FROM daily_tenants dt
            JOIN rooms r ON r.id = dt.room_id
            LEFT JOIN invoices latest_invoice ON latest_invoice.id = (
                SELECT i.id
                FROM invoices i
                WHERE i.tenant_type = 'daily' AND i.tenant_id = dt.id
                ORDER BY i.id DESC
                LIMIT 1
            )
            WHERE r.room_number = ?
              AND dt.status = 'pending_payment'
              AND (dt.payment_deadline IS NULL OR dt.payment_deadline >= NOW())
              AND COALESCE(latest_invoice.grand_total, dt.total_amount) > 0
              AND NOT EXISTS (
                  SELECT 1
                  FROM payment_confirmations pc
                  WHERE pc.bill_type = 'daily'
                    AND pc.bill_id = dt.id
                    AND pc.status = 'pending_verify'
              )
            ORDER BY dt.created_at DESC
        ");
        $stmt->execute([$roomNumber]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $total = (float)$r['amount_due'];
            $bills[] = [
                'id' => $r['id'],
                'label' => formatDate($r['check_in_date']) . ' - ' . formatDate($r['check_out_date']) . ' (' . number_format($total, 2) . ' ' . t('baht') . ')',
                'total' => $total,
                'tenant_name' => $r['guest_name']
            ];
        }
    }

    echo json_encode(['success' => true, 'bills' => $bills]);
    exit;
}

// Fetch only rooms with a current payable bill for the selected form type.
$enableDaily = (int) ($settings['enable_daily'] ?? 1);
$enableMonthly = (int) ($settings['enable_monthly'] ?? 1);

if ($enableDaily && !$enableMonthly) {
    $requestedFormBillType = 'daily';
} elseif (!$enableDaily && $enableMonthly) {
    $requestedFormBillType = 'monthly';
} else {
    $requestedFormBillType = trim($_POST['bill_type'] ?? 'daily');
}

$formBillType = in_array($requestedFormBillType, ['monthly', 'daily'], true)
    ? $requestedFormBillType
    : ($enableDaily ? 'daily' : 'monthly');
$rooms = getPaymentNoticeRooms($formBillType);

// Validate all bill data against the database before the legacy form handler
// creates a confirmation. The browser may only choose an existing payable bill.
$paymentNoticeSubmissionIsValid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $submittedBillType = trim($_POST['bill_type'] ?? '');
    $submittedBillId = (int) ($_POST['bill_id'] ?? 0);
    $submittedAmount = (float) ($_POST['amount'] ?? 0);
    $submittedTransferDate = normalizeDateFilterValue($_POST['transfer_date'] ?? '');
    $submittedTransferTime = trim($_POST['transfer_time'] ?? '');
    $submittedMethod = trim($_POST['payment_method'] ?? '');
    $payableBill = in_array($submittedBillType, ['monthly', 'daily'], true)
        ? getPayableBillForConfirmation($submittedBillType, $submittedBillId)
        : null;

    if (!$payableBill) {
        $paymentNoticeSubmissionIsValid = false;
        $errorMsg = $isEnglish ? 'The selected bill is unavailable or already paid.' : 'ไม่พบบิลที่เลือก หรือบิลนี้ชำระครบแล้ว';
    } elseif ($submittedTransferDate === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $submittedTransferTime)) {
        $paymentNoticeSubmissionIsValid = false;
        $errorMsg = $isEnglish ? 'Please provide a valid transfer date and time.' : 'กรุณาระบุวันที่และเวลาโอนให้ถูกต้อง';
    } elseif (!in_array($submittedMethod, ['promptpay', 'bank_transfer'], true)) {
        $paymentNoticeSubmissionIsValid = false;
        $errorMsg = $isEnglish ? 'Invalid payment method.' : 'วิธีชำระเงินไม่ถูกต้อง';
    } else {
        $amountDue = (float) $payableBill['amount_due'];
        if ($submittedAmount <= 0 || abs($submittedAmount - $amountDue) > 0.01) {
            $paymentNoticeSubmissionIsValid = false;
            $errorMsg = t('payment_full_amount_required');
        } else {
            // Ignore tampered room and amount fields; all bills are paid in full.
            $_POST['room_number'] = $payableBill['room_number'];
            $_POST['tenant_name'] = $payableBill['tenant_name'];
            $_POST['amount'] = $amountDue;
            $_POST['transfer_date'] = $submittedTransferDate;
            $_POST['payment_method'] = $submittedMethod;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $paymentNoticeSubmissionIsValid) {
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
        $paymentNoticeSubmissionIsValid = false;
        $errorMsg = $isEnglish ? 'Please upload a valid slip image (JPG, PNG, or WEBP; maximum 5 MB).' : 'กรุณาแนบรูปสลิปที่ถูกต้อง (JPG, PNG หรือ WEBP ขนาดไม่เกิน 5 MB)';
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $paymentNoticeSubmissionIsValid) {
    $billType = trim($_POST['bill_type'] ?? 'monthly');
    $roomNumber = trim($_POST['room_number'] ?? '');
    $billId = (int)($_POST['bill_id'] ?? 0);
    $tenantName = trim($payableBill['tenant_name'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'promptpay');
    $transferDate = trim($_POST['transfer_date'] ?? date('Y-m-d'));
    $transferTime = trim($_POST['transfer_time'] ?? date('H:i'));

    if (empty($roomNumber) || empty($tenantName) || $amount <= 0 || empty($transferDate)) {
        $errorMsg = t('please_enter_all_required');
    } else {
        $slipFilename = null;

        // Handle Upload
        if (isset($_FILES['slip_image']) && $_FILES['slip_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['slip_image'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($ext, $allowedExts, true)) {
                $errorMsg = $isEnglish ? 'Invalid file format. Only JPG, PNG, WEBP allowed.' : 'รูปแบบไฟล์ไม่ถูกต้อง รองรับเฉพาะ JPG, PNG, WEBP';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $errorMsg = $isEnglish ? 'File size exceeds 5MB.' : 'ขนาดไฟล์เกิน 5MB';
            } else {
                $uploadDir = __DIR__ . '/../uploads/slips/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                    $errorMsg = $isEnglish ? 'Unable to create the upload directory.' : 'ไม่สามารถสร้างโฟลเดอร์อัปโหลดได้';
                }

                $slipFilename = bin2hex(random_bytes(16)) . '.' . $ext;
                $targetFile = $uploadDir . $slipFilename;

                if (!$errorMsg && !move_uploaded_file($file['tmp_name'], $targetFile)) {
                    $errorMsg = $isEnglish ? 'Failed to upload slip file.' : 'ไม่สามารถอัปโหลดไฟล์สลิปได้';
                    $slipFilename = null;
                }
            }
        } else {
            $errorMsg = $isEnglish ? 'Please upload a payment slip image.' : 'กรุณาแนบรูปภาพสลิปการโอนเงิน';
        }

        if (!$errorMsg) {
            try {
                $trackingCode = generatePaymentTrackingCode();
                $stmtIns = $pdo->prepare("
                    INSERT INTO payment_confirmations
                    (tracking_code, bill_type, bill_id, room_number, tenant_name, amount, payment_method, transfer_date, transfer_time, slip_image, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_verify', NOW())
                ");
                $stmtIns->execute([
                    $trackingCode,
                    $billType,
                    $billId,
                    $roomNumber,
                    $tenantName,
                    $amount,
                    $paymentMethod,
                    $transferDate,
                    $transferTime,
                    $slipFilename
                ]);

                $insertedId = $pdo->lastInsertId();
                logActivity('submit_payment_confirmation', 'payment_confirmations', $insertedId, "Tenant submitted slip for Room {$roomNumber} amount {$amount}");
                $_SESSION['success_payment_tracking_code'] = $trackingCode;
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } catch (Exception $e) {
                if ($slipFilename) {
                    @unlink(__DIR__ . '/../uploads/slips/' . $slipFilename);
                }
                error_log('Public payment confirmation failed: ' . $e->getMessage());
                $errorMsg = $isEnglish ? 'Unable to submit the payment notice. Please try again.' : 'ไม่สามารถส่งการแจ้งชำระเงินได้ กรุณาลองใหม่อีกครั้ง';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($dormName); ?> - <?php echo t('payment_confirmations'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body { font-family: 'Prompt', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%); min-height: 100vh; display: flex; flex-direction: column; }
        .form-container { max-width: 640px; margin: 2rem auto; width: 100%; padding: 0 1rem; flex: 1; }
        .header-bg { background-color: <?php echo htmlspecialchars($primaryColor); ?>; background-image: linear-gradient(135deg, rgba(255,255,255,.15), rgba(0,0,0,.15)); color: #fff; padding: 2.5rem 1rem 3.5rem; border-radius: 1.25rem 1.25rem 0 0; text-align: center; }
        .form-card { background: #fff; border-radius: 1.25rem; box-shadow: 0 15px 35px rgba(0,0,0,.08); margin-top: -2.5rem; padding: 2rem; position: relative; }
        .footer { text-align: center; padding: 1.5rem 1rem; color: #6c757d; font-size: .875rem; margin-top: auto; }
        .btn-primary { background-color: <?php echo htmlspecialchars($primaryColor); ?>; border-color: <?php echo htmlspecialchars($primaryColor); ?>; }
        .btn-primary:hover { filter: brightness(.9); }
        .preview-img { max-height: 250px; border-radius: 8px; border: 2px dashed #dee2e6; margin-top: 10px; width: 100%; object-fit: contain; }
        .tracking-code-block { background: transparent; border: 0; box-shadow: none; padding: 0; margin: 1rem 0; }
        .tracking-code-block .tracking-code-value { display: block; }
        .tracking-code-block .tracking-actions { display: block; margin-top: .5rem; }
    </style>
</head>
<body>
<div class="form-container">
    <div class="header-bg position-relative">
        <div class="position-absolute top-0 end-0 p-3">
            <a href="?lang=<?php echo $lang === 'th' ? 'en' : 'th'; ?>" class="btn btn-sm btn-outline-light">
                <?php echo $lang === 'th' ? 'EN' : 'TH'; ?>
            </a>
        </div>
        <?php if (!empty($settings['logo'])): ?>
            <img src="<?php echo htmlspecialchars(BASE_URL . 'assets/images/logo/' . $settings['logo']); ?>" alt="Logo" style="max-height: 70px;" class="mb-2">
        <?php endif; ?>
        <h2 class="mb-1 fw-bold"><?php echo htmlspecialchars($dormName); ?></h2>
        <p class="mb-0 opacity-75"><i class="bi bi-credit-card-2-front me-1"></i><?php echo t('payment_confirmations'); ?></p>
    </div>

    <div class="form-card">
        <div class="mb-4 pb-3 border-bottom">
            <form method="GET" action="" class="row g-2 align-items-center">
                <div class="col-8 col-sm-9">
                    <input type="text" name="ticket" class="form-control" placeholder="<?php echo htmlspecialchars(t('tracking_code_label')); ?> (PAY-...)" value="<?php echo htmlspecialchars($searchTrackingCode); ?>">
                </div>
                <div class="col-4 col-sm-3">
                    <button type="submit" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-search me-1"></i><?php echo t('track'); ?>
                    </button>
                </div>
            </form>
        </div>

        <?php if ($trackedPayment): ?>
            <?php
            $trackedStatusClass = 'bg-warning text-dark';
            $trackedStatusLabel = t('pending_verify');
            if ($trackedPayment['status'] === 'approved') {
                $trackedStatusClass = 'bg-success';
                $trackedStatusLabel = t('approved');
            } elseif ($trackedPayment['status'] === 'rejected') {
                $trackedStatusClass = 'bg-danger';
                $trackedStatusLabel = t('rejected');
            }
            $trackedTypeLabel = $trackedPayment['bill_type'] === 'daily'
                ? t('daily_tenants')
                : t('monthly_tenants');
            ?>
            <div class="card border-primary mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center gap-2">
                    <strong><i class="bi bi-credit-card-2-front me-2"></i><?php echo t('tracking_code_label'); ?>: <?php echo htmlspecialchars($trackedPayment['tracking_code']); ?></strong>
                    <span class="badge <?php echo $trackedStatusClass; ?>"><?php echo $trackedStatusLabel; ?></span>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong><?php echo t('room'); ?>:</strong> <?php echo htmlspecialchars($trackedPayment['room_number'] ?? '-'); ?> | <strong><?php echo t('tenant'); ?>:</strong> <?php echo htmlspecialchars($trackedPayment['tenant_name']); ?></p>
                    <p class="mb-1"><strong><?php echo t('type'); ?>:</strong> <?php echo htmlspecialchars($trackedTypeLabel); ?></p>
                    <p class="mb-1"><strong><?php echo t('payment_amount'); ?>:</strong> <span class="text-success fw-bold"><?php echo formatCurrency($trackedPayment['amount']); ?> <?php echo t('baht'); ?></span></p>
                    <?php if (!empty($trackedPayment['transfer_date'])): ?>
                        <p class="mb-1"><strong><?php echo t('transfer_datetime'); ?>:</strong> <?php echo formatDate($trackedPayment['transfer_date']); ?><?php if (!empty($trackedPayment['transfer_time'])): ?> <?php echo htmlspecialchars(substr($trackedPayment['transfer_time'], 0, 5)); ?><?php endif; ?></p>
                    <?php endif; ?>
                    <p class="mb-0 text-muted small"><strong><?php echo t('created_at'); ?>:</strong> <?php echo formatDate($trackedPayment['created_at']); ?></p>
                    <?php if (!empty($trackedPayment['admin_note'])): ?>
                        <div class="alert <?php echo $trackedPayment['status'] === 'rejected' ? 'alert-danger' : 'alert-light'; ?> border mt-3 mb-0">
                            <strong><i class="bi bi-info-circle me-1"></i><?php echo t('admin_note'); ?>:</strong><br>
                            <?php echo nl2br(htmlspecialchars($trackedPayment['admin_note'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-center mb-4">
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-outline-primary"><i class="bi bi-plus-circle me-1"></i><?php echo $isEnglish ? 'Submit Another Notice' : 'แจ้งชำระเพิ่มเติม'; ?></a>
            </div>
        <?php elseif ($successTrackingCode):
            // Fetch the submitted confirmation details to display
            $stmtSuccess = $pdo->prepare("SELECT * FROM payment_confirmations WHERE tracking_code = ? LIMIT 1");
            $stmtSuccess->execute([$successTrackingCode]);
            $successPayment = $stmtSuccess->fetch();
        ?>
            <div class="text-center py-4">
                <i class="bi bi-hourglass-split text-warning" style="font-size: 4.5rem;"></i>
                <h3 class="mt-3 fw-bold"><?php echo $isEnglish ? 'Payment Verification Pending' : 'แจ้งชำระเงินเรียบร้อยแล้ว'; ?></h3>
                <p class="text-muted"><?php echo $isEnglish ? 'Your payment notice is currently pending admin review.' : 'ข้อมูลสลิปการโอนเงินของคุณถูกส่งเรียบร้อยแล้ว อยู่ระหว่างรอผู้ดูแลระบบตรวจสอบ'; ?></p>
                <div class="mb-2">
                    <span class="badge bg-warning text-dark px-3 py-2 fs-6 mt-1"><i class="bi bi-clock me-1"></i><?php echo t('pending_verify'); ?></span>
                </div>
                <div class="tracking-code-block text-center">
                    <span class="small d-block text-muted"><?php echo t('tracking_code_label'); ?></span>
                    <strong class="tracking-code-value font-monospace fs-5" id="paymentTrackingCode"><?php echo htmlspecialchars($successTrackingCode); ?></strong>
                    <div class="tracking-actions">
                        <button class="btn btn-sm btn-outline-secondary me-2" onclick="copyPaymentTrackingCode()"><i class="bi bi-clipboard me-1"></i><?php echo t('copy_code'); ?></button>
                        <a class="btn btn-sm btn-outline-primary" href="?ticket=<?php echo urlencode($successTrackingCode); ?>"><?php echo t('track_status'); ?></a>
                    </div>
                </div>
                <?php if ($successPayment): ?>
                    <div class="card border-0 bg-light p-3 mt-3 text-start">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted"><?php echo t('room'); ?>:</span>
                            <span class="fw-bold"><?php echo htmlspecialchars($successPayment['room_number']); ?> (<?php echo htmlspecialchars($successPayment['tenant_name']); ?>)</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted"><?php echo t('payment_amount'); ?>:</span>
                            <span class="fw-bold text-success"><?php echo formatCurrency($successPayment['amount']); ?> <?php echo t('baht'); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-0">
                            <span class="text-muted"><?php echo t('created_at'); ?>:</span>
                            <span><?php echo formatDate($successPayment['created_at']); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($successPayment['slip_image'])): ?>
                        <div class="mt-3">
                            <p class="small text-muted mb-2"><?php echo t('payment_slip'); ?>:</p>
                            <img src="<?php echo BASE_URL . 'uploads/slips/' . htmlspecialchars($successPayment['slip_image']); ?>" alt="Slip" class="img-fluid rounded shadow-sm border" style="max-height: 240px; object-fit: contain;">
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="mt-4">
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-primary btn-lg"><i class="bi bi-plus-circle me-1"></i> <?php echo $isEnglish ? 'Submit Another Notice' : 'แจ้งชำระเพิ่มเติม'; ?></a>
                </div>
            </div>
        <?php else: ?>
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="paymentNoticeForm">
                <?php echo csrfInput(); ?>
                <?php if ($enableDaily && $enableMonthly): ?>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo t('type'); ?> <span class="text-danger">*</span></label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="bill_type" id="type_daily" value="daily" <?php echo $formBillType === 'daily' ? 'checked' : ''; ?> onchange="loadRooms()">
                        <label class="btn btn-outline-primary py-2" for="type_daily"><i class="bi bi-calendar-day me-1"></i><?php echo t('daily_tenants'); ?></label>

                        <input type="radio" class="btn-check" name="bill_type" id="type_monthly" value="monthly" <?php echo $formBillType === 'monthly' ? 'checked' : ''; ?> onchange="loadRooms()">
                        <label class="btn btn-outline-primary py-2" for="type_monthly"><i class="bi bi-calendar-month me-1"></i><?php echo t('monthly_tenants'); ?></label>
                    </div>
                </div>
                <?php else: ?>
                    <input type="hidden" name="bill_type" id="bill_type_hidden" value="<?php echo htmlspecialchars($formBillType); ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label for="room_number" class="form-label fw-bold"><?php echo t('room'); ?> <span class="text-danger">*</span></label>
                    <select name="room_number" id="room_number" class="form-select form-select-lg" required onchange="loadBills()">
                        <option value=""><?php echo t('select'); ?>...</option>
                        <?php foreach ($rooms as $rm): ?>
                            <option value="<?php echo htmlspecialchars($rm); ?>"><?php echo t('room'); ?> <?php echo htmlspecialchars($rm); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3" id="billSelectGroup" style="display: none;">
                    <label for="bill_id" class="form-label fw-bold"><?php echo t('bill_details'); ?></label>
                    <select name="bill_id" id="bill_id" class="form-select" onchange="onBillSelected()" required>
                        <option value="0">-- <?php echo t('select'); ?> --</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="tenant_name" class="form-label fw-bold"><?php echo t('tenant'); ?> / <?php echo t('payer_name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="tenant_name" id="tenant_name" class="form-control bg-light" readonly required placeholder="<?php echo htmlspecialchars(t('payer_name_placeholder')); ?>">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <label for="amount" class="form-label fw-bold"><?php echo t('payment_amount'); ?> (<?php echo t('baht'); ?>) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control form-control-lg fw-bold text-success bg-light" readonly required placeholder="0.00">

                    </div>
                    <div class="col-12 col-md-6">
                        <label for="payment_method" class="form-label fw-bold"><?php echo t('payment_method'); ?></label>
                        <select name="payment_method" id="payment_method" class="form-select form-select-lg">
                            <option value="promptpay">PromptPay</option>
                            <option value="bank_transfer"><?php echo t('bank_transfer_settings'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <label for="transfer_date" class="form-label fw-bold"><?php echo t('transfer_date'); ?> <span class="text-danger">*</span></label>
                        <input type="date" name="transfer_date" id="transfer_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="transfer_time" class="form-label fw-bold"><?php echo t('transfer_time'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="transfer_time" id="transfer_time" class="form-control bg-white" value="<?php echo date('H:i'); ?>" placeholder="14:30" pattern="^([01]\d|2[0-3]):[0-5]\d$" maxlength="5" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="slip_image" class="form-label fw-bold"><?php echo t('upload_slip'); ?> <span class="text-danger">*</span></label>
                    <input type="file" name="slip_image" id="slip_image" class="form-control" accept="image/jpeg,image/png,image/webp" required onchange="previewSlip(this)">
                    <div class="form-text"><?php echo $isEnglish ? 'Supported formats: JPG, PNG, WEBP (Max 5MB)' : 'รองรับไฟล์ภาพ JPG, PNG, WEBP (ขนาดไม่เกิน 5MB)'; ?></div>
                    <img id="slipPreview" src="" alt="Preview" class="preview-img" style="display: none;">
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100 py-3 fw-bold">
                    <i class="bi bi-send me-2"></i><?php echo t('confirm_payment'); ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<footer class="footer">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($dormName); ?>. All rights reserved.</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof flatpickr !== 'undefined') {
        flatpickr("#transfer_time", {
            enableTime: true,
            noCalendar: true,
            dateFormat: "H:i",
            time_24hr: true,
            allowInput: true
        });
    }
});
let loadedBillsData = [];

function resetBillFields() {
    const billSelectGroup = document.getElementById('billSelectGroup');
    const billSelect = document.getElementById('bill_id');
    const tenantInput = document.getElementById('tenant_name');
    const amountInput = document.getElementById('amount');

    if (billSelectGroup) billSelectGroup.style.display = 'none';
    if (billSelect) billSelect.innerHTML = '<option value="0">-- <?php echo t('select'); ?> --</option>';
    if (tenantInput) tenantInput.value = '';
    if (amountInput) amountInput.value = '';
    loadedBillsData = [];
}

function getSelectedBillType() {
    const typeRadio = document.querySelector('input[name="bill_type"]:checked');
    if (typeRadio) return typeRadio.value;
    const hiddenInput = document.querySelector('input[name="bill_type"]');
    if (hiddenInput) return hiddenInput.value;
    return '<?php echo htmlspecialchars($formBillType); ?>';
}

function loadRooms() {
    const type = getSelectedBillType();
    const roomSelect = document.getElementById('room_number');
    if (!roomSelect) return;

    resetBillFields();
    roomSelect.innerHTML = '<option value=""><?php echo t('select'); ?>...</option>';

    fetch('payment-notice.php?ajax_action=get_rooms&bill_type=' + encodeURIComponent(type))
        .then(res => res.json())
        .then(data => {
            (data.rooms || []).forEach(room => {
                const option = document.createElement('option');
                option.value = room;
                option.textContent = '<?php echo addslashes(t('room')); ?> ' + room;
                roomSelect.appendChild(option);
            });
        })
        .catch(err => console.error(err));
}

function loadBills() {
    const roomSelect = document.getElementById('room_number');
    const type = getSelectedBillType();
    const room = roomSelect ? roomSelect.value : '';
    const billSelectGroup = document.getElementById('billSelectGroup');
    const billSelect = document.getElementById('bill_id');

    if (!room) {
        resetBillFields();
        return;
    }

    fetch('payment-notice.php?ajax_action=get_bills&room_number=' + encodeURIComponent(room) + '&bill_type=' + encodeURIComponent(type))
        .then(res => res.json())
        .then(data => {
            loadedBillsData = data.bills || [];
            billSelect.innerHTML = '<option value="0">-- <?php echo t('select'); ?> --</option>';

            if (loadedBillsData.length > 0) {
                loadedBillsData.forEach(b => {
                    const opt = document.createElement('option');
                    opt.value = b.id;
                    opt.textContent = b.label;
                    billSelect.appendChild(opt);
                });
                billSelectGroup.style.display = 'block';

                // Pre-select first bill if available
                billSelect.selectedIndex = 1;
                onBillSelected();
            } else {
                resetBillFields();
            }
        })
        .catch(err => {
            resetBillFields();
            console.error(err);
        });
}

function onBillSelected() {
    const billId = parseInt(document.getElementById('bill_id').value, 10);
    const found = loadedBillsData.find(b => parseInt(b.id, 10) === billId);
    if (found) {
        document.getElementById('amount').value = found.total.toFixed(2);
        document.getElementById('tenant_name').value = found.tenant_name || '';
    } else {
        document.getElementById('amount').value = '';
        document.getElementById('tenant_name').value = '';
    }
}

function copyPaymentTrackingCode() {
    const codeElement = document.getElementById('paymentTrackingCode');
    if (!codeElement) return;
    const code = codeElement.innerText;
    navigator.clipboard.writeText(code).then(() => {
        alert('<?php echo addslashes(t('code_copied')); ?>: ' + code);
    });
}

function previewSlip(input) {
    const preview = document.getElementById('slipPreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.style.display = 'none';
    }
}
</script>
</body>
</html>
