<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isValidCsrfToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF Token']);
    exit;
}

$result = verifySystemPin(trim($_POST['pin'] ?? ''));

if (!empty($result['http_code'])) {
    http_response_code((int) $result['http_code']);
}

echo json_encode([
    'success' => !empty($result['success']),
    'message' => $result['message'] ?? '',
    'suspended' => !empty($result['suspended']),
    'logout' => !empty($result['logout']),
    'remaining' => $result['remaining'] ?? null,
    'attempts' => $result['attempts'] ?? null,
]);
exit;
