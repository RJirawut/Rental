<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$lang = $_SESSION['lang'] ?? 'th';
ensureDailyTenantOtherFeesColumn();

$tenantId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($tenantId === 0) {
    setFlashMessage('error', 'ไม่พบข้อมูล');
    header('Location: index.php');
    exit;
}

// Get tenant details
$stmt = $pdo->prepare("SELECT dt.*, r.room_number, rt.type_name, rt.type_name_en FROM daily_tenants dt JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id WHERE dt.id = ?");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    setFlashMessage('error', 'ไม่พบข้อมูล');
    header('Location: index.php');
    exit;
}

$settings = getSettings();

// Handle save invoice request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_invoice') {
    requireValidCsrfToken();

    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, invoice_type, tenant_type, tenant_id, room_id, invoice_date, due_date, subtotal, vat_rate, vat_amount, grand_total, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['invoice_number'],
        $_POST['invoice_type'],
        $_POST['tenant_type'],
        $_POST['tenant_id'],
        $tenant['room_id'],
        $_POST['invoice_date'],
        $_POST['due_date'],
        $_POST['subtotal'],
        $_POST['vat_rate'],
        $_POST['vat_amount'],
        $_POST['grand_total'],
        $_POST['status'],
        $_POST['created_by']
    ]);

    setFlashMessage('success', t('invoice_saved_success'));
    header('Location: index.php');
    exit;
}

// Check if Tax ID is configured
$hasTaxId = !empty($settings['tax_id']);
$documentType = $hasTaxId ? t('invoice_doc_tax') : t('invoice_doc_receipt');

// Room type name based on language
$typeName = ($lang === 'en' && !empty($tenant['type_name_en'])) ? $tenant['type_name_en'] : $tenant['type_name'];

// Generate invoice number
$invoiceNumber = $hasTaxId ? generateInvoiceNumber('daily') : 'REC-' . date('Ym') . '-' . str_pad($tenantId, 4, '0', STR_PAD_LEFT);

// Calculate overdue and extra charges
$earlyCheckinDays = 0;
$earlyCheckinCharge = 0;
$lateCheckoutDays = 0;
$lateCheckoutCharge = 0;
$otherFees = (float) ($tenant['other_fees'] ?? 0);
$roomAmount = (float) $tenant['daily_rate'] * (int) $tenant['total_days'];
$baseAmount = $roomAmount + $otherFees;
$baseDays = $tenant['total_days'];
$roomDescription = t('invoice_room_charge') . ' ' . $typeName . ' ' . t('invoice_room_label') . ' ' . $tenant['room_number'] . ' (' . $baseDays . ' ' . t('invoice_days_unit') . ')';

// Early check-in: actual_check_in_date < check_in_date
if (!empty($tenant['actual_check_in_date']) && $tenant['actual_check_in_date'] < $tenant['check_in_date']) {
    $actualIn = new DateTime($tenant['actual_check_in_date']);
    $scheduledIn = new DateTime($tenant['check_in_date']);
    $earlyCheckinDays = $actualIn->diff($scheduledIn)->days;
    $earlyCheckinCharge = $earlyCheckinDays * (float) $tenant['daily_rate'];
}

// Late checkout: actual_check_out_date > check_out_date
if (!empty($tenant['actual_check_out_date']) && $tenant['actual_check_out_date'] > $tenant['check_out_date']) {
    $scheduledOut = new DateTime($tenant['check_out_date']);
    $actualOut = new DateTime($tenant['actual_check_out_date']);
    $lateCheckoutDays = $scheduledOut->diff($actualOut)->days;
    $lateCheckoutCharge = $lateCheckoutDays * (float) $tenant['daily_rate'];
} elseif (empty($tenant['actual_check_out_date']) && $tenant['status'] === 'checked_in' && date('Y-m-d') > $tenant['check_out_date']) {
    $scheduledOut = new DateTime($tenant['check_out_date']);
    $today = new DateTime();
    $lateCheckoutDays = $scheduledOut->diff($today)->days;
    $lateCheckoutCharge = $lateCheckoutDays * (float) $tenant['daily_rate'];
}

$displayTotal = $baseAmount + $earlyCheckinCharge + $lateCheckoutCharge;

// Calculate totals
$subtotal = $displayTotal;
$vatRate = $hasTaxId ? 7 : 0;
$vatAmount = $hasTaxId ? $subtotal * ($vatRate / 100) : 0;
$grandTotal = $subtotal + $vatAmount;
$calculatedSubtotal = $subtotal;
$calculatedVatAmount = $vatAmount;
$calculatedGrandTotal = $grandTotal;

// Check if invoice already exists
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE tenant_type = 'daily' AND tenant_id = ? AND invoice_type = 'daily'");
$stmt->execute([$tenantId]);
$existingInvoice = $stmt->fetch();

if ($existingInvoice) {
    $invoiceNumber = $existingInvoice['invoice_number'];
    $needsInvoiceSync =
        abs((float) $existingInvoice['subtotal'] - $calculatedSubtotal) > 0.0001 ||
        abs((float) $existingInvoice['vat_rate'] - $vatRate) > 0.0001 ||
        abs((float) $existingInvoice['vat_amount'] - $calculatedVatAmount) > 0.0001 ||
        abs((float) $existingInvoice['grand_total'] - $calculatedGrandTotal) > 0.0001;

    if ($needsInvoiceSync) {
        $stmt = $pdo->prepare("UPDATE invoices SET subtotal = ?, vat_rate = ?, vat_amount = ?, grand_total = ?, customer_tax_id = ?, customer_branch_code = ?, seller_branch_code = ? WHERE id = ?");
        $stmt->execute([
            $calculatedSubtotal,
            $vatRate,
            $calculatedVatAmount,
            $calculatedGrandTotal,
            $tenant['customer_tax_id'] ?? null,
            $tenant['customer_branch'] ?? '00000',
            $settings['branch_number'] ?? '00000',
            $existingInvoice['id']
        ]);

        // Replace all invoice items
        $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
        $stmt->execute([$existingInvoice['id']]);

        $stmtItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
        $stmtItem->execute([$existingInvoice['id'], $roomDescription, $baseDays, $tenant['daily_rate'], $roomAmount]);

        if ($otherFees > 0) {
            $stmtItem->execute([$existingInvoice['id'], t('other_fees'), 1, $otherFees, $otherFees]);
        }

        if ($earlyCheckinDays > 0) {
            $stmtItem->execute([$existingInvoice['id'], t('invoice_early_checkin_charge'), $earlyCheckinDays, $tenant['daily_rate'], $earlyCheckinCharge]);
        }
        if ($lateCheckoutDays > 0) {
            $stmtItem->execute([$existingInvoice['id'], t('invoice_late_checkout_charge'), $lateCheckoutDays, $tenant['daily_rate'], $lateCheckoutCharge]);
        }
    }

    $subtotal = $calculatedSubtotal;
    $vatAmount = $calculatedVatAmount;
    $grandTotal = $calculatedGrandTotal;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    // Determine document type based on customer tax ID
    $documentType = 'full_tax';
    if (!$hasTaxId) {
        $documentType = 'receipt';
    } elseif (empty($tenant['customer_tax_id'])) {
        $documentType = 'abbreviated_tax';
    }
    
    // Create new invoice with Thai tax compliance fields
    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, document_type, invoice_type, tenant_type, tenant_id, room_id, invoice_date, due_date, subtotal, vat_rate, vat_amount, discount, grand_total, customer_tax_id, customer_branch_code, seller_branch_code, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $invoiceNumber,
        $documentType,
        'daily',
        'daily',
        $tenantId,
        $tenant['room_id'],
        date('Y-m-d'),
        null,
        $subtotal,
        $vatRate,
        $vatAmount,
        0, // no discount for daily tenants
        $grandTotal,
        $tenant['customer_tax_id'] ?? null,
        $tenant['customer_branch'] ?? '00000',
        $settings['branch_number'] ?? '00000',
        'issued',
        $_SESSION['user_id']
    ]);
    $invoiceId = $pdo->lastInsertId();

    $stmtItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
    $stmtItem->execute([$invoiceId, $roomDescription, $baseDays, $tenant['daily_rate'], $roomAmount]);

    if ($otherFees > 0) {
        $stmtItem->execute([$invoiceId, t('other_fees'), 1, $otherFees, $otherFees]);
    }

    if ($earlyCheckinDays > 0) {
        $stmtItem->execute([$invoiceId, t('invoice_early_checkin_charge'), $earlyCheckinDays, $tenant['daily_rate'], $earlyCheckinCharge]);
    }
    if ($lateCheckoutDays > 0) {
        $stmtItem->execute([$invoiceId, t('invoice_late_checkout_charge'), $lateCheckoutDays, $tenant['daily_rate'], $lateCheckoutCharge]);
    }
    
    logActivity('generate_invoice', 'invoice', $invoiceId);
    setFlashMessage('success', t('invoice_created_success'));
}

$pageTitle = $documentType . ' - ' . $tenant['guest_name'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-receipt me-2"></i><?php echo $documentType; ?></h5>
                    <div>
                        <a href="print.php?id=<?php echo $tenantId; ?>&type=receipt" target="_blank" class="btn btn-sm btn-secondary" onclick="saveInvoiceData()">
                            <i class="bi bi-printer me-1"></i><?php echo t('invoice_print'); ?>
                        </a>
                        <?php if ($hasTaxId): ?>
                        <a href="print.php?id=<?php echo $tenantId; ?>&type=invoice" target="_blank" class="btn btn-sm btn-info ms-2" onclick="saveInvoiceData()">
                            <i class="bi bi-file-text me-1"></i><?php echo t('invoice_doc_tax'); ?>
                        </a>
                        <?php endif; ?>
                        <a href="../reports/income.php" class="btn btn-sm btn-primary ms-2"><?php echo t('invoice_back'); ?>
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <?php if ($settings['logo']): ?>
                            <img src="<?php echo BASE_URL; ?>assets/images/logo/<?php echo $settings['logo']; ?>" alt="Logo" style="max-height: 80px;" class="mb-3">
                            <?php endif; ?>
                            <h5><?php echo htmlspecialchars($lang === 'en' ? ($settings['dorm_name_en'] ?? $settings['dorm_name']) : $settings['dorm_name']); ?></h5>
                            <p class="text-muted small mb-1"><?php echo nl2br(htmlspecialchars($lang === 'en' ? ($settings['address_en'] ?? $settings['address']) : $settings['address'])); ?></p>
                            <p class="text-muted small mb-0"><?php echo t('invoice_phone'); ?>: <?php echo $settings['phone']; ?></p>
                            <?php if ($hasTaxId): ?>
                            <p class="text-muted small"><?php echo t('invoice_tax_id_label'); ?>: <?php echo $settings['tax_id']; ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <h4 class="text-primary"><?php echo $documentType; ?></h4>
                            <p class="mb-1"><strong><?php echo t('invoice_no'); ?>:</strong> <?php echo $invoiceNumber; ?></p>
                            <p class="mb-1"><strong><?php echo t('invoice_date_label'); ?>:</strong> <?php echo date('d/m/Y'); ?></p>
                                                    </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6><?php echo t('invoice_customer'); ?>:</h6>
                            <p class="mb-1"><strong><?php echo htmlspecialchars($tenant['guest_name']); ?></strong></p>
                            <p class="mb-0"><?php echo t('invoice_phone'); ?>: <?php echo $tenant['phone']; ?></p>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <h6><?php echo t('invoice_stay_details'); ?>:</h6>
                            <p class="mb-1"><?php echo t('invoice_room_label'); ?>: <?php echo $tenant['room_number']; ?> (<?php echo $typeName; ?>)</p>
                            <p class="mb-1"><?php echo t('invoice_stay_period'); ?>: <?php echo formatDate($tenant['check_in_date']); ?> - <?php echo formatDate($tenant['check_out_date']); ?></p>
                            <p class="mb-0"><?php echo t('invoice_quantity_label'); ?>: <?php echo $baseDays + $earlyCheckinDays + $lateCheckoutDays; ?> <?php echo t('invoice_nights'); ?></p>
                        </div>
                    </div>
                    
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo t('invoice_item'); ?></th>
                                <th class="text-center"><?php echo t('invoice_qty'); ?></th>
                                <th class="text-end"><?php echo t('invoice_unit_price'); ?></th>
                                <th class="text-end"><?php echo t('invoice_amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo $roomDescription; ?></td>
                                <td class="text-center"><?php echo $baseDays; ?> <?php echo t('invoice_nights'); ?></td>
                                <td class="text-end"><?php echo formatCurrency($tenant['daily_rate']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($roomAmount); ?></td>
                            </tr>
                            <?php if ($otherFees > 0): ?>
                            <tr>
                                <td><?php echo t('other_fees'); ?></td>
                                <td class="text-center">1</td>
                                <td class="text-end"><?php echo formatCurrency($otherFees); ?></td>
                                <td class="text-end"><?php echo formatCurrency($otherFees); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($earlyCheckinDays > 0): ?>
                            <tr>
                                <td><?php echo t('invoice_early_checkin_charge'); ?></td>
                                <td class="text-center"><?php echo $earlyCheckinDays; ?> <?php echo t('invoice_days_unit'); ?></td>
                                <td class="text-end"><?php echo formatCurrency($tenant['daily_rate']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($earlyCheckinCharge); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($lateCheckoutDays > 0): ?>
                            <tr>
                                <td><?php echo t('invoice_late_checkout_charge'); ?></td>
                                <td class="text-center"><?php echo $lateCheckoutDays; ?> <?php echo t('invoice_days_unit'); ?></td>
                                <td class="text-end"><?php echo formatCurrency($tenant['daily_rate']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($lateCheckoutCharge); ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end"><strong><?php echo t('invoice_subtotal'); ?></strong></td>
                                <td class="text-end"><?php echo formatCurrency($subtotal); ?></td>
                            </tr>
                            <?php if ($hasTaxId): ?>
                            <tr>
                                <td colspan="3" class="text-end"><strong><?php echo t('invoice_vat'); ?> (<?php echo $vatRate; ?>%)</strong></td>
                                <td class="text-end"><?php echo formatCurrency($vatAmount); ?></td>
                            </tr>
                            <tr class="table-primary">
                                <td colspan="3" class="text-end"><strong><?php echo t('invoice_grand_total'); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($grandTotal); ?></strong></td>
                            </tr>
                            <?php else: ?>
                            <tr class="table-primary">
                                <td colspan="3" class="text-end"><strong><?php echo t('invoice_grand_total'); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($subtotal); ?></strong></td>
                            </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                    
                    <div class="mt-3 small">
                        <?php echo t('invoice_note_text'); ?><br>
                        <?php echo t('invoice_keep_receipt'); ?>
                    </div>


                    
                    <?php if (!$existingInvoice): ?>
                    <form method="POST" action="" id="saveInvoiceForm">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="save_invoice">
                        <input type="hidden" name="invoice_number" value="<?php echo $invoiceNumber; ?>">
                        <input type="hidden" name="tenant_type" value="daily">
                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                        <input type="hidden" name="invoice_type" value="daily">
                        <input type="hidden" name="invoice_date" value="<?php echo date('Y-m-d'); ?>">
                        <input type="hidden" name="due_date" value="">
                        <input type="hidden" name="subtotal" value="<?php echo $subtotal; ?>">
                        <input type="hidden" name="vat_rate" value="<?php echo $vatRate; ?>">
                        <input type="hidden" name="vat_amount" value="<?php echo $vatAmount; ?>">
                        <input type="hidden" name="grand_total" value="<?php echo $grandTotal; ?>">
                        <input type="hidden" name="status" value="issued">
                        <input type="hidden" name="created_by" value="<?php echo $_SESSION['user_id']; ?>">
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
function saveInvoiceData() {
    const form = document.getElementById('saveInvoiceForm');
    if (form) {
        // Submit form using fetch to save to database
        fetch('', {
            method: 'POST',
            body: new FormData(form)
        }).then(response => {
            console.log('Invoice data saved');
        }).catch(error => {
            console.error('Error saving invoice:', error);
        });
    }
}
</script>
