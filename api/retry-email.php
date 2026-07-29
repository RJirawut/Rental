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
ensureEmailQueueTable();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid email ID']);
    exit;
}

// Get email from queue
$stmt = $pdo->prepare("SELECT * FROM email_queue WHERE id = ?");
$stmt->execute([$id]);
$email = $stmt->fetch();

if (!$email) {
    echo json_encode(['success' => false, 'error' => 'Email not found']);
    exit;
}

// Reset status to pending and attempts
$stmt = $pdo->prepare("UPDATE email_queue SET status = 'pending', attempts = 0, error_message = NULL, updated_at = NOW() WHERE id = ?");
$stmt->execute([$id]);

$result = processEmailQueue(1, $id);

echo json_encode([
    'success' => $result['success'] > 0 && $result['failed'] === 0,
    'message' => $result['success'] > 0 ? 'Email sent' : 'Email queued for retry',
    'result' => $result,
]);
