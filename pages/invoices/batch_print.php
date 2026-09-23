<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/promptpay_qr.php';

requireLogin();

$lang = $_SESSION['lang'] ?? 'th';

$ids = isset($_GET['ids']) ? $_GET['ids'] : '';

if (empty($ids)) {
    die('ไม่พบข้อมูล');
}

$invoiceIds = array_filter(array_map('intval', explode(',', $ids)));

if (empty($invoiceIds)) {
    die('ไม่พบข้อมูล');
}

$settings = getSettings();
$hasTaxId = !empty($settings['tax_id']);
$documentTitle = $hasTaxId ? t('invoice_doc_tax') : t('invoice_doc_receipt');

// Fetch all invoices
$placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
$stmt = $pdo->prepare("SELECT i.*, r.room_number FROM invoices i JOIN rooms r ON i.room_id = r.id WHERE i.id IN ($placeholders)");
$stmt->execute($invoiceIds);
$invoices = $stmt->fetchAll();

if (empty($invoices)) {
    die('ไม่พบข้อมูล');
}

$invoiceData = [];
foreach ($invoices as $invoice) {
    // Get invoice items
    $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$invoice['id']]);
    $items = $stmt->fetchAll();
    
    // Get tenant name
    if ($invoice['tenant_type'] === 'daily') {
        $stmt = $pdo->prepare("SELECT guest_name as name, phone, status FROM daily_tenants WHERE id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT tenant_name as name, phone FROM monthly_tenants WHERE id = ?");
    }
    $stmt->execute([$invoice['tenant_id']]);
    $tenant = $stmt->fetch();

    $utilityBillNotes = '';
    if ($invoice['tenant_type'] === 'monthly' && !empty($invoice['invoice_date'])) {
        $billMonth = date('Y-m', strtotime($invoice['invoice_date']));
        $stmt = $pdo->prepare("SELECT notes FROM utility_bills WHERE tenant_id = ? AND bill_month = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$invoice['tenant_id'], $billMonth]);
        $utilityBillRow = $stmt->fetch();
        $utilityBillNotes = $utilityBillRow ? trim($utilityBillRow['notes'] ?? '') : '';
    }
    
    $invoiceData[] = [
        'invoice' => $invoice,
        'items' => $items,
        'tenant' => $tenant,
        'utility_bill_notes' => $utilityBillNotes
    ];
}

$printAllLabel = $lang === 'en' 
    ? 'Print All (' . count($invoiceData) . ' invoice(s))' 
    : 'พิมพ์ทั้งหมด (' . count($invoiceData) . ' ใบ)';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $documentTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            margin: 0;
            padding: 0;
        }
        .invoice-page {
            page-break-after: always;
            padding: 20px;
            min-height: 100vh;
        }
        .invoice-page:last-child {
            page-break-after: auto;
        }
        .qr-code-grid { margin-bottom: 0 !important; }
        .qr-code-grid > [class*="col-"] { display: flex; }
        .qr-card { background-color: #f8f9fa; border-radius: 12px; padding: 18px 14px; width: 100%; height: auto; min-height: 0; display: flex; flex-direction: column; align-items: center; text-align: center; box-sizing: border-box; }
        .qr-card > p:first-child { display: flex; align-items: flex-start; justify-content: center; width: 100%; min-height: 20px; margin-bottom: 0 !important; font-size: 14px; font-weight: 600; line-height: 1.35; color: #2d3748; }
        .qr-image-slot { display: flex; align-items: flex-start; justify-content: center; width: 100%; height: 80px; flex: 0 0 80px; }
        .qr-image-slot img { width: 80px; height: 80px; background-color: #fff; padding: 8px; border-radius: 8px; box-sizing: border-box; }
        .qr-card-caption { min-height: 32px; margin-top: 8px !important; color: #718096; font-size: 12px; line-height: 1.4; }
        @media print {
            .no-print { display: none !important; }
            .invoice-page {
                padding: 0;
            }
            body { 
                margin: 0; 
                padding: 0;
            }
        }
        @media screen {
            .invoice-page {
                background: white;
                margin: 20px auto;
                max-width: 800px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
        }
    </style>
</head>
<body>
    <div class="no-print" style="position: fixed; top: 20px; right: 20px; z-index: 1000;">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="bi bi-printer me-2"></i><?php echo $printAllLabel; ?>
        </button>
        <button onclick="window.close()" class="btn btn-secondary ms-2"><?php echo t('close'); ?></button>
    </div>
    
    <?php foreach ($invoiceData as $data): ?>
    <?php $invoice = $data['invoice']; $items = $data['items']; $tenant = $data['tenant']; $utilityBillNotes = $data['utility_bill_notes'] ?? ''; $paymentInstruction = buildPaymentInstructionText($settings, $lang, $invoice['tenant_type'] ?? 'monthly'); ?>
    <div class="invoice-page">
        <div class="container">
            <div class="row mb-4">
                <div class="col-6">
                    <?php if ($settings['logo']): ?>
                    <img src="<?php echo BASE_URL; ?>assets/images/logo/<?php echo $settings['logo']; ?>" alt="Logo" style="max-height: 60px;" class="mb-2">
                    <?php endif; ?>
                    <h5><?php echo htmlspecialchars($lang === 'en' ? ($settings['dorm_name_en'] ?? $settings['dorm_name']) : $settings['dorm_name']); ?></h5>
                    <p class="small mb-0"><?php echo nl2br(htmlspecialchars($lang === 'en' ? ($settings['address_en'] ?? $settings['address']) : $settings['address'])); ?></p>
                    <?php if ($hasTaxId): ?>
                    <p class="small"><?php echo t('invoice_tax_id_label'); ?>: <?php echo $settings['tax_id']; ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-6 text-end">
                    <h3 class="text-primary"><?php echo $documentTitle; ?></h3>
                    <p class="mb-0"><strong><?php echo t('invoice_no'); ?>:</strong> <?php echo $invoice['invoice_number']; ?></p>
                    <p class="mb-0"><strong><?php echo t('invoice_date_label'); ?>:</strong> <?php echo date('d/m/Y', strtotime($invoice['created_at'])); ?></p>
                    <p class="mb-0"><strong><?php echo t('invoice_due_label'); ?>:</strong> <?php echo formatDate($invoice['due_date']); ?></p>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-6">
                    <h6><?php echo t('invoice_customer'); ?>:</h6>
                    <p class="mb-0"><strong><?php echo htmlspecialchars($tenant['name'] ?? 'N/A'); ?></strong></p>
                    <p class="mb-0"><?php echo t('invoice_phone'); ?>: <?php echo $tenant['phone'] ?? '-'; ?></p>
                </div>
                <div class="col-6 text-end">
                    <h6><?php echo t('invoice_room_label'); ?>:</h6>
                    <p class="mb-0"><?php echo $invoice['room_number']; ?></p>
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
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['item_description']); ?></td>
                        <td class="text-center"><?php echo $item['quantity']; ?></td>
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
                    <?php if ($hasTaxId): ?>
                    <tr>
                        <td colspan="3" class="text-end"><strong><?php echo t('invoice_vat'); ?> (<?php echo $invoice['vat_rate']; ?>%)</strong></td>
                        <td class="text-end"><?php echo formatCurrency($invoice['vat_amount']); ?></td>
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
                        <td class="text-end"><strong><?php echo formatCurrency($invoice['grand_total']); ?></strong></td>
                    </tr>
                </tfoot>
            </table>

            <?php $isInvoicePaid = checkInvoicePaid($pdo, $invoice, $tenant); ?>
            <?php if ($isInvoicePaid): ?>
                <div class="row align-items-stretch qr-code-grid justify-content-center">
                    <div class="col-6 col-md-4 text-center">
                        <?php
                        $repairFormUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');
                        $repairQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($repairFormUrl);
                        ?>
                        <div class="qr-card">
                            <p>🔧 <?php echo $lang === 'en' ? 'Repair Request' : 'แจ้งซ่อมห้องพัก'; ?></p>
                            <div class="qr-image-slot"><img src="<?php echo $repairQrUrl; ?>" alt="Repair Request QR"></div>
                            <p class="qr-card-caption"><?php echo $lang === 'en' ? 'Scan to request repair' : 'สแกนเพื่อแจ้งซ่อม'; ?></p>
                        </div>
                    </div>
                </div>
            <?php elseif (!empty($settings['promptpay_id'])): ?>
                <div class="row align-items-stretch qr-code-grid">
                    <div class="col-4 text-center">
                        <?php echo buildPaymentQRBlockHtml($settings, (float) $invoice['grand_total']); ?>
                    </div>
                    <div class="col-4 text-center">
                        <?php
                        $paymentNoticeUrl = buildAbsoluteUrl(BASE_URL . 'pages/payment-notice.php');
                        $paymentNoticeQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($paymentNoticeUrl);
                        ?>
                        <div class="qr-card">
                            <p>💳 <?php echo $lang === 'en' ? 'Payment Confirmation' : 'แจ้งชำระเงิน'; ?></p>
                            <div class="qr-image-slot"><img src="<?php echo $paymentNoticeQrUrl; ?>" alt="Payment Confirmation QR"></div>
                            <p class="qr-card-caption"><?php echo $lang === 'en' ? 'Scan to submit payment slip' : 'สแกนเพื่อแจ้งชำระเงิน'; ?></p>
                        </div>
                    </div>
                    <div class="col-4 text-center">
                        <?php
                        $repairFormUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');
                        $repairQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($repairFormUrl);
                        ?>
                        <div class="qr-card">
                            <p>🔧 <?php echo $lang === 'en' ? 'Repair Request' : 'แจ้งซ่อมห้องพัก'; ?></p>
                            <div class="qr-image-slot"><img src="<?php echo $repairQrUrl; ?>" alt="Repair Request QR"></div>
                            <p class="qr-card-caption"><?php echo $lang === 'en' ? 'Scan to request repair' : 'สแกนเพื่อแจ้งซ่อม'; ?></p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row align-items-stretch qr-code-grid justify-content-center">
                    <div class="col-6 col-md-4 text-center">
                        <?php
                        $paymentNoticeUrl = buildAbsoluteUrl(BASE_URL . 'pages/payment-notice.php');
                        $paymentNoticeQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($paymentNoticeUrl);
                        ?>
                        <div class="qr-card">
                            <p>💳 <?php echo $lang === 'en' ? 'Payment Confirmation' : 'แจ้งชำระเงิน'; ?></p>
                            <div class="qr-image-slot"><img src="<?php echo $paymentNoticeQrUrl; ?>" alt="Payment Confirmation QR"></div>
                            <p class="qr-card-caption"><?php echo $lang === 'en' ? 'Scan to submit payment slip' : 'สแกนเพื่อแจ้งชำระเงิน'; ?></p>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 text-center">
                        <?php
                        $repairFormUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');
                        $repairQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($repairFormUrl);
                        ?>
                        <div class="qr-card">
                            <p>🔧 <?php echo $lang === 'en' ? 'Repair Request' : 'แจ้งซ่อมห้องพัก'; ?></p>
                            <div class="qr-image-slot"><img src="<?php echo $repairQrUrl; ?>" alt="Repair Request QR"></div>
                            <p class="qr-card-caption"><?php echo $lang === 'en' ? 'Scan to request repair' : 'สแกนเพื่อแจ้งซ่อม'; ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="small mt-2">
                <strong><?php echo t('notes'); ?>:</strong>
                <ul class="mb-0 ps-3" style="list-style-type: '- ';">
                    <?php if (!empty($utilityBillNotes)): ?>
                    <li><?php echo nl2br(htmlspecialchars($utilityBillNotes)); ?></li>
                    <?php endif; ?>
                    <li>
                        <?php echo htmlspecialchars($paymentInstruction, ENT_QUOTES, 'UTF-8'); ?><br>
                        <?php echo t('invoice_note_text'); ?><br>
                        <?php echo t('invoice_keep_receipt'); ?>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
