<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
requireValidCsrfToken();

header('Content-Type: application/json');

$toEmail = trim($_POST['test_email'] ?? '');

if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'กรุณากรอกอีเมลที่ถูกต้อง']);
    exit;
}

$savedSettings = getSettings() ?: [];
$smtpFromName = trim($_POST['smtp_from_name'] ?? '');
if ($smtpFromName === '') {
    $smtpFromName = trim($savedSettings['smtp_from_name'] ?? '');
}

$smtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
if ($smtpFromEmail === '') {
    $smtpFromEmail = trim($savedSettings['smtp_from_email'] ?? '');
}

// Use submitted SMTP config (so admin can test before saving)
$smtpConfig = [
    'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
    'smtp_port'       => (int) ($_POST['smtp_port'] ?? 587),
    'smtp_username'   => trim($_POST['smtp_username'] ?? ''),
    'smtp_password'   => trim($_POST['smtp_password'] ?? ''),
    'smtp_encryption' => trim($_POST['smtp_encryption'] ?? 'tls'),
    'smtp_from_email' => $smtpFromEmail,
    'smtp_from_name'  => $smtpFromName,
    'dorm_name'       => $savedSettings['dorm_name'] ?? 'Rental System',
];

// If password is empty, use saved one from database
if (empty($smtpConfig['smtp_password'])) {
    $smtpConfig['smtp_password'] = $savedSettings['smtp_password'] ?? '';
}

if (empty($smtpConfig['smtp_host'])) {
    echo json_encode(['success' => false, 'error' => 'กรุณากรอก SMTP Host']);
    exit;
}

if (empty($smtpConfig['smtp_username'])) {
    echo json_encode(['success' => false, 'error' => 'กรุณากรอก SMTP Username']);
    exit;
}

if (empty($smtpConfig['smtp_password'])) {
    echo json_encode(['success' => false, 'error' => 'กรุณากรอก SMTP Password']);
    exit;
}

try {
    sendTestEmail($toEmail, $smtpConfig);
    echo json_encode(['success' => true, 'message' => 'ส่งอีเมลทดสอบสำเร็จ! กรุณาตรวจสอบกล่องจดหมาย']);
} catch (Exception $e) {
    error_log('SMTP test failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'ส่งอีเมลไม่สำเร็จ กรุณาตรวจสอบการตั้งค่าแล้วลองใหม่อีกครั้ง']);
}
