<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load language
require_once __DIR__ . '/../assets/lang/language.php';

ensureRepairRequestsTable();

$settings = getSettings();
$dormName = $settings['dorm_name'] ?? 'Rental System';
$primaryColor = $settings['primary_color'] ?? '#0d6efd';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor)) {
    $primaryColor = '#0d6efd';
}

// Fetch active rooms for dropdown
$rooms = [];
try {
    $stmtRooms = $pdo->query("SELECT room_number FROM rooms ORDER BY room_number ASC");
    $rooms = $stmtRooms->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$successTicket = $_SESSION['success_ticket'] ?? null;
if ($successTicket) {
    unset($_SESSION['success_ticket']);
}
$errorMsg = null;
$trackedRequest = null;

// Handle Ticket Search/Track via GET ?ticket=REP-xxx
$searchTicket = trim($_GET['ticket'] ?? '');
if (!empty($searchTicket)) {
    $stmtTrack = $pdo->prepare("SELECT * FROM repair_requests WHERE ticket_number = ?");
    $stmtTrack->execute([$searchTicket]);
    $trackedRequest = $stmtTrack->fetch();
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_repair') {
    $roomNumber = trim($_POST['room_number'] ?? '');
    $reporterName = trim($_POST['reporter_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    $description = trim($_POST['description'] ?? '');

    if (!in_array($priority, ['normal', 'urgent', 'very_urgent'], true)) {
        $priority = 'normal';
    }

    if (empty($roomNumber) || empty($reporterName) || empty($phone) || empty($title) || empty($description)) {
        $errorMsg = t('please_enter_all_required');
    } else {
        // Upload image if provided
        $imagePath = null;
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../assets/images/repairs/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $filename = 'repair_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $filename)) {
                    $imagePath = 'assets/images/repairs/' . $filename;
                }
            }
        }

        // Generate unique Ticket Number (e.g., REP-20260723-A4F2)
        $dateCode = date('Ymd');
        $randomCode = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
        $ticketNumber = 'REP-' . $dateCode . '-' . $randomCode;

        // Try to match room_id
        $roomId = null;
        $stmtRoomMatch = $pdo->prepare("SELECT id FROM rooms WHERE room_number = ? LIMIT 1");
        $stmtRoomMatch->execute([$roomNumber]);
        $roomMatch = $stmtRoomMatch->fetch();
        if ($roomMatch) {
            $roomId = $roomMatch['id'];
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO repair_requests 
                (ticket_number, room_id, room_number, reporter_name, phone, email, title, description, priority, status, image, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
            ");
            $stmt->execute([
                $ticketNumber,
                $roomId,
                $roomNumber,
                $reporterName,
                $phone,
                $email,
                $title,
                $description,
                $priority,
                $imagePath
            ]);

            logActivity('submit_public_repair', 'repair_requests', $pdo->lastInsertId(), "Public repair ticket {$ticketNumber} for Room {$roomNumber}");

            // Send email if email is provided
            if (!empty($email) && isValidEmailFormat($email)) {
                $repairData = [
                    'ticket_number' => $ticketNumber,
                    'room_number' => $roomNumber,
                    'reporter_name' => $reporterName,
                    'phone' => $phone,
                    'email' => $email,
                    'title' => $title,
                    'description' => $description,
                    'priority' => $priority
                ];
                sendRepairSubmissionEmail($repairData, $lang);
            }

            // Store in Session Flash and Redirect to clean URL
            $_SESSION['success_ticket'] = $ticketNumber;
            header('Location: repair-request.php');
            exit;
        } catch (Exception $e) {
            $errorMsg = t('save_error') . ': ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($dormName); ?> - <?php echo t('repair_request'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%); min-height: 100vh; display: flex; flex-direction: column; }
        .form-container { max-width: 680px; margin: 2rem auto; width: 100%; padding: 0 1rem; flex: 1; }
        .header-bg { background-color: <?php echo htmlspecialchars($primaryColor); ?>; background-image: linear-gradient(135deg, rgba(255,255,255,.1), rgba(0,0,0,.1)); color: #fff; padding: 2.5rem 1rem 3.5rem; border-radius: 1rem 1rem 0 0; text-align: center; }
        .form-card { background: #fff; border-radius: 1rem; box-shadow: 0 10px 30px rgba(0,0,0,.08); margin-top: -2.5rem; padding: 2rem; position: relative; }
        .footer { text-align: center; padding: 1rem; color: #6c757d; font-size: .875rem; margin-top: auto; }
        .logo-img { max-height: 80px; margin-bottom: 1rem; border-radius: .5rem; }
        .btn-primary { background-color: <?php echo htmlspecialchars($primaryColor); ?>; border-color: <?php echo htmlspecialchars($primaryColor); ?>; }
        .btn-primary:hover { filter: brightness(.9); }
        .priority-badge-normal { background-color: #0dcaf0; color: #000; }
        .priority-badge-urgent { background-color: #fd7e14; color: #fff; }
        .priority-badge-very_urgent { background-color: #dc3545; color: #fff; }
        .card-header-theme { background-color: <?php echo htmlspecialchars($primaryColor); ?>; color: #fff; }
        .card-border-theme { border-color: <?php echo htmlspecialchars($primaryColor); ?>; }
        .badge-theme { background-color: <?php echo htmlspecialchars($primaryColor); ?>; color: #fff; }
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
            <img src="<?php echo htmlspecialchars(BASE_URL . 'assets/images/logo/' . $settings['logo']); ?>" alt="Logo" class="logo-img">
        <?php endif; ?>
        <h4 class="mb-1"><i class="bi bi-tools me-2"></i><?php echo t('repair_request'); ?></h4>
        <p class="mb-0 text-white-50 small"><?php echo htmlspecialchars($dormName); ?></p>
    </div>

    <div class="form-card">
        <!-- Ticket Search / Track Form -->
        <div class="mb-4 pb-3 border-bottom">
            <form method="GET" action="" class="row g-2 align-items-center">
                <div class="col-8 col-sm-9">
                    <input type="text" name="ticket" class="form-control" placeholder="<?php echo t('enter_ticket_placeholder'); ?>" value="<?php echo htmlspecialchars($searchTicket); ?>">
                </div>
                <div class="col-4 col-sm-3">
                    <button type="submit" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-search me-1"></i><?php echo t('track'); ?>
                    </button>
                </div>
            </form>
        </div>

        <?php if ($trackedRequest): ?>
            <!-- Tracked Ticket Display -->
            <div class="card card-border-theme mb-4">
                <div class="card-header card-header-theme d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-ticket-perforated me-2"></i><?php echo t('ticket_number'); ?>: <?php echo htmlspecialchars($trackedRequest['ticket_number']); ?></strong>
                    <?php
                    $statusClass = 'badge-theme';
                    $statusText = t('status_pending');
                    if ($trackedRequest['status'] === 'in_progress') {
                        $statusClass = 'badge-theme';
                        $statusText = t('status_in_progress');
                    } elseif ($trackedRequest['status'] === 'completed') {
                        $statusClass = 'badge-theme';
                        $statusText = t('status_completed');
                    } elseif ($trackedRequest['status'] === 'cancelled') {
                        $statusClass = 'badge-theme';
                        $statusText = t('status_cancelled');
                    }
                    ?>
                    <span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong><?php echo t('room'); ?>:</strong> <?php echo htmlspecialchars($trackedRequest['room_number']); ?> | <strong><?php echo t('reporter'); ?>:</strong> <?php echo htmlspecialchars($trackedRequest['reporter_name']); ?></p>
                    <p class="mb-1"><strong><?php echo t('issue'); ?>:</strong> <?php echo htmlspecialchars($trackedRequest['title']); ?></p>
                    <p class="mb-2 text-muted"><strong><?php echo t('repair_details'); ?>:</strong> <?php echo nl2br(htmlspecialchars($trackedRequest['description'])); ?></p>
                    <?php if (!empty($trackedRequest['admin_note'])): ?>
                        <div class="alert alert-light border mt-2 mb-0">
                            <strong><i class="bi bi-info-circle me-1"></i><?php echo t('admin_note'); ?>:</strong><br>
                            <?php echo nl2br(htmlspecialchars($trackedRequest['admin_note'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-center mb-4">
                <a href="repair-request.php" class="btn btn-outline-primary"><i class="bi bi-plus-circle me-1"></i><?php echo t('new_repair_request'); ?></a>
            </div>
        <?php endif; ?>

        <?php if ($successTicket): ?>
            <!-- Success Card -->
            <div class="text-center py-4">
                <i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i>
                <h4 class="mt-3"><?php echo t('repair_submitted_success'); ?></h4>
                <p class="text-muted mb-2 small"><?php echo t('repair_submitted_desc'); ?></p>
                <div class="alert alert-success d-inline-block px-4 py-2 my-3">
                    <span class="d-block text-muted small mb-1"><?php echo t('tracking_code_label'); ?></span>
                    <strong class="fs-5 text-dark font-monospace" id="ticketCode"><?php echo htmlspecialchars($successTicket); ?></strong>
                </div>
                <div>
                    <button class="btn btn-outline-secondary me-2" onclick="copyTicket()"><i class="bi bi-clipboard me-1"></i><?php echo t('copy_code'); ?></button>
                    <a href="repair-request.php?ticket=<?php echo urlencode($successTicket); ?>" class="btn btn-primary"><i class="bi bi-search me-1"></i><?php echo t('track_status'); ?></a>
                </div>
                <hr class="my-4">
                <a href="repair-request.php" class="btn btn-outline-primary"><i class="bi bi-plus-circle me-1"></i><?php echo t('another_repair_request'); ?></a>
            </div>
            <script>
            function copyTicket() {
                const code = document.getElementById('ticketCode').innerText;
                navigator.clipboard.writeText(code).then(() => {
                    alert('<?php echo t('code_copied'); ?>: ' + code);
                });
            }
            </script>
        <?php elseif (!$trackedRequest): ?>
            <!-- Submission Form -->
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($errorMsg); ?></div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="submit_repair">

                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label"><?php echo t('room_number'); ?> <span class="text-danger">*</span></label>
                        <?php if (!empty($rooms)): ?>
                            <select name="room_number" class="form-select" required>
                                <option value=""><?php echo t('select_room'); ?></option>
                                <?php foreach ($rooms as $rNum): ?>
                                    <option value="<?php echo htmlspecialchars($rNum); ?>"><?php echo htmlspecialchars($rNum); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="room_number" class="form-control" placeholder="<?php echo t('room_number_placeholder'); ?>" required>
                        <?php endif; ?>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label"><?php echo t('priority'); ?></label>
                        <select name="priority" class="form-select">
                            <option value="normal"><?php echo t('priority_normal'); ?></option>
                            <option value="urgent"><?php echo t('priority_urgent'); ?></option>
                            <option value="very_urgent"><?php echo t('priority_very_urgent'); ?></option>
                        </select>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label"><?php echo t('reporter_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="reporter_name" class="form-control" placeholder="<?php echo t('name_placeholder'); ?>" required>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label"><?php echo t('phone'); ?> <span class="text-danger">*</span></label>
                        <input type="tel" name="phone" class="form-control" placeholder="<?php echo t('phone_placeholder'); ?>" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label"><?php echo t('email'); ?></label>
                        <input type="email" name="email" class="form-control" placeholder="<?php echo t('email_placeholder'); ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label"><?php echo t('repair_title'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="<?php echo t('issue_placeholder'); ?>" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label"><?php echo t('repair_details'); ?> <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="4" placeholder="<?php echo t('description_placeholder'); ?>" required></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label"><?php echo t('attach_image'); ?></label>
                        <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <div class="form-text"><?php echo t('supported_image_formats'); ?></div>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary w-100 py-2">
                        <i class="bi bi-send-check me-2"></i><?php echo t('submit_repair'); ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<footer class="footer">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($dormName); ?>. <?php echo t('all_rights_reserved'); ?></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
