<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/promptpay_qr.php';

// Handle language from URL parameter after session_start() from database.php
if (isset($_GET['lang']) && in_array($_GET['lang'], ['th', 'en'])) {
    $_SESSION['lang'] = $_GET['lang'];
}

requireLogin();

$lang = $_SESSION['lang'] ?? 'th';

$invoiceId = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
$tenantId = isset($_GET['id']) ? intval($_GET['id']) : 0;

$invoice = null;
if ($invoiceId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch();

    if ($invoice && (($invoice['tenant_type'] ?? '') === 'monthly' || ($invoice['invoice_type'] ?? '') === 'monthly')) {
        $tenantId = (int) $invoice['tenant_id'];
    } else {
        $invoice = null;
    }
}

// Get billing month from URL parameter, invoice record, or fallback to current month
$billingMonth = $_GET['month'] ?? '';
if ($billingMonth === '' && $invoice) {
    $billingMonth = date('Y-m', strtotime($invoice['invoice_date']));
}
if (!preg_match('/^\d{4}-\d{2}$/', $billingMonth)) {
    $billingMonth = date('Y-m');
}

// Format billing month for display (Thai or English month name based on language)
$thaiMonths = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
$englishMonths = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$billingMonthParts = explode('-', $billingMonth);
$monthIndex = (int)$billingMonthParts[1] - 1;
$year = (int)$billingMonthParts[0];
if ($lang === 'en') {
    $billingMonthDisplay = $englishMonths[$monthIndex] . ' ' . $year;
} else {
    $billingMonthDisplay = $thaiMonths[$monthIndex] . ' ' . $year;
}

$billingMonthStart = $billingMonth . '-01';
$nextBillingMonthStart = date('Y-m-d', strtotime($billingMonthStart . ' +1 month'));

if ($tenantId === 0) {
    setFlashMessage('error', 'ไม่พบข้อมูล');
    header('Location: index.php');
    exit;
}

// Get tenant details
$stmt = $pdo->prepare("SELECT mt.*, r.room_number, rt.type_name, rt.type_name_en FROM monthly_tenants mt JOIN rooms r ON mt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id WHERE mt.id = ?");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant && $invoice) {
    $stmt = $pdo->prepare("SELECT mt.*, r.room_number, rt.type_name, rt.type_name_en FROM monthly_tenants mt JOIN rooms r ON mt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id WHERE mt.room_id = ? AND mt.contract_start <= ? AND mt.contract_end >= ? ORDER BY mt.updated_at DESC, mt.id DESC LIMIT 1");
    $stmt->execute([$invoice['room_id'], $billingMonthStart, $billingMonthStart]);
    $tenant = $stmt->fetch();

    if ($tenant) {
        $tenantId = (int) $tenant['id'];
    }
}

if (!$tenant) {
    setFlashMessage('error', 'ไม่พบข้อมูล');
    header('Location: index.php');
    exit;
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

// Branch labels
$hqLabel = $lang === 'en' ? 'Head Office' : 'สำนักงานใหญ่';
$branchLabel = $lang === 'en' ? 'Branch' : 'สาขาที่';
$addressLabel = $lang === 'en' ? 'Address' : 'ที่อยู่';

// Generate document number
if ($hasTaxId) {
    $documentNumber = generateInvoiceNumber('monthly');
} else {
    $documentNumber = 'REC-' . date('Ym') . '-' . str_pad($tenantId, 4, '0', STR_PAD_LEFT);
}

// Get utility bill for the selected month
$stmt = $pdo->prepare("SELECT * FROM utility_bills WHERE tenant_id = ? AND bill_month = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$tenantId, $billingMonth]);
$bill = $stmt->fetch();

// Calculate totals from bill details (bill total_amount includes discount, so we recalculate)
$rentAmount = $bill ? $bill['rent_amount'] : $tenant['monthly_rent'];
$waterAmount = $bill ? $bill['water_amount'] : 0;
$elecAmount = $bill ? $bill['elec_amount'] : 0;
$otherFees = $bill ? $bill['other_fees'] : 0;
$discount = $bill ? $bill['discount'] : 0;
$subtotal = $rentAmount + $waterAmount + $elecAmount + $otherFees;

// Calculate VAT correctly: VAT is calculated on subtotal BEFORE discount (Thai RD requirement)
$vatRate = $hasTaxId ? 7 : 0;
$vatAmount = $hasTaxId ? $subtotal * ($vatRate / 100) : 0;
$finalTotal = $subtotal + $vatAmount - $discount;
$grandTotal = $finalTotal;

if (!$invoice) {
    // Get existing invoice for the selected billing month
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE tenant_type = 'monthly' AND tenant_id = ? AND invoice_date >= ? AND invoice_date < ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$tenantId, $billingMonthStart, $nextBillingMonthStart]);
    $invoice = $stmt->fetch();
}

if ($invoice) {
    $documentNumber = $invoice['invoice_number'];
    $invoiceId = (int) $invoice['id'];
    $subtotal = $invoice['subtotal'];
    $vatRate = $invoice['vat_rate'];
    $vatAmount = $invoice['vat_amount'];
    $discount = $invoice['discount'] ?? 0;
    $finalTotal = $invoice['grand_total'];
    $grandTotal = $finalTotal;

    switch ($invoice['document_type'] ?? '') {
        case 'receipt':
            $documentType = t('invoice_doc_receipt');
            $documentSubType = 'receipt';
            break;
        case 'abbreviated_tax':
            $documentType = $lang === 'en' ? 'Abbreviated Tax Invoice' : 'ใบกำกับภาษีอย่างย่อ';
            $documentSubType = 'abbreviated_tax';
            break;
        default:
            $documentType = t('invoice_doc_tax');
            $documentSubType = 'full_tax';
            break;
    }
}

$documentDate = !empty($invoice['created_at'])
    ? $invoice['created_at']
    : (!empty($bill['created_at']) ? $bill['created_at'] : date('Y-m-d'));
$documentDueDate = !empty($invoice['due_date'])
    ? $invoice['due_date']
    : null;
$invoiceItems = [];
if ($invoiceId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
    $stmt->execute([$invoiceId]);
    $invoiceItems = $stmt->fetchAll();
}

// Get outstanding bills before the current billing month
$outstanding = getOutstandingBills($tenantId, $billingMonth);
$outstandingBills = $outstanding['bills'];
$totalOutstanding = $outstanding['total'];
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
                $dormNameTh = $settings['dorm_name'] ?? '';
                $dormNameDisplay = $lang === 'en'
                    ? (!empty($settings['dorm_name_en']) ? $settings['dorm_name_en'] : $dormNameTh)
                    : $dormNameTh;

                if ($dormNameDisplay === '') {
                    $dormNameDisplay = $settings['company_name'] ?? t('app_name');
                }
                ?>
                <h5><?php echo htmlspecialchars($dormNameDisplay); ?></h5>
                <?php if (!empty($settings['company_name']) && $settings['company_name'] !== $dormNameDisplay): ?>
                <p class="small mb-0"><?php echo htmlspecialchars($settings['company_name']); ?></p>
                <?php endif; ?>
                <p class="small mb-0"><?php echo nl2br(htmlspecialchars($lang === 'en' ? ($settings['address_en'] ?? $settings['address']) : $settings['address'])); ?></p>
                <?php if (!empty($settings['phone'])): ?>
                <p class="small mb-0"><?php echo t('invoice_phone'); ?>: <?php echo $settings['phone']; ?></p>
                <?php endif; ?>
                <?php if ($hasTaxId): ?>
                <p class="small mb-0">
                    <?php echo t('invoice_tax_id_label'); ?>: <?php echo $settings['tax_id']; ?>
                    <?php if ($sellerBranch === '00000'): ?>
                        (<?php echo $hqLabel; ?>)
                    <?php else: ?>
                        (<?php echo $branchLabel; ?> <?php echo $sellerBranch; ?>)
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
            <div class="col-6 text-end">
                <h3 class="text-primary"><?php echo $documentType; ?></h3>
                <p class="mb-0"><?php echo t('invoice_no'); ?>: <?php echo $documentNumber; ?></p>
                <p class="mb-0"><?php echo t('invoice_due_label'); ?>: <?php echo formatDate($documentDueDate); ?></p>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-6">
                <p class="mb-0"><?php echo t('invoice_customer'); ?>: <strong><?php echo htmlspecialchars($tenant['tenant_name']); ?></strong></p>
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
                <p class="mb-0"><?php echo t('invoice_room_label'); ?>: <strong><?php echo $tenant['room_number']; ?></strong></p>
            </div>
        </div>
        
        <div class="alert alert-secondary py-2 mb-3">
            <strong><i class="bi bi-calendar-month me-2"></i><?php echo t('invoice_bill_for_month'); ?>:</strong> <?php echo htmlspecialchars($billingMonthDisplay); ?>
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
                <?php if (!empty($invoiceItems)): ?>
                    <?php foreach ($invoiceItems as $item): ?>
                    <tr>
                        <td><?php $itemDesc = htmlspecialchars(str_replace('ค่าอื่นๆ', 'อื่นๆ', $item['item_description'])); $roomLabel = t('invoice_room_label'); echo preg_replace('/' . preg_quote($roomLabel, '/') . '\s+(\d+)/', '(' . $roomLabel . ' $1)', $itemDesc); ?></td>
                        <td class="text-center"><?php $isUtilityItem = in_array($item['item_description'], ['ค่าน้ำ', 'ค่าไฟฟ้า']); echo ($item['quantity'] == 1 && !$isUtilityItem) ? '1 ' . t('invoice_month_unit') : ($isUtilityItem ? (int)$item['quantity'] . ' ' . t('invoice_units') : $item['quantity']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($item['unit_price']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($item['total_price']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td><?php echo t('invoice_room_rent'); ?> <?php echo $typeName; ?> (<?php echo t('invoice_room_label'); ?> <?php echo $tenant['room_number']; ?>)</td>
                    <td class="text-center">1 <?php echo t('invoice_month_unit'); ?></td>
                    <td class="text-end"><?php echo formatCurrency($bill ? $bill['rent_amount'] : $tenant['monthly_rent']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($bill ? $bill['rent_amount'] : $tenant['monthly_rent']); ?></td>
                </tr>
                <?php if ($bill && $bill['water_amount'] > 0): ?>
                <tr>
                    <td><?php echo t('invoice_water'); ?></td>
                    <td class="text-center"><?php echo $bill['water_units']; ?> <?php echo t('invoice_units'); ?></td>
                    <td class="text-end"><?php echo formatCurrency($bill['water_rate']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($bill['water_amount']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($bill && $bill['elec_amount'] > 0): ?>
                <tr>
                    <td><?php echo t('invoice_electric'); ?></td>
                    <td class="text-center"><?php echo $bill['elec_units']; ?> <?php echo t('invoice_units'); ?></td>
                    <td class="text-end"><?php echo formatCurrency($bill['elec_rate']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($bill['elec_amount']); ?></td>
                </tr>
                <?php endif; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="text-end"><strong><?php echo t('invoice_subtotal'); ?></strong></td>
                    <td class="text-end"><?php echo formatCurrency($subtotal); ?></td>
                </tr>
                <?php if ($discount > 0): ?>
                <tr>
                    <td colspan="3" class="text-end"><strong><?php echo t('invoice_discount'); ?></strong></td>
                    <td class="text-end"><?php echo formatCurrency($discount); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($hasTaxId): ?>
                <tr>
                    <td colspan="3" class="text-end"><strong><?php echo t('invoice_vat'); ?> <?php echo $vatRate; ?>%</strong></td>
                    <td class="text-end"><?php echo formatCurrency($vatAmount); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="table-primary">
                    <td colspan="3" class="text-end"><strong><?php echo t('invoice_grand_total'); ?></strong></td>
                    <td class="text-end"><strong><?php echo formatCurrency($finalTotal); ?></strong></td>
                </tr>
            </tfoot>
        </table>

        <?php if (!empty($outstandingBills)): ?>
        <div class="alert alert-danger py-2 mb-3 mt-3">
            <strong><i class="bi bi-exclamation-triangle me-2"></i><?php echo $lang === 'en' ? 'Outstanding Balance' : 'ยอดค้างชำระ'; ?></strong>
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
                <?php foreach ($outstandingBills as $ob): ?>
                <tr>
                    <td>
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php echo $lang === 'en' ? 'Outstanding' : 'ค้างชำระ'; ?> - <?php echo formatBillMonth($ob['bill_month'], $lang); ?>
                    </td>
                    <td class="text-center">1 <?php echo t('invoice_month_unit'); ?></td>
                    <td class="text-end"><?php echo formatCurrency($ob['total_amount']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($ob['total_amount']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-danger">
                    <td colspan="3" class="text-end"><strong><?php echo $lang === 'en' ? 'Total Outstanding' : 'ยอดค้างชำระทั้งหมด'; ?></strong></td>
                    <td class="text-end fw-bold"><?php echo formatCurrency($totalOutstanding); ?></td>
                </tr>
            </tfoot>
        </table>
        <div class="alert alert-primary py-2 mb-3">
            <strong><?php echo $lang === 'en' ? 'Total Amount Due' : 'ยอดที่ต้องชำระทั้งหมด'; ?>: <?php echo formatCurrency($finalTotal + $totalOutstanding); ?></strong>
        </div>
        <?php endif; ?>

        <!-- QR Codes Section -->
        <div class="row align-items-stretch">
            <div class="col-6 text-center h-100">
                <?php if (!empty($settings['promptpay_id']) && $bill): ?>
                    <?php
                    ensurePaymentTokenColumn();
                    $token = getOrCreatePaymentToken((int)$bill['id']);
                    $paymentUrl = buildAbsoluteUrl(BASE_URL . 'pages/payment.php?token=' . $token);
                    $qrBlock = buildPaymentQRBlockHtml($settings, $finalTotal, $paymentUrl);
                    echo $qrBlock;
                    ?>
                <?php endif; ?>
            </div>
            <div class="col-6 text-center h-100">
                <?php
                $repairFormUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');
                $repairQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($repairFormUrl);
                ?>
                <div style="background-color: #f8f9fa; border-radius: 12px; padding: 25px; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                    <p style="font-size: 14px; font-weight: 600; color: #2d3748; margin-bottom: 8px;">🔧 แจ้งซ่อมห้องพัก</p>
                    <img src="<?php echo $repairQrUrl; ?>" alt="Repair Request QR" width="80" height="80" style="background-color: #fff; padding: 8px; border-radius: 8px;">
                    <p style="color: #718096; font-size: 12px; margin-top: 8px; margin-bottom: 0;">สแกนเพื่อแจ้งซ่อม</p>
                </div>
            </div>
        </div>

        <?php $utilityBillNotes = $bill ? trim($bill['notes'] ?? '') : ''; ?>
        <div class="small">
            <strong><?php echo t('notes'); ?>:</strong>
            <ul class="mb-0 ps-3" style="list-style-type: '- ';">
                <?php if (!empty($utilityBillNotes)): ?>
                <li><?php echo nl2br(htmlspecialchars($utilityBillNotes)); ?></li>
                <?php endif; ?>
                <li>
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
