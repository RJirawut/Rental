<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Handle language from URL parameter after session_start() from database.php
if (isset($_GET['lang']) && in_array($_GET['lang'], ['th', 'en'])) {
    $_SESSION['lang'] = $_GET['lang'];
}

requireLogin();

$lang = $_SESSION['lang'] ?? 'th';

$tenantId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($tenantId === 0) {
    setFlashMessage('error', 'ไม่พบข้อมูล');
    header('Location: index.php');
    exit;
}

// Get tenant details
$stmt = $pdo->prepare("SELECT mt.*, r.room_number, r.room_type_id, rt.type_name, rt.type_name_en,
    rt.price_monthly AS room_type_price_monthly
    FROM monthly_tenants mt
    JOIN rooms r ON mt.room_id = r.id
    JOIN room_types rt ON r.room_type_id = rt.id
    WHERE mt.id = ?");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    setFlashMessage('error', 'ไม่พบข้อมูล');
    header('Location: index.php');
    exit;
}

$settings = getSettings();

// Room type name based on language
$typeName = ($lang === 'en' && !empty($tenant['type_name_en'])) ? $tenant['type_name_en'] : $tenant['type_name'];

// Get billing month from URL parameter or use current month
$billingMonth = $_GET['month'] ?? date('Y-m');
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

// Check if Tax ID is configured
$hasTaxId = !empty($settings['tax_id']);
$documentTypeLabel = $hasTaxId ? t('invoice_doc_tax') : t('invoice_doc_receipt');

// Generate document number
if ($hasTaxId) {
    $invoiceNumber = generateInvoiceNumber('monthly');
} else {
    // Generate unique receipt number
    $prefix = 'REC-' . date('Ym');
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invoices WHERE invoice_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = $stmt->fetch()['count'];
    $invoiceNumber = $prefix . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
}

// Calculate due date (5th of current month if before 5th, else 5th of next month)
$currentDay = date('d');
if ($currentDay <= 5) {
    $dueDate = date('Y-m-05'); // 5th of current month
} else {
    $dueDate = date('Y-m-05', strtotime('+1 month')); // 5th of next month
}

// Get utility bill for the selected month
$stmt = $pdo->prepare("SELECT * FROM utility_bills WHERE tenant_id = ? AND bill_month = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$tenantId, $billingMonth]);
$latestBill = $stmt->fetch();

// Calculate totals
$calculatedMonthlyRent = calculateMonthlyTenantRentForMonth($tenant, $billingMonth);
$rentAmount = $latestBill ? $latestBill['rent_amount'] : $calculatedMonthlyRent;
$waterAmount = $latestBill ? $latestBill['water_amount'] : 0;
$elecAmount = $latestBill ? $latestBill['elec_amount'] : 0;
$otherFees = $latestBill ? $latestBill['other_fees'] : 0;
$discount = $latestBill ? $latestBill['discount'] : 0;
$subtotal = $rentAmount + $waterAmount + $elecAmount + $otherFees;

// Calculate VAT correctly: VAT is calculated on subtotal BEFORE discount (Thai RD requirement)
$vatRate = $hasTaxId ? 7 : 0;
$vatAmount = $hasTaxId ? $subtotal * ($vatRate / 100) : 0;
$finalTotal = $subtotal + $vatAmount - $discount;

// Check if invoice already exists for this tenant and month
$existingInvoice = null;
$billingMonthStart = $billingMonth . '-01';
$nextBillingMonthStart = date('Y-m-d', strtotime($billingMonthStart . ' +1 month'));
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE tenant_type = 'monthly' AND tenant_id = ? AND invoice_date >= ? AND invoice_date < ?");
$stmt->execute([$tenantId, $billingMonthStart, $nextBillingMonthStart]);
$existingInvoice = $stmt->fetch();

if ($existingInvoice) {
    $invoiceNumber = $existingInvoice['invoice_number'];
    // Recalculate totals based on current data, not from existing invoice
    // $subtotal, $vatAmount, $finalTotal are already calculated above
}

// Get outstanding bills before the current billing month
$outstanding = getOutstandingBills($tenantId, $billingMonth);
$outstandingBills = $outstanding['bills'];
$totalOutstanding = $outstanding['total'];

$documentTypeCode = 'full_tax';
if (!$hasTaxId) {
    $documentTypeCode = 'receipt';
} elseif (empty($tenant['customer_tax_id'])) {
    $documentTypeCode = 'abbreviated_tax';
}

$syncInvoiceItems = function ($invoiceId) use (
    $pdo,
    $tenant,
    $settings,
    $latestBill,
    $rentAmount,
    $waterAmount,
    $elecAmount,
    $otherFees,
    $discount,
    $lang,
    $typeName
) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$invoiceId]);
    $hasItems = (int) $stmt->fetchColumn() > 0;

    if ($hasItems) {
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");

    if ($rentAmount > 0) {
        $rentDesc = t('invoice_room_rent') . ' ' . $typeName . ' ' . t('invoice_room_label') . ' ' . $tenant['room_number'];
        $stmt->execute([$invoiceId, $rentDesc, 1, $rentAmount, $rentAmount]);
    }
    if ($waterAmount > 0) {
        $stmt->execute([$invoiceId, t('invoice_water'), $latestBill['water_units'] ?? 0, $latestBill['water_rate'] ?? $settings['water_rate'], $waterAmount]);
    }
    if ($elecAmount > 0) {
        $stmt->execute([$invoiceId, t('invoice_electric'), $latestBill['elec_units'] ?? 0, $latestBill['elec_rate'] ?? $settings['electric_rate'], $elecAmount]);
    }
    if ($otherFees > 0) {
        $stmt->execute([$invoiceId, t('invoice_other_fees'), 1, $otherFees, $otherFees]);
    }
    if ($discount > 0) {
        $stmt->execute([$invoiceId, t('invoice_discount'), 1, -$discount, -$discount]);
    }
};

$createInvoice = function () use (
    $pdo,
    $invoiceNumber,
    $documentTypeCode,
    $tenantId,
    $tenant,
    $billingMonthStart,
    $dueDate,
    $subtotal,
    $vatRate,
    $vatAmount,
    $discount,
    $finalTotal,
    $settings,
    $syncInvoiceItems
) {
    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, document_type, invoice_type, tenant_type, tenant_id, room_id, invoice_date, due_date, subtotal, vat_rate, vat_amount, discount, grand_total, customer_tax_id, customer_branch_code, seller_branch_code, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $invoiceNumber,
        $documentTypeCode,
        'monthly',
        'monthly',
        $tenantId,
        $tenant['room_id'],
        $billingMonthStart,
        $dueDate,
        $subtotal,
        $vatRate,
        $vatAmount,
        $discount,
        $finalTotal,
        $tenant['customer_tax_id'] ?? null,
        $tenant['customer_branch'] ?? '00000',
        $settings['branch_number'] ?? '00000',
        'issued',
        $_SESSION['user_id']
    ]);

    $invoiceId = $pdo->lastInsertId();
    $syncInvoiceItems($invoiceId);

    return $invoiceId;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireValidCsrfToken();

    if (!$existingInvoice) {
        $invoiceId = $createInvoice();
        logActivity('generate_invoice', 'invoice', $invoiceId);
        sendMonthlyReceiptEmail($tenantId, $billingMonth, 'unpaid', (int) $invoiceId);
        $existingInvoice = [
            'id' => $invoiceId,
            'invoice_number' => $invoiceNumber
        ];
    } else {
        $syncInvoiceItems($existingInvoice['id']);
    }

    if ($_POST['action'] === 'save_and_print') {
        $printType = ($_POST['print_type'] ?? 'receipt') === 'invoice' ? 'invoice' : 'receipt';
        $printLang = isset($_POST['lang']) && in_array($_POST['lang'], ['th', 'en'])
            ? $_POST['lang']
            : ($_SESSION['lang'] ?? 'th');

        $redirectUrl = 'print.php?id=' . urlencode((string) $tenantId)
            . '&month=' . urlencode($billingMonth)
            . '&invoice_id=' . urlencode((string) $existingInvoice['id'])
            . '&type=' . urlencode($printType)
            . '&lang=' . urlencode($printLang);

        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($_POST['action'] === 'save_invoice') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'invoice_id' => $existingInvoice['id'] ?? null,
            'invoice_number' => $existingInvoice['invoice_number'] ?? $invoiceNumber
        ]);
        exit;
    }
}

// Existing invoices are kept in sync when this page is viewed. New invoices are
// created by explicit POST actions or by utility bill saving.
if ($existingInvoice) {
    $syncInvoiceItems($existingInvoice['id']);
}

$documentDate = !empty($existingInvoice['created_at'])
    ? $existingInvoice['created_at']
    : (!empty($latestBill['created_at']) ? $latestBill['created_at'] : date('Y-m-d'));
$printInvoiceId = $existingInvoice['id'] ?? ($invoiceId ?? null);
$pageTitle = $documentTypeLabel . ($lang === 'en' ? ' Monthly - ' : 'รายเดือน - ') . $tenant['tenant_name'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-receipt me-2"></i><?php echo $documentTypeLabel; ?></h5>
                    <div>
                        <a href="<?php echo BASE_URL; ?>pages/monthly-tenants/print.php?id=<?php echo $tenantId; ?>&month=<?php echo urlencode($billingMonth); ?>&invoice_id=<?php echo urlencode((string) $printInvoiceId); ?>&type=receipt&lang=<?php echo urlencode($_SESSION['lang'] ?? 'th'); ?>" target="_blank" class="btn btn-sm btn-primary">
                            <i class="bi bi-printer me-1"></i><?php echo t('invoice_print'); ?>
                        </a>
                        <?php if ($hasTaxId): ?>
                        <a href="<?php echo BASE_URL; ?>pages/monthly-tenants/print.php?id=<?php echo $tenantId; ?>&month=<?php echo urlencode($billingMonth); ?>&invoice_id=<?php echo urlencode((string) $printInvoiceId); ?>&type=invoice&lang=<?php echo urlencode($_SESSION['lang'] ?? 'th'); ?>" target="_blank" class="btn btn-sm btn-info ms-2">
                            <i class="bi bi-file-text me-1"></i><?php echo t('invoice_doc_tax'); ?>
                        </a>
                        <?php endif; ?>
                        <a href="index.php" class="btn btn-sm btn-secondary ms-2"><?php echo t('invoice_back'); ?>
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
                            <h4 class="text-primary"><?php echo $documentTypeLabel; ?></h4>
                            <p class="mb-1"><strong><?php echo t('invoice_no'); ?>:</strong> <?php echo $invoiceNumber; ?></p>
                            <p class="mb-1"><strong><?php echo t('invoice_date_label'); ?>:</strong> <?php echo formatDate($documentDate, 'd/m/Y'); ?></p>
                            <p class="mb-0"><strong><?php echo t('invoice_due_label'); ?>:</strong> <?php echo formatDate($dueDate, 'd/m/Y'); ?></p>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <p class="mb-1"><?php echo t('invoice_customer'); ?>: <strong><?php echo htmlspecialchars($tenant['tenant_name']); ?></strong></p>
                            <?php if (!empty($tenant['phone'])): ?>
                            <p class="mb-0"><?php echo t('invoice_phone'); ?>: <?php echo $tenant['phone']; ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <h6><?php echo t('invoice_contract_info'); ?>:</h6>
                            <p class="mb-1"><?php echo t('invoice_room_label'); ?>: <?php echo $tenant['room_number']; ?></p>
                            <?php if (!empty($tenant['contract_start']) && !empty($tenant['contract_end'])): ?>
                            <p class="mb-1"><?php echo t('invoice_contract_period'); ?>: <?php echo formatDate($tenant['contract_start']); ?> - <?php echo formatDate($tenant['contract_end']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="alert alert-primary py-2 mb-3">
                        <h5 class="mb-0"><i class="bi bi-calendar-month me-2"></i><?php echo t('invoice_bill_for_month'); ?>: <?php echo htmlspecialchars($billingMonthDisplay); ?></h5>
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
                                <td><?php echo t('invoice_room_rent'); ?> <?php echo $typeName; ?> (<?php echo t('invoice_room_label'); ?> <?php echo $tenant['room_number']; ?>)</td>
                                <td class="text-center">1 <?php echo t('invoice_month_unit'); ?></td>
                                <td class="text-end"><?php echo formatCurrency($rentAmount); ?></td>
                                <td class="text-end"><?php echo formatCurrency($rentAmount); ?></td>
                            </tr>
                            <?php if ($waterAmount > 0): ?>
                            <tr>
                                <td><?php echo t('invoice_water'); ?></td>
                                <td class="text-center"><?php echo $latestBill['water_units'] ?? 0; ?> <?php echo t('invoice_units'); ?></td>
                                <td class="text-end"><?php echo formatCurrency($latestBill['water_rate'] ?? $settings['water_rate']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($waterAmount); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($elecAmount > 0): ?>
                            <tr>
                                <td><?php echo t('invoice_electric'); ?></td>
                                <td class="text-center"><?php echo $latestBill['elec_units'] ?? 0; ?> <?php echo t('invoice_units'); ?></td>
                                <td class="text-end"><?php echo formatCurrency($latestBill['elec_rate'] ?? $settings['electric_rate']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($elecAmount); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($otherFees > 0): ?>
                            <tr>
                                <td><?php echo t('invoice_other_fees'); ?></td>
                                <td class="text-center">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end"><?php echo formatCurrency($otherFees); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($discount > 0): ?>
                            <tr>
                                <td><?php echo t('invoice_discount'); ?></td>
                                <td class="text-center">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end text-danger">-<?php echo formatCurrency($discount); ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end"><strong><?php echo t('invoice_subtotal'); ?></strong></td>
                                <td class="text-end"><?php echo formatCurrency($subtotal); ?></td>
                            </tr>
                            <?php if ($hasTaxId && $vatAmount > 0): ?>
                            <tr>
                                <td colspan="3" class="text-end"><strong><?php echo t('invoice_vat'); ?> (<?php echo $vatRate; ?>%)</strong></td>
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
                        <h5 class="mb-0"><?php echo $lang === 'en' ? 'Total Amount Due' : 'ยอดที่ต้องชำระทั้งหมด'; ?>: <?php echo formatCurrency($finalTotal + $totalOutstanding); ?></h5>
                    </div>
                    <?php endif; ?>

                    <?php $utilityBillNotes = $latestBill ? trim($latestBill['notes'] ?? '') : ''; ?>
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
