<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$pageTitle = t('dashboard');
// dashboard page loaded

syncRoomStatuses();

// Get statistics
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
$currentMonth = date('Y-m');
[$currentMonthStart, $nextMonthStart] = monthDateRange($currentMonth);

// Keep dashboard income aligned with the income report calculations.
$paidAmountExpr = "CASE WHEN paid_amount > 0 THEN paid_amount ELSE total_amount END";

// Daily income today
$dailyIncomeToday = getDailyTenantRevenueByDate($today);

// Monthly income today
$stmt = $pdo->prepare("SELECT COALESCE(SUM($paidAmountExpr), 0) as income FROM utility_bills WHERE paid_date >= ? AND paid_date < ? AND status = 'paid'");
$stmt->execute([$today, $tomorrow]);
$monthlyIncomeToday = $stmt->fetch()['income'];

// Monthly income this month (for total calculation)
$stmt = $pdo->prepare("SELECT COALESCE(SUM($paidAmountExpr), 0) as income FROM utility_bills WHERE paid_date >= ? AND paid_date < ? AND status = 'paid'");
$stmt->execute([$currentMonthStart, $nextMonthStart]);
$monthlyIncome = $stmt->fetch()['income'];

// Total income this month (daily + monthly)
// คำนวณรายได้รายวันสำหรับเดือนปัจจุบันแบบง่ายๆ
$currentMonth = date('Y-m');

// คำนวณรายได้รายวันทั้งหมดที่เกิดขึ้นในเดือนนี้
$dailyIncomeCurrentMonth = getDailyTenantRevenueByMonth($currentMonth);
$totalIncomeCurrentMonth = $dailyIncomeCurrentMonth + $monthlyIncome;

// Total income today (daily + monthly)
$totalIncomeToday = $dailyIncomeToday + $monthlyIncomeToday;

// Daily tenants currently staying (กำลังพักอยู่)
$stmt = $pdo->query("SELECT COUNT(*) as count FROM daily_tenants WHERE status = 'checked_in'");
$dailyGuestsStaying = $stmt->fetch()['count'];

// Monthly tenants count
$stmt = $pdo->query("SELECT COUNT(*) as count FROM monthly_tenants WHERE status IN ('active', 'pending')");
$monthlyTenantsCount = $stmt->fetch()['count'];

// Get room statistics
$stmt = $pdo->query("
    SELECT 
        SUM(CASE WHEN r.status = 'available' THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN r.status = 'reserved' THEN 1 ELSE 0 END) as reserved,
        SUM(CASE WHEN r.status = 'occupied' THEN 1 ELSE 0 END) as occupied,
        SUM(CASE WHEN r.status = 'maintenance' THEN 1 ELSE 0 END) as maintenance
    FROM rooms r
");
$roomStats = $stmt->fetch();

// Calculate totals from room stats (handle NULL values)
$totalAvailable = (int)($roomStats['available'] ?? 0);
$totalReserved = (int)($roomStats['reserved'] ?? 0);
$totalOccupied = (int)($roomStats['occupied'] ?? 0);
$totalMaintenance = (int)($roomStats['maintenance'] ?? 0);

// Recent daily tenants
$stmt = $pdo->query("SELECT dt.*, r.room_number, rt.type_name FROM daily_tenants dt 
    JOIN rooms r ON dt.room_id = r.id 
    JOIN room_types rt ON r.room_type_id = rt.id 
    ORDER BY dt.created_at DESC LIMIT 5");
$recentDailyTenants = $stmt->fetchAll();

// Recent monthly tenants
$stmt = $pdo->query("SELECT mt.*, r.room_number FROM monthly_tenants mt 
    JOIN rooms r ON mt.room_id = r.id 
    ORDER BY mt.created_at DESC LIMIT 5");
$recentMonthlyTenants = $stmt->fetchAll();

// Get income chart data (last 6 months)
$chartData = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthLabel = date('M Y', strtotime("-$i months"));

    $dailyIncome = getDailyTenantRevenueByMonth($month);

    [$chartMonthStart, $nextChartMonthStart] = monthDateRange($month);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM($paidAmountExpr), 0) as monthly FROM utility_bills WHERE paid_date >= ? AND paid_date < ? AND status = 'paid'");
    $stmt->execute([$chartMonthStart, $nextChartMonthStart]);
    $monthlyIncomeChart = $stmt->fetch()['monthly'];

    $chartData[] = [
        'month' => $monthLabel,
        'daily' => $dailyIncome,
        'monthly' => $monthlyIncomeChart
    ];
}

$chartDatasets = [];
if (($settings['enable_daily'] ?? 1)) {
    $chartDatasets[] = [
        'label' => t('daily_income'),
        'data' => array_map('floatval', array_column($chartData, 'daily')),
        'borderColor' => 'rgba(54, 162, 235, 1)',
        'backgroundColor' => 'rgba(54, 162, 235, 0.1)',
        'borderWidth' => 3,
        'tension' => 0.4,
        'fill' => true
    ];
}
if (($settings['enable_monthly'] ?? 1)) {
    $chartDatasets[] = [
        'label' => t('monthly_income'),
        'data' => array_map('floatval', array_column($chartData, 'monthly')),
        'borderColor' => 'rgba(255, 99, 132, 1)',
        'backgroundColor' => 'rgba(255, 99, 132, 0.1)',
        'borderWidth' => 3,
        'tension' => 0,
        'fill' => true
    ];
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.room-status-card-body {
    min-height: 250px;
}
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
        min-width: 135px;
        width: calc(38% - 0.35rem);
    }
    .stat-cards-scroll .card-body {
        padding: 0.45rem 0.3rem;
        text-align: center;
    }
    .stat-cards-scroll .card-body .d-flex {
        flex-direction: column;
        align-items: center;
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
    .stat-cards-scroll .card-body .fs-1 { display: none; }
    .stat-cards-scroll .card-body .bi.me-2 { display: none; }

    .room-status-card-body {
        min-height: auto !important;
        padding: 0.75rem 0.25rem !important;
    }
    .room-status-row {
        flex-wrap: nowrap !important;
        margin-left: -0.15rem !important;
        margin-right: -0.15rem !important;
    }
    .room-status-row .col {
        padding-left: 0.15rem !important;
        padding-right: 0.15rem !important;
        flex: 1 0 0%;
        max-width: 20%;
    }
    .room-status-row .mb-3 {
        margin-bottom: 0.3rem !important;
    }
    .room-status-row .bi {
        font-size: 1.5rem !important;
    }
    .room-status-row h3 {
        font-size: 1.1rem !important;
    }
    .room-status-row small {
        font-size: 0.58rem !important;
        white-space: nowrap;
    }
}
@media (min-width: 768px) {
    .stat-cards-scroll { display: flex; flex-wrap: wrap; gap: 0.75rem; }
    .stat-cards-scroll .stat-card-item { flex: 1 1 0; min-width: 0; }
}
</style>

<div class="stat-cards-scroll mb-4">
    <?php if ($settings['enable_daily'] ?? 1): ?>
    <div class="stat-card-item">
        <div class="card bg-primary text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="mb-2"><i class="bi bi-people-fill me-2"></i><?php echo t('daily_tenants'); ?></h6>
                        <h3><?php echo $dailyGuestsStaying; ?> <?php echo t('people'); ?></h3>
                    </div>
                    <i class="bi bi-people fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($settings['enable_monthly'] ?? 1): ?>
    <div class="stat-card-item">
        <div class="card bg-success text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="mb-2"><i class="bi bi-house-door-fill me-2"></i><?php echo t('monthly_tenants'); ?></h6>
                        <h3><?php echo $monthlyTenantsCount; ?> <?php echo t('people'); ?></h3>
                    </div>
                    <i class="bi bi-person-check fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($settings['enable_daily'] ?? 1): ?>
    <div class="stat-card-item">
        <div class="card bg-danger text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="mb-2"><i class="bi bi-cash-stack me-2"></i><?php echo t('daily_income'); ?> (<?php echo t('today'); ?>)</h6>
                        <h3><?php echo formatCurrency($dailyIncomeToday); ?></h3>
                    </div>
                    <i class="bi bi-calendar-day fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($settings['enable_monthly'] ?? 1): ?>
    <div class="stat-card-item">
        <div class="card bg-warning text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="mb-2"><i class="bi bi-cash me-2"></i><?php echo t('monthly_income'); ?> (<?php echo t('today'); ?>)</h6>
                        <h3><?php echo formatCurrency($monthlyIncomeToday); ?></h3>
                    </div>
                    <i class="bi bi-calendar-month fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="stat-card-item">
        <div class="card bg-dark text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-2"><?php echo t('total_income'); ?> (<?php echo t('today'); ?>)</h6>
                        <h3><?php echo formatCurrency($totalIncomeToday); ?></h3>
                    </div>
                    <i class="bi bi-cash-stack fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6 mb-3 mb-md-0">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i><?php echo t('room_status'); ?></h5>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center room-status-card-body">
                <div class="row text-center w-100 room-status-row g-1">
                    <div class="col">
                        <div class="mb-3">
                            <i class="bi bi-door-open text-success" style="font-size: 2.5rem;"></i>
                        </div>
                        <h3 class="text-success mb-1"><?php echo $totalAvailable; ?></h3>
                        <small class="text-muted d-block"><?php echo t('available'); ?></small>
                    </div>
                    <div class="col">
                        <div class="mb-3">
                            <i class="bi bi-bookmark-check text-info" style="font-size: 2.5rem;"></i>
                        </div>
                        <h3 class="text-info mb-1"><?php echo $totalReserved; ?></h3>
                        <small class="text-muted d-block"><?php echo t('reserved'); ?></small>
                    </div>
                    <div class="col">
                        <div class="mb-3">
                            <i class="bi bi-door-closed text-danger" style="font-size: 2.5rem;"></i>
                        </div>
                        <h3 class="text-danger mb-1"><?php echo $totalOccupied; ?></h3>
                        <small class="text-muted d-block"><?php echo t('occupied'); ?></small>
                    </div>
                    <div class="col">
                        <div class="mb-3">
                            <i class="bi bi-tools text-warning" style="font-size: 2.5rem;"></i>
                        </div>
                        <h3 class="text-warning mb-1"><?php echo $totalMaintenance; ?></h3>
                        <small class="text-muted d-block"><?php echo t('maintenance'); ?></small>
                    </div>
                    <div class="col">
                        <div class="mb-3">
                            <i class="bi bi-houses text-primary" style="font-size: 2.5rem;"></i>
                        </div>
                        <h3 class="text-primary mb-1"><?php echo $totalAvailable + $totalReserved + $totalOccupied + $totalMaintenance; ?></h3>
                        <small class="text-muted d-block"><?php echo t('all'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-3 mb-md-0">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i><?php echo t('income_last_6_months'); ?></h5>
            </div>
            <div class="card-body">
                <canvas id="incomeChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <?php if ($settings['enable_daily'] ?? 1): ?>
    <div class="<?php echo ($settings['enable_monthly'] ?? 1) ? 'col-md-6' : 'col-12'; ?> mb-3 mb-md-0">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-calendar-day me-2"></i><?php echo t('recent_daily_tenants'); ?></h5>
                <a href="daily-tenants/index.php" class="btn btn-sm btn-primary"><?php echo t('view_all'); ?></a>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th><?php echo t('room'); ?></th>
                            <th><?php echo t('name'); ?></th>
                            <th><?php echo t('check_in'); ?></th>
                            <th><?php echo t('status'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentDailyTenants as $tenant):
                            // Mirror the same status rules used on daily-tenants/index.php.
                            if ($tenant['status'] === 'checked_out') {
                                $status = ['label' => t('checked_out'), 'class' => 'secondary'];
                            } elseif ($tenant['status'] === 'cancelled') {
                                $status = ['label' => t('cancelled'), 'class' => 'dark'];
                            } elseif (empty($tenant['status'])) {
                                if ($tenant['check_in_date'] == date('Y-m-d')) {
                                    $status = ['label' => t('check_in_today'), 'class' => 'warning'];
                                } elseif ($tenant['check_in_date'] > date('Y-m-d')) {
                                    $status = ['label' => t('reserved'), 'class' => 'info'];
                                } else {
                                    $status = ['label' => t('past_checkin_date'), 'class' => 'danger'];
                                }
                            } elseif ($tenant['status'] === 'checked_in' && empty($tenant['actual_check_in_date']) && $tenant['check_in_date'] == date('Y-m-d')) {
                                $status = ['label' => t('check_in_today'), 'class' => 'warning'];
                            } elseif ($tenant['status'] === 'checked_in' && empty($tenant['actual_check_in_date']) && $tenant['check_in_date'] > date('Y-m-d')) {
                                $status = ['label' => t('upcoming'), 'class' => 'info'];
                            } elseif ($tenant['status'] === 'checked_in' && empty($tenant['actual_check_in_date']) && $tenant['check_in_date'] < date('Y-m-d')) {
                                $status = ['label' => t('past_checkin_date'), 'class' => 'danger'];
                            } elseif ($tenant['status'] === 'checked_in' && !empty($tenant['actual_check_in_date']) && $tenant['check_out_date'] == date('Y-m-d') && empty($tenant['actual_check_out_date'])) {
                                $status = ['label' => t('checkout_today'), 'class' => 'warning'];
                            } elseif ($tenant['status'] === 'checked_in' && !empty($tenant['actual_check_in_date']) && $tenant['check_out_date'] < date('Y-m-d') && empty($tenant['actual_check_out_date'])) {
                                $status = ['label' => t('overdue_checkout'), 'class' => 'danger'];
                            } else {
                                $status = ['label' => t('checked_in'), 'class' => 'success'];
                            }
                        ?>
                            <tr>
                                <td><?php echo $tenant['room_number']; ?> (<?php echo $tenant['type_name']; ?>)</td>
                                <td><?php echo $tenant['guest_name']; ?></td>
                                <td><?php echo formatDate($tenant['check_in_date']); ?></td>
                                <td><span class="badge bg-<?php echo $status['class']; ?>"><?php echo $status['label']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentDailyTenants)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-3 text-muted"><?php echo t('no_data'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($settings['enable_monthly'] ?? 1): ?>
    <div class="<?php echo ($settings['enable_daily'] ?? 1) ? 'col-md-6' : 'col-12'; ?> mb-3 mb-md-0">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-calendar-month me-2"></i><?php echo t('recent_monthly_tenants'); ?></h5>
                <a href="monthly-tenants/index.php" class="btn btn-sm btn-primary"><?php echo t('view_all'); ?></a>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th><?php echo t('room'); ?></th>
                            <th><?php echo t('name'); ?></th>
                            <th><?php echo t('contract_end'); ?></th>
                            <th><?php echo t('status'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentMonthlyTenants as $tenant):
                            $status = calculateMonthlyStatus($tenant['contract_start'], $tenant['contract_end']);
                        ?>
                            <tr>
                                <td><?php echo $tenant['room_number']; ?></td>
                                <td><?php echo $tenant['tenant_name']; ?></td>
                                <td><?php echo formatDate($tenant['contract_end']); ?></td>
                                <td>
                                    <?php if ($tenant['status'] === 'terminated' && date('Y-m', strtotime($tenant['updated_at'])) === date('Y-m')): ?>
                                    <span class="badge bg-secondary"><?php echo t('contract_terminated'); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-<?php echo $status['class']; ?>"><?php echo $status['label']; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentMonthlyTenants)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-3 text-muted"><?php echo t('no_data'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    // ตรวจสอบว่า Chart.js โหลดหรือไม่
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof Chart === 'undefined') {
            console.error('Chart.js ไม่ได้โหลดใน dashboard');
            return;
        }

        const incomeData = <?php echo json_encode($chartData); ?>;
        console.log('Dashboard income data:', incomeData);

        try {
            new Chart(document.getElementById('incomeChart'), {
                type: 'line',
                data: {
                    labels: incomeData.map(d => d.month),
                    datasets: [
                        <?php if ($settings['enable_daily'] ?? 1): ?>
                        {
                            label: '<?php echo t('daily_income'); ?>',
                            data: incomeData.map(d => d.daily),
                            borderColor: 'rgba(54, 162, 235, 1)',
                            backgroundColor: 'rgba(54, 162, 235, 0.1)',
                            borderWidth: 3,
                            tension: 0.4,
                            fill: true
                        }
                        <?php endif; ?>
                        <?php if (($settings['enable_daily'] ?? 1) && ($settings['enable_monthly'] ?? 1)) echo ','; ?>
                        <?php if ($settings['enable_monthly'] ?? 1): ?>
                        {
                            label: '<?php echo t('monthly_income'); ?>',
                            data: incomeData.map(d => d.monthly),
                            borderColor: 'rgba(255, 99, 132, 1)',
                            backgroundColor: 'rgba(255, 99, 132, 0.1)',
                            borderWidth: 3,
                            tension: 0,
                            fill: true
                        }
                        <?php endif; ?>
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
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
            console.log('กราฟรายได้ 6 เดือนล่าสุดสร้างสำเร็จ');
        } catch (error) {
            console.error('เกิดข้อผิดพลาดในการสร้างกราฟรายได้:', error);
        }
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
