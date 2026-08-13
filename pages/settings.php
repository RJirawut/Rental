<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
require_once __DIR__ . '/../includes/pin-lockscreen-check.php';

$pageTitle = t('settings');
ensureSmtpColumns();
ensureDailyPaymentDeadlineHoursColumn();
ensurePaymentColumns();
$settings = getSettings();
$storageUsage = getStorageUsage();
$currentColor = $settings['primary_color'] ?? '#0d6efd';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $currentColor)) {
    $currentColor = '#0d6efd';
}

// Check if this is an API request
$isApi = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $dormName = sanitize($_POST['dorm_name'] ?? '');
    $dormNameEn = sanitize($_POST['dorm_name_en'] ?? '');
    $companyName = sanitize($_POST['company_name'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $addressEn = sanitize($_POST['address_en'] ?? '');
    $taxId = sanitize($_POST['tax_id'] ?? '');
    
    $branchNumber = sanitize($_POST['branch_number'] ?? '00000');
    $phone = normalizePhoneDigits($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Validate tax_id: if provided, must be exactly 13 digits
    if (!empty($taxId) && !preg_match('/^\d{13}$/', $taxId)) {
        $error = t('tax_id_13_digits_only');
    }

    if (!isset($error) && !empty($phone) && !preg_match('/^\d+$/', $phone)) {
        $error = t('phone_digits_only');
    }

    $enableDaily = isset($_POST['enable_daily']) ? 1 : 0;
    $enableMonthly = isset($_POST['enable_monthly']) ? 1 : 0;

    if (!isset($error) && $enableDaily === 0 && $enableMonthly === 0) {
        $error = t('must_select_at_least_one_rental_type');
    }

    $dailyPaymentDeadlineHours = intval($_POST['daily_payment_deadline_hours'] ?? 24);
    $dailyPaymentDeadlineHours = max(1, min(168, $dailyPaymentDeadlineHours)); // Max 7 days (168 hours)

    $waterRate = floatval($_POST['water_rate'] ?? 25);
    $electricRate = floatval($_POST['electric_rate'] ?? 8);
    $vatRate = floatval($_POST['vat_rate'] ?? 7);
    $paymentDueDay = intval($_POST['payment_due_day'] ?? 5);
    $primaryColor = sanitize($_POST['primary_color'] ?? '#0d6efd');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor)) {
        $primaryColor = '#0d6efd';
    }
    $paymentDueDay = max(1, min(31, $paymentDueDay));
    
    // Handle logo upload
    $logo = $settings['logo'] ?? '';
    
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadImage($_FILES['logo'], 'logo/');
        if ($uploadResult['success']) {
            // Delete old logo
            $oldLogo = basename($logo);
            if ($logo && $oldLogo === $logo && file_exists(UPLOAD_PATH . 'logo/' . $oldLogo)) {
                unlink(UPLOAD_PATH . 'logo/' . $oldLogo);
            }
            $logo = $uploadResult['filename'];
            setFlashMessage('success', 'Logo uploaded: ' . $logo);
        } else {
            setFlashMessage('error', 'Logo upload failed: ' . $uploadResult['message']);
        }
    } else {
        // Keep existing logo if no new file uploaded
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            setFlashMessage('error', 'Logo upload error: ' . $_FILES['logo']['error']);
        }
    }

    $dbPin = $settings['pin'] ?? null;
    if (!empty($dbPin)) {
        $pinResult = verifySystemPin(trim($_POST['confirm_pin'] ?? ''));
        if (empty($pinResult['success'])) {
            if (!empty($pinResult['logout'])) {
                header('Location: ' . BASE_URL . 'pages/auth/login.php?reason=suspended');
                exit;
            }
            $error = $pinResult['message'] ?? t('confirm_pin_incorrect');
        }
    }

    // Handle PIN update
    $pin = $settings['pin'] ?? null;
    if (isset($_POST['disable_pin']) && $_POST['disable_pin'] == '1') {
        $pin = null;
    } elseif (!empty($_POST['new_pin'])) {
        $newPin = sanitize($_POST['new_pin']);
        if (preg_match('/^\d{6}$/', $newPin)) {
            $pin = password_hash($newPin, PASSWORD_DEFAULT);
        } else {
            $error = t('pin_must_be_6_digits');
        }
    }

    // SMTP settings
    $smtpHost       = trim($_POST['smtp_host'] ?? '');
    $smtpPort       = (int) ($_POST['smtp_port'] ?? 587);
    $smtpUsername   = trim($_POST['smtp_username'] ?? '');
    $smtpPassword   = trim($_POST['smtp_password'] ?? '');
    $smtpEncryption = trim($_POST['smtp_encryption'] ?? 'tls');
    $smtpFromEmail  = trim($_POST['smtp_from_email'] ?? '');
    $smtpFromName   = trim($_POST['smtp_from_name'] ?? '');
    $emailEnabled   = isset($_POST['email_enabled']) ? 1 : 0;

    // Payment settings
    $promptpayId = sanitize($_POST['promptpay_id'] ?? '');
    $promptpayName = sanitize($_POST['promptpay_name'] ?? '');
    $bankAccountName = sanitize($_POST['bank_account_name'] ?? '');
    $bankAccountNumber = sanitize($_POST['bank_account_number'] ?? '');
    $bankName = sanitize($_POST['bank_name'] ?? '');

    // Keep existing password if field is empty (placeholder shown)
    if (empty($smtpPassword) && !empty($settings['smtp_password'])) {
        $smtpPassword = $settings['smtp_password'];
    }

    if (isset($error)) {
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
        setFlashMessage('error', $error);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $stmt = $pdo->prepare("UPDATE settings SET 
        dorm_name = ?, dorm_name_en = ?, company_name = ?, address = ?, address_en = ?, 
        tax_id = ?, branch_number = ?, phone = ?, email = ?, logo = ?, 
        water_rate = ?, electric_rate = ?, vat_rate = ?, payment_due_day = ?, primary_color = ?,
        pin = ?,
        smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?,
        smtp_encryption = ?, smtp_from_email = ?, smtp_from_name = ?,
        enable_daily = ?, enable_monthly = ?, daily_payment_deadline_hours = ?,
        email_enabled = ?, promptpay_id = ?, promptpay_name = ?, bank_account_name = ?, bank_account_number = ?, bank_name = ?
        WHERE id = 1");
    
    if ($stmt->execute([$dormName, $dormNameEn, $companyName, $address, $addressEn, $taxId, $branchNumber, $phone, $email, $logo, $waterRate, $electricRate, $vatRate, $paymentDueDay, $primaryColor, $pin, $smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $smtpEncryption, $smtpFromEmail, $smtpFromName, $enableDaily, $enableMonthly, $dailyPaymentDeadlineHours, $emailEnabled, $promptpayId, $promptpayName, $bankAccountName, $bankAccountNumber, $bankName])) {
        if (!isset($uploadResult) || $uploadResult['success']) {
            logActivity('update_settings');
            if ($isApi) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => t('save_success')]);
                exit;
            }
            setFlashMessage('success', t('save_success'));
        }
    } else {
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => t('save_error')]);
            exit;
        }
        setFlashMessage('error', t('save_error') . ' SQL Error');
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="content-wrapper">
    <?php if (!$showLockScreen): ?>
    <form method="POST" action="" enctype="multipart/form-data">
        <?php echo csrfInput(); ?>
        <div class="row">
            <!-- Logo Section - First -->
            <div class="col-12 mb-4">
                <h5 class="mb-3"><i class="bi bi-image me-2"></i><?php echo t('logo'); ?></h5>
                
                <div class="row align-items-center">
                    <div class="col-md-4 text-center mb-3 mb-md-0">
                        <?php if ($settings['logo']): ?>
                        <img src="<?php echo BASE_URL; ?>assets/images/logo/<?php echo $settings['logo']; ?>" alt="Logo" style="max-height: 150px; max-width: 100%;" class="img-thumbnail">
                        <?php else: ?>
                        <div class="bg-light rounded p-4 text-muted">
                            <i class="bi bi-image fs-1"></i>
                            <p class="mb-0 mt-2"><?php echo t('no_logo'); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label"><?php echo t('upload_logo'); ?></label>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <small class="text-muted"><?php echo t('supported_formats'); ?> (PNG, JPG, JPEG, GIF)</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-4 mb-md-0">
                <h5 class="mb-3"><i class="bi bi-house me-2"></i><?php echo t('dorm_info'); ?></h5>
                
                <div class="mb-3">
                    <label class="form-label"><?php echo t('dorm_name'); ?> (ไทย)</label>
                    <input type="text" name="dorm_name" class="form-control" value="<?php echo htmlspecialchars($settings['dorm_name'] ?? ''); ?>" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><?php echo t('dorm_name'); ?> (English)</label>
                    <input type="text" name="dorm_name_en" class="form-control" value="<?php echo htmlspecialchars($settings['dorm_name_en'] ?? ''); ?>">
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><?php echo t('address'); ?> (ไทย)</label>
                    <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($settings['address'] ?? ''); ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><?php echo t('address'); ?> (English)</label>
                    <textarea name="address_en" class="form-control" rows="3"><?php echo htmlspecialchars($settings['address_en'] ?? ''); ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><?php echo t('tax_id'); ?></label>
                    <input type="text" name="tax_id" id="taxIdInput" class="form-control" value="<?php echo htmlspecialchars($settings['tax_id'] ?? ''); ?>" pattern="[0-9]{13}" maxlength="13" title="<?php echo t('tax_id_13_digits_only'); ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <small class="text-muted"><?php echo t('tax_id_13_digits_help'); ?></small>
                </div>
                
                <div id="taxRelatedFields" style="display: <?php echo !empty($settings['tax_id']) ? 'block' : 'none'; ?>;">
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('company_name'); ?></label>
                        <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>">
                        <small class="text-muted"><?php echo t('company_name_help'); ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('branch_number'); ?></label>
                        <input type="text" name="branch_number" class="form-control" value="<?php echo htmlspecialchars($settings['branch_number'] ?? '00000'); ?>" maxlength="5">
                        <small class="text-muted"><?php echo t('branch_number_help'); ?></small>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><?php echo t('phone'); ?></label>
                    <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($settings['phone'] ?? ''); ?>" inputmode="numeric" pattern="[0-9]*" title="<?php echo t('phone_digits_only'); ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><?php echo t('email'); ?></label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($settings['email'] ?? ''); ?>" pattern="[^@\s]+@[^@\s]+\.[^@\s]+" title="<?php echo t('invalid_email_format'); ?>">
                </div>
            </div>
            
            <!-- Rental Type Settings -->
            <div class="col-md-12 mb-4">
                <hr class="my-4">
                <h5 class="mb-3"><i class="bi bi-gear-wide-connected me-2"></i><?php echo ($_SESSION['lang'] ?? 'th') === 'en' ? 'Rental System Settings' : 'ตั้งค่าระบบประเภทหอพัก'; ?></h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="enable_daily" id="enable_daily" value="1" <?php echo ($settings['enable_daily'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="enable_daily">
                                <?php echo t('enable_daily_rental'); ?>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="enable_monthly" id="enable_monthly" value="1" <?php echo ($settings['enable_monthly'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="enable_monthly">
                                <?php echo t('enable_monthly_rental'); ?>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Time Settings -->
            <div class="col-md-12 mb-4">
                <hr class="my-4">
                <h5 class="mb-3"><i class="bi bi-clock me-2"></i><?php echo ($_SESSION['lang'] ?? 'th') === 'en' ? 'Time Settings' : 'กำหนดระยะเวลา'; ?></h5>
                <div class="row">
                    <div class="col-md-6 mb-3" id="dailyPaymentDeadlineField" style="display: <?php echo ($settings['enable_daily'] ?? 1) ? 'block' : 'none'; ?>;">
                        <label class="form-label"><?php echo ($_SESSION['lang'] ?? 'th') === 'en' ? 'Payment Deadline (Daily Tenants)' : 'ระยะเวลากำหนดชำระ (ผู้เช่ารายวัน)'; ?></label>
                        <input type="number" name="daily_payment_deadline_hours" class="form-control" value="<?php echo $settings['daily_payment_deadline_hours'] ?? 24; ?>" min="1" max="168">
                    </div>
                    <div class="col-md-6 mb-3" id="monthlyPaymentDueDayField" style="display: <?php echo ($settings['enable_monthly'] ?? 1) ? 'block' : 'none'; ?>;">
                        <label class="form-label"><?php echo ($_SESSION['lang'] ?? 'th') === 'en' ? 'Payment Due Day (Monthly Tenants)' : 'วันที่กำหนดชำระ (ผู้เช่ารายเดือน)'; ?></label>
                        <input type="number" name="payment_due_day" class="form-control" value="<?php echo $settings['payment_due_day'] ?? 5; ?>" min="1" max="31" required>
                    </div>
                </div>
            </div>
            
            <!-- Rates -->
            <div class="col-md-12 mb-4" id="ratesSection" style="display: <?php echo (($settings['enable_monthly'] ?? 1) || !empty($settings['tax_id'])) ? 'block' : 'none'; ?>;">
                <hr class="my-4">
                <h5 class="mb-3"><i class="bi bi-cash me-2"></i><?php echo t('rates'); ?></h5>
                <div class="row">
                    <div class="col-md-4 mb-3" id="waterRateField" style="display: <?php echo ($settings['enable_monthly'] ?? 1) ? 'block' : 'none'; ?>;">
                        <label class="form-label"><?php echo t('water_rate'); ?> (<?php echo t('baht_per_unit'); ?>)</label>
                        <input type="number" name="water_rate" class="form-control" value="<?php echo $settings['water_rate'] ?? 25; ?>" step="0.01" min="0">
                    </div>
                    <div class="col-md-4 mb-3" id="electricRateField" style="display: <?php echo ($settings['enable_monthly'] ?? 1) ? 'block' : 'none'; ?>;">
                        <label class="form-label"><?php echo t('electric_rate'); ?> (<?php echo t('baht_per_unit'); ?>)</label>
                        <input type="number" name="electric_rate" class="form-control" value="<?php echo $settings['electric_rate'] ?? 8; ?>" step="0.01" min="0">
                    </div>
                    <div class="col-md-4 mb-3" id="vatField" style="display: <?php echo !empty($settings['tax_id']) ? 'block' : 'none'; ?>;">
                        <label class="form-label"><?php echo t('vat_rate_label'); ?></label>
                        <input type="number" name="vat_rate" class="form-control" value="<?php echo $settings['vat_rate'] ?? 7; ?>" step="0.01" min="0" max="100">
                    </div>
                </div>
            </div>
            
            <div class="col-md-12 mb-4">
                <hr class="my-4">
                <h5 class="mb-3"><i class="bi bi-shield-lock me-2"></i><?php echo t('security_pin_system'); ?></h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label"><?php echo t('set_new_pin'); ?></label>
                        <input type="password" name="new_pin" class="form-control" placeholder="<?php echo t('new_pin_placeholder'); ?>" maxlength="6" pattern="[0-9]{6}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <small class="text-muted"><?php echo t('new_pin_help'); ?></small>
                    </div>
                    <?php if (!empty($settings['pin'])): ?>
                    <div class="col-md-6 mb-3 d-flex align-items-center">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" name="disable_pin" id="disable_pin" value="1">
                            <label class="form-check-label text-danger fw-bold" for="disable_pin">
                                <i class="bi bi-exclamation-triangle me-1"></i><?php echo t('disable_pin_system'); ?>
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-md-12 mb-4">
                <hr class="my-4">
                <h5 class="mb-3"><i class="bi bi-palette me-2"></i><?php echo t('theme_color'); ?></h5>
                
                <div class="mb-3">
                    <!-- Custom Color Picker -->
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('select_color'); ?></label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="color" id="customColorPicker" class="form-control form-control-color" value="<?php echo $currentColor; ?>" title="<?php echo t('select_color'); ?>">
                            <span class="text-muted"><?php echo t('or_select_preset_color'); ?></span>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 flex-wrap mb-3">
                        <?php
                        $primaryColors = [
                            '#0d6efd' => 'Blue',
                            '#dc3545' => 'Red',
                            '#198754' => 'Green',
                            '#ffc107' => 'Yellow',
                            '#fd7e14' => 'Orange',
                            '#6f42c1' => 'Purple',
                            '#d63384' => 'Pink',
                            '#0dcaf0' => 'Cyan',
                            '#6610f2' => 'Indigo',
                            '#20c997' => 'Teal',
                            '#795548' => 'Brown',
                            '#607d8b' => 'Blue Grey',
                            '#1e3a5f' => 'Dark Navy',
                            '#BAE1FF' => 'Pastel Blue',
                            '#FF6B6B' => 'Pastel Red',
                            '#BAFFC9' => 'Pastel Green',
                            '#FFFFBA' => 'Pastel Yellow',
                            '#FFDFBA' => 'Pastel Orange',
                            '#E0BBE4' => 'Pastel Purple',
                            '#FFB3BA' => 'Pastel Pink'
                        ];
                        // Calculate darker shade for preview
                        list($r, $g, $b) = sscanf($currentColor, "#%02x%02x%02x");
                        $darkColor = sprintf("#%02x%02x%02x", max($r - 40, 0), max($g - 40, 0), max($b - 40, 0));
                        foreach ($primaryColors as $color => $name):
                        ?>
                        <label class="theme-color-option">
                            <input type="radio" name="primary_color" value="<?php echo $color; ?>" <?php echo $currentColor === $color ? 'checked' : ''; ?>>
                            <span class="color-swatch" style="background-color: <?php echo $color; ?>;" title="<?php echo $name; ?>"></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Preview -->
                    <div id="sidebar-preview" class="sidebar-preview" style="background: linear-gradient(180deg, <?php echo $darkColor; ?> 0%, <?php echo $currentColor; ?> 100%); padding: 20px; border-radius: 12px; max-width: 320px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);">
                        <div id="preview-header" class="d-flex align-items-center gap-3 mb-3" style="color: white;">
                            <i class="bi bi-house-door fs-5"></i>
                            <span class="fw-semibold" style="font-size: 1.1rem; letter-spacing: 0.5px;"><?php echo t('preview'); ?></span>
                        </div>
                        <div id="preview-box" style="background: rgba(255,255,255,0.15); padding: 12px; border-radius: 8px; backdrop-filter: blur(5px);">
                            <small id="preview-text" style="color: white; display: block; line-height: 1.5;"><?php echo t('sidebar_preview_text'); ?></small>
                        </div>
                    </div>
                </div>
                
                <style>
                    .theme-color-option {
                        display: inline-block;
                        cursor: pointer;
                    }
                    .theme-color-option input {
                        display: none;
                    }
                    .color-swatch {
                        display: block;
                        width: 40px;
                        height: 40px;
                        border-radius: 8px;
                        border: 3px solid transparent;
                        transition: all 0.2s;
                    }
                    .theme-color-option input:checked + .color-swatch {
                        border-color: #000;
                        transform: scale(1.1);
                    }
                    .theme-color-option:hover .color-swatch {
                        transform: scale(1.1);
                    }
                    .form-control-color {
                        width: 60px;
                        height: 40px;
                        padding: 0;
                        border: none;
                        border-radius: 8px;
                        cursor: pointer;
                    }
                    .form-control-color::-webkit-color-swatch-wrapper {
                        padding: 0;
                    }
                    .form-control-color::-webkit-color-swatch {
                        border: none;
                        border-radius: 8px;
                        border: 2px solid #dee2e6;
                    }
                    .sidebar-preview {
                        transition: all 0.3s ease;
                    }
                    .sidebar-preview:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 6px 20px rgba(0,0,0,0.4) !important;
                    }
                    #sidebar-preview i {
                        filter: drop-shadow(0 2px 3px rgba(0,0,0,0.4));
                    }
                </style>
                
                <script>
                    // Translation helper
                    function t(key) {
                        const translations = {
                            'please_enter_test_email': '<?php echo t('please_enter_test_email'); ?>',
                            'sending_email': '<?php echo t('sending_email'); ?>',
                            'send_test_email': '<?php echo t('send_test_email'); ?>',
                            'connection_error': '<?php echo t('connection_error'); ?>',
                            'please_enter_pin': '<?php echo t('please_enter_pin'); ?>',
                            'pin_must_be_6_digits': '<?php echo t('pin_must_be_6_digits'); ?>',
                            'clear_yearly_confirm_title': '<?php echo t('clear_yearly_confirm_title'); ?>',
                            'clear_yearly_confirm_text': '<?php echo t('clear_yearly_confirm_text'); ?>',
                            'clear_yearly_success': '<?php echo t('clear_yearly_success'); ?>',
                            'clear_yearly_error': '<?php echo t('clear_yearly_error'); ?>',
                            'end_year_invalid': '<?php echo t('end_year_invalid'); ?>',
                            'must_select_at_least_one_rental_type': '<?php echo t('must_select_at_least_one_rental_type'); ?>',
                            'cleanup_activity_logs_confirm_title': '<?php echo t('clear_cache_confirm_title'); ?>',
                            'cleanup_activity_logs_confirm_text': '<?php echo t('clear_cache_confirm_text'); ?>',
                            'cleanup_activity_logs_success': '<?php echo t('clear_cache_success'); ?>',
                            'cleanup_activity_logs_error': '<?php echo t('clear_cache_error'); ?>'
                        };
                        return translations[key] || key;
                    }

                    function testSmtp() {
                        const btn = document.getElementById('btnTestSmtp');
                        const result = document.getElementById('smtpTestResult');
                        const testEmail = document.getElementById('testEmailAddress').value.trim();
                        
                        if (!testEmail) {
                            result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + t('please_enter_test_email') + '</span>';
                            return;
                        }
                        
                        btn.disabled = true;
                        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + t('sending_email');
                        result.innerHTML = '';
                        
                        const form = document.querySelector('form');
                        const formData = new FormData();
                        formData.append('test_email', testEmail);
                        formData.append('smtp_host', form.querySelector('[name="smtp_host"]').value);
                        formData.append('smtp_port', form.querySelector('[name="smtp_port"]').value);
                        formData.append('smtp_username', form.querySelector('[name="smtp_username"]').value);
                        
                        const passwordField = form.querySelector('[name="smtp_password"]');
                        const passwordValue = passwordField.value.trim();
                        // If password is empty and placeholder shows dots, don't send password (use saved one)
                        if (passwordValue === '' && passwordField.placeholder.includes('•')) {
                            // Don't append password - API will use saved one
                        } else {
                            formData.append('smtp_password', passwordValue);
                        }
                        
                        formData.append('smtp_encryption', form.querySelector('[name="smtp_encryption"]').value);
                        formData.append('smtp_from_name', form.querySelector('[name="smtp_from_name"]').value);
                        formData.append('_csrf_token', '<?php echo csrfToken(); ?>');
                        
                        fetch('<?php echo BASE_URL; ?>api/test-smtp.php', {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                        .then(res => res.json())
                        .then(data => {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-send me-1"></i>' + t('send_test_email');
                            if (data.success) {
                                result.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + data.message + '</span>';
                            } else {
                                result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + data.error + '</span>';
                            }
                        })
                        .catch(err => {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-send me-1"></i>' + t('send_test_email');
                            result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + t('connection_error') + '</span>';
                        });
                    }

                    function toggleSmtpFields() {
                        const isEnabled = document.getElementById('email_enabled').checked;
                        const container = document.getElementById('smtpFieldsContainer');
                        const inputs = container.querySelectorAll('.smtp-input');
                        
                        if (isEnabled) {
                            container.style.opacity = '1';
                            container.style.pointerEvents = 'auto';
                            inputs.forEach(input => {
                                input.disabled = false;
                            });
                        } else {
                            container.style.opacity = '0.5';
                            container.style.pointerEvents = 'none';
                            inputs.forEach(input => {
                                input.disabled = true;
                            });
                        }
                    }

                    function exportYearlyData() {
                        const year = document.getElementById('exportYearSelect').value;
                        window.location.href = '<?php echo BASE_URL; ?>pages/reports/yearly_export.php?year=' + year;
                    }

                    let clearYearlyModal = null;
                    let clearYearlyPinModal = null;
                    let isClearingYearlyData = false;

                    function confirmClearYearlyData() {
                        if (isClearingYearlyData) return;
                        
                        const startYear = parseInt(document.getElementById('clearYearStartSelect').value);
                        const endYear = parseInt(document.getElementById('clearYearEndSelect').value);
                        
                        if (endYear < startYear) {
                            alert(t('end_year_invalid'));
                            return;
                        }

                        const mainBtn = document.querySelector('[onclick="confirmClearYearlyData()"]');
                        if (mainBtn) {
                            mainBtn.disabled = true;
                            mainBtn.setAttribute('data-original-html', mainBtn.innerHTML);
                            mainBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> กำลังตรวจสอบ...';
                        }
                        
                        // Pre-check if data exists before showing confirmation
                        const formData = new FormData();
                        formData.append('start_year', startYear);
                        formData.append('end_year', endYear);
                        formData.append('check_only', '1');
                        formData.append('_csrf_token', '<?php echo csrfToken(); ?>');
                        
                        fetch('<?php echo BASE_URL; ?>api/clear-yearly-data.php', {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (mainBtn) {
                                mainBtn.disabled = false;
                                mainBtn.innerHTML = mainBtn.getAttribute('data-original-html');
                            }
                            
                            if (!data.success) {
                                // No data found — show Swal popup (separate from PIN)
                                Swal.fire({
                                    icon: 'warning',
                                    title: '<?php echo t("error"); ?>',
                                    text: data.error || t('clear_yearly_error'),
                                    confirmButtonColor: 'var(--primary-color, #0d6efd)'
                                });
                                return;
                            }
                            
                            // Data exists — proceed to show confirmation modal
                            showClearConfirmModal(startYear, endYear);
                        })
                        .catch(err => {
                            if (mainBtn) {
                                mainBtn.disabled = false;
                                mainBtn.innerHTML = mainBtn.getAttribute('data-original-html');
                            }
                            Swal.fire({
                                icon: 'error',
                                title: '<?php echo t("error"); ?>',
                                text: t('connection_error'),
                                confirmButtonColor: 'var(--primary-color, #0d6efd)'
                            });
                        });
                    }

                    function showClearConfirmModal(startYear, endYear) {
                        document.getElementById('confirmModalTitle').textContent = t('clear_yearly_confirm_title');
                        
                        const rangeText = (startYear === endYear) ? startYear : startYear + ' - ' + endYear;
                        const rawText = t('clear_yearly_confirm_text');
                        const formattedText = rawText.replace('%s', rangeText);
                        document.getElementById('confirmModalBodyText').textContent = formattedText;
                        
                        if (!clearYearlyModal) {
                            clearYearlyModal = new bootstrap.Modal(document.getElementById('clearDataConfirmModal'));
                        }
                        
                        const confirmBtn = document.getElementById('confirmClearBtn');
                        const newConfirmBtn = confirmBtn.cloneNode(true);
                        confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
                        
                        newConfirmBtn.disabled = false;
                        newConfirmBtn.addEventListener('click', function() {
                            if (newConfirmBtn.disabled) return;
                            newConfirmBtn.disabled = true;
                            
                            const hasPin = <?php echo !empty($settings['pin']) ? 'true' : 'false'; ?>;
                            const modalEl = document.getElementById('clearDataConfirmModal');
                            modalEl.addEventListener('hidden.bs.modal', function() {
                                if (hasPin) {
                                    promptClearDataPin(startYear, endYear);
                                } else {
                                    executeClearYearlyData(startYear, endYear, '');
                                }
                            }, { once: true });
                            clearYearlyModal.hide();
                        });
                        
                        clearYearlyModal.show();
                    }

                    function promptClearDataPin(startYear, endYear) {
                        if (isClearingYearlyData) return;
                        
                        const pinInput = document.getElementById('clearDataPinInput');
                        const submitBtn = document.getElementById('submitClearDataPinBtn');
                        const errorMsg = document.getElementById('clearDataPinErrorMsg');
                        
                        pinInput.value = '';
                        errorMsg.classList.add('d-none');
                        
                        if (!clearYearlyPinModal) {
                            clearYearlyPinModal = new bootstrap.Modal(document.getElementById('clearDataPinModal'));
                        }
                        
                        const newSubmitBtn = submitBtn.cloneNode(true);
                        submitBtn.parentNode.replaceChild(newSubmitBtn, submitBtn);
                        newSubmitBtn.disabled = false;
                        
                        newSubmitBtn.addEventListener('click', submitPin);
                        
                        const newPinInput = pinInput.cloneNode(true);
                        pinInput.parentNode.replaceChild(newPinInput, pinInput);
                        
                        newPinInput.addEventListener('keypress', function(e) {
                            if (e.key === 'Enter') {
                                submitPin();
                            }
                        });
                        
                        function submitPin() {
                            if (newSubmitBtn.disabled) return;
                            
                            const pinVal = newPinInput.value.trim();
                            if (!pinVal) {
                                errorMsg.textContent = t('please_enter_pin');
                                errorMsg.classList.remove('d-none');
                                return;
                            }
                            if (pinVal.length !== 6 || !/^\d+$/.test(pinVal)) {
                                errorMsg.textContent = t('pin_must_be_6_digits');
                                errorMsg.classList.remove('d-none');
                                return;
                            }
                            
                            newSubmitBtn.disabled = true;
                            errorMsg.classList.add('d-none');
                            
                            executeClearYearlyData(startYear, endYear, pinVal, newSubmitBtn, errorMsg);
                        }
                        
                        clearYearlyPinModal.show();
                        setTimeout(() => newPinInput.focus(), 500);
                    }

                    function executeClearYearlyData(startYear, endYear, pinVal, submitBtn = null, errorMsg = null) {
                        if (isClearingYearlyData) return;
                        isClearingYearlyData = true;

                        const mainBtn = document.getElementById('clearYearlyDataBtn');
                        if (mainBtn) {
                            mainBtn.disabled = true;
                            if (!mainBtn.hasAttribute('data-original-html')) {
                                mainBtn.setAttribute('data-original-html', mainBtn.innerHTML);
                            }
                            mainBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';
                        }

                        const formData = new FormData();
                        formData.append('start_year', startYear);
                        formData.append('end_year', endYear);
                        formData.append('pin', pinVal);
                        formData.append('_csrf_token', '<?php echo csrfToken(); ?>');
                        
                        fetch('<?php echo BASE_URL; ?>api/clear-yearly-data.php', {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (submitBtn) submitBtn.disabled = false;
                            
                            if (data.logout) {
                                window.location.href = '<?php echo BASE_URL; ?>pages/auth/login.php?reason=suspended';
                                return;
                            }
                            
                            if (data.success) {
                                // Hide PIN modal first, then show Swal success message after it's fully hidden
                                if (clearYearlyPinModal) {
                                    const pinModalEl = document.getElementById('clearDataPinModal');
                                    pinModalEl.addEventListener('hidden.bs.modal', function() {
                                        Swal.fire({
                                            icon: 'success',
                                            title: '<?php echo t("success"); ?>',
                                            text: data.message || t('clear_yearly_success'),
                                            confirmButtonColor: 'var(--primary-color, #0d6efd)',
                                            timer: 2000,
                                            showConfirmButton: true
                                        }).then(() => {
                                            window.location.reload();
                                        });
                                    }, { once: true });
                                    clearYearlyPinModal.hide();
                                } else {
                                    Swal.fire({
                                        icon: 'success',
                                        title: '<?php echo t("success"); ?>',
                                        text: data.message || t('clear_yearly_success'),
                                        confirmButtonColor: 'var(--primary-color, #0d6efd)',
                                        timer: 2000,
                                        showConfirmButton: true
                                    }).then(() => {
                                        window.location.reload();
                                    });
                                }
                            } else {
                                isClearingYearlyData = false;
                                
                                if (mainBtn) {
                                    mainBtn.disabled = false;
                                    mainBtn.innerHTML = mainBtn.getAttribute('data-original-html');
                                }
                                
                                // Re-enable confirm clear button in case of failure without PIN
                                const confirmBtn = document.getElementById('confirmClearBtn');
                                if (confirmBtn) confirmBtn.disabled = false;
                                
                                if (errorMsg && data.pin_error) {
                                    errorMsg.textContent = data.error || t('clear_yearly_error');
                                    errorMsg.classList.remove('d-none');
                                    const pinInput = document.getElementById('clearDataPinInput');
                                    if (pinInput) {
                                        pinInput.value = '';
                                        pinInput.focus();
                                    }
                                } else {
                                    if (clearYearlyPinModal) {
                                        clearYearlyPinModal.hide();
                                    }
                                    
                                    Swal.fire({
                                        icon: 'error',
                                        title: '<?php echo t("error"); ?>',
                                        text: data.error || t('clear_yearly_error'),
                                        confirmButtonColor: 'var(--primary-color, #0d6efd)'
                                    });
                                }
                            }
                        })
                        .catch(err => {
                            isClearingYearlyData = false;
                            
                            if (submitBtn) submitBtn.disabled = false;
                            
                            if (mainBtn) {
                                mainBtn.disabled = false;
                                mainBtn.innerHTML = mainBtn.getAttribute('data-original-html');
                            }
                            
                            const confirmBtn = document.getElementById('confirmClearBtn');
                            if (confirmBtn) confirmBtn.disabled = false;
                            
                            if (errorMsg) {
                                errorMsg.textContent = t('connection_error');
                                errorMsg.classList.remove('d-none');
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: '<?php echo t("error"); ?>',
                                    text: t('connection_error'),
                                    confirmButtonColor: 'var(--primary-color, #0d6efd)'
                                });
                            }
                        });
                    }

                    window.confirmClearYearlyData = confirmClearYearlyData;
                    window.promptClearDataPin = promptClearDataPin;
                    window.executeClearYearlyData = executeClearYearlyData;

                    let cleanupActivityLogsModal = null;
                    let cleanupActivityLogsPinModal = null;
                    let isCleaningActivityLogs = false;

                    function confirmCleanupActivityLogs() {
                        if (isCleaningActivityLogs) return;

                        const hasPin = <?php echo !empty($settings['pin']) ? 'true' : 'false'; ?>;

                        Swal.fire({
                            icon: 'warning',
                            title: t('cleanup_activity_logs_confirm_title'),
                            text: t('cleanup_activity_logs_confirm_text'),
                            showCancelButton: true,
                            confirmButtonColor: '#ffc107',
                            cancelButtonColor: '#6c757d',
                            confirmButtonText: 'ล้างข้อมูล',
                            cancelButtonText: 'ยกเลิก'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                if (hasPin) {
                                    promptCleanupActivityLogsPin();
                                } else {
                                    executeCleanupActivityLogs('');
                                }
                            }
                        });
                    }

                    function promptCleanupActivityLogsPin() {
                        if (isCleaningActivityLogs) return;

                        const pinInput = document.getElementById('cleanupActivityLogsPinInput');
                        const submitBtn = document.getElementById('submitCleanupActivityLogsPinBtn');
                        const errorMsg = document.getElementById('cleanupActivityLogsPinErrorMsg');

                        pinInput.value = '';
                        errorMsg.classList.add('d-none');

                        if (!cleanupActivityLogsPinModal) {
                            cleanupActivityLogsPinModal = new bootstrap.Modal(document.getElementById('cleanupActivityLogsPinModal'));
                        }

                        const newSubmitBtn = submitBtn.cloneNode(true);
                        submitBtn.parentNode.replaceChild(newSubmitBtn, submitBtn);
                        newSubmitBtn.disabled = false;

                        newSubmitBtn.addEventListener('click', submitPin);

                        const newPinInput = pinInput.cloneNode(true);
                        pinInput.parentNode.replaceChild(newPinInput, pinInput);

                        newPinInput.addEventListener('keypress', function(e) {
                            if (e.key === 'Enter') {
                                submitPin();
                            }
                        });

                        function submitPin() {
                            if (newSubmitBtn.disabled) return;

                            const pinVal = newPinInput.value.trim();
                            if (!pinVal) {
                                errorMsg.textContent = t('please_enter_pin');
                                errorMsg.classList.remove('d-none');
                                return;
                            }
                            if (pinVal.length !== 6 || !/^\d+$/.test(pinVal)) {
                                errorMsg.textContent = t('pin_must_be_6_digits');
                                errorMsg.classList.remove('d-none');
                                return;
                            }

                            newSubmitBtn.disabled = true;
                            errorMsg.classList.add('d-none');

                            executeCleanupActivityLogs(pinVal, newSubmitBtn, errorMsg);
                        }

                        cleanupActivityLogsPinModal.show();
                        setTimeout(() => newPinInput.focus(), 500);
                    }

                    function executeCleanupActivityLogs(pinVal, submitBtn = null, errorMsg = null) {
                        if (isCleaningActivityLogs) return;
                        isCleaningActivityLogs = true;

                        const mainBtn = document.getElementById('cleanupActivityLogsBtn');
                        if (mainBtn) {
                            mainBtn.disabled = true;
                            mainBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span><?php echo t('clear_cache_clearing'); ?>';
                        }

                        const formData = new FormData();
                        formData.append('pin', pinVal);
                        formData.append('_csrf_token', '<?php echo csrfToken(); ?>');

                        fetch('<?php echo BASE_URL; ?>api/cleanup-activity-logs.php', {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (submitBtn) submitBtn.disabled = false;

                            if (data.logout) {
                                window.location.href = '<?php echo BASE_URL; ?>pages/auth/login.php?reason=suspended';
                                return;
                            }

                            if (data.success) {
                                if (cleanupActivityLogsPinModal) {
                                    const pinModalEl = document.getElementById('cleanupActivityLogsPinModal');
                                    pinModalEl.addEventListener('hidden.bs.modal', function() {
                                        Swal.fire({
                                            icon: 'success',
                                            title: '<?php echo t("success"); ?>',
                                            text: data.message || t('cleanup_activity_logs_success'),
                                            confirmButtonColor: 'var(--primary-color, #0d6efd)',
                                            timer: 2000,
                                            showConfirmButton: true
                                        }).then(() => {
                                            window.location.reload();
                                        });
                                    }, { once: true });
                                    cleanupActivityLogsPinModal.hide();
                                } else {
                                    Swal.fire({
                                        icon: 'success',
                                        title: '<?php echo t("success"); ?>',
                                        text: data.message || t('cleanup_activity_logs_success'),
                                        confirmButtonColor: 'var(--primary-color, #0d6efd)',
                                        timer: 2000,
                                        showConfirmButton: true
                                    }).then(() => {
                                        window.location.reload();
                                    });
                                }
                            } else {
                                isCleaningActivityLogs = false;

                                if (mainBtn) {
                                    mainBtn.disabled = false;
                                    mainBtn.innerHTML = '<i class="bi bi-eraser me-1"></i><?php echo t('clear_cache'); ?>';
                                }

                                if (errorMsg && data.pin_error) {
                                    errorMsg.textContent = data.error || t('cleanup_activity_logs_error');
                                    errorMsg.classList.remove('d-none');
                                    const pinInput = document.getElementById('cleanupActivityLogsPinInput');
                                    if (pinInput) {
                                        pinInput.value = '';
                                        pinInput.focus();
                                    }
                                } else {
                                    if (cleanupActivityLogsPinModal) {
                                        cleanupActivityLogsPinModal.hide();
                                    }

                                    Swal.fire({
                                        icon: 'error',
                                        title: '<?php echo t("error"); ?>',
                                        text: data.error || t('cleanup_activity_logs_error'),
                                        confirmButtonColor: 'var(--primary-color, #0d6efd)'
                                    });
                                }
                            }
                        })
                        .catch(err => {
                            isCleaningActivityLogs = false;

                            if (submitBtn) submitBtn.disabled = false;

                            if (mainBtn) {
                                mainBtn.disabled = false;
                                mainBtn.innerHTML = '<i class="bi bi-eraser me-1"></i><?php echo t('clear_cache'); ?>';
                            }

                            if (errorMsg) {
                                errorMsg.textContent = t('connection_error');
                                errorMsg.classList.remove('d-none');
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: '<?php echo t("error"); ?>',
                                    text: t('connection_error'),
                                    confirmButtonColor: 'var(--primary-color, #0d6efd)'
                                });
                            }
                        });
                    }

                    window.confirmCleanupActivityLogs = confirmCleanupActivityLogs;
                    window.promptCleanupActivityLogsPin = promptCleanupActivityLogsPin;
                    window.executeCleanupActivityLogs = executeCleanupActivityLogs;

                    document.addEventListener('DOMContentLoaded', function() {
                        // Tax ID field toggle
                        const taxIdInput = document.getElementById('taxIdInput');
                        const taxRelatedFields = document.getElementById('taxRelatedFields');
                        const vatField = document.getElementById('vatField');
                        const ratesSection = document.getElementById('ratesSection');
                        
                        // Monthly rental toggle elements
                        const enableMonthlyCheckbox = document.getElementById('enable_monthly');
                        const monthlyPaymentDueDayField = document.getElementById('monthlyPaymentDueDayField');
                        const waterRateField = document.getElementById('waterRateField');
                        const electricRateField = document.getElementById('electricRateField');

                        // Toggle entire rates section visibility
                        function toggleRatesSection() {
                            const isMonthlyEnabled = enableMonthlyCheckbox && enableMonthlyCheckbox.checked;
                            const hasTaxId = taxIdInput && taxIdInput.value.trim().length > 0;
                            if (ratesSection) {
                                ratesSection.style.display = (isMonthlyEnabled || hasTaxId) ? 'block' : 'none';
                            }
                        }

                        function toggleTaxFields() {
                            const hasTaxId = taxIdInput.value.trim().length > 0;
                            taxRelatedFields.style.display = hasTaxId ? 'block' : 'none';
                            vatField.style.display = hasTaxId ? 'block' : 'none';
                            toggleRatesSection();
                        }
                        
                        taxIdInput.addEventListener('input', function() {
                            // Allow only digits
                            this.value = this.value.replace(/[^0-9]/g, '');
                            toggleTaxFields();
                        });
                        
                        // Daily rental toggle for payment deadline field
                        const enableDailyCheckbox = document.getElementById('enable_daily');
                        const dailyPaymentDeadlineField = document.getElementById('dailyPaymentDeadlineField');
                        
                        if (enableDailyCheckbox && dailyPaymentDeadlineField) {
                            enableDailyCheckbox.addEventListener('change', function() {
                                dailyPaymentDeadlineField.style.display = this.checked ? 'block' : 'none';
                            });
                        }
                        
                        // Monthly rental toggle for payment due day field and utility rates
                        if (enableMonthlyCheckbox) {
                            enableMonthlyCheckbox.addEventListener('change', function() {
                                const show = this.checked ? 'block' : 'none';
                                if (monthlyPaymentDueDayField) monthlyPaymentDueDayField.style.display = show;
                                if (waterRateField) waterRateField.style.display = show;
                                if (electricRateField) electricRateField.style.display = show;
                                toggleRatesSection();
                            });
                        }
                        
                        // Color picker functionality
                        const colorInputs = document.querySelectorAll('input[name="primary_color"]');
                        const preview = document.getElementById('sidebar-preview');
                        const customColorPicker = document.getElementById('customColorPicker');
                        const form = document.querySelector('form');
                        
                        // Function to calculate darker shade
                        function getDarkerColor(color) {
                            const r = parseInt(color.substr(1, 2), 16);
                            const g = parseInt(color.substr(3, 2), 16);
                            const b = parseInt(color.substr(5, 2), 16);
                            const darkR = Math.max(r - 40, 0);
                            const darkG = Math.max(g - 40, 0);
                            const darkB = Math.max(b - 40, 0);
                            return '#' + 
                                darkR.toString(16).padStart(2, '0') + 
                                darkG.toString(16).padStart(2, '0') + 
                                darkB.toString(16).padStart(2, '0');
                        }
                        
                        // Function to calculate brightness (0-255)
                        function getBrightness(color) {
                            const r = parseInt(color.substr(1, 2), 16);
                            const g = parseInt(color.substr(3, 2), 16);
                            const b = parseInt(color.substr(5, 2), 16);
                            // YIQ brightness formula
                            return (r * 299 + g * 587 + b * 114) / 1000;
                        }
                        
                        // Function to update preview
                        function updatePreview(color) {
                            const darkColor = getDarkerColor(color);
                            const brightness = getBrightness(color);
                            const isLight = brightness > 160;
                            
                            preview.style.background = `linear-gradient(180deg, ${darkColor} 0%, ${color} 100%)`;
                            
                            // Update text color based on brightness
                            const header = document.getElementById('preview-header');
                            const previewText = document.getElementById('preview-text');
                            const previewBox = document.getElementById('preview-box');
                            
                            if (isLight) {
                                // Light background -> Black text
                                header.style.color = '#000';
                                header.style.textShadow = 'none';
                                previewText.style.color = '#000';
                                previewText.style.textShadow = 'none';
                                previewBox.style.background = 'rgba(0,0,0,0.1)';
                            } else {
                                // Dark background -> White text
                                header.style.color = '#fff';
                                header.style.textShadow = '0 2px 4px rgba(0,0,0,0.5)';
                                previewText.style.color = '#fff';
                                previewText.style.textShadow = '0 1px 2px rgba(0,0,0,0.4)';
                                previewBox.style.background = 'rgba(255,255,255,0.15)';
                            }
                        }
                        
                        // Handle preset color radio buttons
                        colorInputs.forEach(input => {
                            input.addEventListener('change', function() {
                                if (this.checked) {
                                    customColorPicker.value = this.value;
                                    updatePreview(this.value);
                                }
                            });
                        });
                        
                        // Handle custom color picker
                        customColorPicker.addEventListener('input', function() {
                            const customColor = this.value;
                            // Uncheck all radio buttons
                            colorInputs.forEach(input => input.checked = false);
                            updatePreview(customColor);
                        });
                        
                        // Before form submission, ensure custom color is saved if no radio is checked
                        form.addEventListener('submit', function() {
                            const anyRadioChecked = Array.from(colorInputs).some(input => input.checked);
                            if (!anyRadioChecked) {
                                // Create hidden input for custom color
                                let hiddenInput = form.querySelector('input[name="primary_color"]');
                                if (!hiddenInput || hiddenInput.type === 'radio') {
                                    hiddenInput = document.createElement('input');
                                    hiddenInput.type = 'hidden';
                                    hiddenInput.name = 'primary_color';
                                    form.appendChild(hiddenInput);
                                }
                                hiddenInput.value = customColorPicker.value;
                            }
                        });
                    });
                </script>
            </div>
        </div>

        <!-- SMTP Email Settings -->
        <div class="col-md-12 mb-4">
            <hr class="my-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="mb-0"><i class="bi bi-envelope-at me-2"></i><?php echo t('smtp_settings'); ?></h5>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="email_enabled" id="email_enabled" value="1" <?php echo ($settings['email_enabled'] ?? 1) ? 'checked' : ''; ?> onchange="toggleSmtpFields()">
                    <label class="form-check-label fw-bold text-primary" for="email_enabled">
                        <?php echo t('email_enabled'); ?>
                    </label>
                </div>
            </div>
            
            <div id="smtpFieldsContainer" style="transition: all 0.3s ease;">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label"><?php echo t('smtp_host'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="smtp_host" class="form-control smtp-input" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>" placeholder="smtp.gmail.com">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label"><?php echo t('smtp_port'); ?></label>
                        <input type="number" name="smtp_port" class="form-control smtp-input" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '587'); ?>" placeholder="587">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label"><?php echo t('smtp_encryption'); ?></label>
                        <select name="smtp_encryption" class="form-select smtp-input">
                            <option value="tls" <?php echo ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                            <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label"><?php echo t('smtp_username'); ?></label>
                        <input type="text" name="smtp_username" class="form-control smtp-input" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>" placeholder="your-email@gmail.com">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label"><?php echo t('smtp_sender_name'); ?></label>
                        <input type="text" name="smtp_from_name" class="form-control smtp-input" value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? ''); ?>" placeholder="<?php echo t('smtp_sender_name_placeholder'); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label"><?php echo t('smtp_password'); ?></label>
                        <div class="input-group">
                            <input type="password" name="smtp_password" id="smtpPasswordInput" class="form-control smtp-input" value="" placeholder="<?php echo !empty($settings['smtp_password']) ? '••••••••' : t('smtp_password_placeholder'); ?>">
                            <button class="btn btn-outline-secondary smtp-input" type="button" onclick="let i=document.getElementById('smtpPasswordInput');i.type=i.type==='password'?'text':'password';this.innerHTML=i.type==='password'?'<i class=\'bi bi-eye\'></i>':'<i class=\'bi bi-eye-slash\'></i>';">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted"><?php echo t('leave_blank_keep_unchanged'); ?></small>
                    </div>
                </div>
                <!-- Test Email Button -->
                <div class="d-flex align-items-center gap-3 mt-2">
                    <div class="input-group" style="max-width:350px;">
                        <input type="email" id="testEmailAddress" class="form-control smtp-input" placeholder="<?php echo t('test_email_placeholder'); ?>" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? $settings['smtp_username'] ?? ''); ?>">
                        <button type="button" class="btn btn-outline-success smtp-input" id="btnTestSmtp" onclick="testSmtp()">
                            <i class="bi bi-send me-1"></i><?php echo t('send_test_email'); ?>
                        </button>
                    </div>
                    <span id="smtpTestResult" class="small"></span>
                </div>
            </div>
            </div>
        </div>

        <!-- Payment Settings -->
        <div class="col-md-12 mb-4">
            <hr class="my-4">
            <h5 class="mb-3"><i class="bi bi-qr-code me-2"></i><?php echo t('payment_settings'); ?></h5>
            
            <div class="row">
                <div class="col-md-6">
                    <h6 class="mb-3"><i class="bi bi-phone me-2"></i><?php echo t('promptpay_settings'); ?></h6>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('promptpay_id'); ?></label>
                        <input type="text" name="promptpay_id" class="form-control" 
                            value="<?php echo htmlspecialchars($settings['promptpay_id'] ?? ''); ?>" 
                            placeholder="0812345678"
                            oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <small class="text-muted"><?php echo t('promptpay_id_help'); ?></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('promptpay_name'); ?></label>
                        <input type="text" name="promptpay_name" class="form-control" 
                            value="<?php echo htmlspecialchars($settings['promptpay_name'] ?? ''); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="mb-3"><i class="bi bi-bank me-2"></i><?php echo t('bank_transfer_settings'); ?></h6>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('bank_name'); ?></label>
                        <input type="text" name="bank_name" class="form-control" 
                            value="<?php echo htmlspecialchars($settings['bank_name'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('bank_account_number'); ?></label>
                        <input type="text" name="bank_account_number" class="form-control" 
                            value="<?php echo htmlspecialchars($settings['bank_account_number'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo t('bank_account_name'); ?></label>
                        <input type="text" name="bank_account_name" class="form-control" 
                            value="<?php echo htmlspecialchars($settings['bank_account_name'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Storage Usage -->
        <div class="col-md-12 mb-4">
            <hr class="my-4">
            <h5 class="mb-3"><i class="bi bi-hdd me-2"></i><?php echo t('storage_usage'); ?></h5>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center g-3">
                        <div class="col-md-5">
                            <div class="text-muted small"><?php echo t('storage_total_used'); ?></div>
                            <div class="fs-2 fw-bold text-primary"><?php echo htmlspecialchars($storageUsage['total_display']); ?></div>
                            <div class="text-muted small"><?php echo t('storage_usage_help'); ?></div>
                        </div>
                        <div class="col-md-7">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="text-muted small"><i class="bi bi-database me-1"></i><?php echo t('storage_database'); ?></div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($storageUsage['database_display']); ?></div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="text-muted small"><i class="bi bi-images me-1"></i><?php echo t('storage_uploaded_files'); ?></div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($storageUsage['uploaded_files_display']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Yearly Data -->
        <div class="col-md-12 mb-4">
            <hr class="my-4">
            <h5 class="mb-3"><i class="bi bi-file-earmark-excel me-2"></i><?php echo t('export_yearly_data'); ?></h5>
            <div class="d-flex align-items-center gap-3">
                <div class="input-group" style="max-width:300px;">
                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                    <select id="exportYearSelect" class="form-select">
                        <?php
                        $currentYear = intval(date('Y'));
                        for ($y = $currentYear + 5; $y >= $currentYear - 5; $y--) {
                            $selected = ($y === $currentYear) ? 'selected' : '';
                            echo "<option value=\"$y\" $selected>$y</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="button" class="btn btn-success" onclick="exportYearlyData()">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i><?php echo t('export_excel'); ?>
                </button>
            </div>
        </div>

        <!-- Clear Yearly Data -->
        <div class="col-md-12 mb-4">
            <hr class="my-4">
            <h5 class="mb-3 text-danger"><i class="bi bi-trash3 me-2"></i><?php echo t('clear_yearly_data'); ?></h5>
            <div class="d-flex align-items-center gap-3 mt-3">
                <div class="input-group" style="max-width:450px;">
                    <span class="input-group-text"><i class="bi bi-calendar-x"></i></span>
                    <select id="clearYearStartSelect" class="form-select">
                        <option value="" disabled><?php echo t('start_year'); ?></option>
                        <?php
                        $currentYear = intval(date('Y'));
                        for ($y = $currentYear + 5; $y >= $currentYear - 5; $y--) {
                            $selected = ($y === $currentYear) ? 'selected' : '';
                            echo "<option value=\"$y\" $selected>$y</option>";
                        }
                        ?>
                    </select>
                    <span class="input-group-text">-</span>
                    <select id="clearYearEndSelect" class="form-select">
                        <option value="" disabled><?php echo t('end_year'); ?></option>
                        <?php
                        $currentYear = intval(date('Y'));
                        for ($y = $currentYear + 5; $y >= $currentYear - 5; $y--) {
                            $selected = ($y === $currentYear) ? 'selected' : '';
                            echo "<option value=\"$y\" $selected>$y</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="button" id="clearYearlyDataBtn" class="btn btn-danger" onclick="confirmClearYearlyData()">
                    <i class="bi bi-trash me-1"></i><?php echo t('clear_yearly_data_btn'); ?>
                </button>
            </div>
        </div>

        <!-- Cleanup Activity Logs -->
        <div class="col-md-12 mb-4">
            <hr class="my-4">
            <h5 class="mb-3 text-warning"><i class="bi bi-journal-x me-2"></i><?php echo t('clear_cache'); ?></h5>
            <button type="button" id="cleanupActivityLogsBtn" class="btn btn-warning" onclick="confirmCleanupActivityLogs()">
                <i class="bi bi-eraser me-1"></i><?php echo t('clear_cache'); ?>
            </button>
        </div>
        
        <div class="text-end mt-4">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-save me-2"></i><?php echo t('save'); ?>
            </button>
        </div>
    </form>
</div>

<!-- Modal Confirm PIN -->
<div class="modal fade" id="pinConfirmModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <div class="modal-header theme-modal-header border-0 py-3 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock me-2"></i><?php echo t('confirm_security_pin'); ?></h5>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-3">
                    <div class="theme-icon-circle rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                        <i class="bi bi-shield-lock-fill fs-3"></i>
                    </div>
                    <p class="text-muted small mb-0"><?php echo t('confirm_pin_help'); ?></p>
                </div>
                <div class="mb-3">
                    <input type="password" id="confirmPinInput" class="form-control form-control-lg text-center fs-3" style="letter-spacing: 8px;" placeholder="••••••" maxlength="6" autocomplete="off" autofocus inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <div id="pinErrorMsg" class="text-danger text-center mt-2 d-none"><?php echo t('pin_incorrect_try_again'); ?></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                <button type="button" id="submitPinBtn" class="btn theme-btn-primary px-4"><?php echo t('submit'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Confirm Clear Yearly Data -->
<div class="modal fade" id="clearDataConfirmModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <div class="modal-header border-0 py-3 rounded-top-4 bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i><span id="confirmModalTitle"></span></h5>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-3">
                    <div class="text-danger d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                        <i class="bi bi-trash-fill fs-1"></i>
                    </div>
                    <p class="fw-bold text-danger fs-5 mb-2"><?php echo t('clear_yearly_confirm_title'); ?></p>
                    <p class="text-muted mb-0" id="confirmModalBodyText"></p>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                <button type="button" id="confirmClearBtn" class="btn btn-danger px-4"><?php echo t('submit'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Confirm PIN for Clear Data -->
<div class="modal fade" id="clearDataPinModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <div class="modal-header theme-modal-header border-0 py-3 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock me-2"></i><?php echo t('confirm_security_pin'); ?></h5>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-3">
                    <div class="theme-icon-circle rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                        <i class="bi bi-shield-lock-fill fs-3"></i>
                    </div>
                    <p class="text-muted small mb-0"><?php echo t('confirm_pin_help'); ?></p>
                </div>
                <div class="mb-3">
                    <input type="password" id="clearDataPinInput" class="form-control form-control-lg text-center fs-3" style="letter-spacing: 8px;" placeholder="••••••" maxlength="6" autocomplete="off" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <div id="clearDataPinErrorMsg" class="text-danger text-center mt-2 d-none"><?php echo t('pin_incorrect_try_again'); ?></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                <button type="button" id="submitClearDataPinBtn" class="btn theme-btn-primary px-4"><?php echo t('submit'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Confirm PIN for Cleanup Activity Logs -->
<div class="modal fade" id="cleanupActivityLogsPinModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <div class="modal-header theme-modal-header border-0 py-3 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock me-2"></i><?php echo t('confirm_security_pin'); ?></h5>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-3">
                    <div class="theme-icon-circle rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                        <i class="bi bi-shield-lock-fill fs-3"></i>
                    </div>
                    <p class="text-muted small mb-0"><?php echo t('confirm_pin_help'); ?></p>
                </div>
                <div class="mb-3">
                    <input type="password" id="cleanupActivityLogsPinInput" class="form-control form-control-lg text-center fs-3" style="letter-spacing: 8px;" placeholder="••••••" maxlength="6" autocomplete="off" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <div id="cleanupActivityLogsPinErrorMsg" class="text-danger text-center mt-2 d-none"><?php echo t('pin_incorrect_try_again'); ?></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                <button type="button" id="submitCleanupActivityLogsPinBtn" class="btn theme-btn-primary px-4"><?php echo t('submit'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    toggleSmtpFields();
    const hasPin = <?php echo !empty($settings['pin']) ? 'true' : 'false'; ?>;
    const form = document.querySelector('form');
    
    form.addEventListener('submit', function(e) {
        const enableDaily = document.getElementById('enable_daily').checked;
        const enableMonthly = document.getElementById('enable_monthly').checked;
        if (!enableDaily && !enableMonthly) {
            e.preventDefault();
            e.stopImmediatePropagation();
            alert(t('must_select_at_least_one_rental_type'));
            return;
        }
    });

    let pinConfirmModal = null;
    const confirmPinInput = document.getElementById('confirmPinInput');
    const submitPinBtn = document.getElementById('submitPinBtn');
    const pinErrorMsg = document.getElementById('pinErrorMsg');
    
    if (hasPin) {
        form.addEventListener('submit', function(e) {
            // Check if confirm_pin is already injected
            if (form.querySelector('input[name="confirm_pin"]')) {
                return; // let form submit
            }
            
            e.preventDefault();
            confirmPinInput.value = '';
            pinErrorMsg.classList.add('d-none');
            
            if (!pinConfirmModal) {
                pinConfirmModal = new bootstrap.Modal(document.getElementById('pinConfirmModal'));
            }
            pinConfirmModal.show();
            setTimeout(() => confirmPinInput.focus(), 500);
        });
        
        submitPinBtn.addEventListener('click', verifyAndSubmit);
        confirmPinInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                verifyAndSubmit();
            }
        });
        
        function verifyAndSubmit() {
            const pinVal = confirmPinInput.value.trim();
            if (!pinVal) {
                pinErrorMsg.textContent = t('please_enter_pin');
                pinErrorMsg.classList.remove('d-none');
                return;
            }
            if (pinVal.length !== 6 || !/^\d+$/.test(pinVal)) {
                pinErrorMsg.textContent = t('pin_must_be_6_digits');
                pinErrorMsg.classList.remove('d-none');
                return;
            }
            
            submitPinBtn.disabled = true;
            pinErrorMsg.classList.add('d-none');
            
            const formData = new FormData();
            formData.append('pin', pinVal);
            formData.append('_csrf_token', '<?php echo csrfToken(); ?>');
            
            fetch('<?php echo BASE_URL; ?>api/verify-pin.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.json())
            .then(data => {
                submitPinBtn.disabled = false;
                if (data.logout) {
                    window.location.href = '<?php echo BASE_URL; ?>pages/auth/login.php?reason=suspended';
                    return;
                }
                if (data.success) {
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'confirm_pin';
                    hiddenInput.value = pinVal;
                    form.appendChild(hiddenInput);
                    
                    pinConfirmModal.hide();
                    form.submit();
                } else {
                    pinErrorMsg.textContent = data.message || t('pin_incorrect_try_again');
                    pinErrorMsg.classList.remove('d-none');
                    confirmPinInput.value = '';
                    confirmPinInput.focus();
                }
            })
            .catch(err => {
                submitPinBtn.disabled = false;
                pinErrorMsg.textContent = t('connection_error');
                pinErrorMsg.classList.remove('d-none');
            });
        }
    }
});
</script>

<?php else: ?>
    <?php require_once __DIR__ . '/../includes/pin-lockscreen-modal.php'; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
