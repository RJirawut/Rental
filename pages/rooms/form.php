<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = isset($_GET['id']) ? t('edit_room') : t('add_room');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$room = null;

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    $room = $stmt->fetch();

    if (!$room) {
        setFlashMessage('error', t('not_found'));
        header('Location: index.php');
        exit;
    }
}

// Get room types
$stmt = $pdo->query("SELECT * FROM room_types WHERE is_active = 1 ORDER BY type_name");
$roomTypes = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $roomNumber = sanitize($_POST['room_number'] ?? '');
    $roomTypeId = intval($_POST['room_type_id'] ?? 0);
    $floor = intval($_POST['floor'] ?? 1);
    $status = sanitize($_POST['status'] ?? 'available');
    $notes = sanitize($_POST['notes'] ?? '');

    if (empty($roomNumber) || $roomTypeId === 0) {
        $error = t('please_enter_room_number_and_type');
    } else {
        // Check duplicate room number
        $stmt = $pdo->prepare("SELECT id FROM rooms WHERE room_number = ? AND id != ?");
        $stmt->execute([$roomNumber, $id]);
        if ($stmt->fetch()) {
            $error = t('room_number_already_exists');
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE rooms SET room_number = ?, room_type_id = ?, floor = ?, status = ?, notes = ? WHERE id = ?");
                $stmt->execute([$roomNumber, $roomTypeId, $floor, $status, $notes, $id]);
                logActivity('update_room', 'room', $id);
                setFlashMessage('success', t('save_success'));
            } else {
                $stmt = $pdo->prepare("INSERT INTO rooms (room_number, room_type_id, floor, status, notes) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$roomNumber, $roomTypeId, $floor, $status, $notes]);
                $newId = $pdo->lastInsertId();
                logActivity('create_room', 'room', $newId);
                setFlashMessage('success', t('save_success'));
            }

            header('Location: index.php');
            exit;
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
                    
                    <form method="POST" action="">
                        <?php echo csrfInput(); ?>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('room_number'); ?> *</label>
                                <input type="text" name="room_number" class="form-control" value="<?php echo htmlspecialchars($room['room_number'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('room_type'); ?> *</label>
                                <select name="room_type_id" class="form-select" required>
                                    <option value=""><?php echo t('select_room_type'); ?></option>
                                    <?php foreach ($roomTypes as $type): ?>
                                    <option value="<?php echo $type['id']; ?>" <?php echo ($room['room_type_id'] ?? '') == $type['id'] ? 'selected' : ''; ?>>
                                        <?php echo $type['type_name']; ?> (<?php echo formatCurrency($type['price_daily']); ?>/<?php echo formatCurrency($type['price_monthly']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('floor'); ?></label>
                                <input type="number" name="floor" class="form-control" value="<?php echo $room['floor'] ?? 1; ?>" min="1">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('status'); ?></label>
                                <select name="status" class="form-select">
                                    <option value="available" <?php echo ($room['status'] ?? '') === 'available' ? 'selected' : ''; ?>><?php echo t('available'); ?></option>
                                    <option value="maintenance" <?php echo ($room['status'] ?? '') === 'maintenance' ? 'selected' : ''; ?>><?php echo t('maintenance'); ?></option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><?php echo t('notes'); ?></label>
                            <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($room['notes'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="text-end">
                            <a href="index.php" class="btn btn-secondary me-2"><?php echo t('cancel'); ?></a>
                            <button type="submit" class="btn btn-primary"><?php echo t('save'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
