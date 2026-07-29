<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
global $lang;

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'pages/dashboard.php');
    exit;
}

$settings = getSettings();
$primaryColor = $settings['primary_color'] ?? '#6366f1';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor)) {
    $primaryColor = '#6366f1';
}
list($r, $g, $b) = sscanf($primaryColor, "#%02x%02x%02x");
$primaryColorLight = sprintf("rgba(%d, %d, %d, 0.8)", min($r + 30, 255), min($g + 30, 255), min($b + 30, 255));
$primaryColorDark = sprintf("rgba(%d, %d, %d, 1.0)", max($r - 40, 0), max($g - 40, 0), max($b - 40, 0));

$error = '';

if (($_GET['reason'] ?? '') === 'suspended') {
    $error = t('account_suspended_login');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = t('required_field');
    } else {
        ensureUserSecurityColumns();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if (($user['account_status'] ?? 'active') === 'suspended') {
                $error = t('account_suspended_login');
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                logActivity('login');

                header('Location: ' . BASE_URL . 'pages/dashboard.php');
                exit;
            }
        } else {
            $error = t('invalid_login');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('login'); ?> - <?php echo t('app_name'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    window.alert = function(message) {
        Swal.fire({
            text: message,
            icon: 'info',
            confirmButtonColor: '<?php echo $primaryColor; ?>',
            confirmButtonText: '<?php echo t("close"); ?>'
        });
    };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $primaryColor; ?>;
            --primary-color-rgb: <?php echo "$r, $g, $b"; ?>;
            --primary-color-light: <?php echo $primaryColorLight; ?>;
            --primary-color-dark: <?php echo $primaryColorDark; ?>;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: radial-gradient(circle at 10% 20%, rgba(var(--primary-color-rgb), 0.15) 0%, transparent 40%), 
                        radial-gradient(circle at 90% 80%, rgba(var(--primary-color-rgb), 0.1) 0%, transparent 40%), 
                        #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .login-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
            padding: 40px;
            width: 100%;
            max-width: 420px;
            transition: transform 0.3s ease;
        }
        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-logo i {
            font-size: 50px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-color-dark) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 2px 8px rgba(var(--primary-color-rgb), 0.3));
        }
        .login-logo h3 {
            font-family: 'Outfit', sans-serif;
            color: #ffffff;
            font-weight: 700;
            font-size: 24px;
            margin-top: 15px;
        }
        .login-logo p {
            color: #94a3b8;
            font-size: 15px;
            margin-bottom: 0;
        }
        .form-label {
            color: #cbd5e1;
            font-weight: 500;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .input-group-text {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-right: none;
            color: var(--primary-color);
            border-top-left-radius: 12px !important;
            border-bottom-left-radius: 12px !important;
            padding: 12px 16px;
        }
        .form-control {
            background: rgba(15, 23, 42, 0.6) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #f1f5f9 !important;
            border-top-right-radius: 12px !important;
            border-bottom-right-radius: 12px !important;
            padding: 12px 16px;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            background: rgba(15, 23, 42, 0.8) !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 3px rgba(var(--primary-color-rgb), 0.25) !important;
            color: #fff !important;
        }
        .form-check-label {
            color: #cbd5e1;
            font-size: 14px;
        }
        .form-check-input {
            background-color: rgba(15, 23, 42, 0.6);
            border-color: rgba(255, 255, 255, 0.1);
        }
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-color-dark) 100%) !important;
            border: none !important;
            border-radius: 12px !important;
            padding: 14px !important;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: #ffffff !important;
            width: 100%;
            transition: all 0.3s ease !important;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(var(--primary-color-rgb), 0.4) !important;
        }
        .btn-login:active {
            transform: translateY(0);
        }
        .text-decoration-none {
            color: var(--primary-color);
            font-weight: 500;
            transition: color 0.2s ease;
        }
        .text-decoration-none:hover {
            color: var(--primary-color-light);
            text-decoration: underline !important;
        }
        .alert {
            border-radius: 12px;
            border: none;
            font-size: 14px;
        }
        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        .alert-info {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        .language-switch {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 30px;
            padding: 4px;
        }
        .language-switch .btn {
            border: none;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .language-switch .btn-primary {
            background-color: var(--primary-color);
            color: #ffffff;
        }
        .language-switch .btn-outline-light {
            background: transparent;
            color: #94a3b8;
        }
        .language-switch .btn-outline-light:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }
    </style>
</head>
<body>
    <div class="language-switch">
        <a href="?lang=th" class="btn <?php echo $lang == 'th' ? 'btn-primary' : 'btn-outline-light'; ?>">ไทย</a>
        <a href="?lang=en" class="btn <?php echo $lang == 'en' ? 'btn-primary' : 'btn-outline-light'; ?>">EN</a>
    </div>
    
    <div class="login-card">
        <div class="login-logo">
            <i class="bi bi-building"></i>
            <h3 class="mt-3"><?php echo t('app_name'); ?></h3>
            <p class="text-muted"><?php echo t('login'); ?></p>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo $error; ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <?php echo csrfInput(); ?>
            <div class="mb-3">
                <label class="form-label"><?php echo t('username'); ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" name="username" class="form-control" required autofocus>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label"><?php echo t('password'); ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" class="form-control" required>
                </div>
            </div>
            
            <div class="d-flex justify-content-between mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember">
                    <label class="form-check-label" for="remember"><?php echo t('remember_me'); ?></label>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary btn-login" id="loginBtn"><?php echo t('login'); ?></button>
            
            <div class="text-center mt-3">
                <a href="forgot-password.php" class="text-decoration-none"><?php echo t('forgot_password'); ?>?</a>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Check login status and update UI
        function checkLoginStatus() {
            fetch('check-status.php')
                .then(response => response.json())
                .then(data => {
                    if (data.isLoggedIn) {
                        // User is logged in, show checkout button and status
                        updateLoginUI(true, data.user);
                    } else {
                        // User is not logged in, show login button
                        updateLoginUI(false, null);
                    }
                })
                .catch(error => {
                    console.error('Error checking login status:', error);
                });
        }
        
        function updateLoginUI(isLoggedIn, user) {
            const loginBtn = document.getElementById('loginBtn');
            const form = document.querySelector('form');
            
            // Remove existing status alert if any
            const existingStatus = document.getElementById('loginStatusAlert');
            if (existingStatus) {
                existingStatus.remove();
            }
            
            if (isLoggedIn) {
                // Change to checkout mode
                loginBtn.textContent = '<?php echo t('checkout'); ?>';
                loginBtn.className = 'btn btn-danger btn-login';
                loginBtn.onclick = function(e) {
                    e.preventDefault();
                    checkout();
                };
                
                // Show user status
                const statusDiv = document.createElement('div');
                statusDiv.id = 'loginStatusAlert';
                statusDiv.className = 'alert alert-info mb-3';
                statusDiv.innerHTML = `
                    <i class="bi bi-person-check me-2"></i>
                    <?php echo t('currently_staying'); ?>: <strong>${user.full_name}</strong>
                `;
                form.parentNode.insertBefore(statusDiv, form);
                
                // Disable form inputs
                form.querySelectorAll('input').forEach(input => input.disabled = true);
            } else {
                // Keep as login mode
                loginBtn.textContent = '<?php echo t('login'); ?>';
                loginBtn.className = 'btn btn-primary btn-login';
                loginBtn.onclick = null;
                
                // Enable form inputs
                form.querySelectorAll('input').forEach(input => input.disabled = false);
            }
        }
        
        function checkout() {
            Swal.fire({
                title: '<?php echo $lang === "en" ? "Confirm" : "ยืนยัน"; ?>',
                text: '<?php echo t('confirm_checkout'); ?>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<?php echo t('yes'); ?>',
                cancelButtonText: '<?php echo t('no'); ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '<?php echo BASE_URL; ?>pages/auth/logout.php';
                }
            });
        }
        
        // Check status on page load
        document.addEventListener('DOMContentLoaded', checkLoginStatus);
        
        // Check status every 30 seconds
        setInterval(checkLoginStatus, 30000);
    </script>
</body>
</html>
