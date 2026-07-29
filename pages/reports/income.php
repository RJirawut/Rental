<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Load PhpSpreadsheet if export is requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

// Use statements must be at global scope
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Set charset to UTF-8
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

requireLogin();
require_once __DIR__ . '/../../includes/pin-lockscreen-check.php';

$pageTitle = t('income_report');

// Filters
$year = $_GET['year'] ?? date('Y');
$type = $_GET['type'] ?? 'monthly';
$month = normalizeMonthFilterValue($_GET['month'] ?? date('Y-m'));
$lang = $_SESSION['lang'] ?? 'th';
$settings = getSettings();
ensureIncomeSnapshotsTable();

// Get income data
$incomeData = [];
$dailyData = []; // For monthly view with daily details
$selectedMonthSnapshot = null;
$selectedMonthDailySnapshots = [];
$selectedMonthUsesSnapshot = false;
$yearlySnapshots = [];

$localizedMonthsShort = localizedMonthNames($lang, false);
$localizedMonthsFull = localizedMonthNames($lang);

function reportHeldMonthlyTenantDeposit($pdo, $asOfDate)
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

function reportForfeitedMonthlyTenantDepositByDate($pdo, $date)
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

function reportForfeitedMonthlyTenantDepositByMonth($pdo, $month)
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

if ($type === 'monthly') {
    // ????????????????????????????????????
    list($selYear, $selMonth) = explode('-', $month);
    $numDays = cal_days_in_month(CAL_GREGORIAN, $selMonth, $selYear);

    // ????????????????????????????????????
    $currentMonth = date('Y-m');
    $isPastMonth = $month < $currentMonth;

    // Check if snapshot exists for this month
    $stmt = $pdo->prepare("SELECT * FROM income_snapshots WHERE year = ? AND month = ?");
    $stmt->execute([(int) $selYear, (int) $selMonth]);
    $snapshot = $stmt->fetch();
    $selectedMonthSnapshot = $snapshot ?: null;

    $stmt = $pdo->prepare("SELECT * FROM income_daily_snapshots WHERE year = ? AND month = ? ORDER BY snapshot_date");
    $stmt->execute([(int) $selYear, (int) $selMonth]);
    $selectedMonthDailySnapshots = $stmt->fetchAll();

    // Map daily snapshots by day for quick lookup
    $dailySnapshotsByDay = [];
    foreach ($selectedMonthDailySnapshots as $rowSnap) {
        $dailySnapshotsByDay[(int)$rowSnap['day']] = $rowSnap;
    }

    $selectedMonthUsesSnapshot = !empty($selectedMonthDailySnapshots) || !empty($selectedMonthSnapshot);

    // Show running balance up to today if current month, or the entire month if past
    $limitDay = $isPastMonth ? $numDays : min($numDays, (int) date('j'));

    for ($d = 1; $d <= $limitDay; $d++) {
        $currentDate = sprintf('%04d-%02d-%02d', $selYear, $selMonth, $d);

        // 1. Live Data
        $liveDailyRoom = getDailyTenantRevenueByDate($currentDate);
        $liveDailyExtra = getDailyTenantExtraRevenueByDate($currentDate);

        // monthly paid (running balance up to currentDate)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN ub.paid_amount > 0 THEN ub.paid_amount ELSE ub.total_amount END), 0) as amount
            FROM utility_bills ub
            WHERE DATE(ub.created_at) <= ?
            AND ub.status = 'paid'
            AND DATE(ub.paid_date) <= ?
        ");
        $stmt->execute([$currentDate, $currentDate]);
        $liveMonthlyPaid = (float) $stmt->fetch()['amount'];

        $liveDepositIncome = reportForfeitedMonthlyTenantDepositByDate($pdo, $currentDate);

        // unpaid
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ub.total_amount), 0) as amount
            FROM utility_bills ub
            WHERE DATE(ub.created_at) <= ?
            AND (
                ub.status IN ('unpaid', 'overdue')
                OR (ub.status = 'paid' AND DATE(ub.paid_date) > ?)
            )
        ");
        $stmt->execute([$currentDate, $currentDate]);
        $liveCurrentUnpaid = (float) $stmt->fetch()['amount'];

        $liveHeldDeposit = reportHeldMonthlyTenantDeposit($pdo, $currentDate);

        // 2. Combine with Snapshot if exists
        $dailyIncome = $liveDailyRoom;
        $dailyExtraIncome = $liveDailyExtra;
        $monthlyIncome = $liveMonthlyPaid;
        $depositIncome = $liveDepositIncome;
        $heldDeposit = $liveHeldDeposit;
        $monthlyUnpaid = $liveCurrentUnpaid;

        if (isset($dailySnapshotsByDay[$d])) {
            $snapDay = $dailySnapshotsByDay[$d];
            $dailyIncome += (float) $snapDay['daily_income'];
            $dailyExtraIncome += (float) $snapDay['daily_extra_income'];
            $monthlyIncome += (float) $snapDay['monthly_income'];
            $depositIncome += (float) $snapDay['deposit_income'];
            $heldDeposit += (float) $snapDay['held_deposit'];
            $monthlyUnpaid += (float) $snapDay['monthly_unpaid'];
        } elseif ($snapshot) {
            // Fallback for monthly-only snapshot
            $snapshotDayLimit = $month === $currentMonth ? min($numDays, (int) date('j')) : $numDays;
            if ($d === $snapshotDayLimit) {
                $dailyIncome += (float) $snapshot['daily_income'];
                $dailyExtraIncome += (float) $snapshot['daily_extra_income'];
                $monthlyIncome += (float) $snapshot['monthly_income'];
                $depositIncome += (float) $snapshot['deposit_income'];
                $heldDeposit += (float) $snapshot['held_deposit'];
                $monthlyUnpaid += (float) $snapshot['monthly_unpaid'];
            }
        }

        $periodLabel = date('d/m/Y', strtotime($currentDate));

        $incomeData[] = [
            'period' => $periodLabel,
            'daily' => $dailyIncome,
            'daily_extra' => $dailyExtraIncome,
            'monthly' => $monthlyIncome + $depositIncome,
            'deposit' => $heldDeposit,
            'monthly_unpaid' => $monthlyUnpaid,
            'total' => $dailyIncome + $dailyExtraIncome + $monthlyIncome + $depositIncome
        ];
    }
} else {
    // Yearly view
    $stmt = $pdo->prepare("SELECT * FROM income_snapshots WHERE year = ? AND month BETWEEN 1 AND 12 ORDER BY month");
    $stmt->execute([(int) $year]);
    $yearlySnapshots = $stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);
    $snapshots = $yearlySnapshots;

    for ($m = 1; $m <= 12; $m++) {
        $monthStr = sprintf('%s-%02d', $year, $m);

        // 1. Live Data
        $liveDailyIncome = getDailyTenantRevenueByMonth($monthStr);
        $liveDailyExtraIncome = getDailyTenantExtraRevenueByMonth($monthStr);

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN ub.paid_amount > 0 THEN ub.paid_amount ELSE ub.total_amount END), 0) as amount
            FROM utility_bills ub
            WHERE DATE_FORMAT(ub.paid_date, '%Y-%m') = ?
            AND ub.status = 'paid'
        ");
        $stmt->execute([$monthStr]);
        $liveMonthlyIncome = $stmt->fetch()['amount'];

        $depositSnapshotDate = date('Y-m-d', strtotime($monthStr . '-01 last day of this month'));
        if ($monthStr === date('Y-m')) {
            $depositSnapshotDate = date('Y-m-d');
        }

        $liveDepositIncome = reportForfeitedMonthlyTenantDepositByMonth($pdo, $monthStr);
        $liveHeldDeposit = reportHeldMonthlyTenantDeposit($pdo, $depositSnapshotDate);

        // unpaid from bills
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ub.total_amount), 0) as amount
            FROM utility_bills ub
            WHERE ub.bill_month = ?
            AND ub.status IN ('unpaid', 'overdue')
        ");
        $stmt->execute([$monthStr]);
        $unpaidFromBills = (float) $stmt->fetch()['amount'];

        // unpaid from tenants without bills
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
        $stmt->execute([$monthStr, $monthStr . '-01', $monthStr . '-01', $monthStr]);
        $tenantsWithoutBills = $stmt->fetchAll();

        $unpaidFromNoBills = 0;
        foreach ($tenantsWithoutBills as $tenant) {
            $contractStart = new DateTime($tenant['contract_start']);
            $selectedMonthStart = new DateTime($monthStr . '-01');
            $selectedMonthEnd = new DateTime($monthStr . '-01');
            $selectedMonthEnd->modify('last day of this month');

            if ($contractStart > $selectedMonthStart) {
                $startDate = $contractStart;
            } else {
                $startDate = $selectedMonthStart;
            }

            $monthsDiff = ($selectedMonthEnd->format('Y') - $startDate->format('Y')) * 12;
            $monthsDiff += $selectedMonthEnd->format('n') - $startDate->format('n') + 1;

            $existingBills = (int) $tenant['existing_bills'];
            $unpaidMonths = max(0, $monthsDiff - $existingBills);

            $unpaidFromNoBills += $tenant['monthly_rent'] * $unpaidMonths;
        }

        $liveMonthlyUnpaid = $unpaidFromBills + $unpaidFromNoBills;

        // 2. Combine with Snapshot
        $dailyIncome = $liveDailyIncome;
        $dailyExtraIncome = $liveDailyExtraIncome;
        $monthlyIncome = $liveMonthlyIncome;
        $depositIncome = $liveDepositIncome;
        $heldDeposit = $liveHeldDeposit;
        $monthlyUnpaid = $liveMonthlyUnpaid;

        if (isset($snapshots[$m])) {
            $snap = $snapshots[$m];
            $dailyIncome += (float)$snap['daily_income'];
            $dailyExtraIncome += (float)$snap['daily_extra_income'];
            $monthlyIncome += (float)$snap['monthly_income'];
            $depositIncome += (float)$snap['deposit_income'];
            $heldDeposit += (float)$snap['held_deposit'];
            $monthlyUnpaid += (float)$snap['monthly_unpaid'];
        }

        $periodLabel = $localizedMonthsShort[$m - 1] . ' ' . $year;

        $incomeData[] = [
            'period' => $periodLabel,
            'daily' => $dailyIncome,
            'daily_extra' => $dailyExtraIncome,
            'monthly' => $monthlyIncome + $depositIncome,
            'deposit' => $heldDeposit,
            'monthly_unpaid' => $monthlyUnpaid,
            'total' => $dailyIncome + $dailyExtraIncome + $monthlyIncome + $depositIncome
        ];
    }
}
// Calculate totals
$totalDailyRoom = array_sum(array_column($incomeData, 'daily'));
$totalDailyExtra = array_sum(array_column($incomeData, 'daily_extra'));
$totalDaily = $totalDailyRoom + $totalDailyExtra;
$lastIncomeIndex = count($incomeData) - 1;
if ($type === 'monthly') {
    $totalMonthly = $lastIncomeIndex >= 0 ? $incomeData[$lastIncomeIndex]['monthly'] : 0;
} else {
    $totalMonthly = array_sum(array_column($incomeData, 'monthly'));
}
$depositSummaryDate = $type === 'monthly'
    ? ($month === date('Y-m') ? date('Y-m-d') : date('Y-m-d', strtotime($month . '-01 last day of this month')))
    : ((int) $year === (int) date('Y') ? date('Y-m-d') : $year . '-12-31');
$totalDeposit = reportHeldMonthlyTenantDeposit($pdo, $depositSummaryDate);
if ($type === 'monthly') {
    if (!empty($selectedMonthDailySnapshots)) {
        $lastDailySnapshot = end($selectedMonthDailySnapshots);
        $totalDeposit += (float) $lastDailySnapshot['held_deposit'];
        reset($selectedMonthDailySnapshots);
    } elseif ($selectedMonthSnapshot) {
        $totalDeposit += (float) $selectedMonthSnapshot['held_deposit'];
    }
} else {
    // Yearly
    if (!empty($yearlySnapshots)) {
        $depositSnapshotMonth = ((int) $year === (int) date('Y')) ? (int) date('n') : 12;
        for ($m = $depositSnapshotMonth; $m >= 1; $m--) {
            if (isset($yearlySnapshots[$m])) {
                $totalDeposit += (float) $yearlySnapshots[$m]['held_deposit'];
                break;
            }
        }
    }
}
$totalMonthlyUnpaid = 0;

// For monthly view, use the last day's unpaid balance as the final accumulated amount
if ($type === 'monthly') {
    // Get unpaid amount from the last day of the month
    $totalMonthlyUnpaid = $lastIncomeIndex >= 0 ? $incomeData[$lastIncomeIndex]['monthly_unpaid'] : 0;
} else {
    // For yearly view, sum all unpaid amounts
    $totalMonthlyUnpaid = array_sum(array_column($incomeData, 'monthly_unpaid'));
}

$grandTotal = (($settings['enable_daily'] ?? 1) ? $totalDaily : 0) + (($settings['enable_monthly'] ?? 1) ? $totalMonthly : 0);

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <?php if (!$showLockScreen): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i><?php echo t('income_report'); ?></h5>
        <a href="income_excel.php?year=<?php echo $year; ?>&type=<?php echo $type; ?>" class="btn btn-success">
            <i class="bi bi-download me-2"></i>Export Excel
        </a>
    </div>

    <!-- Summary Cards -->
    <style>
    @media (max-width: 767.98px) {
        .stat-cards-scroll {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            gap: 0.35rem;
            padding-bottom: 0.4rem;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .stat-cards-scroll::-webkit-scrollbar { display: none; }
        .stat-cards-scroll .stat-card-item {
            flex: 0 0 auto;
            min-width: 130px;
            width: calc(38% - 0.35rem);
        }
        .stat-cards-scroll .card-body {
            padding: 0.45rem 0.3rem;
            text-align: center;
        }
        .stat-cards-scroll .card-body h6 {
            font-size: 0.7rem;
            line-height: 1.25;
            margin-bottom: 0.2rem;
            word-break: break-word;
            white-space: normal;
        }
        .stat-cards-scroll .card-body h3 {
            font-size: 0.9rem;
            line-height: 1;
            margin-bottom: 0;
            white-space: nowrap;
        }
    }
    @media (min-width: 768px) {
        .stat-cards-scroll { display: flex; flex-wrap: wrap; gap: 0.75rem; }
        .stat-cards-scroll .stat-card-item { flex: 1 1 0; min-width: 0; }
    }
    </style>
    <div class="stat-cards-scroll mb-4">
        <div class="stat-card-item">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h6><?php echo t('grand_total'); ?></h6>
                    <h3><?php echo formatCurrency($grandTotal); ?></h3>
                </div>
            </div>
        </div>
        <?php if ($settings['enable_monthly'] ?? 1): ?>
        <div class="stat-card-item">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h6><?php echo t('paid_amount'); ?></h6>
                    <h3><?php echo formatCurrency($totalMonthly); ?></h3>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($settings['enable_daily'] ?? 1): ?>
        <div class="stat-card-item">
            <div class="card bg-danger text-white">
                <div class="card-body text-center">
                    <h6><?php echo t('total_daily_income'); ?></h6>
                    <h3><?php echo formatCurrency($totalDaily); ?></h3>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($settings['enable_monthly'] ?? 1): ?>
        <div class="stat-card-item">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h6><?php echo t('unpaid_amount'); ?></h6>
                    <h3><?php echo formatCurrency($totalMonthlyUnpaid); ?></h3>
                </div>
            </div>
        </div>
        <div class="stat-card-item">
            <div class="card bg-secondary text-white">
                <div class="card-body text-center">
                    <h6><?php echo t('deposit'); ?></h6>
                    <h3><?php echo formatCurrency($totalDeposit); ?></h3>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label"><?php echo t('year'); ?></label>
                    <select name="year" class="form-select">
                        <?php for ($y = date('Y'); $y >= date('Y') - 15; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?php echo t('month'); ?></label>
                    <select name="month" class="form-select" id="monthSelect">
                        <?php for ($m = 1; $m <= 12; $m++):
                            $monthVal = sprintf('%s-%02d', $year, $m);
                        ?>
                            <option value="<?php echo $monthVal; ?>" <?php echo $month == $monthVal ? 'selected' : ''; ?>><?php echo $localizedMonthsFull[$m - 1]; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?php echo t('format'); ?></label>
                    <select name="type" class="form-select" id="typeSelect" onchange="toggleMonthFilter()">
                        <option value="monthly" <?php echo $type === 'monthly' ? 'selected' : ''; ?>><?php echo t('monthly'); ?></option>
                        <option value="yearly" <?php echo $type === 'yearly' ? 'selected' : ''; ?>><?php echo t('yearly'); ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100"><?php echo t('show'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleMonthFilter() {
            const type = document.getElementById('typeSelect').value;
            const monthSelect = document.getElementById('monthSelect');
            monthSelect.disabled = (type === 'yearly');
        }
        // Initial state
        toggleMonthFilter();
    </script>

    <!-- Mobile Table Styles -->
    <style>
        /* Mobile: keep table data on single line and center aligned */
        .table td,
        .table th {
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
        }

        /* First column: left align for month/date */
        .table th:first-child,
        .table td:first-child {
            text-align: left;
            white-space: nowrap;
        }
    </style>

    <!-- Chart -->
    <?php if ($type !== 'daily'): ?>
        <div class="card mb-4 mt-4">
            <div class="card-header">
                <h5 class="mb-0"><?php echo t('income_chart'); ?></h5>
            </div>
            <div class="card-body">
                <canvas id="incomeChart" height="500"></canvas>
                <div id="noChartMessage" style="display: none; text-align: center; padding: 40px; color: #6c757d;">
                    <i class="bi bi-bar-chart-line" style="font-size: 3rem;"></i>
                    <p class="mt-3">ไม่มีข้อมูลสำหรับแสดงกราฟในช่วงเวลาที่เลือก</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Daily Income Table (Monthly/Yearly View) -->
    <?php if ($settings['enable_daily'] ?? 1): ?>
    <div class="card mb-4 mt-4">
        <div class="card-header">
            <h5 class="mb-0"><?php echo t('daily_income_details'); ?></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo $type === 'monthly' ? t('month') : t('year'); ?></th>
                            <th class="text-end"><?php echo t('daily_income'); ?></th>
                            <th class="text-end"><?php echo t('additional_charges'); ?></th>
                            <th class="text-end"><?php echo t('grand_total'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incomeData as $data): ?>
                            <tr>
                                <td><?php echo $data['period']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($data['daily']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($data['daily_extra'] ?? 0); ?></td>
                                <td class="text-end fw-bold"><?php echo formatCurrency(($data['daily'] ?? 0) + ($data['daily_extra'] ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-primary fw-bold">
                            <td><?php echo t('daily_income_total'); ?></td>
                            <td class="text-end"><?php echo formatCurrency($totalDailyRoom); ?></td>
                            <td class="text-end"><?php echo formatCurrency($totalDailyExtra); ?></td>
                            <td class="text-end"><?php echo formatCurrency($totalDaily); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Monthly Income Table -->
    <?php if ($settings['enable_monthly'] ?? 1): ?>
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0"><?php echo t('monthly_income_details'); ?></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo $type === 'monthly' ? t('month') : t('year'); ?></th>
                            <th class="text-end"><?php echo t('paid_amount'); ?></th>
                            <th class="text-end"><?php echo t('unpaid_amount'); ?></th>
                            <th class="text-end"><?php echo t('deposit'); ?></th>
                            <th class="text-end"><?php echo t('monthly_income_total'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incomeData as $data): ?>
                            <?php if (isset($data['is_unpaid_row']) && $data['is_unpaid_row']): ?>
                                <tr class="table-danger">
                                    <td><?php echo $data['period']; ?></td>
                                    <td class="text-end">-</td>
                                    <td class="text-end"><?php echo formatCurrency($data['monthly_unpaid']); ?></td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td><?php echo $data['period']; ?></td>
                                    <td class="text-end"><?php echo formatCurrency($data['monthly']); ?></td>
                                    <td class="text-end"><?php echo formatCurrency($data['monthly_unpaid']); ?></td>
                                    <td class="text-end"><?php echo formatCurrency($data['deposit'] ?? 0); ?></td>
                                    <td class="text-end fw-bold"><?php echo formatCurrency($data['monthly'] + $data['monthly_unpaid']); ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <tr class="table-primary fw-bold">
                            <td><?php echo t('monthly_income_total'); ?></td>
                            <td class="text-end"><?php echo formatCurrency($totalMonthly); ?></td>
                            <td class="text-end"><?php echo formatCurrency($totalMonthlyUnpaid); ?></td>
                            <td class="text-end"><?php echo formatCurrency($totalDeposit); ?></td>
                            <td class="text-end"><?php echo formatCurrency($totalMonthly + $totalMonthlyUnpaid); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php
$chartDatasets = [];
if ($settings['enable_daily'] ?? 1) {
    $chartDatasets[] = [
        'label' => t('daily_income'),
        'data' => array_map(function($d) { return (float)($d['daily'] ?? 0); }, $incomeData),
        'borderColor' => 'rgba(13, 202, 240, 1)',
        'backgroundColor' => 'rgba(13, 202, 240, 0.1)',
        'borderWidth' => 3,
        'tension' => 0,
        'fill' => true
    ];
    $chartDatasets[] = [
        'label' => t('additional_charges'),
        'data' => array_map(function($d) { return (float)($d['daily_extra'] ?? 0); }, $incomeData),
        'borderColor' => 'rgba(255, 193, 7, 1)',
        'backgroundColor' => 'rgba(255, 193, 7, 0.1)',
        'borderWidth' => 3,
        'tension' => 0.4,
        'fill' => true
    ];
}
if ($settings['enable_monthly'] ?? 1) {
    $chartDatasets[] = [
        'label' => t('paid_amount'),
        'data' => array_map(function($d) { return (float)($d['monthly'] ?? 0); }, $incomeData),
        'borderColor' => 'rgba(25, 135, 84, 1)',
        'backgroundColor' => 'rgba(25, 135, 84, 0.1)',
        'borderWidth' => 3,
        'tension' => 0,
        'fill' => true
    ];
    $chartDatasets[] = [
        'label' => t('unpaid_amount'),
        'data' => array_map(function($d) { return (float)($d['monthly_unpaid'] ?? 0); }, $incomeData),
        'borderColor' => 'rgba(220, 53, 69, 1)',
        'backgroundColor' => 'rgba(220, 53, 69, 0.1)',
        'borderWidth' => 3,
        'tension' => 0,
        'fill' => true
    ];
}
?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof Chart === 'undefined') {
            console.error('Chart.js ไม่ได้โหลด');
            document.getElementById('noChartMessage').style.display = 'block';
            document.getElementById('noChartMessage').innerHTML = '<i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: #dc3545;"></i><p class="mt-3">ไม่สามารถโหลดกราฟได้ กรุณาลองรีเฟรชหน้าเพจ</p>';
            return;
        }

        const incomeData = <?php echo json_encode($incomeData); ?>;
        console.log('Income data:', incomeData);
        console.log('Current type:', '<?php echo $type; ?>');

        // Only show chart for monthly and yearly views
        <?php if ($type !== 'daily'): ?>
            if (incomeData && incomeData.length > 0) {
                console.log('กำลังสร้างกราฟ...');
                document.getElementById('incomeChart').style.display = 'block';
                document.getElementById('noChartMessage').style.display = 'none';

                try {
                    new Chart(document.getElementById('incomeChart'), {
                        type: 'line',
                        data: {
                            labels: incomeData.map(d => d.period),
                            datasets: <?php echo json_encode($chartDatasets); ?>
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        maxTicksLimit: 20,
                                        callback: function(value) {
                                            return '฿' + value.toLocaleString('th-TH');
                                        }
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'top',
                                }
                            }
                        }
                    });
                    console.log('สร้างกราฟสำเร็จ');
                } catch (error) {
                    console.error('เกิดข้อผิดพลาดในการสร้างกราฟ:', error);
                    document.getElementById('incomeChart').style.display = 'none';
                    document.getElementById('noChartMessage').style.display = 'block';
                }
            } else {
                console.log('ไม่มีข้อมูลสำหรับกราฟ');
                document.getElementById('incomeChart').style.display = 'none';
                document.getElementById('noChartMessage').style.display = 'block';
            }
        <?php endif; ?>
    });
</script>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../../includes/pin-lockscreen-modal.php';
include __DIR__ . '/../../includes/footer.php'; 
?>
