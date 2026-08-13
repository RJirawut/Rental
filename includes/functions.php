<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../assets/lang/language.php';

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Return a valid user id for foreign keys, or null if session is stale.
function getValidSessionUserId() {
    global $pdo;

    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);

    return $stmt->fetch() ? $_SESSION['user_id'] : null;
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'pages/auth/login.php');
        exit;
    }

    requireActiveAccount();
}

// Redirect if not admin
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . 'pages/dashboard.php');
        exit;
    }
}

// CSRF helpers
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfInput() {
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function isValidCsrfToken($token) {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function requireValidCsrfToken($token = null) {
    $token = $token ?? ($_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!isValidCsrfToken($token)) {
        http_response_code(403);
        $wantsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        } else {
            die('Invalid CSRF token');
        }
        exit;
    }
}

function requireApiLogin() {
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

function normalizePhoneDigits(?string $phone): string
{
    return preg_replace('/\D+/', '', (string) $phone);
}

function isValidEmailFormat(?string $email): bool
{
    $email = trim((string) $email);

    return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Sanitize input
function sanitize($data) {
    global $conn;
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitize($value);
        }
        return $data;
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Format date
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '-';
    return date($format, strtotime($date));
}

function localizedMonthNames($lang = null, $full = true) {
    $lang = $lang ?? ($_SESSION['lang'] ?? 'th');
    $key = $full ? 'full' : 'short';

    $months = [
        'th' => [
            'full' => ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'],
            'short' => ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'],
        ],
        'en' => [
            'full' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            'short' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        ],
    ];

    return $months[$lang === 'en' ? 'en' : 'th'][$key];
}

function localizedMonthName($monthNumber, $lang = null, $full = true) {
    $monthIndex = (int) $monthNumber - 1;
    $months = localizedMonthNames($lang, $full);

    return $months[$monthIndex] ?? '';
}

function normalizeMonthFilterValue($month, $default = null) {
    if ($default === null) {
        $default = date('Y-m');
    }

    if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $year = (int) substr($month, 0, 4);
        $monthNumber = (int) substr($month, 5, 2);
        if ($year >= 1900 && $year <= 2100 && $monthNumber >= 1 && $monthNumber <= 12) {
            return sprintf('%04d-%02d', $year, $monthNumber);
        }
    }

    return $default;
}

function normalizeDateFilterValue($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return '';
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));
    if (!checkdate($month, $day, $year)) {
        return '';
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function buildDateFilterValue($year, $month, $day) {
    $year = (int) $year;
    $month = (int) $month;
    $day = (int) $day;

    if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
        return '';
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function monthDateRange($month): array {
    $month = normalizeMonthFilterValue($month, '');
    if ($month === '') {
        return ['', ''];
    }

    $start = $month . '-01';
    $next = date('Y-m-d', strtotime($start . ' +1 month'));

    return [$start, $next];
}

// Format bill month (YYYY-MM) to localized month name
function formatBillMonth($billMonth, $lang = null) {
    if (empty($billMonth)) return '-';
    $billMonth = normalizeMonthFilterValue($billMonth, '');
    if ($billMonth === '') return '-';

    $parts = explode('-', $billMonth);
    $year = (int)$parts[0];
    $monthName = localizedMonthName((int) $parts[1], $lang);

    return $monthName . ' ' . $year;
}

// Format currency
function formatCurrency($amount) {
    return number_format($amount, 2);
}

/**
 * Render the shared pagination layout used by data-heavy list pages.
 */
function renderUnifiedPagination(int $page, int $totalPages, int $totalRecords, int $itemsPerPage, array $params = [], string $ariaLabel = 'Page navigation'): void
{
    if ($totalPages <= 1) {
        return;
    }

    $page = max(1, min($page, $totalPages));
    $buildUrl = static function (int $targetPage) use ($params): string {
        $query = $params;
        $query['page'] = $targetPage;
        return '?' . http_build_query($query);
    };

    $startPage = max(1, $page - 1);
    $endPage = min($totalPages, $page + 1);
    if ($totalPages > 3) {
        if ($page <= 2) {
            $startPage = 1;
            $endPage = 3;
        } elseif ($page >= $totalPages - 1) {
            $startPage = $totalPages - 2;
            $endPage = $totalPages;
        }
    }

    $fromRecord = (($page - 1) * $itemsPerPage) + 1;
    $toRecord = min($page * $itemsPerPage, $totalRecords);
    ?>
    <div class="pagination-unified d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="text-muted pagination-unified-summary">
            <?php echo t('showing'); ?> <?php echo $fromRecord; ?> - <?php echo $toRecord; ?> <?php echo t('of'); ?> <?php echo number_format($totalRecords); ?> <?php echo t('records'); ?>
        </div>
        <nav aria-label="<?php echo htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8'); ?>">
            <ul class="pagination pagination-unified-list mb-0">
                <li class="page-item pagination-unified-edge <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <?php if ($page <= 1): ?>
                        <span class="page-link"><?php echo t('previous'); ?></span>
                    <?php else: ?>
                        <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($page - 1), ENT_QUOTES, 'UTF-8'); ?>"><?php echo t('previous'); ?></a>
                    <?php endif; ?>
                </li>

                <?php for ($paginationPage = $startPage; $paginationPage <= $endPage; $paginationPage++): ?>
                    <li class="page-item <?php echo $paginationPage === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($paginationPage), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $paginationPage; ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>

                <li class="page-item pagination-unified-edge <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <?php if ($page >= $totalPages): ?>
                        <span class="page-link"><?php echo t('next'); ?></span>
                    <?php else: ?>
                        <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($page + 1), ENT_QUOTES, 'UTF-8'); ?>"><?php echo t('next'); ?></a>
                    <?php endif; ?>
                </li>
            </ul>
        </nav>
    </div>
    <?php
}

function calculateDirectorySize($directory) {
    if (!is_dir($directory)) {
        return 0;
    }

    $size = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $size += $file->getSize();
            }
        }
    } catch (Throwable $e) {
        error_log('Unable to calculate directory size: ' . $e->getMessage());
    }

    return $size;
}

function formatStorageSize($bytes) {
    $bytes = max(0, (float) $bytes);
    $megabyte = 1024 * 1024;
    $gigabyte = 1024 * $megabyte;

    // Keep the default display in MB and switch to GB near the 1 GB range.
    if ($bytes >= 900 * $megabyte) {
        return number_format($bytes / $gigabyte, 2) . ' GB';
    }

    return number_format($bytes / $megabyte, 2) . ' MB';
}

function getStorageUsage() {
    global $pdo;

    $databaseBytes = 0;
    try {
        $stmt = $pdo->query("SELECT COALESCE(SUM(data_length), 0) + COALESCE(SUM(index_length), 0) AS database_bytes FROM information_schema.tables WHERE table_schema = DATABASE()");
        $databaseBytes = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Unable to calculate database size: ' . $e->getMessage());
    }

    $uploadedFilesBytes = calculateDirectorySize(BASE_PATH . 'uploads')
        + calculateDirectorySize(BASE_PATH . 'assets/images');
    $totalBytes = $databaseBytes + $uploadedFilesBytes;

    return [
        'total_bytes' => $totalBytes,
        'database_bytes' => $databaseBytes,
        'uploaded_files_bytes' => $uploadedFilesBytes,
        'total_display' => formatStorageSize($totalBytes),
        'database_display' => formatStorageSize($databaseBytes),
        'uploaded_files_display' => formatStorageSize($uploadedFilesBytes),
    ];
}

// Generate invoice number
function generateInvoiceNumber($type) {
    global $pdo;
    $prefix = ($type === 'daily') ? 'INV-D-' : 'INV-M-';
    $yearMonth = date('Ym');
    
    $stmt = $pdo->prepare("
        SELECT MAX(CAST(RIGHT(invoice_number, 4) AS UNSIGNED)) as max_seq
        FROM invoices
        WHERE invoice_number LIKE ?
    ");
    $stmt->execute([$prefix . $yearMonth . '-%']);
    $result = $stmt->fetch();
    $nextSeq = ((int) ($result['max_seq'] ?? 0)) + 1;
    
    return $prefix . $yearMonth . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
}

// Generate receipt number for non-tax documents
function generateReceiptNumber() {
    global $pdo;
    $prefix = 'REC-' . date('Ym');

    $stmt = $pdo->prepare("
        SELECT MAX(CAST(RIGHT(invoice_number, 4) AS UNSIGNED)) as max_seq
        FROM invoices
        WHERE invoice_number LIKE ?
    ");
    $stmt->execute([$prefix . '-%']);
    $result = $stmt->fetch();
    $nextSeq = ((int) ($result['max_seq'] ?? 0)) + 1;

    return $prefix . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
}

// Create or update a monthly invoice/receipt from an existing utility bill
function syncMonthlyInvoiceFromUtilityBill($tenantId, $billMonth) {
    global $pdo;

    if (!preg_match('/^\d{4}-\d{2}$/', $billMonth)) {
        throw new InvalidArgumentException('Invalid bill month format');
    }

    $settings = getSettings();

    $stmt = $pdo->prepare("
        SELECT mt.*, r.room_number, rt.type_name
        FROM monthly_tenants mt
        JOIN rooms r ON mt.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id
        WHERE mt.id = ?
        LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();

    if (!$tenant) {
        throw new RuntimeException('Monthly tenant not found');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM utility_bills
        WHERE tenant_id = ? AND bill_month = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $billMonth]);
    $bill = $stmt->fetch();

    if (!$bill) {
        throw new RuntimeException('Utility bill not found');
    }

    $billingMonthStart = $billMonth . '-01';
    $nextBillingMonthStart = date('Y-m-d', strtotime($billingMonthStart . ' +1 month'));

    $hasTaxId = !empty($settings['tax_id']);
    $documentTypeCode = 'full_tax';
    if (!$hasTaxId) {
        $documentTypeCode = 'receipt';
    } elseif (empty($tenant['customer_tax_id'])) {
        $documentTypeCode = 'abbreviated_tax';
    }

    $rentAmount = (float) $bill['rent_amount'];
    $waterAmount = (float) $bill['water_amount'];
    $elecAmount = (float) $bill['elec_amount'];
    $otherFees = (float) $bill['other_fees'];
    $discount = (float) $bill['discount'];

    $subtotal = $rentAmount + $waterAmount + $elecAmount + $otherFees;
    $vatRate = $hasTaxId ? 7 : 0;
    $vatAmount = $hasTaxId ? $subtotal * ($vatRate / 100) : 0;
    $grandTotal = $subtotal + $vatAmount - $discount;

    // Keep the same business rule used by the invoice page.
    $currentDay = (int) date('d');
    if ($currentDay <= 5) {
        $dueDate = date('Y-m-05');
    } else {
        $dueDate = date('Y-m-05', strtotime('+1 month'));
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM invoices
        WHERE tenant_type = 'monthly'
            AND tenant_id = ?
            AND invoice_date >= ?
            AND invoice_date < ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $billingMonthStart, $nextBillingMonthStart]);
    $existingInvoice = $stmt->fetch();

    if ($existingInvoice) {
        $invoiceId = (int) $existingInvoice['id'];
        $invoiceNumber = $existingInvoice['invoice_number'];

        $stmt = $pdo->prepare("
            UPDATE invoices SET
                document_type = ?,
                room_id = ?,
                due_date = ?,
                subtotal = ?,
                vat_rate = ?,
                vat_amount = ?,
                discount = ?,
                grand_total = ?,
                customer_tax_id = ?,
                customer_branch_code = ?,
                seller_branch_code = ?,
                status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $documentTypeCode,
            $tenant['room_id'],
            $dueDate,
            $subtotal,
            $vatRate,
            $vatAmount,
            $discount,
            $grandTotal,
            $tenant['customer_tax_id'] ?? null,
            $tenant['customer_branch'] ?? '00000',
            $settings['branch_number'] ?? '00000',
            'issued',
            $invoiceId
        ]);
    } else {
        $invoiceNumber = $hasTaxId ? generateInvoiceNumber('monthly') : generateReceiptNumber();

        $stmt = $pdo->prepare("
            INSERT INTO invoices (
                invoice_number, document_type, invoice_type, tenant_type, tenant_id, room_id,
                invoice_date, due_date, subtotal, vat_rate, vat_amount, discount, grand_total,
                customer_tax_id, customer_branch_code, seller_branch_code, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoiceNumber,
            $documentTypeCode,
            'monthly',
            'monthly',
            $tenantId,
            $tenant['room_id'],
            $billingMonthStart,
            $dueDate,
            $subtotal,
            $vatRate,
            $vatAmount,
            $discount,
            $grandTotal,
            $tenant['customer_tax_id'] ?? null,
            $tenant['customer_branch'] ?? '00000',
            $settings['branch_number'] ?? '00000',
            'issued',
            getValidSessionUserId()
        ]);

        $invoiceId = (int) $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$invoiceId]);

    $stmt = $pdo->prepare("
        INSERT INTO invoice_items (invoice_id, item_description, quantity, unit_price, total_price)
        VALUES (?, ?, ?, ?, ?)
    ");

    if ($rentAmount > 0) {
        $stmt->execute([
            $invoiceId,
            'ค่าเช่าห้อง ' . $tenant['type_name'] . ' (ห้อง ' . $tenant['room_number'] . ')',
            1,
            $rentAmount,
            $rentAmount
        ]);
    }

    if ($waterAmount > 0) {
        $stmt->execute([
            $invoiceId,
            'ค่าน้ำ',
            $bill['water_units'] ?? 0,
            $bill['water_rate'] ?? $settings['water_rate'],
            $waterAmount
        ]);
    }

    if ($elecAmount > 0) {
        $stmt->execute([
            $invoiceId,
            'ค่าไฟฟ้า',
            $bill['elec_units'] ?? 0,
            $bill['elec_rate'] ?? $settings['electric_rate'],
            $elecAmount
        ]);
    }

    if ($otherFees > 0) {
        $stmt->execute([
            $invoiceId,
            'อื่นๆ',
            1,
            $otherFees,
            $otherFees
        ]);
    }

    if ($discount > 0) {
        $stmt->execute([
            $invoiceId,
            'ส่วนลด',
            1,
            -$discount,
            -$discount
        ]);
    }

    return [
        'id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'document_type' => $documentTypeCode,
        'grand_total' => $grandTotal
    ];
}

function getDailyTenantRevenueByDate($date, $roomTypeMode = null) {
    global $pdo;

    $joins = '';
    $typeFilter = '';
    $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
    $params = [$date, $nextDate, $date, $nextDate];

    if ($roomTypeMode === 'daily') {
        $joins = " JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id";
        $typeFilter = " AND (rt.type_name LIKE '%วัน%' OR rt.type_name LIKE '%daily%' OR rt.type_name LIKE '%day%')";
    } elseif ($roomTypeMode === 'monthly') {
        $joins = " JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id";
        $typeFilter = " AND (rt.type_name LIKE '%เดือน%' OR rt.type_name LIKE '%monthly%' OR rt.type_name LIKE '%month%')";
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN dt.created_at >= ? AND dt.created_at < ? THEN (dt.daily_rate * dt.total_days) ELSE 0 END), 0) as booked_amount,
            COALESCE(SUM(CASE WHEN dt.status = 'cancelled' AND dt.cancel_refunded = 1 AND dt.updated_at >= ? AND dt.updated_at < ? THEN dt.total_amount ELSE 0 END), 0) as cancelled_amount
        FROM daily_tenants dt
        {$joins}
        WHERE (dt.status IS NULL OR dt.status = '' OR dt.status != 'pending_payment') {$typeFilter}
    ");
    $stmt->execute($params);
    $result = $stmt->fetch();

    return (float) $result['booked_amount'] - (float) $result['cancelled_amount'];
}

function getDailyTenantExtraRevenueByDate($date, $roomTypeMode = null) {
    global $pdo;

    $joins = '';
    $typeFilter = '';
    $params = [$date];

    if ($roomTypeMode === 'daily') {
        $joins = " JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id";
        $typeFilter = " AND (rt.type_name LIKE '%วัน%' OR rt.type_name LIKE '%daily%' OR rt.type_name LIKE '%day%')";
    } elseif ($roomTypeMode === 'monthly') {
        $joins = " JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id";
        $typeFilter = " AND (rt.type_name LIKE '%เดือน%' OR rt.type_name LIKE '%monthly%' OR rt.type_name LIKE '%month%')";
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN dt.status = 'checked_out'
                     AND NULLIF(dt.actual_check_out_date, '') IS NOT NULL
                     AND dt.actual_check_out_date = ? THEN
                    COALESCE(dt.other_fees, 0)
                    + CASE
                        WHEN NULLIF(dt.actual_check_in_date, '') IS NOT NULL
                             AND dt.actual_check_in_date < dt.check_in_date
                        THEN DATEDIFF(dt.check_in_date, dt.actual_check_in_date) * dt.daily_rate
                        ELSE 0
                    END
                    + CASE
                        WHEN NULLIF(dt.actual_check_out_date, '') IS NOT NULL
                             AND dt.actual_check_out_date > dt.check_out_date
                        THEN DATEDIFF(dt.actual_check_out_date, dt.check_out_date) * dt.daily_rate
                        ELSE 0
                    END
                ELSE 0
            END
        ), 0) AS extra_amount
        FROM daily_tenants dt
        {$joins}
        WHERE (dt.status IS NULL OR dt.status = '' OR dt.status != 'cancelled') AND (dt.status IS NULL OR dt.status = '' OR dt.status != 'pending_payment') {$typeFilter}
    ");
    $stmt->execute($params);
    $result = $stmt->fetch();

    return (float) $result['extra_amount'];
}

function getDailyTenantRevenueByMonth($month, $roomTypeMode = null) {
    global $pdo;

    $joins = '';
    $typeFilter = '';
    [$monthStart, $nextMonthStart] = monthDateRange($month);
    $params = [$monthStart, $nextMonthStart, $monthStart, $nextMonthStart];

    if ($roomTypeMode === 'daily') {
        $joins = " JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id";
        $typeFilter = " AND (rt.type_name LIKE '%วัน%' OR rt.type_name LIKE '%daily%' OR rt.type_name LIKE '%day%')";
    } elseif ($roomTypeMode === 'monthly') {
        $joins = " JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id";
        $typeFilter = " AND (rt.type_name LIKE '%เดือน%' OR rt.type_name LIKE '%monthly%' OR rt.type_name LIKE '%month%')";
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN dt.created_at >= ? AND dt.created_at < ? THEN (dt.daily_rate * dt.total_days) ELSE 0 END), 0) as booked_amount,
            COALESCE(SUM(CASE WHEN dt.status = 'cancelled' AND dt.cancel_refunded = 1 AND dt.updated_at >= ? AND dt.updated_at < ? THEN dt.total_amount ELSE 0 END), 0) as cancelled_amount
        FROM daily_tenants dt
        {$joins}
        WHERE (dt.status IS NULL OR dt.status = '' OR dt.status != 'pending_payment') {$typeFilter}
    ");
    $stmt->execute($params);
    $result = $stmt->fetch();

    return (float) $result['booked_amount'] - (float) $result['cancelled_amount'];
}

function getDailyTenantExtraRevenueByMonth($month, $roomTypeMode = null) {
    global $pdo;

    $joins = '';
    $typeFilter = '';
    [$monthStart, $nextMonthStart] = monthDateRange($month);
    $params = [$monthStart, $nextMonthStart];

    if ($roomTypeMode === 'daily') {
        $joins = " JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id";
        $typeFilter = " AND (rt.type_name LIKE '%วัน%' OR rt.type_name LIKE '%daily%' OR rt.type_name LIKE '%day%')";
    } elseif ($roomTypeMode === 'monthly') {
        $joins = " JOIN rooms r ON dt.room_id = r.id JOIN room_types rt ON r.room_type_id = rt.id";
        $typeFilter = " AND (rt.type_name LIKE '%เดือน%' OR rt.type_name LIKE '%monthly%' OR rt.type_name LIKE '%month%')";
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN dt.status = 'checked_out'
                     AND NULLIF(dt.actual_check_out_date, '') IS NOT NULL
                     AND dt.actual_check_out_date >= ? AND dt.actual_check_out_date < ? THEN
                    COALESCE(dt.other_fees, 0)
                    + CASE
                        WHEN NULLIF(dt.actual_check_in_date, '') IS NOT NULL
                             AND dt.actual_check_in_date < dt.check_in_date
                        THEN DATEDIFF(dt.check_in_date, dt.actual_check_in_date) * dt.daily_rate
                        ELSE 0
                    END
                    + CASE
                        WHEN NULLIF(dt.actual_check_out_date, '') IS NOT NULL
                             AND dt.actual_check_out_date > dt.check_out_date
                        THEN DATEDIFF(dt.actual_check_out_date, dt.check_out_date) * dt.daily_rate
                        ELSE 0
                    END
                ELSE 0
            END
        ), 0) AS extra_amount
        FROM daily_tenants dt
        {$joins}
        WHERE (dt.status IS NULL OR dt.status = '' OR dt.status != 'cancelled') AND (dt.status IS NULL OR dt.status = '' OR dt.status != 'pending_payment') {$typeFilter}
    ");
    $stmt->execute($params);
    $result = $stmt->fetch();

    return (float) $result['extra_amount'];
}

// Calculate monthly tenant status
function calculateMonthlyStatus($contractStart, $contractEnd, $status = null) {
    $today = date('Y-m-d');
    $start = date('Y-m-d', strtotime($contractStart));
    $end = date('Y-m-d', strtotime($contractEnd));
    
    // If status is provided, use it for accurate status determination
    if ($status) {
        if ($status === 'active') {
            return ['status' => 'active', 'label' => t('contract_active'), 'class' => 'success'];
        } elseif ($status === 'pending') {
            return ['status' => 'pending', 'label' => t('contract_pending'), 'class' => 'info'];
        } elseif ($status === 'terminated') {
            return ['status' => 'terminated', 'label' => t('contract_terminated'), 'class' => 'secondary'];
        } elseif ($status === 'expired') {
            return ['status' => 'expired', 'label' => t('contract_expired'), 'class' => 'danger'];
        }
    }
    
    // Fallback to date-based calculation if no status is provided
    if ($today < $start) {
        return ['status' => 'pending', 'label' => t('contract_pending'), 'class' => 'info'];
    } elseif ($today > $end) {
        return ['status' => 'expired', 'label' => t('contract_expired'), 'class' => 'danger'];
    } else {
        $daysRemaining = (strtotime($end) - strtotime($today)) / (60 * 60 * 24);
        if ($daysRemaining <= 30) {
            return ['status' => 'expiring', 'label' => t('contract_expiring'), 'class' => 'warning'];
        }
        return ['status' => 'active', 'label' => t('contract_active'), 'class' => 'success'];
    }
}

/**
 * Check whether a room can be booked for a half-open date range [start, end).
 * The same rule is used for daily stays and monthly contracts, so a direct
 * POST cannot bypass the availability check performed by the browser.
 */
function isRoomAvailableForPeriod(int $roomId, string $startDate, string $endDate, ?int $excludeDailyId = null, ?int $excludeMonthlyId = null): bool
{
    global $pdo;

    $startDate = normalizeDateFilterValue($startDate);
    $endDate = normalizeDateFilterValue($endDate);
    if ($roomId <= 0 || $startDate === '' || $endDate === '' || $startDate >= $endDate) {
        return false;
    }

    $dailyExclusion = $excludeDailyId !== null && $excludeDailyId > 0 ? ' AND dt.id != ?' : '';
    $monthlyExclusion = $excludeMonthlyId !== null && $excludeMonthlyId > 0 ? ' AND mt.id != ?' : '';

    $sql = "
        SELECT COUNT(*)
        FROM rooms r
        WHERE r.id = ?
          AND r.status != 'maintenance'
          AND NOT EXISTS (
              SELECT 1
              FROM daily_tenants dt
              WHERE dt.room_id = r.id
                AND (dt.status IS NULL OR dt.status NOT IN ('cancelled', 'checked_out', 'no_show'))
                AND dt.check_in_date < ?
                AND dt.check_out_date > ?
                {$dailyExclusion}
          )
          AND NOT EXISTS (
              SELECT 1
              FROM monthly_tenants mt
              WHERE mt.room_id = r.id
                AND mt.status IN ('active', 'pending')
                AND mt.contract_start < ?
                AND mt.contract_end > ?
                {$monthlyExclusion}
          )
    ";

    $params = [$roomId, $endDate, $startDate];
    if ($dailyExclusion !== '') {
        $params[] = $excludeDailyId;
    }
    $params[] = $endDate;
    $params[] = $startDate;
    if ($monthlyExclusion !== '') {
        $params[] = $excludeMonthlyId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

// Get room status badge
function getRoomStatusBadge($status) {
    $badges = [
        'available' => '<span class="badge bg-success">' . t('available') . '</span>',
        'occupied' => '<span class="badge bg-danger">' . t('occupied') . '</span>',
        'maintenance' => '<span class="badge bg-warning">' . t('maintenance') . '</span>',
        'reserved' => '<span class="badge bg-info">' . t('reserved') . '</span>'
    ];
    return isset($badges[$status]) ? $badges[$status] : '<span class="badge bg-secondary">' . $status . '</span>';
}

// Flash message
function setFlashMessage($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Log activity
function logActivity($action, $entityType = null, $entityId = null, $description = null) {
    global $pdo;
    
    $userId = null;
    if (isset($_SESSION['user_id'])) {
        // Verify user exists in database before using as foreign key
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        if ($stmt->fetch()) {
            $userId = $_SESSION['user_id'];
        }
    }
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $action, $entityType, $entityId, $description, $ipAddress]);
}

// Get settings
function getSettings() {
    global $pdo;
    ensureRentalTypeColumns();
    ensureSettingsPinColumn();
    $stmt = $pdo->query("SELECT * FROM settings LIMIT 1");
    return $stmt->fetch();
}

function ensureRentalTypeColumns() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $requiredColumns = [
        'enable_daily'   => "TINYINT(1) DEFAULT 1",
        'enable_monthly' => "TINYINT(1) DEFAULT 1",
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM settings");
    $existingColumns = array_column($stmt->fetchAll(), 'Field');

    foreach ($requiredColumns as $column => $definition) {
        if (!in_array($column, $existingColumns)) {
            $pdo->exec("ALTER TABLE settings ADD COLUMN {$column} {$definition}");
        }
    }

    $checked = true;
}

function ensureSettingsPinColumn() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM settings");
    $existingColumns = array_column($stmt->fetchAll(), 'Field');

    if (!in_array('pin', $existingColumns, true)) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN pin VARCHAR(255) DEFAULT NULL AFTER primary_color");
    }

    $checked = true;
}

function ensurePasswordResetTable() {
    global $pdo;

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        ip_address VARCHAR(45),
        user_agent VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_password_resets_user_id (user_id),
        INDEX idx_password_resets_expires_at (expires_at),
        CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getTrustedAppHost(): string {
    $host = getenv('RENTAL_APP_HOST') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = trim((string) $host);

    if (!preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host)) {
        return 'localhost';
    }

    return $host;
}

function buildAbsoluteUrl($path) {
    $scheme = getenv('RENTAL_APP_SCHEME') ?: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    if (!in_array($scheme, ['http', 'https'], true)) {
        $scheme = 'http';
    }

    return $scheme . '://' . getTrustedAppHost() . '/' . ltrim($path, '/');
}

function createPasswordResetToken($userId) {
    global $pdo;

    ensurePasswordResetTable();

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
    $stmt->execute([$userId]);

    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $tokenHash, $expiresAt, $ipAddress, $userAgent]);

    return $token;
}

function getPasswordResetRequest($token) {
    global $pdo;

    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    ensurePasswordResetTable();

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("SELECT pr.*, u.username, u.email, u.full_name
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token_hash = ?
        AND pr.used_at IS NULL
        AND pr.expires_at > NOW()
        AND u.is_active = 1
        AND (u.account_status = 'active' OR u.account_status IS NULL)
        LIMIT 1");
    $stmt->execute([$tokenHash]);
    return $stmt->fetch() ?: null;
}

// Ensure SMTP columns exist in settings table (auto-migration)
function ensureSmtpColumns() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $requiredColumns = [
        'smtp_host'       => "VARCHAR(255) DEFAULT NULL",
        'smtp_port'       => "INT DEFAULT 587",
        'smtp_username'   => "VARCHAR(255) DEFAULT NULL",
        'smtp_password'   => "VARCHAR(255) DEFAULT NULL",
        'smtp_encryption' => "VARCHAR(10) DEFAULT 'tls'",
        'smtp_from_email' => "VARCHAR(255) DEFAULT NULL",
        'smtp_from_name'  => "VARCHAR(255) DEFAULT NULL",
        'email_enabled'   => "TINYINT(1) DEFAULT 1",
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM settings");
    $existingColumns = array_column($stmt->fetchAll(), 'Field');

    foreach ($requiredColumns as $column => $definition) {
        if (!in_array($column, $existingColumns)) {
            $pdo->exec("ALTER TABLE settings ADD COLUMN {$column} {$definition}");
        }
    }

    $checked = true;
}

function ensureDailyTenantOtherFeesColumn() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM daily_tenants LIKE 'other_fees'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE daily_tenants ADD COLUMN other_fees DECIMAL(10,2) DEFAULT 0 AFTER total_amount");
    }

    $checked = true;
}

function ensureDailyTenantCancelRefundColumn() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM daily_tenants LIKE 'cancel_refunded'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE daily_tenants ADD COLUMN cancel_refunded TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }

    $checked = true;
}

function ensureDailyTenantPaymentDeadlineColumn() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM daily_tenants LIKE 'payment_deadline'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE daily_tenants ADD COLUMN payment_deadline DATETIME NULL AFTER cancel_refunded");
    }

    $checked = true;
}

function ensureDailyTenantPendingPaymentStatus() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM daily_tenants LIKE 'status'");
    $column = $stmt->fetch();

    if ($column) {
        $type = $column['Type'];
        // Check if pending_payment is already in the ENUM
        if (strpos($type, 'pending_payment') === false) {
            // Modify ENUM to include pending_payment
            $pdo->exec("ALTER TABLE daily_tenants MODIFY COLUMN status ENUM('checked_in','checked_out','no_show','cancelled','pending_payment') NULL DEFAULT NULL");
        }
    }

    $checked = true;
}

function ensureDailyPaymentDeadlineHoursColumn() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM settings LIKE 'daily_payment_deadline_hours'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN daily_payment_deadline_hours INT NOT NULL DEFAULT 24 AFTER enable_monthly");
    }

    $checked = true;
}

function ensureIncomeSnapshotsTable() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS income_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            year INT NOT NULL,
            month TINYINT NULL,
            daily_income DECIMAL(12,2) DEFAULT 0,
            daily_extra_income DECIMAL(12,2) DEFAULT 0,
            monthly_income DECIMAL(12,2) DEFAULT 0,
            deposit_income DECIMAL(12,2) DEFAULT 0,
            monthly_unpaid DECIMAL(12,2) DEFAULT 0,
            held_deposit DECIMAL(12,2) DEFAULT 0,
            total_income DECIMAL(12,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_income_snapshot_month (year, month),
            INDEX idx_income_snapshots_year_month (year, month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS income_daily_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            snapshot_date DATE NOT NULL,
            year INT NOT NULL,
            month TINYINT NOT NULL,
            day TINYINT NOT NULL,
            daily_income DECIMAL(12,2) DEFAULT 0,
            daily_extra_income DECIMAL(12,2) DEFAULT 0,
            monthly_income DECIMAL(12,2) DEFAULT 0,
            deposit_income DECIMAL(12,2) DEFAULT 0,
            monthly_unpaid DECIMAL(12,2) DEFAULT 0,
            held_deposit DECIMAL(12,2) DEFAULT 0,
            total_income DECIMAL(12,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_income_daily_snapshot_date (snapshot_date),
            INDEX idx_income_daily_snapshots_year_month_day (year, month, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $requiredColumns = [
        'daily_extra_income' => 'DECIMAL(12,2) DEFAULT 0',
        'deposit_income' => 'DECIMAL(12,2) DEFAULT 0',
        'monthly_unpaid' => 'DECIMAL(12,2) DEFAULT 0',
        'held_deposit' => 'DECIMAL(12,2) DEFAULT 0',
        'total_income' => 'DECIMAL(12,2) DEFAULT 0',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM income_snapshots");
    $existingColumns = array_column($stmt->fetchAll(), 'Field');

    foreach ($requiredColumns as $column => $definition) {
        if (!in_array($column, $existingColumns)) {
            $pdo->exec("ALTER TABLE income_snapshots ADD COLUMN {$column} {$definition}");
        }
    }

    try {
        $stmt = $pdo->query("SHOW INDEX FROM income_snapshots WHERE Key_name = 'unique_income_snapshot_month'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE income_snapshots ADD UNIQUE KEY unique_income_snapshot_month (year, month)");
        }
    } catch (PDOException $e) {
        // Existing installs may already have an equivalent key or duplicate legacy snapshots.
    }

    $requiredDailyColumns = [
        'daily_extra_income' => 'DECIMAL(12,2) DEFAULT 0',
        'deposit_income' => 'DECIMAL(12,2) DEFAULT 0',
        'monthly_unpaid' => 'DECIMAL(12,2) DEFAULT 0',
        'held_deposit' => 'DECIMAL(12,2) DEFAULT 0',
        'total_income' => 'DECIMAL(12,2) DEFAULT 0',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM income_daily_snapshots");
    $existingDailyColumns = array_column($stmt->fetchAll(), 'Field');

    foreach ($requiredDailyColumns as $column => $definition) {
        if (!in_array($column, $existingDailyColumns)) {
            $pdo->exec("ALTER TABLE income_daily_snapshots ADD COLUMN {$column} {$definition}");
        }
    }

    try {
        $stmt = $pdo->query("SHOW INDEX FROM income_daily_snapshots WHERE Key_name = 'unique_income_daily_snapshot_date'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE income_daily_snapshots ADD UNIQUE KEY unique_income_daily_snapshot_date (snapshot_date)");
        }
    } catch (PDOException $e) {
        // Existing installs may already have an equivalent key or duplicate legacy snapshots.
    }

    $checked = true;
}

function cleanupExpiredPendingPayments() {
    global $pdo;

    ensureDailyTenantPaymentDeadlineColumn();
    ensurePaymentConfirmationsTable();

    // Do not remove a booking that has already submitted a payment and is
    // waiting for staff verification, even if that verification takes longer
    // than the original payment deadline.
    $stmt = $pdo->prepare("
        DELETE dt, ii, i 
        FROM daily_tenants dt
        LEFT JOIN invoices i ON i.tenant_type = 'daily' AND i.tenant_id = dt.id
        LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
        WHERE dt.status = 'pending_payment'
        AND dt.payment_deadline < NOW()
    ");
    $stmt->execute();

    return $stmt->rowCount();
}

const PIN_MAX_ATTEMPTS = 5;

function ensureUserSecurityColumns() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $requiredColumns = [
        'account_status' => "ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER is_active",
        'pin_failed_attempts' => 'INT NOT NULL DEFAULT 0 AFTER account_status',
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM users");
    $existingColumns = array_column($stmt->fetchAll(), 'Field');

    foreach ($requiredColumns as $column => $definition) {
        if (!in_array($column, $existingColumns)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
        }
    }

    $checked = true;
}

function isUserAccountActive(?array $user): bool {
    if (!$user || empty($user['is_active'])) {
        return false;
    }

    return ($user['account_status'] ?? 'active') === 'active';
}

function getSessionUserRow(): ?array {
    global $pdo;

    if (!isLoggedIn()) {
        return null;
    }

    ensureUserSecurityColumns();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function requireActiveAccount() {
    $user = getSessionUserRow();

    if (!$user || !isUserAccountActive($user)) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();

        $reason = (!$user || empty($user['is_active'])) ? '' : 'suspended';
        $query = $reason ? '?reason=' . urlencode($reason) : '';
        header('Location: ' . BASE_URL . 'pages/auth/login.php' . $query);
        exit;
    }
}

function suspendUserAccount(int $userId, string $reason = 'pin_failures'): bool {
    global $pdo;

    ensureUserSecurityColumns();

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || ($user['account_status'] ?? 'active') === 'suspended') {
        return false;
    }

    $stmt = $pdo->prepare("UPDATE users SET account_status = 'suspended', pin_failed_attempts = ? WHERE id = ?");
    $stmt->execute([PIN_MAX_ATTEMPTS, $userId]);

    $user['account_status'] = 'suspended';
    sendAccountSuspendedEmail($user, $reason);
    logActivity('suspend_user_account', 'user', $userId, $reason);

    return true;
}

function getEmailLogoFilePath(?array $settings = null): ?string
{
    if (!$settings) {
        $settings = getSettings();
    }

    $logo = basename($settings['logo'] ?? '');
    if ($logo === '') {
        return null;
    }

    $path = LOGO_PATH . $logo;

    return file_exists($path) ? $path : null;
}

function getEmailLogoUrl(?array $settings = null): ?string
{
    if (!getEmailLogoFilePath($settings)) {
        return null;
    }

    if (!$settings) {
        $settings = getSettings();
    }

    return buildAbsoluteUrl(BASE_URL . 'assets/images/logo/' . rawurlencode(basename($settings['logo'])));
}

function attachEmailLogo($mail, ?array $settings = null): ?string
{
    $path = getEmailLogoFilePath($settings);
    if (!$path) {
        return null;
    }

    try {
        $mail->addEmbeddedImage($path, 'email_logo', basename($path));
        return 'cid:email_logo';
    } catch (Exception $e) {
        error_log('Email logo embed error: ' . $e->getMessage());
        return getEmailLogoUrl($settings);
    }
}

function buildEmailLogoHeaderHtml(?string $logoSrc, string $appName, string $titleHtml = '', string $headerStyle = ''): string
{
    $appNameEsc = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $style = $headerStyle !== ''
        ? $headerStyle
        : 'background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:28px 40px;color:#fff;text-align:center;';

    $logoBlock = '';
    if (!empty($logoSrc)) {
        $logoBlock = '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" alt="' . $appNameEsc . '" style="max-height:70px;max-width:220px;margin:0 auto 12px;display:block;">';
    }

    return <<<HTML
<tr><td style="{$style}">
{$logoBlock}
<p style="margin:0;font-size:18px;font-weight:600;opacity:0.95;">{$appNameEsc}</p>
{$titleHtml}
</td></tr>
HTML;
}

function buildAccountStatusEmailHtml(array $user, string $appName, bool $isEnglish, string $type, ?string $logoSrc = null): string {
    $fullName = htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $username = htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8');

    if ($type === 'suspended') {
        $title = $isEnglish ? 'Account Suspended' : 'บัญชีถูกระงับการใช้งาน';
        $lead = $isEnglish
            ? 'Your administrator account has been suspended because the security PIN was entered incorrectly too many times.'
            : 'บัญชีผู้ดูแลระบบของคุณถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบหลัก เพื่อปลดล็อก';
        $detail = $isEnglish
            ? 'You cannot sign in or request a password reset until an administrator restores your account to active status.'
            : 'คุณไม่สามารถเข้าสู่ระบบหรือขอรีเซ็ตรหัสผ่านได้ จนกว่าผู้ดูแลระบบจะเปิดใช้งานบัญชีอีกครั้ง';
    } else {
        $title = $isEnglish ? 'Account Reactivated' : 'บัญชีกลับมาใช้งานได้แล้ว';
        $lead = $isEnglish
            ? 'Your administrator account has been restored to active status.'
            : 'บัญชีผู้ดูแลระบบของคุณกลับมาใช้งานได้ตามปกติแล้ว กรุณาตรวจสอบการเข้าสู่ระบบ';
        $detail = $isEnglish
            ? 'You can sign in and use the system again.'
            : 'คุณสามารถเข้าสู่ระบบและใช้งานระบบได้อีกครั้ง';
    }

    $titleHtml = '<h1 style="margin:12px 0 0;font-size:22px;font-weight:700;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    $headerHtml = buildEmailLogoHeaderHtml($logoSrc, $appName, $titleHtml);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:30px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.08);">
{$headerHtml}
<tr><td style="padding:32px 40px;color:#333;line-height:1.7;">
<p style="margin:0 0 12px;">{$fullName} ({$username})</p>
<p style="margin:0 0 12px;">{$lead}</p>
<p style="margin:0;">{$detail}</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

function sendAccountStatusEmail(array $user, string $type, string $reason = ''): bool {
    global $lang;

    if (empty($user['email'])) {
        return false;
    }

    $settings = getSettings();
    $appName = $settings['dorm_name'] ?? t('app_name');
    $isEnglish = ($lang ?? 'th') === 'en';

    if ($type === 'suspended') {
        $subject = $isEnglish ? 'Account suspended' : 'บัญชีถูกระงับการใช้งาน';
    } else {
        $subject = $isEnglish ? 'Account reactivated' : 'บัญชีกลับมาใช้งานได้แล้ว';
    }

    $logoSrc = getEmailLogoUrl($settings);
    $html = buildAccountStatusEmailHtml($user, $appName, $isEnglish, $type, $logoSrc);
    $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '<p>'], ["\n", "\n", "\n", "\n\n", ''], $html));

    return queueEmail($user['email'], $user['full_name'] ?? '', $subject, $html, $plain);
}

function sendAccountSuspendedEmail(array $user, string $reason = 'pin_failures'): bool {
    return sendAccountStatusEmail($user, 'suspended', $reason);
}

function sendAccountReactivatedEmail(array $user): bool {
    return sendAccountStatusEmail($user, 'reactivated');
}

function buildNewUserWelcomeHtml(array $user, string $plainPassword, string $loginUrl, string $appName, bool $isEnglish, ?string $logoSrc = null): string
{
    $fullName = htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $username = htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8');
    $password = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
    $loginUrlEsc = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $appNameEsc = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');

    if ($isEnglish) {
        $title = 'Member account added';
        $greeting = "Hello, {$fullName}";
        $message = "An administrator has added a member account for <strong>{$appNameEsc}</strong>. Use the credentials below to sign in.";
        $usernameLabel = 'Username';
        $passwordLabel = 'Password';
        $buttonText = 'Sign In';
        $securityNote = 'For your security, please change your password after your first login.';
        $linkFallback = "If the button above doesn't work, copy and paste this link into your browser:";
    } else {
        $title = 'เพิ่มบัญชีสมาชิกแล้ว';
        $greeting = "สวัสดี คุณ{$fullName}";
        $message = "ผู้ดูแลระบบได้เพิ่มบัญชีสมาชิก กรุณาตรวจสอบการเข้าสู่ระบบ โดยใช้ข้อมูลด้านล่าง";
        $usernameLabel = 'ชื่อผู้ใช้';
        $passwordLabel = 'รหัสผ่าน';
        $buttonText = 'เข้าสู่ระบบ';
        $securityNote = 'เพื่อความปลอดภัย กรุณาเปลี่ยนรหัสผ่านหลังเข้าใช้งานครั้งแรก';
        $linkFallback = 'หากปุ่มด้านบนไม่ทำงาน กรุณาคัดลอกลิงก์นี้ไปวางในเบราว์เซอร์:';
    }

    $titleHtml = '<h1 style="margin:12px 0 0;font-size:22px;font-weight:700;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    $headerHtml = buildEmailLogoHeaderHtml($logoSrc, $appName, $titleHtml);

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
    {$headerHtml}
    <tr>
        <td style="padding:40px;">
            <h2 style="color:#333;margin:0 0 20px;font-size:20px;font-weight:600;">{$greeting}</h2>
            <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 24px;">{$message}</p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;margin:0 0 28px;">
                <tr>
                    <td style="padding:20px 24px;color:#333;font-size:15px;line-height:1.8;">
                        <div><span style="color:#666;">{$usernameLabel}:</span> <strong style="font-size:16px;">{$username}</strong></div>
                        <div style="margin-top:8px;"><span style="color:#666;">{$passwordLabel}:</span> <strong style="font-size:16px;">{$password}</strong></div>
                    </td>
                </tr>
            </table>
            <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 24px;">
                <a href="{$loginUrlEsc}" style="display:inline-block;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:8px;font-size:16px;font-weight:600;letter-spacing:0.5px;box-shadow:0 4px 15px rgba(102,126,234,0.4);">{$buttonText}</a>
            </td></tr>
            </table>
            <p style="color:#888;font-size:13px;line-height:1.6;margin:0 0 15px;">{$securityNote}</p>
            <hr style="border:none;border-top:1px solid #eee;margin:25px 0;">
            <p style="color:#aaa;font-size:12px;line-height:1.5;margin:0 0 8px;">{$linkFallback}</p>
            <p style="word-break:break-all;color:#667eea;font-size:12px;margin:0;"><a href="{$loginUrlEsc}" style="color:#667eea;">{$loginUrlEsc}</a></p>
        </td>
    </tr>
    <tr>
        <td style="background-color:#f8f9fa;padding:20px 40px;text-align:center;border-top:1px solid #eee;">
            <p style="color:#aaa;font-size:12px;margin:0;">© {$appName}</p>
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

function sendNewUserWelcomeEmail(array $user, string $plainPassword): bool
{
    global $lang;

    if (empty($user['email']) || $plainPassword === '') {
        return false;
    }

    $settings = getSettings();
    $appName = $settings['dorm_name'] ?? t('app_name');
    $isEnglish = ($lang ?? 'th') === 'en';
    $loginUrl = buildAbsoluteUrl(BASE_URL . 'pages/auth/login.php');
    $subject = $isEnglish ? 'Your account credentials' : 'ข้อมูลบัญชีผู้ใช้ของคุณ';

    $logoSrc = getEmailLogoUrl($settings);
    $html = buildNewUserWelcomeHtml($user, $plainPassword, $loginUrl, $appName, $isEnglish, $logoSrc);
    $plain = $isEnglish
        ? "Hello {$user['full_name']},\n\nAn administrator has added a member account for {$appName}.\n\nUsername: {$user['username']}\nPassword: {$plainPassword}\n\nSign in: {$loginUrl}\n\nPlease change your password after your first login."
        : "สวัสดี {$user['full_name']}\n\nผู้ดูแลระบบได้เพิ่มบัญชีสมาชิก {$appName}\n\nชื่อผู้ใช้: {$user['username']}\nรหัสผ่าน: {$plainPassword}\n\nเข้าสู่ระบบ: {$loginUrl}\n\nกรุณาเปลี่ยนรหัสผ่านหลังเข้าใช้งานครั้งแรก";

    return queueEmail($user['email'], $user['full_name'] ?? '', $subject, $html, $plain);
}

function destroyCurrentSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

function verifySystemPin(string $pin): array {
    global $pdo;

    ensureUserSecurityColumns();

    if (!isLoggedIn()) {
        return [
            'success' => false,
            'message' => t('unauthorized'),
            'http_code' => 401,
        ];
    }

    $user = getSessionUserRow();
    if (!$user || !isUserAccountActive($user)) {
        return [
            'success' => false,
            'message' => t('account_suspended_login'),
            'suspended' => true,
            'logout' => true,
        ];
    }

    if ($pin === '') {
        return [
            'success' => false,
            'message' => t('please_enter_pin'),
        ];
    }

    $settings = getSettings();
    $dbPin = $settings['pin'] ?? null;

    if (empty($dbPin)) {
        $_SESSION['admin_pin_verified'] = true;
        return ['success' => true];
    }

    $pinValid = password_verify($pin, $dbPin) || $pin === $dbPin;

    if ($pinValid) {
        $pinInfo = password_get_info($dbPin);
        if (($pinInfo['algo'] ?? 0) === 0 || password_needs_rehash($dbPin, PASSWORD_DEFAULT)) {
            $stmt = $pdo->prepare("UPDATE settings SET pin = ? WHERE id = 1");
            $stmt->execute([password_hash($pin, PASSWORD_DEFAULT)]);
        }

        $stmt = $pdo->prepare("UPDATE users SET pin_failed_attempts = 0 WHERE id = ?");
        $stmt->execute([$user['id']]);
        $_SESSION['admin_pin_verified'] = true;

        return ['success' => true];
    }

    $attempts = (int) ($user['pin_failed_attempts'] ?? 0) + 1;
    $stmt = $pdo->prepare("UPDATE users SET pin_failed_attempts = ? WHERE id = ?");
    $stmt->execute([$attempts, $user['id']]);

    if ($attempts >= PIN_MAX_ATTEMPTS) {
        suspendUserAccount((int) $user['id'], 'pin_failures');
        destroyCurrentSession();

        return [
            'success' => false,
            'message' => t('account_suspended_pin'),
            'suspended' => true,
            'logout' => true,
            'attempts' => $attempts,
        ];
    }

    $remaining = PIN_MAX_ATTEMPTS - $attempts;

    return [
        'success' => false,
        'message' => sprintf(t('pin_incorrect_remaining'), $remaining),
        'attempts' => $attempts,
        'remaining' => $remaining,
    ];
}

function ensureUtilityBillMeterResetColumns() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return;
    }

    $requiredColumns = [
        'water_old_meter_final' => 'INT DEFAULT NULL AFTER water_amount',
        'water_carryover_units' => 'INT DEFAULT 0 AFTER water_old_meter_final',
        'elec_old_meter_final'  => 'INT DEFAULT NULL AFTER elec_amount',
        'elec_carryover_units'  => 'INT DEFAULT 0 AFTER elec_old_meter_final',
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM utility_bills");
    $existingColumns = array_column($stmt->fetchAll(), 'Field');

    foreach ($requiredColumns as $column => $definition) {
        if (!in_array($column, $existingColumns)) {
            $pdo->exec("ALTER TABLE utility_bills ADD COLUMN {$column} {$definition}");
        }
    }

    $checked = true;
}

// Resolve the sender details consistently for test, queued, and system emails.
function getSmtpSenderDetails($settings) {
    $settings = is_array($settings) ? $settings : [];

    $fromEmail = trim((string) ($settings['smtp_from_email'] ?? ''));
    if ($fromEmail === '') {
        $fromEmail = trim((string) ($settings['smtp_username'] ?? ''));
    }

    $fromName = trim((string) ($settings['smtp_from_name'] ?? ''));
    if ($fromName === '') {
        $fromName = trim((string) ($settings['dorm_name'] ?? ''));
    }
    if ($fromName === '') {
        $fromName = 'Rental System';
    }

    return [$fromEmail, $fromName];
}

// Create a configured PHPMailer instance from settings
function getSmtpMailer($settings = null) {
    if (!$settings) {
        $settings = getSettings();
    }

    if (isset($settings['email_enabled']) && (int) $settings['email_enabled'] === 0) {
        return null; // Email sending is disabled
    }

    $host = trim($settings['smtp_host'] ?? '');
    if (empty($host)) {
        return null; // SMTP not configured
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->Port       = (int) ($settings['smtp_port'] ?? 587);
    $mail->SMTPAuth   = true;
    $mail->Username   = $settings['smtp_username'] ?? '';
    $mail->Password   = $settings['smtp_password'] ?? '';
    $mail->CharSet    = 'UTF-8';

    $encryption = strtolower(trim($settings['smtp_encryption'] ?? 'tls'));
    if ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    [$fromEmail, $fromName] = getSmtpSenderDetails($settings);
    if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $mail->setFrom($fromEmail, $fromName);
    }

    return $mail;
}

// Build HTML email template for password reset
function buildPasswordResetHtml($user, $resetUrl, $appName, $isEnglish, ?string $logoSrc = null) {
    $greeting = $isEnglish ? "Hello, {$user['full_name']}" : "สวัสดี คุณ{$user['full_name']}";
    $message = $isEnglish
        ? "We received a request to reset your password for <strong>{$appName}</strong>. Click the button below to set a new password. This link will expire in <strong>1 hour</strong>."
        : "ระบบได้รับคำขอรีเซ็ตรหัสผ่านสำหรับ <strong>{$appName}</strong> กรุณากดปุ่มด้านล่างเพื่อตั้งรหัสผ่านใหม่ ลิงก์นี้จะหมดอายุใน <strong>1 ชั่วโมง</strong>";
    $buttonText = $isEnglish ? 'Reset Password' : 'รีเซ็ตรหัสผ่าน';
    $ignore = $isEnglish
        ? "If you did not request a password reset, you can safely ignore this email."
        : "หากคุณไม่ได้เป็นผู้ขอรีเซ็ตรหัสผ่าน กรุณาเพิกเฉยต่ออีเมลฉบับนี้";
    $linkFallback = $isEnglish
        ? "If the button above doesn't work, copy and paste this link into your browser:"
        : "หากปุ่มด้านบนไม่ทำงาน กรุณาคัดลอกลิงก์นี้ไปวางในเบราว์เซอร์:";

    $headerHtml = buildEmailLogoHeaderHtml($logoSrc, $appName);

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
    {$headerHtml}
    <!-- Body -->
    <tr>
        <td style="padding:40px;">
            <h2 style="color:#333;margin:0 0 20px;font-size:20px;font-weight:600;">{$greeting}</h2>
            <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 30px;">{$message}</p>
            <!-- Button -->
            <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:10px 0 30px;">
                <a href="{$resetUrl}" style="display:inline-block;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:8px;font-size:16px;font-weight:600;letter-spacing:0.5px;box-shadow:0 4px 15px rgba(102,126,234,0.4);">{$buttonText}</a>
            </td></tr>
            </table>
            <p style="color:#888;font-size:13px;line-height:1.6;margin:0 0 15px;">{$ignore}</p>
            <hr style="border:none;border-top:1px solid #eee;margin:25px 0;">
            <p style="color:#aaa;font-size:12px;line-height:1.5;margin:0 0 8px;">{$linkFallback}</p>
            <p style="word-break:break-all;color:#667eea;font-size:12px;margin:0;"><a href="{$resetUrl}" style="color:#667eea;">{$resetUrl}</a></p>
        </td>
    </tr>
    <!-- Footer -->
    <tr>
        <td style="background-color:#f8f9fa;padding:20px 40px;text-align:center;border-top:1px solid #eee;">
            <p style="color:#aaa;font-size:12px;margin:0;">© {$appName} · Powered by Rental Management System</p>
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

function sendPasswordResetEmail($user, $token) {
    global $lang;

    $settings = getSettings();
    ensureSmtpColumns();
    $settings = getSettings(); // reload after ensuring columns

    $appName = $settings['dorm_name'] ?? t('app_name');
    $resetUrl = buildAbsoluteUrl(BASE_URL . 'pages/auth/reset-password.php?token=' . urlencode($token));
    $isEnglish = ($lang ?? 'th') === 'en';
    $subject = $isEnglish ? 'Password reset request' : 'ขอรีเซ็ตรหัสผ่าน';

    // Try SMTP via PHPMailer
    try {
        $mail = getSmtpMailer($settings);
        if ($mail) {
            $logoSrc = attachEmailLogo($mail, $settings);
            $mail->addAddress($user['email'], $user['full_name']);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = buildPasswordResetHtml($user, $resetUrl, $appName, $isEnglish, $logoSrc);
            $mail->AltBody = $isEnglish
                ? "Hello {$user['full_name']},\n\nReset your password: {$resetUrl}\n\nThis link expires in 1 hour."
                : "สวัสดี {$user['full_name']}\n\nรีเซ็ตรหัสผ่าน: {$resetUrl}\n\nลิงก์นี้หมดอายุใน 1 ชั่วโมง";
            return $mail->send();
        }
    } catch (Exception $e) {
        error_log('PHPMailer Error: ' . $e->getMessage());
        return false;
    }

    // Fallback: PHP mail() if SMTP is not configured
    $fromEmail = trim($settings['email'] ?? '');
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $fromEmail = 'no-reply@' . ($host ?: 'localhost');
    }

    $body = $isEnglish
        ? "Hello {$user['full_name']},\n\nReset your password: {$resetUrl}\n\nThis link expires in 1 hour.\n"
        : "สวัสดี {$user['full_name']}\n\nรีเซ็ตรหัสผ่าน: {$resetUrl}\n\nลิงก์นี้หมดอายุใน 1 ชั่วโมง\n";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . mb_encode_mimeheader($appName, 'UTF-8') . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
    ];

    return @mail($user['email'], mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers));
}

// Send a test email to verify SMTP configuration
function sendTestEmail($toEmail, $smtpConfig = null) {
    if (!$smtpConfig) {
        ensureSmtpColumns();
        $smtpConfig = getSettings();
    }

    $appName = trim((string) ($smtpConfig['dorm_name'] ?? '')) ?: 'Rental System';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = trim($smtpConfig['smtp_host'] ?? '');
    $mail->Port       = (int) ($smtpConfig['smtp_port'] ?? 587);
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpConfig['smtp_username'] ?? '';
    $mail->Password   = $smtpConfig['smtp_password'] ?? '';
    $mail->CharSet    = 'UTF-8';

    $encryption = strtolower(trim($smtpConfig['smtp_encryption'] ?? 'tls'));
    if ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    [$fromEmail, $fromName] = getSmtpSenderDetails($smtpConfig);
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail);
    $mail->isHTML(true);
    $mail->Subject = '✅ ทดสอบอีเมล';
    $logoSrc = attachEmailLogo($mail, $smtpConfig);
    $testTitleHtml = '<h1 style="margin:12px 0 0;font-size:22px;font-weight:700;">✅ ทดสอบอีเมลสำเร็จ!</h1>';
    $testHeaderHtml = buildEmailLogoHeaderHtml(
        $logoSrc,
        $appName,
        $testTitleHtml,
        'background:linear-gradient(135deg,#28a745 0%,#20c997 100%);padding:30px;color:#fff;text-align:center;'
    );
    $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;padding:40px 0;">
<tr><td align="center">
<table width="500" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
    {$testHeaderHtml}
    <tr><td style="padding:30px;text-align:center;">
        <p style="color:#333;font-size:16px;margin:0 0 10px;">ระบบ SMTP ของ <strong>{$appName}</strong> ทำงานได้ปกติ</p>
        <p style="color:#888;font-size:13px;margin:0;">Email test successful · SMTP is working correctly</p>
    </td></tr>
    <tr><td style="background:#f8f9fa;padding:15px;text-align:center;border-top:1px solid #eee;">
        <p style="color:#aaa;font-size:11px;margin:0;">© {$appName}</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    $mail->AltBody = "ทดสอบอีเมลสำเร็จ! ระบบ SMTP ของ {$appName} ทำงานได้ปกติ";

    return $mail->send();
}

/**
 * Get all outstanding (unpaid + overdue) utility bills for a monthly tenant.
 * Returns the latest bill per month with overdue calculation.
 *
 * @param int $tenantId Monthly tenant ID
 * @param string|null $beforeBillMonth Optional bill_month (YYYY-MM). If provided, returns only bills strictly before this month.
 * @return array ['bills' => [...], 'total' => float]
 */
function getOutstandingBills($tenantId, $beforeBillMonth = null) {
    global $pdo;

    $sql = "SELECT ub.* FROM utility_bills ub
        INNER JOIN (
            SELECT bill_month, MAX(id) AS max_id
            FROM utility_bills
            WHERE tenant_id = ?
            GROUP BY bill_month
        ) latest ON ub.id = latest.max_id
        WHERE ub.tenant_id = ? AND (ub.status IS NULL OR ub.status != 'paid')";
    $params = [$tenantId, $tenantId];

    if ($beforeBillMonth !== null && $beforeBillMonth !== '') {
        $sql .= " AND ub.bill_month < ?";
        $params[] = $beforeBillMonth;
    }

    $sql .= " ORDER BY ub.bill_month ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bills = $stmt->fetchAll();

    $paymentDueDay = $pdo->query("SELECT payment_due_day FROM settings LIMIT 1")->fetchColumn() ?: 5;

    $today = new DateTime();
    $result = ['bills' => [], 'total' => 0];

    foreach ($bills as $bill) {
        $billDate = new DateTime($bill['bill_month'] . '-01');
        $dueDate = clone $billDate;
        $dueDate->modify('+1 month');
        $dueDate->setDate((int)$dueDate->format('Y'), (int)$dueDate->format('m'), (int)$paymentDueDay);

        $bill['is_overdue'] = ($dueDate < $today);
        $bill['days_overdue'] = $bill['is_overdue'] ? $dueDate->diff($today)->days : 0;
        $bill['due_date_formatted'] = $dueDate->format('Y-m-d');

        $result['bills'][] = $bill;
        $result['total'] += (float)$bill['total_amount'];
    }

    return $result;
}

// Get current user info
function getCurrentUser() {
    global $pdo;
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
    return null;
}

/**
 * Store a verified image in an absolute directory outside the code path.
 */
function uploadVerifiedImage($file, string $uploadDirectory, array $allowedTypes): array
{
    if (!is_array($file)
        || !isset($file['error'], $file['tmp_name'], $file['size'])
        || $file['error'] !== UPLOAD_ERR_OK
        || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => 'Upload failed'];
    }

    $maxSize = 5 * 1024 * 1024;
    if (!is_numeric($file['size']) || (int) $file['size'] <= 0 || (int) $file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File too large'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!isset($allowedTypes[$mimeType]) || @getimagesize($file['tmp_name']) === false) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }

    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
        return ['success' => false, 'message' => 'Upload directory is not writable'];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    if (move_uploaded_file($file['tmp_name'], rtrim($uploadDirectory, '/\\') . DIRECTORY_SEPARATOR . $filename)) {
        return ['success' => true, 'filename' => $filename];
    }

    return ['success' => false, 'message' => 'Failed to move file'];
}

// Upload image used by settings and repair requests.
function uploadImage($file, $directory = 'logo/') {
    $directory = trim(str_replace(['\\', "\0"], '/', $directory), '/') . '/';
    if (strpos($directory, '..') !== false) {
        return ['success' => false, 'message' => 'Invalid upload directory'];
    }

    return uploadVerifiedImage($file, UPLOAD_PATH . $directory, [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ]);
}

// Payment slips intentionally exclude animated GIF files.
function uploadPaymentSlip($file): array
{
    return uploadVerifiedImage($file, BASE_PATH . 'uploads/slips/', [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ]);
}

// Update room status based on tenant occupancy
function updateRoomStatus($roomId) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
    $stmt->execute([$roomId]);
    $currentStatus = $stmt->fetchColumn();

    if ($currentStatus === 'maintenance') {
        return true;
    }

    // Check for active monthly tenants
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM monthly_tenants WHERE room_id = ? AND status = 'active'");
    $stmt->execute([$roomId]);
    $monthlyCount = $stmt->fetch()['count'];

    // Only guests with an actual check-in should make a room occupied.
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM daily_tenants WHERE room_id = ? AND status = 'checked_in' AND actual_check_in_date IS NOT NULL");
    $stmt->execute([$roomId]);
    $dailyCount = $stmt->fetch()['count'];

    // Guests scheduled to check in today but not yet checked in keep the room reserved.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM daily_tenants
        WHERE room_id = ?
        AND check_in_date = CURDATE()
        AND actual_check_in_date IS NULL
        AND (
            status IS NULL
            OR status = ''
            OR status = 'checked_in'
            OR status = 'pending_payment'
        )
        AND (
            actual_check_out_date IS NULL
            OR actual_check_out_date = ''
        )
    ");
    $stmt->execute([$roomId]);
    $reservedTodayCount = $stmt->fetch()['count'];

    // Determine new status
    if ($monthlyCount > 0 || $dailyCount > 0) {
        $newStatus = 'occupied';
    } elseif ($reservedTodayCount > 0) {
        $newStatus = 'reserved';
    } else {
        $newStatus = 'available';
    }

    // Update room status
    $stmt = $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $roomId]);

    return true;
}

function syncRoomStatuses() {
    global $pdo;

    $pdo->exec("
        UPDATE rooms r
        SET status = CASE
            WHEN EXISTS (
                SELECT 1
                FROM monthly_tenants mt
                WHERE mt.room_id = r.id
                  AND mt.status = 'active'
                LIMIT 1
            ) OR EXISTS (
                SELECT 1
                FROM daily_tenants dt
                WHERE dt.room_id = r.id
                  AND dt.status = 'checked_in'
                  AND dt.actual_check_in_date IS NOT NULL
                LIMIT 1
            ) THEN 'occupied'
            WHEN EXISTS (
                SELECT 1
                FROM daily_tenants dr
                WHERE dr.room_id = r.id
                  AND dr.check_in_date = CURDATE()
                  AND dr.actual_check_in_date IS NULL
                  AND (dr.status IS NULL OR dr.status = '' OR dr.status = 'checked_in' OR dr.status = 'pending_payment')
                  AND (dr.actual_check_out_date IS NULL OR dr.actual_check_out_date = '')
                LIMIT 1
            ) THEN 'reserved'
            ELSE 'available'
        END
        WHERE r.status != 'maintenance'
    ");

    return true;
}

require_once __DIR__ . '/email_queue.php';
require_once __DIR__ . '/receipt_email.php';

function ensurePaymentColumns() {
    global $pdo;
    static $checked = false;
    if ($checked) return;
    
    $requiredColumns = [
        'promptpay_id' => 'VARCHAR(20) DEFAULT NULL',
        'promptpay_name' => 'VARCHAR(100) DEFAULT NULL', 
        'bank_account_name' => 'VARCHAR(100) DEFAULT NULL',
        'bank_account_number' => 'VARCHAR(30) DEFAULT NULL',
        'bank_name' => 'VARCHAR(100) DEFAULT NULL',
    ];
    
    $stmt = $pdo->query("SHOW COLUMNS FROM settings");
    $existingColumns = array_column($stmt->fetchAll(), 'Field');
    foreach ($requiredColumns as $column => $definition) {
        if (!in_array($column, $existingColumns)) {
            $pdo->exec("ALTER TABLE settings ADD COLUMN {$column} {$definition}");
        }
    }
    $checked = true;
}

function ensurePaymentConfirmationsTable() {
    global $pdo;
    static $checked = false;
    if ($checked) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_confirmations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bill_type ENUM('monthly','daily') NOT NULL,
            bill_id INT NOT NULL,
            room_number VARCHAR(20) DEFAULT NULL,
            tenant_name VARCHAR(100) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL DEFAULT 'promptpay',
            transfer_date DATE DEFAULT NULL,
            transfer_time TIME DEFAULT NULL,
            slip_image VARCHAR(255) DEFAULT NULL,
            status ENUM('pending_verify', 'approved', 'rejected') NOT NULL DEFAULT 'pending_verify',
            admin_note TEXT DEFAULT NULL,
            verified_by INT DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pc_status (status),
            INDEX idx_pc_bill (bill_type, bill_id),
            INDEX idx_pc_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columnsToCheck = [
        'room_number' => "ALTER TABLE payment_confirmations ADD COLUMN room_number VARCHAR(20) DEFAULT NULL AFTER bill_id",
        'transfer_date' => "ALTER TABLE payment_confirmations ADD COLUMN transfer_date DATE DEFAULT NULL AFTER payment_method",
        'transfer_time' => "ALTER TABLE payment_confirmations ADD COLUMN transfer_time TIME DEFAULT NULL AFTER transfer_date",
    ];

    foreach ($columnsToCheck as $col => $alterSql) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM payment_confirmations LIKE '{$col}'");
            if ($stmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        } catch (Exception $e) {}
    }

    try {
        $stmt = $pdo->query("SHOW INDEX FROM payment_confirmations WHERE Key_name = 'idx_pc_created'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE payment_confirmations ADD INDEX idx_pc_created (created_at)");
        }
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') === false) {
            error_log('Payment confirmation created_at index failed: ' . $e->getMessage());
        }
    }

    $checked = true;
}

function ensurePaymentTokenColumn() {
    global $pdo;
    static $checked = false;
    if ($checked) return;
    
    $stmt = $pdo->query("SHOW COLUMNS FROM utility_bills LIKE 'payment_token'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE utility_bills ADD COLUMN payment_token VARCHAR(64) DEFAULT NULL");
        try {
            $pdo->exec("ALTER TABLE utility_bills ADD UNIQUE INDEX idx_ub_payment_token (payment_token)");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                error_log('Payment token index failed: ' . $e->getMessage());
            }
        }
    }
    $checked = true;
}

function generatePaymentToken(): string {
    return bin2hex(random_bytes(32));
}

function getOrCreatePaymentToken(int $billId): string {
    global $pdo;
    ensurePaymentTokenColumn();
    
    $stmt = $pdo->prepare("SELECT payment_token FROM utility_bills WHERE id = ?");
    $stmt->execute([$billId]);
    $token = $stmt->fetchColumn();
    
    if (!empty($token)) {
        return $token;
    }
    
    $token = generatePaymentToken();
    $stmt = $pdo->prepare("UPDATE utility_bills SET payment_token = ? WHERE id = ?");
    $stmt->execute([$token, $billId]);
    return $token;
}

function getUtilityBillByPaymentToken(string $token): ?array {
    global $pdo;
    ensurePaymentTokenColumn();
    
    $stmt = $pdo->prepare("
        SELECT ub.*, mt.tenant_name, mt.email, mt.phone, r.room_number, rt.type_name
        FROM utility_bills ub
        JOIN monthly_tenants mt ON ub.tenant_id = mt.id
        JOIN rooms r ON ub.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id
        WHERE ub.payment_token = ?
    ");
    $stmt->execute([$token]);
    $result = $stmt->fetch();
    return $result ?: null;
}

function ensureDailyTenantPaymentTokenColumn() {
    global $pdo;
    static $checked = false;
    if ($checked) return;

    $stmt = $pdo->query("SHOW COLUMNS FROM daily_tenants LIKE 'payment_token'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE daily_tenants ADD COLUMN payment_token VARCHAR(64) DEFAULT NULL");
        try {
            $pdo->exec("ALTER TABLE daily_tenants ADD UNIQUE INDEX idx_dt_payment_token (payment_token)");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                throw $e;
            }
        }
    }
    $checked = true;
}

function getOrCreateDailyTenantPaymentToken(int $tenantId): string {
    global $pdo;
    ensureDailyTenantPaymentTokenColumn();

    $stmt = $pdo->prepare("SELECT payment_token FROM daily_tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $token = $stmt->fetchColumn();
    if (!empty($token)) {
        return $token;
    }

    $token = generatePaymentToken();
    $stmt = $pdo->prepare("UPDATE daily_tenants SET payment_token = ? WHERE id = ? AND payment_token IS NULL");
    $stmt->execute([$token, $tenantId]);

    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->prepare("SELECT payment_token FROM daily_tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $existingToken = $stmt->fetchColumn();
        if (!empty($existingToken)) {
            return $existingToken;
        }
        throw new RuntimeException('Unable to create daily payment token.');
    }

    return $token;
}

function getDailyTenantByPaymentToken(string $token): ?array {
    global $pdo;
    ensureDailyTenantPaymentTokenColumn();

    $stmt = $pdo->prepare("\n        SELECT dt.*, r.room_number, rt.type_name, rt.type_name_en,\n               latest_invoice.grand_total AS invoice_grand_total\n        FROM daily_tenants dt\n        JOIN rooms r ON dt.room_id = r.id\n        JOIN room_types rt ON r.room_type_id = rt.id\n        LEFT JOIN (\n            SELECT i.tenant_id, i.grand_total\n            FROM invoices i\n            INNER JOIN (\n                SELECT tenant_id, MAX(id) AS id\n                FROM invoices\n                WHERE tenant_type = 'daily'\n                GROUP BY tenant_id\n            ) latest ON latest.id = i.id\n        ) latest_invoice ON latest_invoice.tenant_id = dt.id\n        WHERE dt.payment_token = ?\n    ");
    $stmt->execute([$token]);
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * Return the current, server-calculated amount for a bill that can receive a
 * public payment confirmation. Browser-submitted bill values are never used
 * as the source of truth.
 */
function getPayableBillForConfirmation(string $billType, int $billId): ?array
{
    global $pdo;

    if ($billId <= 0) {
        return null;
    }

    if ($billType === 'monthly') {
        $stmt = $pdo->prepare("\n            SELECT ub.id, r.room_number, mt.tenant_name, ub.total_amount AS amount_due\n            FROM utility_bills ub\n            INNER JOIN monthly_tenants mt ON mt.id = ub.tenant_id\n            INNER JOIN rooms r ON r.id = ub.room_id\n            WHERE ub.id = ?\n              AND ub.status IN ('unpaid', 'overdue')\n        ");
        $stmt->execute([$billId]);
        $bill = $stmt->fetch();

        return $bill && (float) $bill['amount_due'] > 0 ? $bill : null;
    }

    if ($billType === 'daily') {
        $stmt = $pdo->prepare("\n            SELECT dt.id, r.room_number, dt.guest_name AS tenant_name,\n                   COALESCE(latest_invoice.grand_total, dt.total_amount) AS amount_due\n            FROM daily_tenants dt\n            INNER JOIN rooms r ON r.id = dt.room_id\n            LEFT JOIN invoices latest_invoice ON latest_invoice.id = (\n                SELECT i.id\n                FROM invoices i\n                WHERE i.tenant_type = 'daily' AND i.tenant_id = dt.id\n                ORDER BY i.id DESC\n                LIMIT 1\n            )\n            WHERE dt.id = ? AND dt.status = 'pending_payment'\n        ");
        $stmt->execute([$billId]);
        $bill = $stmt->fetch();

        return $bill && (float) $bill['amount_due'] > 0 ? $bill : null;
    }

    return null;
}

function ensureRepairRequestsTable() {
    global $pdo;
    static $checked = false;
    if ($checked) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS repair_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_number VARCHAR(30) UNIQUE NOT NULL,
            room_id INT DEFAULT NULL,
            room_number VARCHAR(20) NOT NULL,
            reporter_name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(100) DEFAULT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            priority ENUM('normal', 'urgent', 'very_urgent') DEFAULT 'normal',
            status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
            image VARCHAR(255) DEFAULT NULL,
            admin_note TEXT DEFAULT NULL,
            resolved_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_repair_status (status),
            INDEX idx_repair_room (room_number),
            INDEX idx_repair_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add email column if it doesn't exist (for existing tables)
    $stmt = $pdo->query("SHOW COLUMNS FROM repair_requests LIKE 'email'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE repair_requests ADD COLUMN email VARCHAR(100) DEFAULT NULL AFTER phone");
    }

    $checked = true;
}

function sendRepairSubmissionEmail($repairData, $lang = 'th') {
    $settings = getSettings();
    ensureSmtpColumns();
    $settings = getSettings();
    $appName = $settings['dorm_name'] ?? t('app_name');
    $isEnglish = $lang === 'en';

    $subject = $isEnglish
        ? "Repair Request Submitted - {$repairData['ticket_number']}"
        : "แจ้งซ่อมเรียบร้อย - {$repairData['ticket_number']}";

    $trackingUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php?ticket=' . urlencode($repairData['ticket_number']));
    $logoSrc = getEmailLogoUrl($settings);

    $html = buildRepairSubmissionHtml($repairData, $trackingUrl, $appName, $isEnglish, $logoSrc);
    $plain = $isEnglish
        ? "Hello {$repairData['reporter_name']},\n\nYour repair request has been submitted.\n\nTicket Number: {$repairData['ticket_number']}\nRoom: {$repairData['room_number']}\nIssue: {$repairData['title']}\n\nTrack status: {$trackingUrl}"
        : "สวัสดี {$repairData['reporter_name']}\n\nแจ้งซ่อมเรียบร้อยแล้ว\n\nเลขติดตามสถานะ: {$repairData['ticket_number']}\nห้อง: {$repairData['room_number']}\nเรื่อง: {$repairData['title']}\n\nติดตามสถานะ: {$trackingUrl}";

    return queueEmail($repairData['email'], $repairData['reporter_name'], $subject, $html, $plain);
}

function sendRepairStatusUpdateEmail($repairData, $oldStatus, $newStatus, $lang = 'th') {
    $settings = getSettings();
    ensureSmtpColumns();
    $settings = getSettings();
    $appName = $settings['dorm_name'] ?? t('app_name');
    $isEnglish = $lang === 'en';

    $statusText = $isEnglish
        ? ($newStatus === 'completed' ? 'Completed' : ($newStatus === 'cancelled' ? 'Cancelled' : 'Updated'))
        : ($newStatus === 'completed' ? 'เสร็จสิ้น' : ($newStatus === 'cancelled' ? 'ยกเลิก' : 'อัพเดท'));

    $subject = $isEnglish
        ? "Repair Status Updated - {$repairData['ticket_number']}"
        : "สถานะแจ้งซ่อมอัพเดท - {$repairData['ticket_number']}";

    $trackingUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php?ticket=' . urlencode($repairData['ticket_number']));
    $logoSrc = getEmailLogoUrl($settings);

    $html = buildRepairStatusUpdateHtml($repairData, $oldStatus, $newStatus, $trackingUrl, $appName, $isEnglish, $logoSrc);
    $plain = $isEnglish
        ? "Hello {$repairData['reporter_name']},\n\nYour repair request status has been updated.\n\nTicket Number: {$repairData['ticket_number']}\nRoom: {$repairData['room_number']}\nIssue: {$repairData['title']}\nStatus: {$statusText}\n\nTrack status: {$trackingUrl}"
        : "สวัสดี {$repairData['reporter_name']}\n\nสถานะแจ้งซ่อมได้อัพเดทแล้ว\n\nเลขติดตามสถานะ: {$repairData['ticket_number']}\nห้อง: {$repairData['room_number']}\nเรื่อง: {$repairData['title']}\nสถานะ: {$statusText}\n\nติดตามสถานะ: {$trackingUrl}";

    return queueEmail($repairData['email'], $repairData['reporter_name'], $subject, $html, $plain);
}

function buildRepairSubmissionHtml($repairData, $trackingUrl, $appName, $isEnglish, $logoSrc) {
    $settings = getSettings();
    $contactPhone = $settings['phone'] ?? '';
    $repairFormUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');
    
    $title = $isEnglish ? 'Repair Request Submitted' : 'แจ้งซ่อมเรียบร้อย';
    $greeting = $isEnglish ? "Hello {$repairData['reporter_name']}," : "สวัสดี {$repairData['reporter_name']}";
    $message = $isEnglish
        ? "Your repair request has been submitted successfully. We will process it as soon as possible."
        : "แจ้งซ่อมเรียบร้อยแล้ว เจ้าหน้าที่จะดำเนินการตรวจสอบโดยเร็วที่สุด";
    $ticketLabel = $isEnglish ? 'Ticket Number' : 'เลขติดตามสถานะ';
    $roomLabel = $isEnglish ? 'Room' : 'ห้อง';
    $issueLabel = $isEnglish ? 'Issue' : 'เรื่อง';
    $detailsLabel = $isEnglish ? 'Details' : 'รายละเอียด';
    $buttonText = $isEnglish ? 'Track Status' : 'ติดตามสถานะ';
    $ignore = $isEnglish
        ? 'If you did not submit this request, please ignore this email.'
        : ($contactPhone ? "หากคุณไม่ได้แจ้งซ่อม กรุณาติดต่อเจ้าหน้าที่ {$contactPhone}" : 'หากคุณไม่ได้แจ้งซ่อม กรุณาติดต่อเจ้าหน้าที่');
    $linkFallback = $isEnglish ? 'Click the button above or copy this link:' : 'คลิกปุ่มด้านบนหรือคัดลอกลิงก์นี้:';
    $repairFormLabel = $isEnglish ? 'Submit a new repair request' : 'แจ้งซ่อมห้องพัก';

    $headerHtml = buildEmailLogoHeaderHtml(
        $logoSrc,
        $appName,
        "<h1 style='margin:12px 0 0;font-size:22px;font-weight:700;'>{$title}</h1>",
        'background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%);padding:30px;color:#fff;text-align:center;'
    );

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
    {$headerHtml}
    <tr>
        <td style="padding:40px;">
            <h2 style="color:#333;margin:0 0 20px;font-size:20px;font-weight:600;">{$greeting}</h2>
            <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 30px;">{$message}</p>
            
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border-radius:8px;padding:20px;margin:0 0 30px;">
                <tr><td style="padding:8px 0;"><strong style="color:#333;">{$ticketLabel}:</strong> <span style="color:#0d6efd;font-family:monospace;font-size:16px;">{$repairData['ticket_number']}</span></td></tr>
                <tr><td style="padding:8px 0;"><strong style="color:#333;">{$roomLabel}:</strong> <span style="color:#555;">{$repairData['room_number']}</span></td></tr>
                <tr><td style="padding:8px 0;"><strong style="color:#333;">{$issueLabel}:</strong> <span style="color:#555;">{$repairData['title']}</span></td></tr>
                <tr><td style="padding:8px 0;"><strong style="color:#333;">{$detailsLabel}:</strong> <span style="color:#555;">{$repairData['description']}</span></td></tr>
            </table>
            
            <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:10px 0 30px;">
                <a href="{$trackingUrl}" style="display:inline-block;background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%);color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:8px;font-size:16px;font-weight:600;letter-spacing:0.5px;box-shadow:0 4px 15px rgba(13,110,253,0.4);">{$buttonText}</a>
            </td></tr>
            </table>
            <p style="color:#888;font-size:13px;line-height:1.6;margin:0 0 15px;">{$ignore}</p>
            <hr style="margin:20px 0;border:0;border-top:1px solid #eee;">
            <p style="color:#888;font-size:13px;line-height:1.6;margin:0 0 15px;">
                <a href="{$repairFormUrl}" style="color:#0d6efd;text-decoration:none;">{$repairFormLabel}</a>
            </p>
        </td>
    </tr>
    <tr>
        <td style="background-color:#f8f9fa;padding:20px 40px;text-align:center;border-top:1px solid #eee;">
            <p style="color:#aaa;font-size:12px;margin:0;">© {$appName} · Powered by Rental Management System</p>
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

function buildRepairStatusUpdateHtml($repairData, $oldStatus, $newStatus, $trackingUrl, $appName, $isEnglish, $logoSrc) {
    $settings = getSettings();
    $contactPhone = $settings['phone'] ?? '';
    $repairFormUrl = buildAbsoluteUrl(BASE_URL . 'pages/repair-request.php');
    
    $title = $isEnglish ? 'Repair Status Updated' : 'สถานะแจ้งซ่อมอัพเดท';
    $greeting = $isEnglish ? "Hello {$repairData['reporter_name']}," : "สวัสดี {$repairData['reporter_name']}";
    
    $statusText = $isEnglish
        ? ($newStatus === 'completed' ? 'Completed' : ($newStatus === 'cancelled' ? 'Cancelled' : 'Updated'))
        : ($newStatus === 'completed' ? 'เสร็จสิ้น' : ($newStatus === 'cancelled' ? 'ยกเลิก' : 'อัพเดท'));
    
    $message = $isEnglish
        ? "Your repair request status has been updated to: <strong>{$statusText}</strong>"
        : ($newStatus === 'completed' ? "ขณะนี้สถานะการแจ้งซ่อมของคุณได้ดำเนินการเรียบร้อยแล้ว" : "สถานะแจ้งซ่อมของคุณได้อัพเดทเป็น: <strong>{$statusText}</strong>");
    
    $ticketLabel = $isEnglish ? 'Ticket Number' : 'เลขติดตามสถานะ';
    $roomLabel = $isEnglish ? 'Room' : 'ห้อง';
    $issueLabel = $isEnglish ? 'Issue' : 'เรื่อง';
    $detailsLabel = $isEnglish ? 'Details' : 'รายละเอียด';
    $buttonText = $isEnglish ? 'Track Status' : 'ติดตามสถานะ';
    $ignore = $isEnglish
        ? 'If you did not submit this request, please ignore this email.'
        : ($contactPhone ? "หากคุณไม่ได้แจ้งซ่อม กรุณาติดต่อเจ้าหน้าที่ {$contactPhone}" : 'หากคุณไม่ได้แจ้งซ่อม กรุณาติดต่อเจ้าหน้าที่');
    $linkFallback = $isEnglish ? 'Click the button above or copy this link:' : 'คลิกปุ่มด้านบนหรือคัดลอกลิงก์นี้:';
    $repairFormLabel = $isEnglish ? 'Submit a new repair request' : 'แจ้งซ่อมห้องพัก';

    $headerHtml = buildEmailLogoHeaderHtml(
        $logoSrc,
        $appName,
        "<h1 style='margin:12px 0 0;font-size:22px;font-weight:700;'>{$title}</h1>",
        'background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%);padding:30px;color:#fff;text-align:center;'
    );

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
    {$headerHtml}
    <tr>
        <td style="padding:40px;">
            <h2 style="color:#333;margin:0 0 20px;font-size:20px;font-weight:600;">{$greeting}</h2>
            <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 30px;">{$message}</p>
            
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border-radius:8px;padding:20px;margin:0 0 30px;">
                <tr><td style="padding:8px 0;"><strong style="color:#333;">{$ticketLabel}:</strong> <span style="color:#0d6efd;font-family:monospace;font-size:16px;">{$repairData['ticket_number']}</span></td></tr>
                <tr><td style="padding:8px 0;"><strong style="color:#333;">{$roomLabel}:</strong> <span style="color:#555;">{$repairData['room_number']}</span></td></tr>
                <tr><td style="padding:8px 0;"><strong style="color:#333;">{$issueLabel}:</strong> <span style="color:#555;">{$repairData['title']}</span></td></tr>
                <tr><td style="padding:8px 0;"><strong style="color:#333;">{$detailsLabel}:</strong> <span style="color:#555;">{$repairData['description']}</span></td></tr>
            </table>
            
            <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:10px 0 30px;">
                <a href="{$trackingUrl}" style="display:inline-block;background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%);color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:8px;font-size:16px;font-weight:600;letter-spacing:0.5px;box-shadow:0 4px 15px rgba(13,110,253,0.4);">{$buttonText}</a>
            </td></tr>
            </table>
            <p style="color:#888;font-size:13px;line-height:1.6;margin:0 0 15px;">{$ignore}</p>
            <hr style="margin:20px 0;border:0;border-top:1px solid #eee;">
            <p style="color:#888;font-size:13px;line-height:1.6;margin:0 0 15px;">
                <a href="{$repairFormUrl}" style="color:#0d6efd;text-decoration:none;">{$repairFormLabel}</a>
            </p>
        </td>
    </tr>
    <tr>
        <td style="background-color:#f8f9fa;padding:20px 40px;text-align:center;border-top:1px solid #eee;">
            <p style="color:#aaa;font-size:12px;margin:0;">© {$appName} · Powered by Rental Management System</p>
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}


/**
 * Get count of pending payment confirmations requiring verification.
 */
function getPendingPaymentConfirmationsCount() {
    global $pdo;
    try {
        ensurePaymentConfirmationsTable();
        $stmt = $pdo->query("SELECT COUNT(*) FROM payment_confirmations WHERE status = 'pending_verify'");
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Get payment confirmations list with filters.
 */
function getPaymentConfirmations($statusFilter = 'all', $typeFilter = 'all', $search = '', $limit = 50, $offset = 0, $sortBy = 'created_at', $sortOrder = 'DESC') {
    global $pdo;
    ensurePaymentConfirmationsTable();

    $where = [];
    $params = [];

    if ($statusFilter !== 'all' && !empty($statusFilter)) {
        $where[] = "pc.status = ?";
        $params[] = $statusFilter;
    }

    if ($typeFilter !== 'all' && !empty($typeFilter)) {
        $where[] = "pc.bill_type = ?";
        $params[] = $typeFilter;
    }

    if (!empty($search)) {
        $where[] = "(pc.tenant_name LIKE ? OR pc.room_number LIKE ? OR pc.amount LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payment_confirmations pc {$whereSql}");
    $countStmt->execute($params);
    $totalRecords = (int) $countStmt->fetchColumn();

    // Validate sort column
    $allowedSort = ['room_number', 'tenant_name', 'bill_type', 'amount', 'transfer_date', 'status', 'created_at'];
    if (!in_array($sortBy, $allowedSort, true)) {
        $sortBy = 'created_at';
    }
    $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

    // Use custom sort if no explicit sort requested
    if ($sortBy === 'created_at' && $sortOrder === 'DESC' && !isset($_GET['sort_by'])) {
        $orderClause = "ORDER BY CASE WHEN pc.status = 'pending_verify' THEN 0 ELSE 1 END, pc.created_at DESC";
    } else {
        $orderClause = "ORDER BY pc.{$sortBy} {$sortOrder}";
    }

    $sql = "
        SELECT pc.*, u.username AS verifier_name
        FROM payment_confirmations pc
        LEFT JOIN users u ON pc.verified_by = u.id
        {$whereSql}
        {$orderClause}
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    return [
        'records' => $records,
        'total' => $totalRecords
    ];
}

/**
 * Approve a payment confirmation and update bill status.
 */
function approvePaymentConfirmation($id, $adminUserId = null, $note = '') {
    global $pdo;
    ensurePaymentConfirmationsTable();

    try {
        $pdo->beginTransaction();

        $stmtItem = $pdo->prepare("SELECT * FROM payment_confirmations WHERE id = ? FOR UPDATE");
        $stmtItem->execute([(int) $id]);
        $item = $stmtItem->fetch();
        if (!$item) {
            throw new RuntimeException('Record not found');
        }
        if ($item['status'] !== 'pending_verify') {
            throw new RuntimeException('Only pending payment confirmations can be approved');
        }

        $slipAmount = (float) $item['amount'];
        if ($slipAmount <= 0) {
            throw new RuntimeException('Invalid payment amount');
        }

        if ($item['bill_type'] === 'monthly') {
            $stmtBill = $pdo->prepare("SELECT * FROM utility_bills WHERE id = ? FOR UPDATE");
            $stmtBill->execute([$item['bill_id']]);
            $bill = $stmtBill->fetch();

            if (!$bill) {
                throw new RuntimeException('The monthly bill no longer exists');
            }

            $totalBill = (float) $bill['total_amount'];
            if ($bill['status'] === 'paid') {
                throw new RuntimeException('This bill is already fully paid');
            }
            if (abs($slipAmount - $totalBill) > 0.01) {
                throw new RuntimeException('The payment amount does not match the bill total');
            }

            $stmtUpdateBill = $pdo->prepare("
                UPDATE utility_bills
                SET status = 'paid', paid_date = CURDATE()
                WHERE id = ?
            ");
            $stmtUpdateBill->execute([$item['bill_id']]);
        } elseif ($item['bill_type'] === 'daily') {
            $stmtDaily = $pdo->prepare("SELECT * FROM daily_tenants WHERE id = ? FOR UPDATE");
            $stmtDaily->execute([$item['bill_id']]);
            $daily = $stmtDaily->fetch();

            if (!$daily || $daily['status'] !== 'pending_payment') {
                throw new RuntimeException('This daily booking is no longer awaiting payment');
            }

            $expectedAmount = (float) $daily['total_amount'];
            if (abs($slipAmount - $expectedAmount) > 0.01) {
                throw new RuntimeException('The payment amount does not match the booking total');
            }

            $stmtUpdateDaily = $pdo->prepare("
                UPDATE daily_tenants
                SET status = NULL, payment_deadline = NULL
                WHERE id = ?
            ");
            $stmtUpdateDaily->execute([$item['bill_id']]);

            if (function_exists('syncRoomStatuses')) {
                syncRoomStatuses();
            }
        } else {
            throw new RuntimeException('Invalid bill type');
        }

        $stmtUpdatePc = $pdo->prepare("
            UPDATE payment_confirmations
            SET status = 'approved', admin_note = ?, verified_by = ?, verified_at = NOW()
            WHERE id = ? AND status = 'pending_verify'
        ");
        $stmtUpdatePc->execute([$note, $adminUserId, $id]);
        if ($stmtUpdatePc->rowCount() !== 1) {
            throw new RuntimeException('Payment confirmation was updated by another request');
        }

        $pdo->commit();
        logActivity('approve_payment_slip', 'payment_confirmations', $id, "Approved payment confirmation #{$id} of amount {$item['amount']}");

        return ['success' => true, 'message' => 'Payment approved successfully'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Payment approval failed: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Reject a payment confirmation.
 */
function rejectPaymentConfirmation($id, $adminUserId = null, $note = '') {
    global $pdo;
    ensurePaymentConfirmationsTable();

    try {
        $stmtUpdatePc = $pdo->prepare("
            UPDATE payment_confirmations
            SET status = 'rejected', admin_note = ?, verified_by = ?, verified_at = NOW()
            WHERE id = ? AND status = 'pending_verify'
        ");
        $stmtUpdatePc->execute([$note, $adminUserId, $id]);
        if ($stmtUpdatePc->rowCount() !== 1) {
            return ['success' => false, 'message' => 'Only pending payment confirmations can be rejected'];
        }

        logActivity('reject_payment_slip', 'payment_confirmations', $id, "Rejected payment confirmation #{$id}: {$note}");

        return ['success' => true, 'message' => 'Payment confirmation rejected'];
    } catch (Exception $e) {
        error_log('Payment rejection failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to reject payment confirmation'];
    }
}
