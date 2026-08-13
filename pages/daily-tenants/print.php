<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/promptpay_qr.php';

// Handle language from URL parameter
if (isset($_GET['lang']) && in_array($_GET['lang'], ['th', 'en'])) {
    $_SESSION['lang'] = $_GET['lang'];
}

requireLogin();

$lang = $_SESSION['lang'] ?? 'th';
ensureDailyTenantOtherFeesColumn();

$tenantId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($tenantId === 0) {
    die('ไม่พบข้อมูล');
}

// Get tenant details
$stmt = $pdo->prepare("SELECT dt.*, r.room_number, rt.type_name, rt.type_name_en FROM daily_tenants dt JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id WHERE dt.id = ?");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    die('ไม่พบข้อมูล');
}

$settings = getSettings();

// Room type name based on language
$typeName = ($lang === 'en' && !empty($tenant['type_name_en'])) ? $tenant['type_name_en'] : $tenant['type_name'];

// Check if Tax ID is configured
$hasTaxId = !empty($settings['tax_id']);
$documentType = $hasTaxId ? t('invoice_doc_tax') : t('invoice_doc_receipt');

// Determine document subtype based on customer tax ID
$documentSubType = 'full_tax';
if (!$hasTaxId) {
    $documentSubType = 'receipt';
} elseif (empty($tenant['customer_tax_id'])) {
    $documentSubType = 'abbreviated_tax';
    $documentType = $lang === 'en' ? 'Abbreviated Tax Invoice' : 'ใบกำกับภาษีอย่างย่อ';
}

// Get branch info
$sellerBranch = $settings['branch_number'] ?? '00000';
$customerBranch = $tenant['customer_branch'] ?? '00000';

// Generate document number
$documentNumber = $hasTaxId ? generateInvoiceNumber('daily') : 'REC-' . date('Ym') . '-' . str_pad($tenantId, 4, '0', STR_PAD_LEFT);

// Calculate extra charges
$earlyCheckinDays = 0;
$earlyCheckinCharge = 0;
$lateCheckoutDays = 0;
$lateCheckoutCharge = 0;
$otherFees = (float) ($tenant['other_fees'] ?? 0);
$roomAmount = (float) $tenant['daily_rate'] * (int) $tenant['total_days'];
$baseAmount = $roomAmount + $otherFees;
$baseDays = $tenant['total_days'];

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

// Get invoice
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE tenant_type = 'daily' AND tenant_id = ?");
$stmt->execute([$tenantId]);
$invoice = $stmt->fetch();

// Always use calculated totals
$subtotal = $displayTotal;
$vatRate = $hasTaxId ? 7 : 0;
$vatAmount = $hasTaxId ? $subtotal * ($vatRate / 100) : 0;
$grandTotal = $subtotal + $vatAmount;

if ($invoice) {
    $invoiceNumber = $invoice['invoice_number'];
    $documentDate = $invoice['created_at'] ?? date('Y-m-d');
    $documentDueDate = $invoice['due_date'] ?? null;

    // Sync invoice if totals changed
    $needsSync =
        abs((float) $invoice['subtotal'] - $subtotal) > 0.0001 ||
        abs((float) $invoice['grand_total'] - $grandTotal) > 0.0001;

    if ($needsSync) {
        $stmt = $pdo->prepare("UPDATE invoices SET subtotal = ?, vat_rate = ?, vat_amount = ?, grand_total = ? WHERE id = ?");
        $stmt->execute([$subtotal, $vatRate, $vatAmount, $grandTotal, $invoice['id']]);

        // Rebuild invoice_items
        $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
        $stmt->execute([$invoice['id']]);

        $roomDesc = t('invoice_room_charge') . ' ' . $typeName . ' ' . t('invoice_room_label') . ' ' . $tenant['room_number'] . ' (' . $baseDays . ' ' . t('invoice_days_unit') . ')';
        $stmtItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
        $stmtItem->execute([$invoice['id'], $roomDesc, $baseDays, $tenant['daily_rate'], $roomAmount]);

        if ($otherFees > 0) {
            $stmtItem->execute([$invoice['id'], t('other_fees'), 1, $otherFees, $otherFees]);
        }

        if ($earlyCheckinDays > 0) {
            $stmtItem->execute([$invoice['id'], t('invoice_early_checkin_charge'), $earlyCheckinDays, $tenant['daily_rate'], $earlyCheckinCharge]);
        }
        if ($lateCheckoutDays > 0) {
            $stmtItem->execute([$invoice['id'], t('invoice_late_checkout_charge'), $lateCheckoutDays, $tenant['daily_rate'], $lateCheckoutCharge]);
        }
    }
} else {
    $invoiceNumber = $documentNumber;
    $documentDate = date('Y-m-d');
    $documentDueDate = date('Y-m-d', strtotime('+7 days'));
}

// Room description for display
$roomDescription = t('invoice_room_charge') . ' ' . $typeName . ' (' . t('invoice_room_label') . ' ' . $tenant['room_number'] . ')';

// Branch labels
$hqLabel = $lang === 'en' ? 'Head Office' : 'สำนักงานใหญ่';
$branchLabel = $lang === 'en' ? 'Branch' : 'สาขาที่';
$addressLabel = $lang === 'en' ? 'Address' : 'ที่อยู่';
$paymentInstruction = buildPaymentInstructionText($settings, $lang, 'daily');
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $documentType; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .qr-code-grid { margin-bottom: 0 !important; }
        .qr-code-grid > [class*="col-"] { display: flex; }
        .qr-card { background-color: #f8f9fa; border-radius: 12px; padding: 18px 14px; width: 100%; height: auto; min-height: 0; display: flex; flex-direction: column; align-items: center; text-align: center; box-sizing: border-box; }
        .qr-card > p:first-child { display: flex; align-items: flex-start; justify-content: center; width: 100%; min-height: 20px; margin-bottom: 0 !important; font-size: 14px; font-weight: 600; line-height: 1.35; color: #2d3748; }
        .qr-image-slot { display: flex; align-items: flex-start; justify-content: center; width: 100%; height: 80px; flex: 0 0 80px; }
        .qr-image-slot img { width: 80px; height: 80px; background-color: #fff; padding: 8px; border-radius: 8px; box-sizing: border-box; }
        .qr-payment-details { margin-top: 8px; }
        .qr-card-caption { min-height: 32px; margin-top: 8px !important; color: #718096; font-size: 12px; line-height: 1.4; }
        @media print {
            .no-print { display: none; }
            body { margin: 0; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row mb-4">
            <div class="col-6">
                <?php if ($settings['logo']): ?>
                <img src="<?php echo BASE_URL; ?>assets/images/logo/<?php echo $settings['logo']; ?>" alt="Logo" style="max-height: 60px;" class="mb-2">
                <?php endif; ?>
                <?php
                $dormNameTh = !empty($settings['company_name']) ? $settings['company_name'] : $settings['dorm_name'];
                $dormNameDisplay = ($lang === 'en' && !empty($settings['dorm_name_en'])) ? $settings['dorm_name_en'] : $dormNameTh;
                ?>
                <h5><?php echo htmlspecialchars($dormNameDisplay); ?></h5>
                <p class="small mb-0"><?php echo nl2br(htmlspecialchars($lang === 'en' ? ($settings['address_en'] ?? $settings['address']) : $settings['address'])); ?></p>
                <?php if (!empty($settings['phone'])): ?>
                <p class="small mb-0"><?php echo t('invoice_phone'); ?>: <?php echo $settings['phone']; ?></p>
                <?php endif; ?>
                <?php if ($hasTaxId): ?>
                <p class="small mb-0">
                    <?php echo t('invoice_tax_id_label'); ?>: <?php echo $settings['tax_id']; ?>
                    <?php if ($settings['branch_number'] === '00000'): ?>
                        (<?php echo t('headquarters'); ?>)
                    <?php else: ?>
                        (<?php echo t('branch'); ?> <?php echo $settings['branch_number']; ?>)
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
            <div class="col-6 text-end">
                <h3 class="text-primary"><?php echo $documentType; ?></h3>
                <p class="mb-0"><?php echo t('invoice_no'); ?>: <?php echo $invoiceNumber; ?></p>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-6">
                <p class="mb-0"><?php echo t('invoice_customer'); ?>: <strong><?php echo htmlspecialchars($tenant['guest_name']); ?></strong></p>
                <p class="mb-0"><?php echo t('invoice_phone'); ?>: <?php echo $tenant['phone']; ?></p>
                <?php if ($documentSubType === 'full_tax' && !empty($tenant['customer_tax_id'])): ?>
                    <p class="small mb-0"><?php echo t('invoice_tax_id_label'); ?>: <?php echo $tenant['customer_tax_id']; ?></p>
                    <?php if ($customerBranch !== '00000'): ?>
                        <p class="small mb-0"><?php echo $branchLabel; ?> <?php echo $customerBranch; ?></p>
                    <?php endif; ?>
                    <?php if (!empty($tenant['customer_address'])): ?>
                        <p class="small mb-0"><?php echo $addressLabel; ?>: <?php echo nl2br(htmlspecialchars($tenant['customer_address'])); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="col-6 text-end">
                <h6><?php echo t('invoice_stay_details'); ?>:</h6>
                <p class="mb-0"><?php echo t('invoice_room_label'); ?>: <?php echo $tenant['room_number']; ?></p>
                <p class="mb-0"><?php echo formatDate($tenant['check_in_date']); ?> - <?php echo formatDate($tenant['check_out_date']); ?></p>
            </div>
        </div>
        
        <table class="table table-bordered">
            <thead class="table-light">
                <tr>
                    <th><?php echo t('invoice_item'); ?></th>
                    <th class="text-center"><?php echo t('invoice_qty'); ?></th>
                    <th class="text-end"><?php echo t('invoice_price'); ?></th>
                    <th class="text-end"><?php echo t('invoice_amount'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo $roomDescription; ?></td>
                    <td class="text-center"><?php echo $baseDays; ?> <?php echo t('invoice_days_unit'); ?></td>
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
                    <td colspan="3" class="text-end"><strong><?php echo t('invoice_vat'); ?> <?php echo $vatRate; ?>%</strong></td>
                    <td class="text-end"><?php echo formatCurrency($vatAmount); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($invoice && $invoice['discount'] > 0): ?>
                <tr>
                    <td colspan="3" class="text-end"><strong><?php echo t('invoice_discount'); ?></strong></td>
                    <td class="text-end text-danger">-<?php echo formatCurrency($invoice['discount']); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="table-primary">
                    <td colspan="3" class="text-end"><strong><?php echo t('invoice_grand_total'); ?></strong></td>
                    <td class="text-end"><strong><?php echo formatCurrency($grandTotal); ?></strong></td>
                </tr>
            </tfoot>
        </table>

        <!-- QR Codes Section -->
        <div class="row align-items-stretch mb-4 qr-code-grid">
            <div class="col-4 text-center">
                <?php if (!empty($settings['promptpay_id'])): ?>
                    <?php
                    echo buildPaymentQRBlockHtml($settings, $grandTotal);
                    ?>
                <?php endif; ?>
            </div>
            <div class="col-4 text-center">
                <?php
                $paymentNoticeUrl = buildAbsoluteUrl(BASE_URL . 'pages/payment-notice.php');
                $paymentNoticeQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($paymentNoticeUrl);
                ?>
                <div class="qr-card">
                    <p style="font-size: 14px; font-weight: 600; color: #2d3748; margin-bottom: 8px;">💳 <?php echo $lang === 'en' ? 'Payment Confirmation' : 'แจ้งชำระเงิน'; ?></p>
                    <div class="qr-image-slot"><img src="<?php echo $paymentNoticeQrUrl; ?>" alt="Payment Confirmation QR"></div>
                    <p style="color: #718096; font-size: 12px; margin-top: 8px; margin-bottom: 0;"><?php echo $lang === 'en' ? 'Scan to submit payment slip' : 'สแกนเพื่อแจ้งชำระเงิน'; ?></p>
                </div>
            </div>
            <div class="col-4 text-center">
                <?php
                $repairFormUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');
                $repairQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($repairFormUrl);
                ?>
                <div class="qr-card">
                    <p style="font-size: 14px; font-weight: 600; color: #2d3748; margin-bottom: 8px;">🔧 <?php echo $lang === 'en' ? 'Repair Request' : 'แจ้งซ่อมห้องพัก'; ?></p>
                    <div class="qr-image-slot"><img src="<?php echo $repairQrUrl; ?>" alt="Repair Request QR"></div>
                    <p style="color: #718096; font-size: 12px; margin-top: 8px; margin-bottom: 0;"><?php echo $lang === 'en' ? 'Scan to request repair' : 'สแกนเพื่อแจ้งซ่อม'; ?></p>
                </div>
            </div>
        </div>

        <div class="small">
            <strong><?php echo t('notes'); ?>:</strong>
            <ul class="mb-0 ps-3" style="list-style-type: '- ';">
                <li>
                    <?php echo htmlspecialchars($paymentInstruction, ENT_QUOTES, 'UTF-8'); ?><br>
                    <?php echo t('invoice_note_text'); ?><br>
                    <?php echo t('invoice_keep_receipt'); ?>
                </li>
            </ul>
        </div>

        <div class="mt-4 text-center no-print">
            <button onclick="window.print()" class="btn btn-primary"><?php echo t('invoice_print'); ?></button>
            <button onclick="window.close()" class="btn btn-secondary ms-2"><?php echo t('close'); ?></button>
        </div>
    </div>
    
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
