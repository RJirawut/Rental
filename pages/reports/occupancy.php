<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = t('occupancy_report');

// Filters
$year = $_GET['year'] ?? date('Y');
$type = $_GET['type'] ?? 'monthly';

// Get occupancy data
$occupancyData = [];

if ($type === 'monthly') {
    for ($m = 1; $m <= 12; $m++) {
        $month = sprintf('%s-%02d', $year, $m);
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        
        // Daily guests count
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM daily_tenants WHERE status = 'checked_in' AND check_in_date <= ? AND check_out_date >= ?");
        $stmt->execute([$monthEnd, $monthStart]);
        $dailyGuests = $stmt->fetch()['count'];
        
        // Monthly tenants count
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM monthly_tenants WHERE status = 'active' AND contract_start <= ? AND contract_end >= ?");
        $stmt->execute([$monthEnd, $monthStart]);
        $monthlyTenants = $stmt->fetch()['count'];
        
        $occupancyData[] = [
            'period' => date('M Y', strtotime($monthStart)),
            'daily' => $dailyGuests,
            'monthly' => $monthlyTenants,
            'total' => $dailyGuests + $monthlyTenants
        ];
    }
} else {
    // Yearly data
    for ($y = $year - 4; $y <= $year; $y++) {
        $yearStart = sprintf('%04d-01-01', $y);
        $nextYearStart = sprintf('%04d-01-01', $y + 1);

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM daily_tenants WHERE status = 'checked_in' AND check_in_date >= ? AND check_in_date < ?");
        $stmt->execute([$yearStart, $nextYearStart]);
        $dailyGuests = $stmt->fetch()['count'];
        
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT id) as count FROM monthly_tenants WHERE status = 'active' AND contract_start < ? AND contract_end >= ?");
        $stmt->execute([$nextYearStart, $yearStart]);
        $monthlyTenants = $stmt->fetch()['count'];
        
        $occupancyData[] = [
            'period' => $y,
            'daily' => $dailyGuests,
            'monthly' => $monthlyTenants,
            'total' => $dailyGuests + $monthlyTenants
        ];
    }
}

// Calculate totals
$totalDaily = array_sum(array_column($occupancyData, 'daily'));
$totalMonthly = array_sum(array_column($occupancyData, 'monthly'));
$grandTotal = $totalDaily + $totalMonthly;

include __DIR__ . '/../../includes/header.php';
?>

<div class="content-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i><?php echo t('occupancy_report'); ?></h5>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label"><?php echo t('year'); ?></label>
                    <select name="year" class="form-select">
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?php echo t('format'); ?></label>
                    <select name="type" class="form-select">
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
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h6><?php echo t('total_daily_guests'); ?></h6>
                    <h3><?php echo number_format($totalDaily); ?> คน</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h6><?php echo t('total_monthly_tenants'); ?></h6>
                    <h3><?php echo number_format($totalMonthly); ?> คน</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h6><?php echo t('grand_total'); ?></h6>
                    <h3><?php echo number_format($grandTotal); ?> คน</h3>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Chart -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?php echo t('occupancy_chart'); ?></h5>
        </div>
        <div class="card-body">
            <canvas id="occupancyChart" height="100"></canvas>
        </div>
    </div>
    
    <!-- Data Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><?php echo t('occupancy_details'); ?></h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?php echo $type === 'monthly' ? t('month') : t('year'); ?></th>
                        <th class="text-center"><?php echo t('daily_guests'); ?></th>
                        <th class="text-center"><?php echo t('monthly_tenants'); ?></th>
                        <th class="text-center"><?php echo t('total'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($occupancyData as $data): ?>
                    <tr>
                        <td><?php echo $data['period']; ?></td>
                        <td class="text-center"><?php echo number_format($data['daily']); ?> คน</td>
                        <td class="text-center"><?php echo number_format($data['monthly']); ?> คน</td>
                        <td class="text-center fw-bold"><?php echo number_format($data['total']); ?> คน</td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-primary fw-bold">
                        <td><?php echo t('total'); ?></td>
                        <td class="text-center"><?php echo number_format($totalDaily); ?> คน</td>
                        <td class="text-center"><?php echo number_format($totalMonthly); ?> คน</td>
                        <td class="text-center"><?php echo number_format($grandTotal); ?> คน</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const occupancyData = <?php echo json_encode($occupancyData); ?>;

new Chart(document.getElementById('occupancyChart'), {
    type: 'line',
    data: {
        labels: occupancyData.map(d => d.period),
        datasets: [{
            label: '<?php echo t('daily_guests'); ?>',
            data: occupancyData.map(d => d.daily),
            backgroundColor: 'rgba(13, 202, 240, 0.1)',
            borderColor: 'rgba(13, 202, 240, 1)',
            borderWidth: 2,
            tension: 0.4
        }, {
            label: '<?php echo t('monthly_tenants'); ?>',
            data: occupancyData.map(d => d.monthly),
            backgroundColor: 'rgba(13, 110, 253, 0.1)',
            borderColor: 'rgba(13, 110, 253, 1)',
            borderWidth: 2,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true }
        }
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
