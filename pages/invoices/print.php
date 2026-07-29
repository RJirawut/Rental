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

$invoiceId = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

if ($invoiceId === 0) {
    die('ไม่พบข้อมูล');
}

// Get invoice details
$stmt = $pdo->prepare("SELECT i.*, r.room_number FROM invoices i JOIN rooms r ON i.room_id = r.id WHERE i.id = ?");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die('ไม่พบข้อมูล');
}

// Get invoice items
$stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$stmt->execute([$invoiceId]);
$items = $stmt->fetchAll();

// Get tenant details with room type info
if ($invoice['tenant_type'] === 'daily') {
    $stmt = $pdo->prepare("SELECT dt.*, r.room_number, rt.type_name, rt.type_name_en FROM daily_tenants dt JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id WHERE dt.id = ?");
} else {
    $stmt = $pdo->prepare("SELECT mt.*, r.room_number, rt.type_name, rt.type_name_en FROM monthly_tenants mt JOIN rooms r ON mt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id WHERE mt.id = ?");
}
$stmt->execute([$invoice['tenant_id']]);
$tenant = $stmt->fetch();

$settings = getSettings();
$hasTaxId = !empty($settings['tax_id']);
$documentType = $hasTaxId ? t('invoice_doc_tax') : t('invoice_doc_receipt');

// Determine document subtype based on customer tax ID
$documentSubType = 'full_tax';
if (!$hasTaxId) {
    $documentSubType = 'receipt';
} elseif (empty($invoice['customer_tax_id'])) {
    $documentSubType = 'abbreviated_tax';
    $documentType = $lang === 'en' ? 'Abbreviated Tax Invoice' : 'ใบกำกับภาษีอย่างย่อ';
}

// Get branch info
$sellerBranch = $settings['branch_number'] ?? '00000';
$customerBranch = $invoice['customer_branch_code'] ?? '00000';

// Generate document number
$documentNumber = $invoice['invoice_number'];
$documentDate = $invoice['created_at'] ?? date('Y-m-d');
$documentDueDate = $invoice['due_date'] ?? null;

// Calculate totals
$subtotal = $invoice['subtotal'];
$vatRate = $invoice['vat_rate'];
$vatAmount = $invoice['vat_amount'];
$grandTotal = $invoice['grand_total'];

// Room type name based on language
$typeName = ($lang === 'en' && !empty($tenant['type_name_en'])) ? $tenant['type_name_en'] : $tenant['type_name'];

// Branch labels
$hqLabel = $lang === 'en' ? 'Head Office' : 'สำนักงานใหญ่';
$branchLabel = $lang === 'en' ? 'Branch' : 'สาขาที่';
$addressLabel = $lang === 'en' ? 'Address' : 'ที่อยู่';

// Add billing month for monthly tenants
$billingMonthDisplay = '';
$outstandingBills = [];
$totalOutstanding = 0;
if ($invoice['tenant_type'] === 'monthly') {
    $billingMonth = date('Y-m', strtotime($invoice['invoice_date']));
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

    // Get outstanding bills before the current invoice billing month
    $outstanding = getOutstandingBills((int)$invoice['tenant_id'], $billingMonth);
    $outstandingBills = $outstanding['bills'];
    $totalOutstanding = $outstanding['total'];

    // Fetch utility bill notes for this tenant and month
    $stmt = $pdo->prepare("SELECT notes FROM utility_bills WHERE tenant_id = ? AND bill_month = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$invoice['tenant_id'], $billingMonth]);
    $utilityBillRow = $stmt->fetch();
    $utilityBillNotes = $utilityBillRow ? trim($utilityBillRow['notes'] ?? '') : '';
} else {
    $utilityBillNotes = '';
}
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
                <p class="mb-0"><?php echo t('invoice_no'); ?>: <?php echo $documentNumber; ?></p>
                <?php if ($invoice['tenant_type'] === 'monthly'): ?>
                <p class="mb-0"><?php echo t('invoice_due_label'); ?>: <?php echo formatDate($documentDueDate); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-6">
                <p class="mb-0"><?php echo t('invoice_customer'); ?>: <strong><?php echo htmlspecialchars($invoice['tenant_type'] === 'daily' ? $tenant['guest_name'] : $tenant['tenant_name']); ?></strong></p>
                <p class="mb-0"><?php echo t('invoice_phone'); ?>: <?php echo $tenant['phone']; ?></p>
                <?php if ($documentSubType === 'full_tax' && !empty($invoice['customer_tax_id'])): ?>
                    <p class="small mb-0"><?php echo t('invoice_tax_id_label'); ?>: <?php echo $invoice['customer_tax_id']; ?></p>
                    <?php if ($customerBranch !== '00000'): ?>
                        <p class="small mb-0"><?php echo $branchLabel; ?> <?php echo $customerBranch; ?></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['customer_address'])): ?>
                        <p class="small mb-0"><?php echo $addressLabel; ?>: <?php echo nl2br(htmlspecialchars($invoice['customer_address'])); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="col-6 text-end">
                <?php if ($invoice['tenant_type'] === 'daily'): ?>
                <h6><?php echo t('invoice_stay_details'); ?>:</h6>
                <p class="mb-0"><?php echo t('invoice_room_label'); ?>: <?php echo $tenant['room_number']; ?></p>
                <p class="mb-0"><?php echo formatDate($tenant['check_in_date']); ?> - <?php echo formatDate($tenant['check_out_date']); ?></p>
                <?php else: ?>
                <p class="mb-0"><?php echo t('invoice_room_label'); ?>: <strong><?php echo $tenant['room_number']; ?></strong></p>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($invoice['tenant_type'] === 'monthly' && !empty($billingMonthDisplay)): ?>
        <div class="alert alert-secondary py-2 mb-3">
            <strong><i class="bi bi-calendar-month me-2"></i><?php echo t('invoice_bill_for_month'); ?>:</strong> <?php echo htmlspecialchars($billingMonthDisplay); ?>
        </div>
        <?php endif; ?>
        
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
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php $itemDesc = htmlspecialchars(str_replace('ค่าอื่นๆ', 'อื่นๆ', $lang === 'en' && !empty($item['item_description_en']) ? $item['item_description_en'] : $item['item_description'])); $roomLabel = t('invoice_room_label'); echo preg_replace('/' . preg_quote($roomLabel, '/') . '\s+(\d+)/', '(' . $roomLabel . ' $1)', $itemDesc); ?></td>
                    <td class="text-center"><?php $isUtilityItem = in_array($item['item_description'], ['ค่าน้ำ', 'ค่าไฟฟ้า']); echo ($invoice['tenant_type'] === 'monthly' && $item['quantity'] == 1 && !$isUtilityItem) ? '1 ' . t('invoice_month_unit') : ($isUtilityItem ? (int)$item['quantity'] . ' ' . t('invoice_units') : $item['quantity']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($item['unit_price']); ?></td>
                    <td class="text-end"><?php echo formatCurrency($item['total_price']); ?></td>
                </tr>
                <?php endforeach; ?>
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
                <?php if ($invoice['discount'] > 0): ?>
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
            <strong><?php echo $lang === 'en' ? 'Total Amount Due' : 'ยอดที่ต้องชำระทั้งหมด'; ?>: <?php echo formatCurrency($grandTotal + $totalOutstanding); ?></strong>
        </div>
        <?php endif; ?>

        <!-- QR Codes Section -->
        <div class="row align-items-stretch">
            <div class="col-6 text-center h-100">
                <?php if (!empty($settings['promptpay_id'])): ?>
                    <?php
                    $paymentUrl = '';
                    if ($invoice) {
                        ensurePaymentTokenColumn();
                        $token = getOrCreatePaymentToken((int)$invoice['id']);
                        $paymentUrl = buildAbsoluteUrl(BASE_URL . 'pages/payment.php?token=' . $token);
                    }
                    $qrBlock = buildPaymentQRBlockHtml($settings, $grandTotal, $paymentUrl);
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
