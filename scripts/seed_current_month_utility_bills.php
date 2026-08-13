<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

if (!in_array('--confirm', $argv, true)) {
    exit("Usage: php scripts/seed_current_month_utility_bills.php --confirm [--count=1000]\n");
}

$count = 1000;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--count=')) {
        $count = (int) substr($argument, strlen('--count='));
    }
}

if ($count < 1 || $count > 10000) {
    exit("The count must be between 1 and 10000.\n");
}

require_once __DIR__ . '/../config/database.php';

set_time_limit(0);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$monthDate = new DateTimeImmutable('first day of this month');
$billMonth = $monthDate->format('Y-m');
$billDate = $monthDate->format('Y-m-d');
$marker = "DEMO-CURRENT-METER-{$billMonth}";

$markerStatement = $pdo->prepare('SELECT COUNT(*) FROM utility_bills WHERE notes = ?');
$markerStatement->execute([$marker]);
if ((int) $markerStatement->fetchColumn() > 0) {
    exit("Seeding stopped: current-month demo utility bills for {$billMonth} already exist.\n");
}

$adminUserId = (int) $pdo->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($adminUserId <= 0) {
    exit("Seeding stopped: an active user is required.\n");
}

$tenantStatement = $pdo->prepare(
    'SELECT mt.id AS tenant_id, mt.monthly_rent, mt.contract_start, r.id AS room_id, r.room_number
     FROM monthly_tenants mt
     INNER JOIN rooms r ON r.id = mt.room_id
     WHERE mt.contract_start <= ?
     ORDER BY mt.id ASC
     LIMIT ?'
);
$tenantStatement->bindValue(1, $billDate);
$tenantStatement->bindValue(2, $count, PDO::PARAM_INT);
$tenantStatement->execute();
$tenants = $tenantStatement->fetchAll(PDO::FETCH_ASSOC);

if (count($tenants) < $count) {
    exit("Seeding stopped: only " . count($tenants) . " eligible monthly tenants are available.\n");
}

$insertBill = $pdo->prepare(
    'INSERT INTO utility_bills
    (tenant_id, room_id, bill_month, bill_date, rent_amount, water_prev_reading, water_curr_reading, water_units, water_rate,
     water_amount, elec_prev_reading, elec_curr_reading, elec_units, elec_rate, elec_amount, other_fees, discount,
     total_amount, status, paid_date, notes, created_by, payment_token)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

try {
    $pdo->beginTransaction();

    foreach ($tenants as $index => $tenant) {
        $sequence = $index + 1;
        $waterUnits = 10 + ($sequence % 21);
        $elecUnits = 100 + ($sequence % 121);
        $waterRate = 25.00;
        $elecRate = 8.00;
        $waterAmount = $waterUnits * $waterRate;
        $elecAmount = $elecUnits * $elecRate;
        $otherFees = $sequence % 10 === 0 ? 100.00 : 0.00;
        $discount = $sequence % 25 === 0 ? 150.00 : 0.00;
        $total = (float) $tenant['monthly_rent'] + $waterAmount + $elecAmount + $otherFees - $discount;
        $statusSeed = $sequence % 20;
        $status = $statusSeed < 15 ? 'unpaid' : ($statusSeed < 19 ? 'paid' : 'overdue');
        $paidDate = $status === 'paid' ? $billDate : null;
        $waterPrevious = 500 + ($sequence * 4);
        $elecPrevious = 5000 + ($sequence * 15);

        $insertBill->execute([
            (int) $tenant['tenant_id'],
            (int) $tenant['room_id'],
            $billMonth,
            $billDate,
            (float) $tenant['monthly_rent'],
            $waterPrevious,
            $waterPrevious + $waterUnits,
            $waterUnits,
            $waterRate,
            $waterAmount,
            $elecPrevious,
            $elecPrevious + $elecUnits,
            $elecUnits,
            $elecRate,
            $elecAmount,
            $otherFees,
            $discount,
            $total,
            $status,
            $paidDate,
            $marker,
            $adminUserId,
            hash('sha256', "{$marker}-{$tenant['tenant_id']}"),
        ]);
    }

    $pdo->commit();
    echo "Created {$count} current-month utility bills for {$billMonth}.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
