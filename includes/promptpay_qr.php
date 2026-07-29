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

function generateQRCodeSVG($data, $size = 200) {
    // Generate QR using an external API for simplicity and best compatibility
    $encodedData = urlencode($data);
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encodedData}";
    
    return "<img src=\"{$qrUrl}\" width=\"{$size}\" height=\"{$size}\" alt=\"PromptPay QR Code\" style=\"max-width: 100%; height: auto; display: block; margin: 0 auto;\" />";
}

function generatePromptPayQRDataUri($promptpayId, $amount = 0, $size = 200) {
    $payload = generatePromptPayPayload($promptpayId, $amount);
    $encodedData = urlencode($payload);
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encodedData}";
    
    return $qrUrl;
}

function buildPaymentQRBlockHtml($settings, $amount, $paymentUrl = '') {
    if (empty($settings['promptpay_id'])) {
        return '';
    }
    
    $lang = $_SESSION['lang'] ?? 'th';
    $isEnglish = $lang === 'en';
    
    $html = '<div style="background-color: #f8f9fa; border-radius: 12px; padding: 25px; text-align: center; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; align-self: stretch;">';
    
    if (!empty($paymentUrl)) {
        // QR encodes the PAYMENT PAGE URL (not PromptPay directly)
        // This way: scan QR → opens payment page → if already paid shows "paid", if not shows PromptPay QR
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($paymentUrl);
        
        $html .= '<p style="font-size: 14px; font-weight: 600; color: #2d3748; margin-bottom: 8px;">' 
            . ($isEnglish ? '📱 Scan to Pay' : '📱 สแกนเพื่อชำระเงิน') . '</p>';
        $html .= '<img src="' . $qrUrl . '" alt="QR Code" width="80" height="80" style="background-color: #fff; padding: 8px; border-radius: 8px;">';
        
        $html .= '<p style="color: #718096; font-size: 12px; margin-bottom: 0;">' 
            . ($isEnglish ? 'Scan QR Code to pay' : 'สแกน QR Code เพื่อชำระเงิน') . '</p>';
        
    } else {
        // No payment URL: show raw PromptPay QR directly (for daily tenants etc.)
        $payload = generatePromptPayPayload($settings['promptpay_id'], $amount);
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($payload);
        
        $html .= '<img src="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/PromptPay_logo.svg/512px-PromptPay_logo.svg.png" alt="PromptPay" style="height: 24px; object-fit: contain; margin-bottom: 10px;">';
        $html .= '<br />';
        $html .= '<img src="' . $qrUrl . '" alt="QR Code" width="80" height="80" style="background-color: #fff; padding: 8px; border-radius: 8px;">';
        
        if (!empty($settings['promptpay_name'])) {
            $html .= '<div style="color: #4a5568; font-weight: 500; font-size: 14px; margin-bottom: 4px;">' . htmlspecialchars($settings['promptpay_name']) . '</div>';
        }
        $html .= '<div style="color: #718096; font-size: 12px; margin-bottom: 10px;">' . htmlspecialchars($settings['promptpay_id']) . '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

