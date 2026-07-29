<?php
// Secure PIN verification wrapper (Modal UI phase)
if (!empty($showLockScreen)):
    // Determine the translation key for the PIN help message dynamically
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $filename = basename($scriptPath);
    $dirName = basename(dirname($scriptPath));

    $helpTranslationKey = 'confirm_pin_help';
    if ($filename === 'users.php') {
        $helpTranslationKey = 'confirm_pin_users_help';
    } elseif ($filename === 'income.php') {
        $helpTranslationKey = 'confirm_pin_income_help';
    } elseif ($filename === 'settings.php') {
        $helpTranslationKey = 'confirm_pin_settings_help';
    } elseif ($dirName === 'email-queue' && $filename === 'index.php') {
        $helpTranslationKey = 'confirm_pin_email_queue_help';
    }
?>
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
                    <p class="text-muted small mb-0"><?php echo t($helpTranslationKey); ?></p>
                </div>
                <div class="mb-3">
                    <input type="password" id="confirmPinInput" class="form-control form-control-lg text-center fs-3" style="letter-spacing: 8px;" placeholder="••••••" maxlength="6" autocomplete="off" autofocus inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <div id="pinErrorMsg" class="text-danger text-center mt-2 <?php echo isset($pinError) ? '' : 'd-none'; ?>"><?php echo $pinError ?? t('pin_incorrect_try_again'); ?></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                <button type="button" id="submitPinBtn" class="btn theme-btn-primary px-4"><?php echo t('submit'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
function t(key) {
    const translations = {
        'please_enter_pin': '<?php echo t('please_enter_pin'); ?>',
        'pin_must_be_6_digits': '<?php echo t('pin_must_be_6_digits'); ?>',
        'pin_incorrect_try_again': '<?php echo t('pin_incorrect_try_again'); ?>',
        'connection_error': '<?php echo t('connection_error'); ?>'
    };
    return translations[key] || key;
}

document.addEventListener('DOMContentLoaded', function() {
    const pinConfirmModal = new bootstrap.Modal(document.getElementById('pinConfirmModal'));
    const confirmPinInput = document.getElementById('confirmPinInput');
    const submitPinBtn = document.getElementById('submitPinBtn');
    const pinErrorMsg = document.getElementById('pinErrorMsg');

    pinConfirmModal.show();
    setTimeout(() => confirmPinInput.focus(), 500);

    submitPinBtn.addEventListener('click', verifyAndSubmit);
    confirmPinInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            verifyAndSubmit();
        }
    });

    function submitVerifiedPin(pinVal) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';

        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_csrf_token';
        csrfInput.value = '<?php echo csrfToken(); ?>';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'verify_list_pin';

        const pinInput = document.createElement('input');
        pinInput.type = 'hidden';
        pinInput.name = 'pin';
        pinInput.value = pinVal;

        form.appendChild(csrfInput);
        form.appendChild(actionInput);
        form.appendChild(pinInput);
        document.body.appendChild(form);
        form.submit();
    }

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
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            submitPinBtn.disabled = false;
            if (data.logout) {
                window.location.href = '<?php echo BASE_URL; ?>pages/auth/login.php?reason=suspended';
                return;
            }
            if (data.success) {
                pinConfirmModal.hide();
                submitVerifiedPin(pinVal);
            } else {
                pinErrorMsg.textContent = data.message || t('pin_incorrect_try_again');
                pinErrorMsg.classList.remove('d-none');
                confirmPinInput.value = '';
                confirmPinInput.focus();
            }
        })
        .catch(() => {
            submitPinBtn.disabled = false;
            pinErrorMsg.textContent = t('connection_error');
            pinErrorMsg.classList.remove('d-none');
        });
    }
});
</script>
<?php endif; ?>
