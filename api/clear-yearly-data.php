<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Ensure user is admin
requireAdmin();
// Ensure CSRF is valid
requireValidCsrfToken();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$startYear = isset($_POST['start_year']) ? intval($_POST['start_year']) : 0;
$endYear = isset($_POST['end_year']) ? intval($_POST['end_year']) : 0;
if ($startYear < 2000 || $startYear > 2100 || $endYear < 2000 || $endYear > 2100 || $endYear < $startYear) {
    echo json_encode(['success' => false, 'error' => 'Invalid year range specified']);
    exit;
}

$rangeText = ($startYear === $endYear) ? (string)$startYear : "$startYear - $endYear";
$rangeStart = sprintf('%04d-01-01', $startYear);
$rangeEndExclusive = sprintf('%04d-01-01', $endYear + 1);

// PIN check if PIN is configured in settings
$settings = getSettings();
$dbPin = $settings['pin'] ?? null;
if (!empty($dbPin) && empty($_POST['check_only'])) {
    $pinVal = trim($_POST['pin'] ?? '');
    $pinResult = verifySystemPin($pinVal);
    if (empty($pinResult['success'])) {
        echo json_encode([
            'success' => false,
            'error' => $pinResult['message'] ?? t('confirm_pin_incorrect'),
            'logout' => !empty($pinResult['logout']),
            'pin_error' => true
        ]);
        exit;
    }
}

ensureIncomeSnapshotsTable();

function clearYearlyHeldMonthlyTenantDeposit(PDO $pdo, string $asOfDate): float
{
    $nextDate = date('Y-m-d', strtotime($asOfDate . ' +1 day'));
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(deposit), 0) as amount
        FROM monthly_tenants
        WHERE deposit > 0
        AND (contract_start <= ? OR created_at < ?)
        AND ? <= contract_end
        AND (status IS NULL OR status != 'terminated' OR updated_at >= ?)
    ");
    $stmt->execute([$asOfDate, $nextDate, $asOfDate, $nextDate]);

    return (float) $stmt->fetch()['amount'];
}

function clearYearlyForfeitedMonthlyTenantDepositByMonth(PDO $pdo, string $month): float
{
    [$monthStart, $nextMonthStart] = monthDateRange($month);
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(deposit), 0) as amount
        FROM monthly_tenants
        WHERE deposit > 0
        AND status = 'terminated'
        AND updated_at >= ?
        AND updated_at < ?
    ");
    $stmt->execute([$monthStart, $nextMonthStart]);

    return (float) $stmt->fetch()['amount'];
}

function clearYearlyMonthlyPaidIncomeByMonth(PDO $pdo, string $month): float
{
    [$monthStart, $nextMonthStart] = monthDateRange($month);
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN ub.paid_amount > 0 THEN ub.paid_amount ELSE ub.total_amount END), 0) as amount
        FROM utility_bills ub
        WHERE ub.paid_date >= ?
        AND ub.paid_date < ?
        AND ub.status = 'paid'
    ");
    $stmt->execute([$monthStart, $nextMonthStart]);

    return (float) $stmt->fetch()['amount'];
}

function clearYearlyMonthlyUnpaidByMonth(PDO $pdo, string $month): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ub.total_amount), 0) as amount
        FROM utility_bills ub
        WHERE ub.bill_month = ?
        AND ub.status IN ('unpaid', 'overdue')
    ");
    $stmt->execute([$month]);
    $unpaidFromBills = (float) $stmt->fetch()['amount'];

    $stmt = $pdo->prepare("
        SELECT mt.id, mt.monthly_rent, mt.contract_start,
               (SELECT COUNT(*) FROM utility_bills ub2
                WHERE ub2.tenant_id = mt.id AND ub2.bill_month < ?) as existing_bills
        FROM monthly_tenants mt
        WHERE mt.contract_start <= LAST_DAY(?)
        AND mt.contract_end >= ?
        AND NOT EXISTS (
            SELECT 1 FROM utility_bills ub
            WHERE ub.tenant_id = mt.id
            AND ub.bill_month = ?
        )
    ");
    $stmt->execute([$month, $month . '-01', $month . '-01', $month]);
    $tenantsWithoutBills = $stmt->fetchAll();

    $unpaidFromNoBills = 0;
    foreach ($tenantsWithoutBills as $tenant) {
        $contractStart = new DateTime($tenant['contract_start']);
        $selectedMonthStart = new DateTime($month . '-01');
        $selectedMonthEnd = new DateTime($month . '-01');
        $selectedMonthEnd->modify('last day of this month');

        $startDate = $contractStart > $selectedMonthStart ? $contractStart : $selectedMonthStart;

        $monthsDiff = ($selectedMonthEnd->format('Y') - $startDate->format('Y')) * 12;
        $monthsDiff += $selectedMonthEnd->format('n') - $startDate->format('n') + 1;

        $existingBills = (int) $tenant['existing_bills'];
        $unpaidMonths = max(0, $monthsDiff - $existingBills);

        $unpaidFromNoBills += (float) $tenant['monthly_rent'] * $unpaidMonths;
    }

    return $unpaidFromBills + $unpaidFromNoBills;
}

function clearYearlyMonthlyPaidBalanceByDate(PDO $pdo, string $date): float
{
    $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN ub.paid_amount > 0 THEN ub.paid_amount ELSE ub.total_amount END), 0) as amount
        FROM utility_bills ub
        WHERE ub.created_at < ?
        AND ub.status = 'paid'
        AND ub.paid_date < ?
    ");
    $stmt->execute([$nextDate, $nextDate]);

    return (float) $stmt->fetch()['amount'];
}

function clearYearlyMonthlyUnpaidBalanceByDate(PDO $pdo, string $date): float
{
    $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ub.total_amount), 0) as amount
        FROM utility_bills ub
        WHERE ub.created_at < ?
        AND (
            ub.status IN ('unpaid', 'overdue')
            OR (ub.status = 'paid' AND ub.paid_date >= ?)
        )
    ");
    $stmt->execute([$nextDate, $nextDate]);

    return (float) $stmt->fetch()['amount'];
}

function clearYearlyForfeitedMonthlyTenantDepositByDate(PDO $pdo, string $date): float
{
    $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(deposit), 0) as amount
        FROM monthly_tenants
        WHERE deposit > 0
        AND status = 'terminated'
        AND updated_at >= ?
        AND updated_at < ?
    ");
    $stmt->execute([$date, $nextDate]);

    return (float) $stmt->fetch()['amount'];
}

function clearYearlyUpsertIncomeSnapshot(
    PDO $pdo,
    int $year,
    ?int $month,
    float $dailyIncome,
    float $dailyExtraIncome,
    float $monthlyIncome,
    float $depositIncome,
    float $monthlyUnpaid,
    float $heldDeposit,
    float $totalIncome
): void {
    if ($month === null) {
        $stmt = $pdo->prepare("SELECT * FROM income_snapshots WHERE year = ? AND month IS NULL");
        $stmt->execute([$year]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $dailyIncome += (float) $existing['daily_income'];
            $dailyExtraIncome += (float) $existing['daily_extra_income'];
            $monthlyIncome += (float) $existing['monthly_income'];
            $depositIncome += (float) $existing['deposit_income'];
            $monthlyUnpaid += (float) $existing['monthly_unpaid'];
            $heldDeposit += (float) $existing['held_deposit'];
            $totalIncome = $dailyIncome + $dailyExtraIncome + $monthlyIncome + $depositIncome;
        }

        $stmt = $pdo->prepare("DELETE FROM income_snapshots WHERE year = ? AND month IS NULL");
        $stmt->execute([$year]);

        $stmt = $pdo->prepare("
            INSERT INTO income_snapshots (year, month, daily_income, daily_extra_income, monthly_income, deposit_income, monthly_unpaid, held_deposit, total_income)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$year, $dailyIncome, $dailyExtraIncome, $monthlyIncome, $depositIncome, $monthlyUnpaid, $heldDeposit, $totalIncome]);
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM income_snapshots WHERE year = ? AND month = ?");
    $stmt->execute([$year, $month]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $dailyIncome += (float) $existing['daily_income'];
        $dailyExtraIncome += (float) $existing['daily_extra_income'];
        $monthlyIncome += (float) $existing['monthly_income'];
        $depositIncome += (float) $existing['deposit_income'];
        $monthlyUnpaid += (float) $existing['monthly_unpaid'];
        $heldDeposit += (float) $existing['held_deposit'];
        $totalIncome = $dailyIncome + $dailyExtraIncome + $monthlyIncome + $depositIncome;
    }

    $stmt = $pdo->prepare("DELETE FROM income_snapshots WHERE year = ? AND month = ?");
    $stmt->execute([$year, $month]);

    $stmt = $pdo->prepare("
        INSERT INTO income_snapshots (year, month, daily_income, daily_extra_income, monthly_income, deposit_income, monthly_unpaid, held_deposit, total_income)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$year, $month, $dailyIncome, $dailyExtraIncome, $monthlyIncome, $depositIncome, $monthlyUnpaid, $heldDeposit, $totalIncome]);
}

function clearYearlyUpsertIncomeDailySnapshot(
    PDO $pdo,
    string $date,
    float $dailyIncome,
    float $dailyExtraIncome,
    float $monthlyIncome,
    float $depositIncome,
    float $monthlyUnpaid,
    float $heldDeposit,
    float $totalIncome
): void {
    $year = (int) date('Y', strtotime($date));
    $month = (int) date('n', strtotime($date));
    $day = (int) date('j', strtotime($date));

    $stmt = $pdo->prepare("SELECT * FROM income_daily_snapshots WHERE snapshot_date = ?");
    $stmt->execute([$date]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $dailyIncome += (float) $existing['daily_income'];
        $dailyExtraIncome += (float) $existing['daily_extra_income'];
        $monthlyIncome += (float) $existing['monthly_income'];
        $depositIncome += (float) $existing['deposit_income'];
        $monthlyUnpaid += (float) $existing['monthly_unpaid'];
        $heldDeposit += (float) $existing['held_deposit'];
        $totalIncome = $dailyIncome + $dailyExtraIncome + $monthlyIncome + $depositIncome;
    }

    $stmt = $pdo->prepare("DELETE FROM income_daily_snapshots WHERE snapshot_date = ?");
    $stmt->execute([$date]);

    $stmt = $pdo->prepare("
        INSERT INTO income_daily_snapshots (
            snapshot_date, year, month, day, daily_income, daily_extra_income,
            monthly_income, deposit_income, monthly_unpaid, held_deposit, total_income
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $date,
        $year,
        $month,
        $day,
        $dailyIncome,
        $dailyExtraIncome,
        $monthlyIncome,
        $depositIncome,
        $monthlyUnpaid,
        $heldDeposit,
        $totalIncome
    ]);
}

function clearYearlySnapshotDayLimit(int $year, int $month): int
{
    $monthStr = sprintf('%04d-%02d', $year, $month);
    $numDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    if ($monthStr < date('Y-m')) {
        return $numDays;
    }

    if ($monthStr === date('Y-m')) {
        return min($numDays, (int) date('j'));
    }

    return 0;
}

try {
    $pdo->beginTransaction();

    // 1. Get IDs of daily tenants to delete (strictly within the selected year range)
    $stmt = $pdo->prepare("SELECT id FROM daily_tenants WHERE check_in_date >= ? AND check_in_date < ? AND check_out_date >= ? AND check_out_date < ?");
    $stmt->execute([$rangeStart, $rangeEndExclusive, $rangeStart, $rangeEndExclusive]);
    $dailyTenantIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 2. Get IDs of monthly tenants to delete (strictly within the selected year range)
    $stmt = $pdo->prepare("SELECT id FROM monthly_tenants WHERE contract_start >= ? AND contract_start < ? AND contract_end >= ? AND contract_end < ?");
    $stmt->execute([$rangeStart, $rangeEndExclusive, $rangeStart, $rangeEndExclusive]);
    $monthlyTenantIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Count emails for this year range
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_queue WHERE created_at >= ? AND created_at < ?");
    $stmt->execute([$rangeStart, $rangeEndExclusive]);
    $emailCount = (int)$stmt->fetchColumn();

    if (empty($dailyTenantIds) && empty($monthlyTenantIds) && $emailCount === 0) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error' => sprintf(t('clear_yearly_no_data'), $rangeText)
        ]);
        exit;
    }

    if (!empty($_POST['check_only'])) {
        $pdo->rollBack();
        echo json_encode([
            'success' => true,
            'check_only' => true
        ]);
        exit;
    }

    // 3. Save income snapshots before deleting data
    for ($year = $startYear; $year <= $endYear; $year++) {
        $yearlyDailyIncome = 0;
        $yearlyDailyExtraIncome = 0;
        $yearlyMonthlyIncome = 0;
        $yearlyDepositIncome = 0;
        $yearlyMonthlyUnpaid = 0;

        for ($month = 1; $month <= 12; $month++) {
            $monthStr = sprintf('%04d-%02d', $year, $month);

            $dailyIncome = getDailyTenantRevenueByMonth($monthStr);
            $dailyExtraIncome = getDailyTenantExtraRevenueByMonth($monthStr);
            $monthlyIncome = clearYearlyMonthlyPaidIncomeByMonth($pdo, $monthStr);
            $depositIncome = clearYearlyForfeitedMonthlyTenantDepositByMonth($pdo, $monthStr);
            $depositSnapshotDate = date('Y-m-d', strtotime($monthStr . '-01 last day of this month'));
            if ($monthStr === date('Y-m')) {
                $depositSnapshotDate = date('Y-m-d');
            }
            $heldDeposit = clearYearlyHeldMonthlyTenantDeposit($pdo, $depositSnapshotDate);
            $monthlyUnpaid = clearYearlyMonthlyUnpaidByMonth($pdo, $monthStr);
            $totalIncome = $dailyIncome + $dailyExtraIncome + $monthlyIncome + $depositIncome;

            clearYearlyUpsertIncomeSnapshot(
                $pdo,
                $year,
                $month,
                $dailyIncome,
                $dailyExtraIncome,
                $monthlyIncome,
                $depositIncome,
                $monthlyUnpaid,
                $heldDeposit,
                $totalIncome
            );

            $dayLimit = clearYearlySnapshotDayLimit($year, $month);
            for ($day = 1; $day <= $dayLimit; $day++) {
                $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $dailyIncomeByDate = getDailyTenantRevenueByDate($currentDate);
                $dailyExtraIncomeByDate = getDailyTenantExtraRevenueByDate($currentDate);
                $monthlyPaidBalance = clearYearlyMonthlyPaidBalanceByDate($pdo, $currentDate);
                $depositIncomeByDate = clearYearlyForfeitedMonthlyTenantDepositByDate($pdo, $currentDate);
                $monthlyUnpaidBalance = clearYearlyMonthlyUnpaidBalanceByDate($pdo, $currentDate);
                $heldDepositByDate = clearYearlyHeldMonthlyTenantDeposit($pdo, $currentDate);
                $totalIncomeByDate = $dailyIncomeByDate + $dailyExtraIncomeByDate + $monthlyPaidBalance + $depositIncomeByDate;

                clearYearlyUpsertIncomeDailySnapshot(
                    $pdo,
                    $currentDate,
                    $dailyIncomeByDate,
                    $dailyExtraIncomeByDate,
                    $monthlyPaidBalance,
                    $depositIncomeByDate,
                    $monthlyUnpaidBalance,
                    $heldDepositByDate,
                    $totalIncomeByDate
                );
            }

            $yearlyDailyIncome += $dailyIncome;
            $yearlyDailyExtraIncome += $dailyExtraIncome;
            $yearlyMonthlyIncome += $monthlyIncome;
            $yearlyDepositIncome += $depositIncome;
            $yearlyMonthlyUnpaid += $monthlyUnpaid;
        }

        $yearlyDepositDate = ((int) $year === (int) date('Y')) ? date('Y-m-d') : $year . '-12-31';
        $yearlyHeldDeposit = clearYearlyHeldMonthlyTenantDeposit($pdo, $yearlyDepositDate);
        $yearlyTotalIncome = $yearlyDailyIncome + $yearlyDailyExtraIncome + $yearlyMonthlyIncome + $yearlyDepositIncome;

        clearYearlyUpsertIncomeSnapshot(
            $pdo,
            $year,
            null,
            $yearlyDailyIncome,
            $yearlyDailyExtraIncome,
            $yearlyMonthlyIncome,
            $yearlyDepositIncome,
            $yearlyMonthlyUnpaid,
            $yearlyHeldDeposit,
            $yearlyTotalIncome
        );
    }

    // 4. Delete invoices and tenants
    if (!empty($dailyTenantIds)) {
        // Delete invoices for these daily tenants
        $inQuery = implode(',', array_fill(0, count($dailyTenantIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM invoices WHERE tenant_type = 'daily' AND tenant_id IN ($inQuery)");
        $stmt->execute($dailyTenantIds);

        // Delete daily tenants
        $stmt = $pdo->prepare("DELETE FROM daily_tenants WHERE id IN ($inQuery)");
        $stmt->execute($dailyTenantIds);
    }

    if (!empty($monthlyTenantIds)) {
        // Delete invoices for these monthly tenants
        $inQuery = implode(',', array_fill(0, count($monthlyTenantIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM invoices WHERE tenant_type = 'monthly' AND tenant_id IN ($inQuery)");
        $stmt->execute($monthlyTenantIds);

        // Delete monthly tenants (will automatically cascade delete utility_bills)
        $stmt = $pdo->prepare("DELETE FROM monthly_tenants WHERE id IN ($inQuery)");
        $stmt->execute($monthlyTenantIds);
    }

    // 5. Sync room statuses to ensure room states match database status
    syncRoomStatuses();

    // 6. Log activity
    logActivity('clear_yearly_data', 'system', $startYear, "Cleared data for year range $rangeText");

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => t('clear_yearly_success')
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('clear-yearly-data API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => t('clear_yearly_error')
    ]);
}
exit;
