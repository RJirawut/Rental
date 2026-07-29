<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Check if this is an API request
$isApi = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$pageTitle = isset($_GET['id']) ? t('edit_room_type') : t('add_room_type');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$roomType = null;

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM room_types WHERE id = ?");
    $stmt->execute([$id]);
    $roomType = $stmt->fetch();
    
    if (!$roomType) {
        setFlashMessage('error', t('not_found'));
        header('Location: index.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $typeName = sanitize($_POST['type_name'] ?? '');
    $typeNameEn = sanitize($_POST['type_name_en'] ?? '');
    $priceDaily = floatval($_POST['price_daily'] ?? 0);
    $priceMonthly = floatval($_POST['price_monthly'] ?? 0);
    $description = sanitize($_POST['description'] ?? '');
    $descriptionEn = sanitize($_POST['description_en'] ?? '');
    
    if (empty($typeName)) {
        $error = t('required_field');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } else {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE room_types SET type_name = ?, type_name_en = ?, price_daily = ?, price_monthly = ?, description = ?, description_en = ? WHERE id = ?");
            $stmt->execute([$typeName, $typeNameEn, $priceDaily, $priceMonthly, $description, $descriptionEn, $id]);
            logActivity('update_room_type', 'room_type', $id);
            if ($isApi) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'room_type_id' => $id, 'message' => t('save_success')]);
                exit;
            }
            setFlashMessage('success', t('save_success'));
        } else {
            $stmt = $pdo->prepare("INSERT INTO room_types (type_name, type_name_en, price_daily, price_monthly, description, description_en) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$typeName, $typeNameEn, $priceDaily, $priceMonthly, $description, $descriptionEn]);
            $newId = $pdo->lastInsertId();
            logActivity('create_room_type', 'room_type', $newId);
            if ($isApi) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'room_type_id' => $newId, 'message' => t('save_success')]);
                exit;
            }
            setFlashMessage('success', t('save_success'));
        }

        header('Location: index.php');
        exit;
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
                                <label class="form-label"><?php echo t('type_name_th'); ?> *</label>
                                <input type="text" name="type_name" class="form-control" value="<?php echo htmlspecialchars($roomType['type_name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('type_name_en'); ?></label>
                                <input type="text" name="type_name_en" class="form-control" value="<?php echo htmlspecialchars($roomType['type_name_en'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('price_daily'); ?> (<?php echo t('baht'); ?>)</label>
                                <input type="number" name="price_daily" class="form-control" value="<?php echo $roomType['price_daily'] ?? 0; ?>" step="0.01" min="0">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('price_monthly'); ?> (<?php echo t('baht'); ?>)</label>
                                <input type="number" name="price_monthly" class="form-control" value="<?php echo $roomType['price_monthly'] ?? 0; ?>" step="0.01" min="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><?php echo t('description_th'); ?></label>
                            <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($roomType['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><?php echo t('description_en'); ?></label>
                            <textarea name="description_en" class="form-control" rows="3"><?php echo htmlspecialchars($roomType['description_en'] ?? ''); ?></textarea>
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
