<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
ensureUtilityBillMeterResetColumns();

$pageTitle = isset($_GET['id']) ? t('edit_meter_record') : t('record_utility_bills');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$preselectedTenant = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : 0;
$isApi = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function getPreviousUtilityBill(PDO $pdo, int $tenantId, string $billMonth)
{
    $stmt = $pdo->prepare("
        SELECT water_curr_reading, elec_curr_reading, bill_month
        FROM utility_bills
        WHERE tenant_id = ? AND bill_month < ?
        ORDER BY bill_month DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $billMonth]);
    return $stmt->fetch();
}

function isFirstRentalMonth(?string $contractStart, string $billMonth): bool
{
    if (empty($contractStart) || empty($billMonth)) {
        return false;
    }

    return date('Y-m', strtotime($contractStart)) === $billMonth;
}

$bill = null;
$previousBill = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM utility_bills WHERE id = ?");
    $stmt->execute([$id]);
    $bill = $stmt->fetch();

    if (!$bill) {
        setFlashMessage('error', t('not_found'));
        header('Location: index.php');
        exit;
    }

    $preselectedTenant = $bill['tenant_id'];
}

$settings = getSettings();

// Get selected month from GET or POST
$selectedMonth = $_GET['month'] ?? $_POST['bill_month'] ?? date('Y-m');

// If creating new but bill already exists for this tenant+month, treat as edit
if ($id == 0 && $preselectedTenant > 0 && $selectedMonth) {
    $stmt = $pdo->prepare("SELECT * FROM utility_bills WHERE tenant_id = ? AND bill_month = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$preselectedTenant, $selectedMonth]);
    $existingBill = $stmt->fetch();
    if ($existingBill) {
        $bill = $existingBill;
        $id = (int)$existingBill['id'];
        $preselectedTenant = $existingBill['tenant_id'];
    }
}

// Update page title if now editing
if ($id > 0) {
    $pageTitle = t('edit_meter_record');
}

// If creating new bill with preselected tenant, fetch previous meter readings
if (!$id && $preselectedTenant) {
    $previousBill = getPreviousUtilityBill($pdo, $preselectedTenant, $selectedMonth);
}

// Get tenants (active contracts without existing bill, plus preselected tenant if any)
$tenants = [];
if ($selectedMonth) {
    $stmt = $pdo->prepare("SELECT mt.*, r.room_number, rt.price_monthly 
        FROM monthly_tenants mt 
        JOIN rooms r ON mt.room_id = r.id 
        JOIN room_types rt ON r.room_type_id = rt.id 
        LEFT JOIN utility_bills ub ON mt.id = ub.tenant_id AND ub.bill_month = ?
        WHERE (mt.status = 'active' AND ub.id IS NULL)
        OR mt.id = ?
        ORDER BY r.room_number");
    $stmt->execute([$selectedMonth, $preselectedTenant]);
    $tenants = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $tenantId = intval($_POST['tenant_id'] ?? 0);
    $billMonth = $_POST['bill_month'] ?? date('Y-m');
    $billDate = $_POST['bill_date'] ?? date('Y-m-d');
    
    $waterPrev = intval($_POST['water_prev_reading'] ?? 0);
    $waterCurr = intval($_POST['water_curr_reading'] ?? 0);
    $waterRate = floatval($_POST['water_rate'] ?? $settings['water_rate']);
    
    $elecPrev = intval($_POST['elec_prev_reading'] ?? 0);
    $elecCurr = intval($_POST['elec_curr_reading'] ?? 0);
    $elecRate = floatval($_POST['elec_rate'] ?? $settings['electric_rate']);
    
    $waterMeterReset = !empty($_POST['water_meter_reset']);
    $elecMeterReset = !empty($_POST['elec_meter_reset']);

    $otherFees = floatval($_POST['other_fees'] ?? 0);
    $discount = floatval($_POST['discount'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');
    
    // Get tenant info for rent amount
    $stmt = $pdo->prepare("SELECT mt.monthly_rent, mt.room_id, mt.contract_start FROM monthly_tenants mt WHERE mt.id = ?");
    $stmt->execute([$tenantId]);
    $tenantData = $stmt->fetch();
    $rentAmount = $tenantData['monthly_rent'] ?? 0;
    $roomId = $tenantData['room_id'] ?? 0;
    $contractStart = $tenantData['contract_start'] ?? null;
    
    $createdBy = getValidSessionUserId();

    if (empty($tenantId)) {
        $error = t('please_select_tenant');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } elseif (!$tenantData) {
        $error = t('not_found');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } else {
            $isFirstMonth = isFirstRentalMonth($contractStart, $billMonth);
            $previousMeterBill = getPreviousUtilityBill($pdo, $tenantId, $billMonth);

            if ($waterMeterReset || $elecMeterReset) {
                $dbPin = $settings['pin'] ?? null;
                if (!empty($dbPin)) {
                    $pinResult = verifySystemPin(trim($_POST['confirm_pin'] ?? ''));
                    if (empty($pinResult['success'])) {
                        if (!empty($pinResult['logout'])) {
                            header('Location: ' . BASE_URL . 'pages/auth/login.php?reason=suspended');
                            exit;
                        }
                        $error = $pinResult['message'] ?? t('confirm_pin_incorrect');
                    }
                }
            }

            if (!isset($error) && !$isFirstMonth) {
                $needsPreviousBill = !$waterMeterReset || !$elecMeterReset;

                if ($needsPreviousBill && !$previousMeterBill) {
                    $error = t('previous_meter_record_required');
                } else {
                    if (!$waterMeterReset && $previousMeterBill) {
                        $waterPrev = (int) $previousMeterBill['water_curr_reading'];
                    }
                    if (!$elecMeterReset && $previousMeterBill) {
                        $elecPrev = (int) $previousMeterBill['elec_curr_reading'];
                    }
                }
            }

            $waterOldMeterFinal = null;
            $waterCarryoverUnits = 0;
            $elecOldMeterFinal = null;
            $elecCarryoverUnits = 0;

            if (!isset($error) && $waterMeterReset) {
                $billingWaterPrev = $isFirstMonth
                    ? intval($_POST['water_billing_prev_reading'] ?? $waterPrev)
                    : (int) ($previousMeterBill['water_curr_reading'] ?? 0);
                $waterOldMeterFinal = intval($_POST['water_old_meter_final'] ?? 0);

                if ($waterOldMeterFinal < $billingWaterPrev) {
                    $error = t('old_meter_final_less_than_previous');
                } else {
                    $waterCarryoverUnits = max(0, $waterOldMeterFinal - $billingWaterPrev);
                    $waterPrev = 0;
                }
            }

            if (!isset($error) && $elecMeterReset) {
                $billingElecPrev = $isFirstMonth
                    ? intval($_POST['elec_billing_prev_reading'] ?? $elecPrev)
                    : (int) ($previousMeterBill['elec_curr_reading'] ?? 0);
                $elecOldMeterFinal = intval($_POST['elec_old_meter_final'] ?? 0);

                if ($elecOldMeterFinal < $billingElecPrev) {
                    $error = t('old_meter_final_less_than_previous');
                } else {
                    $elecCarryoverUnits = max(0, $elecOldMeterFinal - $billingElecPrev);
                    $elecPrev = 0;
                }
            }

            if (!isset($error) && !$waterMeterReset && $waterCurr < $waterPrev) {
                $error = t('water_current_less_than_previous');
            }

            if (!isset($error) && !$elecMeterReset && $elecCurr < $elecPrev) {
                $error = t('elec_current_less_than_previous');
            }

            $waterUnits = $waterMeterReset
                ? $waterCarryoverUnits + max(0, $waterCurr - $waterPrev)
                : max(0, $waterCurr - $waterPrev);
            $elecUnits = $elecMeterReset
                ? $elecCarryoverUnits + max(0, $elecCurr - $elecPrev)
                : max(0, $elecCurr - $elecPrev);
            $waterAmount = $waterUnits * $waterRate;
            $elecAmount = $elecUnits * $elecRate;
            $totalAmount = $rentAmount + $waterAmount + $elecAmount + $otherFees - $discount;

            if (isset($error)) {
                if ($isApi) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => $error]);
                    exit;
                }
            } else {
            try {
                $pdo->beginTransaction();

                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE utility_bills SET
                        tenant_id = ?, room_id = ?, bill_month = ?, bill_date = ?, rent_amount = ?,
                        water_prev_reading = ?, water_curr_reading = ?, water_units = ?, water_rate = ?, water_amount = ?,
                        water_old_meter_final = ?, water_carryover_units = ?,
                        elec_prev_reading = ?, elec_curr_reading = ?, elec_units = ?, elec_rate = ?, elec_amount = ?,
                        elec_old_meter_final = ?, elec_carryover_units = ?,
                        other_fees = ?, discount = ?, total_amount = ?, notes = ?
                        WHERE id = ?");
                    $stmt->execute([$tenantId, $roomId, $billMonth, $billDate, $rentAmount,
                        $waterPrev, $waterCurr, $waterUnits, $waterRate, $waterAmount,
                        $waterOldMeterFinal, $waterCarryoverUnits,
                        $elecPrev, $elecCurr, $elecUnits, $elecRate, $elecAmount,
                        $elecOldMeterFinal, $elecCarryoverUnits,
                        $otherFees, $discount, $totalAmount, $notes, $id]);
                    $savedBillId = $id;
                    logActivity('update_utility_bill', 'utility_bill', $savedBillId);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO utility_bills
                        (tenant_id, room_id, bill_month, bill_date, rent_amount,
                        water_prev_reading, water_curr_reading, water_units, water_rate, water_amount,
                        water_old_meter_final, water_carryover_units,
                        elec_prev_reading, elec_curr_reading, elec_units, elec_rate, elec_amount,
                        elec_old_meter_final, elec_carryover_units,
                        other_fees, discount, total_amount, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$tenantId, $roomId, $billMonth, $billDate, $rentAmount,
                        $waterPrev, $waterCurr, $waterUnits, $waterRate, $waterAmount,
                        $waterOldMeterFinal, $waterCarryoverUnits,
                        $elecPrev, $elecCurr, $elecUnits, $elecRate, $elecAmount,
                        $elecOldMeterFinal, $elecCarryoverUnits,
                        $otherFees, $discount, $totalAmount, $notes, $createdBy]);
                    $savedBillId = (int) $pdo->lastInsertId();
                    logActivity('create_utility_bill', 'utility_bill', $savedBillId);
                }

                $invoice = syncMonthlyInvoiceFromUtilityBill($tenantId, $billMonth);
                logActivity('sync_monthly_invoice_from_utility_bill', 'invoice', $invoice['id']);
                $isNewBill = ($id <= 0);

                $pdo->commit();

                sendMonthlyReceiptEmail($tenantId, $billMonth, 'unpaid', (int) $invoice['id']);

                if ($isApi) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'bill_id' => $savedBillId,
                        'invoice_id' => $invoice['id'],
                        'invoice_number' => $invoice['invoice_number'],
                        'message' => t('save_success')
                    ]);
                    exit;
                }

                setFlashMessage('success', t('save_success'));
                // If tenant_id is set, user came from monthly-tenants, redirect to invoice page
                if ($preselectedTenant > 0) {
                    header('Location: ../monthly-tenants/invoice.php?id=' . $preselectedTenant . '&month=' . urlencode($billMonth));
                } else {
                    header('Location: index.php?month=' . $billMonth);
                }
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = t('save_bill_invoice_error');
                if ($isApi) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => $error]);
                    exit;
                }
            }
            }
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
                    
                    <form method="POST" action="" id="billForm">
                        <?php echo csrfInput(); ?>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label"><?php echo t('bill_month'); ?> *</label>
                                <input type="month" name="bill_month" id="bill_month" class="form-control" value="<?php echo $selectedMonth; ?>" required>
                                <small class="text-muted"><?php echo t('bill_month_help'); ?></small>
                            </div>
                        </div>
                        
                        <div id="tenantSection" style="display: <?php echo $selectedMonth ? 'block' : 'none'; ?>;">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('tenant'); ?> *</label>
                                <select name="tenant_id" id="tenant_id" class="form-select" required>
                                    <option value=""><?php echo t('select_tenant'); ?></option>
                                    <?php foreach ($tenants as $t): ?>
                                    <option value="<?php echo $t['id']; ?>" data-rent="<?php echo $t['monthly_rent']; ?>" data-contract-start="<?php echo htmlspecialchars($t['contract_start'] ?? ''); ?>" <?php echo (($bill['tenant_id'] ?? $preselectedTenant) == $t['id']) ? 'selected' : ''; ?>>
                                        <?php echo sprintf(t('tenant_room_rent_format'), $t['room_number'], $t['tenant_name'], formatCurrency($t['monthly_rent'])); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($tenants) && $selectedMonth): ?>
                                <small class="text-danger"><?php echo t('no_tenants_selected_month'); ?></small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('record_date'); ?></label>
                                <input type="date" name="bill_date" class="form-control" value="<?php echo $bill['bill_date'] ?? date('Y-m-d'); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo t('rent_amount'); ?> (<?php echo t('baht'); ?>)</label>
                                <input type="number" name="rent_amount" id="rent_amount" class="form-control" value="<?php echo $bill['rent_amount'] ?? ''; ?>" readonly>
                            </div>
                        </div>
                        
                        <div id="utilitySection" style="display: <?php echo ($bill['tenant_id'] ?? $preselectedTenant) ? 'block' : 'none'; ?>;">
                        
                        <hr class="my-4">
                        
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <h5 class="mb-0"><i class="bi bi-droplet me-2"></i><?php echo t('water'); ?></h5>
                            <button type="button" class="btn btn-sm btn-outline-warning" id="btnResetWaterMeter">
                                <i class="bi bi-arrow-counterclockwise me-1"></i><?php echo t('reset_water_meter'); ?>
                            </button>
                        </div>

                        <div id="water_carryover_info" class="alert alert-warning py-2 small mb-3 d-none"></div>
                        
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label"><?php echo t('meter_previous'); ?></label>
                                <input type="number" name="water_prev_reading" id="water_prev" class="form-control" value="<?php echo $bill['water_prev_reading'] ?? $previousBill['water_curr_reading'] ?? 0; ?>" min="0">
                                <small id="water_prev_help" class="text-muted d-none"><?php echo t('meter_previous_locked_help'); ?></small>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label"><?php echo t('meter_current'); ?></label>
                                <input type="number" name="water_curr_reading" id="water_curr" class="form-control" value="<?php echo $bill['water_curr_reading'] ?? 0; ?>" min="0">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label"><?php echo t('units_used_label'); ?></label>
                                <input type="text" id="water_units_display" class="form-control" readonly value="<?php echo ($bill['water_units'] ?? 0) . ' ' . t('units'); ?>">
                                <input type="hidden" name="water_units" id="water_units" value="<?php echo $bill['water_units'] ?? 0; ?>">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label"><?php echo t('unit_price_label'); ?></label>
                                <input type="number" name="water_rate" id="water_rate" class="form-control" value="<?php echo $bill['water_rate'] ?? $settings['water_rate']; ?>" step="0.01">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><?php echo t('water_total'); ?></label>
                            <input type="text" id="water_amount_display" class="form-control fw-bold" readonly value="<?php echo formatCurrency($bill['water_amount'] ?? 0); ?>">
                            <input type="hidden" name="water_amount" id="water_amount" value="<?php echo $bill['water_amount'] ?? 0; ?>">
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <h5 class="mb-0"><i class="bi bi-lightning me-2"></i><?php echo t('electric'); ?></h5>
                            <button type="button" class="btn btn-sm btn-outline-warning" id="btnResetElecMeter">
                                <i class="bi bi-arrow-counterclockwise me-1"></i><?php echo t('reset_elec_meter'); ?>
                            </button>
                        </div>

                        <div id="elec_carryover_info" class="alert alert-warning py-2 small mb-3 d-none"></div>
                        
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label"><?php echo t('meter_previous'); ?></label>
                                <input type="number" name="elec_prev_reading" id="elec_prev" class="form-control" value="<?php echo $bill['elec_prev_reading'] ?? $previousBill['elec_curr_reading'] ?? 0; ?>" min="0">
                                <small id="elec_prev_help" class="text-muted d-none"><?php echo t('meter_previous_locked_help'); ?></small>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label"><?php echo t('meter_current'); ?></label>
                                <input type="number" name="elec_curr_reading" id="elec_curr" class="form-control" value="<?php echo $bill['elec_curr_reading'] ?? 0; ?>" min="0">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label"><?php echo t('units_used_label'); ?></label>
                                <input type="text" id="elec_units_display" class="form-control" readonly value="<?php echo ($bill['elec_units'] ?? 0) . ' ' . t('units'); ?>">
                                <input type="hidden" name="elec_units" id="elec_units" value="<?php echo $bill['elec_units'] ?? 0; ?>">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label"><?php echo t('unit_price_label'); ?></label>
                                <input type="number" name="elec_rate" id="elec_rate" class="form-control" value="<?php echo $bill['elec_rate'] ?? $settings['electric_rate']; ?>" step="0.01">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><?php echo t('electric'); ?></label>
                            <input type="text" id="elec_amount_display" class="form-control fw-bold" readonly value="<?php echo formatCurrency($bill['elec_amount'] ?? 0); ?>">
                            <input type="hidden" name="elec_amount" id="elec_amount" value="<?php echo $bill['elec_amount'] ?? 0; ?>">
                        </div>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3"><i class="bi bi-calculator me-2"></i><?php echo t('summary'); ?></h5>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label"><?php echo t('other_fees'); ?></label>
                                <input type="number" name="other_fees" id="other_fees" class="form-control" value="<?php echo $bill['other_fees'] ?? 0; ?>" step="0.01">
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label"><?php echo t('discount'); ?></label>
                                <input type="number" name="discount" id="discount" class="form-control" value="<?php echo $bill['discount'] ?? 0; ?>" step="0.01">
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label"><?php echo t('grand_total'); ?></label>
                                <input type="text" id="total_amount_display" class="form-control form-control-lg fw-bold text-primary" readonly value="<?php echo formatCurrency($bill['total_amount'] ?? 0); ?>">
                                <input type="hidden" name="total_amount" id="total_amount" value="<?php echo $bill['total_amount'] ?? 0; ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><?php echo t('notes'); ?></label>
                            <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($bill['notes'] ?? ''); ?></textarea>
                        </div>
                        
                        </div>
                        </div>
                        
                        <div class="text-end mt-4">
                            <a href="index.php" class="btn btn-secondary me-2"><?php echo t('cancel'); ?></a>
                            <button type="button" class="btn btn-primary" id="btnPreviewSave" <?php echo empty($tenants) ? 'disabled' : ''; ?>><?php echo t('save'); ?></button>
                            <button type="submit" class="btn btn-primary d-none" id="btnConfirmSave"><?php echo t('confirm_save'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Old Meter Final Reading -->
<div class="modal fade" id="oldMeterModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <div class="modal-header theme-modal-header border-0 py-3 rounded-top-4">
                <h5 class="modal-title fw-bold" id="oldMeterModalTitle"><i class="bi bi-speedometer2 me-2"></i><?php echo t('old_meter_final_reading'); ?></h5>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-3"><?php echo t('old_meter_final_help'); ?></p>
                <div class="mb-3">
                    <label class="form-label"><?php echo t('old_meter_prev_month_label'); ?></label>
                    <input type="text" id="oldMeterPrevDisplay" class="form-control bg-light" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php echo t('old_meter_final_reading'); ?> *</label>
                    <input type="number" id="oldMeterFinalInput" class="form-control form-control-lg" min="0" step="1" autocomplete="off">
                    <div id="oldMeterErrorMsg" class="text-danger small mt-2 d-none"></div>
                </div>
                <div class="alert alert-info py-2 small mb-0 d-none" id="oldMeterPreview">
                    <span id="oldMeterPreviewText"></span>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                <button type="button" id="confirmOldMeterBtn" class="btn btn-warning px-4"><?php echo t('confirm_old_meter_reading'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Confirm PIN for Meter Reset -->
<div class="modal fade" id="pinConfirmModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <div class="modal-header theme-modal-header border-0 py-3 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock me-2"></i><?php echo t('confirm_security_pin'); ?></h5>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-3">
                    <div class="theme-icon-circle rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                        <i class="bi bi-shield-lock-fill fs-3"></i>
                    </div>
                    <p class="text-muted small mb-0"><?php echo t('confirm_pin_reset_meter_help'); ?></p>
                </div>
                <div class="mb-3">
                    <input type="password" id="confirmPinInput" class="form-control form-control-lg text-center fs-3" style="letter-spacing: 8px;" placeholder="••••••" maxlength="6" autocomplete="off" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <div id="pinErrorMsg" class="text-danger text-center mt-2 d-none"><?php echo t('pin_incorrect_try_again'); ?></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                <button type="button" id="submitPinBtn" class="btn theme-btn-primary px-4"><?php echo t('submit'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Confirm Save -->
<div class="modal fade" id="confirmSaveModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <div class="modal-header theme-modal-header border-0 py-3 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="bi bi-check-circle me-2"></i><?php echo t('confirm_save_meter'); ?></h5>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <strong><?php echo t('tenant'); ?>:</strong> <span id="confirmTenant"></span>
                </div>
                <div class="mb-3">
                    <strong><?php echo t('bill_month'); ?>:</strong> <span id="confirmMonth"></span>
                </div>
                <hr>
                <div class="mb-2">
                    <strong><?php echo t('rent_amount'); ?>:</strong> <span id="confirmRent"></span>
                </div>
                <div class="mb-2">
                    <strong><?php echo t('water'); ?>:</strong> <span id="confirmWater"></span> (<span id="confirmWaterUnits"></span> <?php echo t('units'); ?>)
                </div>
                <div class="mb-2">
                    <strong><?php echo t('electric'); ?>:</strong> <span id="confirmElec"></span> (<span id="confirmElecUnits"></span> <?php echo t('units'); ?>)
                </div>
                <div class="mb-2">
                    <strong><?php echo t('other_fees'); ?>:</strong> <span id="confirmOther"></span>
                </div>
                <div class="mb-2">
                    <strong><?php echo t('discount'); ?>:</strong> <span id="confirmDiscount"></span>
                </div>
                <hr>
                <div class="mb-0">
                    <strong class="text-primary fs-5"><?php echo t('grand_total'); ?>:</strong> <span id="confirmTotal" class="text-primary fs-5 fw-bold"></span>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal"><?php echo t('cancel'); ?></button>
                <button type="button" id="btnFinalConfirmSave" class="btn btn-primary px-4"><?php echo t('confirm_save'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
// Helper functions for translation
function t(key) {
    const translations = {
        'loading': '<?php echo t('loading'); ?>',
        'select_tenant': '<?php echo t('select_tenant'); ?>',
        'no_tenants_selected_month': '<?php echo t('no_tenants_selected_month'); ?>',
        'no_tenants_help_text': '<?php echo t('no_tenants_help_text'); ?>',
        'load_data_error': '<?php echo t('load_data_error'); ?>',
        'load_tenants_error_help': '<?php echo t('load_tenants_error_help'); ?>',
        'meter_previous_locked_help': '<?php echo t('meter_previous_locked_help'); ?>',
        'meter_reset_active_help': '<?php echo t('meter_reset_active_help'); ?>',
        'old_meter_final_less_than_previous': '<?php echo t('old_meter_final_less_than_previous'); ?>',
        'please_enter_old_meter_final': '<?php echo t('please_enter_old_meter_final'); ?>',
        'old_meter_carryover_units': '<?php echo t('old_meter_carryover_units'); ?>',
        'meter_reset_carryover_summary': '<?php echo t('meter_reset_carryover_summary'); ?>',
        'meter_units_breakdown': '<?php echo t('meter_units_breakdown'); ?>',
        'water_current_less_than_previous': '<?php echo t('water_current_less_than_previous'); ?>',
        'elec_current_less_than_previous': '<?php echo t('elec_current_less_than_previous'); ?>',
        'please_enter_pin': '<?php echo t('please_enter_pin'); ?>',
        'pin_must_be_6_digits': '<?php echo t('pin_must_be_6_digits'); ?>',
        'pin_incorrect_try_again': '<?php echo t('pin_incorrect_try_again'); ?>',
        'connection_error': '<?php echo t('connection_error'); ?>',
        'units': '<?php echo t('units'); ?>',
        'room': '<?php echo t('room'); ?>',
        'per_month': '<?php echo t('per_month'); ?>'
    };
    return translations[key] || key;
}

function loadTenantsForMonth(month) {
    if (!month) {
        document.getElementById('tenantSection').style.display = 'none';
        document.getElementById('utilitySection').style.display = 'none';
        return;
    }
    
    // Show loading
    const tenantSelect = document.getElementById('tenant_id');
    tenantSelect.innerHTML = `<option value="">${t('loading')}</option>`;
    document.getElementById('tenantSection').style.display = 'block';
    
    // Clear any existing messages
    const existingHelp = tenantSelect.parentNode.querySelector('.text-muted, .text-danger');
    if (existingHelp) {
        existingHelp.remove();
    }
    
    // Fetch tenants for selected month
    const apiUrl = '<?php echo BASE_URL; ?>api/get-tenants-by-month.php?month=' + encodeURIComponent(month);
    console.log('Fetching from:', apiUrl);
    
    fetch(apiUrl)
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response ok:', response.ok);
            
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Received data:', data);
            
            tenantSelect.innerHTML = `<option value="">${t('select_tenant')}</option>`;
            
            if (data.success && data.tenants && data.tenants.length > 0) {
                data.tenants.forEach(tenant => {
                    const option = document.createElement('option');
                    option.value = tenant.id;
                    option.dataset.rent = tenant.monthly_rent;
                    option.dataset.contractStart = tenant.contract_start || '';
                    option.textContent = `${t('room')} ${tenant.room_number} - ${tenant.tenant_name} (${tenant.monthly_rent.toLocaleString('th-TH', {minimumFractionDigits: 2})}/${t('per_month')})`;
                    tenantSelect.appendChild(option);
                });
                document.querySelector('button[type="submit"]').disabled = false;
            } else {
                // No tenants found - this is not an error
                tenantSelect.innerHTML += `<option value="" disabled>${t('no_tenants_selected_month')}</option>`;
                document.querySelector('button[type="submit"]').disabled = true;
                
                // Show helpful message
                const helpText = document.createElement('small');
                helpText.className = 'text-muted d-block mt-1';
                helpText.textContent = t('no_tenants_help_text');
                tenantSelect.parentNode.appendChild(helpText);
            }
        })
        .catch(error => {
            console.error('Error loading tenants:', error);
            console.error('Error details:', error.message);
            
            tenantSelect.innerHTML = `<option value="">${t('load_data_error')}</option>`;
            document.querySelector('button[type="submit"]').disabled = true;
            
            // Show error message
            const errorText = document.createElement('small');
            errorText.className = 'text-danger d-block mt-1';
            errorText.textContent = t('load_tenants_error_help');
            tenantSelect.parentNode.appendChild(errorText);
        });
}

function isFirstRentalMonth(contractStart, billMonth) {
    if (!contractStart || !billMonth) {
        return false;
    }

    return contractStart.substring(0, 7) === billMonth;
}

let waterMeterReset = false;
let elecMeterReset = false;
let pendingMeterReset = null;
let pendingOldMeterData = null;
let waterCarryoverUnits = 0;
let waterOldMeterFinal = null;
let elecCarryoverUnits = 0;
let elecOldMeterFinal = null;
const hasPin = <?php echo !empty($settings['pin']) ? 'true' : 'false'; ?>;

function setFormHiddenInput(name, value) {
    const form = document.getElementById('billForm');
    let input = form.querySelector(`input[name="${name}"]`);
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        form.appendChild(input);
    }
    input.value = value;
}

function removeFormHiddenInput(name) {
    const input = document.getElementById('billForm').querySelector(`input[name="${name}"]`);
    if (input) {
        input.remove();
    }
}

function clearMeterResetState() {
    waterMeterReset = false;
    elecMeterReset = false;
    waterCarryoverUnits = 0;
    waterOldMeterFinal = null;
    elecCarryoverUnits = 0;
    elecOldMeterFinal = null;
    pendingOldMeterData = null;
    removeFormHiddenInput('water_meter_reset');
    removeFormHiddenInput('elec_meter_reset');
    removeFormHiddenInput('water_old_meter_final');
    removeFormHiddenInput('water_carryover_units');
    removeFormHiddenInput('water_billing_prev_reading');
    removeFormHiddenInput('elec_old_meter_final');
    removeFormHiddenInput('elec_carryover_units');
    removeFormHiddenInput('elec_billing_prev_reading');
    removeFormHiddenInput('confirm_pin');
    document.getElementById('btnResetWaterMeter').classList.remove('active');
    document.getElementById('btnResetElecMeter').classList.remove('active');
    document.getElementById('water_carryover_info').classList.add('d-none');
    document.getElementById('elec_carryover_info').classList.add('d-none');
}

function updateCarryoverInfo(type) {
    const infoId = type === 'water' ? 'water_carryover_info' : 'elec_carryover_info';
    const infoEl = document.getElementById(infoId);
    const isReset = type === 'water' ? waterMeterReset : elecMeterReset;
    const oldFinal = type === 'water' ? waterOldMeterFinal : elecOldMeterFinal;
    const carryover = type === 'water' ? waterCarryoverUnits : elecCarryoverUnits;

    if (!isReset || oldFinal === null) {
        infoEl.classList.add('d-none');
        return;
    }

    infoEl.textContent = t('meter_reset_carryover_summary')
        .replace('%s', oldFinal)
        .replace('%s', carryover);
    infoEl.classList.remove('d-none');
}

function formatUnitsBreakdown(carryover, newUnits, total) {
    return t('meter_units_breakdown')
        .replace('%s', carryover)
        .replace('%s', newUnits)
        .replace('%s', total);
}

function setPrevInputState(inputId, allowEdit) {
    const input = document.getElementById(inputId);
    if (allowEdit) {
        input.type = 'number';
        input.readOnly = false;
        input.tabIndex = 0;
        input.style.pointerEvents = 'auto';
        input.classList.remove('bg-light');
        input.setAttribute('min', '0');
    } else {
        input.type = 'text';
        input.readOnly = true;
        input.tabIndex = -1;
        input.style.pointerEvents = 'none';
        input.classList.add('bg-light');
    }
}

function applyMeterReset(type, oldMeterData) {
    const data = oldMeterData || pendingOldMeterData;
    if (!data || data.type !== type) {
        return;
    }

    if (type === 'water') {
        waterMeterReset = true;
        waterOldMeterFinal = data.oldFinal;
        waterCarryoverUnits = data.carryover;
        setFormHiddenInput('water_billing_prev_reading', data.billingPrev);
        setFormHiddenInput('water_old_meter_final', data.oldFinal);
        setFormHiddenInput('water_carryover_units', data.carryover);
        setFormHiddenInput('water_meter_reset', '1');
        document.getElementById('water_prev').value = 0;
        document.getElementById('water_curr').value = 0;
        document.getElementById('btnResetWaterMeter').classList.add('active');
        updateCarryoverInfo('water');
    } else {
        elecMeterReset = true;
        elecOldMeterFinal = data.oldFinal;
        elecCarryoverUnits = data.carryover;
        setFormHiddenInput('elec_billing_prev_reading', data.billingPrev);
        setFormHiddenInput('elec_old_meter_final', data.oldFinal);
        setFormHiddenInput('elec_carryover_units', data.carryover);
        setFormHiddenInput('elec_meter_reset', '1');
        document.getElementById('elec_prev').value = 0;
        document.getElementById('elec_curr').value = 0;
        document.getElementById('btnResetElecMeter').classList.add('active');
        updateCarryoverInfo('elec');
    }

    pendingOldMeterData = null;
    updatePreviousMeterState();
    calculateUtility();
}

function showOldMeterModal(type) {
    const prevId = type === 'water' ? 'water_prev' : 'elec_prev';
    const billingPrev = parseInt(document.getElementById(prevId).value, 10) || 0;
    const oldMeterFinalInput = document.getElementById('oldMeterFinalInput');
    const oldMeterErrorMsg = document.getElementById('oldMeterErrorMsg');
    const oldMeterPreview = document.getElementById('oldMeterPreview');
    const oldMeterPreviewText = document.getElementById('oldMeterPreviewText');

    document.getElementById('oldMeterPrevDisplay').value = billingPrev;
    oldMeterFinalInput.value = '';
    oldMeterFinalInput.min = billingPrev;
    oldMeterErrorMsg.classList.add('d-none');
    oldMeterPreview.classList.add('d-none');

    oldMeterFinalInput.oninput = function() {
        const finalVal = parseInt(this.value, 10);
        if (Number.isNaN(finalVal) || finalVal < billingPrev) {
            oldMeterPreview.classList.add('d-none');
            return;
        }
        const carryover = finalVal - billingPrev;
        oldMeterPreviewText.textContent = t('old_meter_carryover_units') + ': ' + carryover + ' ' + t('units');
        oldMeterPreview.classList.remove('d-none');
    };

    bootstrap.Modal.getOrCreateInstance(document.getElementById('oldMeterModal')).show();
    setTimeout(() => oldMeterFinalInput.focus(), 500);
}

function proceedAfterOldMeterConfirmed() {
    const type = pendingMeterReset;
    if (!type || !pendingOldMeterData) {
        return;
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('oldMeterModal')).hide();

    if (hasPin) {
        const confirmPinInput = document.getElementById('confirmPinInput');
        const pinErrorMsg = document.getElementById('pinErrorMsg');
        confirmPinInput.value = '';
        pinErrorMsg.classList.add('d-none');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('pinConfirmModal')).show();
        setTimeout(() => confirmPinInput.focus(), 500);
    } else {
        applyMeterReset(type);
        pendingMeterReset = null;
    }
}

function updatePreviousMeterState() {
    const tenantSelect = document.getElementById('tenant_id');
    const selected = tenantSelect.options[tenantSelect.selectedIndex];
    const month = document.getElementById('bill_month').value;
    const contractStart = selected ? selected.dataset.contractStart || '' : '';
    const allowEdit = isFirstRentalMonth(contractStart, month);

    const waterAllowEdit = allowEdit || waterMeterReset;
    const elecAllowEdit = allowEdit || elecMeterReset;

    setPrevInputState('water_prev', waterAllowEdit);
    setPrevInputState('elec_prev', elecAllowEdit);

    const waterHelp = document.getElementById('water_prev_help');
    waterHelp.textContent = waterMeterReset ? t('meter_reset_active_help') : t('meter_previous_locked_help');
    waterHelp.classList.toggle('d-none', waterAllowEdit && !waterMeterReset);
    waterHelp.classList.toggle('text-warning', waterMeterReset);
    waterHelp.classList.toggle('text-muted', !waterMeterReset);

    const elecHelp = document.getElementById('elec_prev_help');
    elecHelp.textContent = elecMeterReset ? t('meter_reset_active_help') : t('meter_previous_locked_help');
    elecHelp.classList.toggle('d-none', elecAllowEdit && !elecMeterReset);
    elecHelp.classList.toggle('text-warning', elecMeterReset);
    elecHelp.classList.toggle('text-muted', !elecMeterReset);
}

function requestMeterReset(type) {
    if (!document.getElementById('tenant_id').value) {
        return;
    }

    pendingMeterReset = type;
    pendingOldMeterData = null;
    showOldMeterModal(type);
}

function validateMeterPair(currentId, previousId, errorKey) {
    const currentInput = document.getElementById(currentId);
    const previousInput = document.getElementById(previousId);
    const currentValue = parseInt(currentInput.value, 10);
    const previousValue = parseInt(previousInput.value, 10) || 0;

    if (!Number.isNaN(currentValue) && currentValue < previousValue) {
        currentInput.setCustomValidity(t(errorKey));
    } else {
        currentInput.setCustomValidity('');
    }
}

document.getElementById('bill_month').addEventListener('change', function() {
    const selectedMonth = this.value;
    loadTenantsForMonth(selectedMonth);
    
    // Reset tenant selection and utility section
    document.getElementById('tenant_id').value = '';
    document.getElementById('rent_amount').value = '';
    document.getElementById('utilitySection').style.display = 'none';
    document.getElementById('water_prev').value = 0;
    document.getElementById('water_curr').value = 0;
    document.getElementById('elec_prev').value = 0;
    document.getElementById('elec_curr').value = 0;
    clearMeterResetState();
    updatePreviousMeterState();
    calculateUtility();
});

document.getElementById('tenant_id').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const tenantId = selected ? selected.value : '';
    const rent = selected ? (parseFloat(selected.dataset.rent) || 0) : 0;
    document.getElementById('rent_amount').value = rent;
    document.getElementById('utilitySection').style.display = tenantId ? 'block' : 'none';
    clearMeterResetState();
    updatePreviousMeterState();
    
    if (tenantId) {
        const month = document.getElementById('bill_month').value;
        fetch(`<?php echo BASE_URL; ?>api/get-last-meter.php?tenant_id=${tenantId}&month=${month}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const allowEdit = isFirstRentalMonth(selected.dataset.contractStart || '', month);

                    if (!waterMeterReset) {
                        if (data.last_readings && !allowEdit) {
                            document.getElementById('water_prev').value = data.last_readings.water_curr_reading || 0;
                        } else if (!data.last_readings && !allowEdit) {
                            document.getElementById('water_prev').value = 0;
                        }
                    }

                    if (!elecMeterReset) {
                        if (data.last_readings && !allowEdit) {
                            document.getElementById('elec_prev').value = data.last_readings.elec_curr_reading || 0;
                        } else if (!data.last_readings && !allowEdit) {
                            document.getElementById('elec_prev').value = 0;
                        }
                    }

                    updatePreviousMeterState();
                    calculateUtility();
                }
            })
            .catch(error => console.error('Error fetching meter readings:', error));
    } else {
        document.getElementById('water_prev').value = 0;
        document.getElementById('elec_prev').value = 0;
    }
    
    calculateUtility();
});

function calculateUtility() {
    console.log('Calculating utility...');
    
    const waterPrev = parseInt(document.getElementById('water_prev').value) || 0;
    const waterCurr = parseInt(document.getElementById('water_curr').value) || 0;
    const waterRate = parseFloat(document.getElementById('water_rate').value) || 0;
    const waterNewUnits = Math.max(0, waterCurr - waterPrev);
    const waterUnits = waterMeterReset ? waterCarryoverUnits + waterNewUnits : waterNewUnits;
    const waterAmount = waterUnits * waterRate;
    
    console.log('Water:', waterPrev, waterCurr, waterRate, waterUnits, waterAmount);
    if (!waterMeterReset) {
        validateMeterPair('water_curr', 'water_prev', 'water_current_less_than_previous');
    } else {
        document.getElementById('water_curr').setCustomValidity(waterCurr < 0 ? t('water_current_less_than_previous') : '');
    }
    
    document.getElementById('water_units').value = waterUnits;
    document.getElementById('water_units_display').value = waterMeterReset
        ? formatUnitsBreakdown(waterCarryoverUnits, waterNewUnits, waterUnits)
        : waterUnits + ' ' + t('units');
    document.getElementById('water_amount').value = waterAmount;
    document.getElementById('water_amount_display').value = waterAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
    
    const elecPrev = parseInt(document.getElementById('elec_prev').value) || 0;
    const elecCurr = parseInt(document.getElementById('elec_curr').value) || 0;
    const elecRate = parseFloat(document.getElementById('elec_rate').value) || 0;
    const elecNewUnits = Math.max(0, elecCurr - elecPrev);
    const elecUnits = elecMeterReset ? elecCarryoverUnits + elecNewUnits : elecNewUnits;
    const elecAmount = elecUnits * elecRate;
    
    console.log('Electric:', elecPrev, elecCurr, elecRate, elecUnits, elecAmount);
    if (!elecMeterReset) {
        validateMeterPair('elec_curr', 'elec_prev', 'elec_current_less_than_previous');
    } else {
        document.getElementById('elec_curr').setCustomValidity(elecCurr < 0 ? t('elec_current_less_than_previous') : '');
    }
    
    document.getElementById('elec_units').value = elecUnits;
    document.getElementById('elec_units_display').value = elecMeterReset
        ? formatUnitsBreakdown(elecCarryoverUnits, elecNewUnits, elecUnits)
        : elecUnits + ' ' + t('units');
    document.getElementById('elec_amount').value = elecAmount;
    document.getElementById('elec_amount_display').value = elecAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
    
    const rent = parseFloat(document.getElementById('rent_amount').value) || 0;
    const other = parseFloat(document.getElementById('other_fees').value) || 0;
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    
    const total = rent + waterAmount + elecAmount + other - discount;
    
    console.log('Total:', rent, waterAmount, elecAmount, other, discount, total);
    
    document.getElementById('total_amount').value = total;
    document.getElementById('total_amount_display').value = total.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
}

document.getElementById('water_prev').addEventListener('input', calculateUtility);
document.getElementById('water_prev').addEventListener('keyup', calculateUtility);
document.getElementById('water_curr').addEventListener('input', calculateUtility);
document.getElementById('water_curr').addEventListener('keyup', calculateUtility);
document.getElementById('water_rate').addEventListener('input', calculateUtility);
document.getElementById('water_rate').addEventListener('keyup', calculateUtility);
document.getElementById('elec_prev').addEventListener('input', calculateUtility);
document.getElementById('elec_prev').addEventListener('keyup', calculateUtility);
document.getElementById('elec_curr').addEventListener('input', calculateUtility);
document.getElementById('elec_curr').addEventListener('keyup', calculateUtility);
document.getElementById('elec_rate').addEventListener('input', calculateUtility);
document.getElementById('elec_rate').addEventListener('keyup', calculateUtility);
document.getElementById('other_fees').addEventListener('input', calculateUtility);
document.getElementById('other_fees').addEventListener('keyup', calculateUtility);
document.getElementById('discount').addEventListener('input', calculateUtility);
document.getElementById('discount').addEventListener('keyup', calculateUtility);
document.getElementById('btnResetWaterMeter').addEventListener('click', function() {
    requestMeterReset('water');
});
document.getElementById('btnResetElecMeter').addEventListener('click', function() {
    requestMeterReset('elec');
});

(function initOldMeterModal() {
    const confirmOldMeterBtn = document.getElementById('confirmOldMeterBtn');
    const oldMeterFinalInput = document.getElementById('oldMeterFinalInput');
    const oldMeterErrorMsg = document.getElementById('oldMeterErrorMsg');

    if (!confirmOldMeterBtn || !oldMeterFinalInput) {
        return;
    }

    function confirmOldMeterReading() {
        const billingPrev = parseInt(document.getElementById('oldMeterPrevDisplay').value, 10) || 0;
        const oldFinal = parseInt(oldMeterFinalInput.value, 10);

        if (Number.isNaN(oldFinal)) {
            oldMeterErrorMsg.textContent = t('please_enter_old_meter_final');
            oldMeterErrorMsg.classList.remove('d-none');
            return;
        }
        if (oldFinal < billingPrev) {
            oldMeterErrorMsg.textContent = t('old_meter_final_less_than_previous');
            oldMeterErrorMsg.classList.remove('d-none');
            return;
        }

        oldMeterErrorMsg.classList.add('d-none');
        pendingOldMeterData = {
            type: pendingMeterReset,
            billingPrev: billingPrev,
            oldFinal: oldFinal,
            carryover: oldFinal - billingPrev
        };
        proceedAfterOldMeterConfirmed();
    }

    confirmOldMeterBtn.addEventListener('click', confirmOldMeterReading);
    oldMeterFinalInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmOldMeterReading();
        }
    });
})();

(function initPinModal() {
    const pinConfirmModal = document.getElementById('pinConfirmModal');
    const confirmPinInput = document.getElementById('confirmPinInput');
    const submitPinBtn = document.getElementById('submitPinBtn');
    const pinErrorMsg = document.getElementById('pinErrorMsg');

    if (!pinConfirmModal || !confirmPinInput || !submitPinBtn || !pinErrorMsg) {
        return;
    }

    function verifyPinAndReset() {
        const pinVal = confirmPinInput.value.trim();
        if (!pinVal) {
            pinErrorMsg.textContent = t('please_enter_pin');
            pinErrorMsg.classList.remove('d-none');
            return;
        }
        if (pinVal.length !== 6 || !/^\d+$/.test(pinVal)) {
            pinErrorMsg.textContent = t('pin_must_be_6_digits');
            pinErrorMsg.classList.remove('d-none');
            return;
        }

        submitPinBtn.disabled = true;
        pinErrorMsg.classList.add('d-none');

        const formData = new FormData();
        formData.append('pin', pinVal);
        formData.append('_csrf_token', '<?php echo csrfToken(); ?>');

        fetch('<?php echo BASE_URL; ?>api/verify-pin.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            submitPinBtn.disabled = false;
            if (data.logout) {
                window.location.href = '<?php echo BASE_URL; ?>pages/auth/login.php?reason=suspended';
                return;
            }
            if (data.success) {
                setFormHiddenInput('confirm_pin', pinVal);
                if (pendingMeterReset && pendingOldMeterData) {
                    applyMeterReset(pendingMeterReset);
                    pendingMeterReset = null;
                }
                bootstrap.Modal.getOrCreateInstance(pinConfirmModal).hide();
            } else {
                pinErrorMsg.textContent = data.message || t('pin_incorrect_try_again');
                pinErrorMsg.classList.remove('d-none');
                confirmPinInput.value = '';
                confirmPinInput.focus();
            }
        })
        .catch(() => {
            submitPinBtn.disabled = false;
            pinErrorMsg.textContent = t('connection_error');
            pinErrorMsg.classList.remove('d-none');
        });
    }

    submitPinBtn.addEventListener('click', verifyPinAndReset);
    confirmPinInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            verifyPinAndReset();
        }
    });
})();

updatePreviousMeterState();

// Confirm Save Modal Handler
document.getElementById('btnPreviewSave').addEventListener('click', function() {
    const tenantSelect = document.getElementById('tenant_id');
    const selectedOption = tenantSelect.options[tenantSelect.selectedIndex];
    
    document.getElementById('confirmTenant').textContent = selectedOption ? selectedOption.textContent : '-';
    document.getElementById('confirmMonth').textContent = document.getElementById('bill_month').value;
    document.getElementById('confirmRent').textContent = parseFloat(document.getElementById('rent_amount').value).toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
    document.getElementById('confirmWater').textContent = parseFloat(document.getElementById('water_amount').value).toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
    document.getElementById('confirmWaterUnits').textContent = document.getElementById('water_units').value;
    document.getElementById('confirmElec').textContent = parseFloat(document.getElementById('elec_amount').value).toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
    document.getElementById('confirmElecUnits').textContent = document.getElementById('elec_units').value;
    document.getElementById('confirmOther').textContent = parseFloat(document.getElementById('other_fees').value).toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
    document.getElementById('confirmDiscount').textContent = parseFloat(document.getElementById('discount').value).toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
    document.getElementById('confirmTotal').textContent = parseFloat(document.getElementById('total_amount').value).toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' ฿';
    
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmSaveModal')).show();
});

document.getElementById('btnFinalConfirmSave').addEventListener('click', function() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmSaveModal')).hide();
    document.getElementById('btnConfirmSave').click();
});

<?php
$waterResetOnBill = $bill && isset($bill['water_old_meter_final']) && $bill['water_old_meter_final'] !== null;
$elecResetOnBill = $bill && isset($bill['elec_old_meter_final']) && $bill['elec_old_meter_final'] !== null;
if ($waterResetOnBill): ?>
waterMeterReset = true;
waterOldMeterFinal = <?php echo (int) $bill['water_old_meter_final']; ?>;
waterCarryoverUnits = <?php echo (int) ($bill['water_carryover_units'] ?? 0); ?>;
setFormHiddenInput('water_meter_reset', '1');
setFormHiddenInput('water_old_meter_final', waterOldMeterFinal);
setFormHiddenInput('water_carryover_units', waterCarryoverUnits);
document.getElementById('btnResetWaterMeter').classList.add('active');
updateCarryoverInfo('water');
<?php endif; ?>
<?php if ($elecResetOnBill): ?>
elecMeterReset = true;
elecOldMeterFinal = <?php echo (int) $bill['elec_old_meter_final']; ?>;
elecCarryoverUnits = <?php echo (int) ($bill['elec_carryover_units'] ?? 0); ?>;
setFormHiddenInput('elec_meter_reset', '1');
setFormHiddenInput('elec_old_meter_final', elecOldMeterFinal);
setFormHiddenInput('elec_carryover_units', elecCarryoverUnits);
document.getElementById('btnResetElecMeter').classList.add('active');
updateCarryoverInfo('elec');
<?php endif; ?>
<?php if ($waterResetOnBill || $elecResetOnBill): ?>
updatePreviousMeterState();
calculateUtility();
<?php endif; ?>

// Initial calculation and tenant data loading if tenant is preselected
<?php if ($bill['tenant_id'] ?? $preselectedTenant): ?>
document.addEventListener('DOMContentLoaded', function() {
    const tenantSelect = document.getElementById('tenant_id');
    if (tenantSelect && tenantSelect.value) {
        // Trigger change event to load rent and meter readings
        const event = new Event('change');
        tenantSelect.dispatchEvent(event);
    }
});
calculateUtility();
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
