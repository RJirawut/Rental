<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = isset($_GET['id']) ? t('edit_guest') : t('add_guest');
ensureDailyTenantOtherFeesColumn();
ensureDailyTenantPaymentDeadlineColumn();
ensureDailyTenantPendingPaymentStatus();
ensureRoomTypePriceHistoryTable();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$tenant = null;

// Check if seller has Tax ID configured
$settings = getSettings();
$sellerHasTaxId = !empty($settings['tax_id']);

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM daily_tenants WHERE id = ?");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();
    
    if (!$tenant) {
        setFlashMessage('error', t('not_found'));
        header('Location: index.php');
        exit;
    }
}

// Get available rooms (exclude maintenance, only show available + current room when editing)
$currentRoomId = (int) ($tenant['room_id'] ?? 0);
$stmt = $pdo->prepare("SELECT r.*, rt.type_name, rt.price_daily FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id WHERE r.status != 'maintenance' AND (r.status = 'available' OR r.id = ?) ORDER BY r.room_number");
$stmt->execute([$currentRoomId]);
$rooms = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $roomId = intval($_POST['room_id'] ?? 0);
    $guestName = sanitize($_POST['guest_name'] ?? '');
    $phone = normalizePhoneDigits($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $customerTaxId = sanitize($_POST['customer_tax_id'] ?? '');
    $customerAddress = sanitize($_POST['customer_address'] ?? '');
    $customerBranch = sanitize($_POST['customer_branch'] ?? '00000');
    $checkIn = normalizeDateFilterValue($_POST['check_in_date'] ?? '');
    $checkOut = normalizeDateFilterValue($_POST['check_out_date'] ?? '');
    $numGuests = intval($_POST['num_guests'] ?? 1);
    $otherFees = floatval($_POST['other_fees'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');
    
    // Validation
    if (empty($roomId) || empty($guestName) || empty($checkIn) || empty($checkOut)) {
        $error = t('please_enter_all_required');
    } elseif (!empty($phone) && !preg_match('/^\d+$/', $phone)) {
        $error = t('phone_digits_only');
    } elseif (!isValidEmailFormat($email)) {
        $error = t('invalid_email_format');
    } elseif ($numGuests < 1 || $otherFees < 0) {
        $error = t('please_enter_all_required');
    } elseif ($checkOut <= $checkIn) {
        $error = t('minimum_one_night_required');
    } elseif (!isRoomAvailableForPeriod($roomId, $checkIn, $checkOut, $id > 0 ? $id : null)) {
        $error = $lang === 'en'
            ? 'The selected room is not available for the chosen period.'
            : 'ห้องที่เลือกไม่ว่างในช่วงเวลาที่ระบุ';
    } else {
        // Calculate days and amount
        // For hotel booking: check-in day 1 to check-out day 2 = 1 night (1 day)
        $checkInDate = new DateTime($checkIn);
        $checkOutDate = new DateTime($checkOut);
        $diff = $checkInDate->diff($checkOutDate);
        $totalDays = max(1, $diff->days);
        
        // Calculate from the rate effective on the check-in date.  The value
        // saved here remains the booking and invoice snapshot afterwards.
        $stmt = $pdo->prepare("SELECT room_type_id FROM rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $roomData = $stmt->fetch();
        if (!$roomData) {
            $error = t('not_found');
        } else {
        $dailyRate = $id > 0 && (int) ($tenant['room_id'] ?? 0) === $roomId
            ? (float) $tenant['daily_rate']
            : getRoomTypePriceForDate((int) $roomData['room_type_id'], $checkIn)['price_daily'];
        $roomAmount = $dailyRate * $totalDays;
        $totalAmount = $roomAmount + $otherFees;
        
        // Save data (rooms shown in dropdown are already filtered as available)
        $newId = 0; // Initialize to prevent IDE warning
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE daily_tenants SET room_id = ?, guest_name = ?, phone = ?, email = ?, customer_tax_id = ?, customer_address = ?, customer_branch = ?, check_in_date = ?, check_out_date = ?, num_guests = ?, daily_rate = ?, total_days = ?, total_amount = ?, other_fees = ?, notes = ? WHERE id = ?");
            $stmt->execute([$roomId, $guestName, $phone, $email, $customerTaxId, $customerAddress, $customerBranch, $checkIn, $checkOut, $numGuests, $dailyRate, $totalDays, $totalAmount, $otherFees, $notes, $id]);
            updateRoomStatus($roomId);
            if (!empty($tenant['room_id']) && (int) $tenant['room_id'] !== $roomId) {
                updateRoomStatus((int) $tenant['room_id']);
            }
            logActivity('update_daily_tenant', 'daily_tenant', $id);
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
            
            $settings = getSettings();
            $paymentDeadlineHours = $settings['daily_payment_deadline_hours'] ?? 24;
            $paymentDeadline = date('Y-m-d H:i:s', strtotime("+{$paymentDeadlineHours} hours"));
            
            $stmt = $pdo->prepare("INSERT INTO daily_tenants (room_id, guest_name, phone, email, customer_tax_id, customer_address, customer_branch, check_in_date, check_out_date, num_guests, daily_rate, total_days, total_amount, other_fees, notes, status, payment_deadline, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment', ?, ?)");
            $stmt->execute([$roomId, $guestName, $phone, $email, $customerTaxId, $customerAddress, $customerBranch, $checkIn, $checkOut, $numGuests, $dailyRate, $totalDays, $totalAmount, $otherFees, $notes, $paymentDeadline, $createdBy]);
            $newId = $pdo->lastInsertId();

            updateRoomStatus($roomId);

            // Auto-generate receipt for new daily tenant
            $receiptNumber = generateReceiptNumber();
            $itemDescription = sprintf(t('room_charge_days_desc'), $totalDays);

            // Create receipt (no VAT for receipt)
            $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, invoice_type, tenant_type, tenant_id, room_id, invoice_date, due_date, subtotal, vat_rate, vat_amount, grand_total, status, created_by, customer_tax_id, customer_branch_code) VALUES (?, 'daily', 'daily', ?, ?, ?, ?, ?, 0, 0, ?, 'issued', ?, ?, ?)");
            $stmt->execute([
                $receiptNumber,
                $newId,
                $roomId,
                $checkIn,
                $checkOut,
                $totalAmount,
                $totalAmount,
                $createdBy,
                $customerTaxId ?: null,
                $customerBranch ?: '00000'
            ]);

            $invoiceId = $pdo->lastInsertId();

            // Add invoice item
            $stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $invoiceId,
                $itemDescription,
                $totalDays,
                $dailyRate,
                $roomAmount
            ]);

            if ($otherFees > 0) {
                $stmt->execute([
                    $invoiceId,
                    t('other_fees'),
                    1,
                    $otherFees,
                    $otherFees
                ]);
            }

            logActivity('create_daily_tenant', 'daily_tenant', $newId);
            logActivity('generate_receipt', 'invoice', $invoiceId);
            
            $emailSent = sendDailyPendingPaymentEmail((int) $newId);
            if (!$emailSent) {
                error_log("Failed to send pending payment email for tenant ID $newId");
            }
            
            setFlashMessage('success', t('add_guest_success'));
        }
        }

        if (empty($error)) {
            $redirectId = $id > 0 ? $id : $newId;
            header('Location: invoice.php?id=' . $redirectId);
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
                    
                    <form method="POST" action="" id="dailyTenantForm">
                        <?php echo csrfInput(); ?>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('check_in'); ?> *</label>
                                <input type="date" name="check_in_date" id="check_in" class="form-control" value="<?php echo $tenant['check_in_date'] ?? date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('check_out'); ?> *</label>
                                <input type="date" name="check_out_date" id="check_out" class="form-control" value="<?php echo $tenant['check_out_date'] ?? date('Y-m-d', strtotime('+1 day')); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('select_room'); ?> *</label>
                                <select name="room_id" id="room_id" class="form-select" required>
                                    <option value=""><?php echo t('select_room'); ?></option>
                                    <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>" data-price="<?php echo $room['price_daily']; ?>" <?php echo ($tenant['room_id'] ?? '') == $room['id'] ? 'selected' : ''; ?>>
                                        <?php echo t('room'); ?> <?php echo $room['room_number']; ?> - <?php echo $room['type_name']; ?> (<?php echo formatCurrency($room['price_daily']); ?>/<?php echo t('night'); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('num_guests'); ?></label>
                                <input type="number" name="num_guests" class="form-control" value="<?php echo $tenant['num_guests'] ?? 1; ?>" min="1" max="10">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('guest_name'); ?> *</label>
                                <input type="text" name="guest_name" class="form-control" value="<?php echo htmlspecialchars($tenant['guest_name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('phone'); ?></label>
                                <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($tenant['phone'] ?? ''); ?>" inputmode="numeric" pattern="[0-9]*" title="<?php echo t('phone_digits_only'); ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('email'); ?></label>
                                <input type="email" name="email" id="emailInput" class="form-control" value="<?php echo htmlspecialchars($tenant['email'] ?? ''); ?>" autocomplete="email">
                                <div id="emailFeedback" class="invalid-feedback"></div>
                                <small id="emailHint" class="text-muted d-none"><?php echo t('invalid_email_format'); ?></small>
                            </div>
                            <?php if ($sellerHasTaxId): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('customer_tax_id_optional'); ?></label>
                                <input type="text" name="customer_tax_id" class="form-control" value="<?php echo htmlspecialchars($tenant['customer_tax_id'] ?? ''); ?>" maxlength="13">
                                <small class="text-muted"><?php echo t('customer_tax_id_help_daily'); ?></small>
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
                                <label class="form-label"><?php echo t('daily_rate'); ?></label>
                                <input type="text" id="daily_rate_display" class="form-control" readonly value="<?php echo formatCurrency($tenant['daily_rate'] ?? 0); ?>">
                                <input type="hidden" name="daily_rate" id="daily_rate" value="<?php echo $tenant['daily_rate'] ?? 0; ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('total_days'); ?></label>
                                <input type="text" id="total_days_display" class="form-control" readonly value="<?php echo $tenant['total_days'] ?? 1; ?> <?php echo t('days'); ?>">
                                <input type="hidden" name="total_days" id="total_days" value="<?php echo $tenant['total_days'] ?? 1; ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?php echo t('other_fees'); ?></label>
                            <input type="number" name="other_fees" id="other_fees" class="form-control" value="<?php echo htmlspecialchars($tenant['other_fees'] ?? 0); ?>" step="0.01" min="0">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><?php echo t('grand_total'); ?></label>
                            <input type="text" id="total_amount_display" class="form-control form-control-lg fw-bold text-primary" readonly value="<?php echo formatCurrency($tenant['total_amount'] ?? 0); ?>">
                            <input type="hidden" name="total_amount" id="total_amount" value="<?php echo $tenant['total_amount'] ?? 0; ?>">
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
const tenantCurrentRoomId = <?php echo json_encode($tenant['room_id'] ?? 0); ?>;

function loadAvailableRooms() {
    const checkIn = document.getElementById('check_in').value;
    const checkOut = document.getElementById('check_out').value;
    const roomSelect = document.getElementById('room_id');
    const currentRoomId = tenantCurrentRoomId || roomSelect.value;
    const excludeId = <?php echo $id; ?>;
    
    if (checkIn && checkOut) {
        // Store current room info before clearing (for editing mode)
        let currentRoomOption = null;
        if (tenantCurrentRoomId && roomSelect.value == tenantCurrentRoomId) {
            const selectedOption = roomSelect.options[roomSelect.selectedIndex];
            if (selectedOption && selectedOption.value) {
                currentRoomOption = {
                    id: selectedOption.value,
                    price: selectedOption.dataset.price,
                    text: selectedOption.textContent
                };
            }
        }
        
        fetch(`../../api/check-availability.php?check_in=${checkIn}&check_out=${checkOut}&exclude_id=${excludeId}&exclude_type=daily`)
            .then(response => response.json())
            .then(data => {
                if (data.rooms) {
                    roomSelect.innerHTML = '<option value=""><?php echo t('select_room'); ?></option>';
                    let hasCurrentRoom = false;
                    
                    data.rooms.forEach(room => {
                        // Skip ALL occupied rooms (including current room being edited)
                        if (room.availability === 'occupied') {
                            return;
                        }

                        const option = document.createElement('option');
                        option.value = room.id;
                        option.dataset.price = room.price_daily;
                        option.textContent = `${t('room')} ${room.room_number} - ${room.type_name} (${formatCurrency(room.price_daily)}/${t('night')})`;

                        if (room.id == currentRoomId) {
                            option.selected = true;
                            hasCurrentRoom = true;
                        }

                        roomSelect.appendChild(option);
                    });

                    calculateTotals();
                }
            })
            .catch(error => console.error('Error:', error));
    }
}

function calculateTotals() {
    const roomSelect = document.getElementById('room_id');
    const checkIn = document.getElementById('check_in').value;
    const checkOut = document.getElementById('check_out').value;
    
    if (roomSelect.value && checkIn && checkOut) {
        const price = parseFloat(roomSelect.options[roomSelect.selectedIndex].dataset.price) || 0;
        const otherFees = parseFloat(document.getElementById('other_fees').value) || 0;
        const start = new Date(checkIn);
        const end = new Date(checkOut);
        
        // Calculate nights: day 1 to day 2 = 1 night (hotel booking style)
        const diffTime = end - start;
        const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
        const totalNights = Math.max(1, diffDays);
        const totalAmount = (price * totalNights) + otherFees;
        
        document.getElementById('daily_rate').value = price;
        document.getElementById('daily_rate_display').value = price.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
        document.getElementById('total_days').value = totalNights;
        document.getElementById('total_days_display').value = totalNights + ' <?php echo t('nights'); ?>';
        document.getElementById('total_amount').value = totalAmount;
        document.getElementById('total_amount_display').value = totalAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
    }
}

// Helper functions for translation
function t(key) {
    const translations = {
        'room': '<?php echo t('room'); ?>',
        'night': '<?php echo t('night'); ?>',
        'nights': '<?php echo t('nights'); ?>',
        'occupied': '<?php echo t('occupied'); ?>',
        'select_room': '<?php echo t('select_room'); ?>',
        'days': '<?php echo t('days'); ?>'
    };
    return translations[key] || key;
}

function formatCurrency(amount) {
    return amount.toLocaleString('th-TH', {minimumFractionDigits: 2});
}

// ── Email real-time validation ──────────────────────────────────
(function () {
    const emailInput   = document.getElementById('emailInput');
    const emailFeedback = document.getElementById('emailFeedback');
    const form         = document.getElementById('dailyTenantForm');

    function isValidEmail(val) {
        // Strict format: local@domain.tld (TLD ≥ 2 chars)
        return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(val);
    }

    function validateEmail() {
        const val = emailInput.value.trim();
        if (val === '') {
            // Empty = optional, clear state
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
        // Only show green/red after user has blurred at least once
        if (emailInput.classList.contains('is-invalid') || emailInput.classList.contains('is-valid')) {
            validateEmail();
        }
    });

    // Block submit if email field is filled but invalid
    form.addEventListener('submit', function (e) {
        if (!validateEmail()) {
            e.preventDefault();
            emailInput.focus();
        }
    });
}());

// Event listeners
document.getElementById('room_id').addEventListener('change', calculateTotals);
document.getElementById('check_in').addEventListener('change', function() {
    loadAvailableRooms();
    calculateTotals();
});
document.getElementById('check_out').addEventListener('change', function() {
    loadAvailableRooms();
    calculateTotals();
});
document.getElementById('other_fees').addEventListener('input', calculateTotals);

// Initial load
loadAvailableRooms();

// If editing and room is preselected, trigger data loading
if (tenantCurrentRoomId && document.getElementById('room_id').value) {
    calculateTotals();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
