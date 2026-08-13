<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Check if this is an API request
$isApi = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$pageTitle = isset($_GET['id']) ? t('edit_monthly_tenant') : t('add_monthly_tenant');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$tenant = null;

// Check if seller has Tax ID configured
$settings = getSettings();
$sellerHasTaxId = !empty($settings['tax_id']);

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM monthly_tenants WHERE id = ?");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();
    
    if (!$tenant) {
        setFlashMessage('error', t('not_found'));
        header('Location: index.php');
        exit;
    }
}

// Get available rooms only
$currentRoomId = (int) ($tenant['room_id'] ?? 0);
$stmt = $pdo->prepare("SELECT r.*, rt.type_name, rt.price_monthly 
                   FROM rooms r 
                   JOIN room_types rt ON r.room_type_id = rt.id 
                   WHERE r.status = 'available' 
                   OR r.id = ?
                   ORDER BY r.room_number");
$stmt->execute([$currentRoomId]);
$rooms = $stmt->fetchAll();

// Add current tenant's room to the list if not included
if ($id && $tenant) {
    $currentRoomExists = false;
    foreach ($rooms as $room) {
        if ($room['id'] == $tenant['room_id']) {
            $currentRoomExists = true;
            break;
        }
    }
    
    if (!$currentRoomExists) {
        // Add current room to the list
        $stmt = $pdo->prepare("SELECT r.*, rt.type_name, rt.price_monthly 
                               FROM rooms r 
                               JOIN room_types rt ON r.room_type_id = rt.id 
                               WHERE r.id = ?");
        $stmt->execute([$tenant['room_id']]);
        $currentRoom = $stmt->fetch();
        if ($currentRoom) {
            $rooms[] = $currentRoom;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $roomId = intval($_POST['room_id'] ?? 0);
    $tenantName = sanitize($_POST['tenant_name'] ?? '');
    $phone = normalizePhoneDigits($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $customerTaxId = sanitize($_POST['customer_tax_id'] ?? '');
    $customerAddress = sanitize($_POST['customer_address'] ?? '');
    $customerBranch = sanitize($_POST['customer_branch'] ?? '00000');
    $contractStart = normalizeDateFilterValue($_POST['contract_start'] ?? '');
    $contractEnd = normalizeDateFilterValue($_POST['contract_end'] ?? '');
    $monthlyRent = floatval($_POST['monthly_rent'] ?? 0);
    $deposit = floatval($_POST['deposit'] ?? 0);
    $emergencyContact = sanitize($_POST['emergency_contact'] ?? '');
    $emergencyPhone = normalizePhoneDigits($_POST['emergency_phone'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    
    if (empty($roomId) || empty($tenantName) || empty($phone) || empty($contractStart) || empty($contractEnd)) {
        $error = t('please_enter_all_required');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } elseif (!preg_match('/^\d+$/', $phone)) {
        $error = t('phone_digits_only');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } elseif (!empty($emergencyPhone) && !preg_match('/^\d+$/', $emergencyPhone)) {
        $error = t('phone_digits_only');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } elseif (!isValidEmailFormat($email)) {
        $error = t('invalid_email_format');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } elseif ($monthlyRent <= 0 || $deposit < 0) {
        $error = t('required_field');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } elseif ($contractEnd <= $contractStart) {
        $error = t('contract_end_after_start');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } elseif (!isRoomAvailableForPeriod($roomId, $contractStart, $contractEnd, null, $id > 0 ? $id : null)) {
        $error = $lang === 'en'
            ? 'The selected room is not available for the chosen period.'
            : 'ห้องที่เลือกไม่ว่างในช่วงเวลาที่ระบุ';
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } elseif (!empty($customerTaxId) && !preg_match('/^\d{13}$/', $customerTaxId)) {
        $error = t('tax_id_13_digits_only');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } else {
        // Determine status based on dates
        $today = date('Y-m-d');
        if ($today < $contractStart) {
            $status = 'pending';
        } elseif ($today > $contractEnd) {
            $status = 'expired';
        } else {
            $status = 'active';
        }
        
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE monthly_tenants SET room_id = ?, tenant_name = ?, phone = ?, email = ?, customer_tax_id = ?, customer_address = ?, customer_branch = ?, contract_start = ?, contract_end = ?, monthly_rent = ?, deposit = ?, emergency_contact = ?, emergency_phone = ?, notes = ?, status = ? WHERE id = ?");
            $stmt->execute([$roomId, $tenantName, $phone, $email, $customerTaxId, $customerAddress, $customerBranch, $contractStart, $contractEnd, $monthlyRent, $deposit, $emergencyContact, $emergencyPhone, $notes, $status, $id]);

            // Update room status
            updateRoomStatus($roomId);
            if (!empty($tenant['room_id']) && (int) $tenant['room_id'] !== $roomId) {
                updateRoomStatus((int) $tenant['room_id']);
            }

            logActivity('update_monthly_tenant', 'monthly_tenant', $id);
            if ($isApi) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'tenant_id' => $id, 'message' => t('save_success')]);
                exit;
            }
            setFlashMessage('success', t('save_success'));
        } else {
            // Verify user exists before using as foreign key
            $createdBy = null;
            if (isset($_SESSION['user_id'])) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                if ($stmt->fetch()) {
                    $createdBy = $_SESSION['user_id'];
                }
            }

            $stmt = $pdo->prepare("INSERT INTO monthly_tenants (room_id, tenant_name, phone, email, customer_tax_id, customer_address, customer_branch, contract_start, contract_end, monthly_rent, deposit, emergency_contact, emergency_phone, notes, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$roomId, $tenantName, $phone, $email, $customerTaxId, $customerAddress, $customerBranch, $contractStart, $contractEnd, $monthlyRent, $deposit, $emergencyContact, $emergencyPhone, $notes, $status, $createdBy]);
            $newId = $pdo->lastInsertId();

            // Update room status
            updateRoomStatus($roomId);

            logActivity('create_monthly_tenant', 'monthly_tenant', $newId);
            if ($isApi) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'tenant_id' => $newId, 'message' => t('add_tenant_success')]);
                exit;
            }
            setFlashMessage('success', t('add_tenant_success'));
        }
        
        header('Location: index.php');
        exit;
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="row justify-content-center">
        <div class="col-md-10">
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
                                <label class="form-label"><?php echo t('contract_start'); ?> *</label>
                                <input type="date" name="contract_start" id="contract_start" class="form-control" value="<?php echo $tenant['contract_start'] ?? date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('contract_end'); ?> *</label>
                                <input type="date" name="contract_end" id="contract_end" class="form-control" value="<?php echo $tenant['contract_end'] ?? date('Y-m-d', strtotime('+1 year')); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('select_room'); ?> *</label>
                                <select name="room_id" id="room_id" class="form-select" required>
                                    <option value=""><?php echo t('select_room'); ?></option>
                                    <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>" data-rent="<?php echo $room['price_monthly']; ?>" <?php echo ($tenant['room_id'] ?? '') == $room['id'] ? 'selected' : ''; ?>>
                                        <?php echo t('room'); ?> <?php echo $room['room_number']; ?> - <?php echo $room['type_name']; ?> (<?php echo formatCurrency($room['price_monthly']); ?>/<?php echo t('month'); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('monthly_rent'); ?> (<?php echo t('baht'); ?>) *</label>
                                <input type="number" name="monthly_rent" id="monthly_rent" class="form-control" value="<?php echo $tenant['monthly_rent'] ?? ''; ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('tenant_name'); ?> *</label>
                                <input type="text" name="tenant_name" class="form-control" value="<?php echo htmlspecialchars($tenant['tenant_name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('phone'); ?> *</label>
                                <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($tenant['phone'] ?? ''); ?>" required inputmode="numeric" pattern="[0-9]+" title="<?php echo t('phone_digits_only'); ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('email'); ?></label>
                                <input type="email" name="email" id="emailInput" class="form-control" value="<?php echo htmlspecialchars($tenant['email'] ?? ''); ?>" autocomplete="email">
                                <div id="emailFeedback" class="invalid-feedback"></div>
                            </div>
                            <?php if ($sellerHasTaxId): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('customer_tax_id_optional'); ?></label>
                                <input type="text" name="customer_tax_id" class="form-control" value="<?php echo htmlspecialchars($tenant['customer_tax_id'] ?? ''); ?>" pattern="[0-9]{13}" maxlength="13" title="<?php echo t('tax_id_13_digits_only'); ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                <small class="text-muted"><?php echo t('customer_tax_id_help'); ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($sellerHasTaxId): ?>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('customer_address_optional'); ?></label>
                                <textarea name="customer_address" class="form-control" rows="2"><?php echo htmlspecialchars($tenant['customer_address'] ?? ''); ?></textarea>
                                <small class="text-muted"><?php echo t('customer_address_help'); ?></small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('customer_branch'); ?></label>
                                <input type="text" name="customer_branch" class="form-control" value="<?php echo htmlspecialchars($tenant['customer_branch'] ?? '00000'); ?>" maxlength="5">
                                <small class="text-muted"><?php echo t('customer_branch_help'); ?></small>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('deposit'); ?> (<?php echo t('baht'); ?>)</label>
                                <input type="number" name="deposit" class="form-control" value="<?php echo $tenant['deposit'] ?? ''; ?>">
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h6 class="mb-3"><?php echo t('emergency_contact_info'); ?></h6>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('emergency_contact_name'); ?></label>
                                <input type="text" name="emergency_contact" class="form-control" value="<?php echo htmlspecialchars($tenant['emergency_contact'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('emergency_contact_phone'); ?></label>
                                <input type="tel" name="emergency_phone" class="form-control" value="<?php echo htmlspecialchars($tenant['emergency_phone'] ?? ''); ?>" inputmode="numeric" pattern="[0-9]*" title="<?php echo t('phone_digits_only'); ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><?php echo t('notes'); ?></label>
                            <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($tenant['notes'] ?? ''); ?></textarea>
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

<script>
function loadAvailableRooms() {
    const contractStart = document.getElementById('contract_start').value;
    const contractEnd = document.getElementById('contract_end').value;
    const roomSelect = document.getElementById('room_id');
    const currentRoomId = roomSelect.value;
    const excludeId = <?php echo $id; ?>;
    
    console.log('Loading rooms for dates:', contractStart, 'to', contractEnd);
    
    if (contractStart && contractEnd) {
        fetch(`../../api/check-availability.php?check_in=${contractStart}&check_out=${contractEnd}&exclude_id=${excludeId}&exclude_type=monthly`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                console.log('API response:', data);
                
                if (data.rooms) {
                    roomSelect.innerHTML = '<option value=""><?php echo t('select_room'); ?></option>';
                    
                    // Filter out occupied rooms
                    const availableRooms = data.rooms.filter(room => room.availability !== 'occupied');
                    
                    availableRooms.forEach(room => {
                        const option = document.createElement('option');
                        option.value = room.id;
                        option.dataset.rent = room.price_monthly;
                        option.textContent = `${t('room')} ${room.room_number} - ${room.type_name} (${formatCurrency(room.price_monthly)}/${t('month')})`;
                        
                        if (room.id == currentRoomId) {
                            option.selected = true;
                        }
                        
                        roomSelect.appendChild(option);
                    });
                    
                    // If current room is occupied and being edited, show it as disabled
                    const currentRoom = data.rooms.find(room => room.id == currentRoomId);
                    if (currentRoom && currentRoom.availability === 'occupied') {
                        const option = document.createElement('option');
                        option.value = currentRoom.id;
                        option.dataset.rent = currentRoom.price_monthly;
                        option.disabled = true;
                        option.selected = true;
                        option.textContent = `${t('room')} ${currentRoom.room_number} - ${currentRoom.type_name} (${formatCurrency(currentRoom.price_monthly)}/${t('month')}) - ${t('occupied')}`;
                        roomSelect.appendChild(option);
                    }
                    
                    updateRent();
                } else if (data.error) {
                    console.error('API error:', data.error);
                }
            })
            .catch(error => {
                console.error('Error loading rooms:', error);
                // Fallback: show all rooms
                roomSelect.innerHTML = '<option value=""><?php echo t('select_room'); ?></option>';
                <?php foreach ($rooms as $room): ?>
                const option<?php echo $room['id']; ?> = document.createElement('option');
                option<?php echo $room['id']; ?>.value = '<?php echo $room['id']; ?>';
                option<?php echo $room['id']; ?>.dataset.rent = '<?php echo $room['price_monthly']; ?>';
                option<?php echo $room['id']; ?>.textContent = '<?php echo t('room'); ?> <?php echo $room['room_number']; ?> - <?php echo $room['type_name']; ?> (<?php echo formatCurrency($room['price_monthly']); ?>/<?php echo t('month'); ?>)';
                if ('<?php echo $room['id']; ?>' == currentRoomId) {
                    option<?php echo $room['id']; ?>.selected = true;
                }
                roomSelect.appendChild(option<?php echo $room['id']; ?>);
                <?php endforeach; ?>
                updateRent();
            });
    }
}

function updateRent() {
    const roomSelect = document.getElementById('room_id');
    const selected = roomSelect.options[roomSelect.selectedIndex];
    const rent = selected.dataset.rent;
    if (rent) {
        document.getElementById('monthly_rent').value = rent;
    }
}

// Helper functions for translation
function t(key) {
    const translations = {
        'room': '<?php echo t('room'); ?>',
        'month': '<?php echo t('month'); ?>',
        'occupied': '<?php echo t('occupied'); ?>',
        'select_room': '<?php echo t('select_room'); ?>'
    };
    return translations[key] || key;
}

function formatCurrency(amount) {
    return amount.toLocaleString('th-TH', {minimumFractionDigits: 2});
}

// Event listeners
document.getElementById('room_id').addEventListener('change', updateRent);
document.getElementById('contract_start').addEventListener('change', function() {
    loadAvailableRooms();
    updateRent();
});
document.getElementById('contract_end').addEventListener('change', function() {
    loadAvailableRooms();
    updateRent();
});

// ── Email real-time validation ──────────────────────────────────
(function () {
    const emailInput    = document.getElementById('emailInput');
    const emailFeedback = document.getElementById('emailFeedback');
    const form          = document.querySelector('form');

    function isValidEmail(val) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(val);
    }

    function validateEmail() {
        const val = emailInput.value.trim();
        if (val === '') {
            emailInput.classList.remove('is-invalid', 'is-valid');
            emailFeedback.textContent = '';
            return true;
        }
        if (isValidEmail(val)) {
            emailInput.classList.remove('is-invalid');
            emailInput.classList.add('is-valid');
            emailFeedback.textContent = '';
            return true;
        } else {
            emailInput.classList.remove('is-valid');
            emailInput.classList.add('is-invalid');
            emailFeedback.textContent = '<?php echo t("invalid_email_format"); ?>';
            return false;
        }
    }

    emailInput.addEventListener('blur',  validateEmail);
    emailInput.addEventListener('input', function () {
        if (emailInput.classList.contains('is-invalid') || emailInput.classList.contains('is-valid')) {
            validateEmail();
        }
    });

    form.addEventListener('submit', function (e) {
        if (!validateEmail()) {
            e.preventDefault();
            emailInput.focus();
        }
    });
}());

// Initial load
loadAvailableRooms();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
