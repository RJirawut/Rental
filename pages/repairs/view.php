<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

ensureRepairRequestsTable();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    setFlashMessage('error', t('not_found'));
    header('Location: index.php');
    exit;
}

// Fetch repair data first (needed for both GET and POST)
$stmt = $pdo->prepare("SELECT * FROM repair_requests WHERE id = ?");
$stmt->execute([$id]);
$repair = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$repair) {
    setFlashMessage('error', t('not_found'));
    header('Location: index.php');
    exit;
}

// Handle Status/Note Update from view page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    requireValidCsrfToken();

    $status = $_POST['status'] ?? 'pending';
    $adminNote = trim($_POST['admin_note'] ?? '');

    if (in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'], true)) {
        // Get old status before updating
        $oldStatus = $repair['status'];
        
        $stmtUpdate = $pdo->prepare("UPDATE repair_requests SET status = ?, admin_note = ?, updated_at = NOW() WHERE id = ?");
        if ($stmtUpdate->execute([$status, $adminNote, $id])) {
            logActivity('update_repair_status', 'repair_requests', $id, "Updated repair ticket #{$id} status to {$status}");
            
            // Send email notification if status changed and email is provided
            if ($oldStatus !== $status && !empty($repair['email'])) {
                $lang = $_SESSION['lang'] ?? 'th';
                sendRepairStatusUpdateEmail($repair, $oldStatus, $status, $lang);
            }
            
            setFlashMessage('success', t('update_success'));
        } else {
            setFlashMessage('error', t('update_error'));
        }
    }
    header('Location: view.php?id=' . $id);
    exit;
}

$pageTitle = t('view_details') . ' - ' . $repair['ticket_number'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-tools me-2"></i>
                        <?php echo t('view_details'); ?> - <span class="font-monospace fw-bold"><?php echo htmlspecialchars($repair['ticket_number']); ?></span>
                    </h5>
                    <?php
                    if ($repair['status'] === 'pending') {
                        echo '<span class="badge bg-warning text-dark">รอดำเนินการ</span>';
                    } elseif ($repair['status'] === 'in_progress') {
                        echo '<span class="badge bg-info text-dark">กำลังดำเนินการ</span>';
                    } elseif ($repair['status'] === 'completed') {
                        echo '<span class="badge bg-success">เสร็จสิ้น</span>';
                    } elseif ($repair['status'] === 'cancelled') {
                        echo '<span class="badge bg-secondary">ยกเลิก</span>';
                    }
                    ?>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6 mb-2">
                            <label class="form-label text-muted">รหัสแจ้งซ่อม</label>
                            <p class="form-control-plaintext fw-bold font-monospace"><?php echo htmlspecialchars($repair['ticket_number']); ?></p>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label text-muted">ห้องพัก</label>
                            <p class="form-control-plaintext fw-bold fs-5"><?php echo htmlspecialchars($repair['room_number']); ?></p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6 mb-2">
                            <label class="form-label text-muted">ชื่อผู้แจ้ง</label>
                            <p class="form-control-plaintext fw-bold"><?php echo htmlspecialchars($repair['reporter_name']); ?></p>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label text-muted">เบอร์โทรศัพท์ติดต่อ</label>
                            <p class="form-control-plaintext fw-bold">
                                <i class="bi bi-telephone me-1 text-muted"></i><?php echo htmlspecialchars($repair['phone']); ?>
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6 mb-2">
                            <label class="form-label text-muted">ระดับความเร่งด่วน</label>
                            <p class="form-control-plaintext">
                                <?php
                                if ($repair['priority'] === 'very_urgent') {
                                    echo '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>ด่วนมาก</span>';
                                } elseif ($repair['priority'] === 'urgent') {
                                    echo '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-circle me-1"></i>ด่วน</span>';
                                } else {
                                    echo '<span class="badge bg-info text-dark">ปกติ</span>';
                                }
                                ?>
                            </p>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label text-muted">วันที่แจ้งเรื่อง</label>
                            <p class="form-control-plaintext text-muted">
                                <?php echo formatDate($repair['created_at'], 'd/m/Y H:i'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label text-muted">เรื่อง / หัวข้อปัญหา</label>
                            <p class="form-control-plaintext fw-bold fs-6"><?php echo htmlspecialchars($repair['title']); ?></p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label text-muted">รายละเอียดปัญหา</label>
                            <div class="p-3 bg-light rounded border">
                                <?php echo nl2br(htmlspecialchars($repair['description'])); ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($repair['image'])): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label text-muted">รูปถ่ายประกอบปัญหา</label>
                            <div>
                                <a href="<?php echo BASE_URL . htmlspecialchars($repair['image']); ?>" target="_blank">
                                    <img src="<?php echo BASE_URL . htmlspecialchars($repair['image']); ?>" alt="Repair photo" class="img-thumbnail" style="max-height: 300px;">
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($repair['admin_note'])): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label text-muted">บันทึกของช่าง / ผู้ดูแล</label>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-chat-left-text me-1"></i>
                                <?php echo nl2br(htmlspecialchars($repair['admin_note'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card-footer text-center py-3">
                    <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalUpdateStatus">
                        <i class="bi bi-pencil me-1"></i><?php echo t('edit'); ?> / อัพเดทสถานะ
                    </button>
                    <a href="index.php" class="btn btn-secondary ms-2">
                        <i class="bi bi-arrow-left me-1"></i><?php echo t('back'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal fade" id="modalUpdateStatus" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-start">
            <form method="POST" action="">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="update_status">

                <div class="modal-header">
                    <h5 class="modal-title mb-0">
                        <i class="bi bi-tools me-2"></i>จัดการรายการแจ้งซ่อม (<?php echo htmlspecialchars($repair['ticket_number']); ?>)
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">อัพเดทสถานะ</label>
                        <select name="status" class="form-select">
                            <option value="pending" <?php echo $repair['status'] === 'pending' ? 'selected' : ''; ?>>รอดำเนินการ</option>
                            <option value="in_progress" <?php echo $repair['status'] === 'in_progress' ? 'selected' : ''; ?>>กำลังดำเนินการ</option>
                            <option value="completed" <?php echo $repair['status'] === 'completed' ? 'selected' : ''; ?>>เสร็จสิ้น</option>
                            <option value="cancelled" <?php echo $repair['status'] === 'cancelled' ? 'selected' : ''; ?>>ยกเลิกรายการ</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">บันทึกของช่าง/ผู้ดูแล (แสดงให้ผู้แจ้งเห็นได้)</label>
                        <textarea name="admin_note" class="form-control" rows="3" placeholder="ระบุหมายเหตุ เช่น ช่างเข้าซ่อมวันที่ 24/07 เวลา 10:00 น. หรือ เปลี่ยนอะไหล่เรียบร้อย"><?php echo htmlspecialchars($repair['admin_note'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
