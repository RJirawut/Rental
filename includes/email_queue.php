<?php

function ensureEmailQueueTable(): void
{
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            to_email VARCHAR(255) NOT NULL,
            to_name VARCHAR(255) DEFAULT NULL,
            subject VARCHAR(255) NOT NULL,
            html_body MEDIUMTEXT NOT NULL,
            plain_body TEXT,
            status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            max_attempts INT NOT NULL DEFAULT 3,
            error_message TEXT,
            sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email_queue_status_created (status, created_at),
            INDEX idx_email_queue_status_attempts (status, attempts, max_attempts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $requiredColumns = [
        'to_email' => 'VARCHAR(255) NOT NULL',
        'to_name' => 'VARCHAR(255) DEFAULT NULL',
        'subject' => 'VARCHAR(255) NOT NULL',
        'html_body' => 'MEDIUMTEXT NOT NULL',
        'plain_body' => 'TEXT',
        'status' => "ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending'",
        'attempts' => 'INT NOT NULL DEFAULT 0',
        'max_attempts' => 'INT NOT NULL DEFAULT 3',
        'error_message' => 'TEXT',
        'sent_at' => 'DATETIME NULL',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM email_queue");
    $existingColumns = array_column($stmt->fetchAll(), 'Field');
    foreach ($requiredColumns as $column => $definition) {
        if (!in_array($column, $existingColumns, true)) {
            $pdo->exec("ALTER TABLE email_queue ADD COLUMN {$column} {$definition}");
        }
    }

    foreach ([
        'idx_email_queue_status_created' => 'ALTER TABLE email_queue ADD INDEX idx_email_queue_status_created (status, created_at)',
        'idx_email_queue_status_attempts' => 'ALTER TABLE email_queue ADD INDEX idx_email_queue_status_attempts (status, attempts, max_attempts)',
    ] as $indexName => $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                error_log("Email queue index {$indexName} failed: " . $e->getMessage());
            }
        }
    }

    $checked = true;
}

function fetchPendingEmailQueueItems(int $limit = 10, ?int $emailId = null): array
{
    global $pdo;

    ensureEmailQueueTable();
    $limit = max(1, min(100, $limit));

    if ($emailId !== null) {
        $stmt = $pdo->prepare("SELECT * FROM email_queue WHERE id = ? AND status = 'pending' AND attempts < max_attempts LIMIT 1");
        $stmt->execute([$emailId]);
        $email = $stmt->fetch();
        return $email ? [$email] : [];
    }

    $stmt = $pdo->prepare("SELECT * FROM email_queue WHERE status = 'pending' AND attempts < max_attempts ORDER BY created_at ASC LIMIT {$limit}");
    $stmt->execute();
    return $stmt->fetchAll();
}

function markQueuedEmailSent(int $emailId): void
{
    global $pdo;

    ensureEmailQueueTable();
    $stmt = $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), error_message = NULL, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$emailId]);
}

function markQueuedEmailFailed(array $email, string $errorMessage): string
{
    global $pdo;

    ensureEmailQueueTable();
    $newAttempts = (int) ($email['attempts'] ?? 0) + 1;
    $maxAttempts = max(1, (int) ($email['max_attempts'] ?? 3));
    $newStatus = $newAttempts >= $maxAttempts ? 'failed' : 'pending';

    $stmt = $pdo->prepare("UPDATE email_queue SET status = ?, attempts = ?, error_message = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newStatus, $newAttempts, $errorMessage, $email['id']]);

    return $newStatus;
}

/**
 * Immediately mark an email as 'failed' without consuming an attempt slot.
 * Used for validation errors (bad format / domain has no MX) where retrying
 * would never succeed.
 */
function markQueuedEmailInvalid(int $emailId, string $errorMessage): void
{
    global $pdo;

    ensureEmailQueueTable();
    $stmt = $pdo->prepare(
        "UPDATE email_queue
            SET status = 'failed', error_message = ?, updated_at = NOW()
          WHERE id = ?"
    );
    $stmt->execute([$errorMessage, $emailId]);
}

/**
 * Validate an email address:
 *   1. Check RFC format with filter_var.
 *   2. Check that the domain has at least one MX (or A/AAAA) DNS record,
 *      meaning a mail server actually exists for that domain.
 *
 * Returns an error string on failure, or null when the address looks valid.
 */
function validateEmailAddress(string $email): ?string
{
    // Step 1: format check
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Invalid email address format: {$email}";
    }

    // Step 2: DNS MX record check
    $domain = substr($email, strrpos($email, '@') + 1);

    // checkdnsrr returns true if any record of the given type exists.
    // We accept MX first, then fall back to A/AAAA (some small domains
    // skip MX and deliver directly).
    if (
        !checkdnsrr($domain, 'MX') &&
        !checkdnsrr($domain, 'A') &&
        !checkdnsrr($domain, 'AAAA')
    ) {
        return "Email domain does not exist or has no mail server (DNS lookup failed for: {$domain})";
    }

    return null; // valid
}

function buildEmailQueueResult(): array
{
    return [
        'processed' => 0,
        'success' => 0,
        'failed' => 0,
        'pending' => 0,
        'messages' => [],
        'errors' => [],
    ];
}

function acquireEmailQueueLock(int $timeoutSeconds = 0): bool
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT GET_LOCK('rental_email_queue_worker', ?)");
    $stmt->execute([$timeoutSeconds]);
    return (int) $stmt->fetchColumn() === 1;
}

function releaseEmailQueueLock(): void
{
    global $pdo;

    $pdo->query("SELECT RELEASE_LOCK('rental_email_queue_worker')");
}

function triggerEmailQueueWorker(): void
{
    $workerScript = BASE_PATH . 'scripts/email_queue_worker.php';
    if (!is_file($workerScript)) {
        return;
    }

    $logDir = BASE_PATH . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $logFile = $logDir . DIRECTORY_SEPARATOR . 'email_queue_worker.log';
    $phpPath = PHP_BINARY ?: 'php';
    $xamppPhpPath = 'C:\\xampp\\php\\php.exe';
    if (PHP_OS_FAMILY === 'Windows' && is_file($xamppPhpPath)) {
        $phpPath = $xamppPhpPath;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'start "" /B '
            . escapeshellarg($phpPath) . ' '
            . escapeshellarg($workerScript) . ' >> '
            . escapeshellarg($logFile) . ' 2>&1';
    } else {
        $command = escapeshellarg($phpPath) . ' '
            . escapeshellarg($workerScript) . ' >> '
            . escapeshellarg($logFile) . ' 2>&1 &';
    }

    $handle = @popen($command, 'r');
    if (is_resource($handle)) {
        @pclose($handle);
    }
}

function processEmailQueue(int $limit = 10, ?int $emailId = null): array
{
    $result = buildEmailQueueResult();

    if (!acquireEmailQueueLock(0)) {
        $result['messages'][] = 'Email queue worker is already running.';
        return $result;
    }

    try {
        ensureSmtpColumns();
        $settings = getSettings();
        $emails = fetchPendingEmailQueueItems($limit, $emailId);

        if (empty($emails)) {
            $result['messages'][] = 'No pending emails to send.';
            return $result;
        }

        $mail = getSmtpMailer($settings);
        if (!$mail) {
            foreach ($emails as $email) {
                $result['processed']++;
                $newStatus = markQueuedEmailFailed($email, 'SMTP is not configured.');
                $result[$newStatus === 'failed' ? 'failed' : 'pending']++;
            }

            $result['errors'][] = 'SMTP is not configured.';
            return $result;
        }

        foreach ($emails as $email) {
            $result['processed']++;

            // ── Step 1: validate email address (format + DNS MX) ──────────
            $validationError = validateEmailAddress($email['to_email']);
            if ($validationError !== null) {
                // Mark as failed immediately — no point retrying a bad address
                markQueuedEmailInvalid((int) $email['id'], $validationError);
                $result['failed']++;
                $result['errors'][] = "{$email['to_email']}: {$validationError}";
                continue;
            }

            // ── Step 2: attempt SMTP delivery ─────────────────────────────
            try {
                $mail->clearAddresses();
                $mail->clearCCs();
                $mail->clearBCCs();
                $mail->clearReplyTos();
                $mail->clearAttachments();

                $mail->addAddress($email['to_email'], $email['to_name'] ?? '');
                $mail->isHTML(true);
                $mail->Subject = $email['subject'];
                
                // Embed logo only if HTML body contains external logo URL
                $htmlBody = $email['html_body'];
                $logoUrl = getEmailLogoUrl($settings);
                if ($logoUrl && strpos($htmlBody, $logoUrl) !== false) {
                    // Only embed if logo URL is actually in the HTML
                    $logoSrc = attachEmailLogo($mail, $settings);
                    if ($logoSrc) {
                        $htmlBody = str_replace($logoUrl, $logoSrc, $htmlBody);
                    }
                }
                $mail->Body = $htmlBody;
                $mail->AltBody = $email['plain_body'] ?? '';

                if (!$mail->send()) {
                    throw new RuntimeException($mail->ErrorInfo ?: 'Unable to send email.');
                }

                markQueuedEmailSent((int) $email['id']);
                $result['success']++;
                $result['messages'][] = "Sent: {$email['to_email']}";
            } catch (Throwable $e) {
                $newStatus = markQueuedEmailFailed($email, $e->getMessage());
                $result[$newStatus === 'failed' ? 'failed' : 'pending']++;
                $result['errors'][] = "{$email['to_email']}: {$e->getMessage()}";
            }
        }
    } finally {
        releaseEmailQueueLock();
    }

    return $result;
}
