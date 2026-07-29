<?php
require_once __DIR__ . '/promptpay_qr.php';

function getReceiptPaymentStatusMeta(string $context, string $stage): array
{
    if ($context === 'monthly') {
        if ($stage === 'paid') {
            return ['label' => t('paid'), 'bg' => '#198754', 'color' => '#ffffff'];
        }
        return ['label' => t('unpaid'), 'bg' => '#ffc107', 'color' => '#212529'];
    }

    if ($stage === 'checkout') {
        return ['label' => t('checked_out'), 'bg' => '#0d6efd', 'color' => '#ffffff'];
    }

    return ['label' => t('booking_confirmed'), 'bg' => '#198754', 'color' => '#ffffff'];
}

function buildReceiptPaymentBadgeHtml(array $meta, string $context = 'monthly'): string
{
    $label = htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8');
    $statusLabel = $meta['prefix'] ?? ($context === 'daily' ? t('booking_confirmed') : t('payment_status_label'));
    return '<div style="text-align:center;margin:0 0 20px;">'
        . '<span style="display:inline-block;padding:8px 18px;border-radius:20px;font-weight:700;font-size:14px;'
        . 'background:' . $meta['bg'] . ';color:' . $meta['color'] . ';">'
        . $statusLabel . ': ' . $label
        . '</span></div>';
}

function buildReceiptEmailShell(string $intro, string $badgeHtml, string $receiptBodyHtml, string $appName): string
{
    $introEsc = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
    $appNameEsc = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $repairFormUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');
    $repairFormLabel = 'แจ้งซ่อมห้องพัก';
    $repairFormLink = '<hr style="margin:20px 0;border:0;border-top:1px solid #eee;"><p style="margin:8px 0 0;color:#888;font-size:13px;line-height:1.6;"><a href="' . htmlspecialchars($repairFormUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#0d6efd;text-decoration:none;">' . htmlspecialchars($repairFormLabel, ENT_QUOTES, 'UTF-8') . '</a></p>';

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">
<tr><td align="center">
<table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
<tr><td style="padding:24px 28px;">
<p style="margin:0 0 16px;color:#555;font-size:15px;line-height:1.6;">{$introEsc}</p>
{$badgeHtml}
{$receiptBodyHtml}
{$repairFormLink}
</td></tr>
<tr><td style="background:#f8f9fa;padding:16px 28px;text-align:center;border-top:1px solid #eee;">
<p style="margin:0;color:#aaa;font-size:12px;">© {$appNameEsc}</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

function replaceReceiptPlaceholders(string $text, array $replacements): string
{
    foreach ($replacements as $key => $value) {
        $pattern = '/\{\s*' . preg_quote((string) $key, '/') . '\s*\}/i';
        $text = preg_replace($pattern, (string) $value, $text);
    }

    return $text;
}

function getDailyReceiptContext(int $tenantId): ?array
{
    global $pdo, $lang;

    ensureDailyTenantOtherFeesColumn();

    $stmt = $pdo->prepare("SELECT dt.*, r.room_number, rt.type_name, rt.type_name_en
        FROM daily_tenants dt
        JOIN rooms r ON dt.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id
        WHERE dt.id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();

    if (!$tenant) {
        return null;
    }

    $settings = getSettings();
    $lang = $lang ?? ($_SESSION['lang'] ?? 'th');
    $hasTaxId = !empty($settings['tax_id']);
    $typeName = ($lang === 'en' && !empty($tenant['type_name_en'])) ? $tenant['type_name_en'] : $tenant['type_name'];

    $documentType = $hasTaxId ? t('invoice_doc_tax') : t('invoice_doc_receipt');
    $documentSubType = 'full_tax';
    if (!$hasTaxId) {
        $documentSubType = 'receipt';
    } elseif (empty($tenant['customer_tax_id'])) {
        $documentSubType = 'abbreviated_tax';
        $documentType = $lang === 'en' ? 'Abbreviated Tax Invoice' : 'ใบกำกับภาษีอย่างย่อ';
    }

    $otherFees = (float) ($tenant['other_fees'] ?? 0);
    $roomAmount = (float) $tenant['daily_rate'] * (int) $tenant['total_days'];
    $baseDays = (int) $tenant['total_days'];
    $earlyCheckinDays = 0;
    $earlyCheckinCharge = 0;
    $lateCheckoutDays = 0;
    $lateCheckoutCharge = 0;

    if (!empty($tenant['actual_check_in_date']) && $tenant['actual_check_in_date'] < $tenant['check_in_date']) {
        $actualIn = new DateTime($tenant['actual_check_in_date']);
        $scheduledIn = new DateTime($tenant['check_in_date']);
        $earlyCheckinDays = $actualIn->diff($scheduledIn)->days;
        $earlyCheckinCharge = $earlyCheckinDays * (float) $tenant['daily_rate'];
    }

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

    $subtotal = $roomAmount + $otherFees + $earlyCheckinCharge + $lateCheckoutCharge;
    $vatRate = $hasTaxId ? 7 : 0;
    $vatAmount = $hasTaxId ? $subtotal * ($vatRate / 100) : 0;
    $discount = 0;

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE tenant_type = 'daily' AND tenant_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tenantId]);
    $invoice = $stmt->fetch();
    if ($invoice) {
        $discount = (float) ($invoice['discount'] ?? 0);
    }

    $grandTotal = $subtotal + $vatAmount - $discount;
    $invoiceNumber = $invoice['invoice_number'] ?? ('REC-' . date('Ym') . '-' . str_pad((string) $tenantId, 4, '0', STR_PAD_LEFT));

    $dormNameTh = !empty($settings['company_name']) ? $settings['company_name'] : ($settings['dorm_name'] ?? t('app_name'));
    $dormNameDisplay = ($lang === 'en' && !empty($settings['dorm_name_en'])) ? $settings['dorm_name_en'] : $dormNameTh;
    $address = $lang === 'en' ? ($settings['address_en'] ?? $settings['address'] ?? '') : ($settings['address'] ?? '');

    $lineItems = [[
        'description' => t('invoice_room_charge') . ' ' . $typeName . ' (' . t('invoice_room_label') . ' ' . $tenant['room_number'] . ')',
        'qty' => $baseDays . ' ' . t('invoice_days_unit'),
        'unit_price' => (float) $tenant['daily_rate'],
        'amount' => $roomAmount,
    ]];

    if ($otherFees > 0) {
        $lineItems[] = ['description' => t('other_fees'), 'qty' => '1', 'unit_price' => $otherFees, 'amount' => $otherFees];
    }
    if ($earlyCheckinDays > 0) {
        $lineItems[] = [
            'description' => t('invoice_early_checkin_charge'),
            'qty' => $earlyCheckinDays . ' ' . t('invoice_days_unit'),
            'unit_price' => (float) $tenant['daily_rate'],
            'amount' => $earlyCheckinCharge,
        ];
    }
    if ($lateCheckoutDays > 0) {
        $lineItems[] = [
            'description' => t('invoice_late_checkout_charge'),
            'qty' => $lateCheckoutDays . ' ' . t('invoice_days_unit'),
            'unit_price' => (float) $tenant['daily_rate'],
            'amount' => $lateCheckoutCharge,
        ];
    }

    return [
        'tenant' => $tenant,
        'settings' => $settings,
        'lang' => $lang,
        'has_tax_id' => $hasTaxId,
        'document_type' => $documentType,
        'document_sub_type' => $documentSubType,
        'invoice_number' => $invoiceNumber,
        'dorm_name' => $dormNameDisplay,
        'address' => $address,
        'line_items' => $lineItems,
        'subtotal' => $subtotal,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'discount' => $discount,
        'grand_total' => $grandTotal,
        'customer_name' => $tenant['guest_name'],
        'customer_phone' => $tenant['phone'],
        'customer_email' => $tenant['email'] ?? '',
        'room_number' => $tenant['room_number'],
        'period_label' => formatDate($tenant['check_in_date']) . ' - ' . formatDate($tenant['check_out_date']),
        'type_name' => $typeName,
    ];
}

function getMonthlyReceiptContext(int $tenantId, string $billMonth, ?int $invoiceId = null): ?array
{
    global $pdo, $lang;

    if (!preg_match('/^\d{4}-\d{2}$/', $billMonth)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT mt.*, r.room_number, rt.type_name, rt.type_name_en
        FROM monthly_tenants mt
        JOIN rooms r ON mt.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id
        WHERE mt.id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();

    if (!$tenant) {
        return null;
    }

    $settings = getSettings();
    $lang = $lang ?? ($_SESSION['lang'] ?? 'th');
    $billingMonthStart = $billMonth . '-01';
    $nextBillingMonthStart = date('Y-m-d', strtotime($billingMonthStart . ' +1 month'));

    $stmt = $pdo->prepare("SELECT * FROM utility_bills WHERE tenant_id = ? AND bill_month = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tenantId, $billMonth]);
    $bill = $stmt->fetch();

    $invoice = null;
    if ($invoiceId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
    }
    if (!$invoice) {
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE tenant_type = 'monthly' AND tenant_id = ? AND invoice_date >= ? AND invoice_date < ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$tenantId, $billingMonthStart, $nextBillingMonthStart]);
        $invoice = $stmt->fetch();
    }

    $hasTaxId = !empty($settings['tax_id']);
    $typeName = ($lang === 'en' && !empty($tenant['type_name_en'])) ? $tenant['type_name_en'] : $tenant['type_name'];
    $documentType = $hasTaxId ? t('invoice_doc_tax') : t('invoice_doc_receipt');

    if ($invoice) {
        $subtotal = (float) $invoice['subtotal'];
        $vatRate = (float) $invoice['vat_rate'];
        $vatAmount = (float) $invoice['vat_amount'];
        $discount = (float) ($invoice['discount'] ?? 0);
        $grandTotal = (float) $invoice['grand_total'];
        $invoiceNumber = $invoice['invoice_number'];
        $dueDate = $invoice['due_date'] ?? null;

        switch ($invoice['document_type'] ?? '') {
            case 'receipt':
                $documentType = t('invoice_doc_receipt');
                break;
            case 'abbreviated_tax':
                $documentType = $lang === 'en' ? 'Abbreviated Tax Invoice' : 'ใบกำกับภาษีอย่างย่อ';
                break;
            default:
                $documentType = t('invoice_doc_tax');
                break;
        }

        $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
        $stmt->execute([(int) $invoice['id']]);
        $invoiceItems = $stmt->fetchAll();
    } else {
        $rentAmount = $bill ? (float) $bill['rent_amount'] : (float) $tenant['monthly_rent'];
        $waterAmount = $bill ? (float) $bill['water_amount'] : 0;
        $elecAmount = $bill ? (float) $bill['elec_amount'] : 0;
        $otherFees = $bill ? (float) $bill['other_fees'] : 0;
        $discount = $bill ? (float) $bill['discount'] : 0;
        $subtotal = $rentAmount + $waterAmount + $elecAmount + $otherFees;
        $vatRate = $hasTaxId ? 7 : 0;
        $vatAmount = $hasTaxId ? $subtotal * ($vatRate / 100) : 0;
        $grandTotal = $subtotal + $vatAmount - $discount;
        $invoiceNumber = $hasTaxId ? generateInvoiceNumber('monthly') : ('REC-' . date('Ym') . '-' . str_pad((string) $tenantId, 4, '0', STR_PAD_LEFT));
        $dueDate = null;
        $invoiceItems = [];
    }

    $outstanding = getOutstandingBills($tenantId, $billMonth);
    $lineItems = [];

    if (!empty($invoiceItems)) {
        foreach ($invoiceItems as $item) {
            $desc = str_replace('ค่าอื่นๆ', 'อื่นๆ', $item['item_description']);
            $isUtilityItem = in_array($item['item_description'], ['ค่าน้ำ', 'ค่าไฟฟ้า'], true);
            $qty = ((float) $item['quantity'] == 1 && !$isUtilityItem)
                ? '1 ' . t('invoice_month_unit')
                : ((int) $item['quantity'] . ($isUtilityItem ? ' ' . t('invoice_units') : ''));
            $lineItems[] = [
                'description' => $desc,
                'qty' => $qty,
                'unit_price' => (float) $item['unit_price'],
                'amount' => (float) $item['total_price'],
                'highlight' => false,
            ];
        }
    } else {
        $rentAmount = $bill ? (float) $bill['rent_amount'] : (float) $tenant['monthly_rent'];
        $lineItems[] = [
            'description' => t('invoice_room_rent') . ' ' . $typeName . ' (' . t('invoice_room_label') . ' ' . $tenant['room_number'] . ')',
            'qty' => '1 ' . t('invoice_month_unit'),
            'unit_price' => $rentAmount,
            'amount' => $rentAmount,
            'highlight' => false,
        ];
        if ($bill && (float) $bill['water_amount'] > 0) {
            $lineItems[] = [
                'description' => t('invoice_water'),
                'qty' => (int) $bill['water_units'] . ' ' . t('invoice_units'),
                'unit_price' => (float) $bill['water_rate'],
                'amount' => (float) $bill['water_amount'],
                'highlight' => false,
            ];
        }
        if ($bill && (float) $bill['elec_amount'] > 0) {
            $lineItems[] = [
                'description' => t('invoice_electric'),
                'qty' => (int) $bill['elec_units'] . ' ' . t('invoice_units'),
                'unit_price' => (float) $bill['elec_rate'],
                'amount' => (float) $bill['elec_amount'],
                'highlight' => false,
            ];
        }
    }

    foreach ($outstanding['bills'] as $ob) {
        $lineItems[] = [
            'description' => ($lang === 'en' ? 'Outstanding' : 'ค้างชำระ') . ' - ' . formatBillMonth($ob['bill_month'], $lang),
            'qty' => '1 ' . t('invoice_month_unit'),
            'unit_price' => (float) $ob['total_amount'],
            'amount' => (float) $ob['total_amount'],
            'highlight' => true,
        ];
    }

    $totalOutstanding = (float) $outstanding['total'];
    $displayGrandTotal = empty($outstanding['bills']) ? $grandTotal : ($grandTotal + $totalOutstanding);

    $dormNameTh = $settings['dorm_name'] ?? '';
    $dormNameDisplay = $lang === 'en'
        ? (!empty($settings['dorm_name_en']) ? $settings['dorm_name_en'] : $dormNameTh)
        : $dormNameTh;
    if ($dormNameDisplay === '') {
        $dormNameDisplay = $settings['company_name'] ?? t('app_name');
    }

    return [
        'tenant' => $tenant,
        'bill' => $bill,
        'settings' => $settings,
        'lang' => $lang,
        'has_tax_id' => $hasTaxId,
        'document_type' => $documentType,
        'invoice_number' => $invoiceNumber,
        'due_date' => $dueDate,
        'dorm_name' => $dormNameDisplay,
        'address' => $lang === 'en' ? ($settings['address_en'] ?? $settings['address'] ?? '') : ($settings['address'] ?? ''),
        'line_items' => $lineItems,
        'subtotal' => $subtotal,
        'vat_rate' => $vatRate,
        'vat_amount' => $vatAmount,
        'discount' => $discount,
        'grand_total' => $displayGrandTotal,
        'has_outstanding' => !empty($outstanding['bills']),
        'customer_name' => $tenant['tenant_name'],
        'customer_phone' => $tenant['phone'],
        'customer_email' => $tenant['email'] ?? '',
        'room_number' => $tenant['room_number'],
        'billing_month' => $billMonth,
        'billing_month_display' => formatBillMonth($billMonth, $lang),
        'bill_notes' => $bill ? trim($bill['notes'] ?? '') : '',
        'paid_date' => $bill ? ($bill['paid_date'] ?? null) : null,
    ];
}

function buildReceiptDocumentBodyHtml(array $ctx, ?string $logoSrc): string
{
    $logoBlock = '';
    if (!empty($logoSrc)) {
        $logoBlock = '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" alt="Logo" style="max-height:60px;margin-bottom:8px;">';
    }

    $dormName = htmlspecialchars($ctx['dorm_name'], ENT_QUOTES, 'UTF-8');
    $address = nl2br(htmlspecialchars($ctx['address'], ENT_QUOTES, 'UTF-8'));
    $documentType = htmlspecialchars($ctx['document_type'], ENT_QUOTES, 'UTF-8');
    $invoiceNumber = htmlspecialchars($ctx['invoice_number'], ENT_QUOTES, 'UTF-8');
    $customerName = htmlspecialchars($ctx['customer_name'], ENT_QUOTES, 'UTF-8');
    $customerPhone = htmlspecialchars($ctx['customer_phone'] ?? '', ENT_QUOTES, 'UTF-8');

    $rowsHtml = '';
    foreach ($ctx['line_items'] as $item) {
        $rowStyle = !empty($item['highlight']) ? 'background:#f8d7da;' : '';
        $rowsHtml .= '<tr style="' . $rowStyle . '">'
            . '<td style="padding:8px 10px;border:1px solid #dee2e6;">' . htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px 10px;border:1px solid #dee2e6;text-align:center;">' . htmlspecialchars((string) $item['qty'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px 10px;border:1px solid #dee2e6;text-align:right;">' . formatCurrency($item['unit_price']) . '</td>'
            . '<td style="padding:8px 10px;border:1px solid #dee2e6;text-align:right;">' . formatCurrency($item['amount']) . '</td>'
            . '</tr>';
    }

    $extraInfo = '';
    if (!empty($ctx['period_label'])) {
        $extraInfo .= '<p style="margin:0 0 4px;color:#333;font-size:14px;">' . htmlspecialchars(t('invoice_stay_details'), ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($ctx['period_label'], ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if (!empty($ctx['billing_month_display'])) {
        $extraInfo .= '<p style="margin:0 0 4px;color:#333;font-size:14px;"><strong>' . htmlspecialchars(t('invoice_bill_for_month'), ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($ctx['billing_month_display'], ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if (!empty($ctx['due_date'])) {
        $extraInfo .= '<p style="margin:0 0 4px;color:#333;font-size:14px;">' . htmlspecialchars(t('invoice_due_label'), ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars(formatDate($ctx['due_date']), ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if (!empty($ctx['paid_date'])) {
        $extraInfo .= '<p style="margin:0 0 4px;color:#333;font-size:14px;">' . htmlspecialchars(t('paid_date'), ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars(formatDate($ctx['paid_date']), ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $totalsHtml = '';
    if (empty($ctx['has_outstanding'])) {
        $totalsHtml .= '<tr><td colspan="3" style="padding:8px 10px;border:1px solid #dee2e6;text-align:right;"><strong>' . htmlspecialchars(t('invoice_subtotal'), ENT_QUOTES, 'UTF-8') . '</strong></td>'
            . '<td style="padding:8px 10px;border:1px solid #dee2e6;text-align:right;">' . formatCurrency($ctx['subtotal']) . '</td></tr>';
    }
    if (!empty($ctx['discount']) && (float) $ctx['discount'] > 0) {
        $totalsHtml .= '<tr><td colspan="3" style="padding:8px 10px;border:1px solid #dee2e6;text-align:right;"><strong>' . htmlspecialchars(t('invoice_discount'), ENT_QUOTES, 'UTF-8') . '</strong></td>'
            . '<td style="padding:8px 10px;border:1px solid #dee2e6;text-align:right;">' . formatCurrency($ctx['discount']) . '</td></tr>';
    }
    if (!empty($ctx['has_tax_id'])) {
        $totalsHtml .= '<tr><td colspan="3" style="padding:8px 10px;border:1px solid #dee2e6;text-align:right;"><strong>' . htmlspecialchars(t('invoice_vat'), ENT_QUOTES, 'UTF-8') . ' ' . (float) $ctx['vat_rate'] . '%</strong></td>'
            . '<td style="padding:8px 10px;border:1px solid #dee2e6;text-align:right;">' . formatCurrency($ctx['vat_amount']) . '</td></tr>';
    }

    $grandLabel = !empty($ctx['has_outstanding'])
        ? ($ctx['lang'] === 'en' ? 'Total Amount Due' : 'ยอดที่ต้องชำระทั้งหมด')
        : t('invoice_grand_total');

    $totalsHtml .= '<tr style="background:#e7f1ff;">'
        . '<td colspan="3" style="padding:10px;border:1px solid #dee2e6;text-align:right;"><strong>' . htmlspecialchars($grandLabel, ENT_QUOTES, 'UTF-8') . '</strong></td>'
        . '<td style="padding:10px;border:1px solid #dee2e6;text-align:right;"><strong>' . formatCurrency($ctx['grand_total']) . '</strong></td></tr>';

    $notesHtml = '<p style="margin:8px 0 0;color:#666;font-size:12px;">' . htmlspecialchars(t('invoice_note_text'), ENT_QUOTES, 'UTF-8') . '</p>';
    if (!empty($ctx['bill_notes'])) {
        $notesHtml = '<p style="margin:0 0 6px;color:#333;font-size:13px;">' . nl2br(htmlspecialchars($ctx['bill_notes'], ENT_QUOTES, 'UTF-8')) . '</p>' . $notesHtml;
    }

    $taxBlock = '';
    if (!empty($ctx['has_tax_id']) && !empty($ctx['settings']['tax_id'])) {
        $taxBlock = '<p style="margin:0;color:#666;font-size:12px;">' . htmlspecialchars(t('invoice_tax_id_label'), ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($ctx['settings']['tax_id'], ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $thItem = htmlspecialchars(t('invoice_item'), ENT_QUOTES, 'UTF-8');
    $thQty = htmlspecialchars(t('invoice_qty'), ENT_QUOTES, 'UTF-8');
    $thPrice = htmlspecialchars(t('invoice_price'), ENT_QUOTES, 'UTF-8');
    $thAmount = htmlspecialchars(t('invoice_amount'), ENT_QUOTES, 'UTF-8');
    $thNotes = htmlspecialchars(t('notes'), ENT_QUOTES, 'UTF-8');

    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
<tr>
<td style="vertical-align:top;width:55%;">
{$logoBlock}
<h3 style="margin:0 0 6px;color:#333;font-size:18px;">{$dormName}</h3>
<p style="margin:0 0 4px;color:#666;font-size:12px;">{$address}</p>
{$taxBlock}
</td>
<td style="vertical-align:top;width:45%;text-align:right;">
<h2 style="margin:0 0 8px;color:#0d6efd;font-size:22px;">{$documentType}</h2>
<p style="margin:0;color:#333;font-size:14px;">{$invoiceNumber}</p>
</td>
</tr>
</table>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
<tr>
<td style="vertical-align:top;width:55%;">
<p style="margin:0 0 4px;color:#333;font-size:14px;"><strong>{$customerName}</strong></p>
<p style="margin:0;color:#666;font-size:13px;">{$customerPhone}</p>
</td>
<td style="vertical-align:top;width:45%;text-align:right;">
<p style="margin:0 0 4px;color:#333;font-size:14px;">{$extraInfo}</p>
</td>
</tr>
</table>
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
<thead>
<tr style="background:#f8f9fa;">
<th style="padding:8px 10px;border:1px solid #dee2e6;text-align:left;">{$thItem}</th>
<th style="padding:8px 10px;border:1px solid #dee2e6;text-align:center;">{$thQty}</th>
<th style="padding:8px 10px;border:1px solid #dee2e6;text-align:right;">{$thPrice}</th>
<th style="padding:8px 10px;border:1px solid #dee2e6;text-align:right;">{$thAmount}</th>
</tr>
</thead>
<tbody>
{$rowsHtml}
</tbody>
<tfoot>
{$totalsHtml}
</tfoot>
</table>
<div style="margin-top:12px;padding:10px;border:1px solid #eee;border-radius:6px;background:#fafafa;">
<strong style="font-size:13px;">{$thNotes}:</strong>
{$notesHtml}
</div>
HTML;
}

function sendReceiptEmailMessage($mail, string $toEmail, string $toName, string $subject, string $htmlBody, string $plainBody): bool
{
    if (!$mail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody;
        return $mail->send();
    } catch (Exception $e) {
        error_log('Receipt email error: ' . $e->getMessage());
        return false;
    }
}

function resolveReceiptLogoSrc($mail, ?array $settings = null): ?string
{
    if ($mail) {
        $logoSrc = attachEmailLogo($mail, $settings);
        if ($logoSrc) {
            return $logoSrc;
        }
    }

    return getEmailLogoUrl($settings);
}

function sendDailyReceiptEmail(int $tenantId, string $stage): bool
{
    global $lang;

    if (!in_array($stage, ['booking', 'checkout'], true)) {
        return false;
    }

    $ctx = getDailyReceiptContext($tenantId);
    if (!$ctx || empty($ctx['customer_email'])) {
        return false;
    }

    $settings = $ctx['settings'];
    $lang = $lang ?? ($_SESSION['lang'] ?? 'th');
    $isEnglish = $lang === 'en';
    $appName = $settings['dorm_name'] ?? t('app_name');
    
    if ($stage === 'booking') {
        $meta = ['label' => $isEnglish ? 'Paid' : 'ชำระแล้ว', 'bg' => '#198754', 'color' => '#ffffff'];
        $badgeHtml = buildReceiptPaymentBadgeHtml($meta, 'monthly'); // monthly context gives 'Payment Status: '
        
        $guestName = htmlspecialchars($ctx['customer_name'], ENT_QUOTES, 'UTF-8');
        if ($isEnglish) {
            $intro = "Dear {$guestName}, Thank you for booking with us. We have attached the system-generated receipt below.";
        } else {
            $intro = "เรียนคุณ {$guestName} ขอบคุณที่จองห้องพักกับเรา เราได้แนบใบเสร็จรับเงินจากระบบไว้ด้านล่าง";
        }
    } else {
        $meta = [
            'label' => $isEnglish ? 'Checked Out' : 'เช็คเอาท์แล้ว',
            'bg' => '#0d6efd',
            'color' => '#ffffff',
            'prefix' => $isEnglish ? 'Status' : 'สถานะ'
        ];
        $badgeHtml = buildReceiptPaymentBadgeHtml($meta);
        
        $guestName = htmlspecialchars($ctx['customer_name'], ENT_QUOTES, 'UTF-8');
        if ($isEnglish) {
            $intro = "Dear {$guestName}, Thank you for staying with us. We have attached the system-generated receipt below.";
        } else {
            $intro = "เรียนคุณ {$guestName} ขอบคุณที่ใช้บริการห้องพักของเรา เราได้แนบใบเสร็จรับเงินจากระบบไว้ด้านล่าง";
        }
    }

    $logoSrc = getEmailLogoUrl($settings);
    $receiptBody = buildReceiptDocumentBodyHtml($ctx, $logoSrc);
    $html = buildReceiptEmailShell($intro, $badgeHtml, $receiptBody, $appName);

    $settings = getSettings();
    $hasTaxId = !empty($settings['tax_id']);
    $subject = $hasTaxId ? t('receipt_email_subject_with_tax') : t('receipt_email_subject');

    $plain = $intro . "\n\n"
        . t('payment_status_label') . ': ' . $meta['label'] . "\n"
        . t('invoice_no') . ': ' . $ctx['invoice_number'] . "\n"
        . t('invoice_grand_total') . ': ' . formatCurrency($ctx['grand_total']);

    return queueEmail($ctx['customer_email'], $ctx['customer_name'], $subject, $html, $plain);
}

function sendDailyCheckInNotificationEmail(int $tenantId): bool
{
    global $pdo, $lang;

    $stmt = $pdo->prepare("SELECT dt.*, r.room_number, s.dorm_name FROM daily_tenants dt JOIN rooms r ON dt.room_id = r.id JOIN settings s ON s.id = 1 WHERE dt.id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();

    if (!$tenant || empty($tenant['email'])) {
        return false;
    }

    $settings = getSettings();
    $appName = $settings['dorm_name'] ?? t('app_name');
    $repairFormUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');

    $checkOutDate = formatDate($tenant['check_out_date']);
    $roomNumber = htmlspecialchars($tenant['room_number'], ENT_QUOTES, 'UTF-8');
    $guestName = htmlspecialchars($tenant['guest_name'], ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">
<tr><td align="center">
<table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
<tr><td style="padding:24px 28px;">
<p style="margin:0 0 16px;color:#555;font-size:15px;line-height:1.6;">เรียนคุณ {$guestName}</p>
<div style="text-align:center;margin:0 0 20px;"><span style="display:inline-block;padding:8px 18px;border-radius:20px;font-weight:700;font-size:14px;background:#0dcaf0;color:#000000;">สถานะ: เช็คอินแล้ว</span></div>
<p style="margin:0 0 16px;color:#555;font-size:15px;line-height:1.6;">ขณะนี้คุณได้ทำการเช็คอินห้องพักหมายเลข {$roomNumber} เรียบร้อยแล้ว ขอบคุณที่เลือกใช้บริการห้องพักของเรา</p>
<hr style="margin:20px 0;border:0;border-top:1px solid #eee;">
<p style="margin:8px 0 0;color:#888;font-size:13px;line-height:1.6;"><a href="{$repairFormUrl}" style="color:#0d6efd;text-decoration:none;">แจ้งซ่อมห้องพัก</a></p>
</td></tr>
<tr><td style="background:#f8f9fa;padding:16px 28px;text-align:center;border-top:1px solid #eee;">
<p style="margin:0;color:#aaa;font-size:12px;">© {$appName}</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

    $plain = "เรียนคุณ {$guestName}\n\nสถานะ: เช็คอินแล้ว\n\nขณะนี้คุณได้ทำการเช็คอินห้องพักหมายเลข {$roomNumber} เรียบร้อยแล้ว ขอบคุณที่เลือกใช้บริการห้องพักของเรา\n\nแจ้งซ่อมห้องพัก: {$repairFormUrl}\n\n© {$appName}";

    $subject = 'เช็คอินห้องพักเรียบร้อยแล้ว';

    return queueEmail($tenant['email'], $guestName, $subject, $html, $plain);
}

function sendMonthlyOutstandingBalanceNotificationEmail(int $tenantId, string $billMonth): bool
{
    global $pdo, $lang;

    $stmt = $pdo->prepare("SELECT mt.*, r.room_number, s.dorm_name FROM monthly_tenants mt JOIN rooms r ON mt.room_id = r.id JOIN settings s ON s.id = 1 WHERE mt.id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();

    if (!$tenant || empty($tenant['email'])) {
        return false;
    }

    $settings = getSettings();
    $lang = $lang ?? ($_SESSION['lang'] ?? 'th');
    $appName = $settings['dorm_name'] ?? t('app_name');
    $paymentDueDay = $settings['payment_due_day'] ?? 5;

    // Get bill details for the specific month
    $stmt = $pdo->prepare("SELECT * FROM utility_bills WHERE tenant_id = ? AND bill_month = ?");
    $stmt->execute([$tenantId, $billMonth]);
    $bill = $stmt->fetch();

    if (!$bill) {
        return false;
    }

    // Calculate original bill due date for status, and current-month due date for the email message.
    $billDate = new DateTime($bill['bill_date']);
    $billDueDate = clone $billDate;
    $billDueDate->setDate(
        (int) $billDueDate->format('Y'),
        (int) $billDueDate->format('m'),
        min((int) $paymentDueDay, (int) $billDueDate->format('t'))
    );

    $emailDueDate = new DateTime('first day of this month');
    $emailDueDate->setDate(
        (int) $emailDueDate->format('Y'),
        (int) $emailDueDate->format('m'),
        min((int) $paymentDueDay, (int) $emailDueDate->format('t'))
    );
    
    // Check if overdue
    $today = new DateTime();
    $isOverdue = $today > $billDueDate;

    // Format the month for display
    $monthDisplay = formatBillMonth($billMonth, $lang);

    $roomNumber = htmlspecialchars($tenant['room_number'], ENT_QUOTES, 'UTF-8');
    $tenantName = htmlspecialchars($tenant['tenant_name'], ENT_QUOTES, 'UTF-8');
    $waterAmount = formatCurrency($bill['water_amount']);
    $elecAmount = formatCurrency($bill['elec_amount']);
    $totalAmount = formatCurrency($bill['water_amount'] + $bill['elec_amount']);

    // Use appropriate status
    $statusLabel = $isOverdue ? 'ค้างชำระ' : 'รอชำระ';
    $statusColor = $isOverdue ? '#dc3545' : '#ffc107';
    $statusTextColor = $isOverdue ? '#ffffff' : '#212529';

    // Format due date for display
    $dueDateDisplay = formatDate($emailDueDate->format('Y-m-d'));
    
    // Replace placeholder in intro text before sending.
    $intro = replaceReceiptPlaceholders(
        t('receipt_email_intro_monthly_outstanding'),
        [
            'due_date' => $dueDateDisplay,
            'month' => $monthDisplay,
            'name' => $tenantName
        ]
    );

    $qrBlockHtml = '';
    if (!empty($settings['promptpay_id'])) {
        ensurePaymentTokenColumn();
        $token = getOrCreatePaymentToken((int)$bill['id']);
        $paymentUrl = buildAbsoluteUrl(BASE_URL . 'pages/payment.php?token=' . $token);
        $totalBillAmount = (float)$bill['water_amount'] + (float)$bill['elec_amount'];
        $qrBlockHtml = buildPaymentQRBlockHtml($settings, $totalBillAmount, $paymentUrl);
    }

    $repairFormUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');
    $repairFormLink = '<hr style="margin:20px 0;border:0;border-top:1px solid #eee;"><p style="margin:8px 0 0;color:#888;font-size:13px;line-height:1.6;"><a href="' . htmlspecialchars($repairFormUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#0d6efd;text-decoration:none;">แจ้งซ่อมห้องพัก</a></p>';

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">
<tr><td align="center">
<table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
<tr><td style="padding:24px 28px;">
<p style="margin:0 0 16px;color:#555;font-size:15px;line-height:1.6;">{\$intro}</p>
<div style="text-align:center;margin:16px 0;">
<span style="display:inline-block;padding:8px 18px;border-radius:20px;font-weight:700;font-size:14px;background:{\$statusColor};color:{\$statusTextColor};">สถานะ: {\$statusLabel}</span>
</div>
<div style="background:#f8f9fa;padding:16px;border-radius:6px;margin:16px 0;">
<p style="margin:4px 0;color:#333;font-size:14px;"><strong>ห้องพัก:</strong> {\$roomNumber}</p>
<p style="margin:4px 0;color:#333;font-size:14px;"><strong>ค่าน้ำ:</strong> {\$waterAmount}</p>
<p style="margin:4px 0;color:#333;font-size:14px;"><strong>ค่าไฟ:</strong> {\$elecAmount}</p>
<p style="margin:4px 0;color:#333;font-size:14px;"><strong>ยอดรวม:</strong> {\$totalAmount}</p>
</div>
<p style="margin:0 0 16px;color:#555;font-size:15px;line-height:1.6;">กรุณาชำระตามกำหนด</p>
{\$qrBlockHtml}
{$repairFormLink}
</td></tr>
<tr><td style="background:#f8f9fa;padding:16px 28px;text-align:center;border-top:1px solid #eee;">
<p style="margin:0;color:#aaa;font-size:12px;">© {$appName}</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

    $plain = "สวัสดีคุณ {$tenantName}\n\n{$intro}\n\nสถานะ: {$statusLabel}\n\nห้องพัก: {$roomNumber}\nค่าน้ำ: {$waterAmount}\nค่าไฟ: {$elecAmount}\nยอดรวม: {$totalAmount}\n\n© {$appName}";

    $subject = "แจ้งค่าใช้จ่ายประจำเดือน {$monthDisplay}";

    return queueEmail($tenant['email'], $tenantName, $subject, $html, $plain);
}

function sendMonthlyOutstandingBalanceNotifications(): array
{
    global $pdo;

    $results = ['sent' => 0, 'failed' => 0, 'errors' => [], 'processed' => 0];
    $startTime = microtime(true);
    $maxExecutionTime = 300; // 5 minutes max execution time

    // Get current month (first day of current month)
    $currentMonth = date('Y-m');

    // Get all unpaid bills for all active tenants in a single query (optimized)
    $stmt = $pdo->query("
        SELECT DISTINCT ub.tenant_id, ub.bill_month
        FROM utility_bills ub
        JOIN monthly_tenants mt ON mt.id = ub.tenant_id
        WHERE mt.status = 'active' AND ub.status = 'unpaid'
        ORDER BY ub.tenant_id, ub.bill_month ASC
    ");
    $unpaidBills = $stmt->fetchAll();

    $results['processed'] = count($unpaidBills);

    foreach ($unpaidBills as $bill) {
        // Check execution time to prevent timeout
        if (microtime(true) - $startTime > $maxExecutionTime) {
            $results['errors'][] = "Execution time limit reached. Processed {$results['sent']} emails successfully.";
            break;
        }

        $tenantId = $bill['tenant_id'];
        $billMonth = $bill['bill_month'];

        // Send notification for each unpaid month
        $sent = sendMonthlyOutstandingBalanceNotificationEmail($tenantId, $billMonth);
        if ($sent) {
            $results['sent']++;
        } else {
            $results['failed']++;
            $results['errors'][] = "Failed to send notification for tenant {$tenantId} for month {$billMonth}";
        }
    }

    $executionTime = round(microtime(true) - $startTime, 2);
    $results['execution_time'] = $executionTime;

    return $results;
}

function queueEmail(string $toEmail, string $toName, string $subject, string $htmlBody, ?string $plainBody = null): bool
{
    global $pdo;

    // Check if email sending is enabled
    $settings = getSettings();
    if (isset($settings['email_enabled']) && (int) $settings['email_enabled'] === 0) {
        return false; // Email sending is disabled
    }

    try {
        ensureEmailQueueTable();
        $stmt = $pdo->prepare("INSERT INTO email_queue (to_email, to_name, subject, html_body, plain_body, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $queued = $stmt->execute([$toEmail, $toName, $subject, $htmlBody, $plainBody]);
        if ($queued) {
            triggerEmailQueueWorker();
        }

        return $queued;
    } catch (PDOException $e) {
        error_log('Queue email error: ' . $e->getMessage());
        return false;
    }
}

function sendDailyPendingPaymentEmail(int $tenantId): bool
{
    global $lang;

    ensureDailyTenantPaymentDeadlineColumn();

    $ctx = getDailyReceiptContext($tenantId);
    if (!$ctx || empty($ctx['customer_email'])) {
        error_log("sendDailyPendingPaymentEmail: Context not found or no email for tenant ID $tenantId");
        return false;
    }

    $tenant = $ctx['tenant'];
    $settings = $ctx['settings'];
    $lang = $lang ?? ($_SESSION['lang'] ?? 'th');
    $appName = $settings['dorm_name'] ?? t('app_name');
    $isEnglish = $lang === 'en';
    $paymentDeadlineHours = $settings['daily_payment_deadline_hours'] ?? 24;

    $guestName = htmlspecialchars($tenant['guest_name'], ENT_QUOTES, 'UTF-8');
    $deadlineTime = formatDate($tenant['payment_deadline'], 'd/m/Y H:i');

    $statusLabel = $isEnglish ? 'Pending Payment' : 'รอชำระ';
    $meta = ['label' => $statusLabel, 'bg' => '#ffc107', 'color' => '#212529'];
    $badgeHtml = buildReceiptPaymentBadgeHtml($meta, 'monthly'); // monthly context shows 'Payment Status: ' instead of 'Booking Confirmed: '

    $logoSrc = getEmailLogoUrl($settings);
    $receiptBody = buildReceiptDocumentBodyHtml($ctx, $logoSrc);

    // Add QR Code payment block for pending daily payments
    if (!empty($settings['promptpay_id'])) {
        ensureDailyTenantPaymentTokenColumn();
        $token = getOrCreateDailyTenantPaymentToken((int) $tenantId);
        $paymentUrl = buildAbsoluteUrl(BASE_URL . 'pages/payment.php?token=' . $token);
        $qrBlock = buildPaymentQRBlockHtml($settings, $ctx['grand_total'], $paymentUrl);
        $receiptBody .= $qrBlock;
    }

    if ($isEnglish) {
        $subject = 'Payment Required - Room Booking Confirmation';
        $intro = "Dear {$guestName}, Your room booking has been created successfully. Please complete your payment within {$paymentDeadlineHours} hours to confirm your reservation.";
        $warning = "Important: If payment is not completed by {$deadlineTime}, your booking will be automatically cancelled.";
    } else {
        $subject = 'กรุณาชำระเงิน - ยืนยันการจองห้องพัก';
        $intro = "เรียนคุณ {$guestName} คุณได้ทำการจองห้องพักกับเราเรียบร้อยแล้ว กรุณาชำระเงินภายใน {$paymentDeadlineHours} ชั่วโมงเพื่อยืนยันการจอง";
        $warning = "สำคัญ: หากไม่ชำระเงินภายใน {$deadlineTime} การจองของคุณจะถูกยกเลิกอัตโนมัติ";
    }

    $warningHtml = '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:16px;margin:20px 0;"><p style="margin:0;color:#856404;font-size:14px;line-height:1.6;"><strong>⚠️ ' . htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') . '</strong></p></div>';

    $receiptBodyWithWarning = $receiptBody . $warningHtml;
    $html = buildReceiptEmailShell($intro, $badgeHtml, $receiptBodyWithWarning, $appName);

    $plain = $intro . "\n\n"
        . ($isEnglish ? 'Payment Status: ' : 'สถานะ: ') . $meta['label'] . "\n"
        . ($isEnglish ? 'Total Amount: ' : 'ยอดรวม: ') . formatCurrency($ctx['grand_total']) . "\n\n"
        . $warning . "\n\n"
        . '© ' . $appName;

    return queueEmail($ctx['customer_email'], $ctx['customer_name'], $subject, $html, $plain);
}

function sendMonthlyReceiptEmail(int $tenantId, string $billMonth, string $paymentStage, ?int $invoiceId = null): bool
{
    if (!in_array($paymentStage, ['unpaid', 'paid'], true)) {
        return false;
    }

    $ctx = getMonthlyReceiptContext($tenantId, $billMonth, $invoiceId);
    if (!$ctx || empty($ctx['customer_email'])) {
        return false;
    }

    $settings = $ctx['settings'];
    $appName = $settings['dorm_name'] ?? t('app_name');
    $meta = getReceiptPaymentStatusMeta('monthly', $paymentStage);
    $badgeHtml = buildReceiptPaymentBadgeHtml($meta);

    $logoSrc = getEmailLogoUrl($settings);
    $receiptBody = buildReceiptDocumentBodyHtml($ctx, $logoSrc);
    
    // Add QR Code payment block for unpaid bills
    if ($paymentStage === 'unpaid' && !empty($settings['promptpay_id']) && !empty($ctx['bill'])) {
        ensurePaymentTokenColumn();
        $token = getOrCreatePaymentToken((int)$ctx['bill']['id']);
        $paymentUrl = buildAbsoluteUrl(BASE_URL . 'pages/payment.php?token=' . $token);
        $qrBlock = buildPaymentQRBlockHtml($settings, $ctx['grand_total'], $paymentUrl);
        $receiptBody .= $qrBlock;
    }
    $intro = $paymentStage === 'paid'
        ? t('receipt_email_intro_monthly_paid')
        : t('receipt_email_intro_monthly_pending');

    if ($paymentStage === 'unpaid') {
        $dueDateDisplay = '';
        if (!empty($ctx['due_date'])) {
            $dueDateDisplay = formatDate($ctx['due_date']);
        } else {
            $paymentDueDay = $settings['payment_due_day'] ?? 5;
            if (!empty($ctx['bill']['bill_date'])) {
                $billDate = new DateTime($ctx['bill']['bill_date']);
                $dueDate = clone $billDate;
                $dueDate->setDate($dueDate->format('Y'), $dueDate->format('m'), $paymentDueDay);
                $dueDateDisplay = formatDate($dueDate->format('Y-m-d'));
            } else {
                $billingMonthStart = $billMonth . '-01';
                $dueDate = new DateTime($billingMonthStart);
                $dueDate->setDate($dueDate->format('Y'), $dueDate->format('m'), $paymentDueDay);
                $dueDateDisplay = formatDate($dueDate->format('Y-m-d'));
            }
        }
        $intro = replaceReceiptPlaceholders($intro, [
            'due_date' => $dueDateDisplay,
            'name' => $ctx['customer_name']
        ]);
    } else {
        $intro = replaceReceiptPlaceholders($intro, [
            'name' => $ctx['customer_name'],
            'month' => $ctx['billing_month_display']
        ]);
    }

    $html = buildReceiptEmailShell($intro, $badgeHtml, $receiptBody, $appName);

    $settings = getSettings();
    $hasTaxId = !empty($settings['tax_id']);
    $monthLabel = $ctx['billing_month_display'];
    if ($paymentStage === 'unpaid') {
        $subject = t('monthly_pending_payment_subject');
    } else {
        $subject = $hasTaxId ? t('receipt_email_subject_with_tax') : t('receipt_email_subject');
    }

    $plain = $intro . "\n\n"
        . t('payment_status_label') . ': ' . $meta['label'] . "\n"
        . t('invoice_bill_for_month') . ': ' . $monthLabel . "\n"
        . t('invoice_no') . ': ' . $ctx['invoice_number'] . "\n"
        . t('invoice_grand_total') . ': ' . formatCurrency($ctx['grand_total']);

    return queueEmail($ctx['customer_email'], $ctx['customer_name'], $subject, $html, $plain);
}
