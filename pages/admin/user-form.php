<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireAdmin();
global $lang;

// Set users_page_pin_verified to true since user can access this page
$_SESSION['users_page_pin_verified'] = true;

$pageTitle = isset($_GET['id']) ? t('edit_user') : t('add_user');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user = null;

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        setFlashMessage('error', t('not_found'));
        header('Location: users.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    // Handle cancel action
    if (($_POST['form_action'] ?? '') === 'cancel') {
        $_SESSION['users_page_pin_verified'] = true;
        header('Location: users.php');
        exit;
    }

    ensureUserSecurityColumns();

    $settings = getSettings();
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

    // A failed PIN must stop processing before any database write occurs.
    if (!isset($error)) {
        $username = sanitize($_POST['username'] ?? '');
        $fullName = sanitize($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = 'admin';
        $password = $_POST['password'] ?? '';
        $previousAccountStatus = $user['account_status'] ?? 'active';
        $isCurrentUser = $id > 0 && $id === (int) ($_SESSION['user_id'] ?? 0);
        // Disabled controls are omitted from POST, so keep the current account active server-side.
        $isActive = $isCurrentUser ? 1 : (isset($_POST['is_active']) ? 1 : 0);
        $accountStatus = $isCurrentUser
            ? $previousAccountStatus
            : (($_POST['account_status'] ?? 'active') === 'suspended' ? 'suspended' : 'active');
        if (!$isActive) {
            $accountStatus = 'active';
        }

        if (empty($username) || empty($fullName) || empty($email)) {
            $error = t('required_field');
        } elseif (!isValidEmailFormat($email)) {
            $error = t('invalid_email_format');
        } else {
        // Check if username or email already exists
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $id]);
            $usernameExists = $stmt->fetchColumn() > 0;
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $id]);
            $emailExists = $stmt->fetchColumn() > 0;
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $usernameExists = $stmt->fetchColumn() > 0;
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $emailExists = $stmt->fetchColumn() > 0;
        }

        if ($usernameExists) {
            $error = ($lang === 'en') ? 'Username is already taken' : 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว';
        } elseif ($emailExists) {
            $error = ($lang === 'en') ? 'Email is already in use' : 'อีเมลนี้ถูกใช้งานแล้ว';
        } else {
            if ($id > 0) {
                $pinFailedAttempts = ($previousAccountStatus === 'suspended' && $accountStatus === 'active')
                    ? 0
                    : (int) ($user['pin_failed_attempts'] ?? 0);

                if (!empty($password)) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, is_active = ?, account_status = ?, pin_failed_attempts = ?, password = ? WHERE id = ?");
                    $stmt->execute([$username, $fullName, $email, $role, $isActive, $accountStatus, $pinFailedAttempts, $hashedPassword, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, is_active = ?, account_status = ?, pin_failed_attempts = ? WHERE id = ?");
                    $stmt->execute([$username, $fullName, $email, $role, $isActive, $accountStatus, $pinFailedAttempts, $id]);
                }

                $notifyUser = [
                    'id' => $id,
                    'username' => $username,
                    'full_name' => $fullName,
                    'email' => $email,
                    'account_status' => $accountStatus,
                ];

                if ($isActive && $previousAccountStatus === 'suspended' && $accountStatus === 'active') {
                    sendAccountReactivatedEmail($notifyUser);
                    logActivity('reactivate_user_account', 'user', $id);
                } elseif ($isActive && $previousAccountStatus === 'active' && $accountStatus === 'suspended') {
                    sendAccountSuspendedEmail($notifyUser, 'manual_suspend');
                    logActivity('suspend_user_account', 'user', $id, 'manual_suspend');
                }
                
                setFlashMessage('success', t('save_success'));
                logActivity('update_user', 'user', $id);
            } else {
                if (empty($password)) {
                    $error = t('please_enter_password_for_new_user');
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, full_name, email, role, is_active, account_status, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    
                    if ($stmt->execute([$username, $fullName, $email, $role, $isActive, $accountStatus, $hashedPassword])) {
                        $newId = $pdo->lastInsertId();
                        $notifyUser = [
                            'username' => $username,
                            'full_name' => $fullName,
                            'email' => $email,
                        ];
                        $emailSent = sendNewUserWelcomeEmail($notifyUser, $password);
                        setFlashMessage(
                            'success',
                            $emailSent ? t('save_success_user_email_sent') : t('save_success_user_email_failed')
                        );
                        logActivity('create_user', 'user', $newId);
                    } else {
                        $error = t('save_error');
                    }
                }
            }
            
            if (!isset($error)) {
                $_SESSION['users_page_pin_verified'] = true;
                header('Location: users.php');
                exit;
            }
        }
    }
}
}

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
                    <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" id="userForm">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="form_action" id="formAction" value="">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('username'); ?> *</label>
                                <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('password'); ?> <?php echo $id > 0 ? '(' . t('leave_blank_to_keep') . ')' : '*'; ?></label>
                                <input type="password" name="password" class="form-control" <?php echo $id > 0 ? '' : 'required'; ?>>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('full_name'); ?> *</label>
                                <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('email'); ?> *</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('status'); ?></label>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?php echo ($user['is_active'] ?? 1) ? 'checked' : ''; ?> <?php echo ($id > 0 && $id == $_SESSION['user_id']) ? 'disabled' : ''; ?>>
                                    <label class="form-check-label" for="is_active"><?php echo t('active'); ?></label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('account_status_label'); ?></label>
                                <select name="account_status" id="account_status" class="form-select" <?php echo ($id > 0 && $id == $_SESSION['user_id']) ? 'disabled' : ''; ?>>
                                    <option value="active" <?php echo (($user['account_status'] ?? 'active') === 'active') ? 'selected' : ''; ?>><?php echo t('active'); ?></option>
                                    <?php if (!($id > 0 && $id == $_SESSION['user_id'])): ?>
                                    <option value="suspended" <?php echo (($user['account_status'] ?? 'active') === 'suspended') ? 'selected' : ''; ?>><?php echo t('suspended'); ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="text-end">
                            <button type="button" class="btn btn-outline-secondary" onclick="submitForm('cancel')"><?php echo t('cancel'); ?></button>
                            <button type="submit" class="btn btn-primary"><?php echo t('save'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Confirm PIN -->
<div class="modal fade" id="pinConfirmModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <div class="modal-header theme-modal-header border-0 py-3 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock me-2"></i>ยืนยันรหัส PIN</h5>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-3">
                    <div class="theme-icon-circle rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                        <i class="bi bi-shield-lock-fill fs-3"></i>
                    </div>
                    <p class="text-muted small mb-0">กรุณากรอก PIN เพื่อยืนยันการบันทึกข้อมูลผู้ใช้</p>
                </div>
                <div class="mb-3">
                    <input type="password" id="confirmPinInput" class="form-control form-control-lg text-center fs-3" style="letter-spacing: 8px;" placeholder="••••••" maxlength="6" autocomplete="off" autofocus>
                    <div id="pinErrorMsg" class="text-danger text-center mt-2 d-none">PIN ไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง</div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" id="submitPinBtn" class="btn theme-btn-primary px-4">ยืนยัน</button>
            </div>
        </div>
    </div>
</div>

<script>
function submitForm(action) {
    document.getElementById('formAction').value = action;
    document.getElementById('userForm').submit();
}

document.addEventListener('DOMContentLoaded', function() {
    const hasPin = <?php
        $settings = getSettings();
        echo !empty($settings['pin']) ? 'true' : 'false';
    ?>;
    const form = document.getElementById('userForm');
    const pinConfirmModal = new bootstrap.Modal(document.getElementById('pinConfirmModal'));
    const confirmPinInput = document.getElementById('confirmPinInput');
    const submitPinBtn = document.getElementById('submitPinBtn');
    const pinErrorMsg = document.getElementById('pinErrorMsg');

    if (hasPin) {
        form.addEventListener('submit', function(e) {
            // Check if form_action is cancel - skip PIN verification
            const formAction = document.getElementById('formAction').value;
            if (formAction === 'cancel') {
                return; // let form submit without PIN
            }

            // Check if confirm_pin is already injected
            if (form.querySelector('input[name="confirm_pin"]')) {
                return; // let form submit
            }

            e.preventDefault();
            confirmPinInput.value = '';
            pinErrorMsg.classList.add('d-none');
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
                pinErrorMsg.textContent = 'กรุณากรอก PIN';
                pinErrorMsg.classList.remove('d-none');
                return;
            }
            if (pinVal.length !== 6 || !/^\d+$/.test(pinVal)) {
                pinErrorMsg.textContent = 'PIN ต้องเป็นตัวเลข 6 หลัก';
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
                    pinErrorMsg.textContent = data.message || 'PIN ไม่ถูกต้อง';
                    pinErrorMsg.classList.remove('d-none');
                    confirmPinInput.value = '';
                    confirmPinInput.focus();
                }
            })
            .catch(err => {
                submitPinBtn.disabled = false;
                pinErrorMsg.textContent = 'เกิดข้อผิดพลาดในการเชื่อมต่อ';
                pinErrorMsg.classList.remove('d-none');
            });
        }
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
