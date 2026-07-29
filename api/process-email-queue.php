<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireAdmin();
requireValidCsrfToken();

$limit = (int) ($_POST['limit'] ?? 10);
$result = processEmailQueue($limit);

echo json_encode([
    'success' => $result['failed'] === 0 && empty($result['errors']),
    'result' => $result,
]);
