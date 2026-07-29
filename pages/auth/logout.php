<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Clear session
session_destroy();

header('Location: ' . BASE_URL . 'pages/auth/login.php');
exit;
?>