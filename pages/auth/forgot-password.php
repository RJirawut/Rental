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
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $email = trim(sanitize($_POST['email'] ?? ''));
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = ($lang === 'en') ? 'Please enter a valid email address' : 'กรุณากรอกอีเมลให้ถูกต้อง';
    } else {
        ensureUserSecurityColumns();
        $stmt = $pdo->prepare("SELECT id, username, email, full_name, account_status FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            if (($user['account_status'] ?? 'active') === 'suspended') {
                sendAccountSuspendedEmail($user, 'password_reset_blocked');
                logActivity('password_reset_blocked_suspended', 'user', $user['id']);
            } else {
                $token = createPasswordResetToken((int) $user['id']);
                if (!sendPasswordResetEmail($user, $token)) {
                    error_log('Password reset email could not be sent to user id ' . $user['id']);
                }
                logActivity('password_reset_requested', 'user', $user['id']);
            }
        }

        // Do not reveal whether the email exists.
        $success = ($lang === 'en')
            ? 'If this email exists, a password reset link has been sent.'
            : 'หากอีเมลนี้มีอยู่ในระบบ ระบบจะส่งลิงก์รีเซ็ตรหัสผ่านไปให้';
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('forgot_password'); ?> - <?php echo t('app_name'); ?></title>
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
        }
        .login-logo {
            text-align: center;
            margin-bottom: 25px;
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
        .text-muted {
            color: #94a3b8 !important;
            font-size: 14px;
            line-height: 1.5;
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
        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-logo">
            <i class="bi bi-key"></i>
            <h3 class="mt-3"><?php echo t('forgot_password'); ?></h3>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo $error; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success" role="alert">
            <?php echo $success; ?>
        </div>
        <?php endif; ?>
        
        <p class="text-muted mb-4">
            <?php echo $lang === 'en' ? 'Enter your account email to receive a password reset link.' : 'กรอกอีเมลบัญชีผู้ใช้เพื่อรับลิงก์รีเซ็ตรหัสผ่าน'; ?>
        </p>
        
        <form method="POST" action="">
            <?php echo csrfInput(); ?>
            <div class="mb-3">
                <label class="form-label"><?php echo t('email'); ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email" class="form-control" required autofocus>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary btn-login w-100"><?php echo t('next'); ?></button>
            
            <div class="text-center mt-3">
                <a href="login.php" class="text-decoration-none"><?php echo t('back_to_login'); ?></a>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
