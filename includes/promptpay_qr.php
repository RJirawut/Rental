<?php

/**
 * PromptPay QR Code Generator
 */

function generatePromptPayPayload($promptpayId, $amount = 0) {
    // 00 - Payload Format Indicator (01)
    $payload = "000201";
    
    // 01 - Point of Initiation Method (12 = dynamic with amount, 11 = static)
    $payload .= "0102" . ($amount > 0 ? "12" : "11");
    
    // 29 - Merchant Account Information (PromptPay)
    $merchantInfo = "0016A000000677010111"; // Application ID for PromptPay
    
    // Format PromptPay ID
    $promptpayId = preg_replace('/[^0-9]/', '', $promptpayId);
    
    if (strlen($promptpayId) == 10) {
        // Phone number: convert 08X to 00668X
        $promptpayId = "0066" . substr($promptpayId, 1);
        $merchantInfo .= "01" . str_pad(strlen($promptpayId), 2, "0", STR_PAD_LEFT) . $promptpayId;
    } elseif (strlen($promptpayId) == 13) {
        // National ID
        $merchantInfo .= "02" . str_pad(strlen($promptpayId), 2, "0", STR_PAD_LEFT) . $promptpayId;
    } else {
        // Fallback
        $merchantInfo .= "01" . str_pad(strlen($promptpayId), 2, "0", STR_PAD_LEFT) . $promptpayId;
    }
    
    $payload .= "29" . str_pad(strlen($merchantInfo), 2, "0", STR_PAD_LEFT) . $merchantInfo;
    
    // 53 - Transaction Currency (764 = THB)
    $payload .= "5303764";
    
    // 54 - Transaction Amount
    if ($amount > 0) {
        $amountStr = number_format($amount, 2, '.', '');
        $payload .= "54" . str_pad(strlen($amountStr), 2, "0", STR_PAD_LEFT) . $amountStr;
    }
    
    // 58 - Country Code (TH)
    $payload .= "5802TH";
    
    // 63 - CRC16 (placeholder for checksum)
    $payload .= "6304";
    
    // Calculate CRC16-CCITT
    $crc = crc16_ccitt($payload);
    $payload .= strtoupper(str_pad(dechex($crc), 4, "0", STR_PAD_LEFT));
    
    return $payload;
}

function crc16_ccitt($data) {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($data); $i++) {
        $x = (($crc >> 8) ^ ord($data[$i])) & 0xFF;
        $x ^= $x >> 4;
        $crc = (($crc << 8) ^ ($x << 12) ^ ($x << 5) ^ $x) & 0xFFFF;
    }
    return $crc;
}

function buildPaymentQRBlockHtml($settings, $amount) {
    if (empty($settings['promptpay_id'])) {
        return '';
    }

    $lang = $_SESSION['lang'] ?? 'th';
    $isEnglish = $lang === 'en';
    $amount = (float) $amount;

    $payload = generatePromptPayPayload($settings['promptpay_id'], $amount);
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($payload);

    $html = '<div class="qr-card qr-card-promptpay">';
    $html .= '<p class="qr-card-title" style="font-size: 14px; font-weight: 600; color: #2d3748; margin-bottom: 8px;">💳 '
        . ($isEnglish ? 'Payment' : 'ชำระเงิน') . '</p>';
    $html .= '<div class="qr-image-slot"><img src="' . $qrUrl . '" alt="Payment QR"></div>';
    $html .= '<p style="color: #718096; font-size: 12px; margin-top: 8px; margin-bottom: 0;">' . ($isEnglish ? 'Scan to Pay' : 'สแกนเพื่อชำระเงิน') . '</p>';
    $html .= '</div>';

    return $html;
}

function buildPaymentInstructionText(array $settings, string $lang, string $rentalType = 'monthly'): string
{
    $isEnglish = $lang === 'en';
    $bankDetails = trim(($settings['bank_name'] ?? '') . ' ' . ($settings['bank_account_number'] ?? ''));
    if (!empty($settings['bank_account_name'])) {
        $bankDetails .= ' - ' . $settings['bank_account_name'];
    }

    $bankText = $bankDetails !== ''
        ? ($isEnglish ? ' or transfer to ' . $bankDetails : ' หรือโอนเข้าบัญชี ' . $bankDetails)
        : '';

    if ($rentalType === 'daily') {
        $hours = (int) ($settings['daily_payment_deadline_hours'] ?? 24);
        $deadline = $isEnglish ? "within {$hours} hours" : "ภายใน {$hours} ชั่วโมง";
    } else {
        $day = (int) ($settings['payment_due_day'] ?? 5);
        $deadline = $isEnglish ? "by day {$day} of each month" : "ภายในวันที่ {$day} ของทุกเดือน";
    }

    return $isEnglish
        ? 'Please pay via QR Code' . $bankText . ', then submit the payment confirmation ' . $deadline . '.'
        : 'กรุณาชำระเงินผ่าน QR Code' . $bankText . ' แล้วแจ้งชำระเงิน' . $deadline;
}
