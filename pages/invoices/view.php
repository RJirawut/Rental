<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$lang = $_SESSION['lang'] ?? 'th';

$invoiceId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($invoiceId === 0) {
    setFlashMessage('error', 'ไม่พบข้อมูล');
    header('Location: index.php');
    exit;
}

// Get invoice details
$stmt = $pdo->prepare("SELECT i.*, r.room_number FROM invoices i JOIN rooms r ON i.room_id = r.id WHERE i.id = ?");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    setFlashMessage('error', 'ไม่พบข้อมูล');
    header('Location: index.php');
    exit;
}

// Get invoice items
$stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$stmt->execute([$invoiceId]);
$items = $stmt->fetchAll();

// Get tenant details
if ($invoice['tenant_type'] === 'daily') {
    $stmt = $pdo->prepare("SELECT dt.*, r.room_number, rt.type_name, rt.type_name_en FROM daily_tenants dt JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id WHERE dt.id = ?");
} else {
    $stmt = $pdo->prepare("SELECT mt.*, r.room_number, rt.type_name, rt.type_name_en FROM monthly_tenants mt JOIN rooms r ON mt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id WHERE mt.id = ?");
}
$stmt->execute([$invoice['tenant_id']]);
$tenant = $stmt->fetch();

$typeName = ($lang === 'en' && !empty($tenant['type_name_en'])) ? $tenant['type_name_en'] : ($tenant['type_name'] ?? '');
$tenantName = $invoice['tenant_type'] === 'daily' ? ($tenant['guest_name'] ?? 'N/A') : ($tenant['tenant_name'] ?? 'N/A');

$settings = getSettings();
$hasTaxId = !empty($settings['tax_id']);
$documentType = $hasTaxId ? t('invoice_doc_tax') : t('invoice_doc_receipt');
$invoiceDetailTitle = $lang === 'en' ? 'Invoice Details' : 'รายละเอียดใบกำกับภาษี';
$pageTitle = $invoiceDetailTitle . ' ' . $invoice['invoice_number'];

// Get outstanding bills for monthly tenant (months before the current invoice month)
$outstandingBills = [];
$totalOutstanding = 0;
$utilityBillNotes = '';
if ($invoice['tenant_type'] === 'monthly') {
    $invoiceBillMonth = date('Y-m', strtotime($invoice['invoice_date']));
    $outstanding = getOutstandingBills((int)$invoice['tenant_id'], $invoiceBillMonth);
    $outstandingBills = $outstanding['bills'];
    $totalOutstanding = $outstanding['total'];

    // Fetch utility bill notes for this tenant and month
    $stmt = $pdo->prepare("SELECT notes FROM utility_bills WHERE tenant_id = ? AND bill_month = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$invoice['tenant_id'], $invoiceBillMonth]);
    $utilityBillRow = $stmt->fetch();
    $utilityBillNotes = $utilityBillRow ? trim($utilityBillRow['notes'] ?? '') : '';
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-receipt me-2"></i><?php echo $documentType; ?></h5>
                    <div>
                        <a href="print.php?invoice_id=<?php echo $invoiceId; ?>" target="_blank" class="btn btn-sm btn-primary">
                            <i class="bi bi-printer me-1"></i><?php echo t('invoice_print'); ?>
                        </a>
                        <?php if ($hasTaxId): ?>
                        <a href="print.php?invoice_id=<?php echo $invoiceId; ?>&type=invoice" target="_blank" class="btn btn-sm btn-info ms-2">
                            <i class="bi bi-file-text me-1"></i><?php echo t('invoice_doc_tax'); ?>
                        </a>
                        <?php endif; ?>
                        <a href="index.php" class="btn btn-sm btn-secondary ms-2"><?php echo t('invoice_back'); ?></a>
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
                            <p class="mb-1"><strong><?php echo t('invoice_no'); ?>:</strong> <?php echo $invoice['invoice_number']; ?></p>
                            <p class="mb-1"><strong><?php echo t('invoice_date_label'); ?>:</strong> <?php echo formatDate($invoice['created_at']); ?></p>
                            <p class="mb-0"><strong><?php echo t('invoice_due_label'); ?>:</strong> <?php echo formatDate($invoice['due_date']); ?></p>
                        </div>
                    </div>
                    
                    <?php if ($invoice['tenant_type'] === 'daily'): ?>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6><?php echo t('invoice_customer'); ?>:</h6>
                            <p class="mb-1"><strong><?php echo htmlspecialchars($tenantName); ?></strong></p>
                            <?php if (!empty($tenant['phone'])): ?>
                            <p class="mb-0"><?php echo t('invoice_phone'); ?>: <?php echo $tenant['phone']; ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <h6><?php echo t('invoice_stay_details'); ?>:</h6>
                            <p class="mb-1"><?php echo t('invoice_room_label'); ?>: <?php echo $invoice['room_number']; ?> <?php echo $typeName ? '(' . $typeName . ')' : ''; ?></p>
                            <?php if (!empty($tenant['check_in_date']) && !empty($tenant['check_out_date'])): ?>
                            <p class="mb-1"><?php echo t('invoice_stay_period'); ?>: <?php echo formatDate($tenant['check_in_date']); ?> - <?php echo formatDate($tenant['check_out_date']); ?></p>
                            <p class="mb-0"><?php echo t('invoice_quantity_label'); ?>: <?php echo $tenant['total_days']; ?> <?php echo t('invoice_nights'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <p class="mb-1"><?php echo t('invoice_customer'); ?>: <strong><?php echo htmlspecialchars($tenantName); ?></strong></p>
                            <?php if (!empty($tenant['phone'])): ?>
                            <p class="mb-0"><?php echo t('invoice_phone'); ?>: <?php echo $tenant['phone']; ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <h6><?php echo t('invoice_contract_info'); ?>:</h6>
                            <p class="mb-1"><?php echo t('invoice_room_label'); ?>: <?php echo $invoice['room_number']; ?></p>
                            <?php if (!empty($tenant['contract_start']) && !empty($tenant['contract_end'])): ?>
                            <p class="mb-1"><?php echo t('invoice_contract_period'); ?>: <?php echo formatDate($tenant['contract_start']); ?> - <?php echo formatDate($tenant['contract_end']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
            
                    <?php if ($invoice['tenant_type'] === 'monthly'): ?>
                    <?php
                    // Calculate billing month from invoice date
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
                    ?>
                    <div class="alert alert-primary py-2 mb-3">
                        <h5 class="mb-0"><i class="bi bi-calendar-month me-2"></i><?php echo t('invoice_bill_for_month'); ?>: <?php echo htmlspecialchars($billingMonthDisplay); ?></h5>
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
                                <td><?php $itemDesc = htmlspecialchars(str_replace('ค่าอื่นๆ', 'อื่นๆ', $item['item_description'])); $roomLabel = t('invoice_room_label'); echo preg_replace('/' . preg_quote($roomLabel, '/') . '\s+(\d+)/', '(' . $roomLabel . ' $1)', $itemDesc); ?></td>
                                <td class="text-center"><?php $isUtilityItem = in_array($item['item_description'], ['ค่าน้ำ', 'ค่าไฟฟ้า']); echo ($invoice['tenant_type'] === 'monthly' && $item['quantity'] == 1 && !$isUtilityItem) ? '1 ' . t('invoice_month_unit') : ($isUtilityItem ? (int)$item['quantity'] . ' ' . t('invoice_units') : $item['quantity']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($item['unit_price']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($item['total_price']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end"><strong><?php echo t('invoice_subtotal'); ?></strong></td>
                                <td class="text-end"><?php echo formatCurrency($invoice['subtotal']); ?></td>
                            </tr>
                            <?php if ($hasTaxId && $invoice['vat_amount'] > 0): ?>
                            <tr>
                                <td colspan="3" class="text-end"><strong><?php echo t('invoice_vat'); ?> (<?php echo $invoice['vat_rate']; ?>%)</strong></td>
                                <td class="text-end"><?php echo formatCurrency($invoice['vat_amount']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="table-primary">
                                <td colspan="3" class="text-end"><strong><?php echo t('invoice_grand_total'); ?></strong></td>
                                <td class="text-end"><strong><?php echo formatCurrency($invoice['grand_total']); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>

                    <?php if (!empty($outstandingBills)): ?>
                    <div class="alert alert-danger py-2 mb-3 mt-3">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i><?php echo $lang === 'en' ? 'Outstanding Balance' : 'ยอดค้างชำระ'; ?></h5>
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
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    <?php echo $lang === 'en' ? 'Outstanding' : 'ค้างชำระ'; ?> - <?php echo formatBillMonth($ob['bill_month'], $lang); ?>
                                </td>
                                <td class="text-center">1 <?php echo t('invoice_month_unit'); ?></td>
                                <td class="text-end"><?php echo formatCurrency($ob['total_amount']); ?></td>
                                <td class="text-end fw-bold"><?php echo formatCurrency($ob['total_amount']); ?></td>
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
                        <h5 class="mb-0"><?php echo $lang === 'en' ? 'Total Amount Due' : 'ยอดที่ต้องชำระทั้งหมด'; ?>: <?php echo formatCurrency($invoice['grand_total'] + $totalOutstanding); ?></h5>
                    </div>
                    <?php endif; ?>

                    <div class="mt-3 p-2 border rounded small">
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

                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
