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

// Use submitted SMTP config (so admin can test before saving)
$smtpConfig = [
    'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
    'smtp_port'       => (int) ($_POST['smtp_port'] ?? 587),
    'smtp_username'   => trim($_POST['smtp_username'] ?? ''),
    'smtp_password'   => trim($_POST['smtp_password'] ?? ''),
    'smtp_encryption' => trim($_POST['smtp_encryption'] ?? 'tls'),
    'smtp_from_email' => trim($_POST['smtp_username'] ?? ''), // Use username as from email
    'smtp_from_name'  => '', // Will use dorm name from settings
    'dorm_name'       => '',
];

// If password is empty, use saved one from database
if (empty($smtpConfig['smtp_password'])) {
    $savedSettings = getSettings();
    $smtpConfig['smtp_password'] = $savedSettings['smtp_password'] ?? '';
    $smtpConfig['dorm_name'] = $savedSettings['dorm_name'] ?? 'Rental System';
} else {
    $savedSettings = getSettings();
    $smtpConfig['dorm_name'] = $savedSettings['dorm_name'] ?? 'Rental System';
}

// Debug: Log SMTP config (remove in production)
error_log("SMTP Test - Host: " . $smtpConfig['smtp_host']);
error_log("SMTP Test - Port: " . $smtpConfig['smtp_port']);
error_log("SMTP Test - Username: " . $smtpConfig['smtp_username']);
error_log("SMTP Test - Password length: " . strlen($smtpConfig['smtp_password']));
error_log("SMTP Test - Encryption: " . $smtpConfig['smtp_encryption']);

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
    echo json_encode(['success' => false, 'error' => 'ส่งอีเมลไม่สำเร็จ: ' . $e->getMessage()]);
}
