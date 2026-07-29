<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Require login
requireLogin();

// Clear other pages' PIN verification flags
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$currentPageSessionKey = 'pin_verified_' . md5($scriptPath);
foreach ($_SESSION as $key => $value) {
    if (strpos($key, 'pin_verified_') === 0 && $key !== $currentPageSessionKey) {
        unset($_SESSION[$key]);
    }
}
if (basename($scriptPath) !== 'users.php' && basename($scriptPath) !== 'user-form.php') {
    unset($_SESSION['users_page_pin_verified']);
}

$settings = getSettings();

// Get language from session
global $lang;
if (!isset($lang)) {
    $lang = $_SESSION['lang'] ?? 'th';
}
$flash = getFlashMessage();

// Get current user info
$currentUser = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle : t('app_name'); ?></title>
    <?php if (!empty($settings['logo'])): ?>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>uploads/logo/<?php echo htmlspecialchars($settings['logo']); ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    // System-wide pop-up overrides
    window.alert = function(message) {
        Swal.fire({
            text: message,
            icon: 'info',
            confirmButtonColor: 'var(--primary-color, #0d6efd)',
            confirmButtonText: '<?php echo t("close"); ?>'
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        // Intercept form submissions that have inline confirm onsubmit
        document.addEventListener('submit', function(e) {
            const form = e.target;
            const onsubmitAttr = form.getAttribute('onsubmit');
            if (onsubmitAttr && onsubmitAttr.includes('confirm(')) {
                e.preventDefault();
                e.stopPropagation();
                
                // Extract message
                const match = onsubmitAttr.match(/confirm\(['"](.*?)['"]\)/);
                const message = match ? match[1] : '<?php echo $lang === "en" ? "Are you sure?" : "คุณแน่ใจหรือไม่ที่จะดำเนินการนี้?"; ?>';
                
                // Remove onsubmit temporarily to prevent infinite loop
                const originalOnsubmit = form.onsubmit;
                form.onsubmit = null;
                form.removeAttribute('onsubmit');
                
                Swal.fire({
                    title: '<?php echo $lang === "en" ? "Confirm" : "ยืนยัน"; ?>',
                    text: message,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: 'var(--primary-color, #0d6efd)',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<?php echo t("yes"); ?>',
                    cancelButtonText: '<?php echo t("no"); ?>'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    } else {
                        // Restore onsubmit if cancelled
                        form.onsubmit = originalOnsubmit;
                        form.setAttribute('onsubmit', onsubmitAttr);
                    }
                });
            }
        }, true);

        // Intercept elements with inline confirm onclick
        document.addEventListener('click', function(e) {
            const el = e.target.closest('[onclick]');
            if (!el) return;
            
            const onclickAttr = el.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes('confirm(')) {
                e.preventDefault();
                e.stopPropagation();
                
                // Extract message
                const match = onclickAttr.match(/confirm\(['"](.*?)['"]\)/);
                const message = match ? match[1] : '<?php echo $lang === "en" ? "Are you sure?" : "คุณแน่ใจหรือไม่ที่จะดำเนินการนี้?"; ?>';
                
                // Remove onclick temporarily
                const originalOnclick = el.onclick;
                el.onclick = null;
                el.removeAttribute('onclick');
                
                Swal.fire({
                    title: '<?php echo $lang === "en" ? "Confirm" : "ยืนยัน"; ?>',
                    text: message,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: 'var(--primary-color, #0d6efd)',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<?php echo t("yes"); ?>',
                    cancelButtonText: '<?php echo t("no"); ?>'
                }).then((result) => {
                    if (result.isConfirmed) {
                        if (el.tagName === 'A') {
                            window.location.href = el.href;
                        } else {
                            const form = el.closest('form');
                            if (form) {
                                form.submit();
                            } else {
                                el.click();
                            }
                        }
                    } else {
                        // Restore onclick if cancelled
                        el.onclick = originalOnclick;
                        el.setAttribute('onclick', onclickAttr);
                    }
                });
            }
        }, true);
    });
    </script>
    <?php
    $primaryColor = $settings['primary_color'] ?? '#0d6efd';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor)) {
        $primaryColor = '#0d6efd';
    }
    // Convert hex to rgba for gradient variations
    list($r, $g, $b) = sscanf($primaryColor, "#%02x%02x%02x");
    $primaryColorLight = sprintf("rgba(%d, %d, %d, 0.8)", min($r + 30, 255), min($g + 30, 255), min($b + 30, 255));
    $primaryColorDark = sprintf("rgba(%d, %d, %d, 1.0)", max($r - 40, 0), max($g - 40, 0), max($b - 40, 0));
    // Calculate brightness to determine text color (YIQ formula)
    $brightness = ($r * 299 + $g * 587 + $b * 114) / 1000;
    $isLightTheme = $brightness > 160;
    $sidebarTextColor = $isLightTheme ? '#000' : '#fff';
    $sidebarTextShadow = $isLightTheme ? 'none' : '0 2px 4px rgba(0,0,0,0.5)';
    $sidebarIconShadow = $isLightTheme ? 'none' : '0 2px 3px rgba(0,0,0,0.5)';
    $navHoverBg = $isLightTheme ? 'rgba(0,0,0,0.15)' : 'rgba(0,0,0,0.25)';
    ?>
    <style>
        :root {
            --sidebar-width: 260px;
            --primary-color: <?php echo $primaryColor; ?>;
            --primary-color-rgb: <?php echo "$r, $g, $b"; ?>;
            --primary-color-light: <?php echo $primaryColorLight; ?>;
            --primary-color-dark: <?php echo $primaryColorDark; ?>;
            --sidebar-text-color: <?php echo $sidebarTextColor; ?>;
            --sidebar-text-shadow: <?php echo $sidebarTextShadow; ?>;
            --sidebar-icon-shadow: <?php echo $sidebarIconShadow; ?>;
            --nav-hover-bg: <?php echo $navHoverBg; ?>;
        }
        
        /* Theme Custom Modal and Elements */
        .theme-modal-header {
            background-color: var(--primary-color) !important;
            color: #fff !important;
        }
        .theme-icon-circle {
            background-color: rgba(var(--primary-color-rgb), 0.1) !important;
            color: var(--primary-color) !important;
        }
        .theme-btn-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: #fff !important;
        }
        .theme-btn-primary:hover, .theme-btn-primary:focus {
            background-color: var(--primary-color-dark) !important;
            border-color: var(--primary-color-dark) !important;
            color: #fff !important;
        }
        .theme-text {
            color: var(--primary-color) !important;
        }
        
        /* Dynamic Theme Overrides for Bootstrap Buttons */
        .btn-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: #fff !important;
        }
        .btn-primary:hover, 
        .btn-primary:focus, 
        .btn-primary:active, 
        .btn-primary.active, 
        .show > .btn-primary.dropdown-toggle {
            background-color: var(--primary-color-dark) !important;
            border-color: var(--primary-color-dark) !important;
            color: #fff !important;
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.5) !important;
        }
        .btn-primary:disabled, .btn-primary.disabled {
            background-color: var(--primary-color-light) !important;
            border-color: var(--primary-color-light) !important;
            color: #fff !important;
            opacity: 0.65;
        }
        
        /* Force search icon buttons in tables to use the original Bootstrap primary blue color instead of theme color */
        .btn:has(.bi-search) {
            background-color: #0d6efd !important;
            border-color: #0d6efd !important;
            color: #fff !important;
        }
        .btn:has(.bi-search):hover, 
        .btn:has(.bi-search):focus, 
        .btn:has(.bi-search):active,
        .btn:has(.bi-search).active {
            background-color: #0b5ed7 !important;
            border-color: #0a58ca !important;
            color: #fff !important;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.5) !important;
        }

        .btn-outline-primary {
            color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }
        .btn-outline-primary:hover, 
        .btn-outline-primary:focus, 
        .btn-outline-primary:active, 
        .btn-outline-primary.active, 
        .show > .btn-outline-primary.dropdown-toggle {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: #fff !important;
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.5) !important;
        }
        
        /* Form control focus theme override */
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.25) !important;
        }
        
        body {
            background: #f5f6fa;
            min-height: 100vh;
        }
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: linear-gradient(180deg, var(--primary-color-dark) 0%, var(--primary-color) 100%);
            padding: 20px;
            overflow-y: auto;
            z-index: 1000;
        }
        .sidebar-logo {
            text-align: center;
            padding: 20px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        .sidebar-logo i {
            font-size: 40px;
            color: var(--primary-color);
        }
        .sidebar-logo h5 {
            color: var(--sidebar-text-color);
            margin-top: 10px;
            font-weight: 600;
            font-size: 16px;
            line-height: 1.3;
            text-shadow: var(--sidebar-text-shadow);
            letter-spacing: 0.5px;
        }
        .dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--sidebar-text-color);
            text-decoration: none;
            text-shadow: var(--sidebar-text-shadow);
        }
        .dropdown-toggle:hover {
            color: var(--sidebar-text-color);
            opacity: 0.9;
        }
        .dropdown-toggle span {
            font-weight: 400;
            font-size: 14px;
        }
        .dropdown-menu {
            background: linear-gradient(180deg, var(--primary-color-dark) 0%, var(--primary-color) 100%);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .dropdown-item {
            color: var(--sidebar-text-color);
            text-shadow: var(--sidebar-text-shadow);
        }
        .dropdown-item:hover {
            background: var(--nav-hover-bg);
            color: var(--sidebar-text-color);
        }
        .dropdown-header {
            color: var(--sidebar-text-color);
            text-shadow: var(--sidebar-text-shadow);
        }
        .dropdown-divider {
            border-color: var(--sidebar-text-color);
            opacity: 0.2;
        }
        .nav-link {
            color: var(--sidebar-text-color);
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-shadow: var(--sidebar-text-shadow);
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .nav-link:hover, .nav-link.active {
            background: var(--nav-hover-bg);
            color: var(--sidebar-text-color);
            border-left: 3px solid var(--sidebar-text-color);
        }
        .nav-link i {
            font-size: 18px;
            filter: drop-shadow(var(--sidebar-icon-shadow));
        }
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
        }
        .topbar {
            background: linear-gradient(180deg, var(--primary-color-dark) 0%, var(--primary-color) 100%);
            padding: 15px 20px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 10px;
            position: relative;
        }
        .topbar h4 {
            color: var(--sidebar-text-color);
            text-shadow: var(--sidebar-text-shadow);
        }
        .topbar .btn {
            background: rgba(128,128,128,0.15);
            border-color: rgba(128,128,128,0.3);
            color: var(--sidebar-text-color);
        }
        .topbar .btn:hover {
            background: rgba(128,128,128,0.25);
            color: var(--sidebar-text-color);
        }
        .topbar .btn-primary {
            background: white;
            border-color: white;
            color: var(--primary-color-dark);
            font-weight: 600;
        }
        .topbar .btn-primary:hover {
            background: #f0f0f0;
            border-color: #f0f0f0;
            color: var(--primary-color-dark);
        }
        .topbar .btn-outline-secondary {
            background: transparent;
            border-color: var(--sidebar-text-color);
            opacity: 0.7;
            color: var(--sidebar-text-color);
        }
        .topbar .btn-outline-secondary:hover {
            background: rgba(128,128,128,0.2);
            border-color: var(--sidebar-text-color);
            opacity: 1;
            color: var(--sidebar-text-color);
        }
        .user-menu {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .user-avatar {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        .content-wrapper {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .sidebar-close {
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 1001;
            background: rgba(220, 53, 69, 0.8);
            border-color: rgba(220, 53, 69, 0.9);
            color: white;
        }
        .sidebar-close:hover {
            background: rgba(220, 53, 69, 0.9);
            color: white;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .topbar {
                padding: 12px 15px;
                margin-bottom: 20px;
            }
            .topbar h4 {
                font-size: 16px;
                flex: 1;
                min-width: 0;
                text-align: center;
            }
            .user-menu {
                gap: 8px;
            }
            .user-avatar {
                width: 30px;
                height: 30px;
                font-size: 12px;
            }
            .btn-sm {
                font-size: 12px;
                padding: 4px 8px;
            }
        }
        @media (max-width: 576px) {
            .topbar {
                padding: 8px 12px;
                align-items: center;
                flex-wrap: nowrap;
                gap: 8px;
            }
            .topbar h4 {
                font-size: 14px;
                flex: 1;
                margin: 0;
                text-align: center;
                min-width: 0;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            #sidebarToggle {
                flex: 0 0 auto;
                order: 0;
            }
            .user-menu {
                flex: 0 0 auto;
                order: 2;
                gap: 4px;
                flex-wrap: nowrap;
            }
            .user-menu .btn {
                flex: 0 0 auto;
                padding: 4px 6px;
                font-size: 11px;
            }
            .dropdown {
                flex: 0 0 auto;
            }
            .user-avatar {
                width: 28px;
                height: 28px;
                font-size: 11px;
            }
            .dropdown-toggle span {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <button class="btn btn-sm btn-outline-secondary sidebar-close d-md-none" id="sidebarClose">
            <i class="bi bi-x-lg"></i>
        </button>
        
        <div class="sidebar-logo">
            <?php 
            // Debug: แสดงข้อมูลโลโก้
            $logoPath = !empty($settings['logo']) ? BASE_URL . 'assets/images/logo/' . $settings['logo'] : '';
            ?>
            <?php if (!empty($settings['logo']) && file_exists(__DIR__ . '/../assets/images/logo/' . $settings['logo'])): ?>
            <img src="<?php echo $logoPath; ?>" alt="Logo" style="max-height: 60px; max-width: 100%; object-fit: contain;" class="mb-2">
            <?php else: ?>
            <i class="bi bi-building"></i>
            <?php endif; ?>
            <h5><?php echo $lang === 'en' ? ($settings['dorm_name_en'] ?? t('app_name')) : ($settings['dorm_name'] ?? t('app_name')); ?></h5>
        </div>
        
        <nav class="nav flex-column">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/dashboard.php">
                <i class="bi bi-speedometer2"></i> <?php echo t('dashboard'); ?>
            </a>
            
            <hr class="text-white-50 my-3">
            
            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/rooms/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/rooms/index.php">
                <i class="bi bi-door-open"></i> <?php echo t('rooms'); ?>
            </a>
            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/room-types/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/room-types/index.php">
                <i class="bi bi-layers"></i> <?php echo t('room_types'); ?>
            </a>
            
            <hr class="text-white-50 my-3">
            
            <?php if ($settings['enable_daily'] ?? 1): ?>
            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/daily-tenants/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/daily-tenants/index.php">
                <i class="bi bi-calendar-day"></i> <?php echo t('daily_tenants'); ?>
            </a>
            <?php endif; ?>
            
            <?php if ($settings['enable_monthly'] ?? 1): ?>
            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/monthly-tenants/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/monthly-tenants/index.php">
                <i class="bi bi-calendar-month"></i> <?php echo t('monthly_tenants'); ?>
            </a>
            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/utility-bills/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/utility-bills/index.php">
                <i class="bi bi-droplet"></i> <?php echo t('utility_bills'); ?>
            </a>
            <?php endif; ?>
            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/invoices/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/invoices/index.php">
                <i class="bi bi-receipt"></i> <?php echo !empty($settings['tax_id']) ? t('tax_invoice') : t('receipt'); ?>
            </a>
            <?php 
            $pendingRepairs = 0;
            try {
                ensureRepairRequestsTable();
                $stmtRep = $pdo->query("SELECT COUNT(*) FROM repair_requests WHERE status = 'pending'");
                $pendingRepairs = (int)$stmtRep->fetchColumn();
            } catch (Exception $e) {}
            ?>
            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/repairs/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/repairs/index.php">
                <i class="bi bi-tools"></i> <?php echo t('repairs'); ?>
                <?php if ($pendingRepairs > 0): ?>
                <span class="badge bg-warning text-dark ms-auto"><?php echo $pendingRepairs; ?></span>
                <?php endif; ?>
            </a>

            <hr class="text-white-50 my-3">
            
            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/reports/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/reports/income.php">
                <i class="bi bi-graph-up"></i> <?php echo t('reports'); ?>
            </a>
            
            <?php if (isAdmin() && (int) ($settings['email_enabled'] ?? 1) === 1): ?>
            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/email-queue/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/email-queue/index.php">
                <i class="bi bi-envelope"></i> <?php echo t('email_queue'); ?>
            </a>
            <?php endif; ?>
            
            <?php if (isAdmin()): ?>
            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/admin/users.php">
                <i class="bi bi-shield-lock"></i> <?php echo t('admin'); ?>
            </a>
            <?php endif; ?>
            
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>pages/settings.php">
                <i class="bi bi-gear"></i> <?php echo t('settings'); ?>
            </a>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="topbar">
            <button class="btn btn-sm btn-outline-secondary d-md-none" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            
            <h4 class="mb-0 flex-grow-1 flex-shrink-0"><?php echo isset($pageTitle) ? $pageTitle : ''; ?></h4>
            
            <div class="user-menu">
                <a href="?lang=th" class="btn btn-sm <?php echo $lang == 'th' ? 'btn-primary' : 'btn-outline-secondary'; ?>">ไทย</a>
                <a href="?lang=en" class="btn btn-sm <?php echo $lang == 'en' ? 'btn-primary' : 'btn-outline-secondary'; ?>">EN</a>
                
                <div class="dropdown">
                    <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">
                        <div class="user-avatar"><?php echo mb_strtoupper(mb_substr($currentUser['full_name'] ?? $_SESSION['full_name'] ?? 'U', 0, 1, 'UTF-8'), 'UTF-8'); ?></div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-header text-center">
                            <strong><?php echo htmlspecialchars($currentUser['full_name'] ?? $_SESSION['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?></strong>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>pages/settings.php"><i class="bi bi-gear me-2"></i><?php echo t('settings'); ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>pages/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i><?php echo t('logout'); ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <?php if ($flash): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: '<?php echo htmlspecialchars($flash['type'] === 'danger' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : ($flash['type'] === 'info' ? 'info' : 'success'))); ?>',
                title: '<?php echo htmlspecialchars($flash['type'] === 'danger' ? t('error') : ($flash['type'] === 'warning' ? t('warning') : ($flash['type'] === 'info' ? t('info') : t('success')))); ?>',
                text: '<?php echo htmlspecialchars($flash['message']); ?>',
                confirmButtonColor: 'var(--primary-color, #0d6efd)',
                timer: 3500,
                timerProgressBar: true
            });
        });
        </script>
        <?php endif; ?>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarClose = document.getElementById('sidebarClose');
            const sidebar = document.getElementById('sidebar');
            
            // Open sidebar
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.add('show');
            });
            
            // Close sidebar
            sidebarClose.addEventListener('click', function() {
                sidebar.classList.remove('show');
            });
            
            // Close sidebar when clicking outside
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768 && 
                    !sidebar.contains(e.target) && 
                    !sidebarToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            });
        });
        </script>
