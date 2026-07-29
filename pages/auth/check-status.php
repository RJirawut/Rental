<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

if (isLoggedIn()) {
    ensureUserSecurityColumns();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if ($user && isUserAccountActive($user)) {
        echo json_encode([
            'isLoggedIn' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role']
            ]
        ]);
    } else {
        // Stale session, clean it up
        session_unset();
        session_destroy();
        echo json_encode([
            'isLoggedIn' => false
        ]);
    }
} else {
    echo json_encode([
        'isLoggedIn' => false
    ]);
}
?>