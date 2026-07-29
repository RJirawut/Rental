<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$result = processEmailQueue(10);

foreach ($result['messages'] as $message) {
    echo $message . PHP_EOL;
}

foreach ($result['errors'] as $error) {
    echo 'Failed: ' . $error . PHP_EOL;
}

echo PHP_EOL . "Summary: processed {$result['processed']}, sent {$result['success']}, failed {$result['failed']}, still pending {$result['pending']}" . PHP_EOL;
