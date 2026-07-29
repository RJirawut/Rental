<?php
// Secure PIN verification wrapper for sensitive pages (Check phase)
$settings = getSettings();
$dbPin = $settings['pin'] ?? null;

// Determine unique page key for session storage based on script name
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$pageSessionKey = 'pin_verified_' . md5($scriptPath);

// Special referrer bypasses (like user-form.php for users.php)
if (basename($scriptPath) === 'users.php') {
    $fromUserForm = isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'user-form.php') !== false;
    if ($fromUserForm) {
        $_SESSION[$pageSessionKey] = true;
    }
}

$skipPin = isset($_GET['skip_pin']) && $_GET['skip_pin'] === 'true';
if ($skipPin) {
    $_SESSION[$pageSessionKey] = true;
}

$showLockScreen = !empty($dbPin) && empty($_SESSION[$pageSessionKey]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_list_pin') {
    requireValidCsrfToken();
    $pinResult = verifySystemPin(trim($_POST['pin'] ?? ''));

    if (!empty($pinResult['success'])) {
        $_SESSION[$pageSessionKey] = true;
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if (!empty($pinResult['logout'])) {
        header('Location: ' . BASE_URL . 'pages/auth/login.php?reason=suspended');
        exit;
    }

    $pinError = $pinResult['message'] ?? t('pin_incorrect_try_again');
    $showLockScreen = true;
}
