<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

if (!in_array('--confirm', $argv, true)) {
    exit("Usage: php scripts/seed_demo_data.php --confirm\n");
}

require_once __DIR__ . '/../config/database.php';

set_time_limit(0);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$targets = [
    'room_types' => 4,
    'rooms' => 100,
    'daily_tenants' => 2000,
    'monthly_tenants' => 2000,
    'utility_bills' => 24000,
    'invoices' => 2000,
    'payment_confirmations' => 2000,
    'repair_requests' => 1000,
    'email_queue' => 2000,
];

foreach (array_keys($targets) as $table) {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($count > 0) {
        exit("Seeding stopped: table '{$table}' already contains {$count} record(s).\n");
    }
}

$adminUserId = (int) $pdo->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($adminUserId <= 0) {
    exit("Seeding stopped: an active user is required.\n");
}

function demoDate(string $startDate, int $offsetDays): string
{
    return (new DateTimeImmutable($startDate))->modify("+{$offsetDays} days")->format('Y-m-d');
}

function demoDateTime(string $startDate, int $offsetDays): string
{
    return (new DateTimeImmutable($startDate))->modify("+{$offsetDays} days")->format('Y-m-d H:i:s');
}

function demoRoomNumber(int $index): string
{
    $floor = intdiv($index - 1, 10) + 1;
    $room = (($index - 1) % 10) + 1;

    return sprintf('%d%02d', $floor, $room);
}

function demoName(string $prefix, int $index): string
{
    return sprintf('%s %04d', $prefix, $index);
}

try {
    $pdo->beginTransaction();

    $roomTypes = [
        ['DEMO Standard', 'Demo Standard', 650.00, 4500.00, 'ห้องมาตรฐานสำหรับข้อมูลทดสอบ'],
        ['DEMO Superior', 'Demo Superior', 850.00, 6000.00, 'ห้องซูพีเรียสำหรับข้อมูลทดสอบ'],
        ['DEMO Deluxe', 'Demo Deluxe', 1050.00, 7500.00, 'ห้องดีลักซ์สำหรับข้อมูลทดสอบ'],
        ['DEMO Suite', 'Demo Suite', 1400.00, 9800.00, 'ห้องสวีทสำหรับข้อมูลทดสอบ'],
    ];
    $insertRoomType = $pdo->prepare(
        'INSERT INTO room_types (type_name, type_name_en, price_daily, price_monthly, description, description_en, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $roomTypeIds = [];
    foreach ($roomTypes as [$typeName, $typeNameEn, $dailyPrice, $monthlyPrice, $description]) {
        $insertRoomType->execute([$typeName, $typeNameEn, $dailyPrice, $monthlyPrice, $description, $description]);
        $roomTypeIds[] = (int) $pdo->lastInsertId();
    }

    $insertRoom = $pdo->prepare(
        'INSERT INTO rooms (room_number, room_type_id, floor, status, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $rooms = [];
    for ($index = 1; $index <= $targets['rooms']; $index++) {
        $status = $index <= 40 ? 'occupied' : ($index <= 55 ? 'reserved' : ($index <= 60 ? 'maintenance' : 'available'));
        $roomNumber = demoRoomNumber($index);
        $insertRoom->execute([
            $roomNumber,
            $roomTypeIds[($index - 1) % count($roomTypeIds)],
            intdiv($index - 1, 10) + 1,
            $status,
            'ข้อมูลห้องจำลอง',
        ]);
        $rooms[] = ['id' => (int) $pdo->lastInsertId(), 'number' => $roomNumber, 'index' => $index];
    }

    $insertDailyTenant = $pdo->prepare(
        'INSERT INTO daily_tenants
        (room_id, guest_name, phone, email, customer_tax_id, customer_address, customer_branch, check_in_date, check_out_date,
         actual_check_in_date, actual_check_out_date, num_guests, daily_rate, total_days, total_amount, other_fees, deposit,
         status, cancel_refunded, payment_deadline, notes, created_by, payment_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $dailyTenants = [];
    for ($index = 1; $index <= $targets['daily_tenants']; $index++) {
        $room = $rooms[($index - 1) % count($rooms)];
        $isUpcomingReservation = $index <= 15;
        $checkIn = $isUpcomingReservation
            ? demoDate(date('Y-m-d'), $index)
            : demoDate('2021-01-01', ($index * 5) % 1850);
        $days = ($index % 5) + 1;
        $checkOut = demoDate($checkIn, $days);
        $dailyRate = $roomTypes[($room['index'] - 1) % count($roomTypes)][2];
        $otherFees = (float) (($index % 4) * 50);
        $totalAmount = ($dailyRate * $days) + $otherFees;
        $status = $isUpcomingReservation ? 'checked_in' : 'checked_out';
        $actualCheckIn = $isUpcomingReservation ? null : $checkIn;
        $actualCheckOut = $isUpcomingReservation ? null : $checkOut;

        $insertDailyTenant->execute([
            $room['id'],
            demoName('ผู้เข้าพักรายวันจำลอง', $index),
            sprintf('081%07d', $index),
            sprintf('daily%04d@example.test', $index),
            null,
            'ที่อยู่ข้อมูลจำลอง',
            '00000',
            $checkIn,
            $checkOut,
            $actualCheckIn,
            $actualCheckOut,
            ($index % 4) + 1,
            $dailyRate,
            $days,
            $totalAmount,
            $otherFees,
            500.00,
            $status,
            0,
            $isUpcomingReservation ? demoDateTime($checkIn, 1) : null,
            'ข้อมูลผู้เข้าพักรายวันจำลอง',
            $adminUserId,
            hash('sha256', "demo-daily-payment-{$index}"),
        ]);
        $dailyTenants[] = [
            'id' => (int) $pdo->lastInsertId(),
            'room_id' => $room['id'],
            'room_number' => $room['number'],
            'name' => demoName('ผู้เข้าพักรายวันจำลอง', $index),
            'amount' => $totalAmount,
        ];
    }

    $insertMonthlyTenant = $pdo->prepare(
        'INSERT INTO monthly_tenants
        (room_id, tenant_name, phone, email, customer_tax_id, customer_address, customer_branch, contract_start, contract_end,
         monthly_rent, deposit, status, emergency_contact, emergency_phone, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $monthlyTenants = [];
    for ($index = 1; $index <= $targets['monthly_tenants']; $index++) {
        $room = $index <= 40 ? $rooms[$index - 1] : $rooms[($index - 1) % count($rooms)];
        $isActive = $index <= 40;
        $contractStart = $isActive ? '2026-01-01' : demoDate('2020-01-01', ($index * 17) % 1600);
        $contractEnd = $isActive ? '2026-12-31' : demoDate($contractStart, 364);
        $monthlyRent = $roomTypes[($room['index'] - 1) % count($roomTypes)][3];

        $insertMonthlyTenant->execute([
            $room['id'],
            demoName('ผู้เช่ารายเดือนจำลอง', $index),
            sprintf('082%07d', $index),
            sprintf('monthly%04d@example.test', $index),
            null,
            'ที่อยู่ข้อมูลจำลอง',
            '00000',
            $contractStart,
            $contractEnd,
            $monthlyRent,
            $monthlyRent,
            $isActive ? 'active' : 'expired',
            demoName('ผู้ติดต่อฉุกเฉินจำลอง', $index),
            sprintf('083%07d', $index),
            'ข้อมูลสัญญารายเดือนจำลอง',
            $adminUserId,
        ]);
        $monthlyTenants[] = [
            'id' => (int) $pdo->lastInsertId(),
            'room_id' => $room['id'],
            'room_number' => $room['number'],
            'name' => demoName('ผู้เช่ารายเดือนจำลอง', $index),
            'rent' => $monthlyRent,
        ];
    }

    $insertUtilityBill = $pdo->prepare(
        'INSERT INTO utility_bills
        (tenant_id, room_id, bill_month, bill_date, rent_amount, water_prev_reading, water_curr_reading, water_units, water_rate,
         water_amount, elec_prev_reading, elec_curr_reading, elec_units, elec_rate, elec_amount, other_fees, discount,
         total_amount, status, paid_date, notes, created_by, payment_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $utilityBillsForPayments = [];
    for ($index = 1; $index <= $targets['utility_bills']; $index++) {
        $tenant = $monthlyTenants[($index - 1) % count($monthlyTenants)];
        $monthOffset = intdiv($index - 1, count($monthlyTenants));
        $billDate = (new DateTimeImmutable('2025-01-01'))->modify("+{$monthOffset} months");
        $waterUnits = 8 + ($index % 22);
        $elecUnits = 90 + ($index % 130);
        $waterRate = 25.00;
        $elecRate = 8.00;
        $waterAmount = $waterUnits * $waterRate;
        $elecAmount = $elecUnits * $elecRate;
        $otherFees = ($index % 5 === 0) ? 100.00 : 0.00;
        $discount = ($index % 17 === 0) ? 150.00 : 0.00;
        $total = $tenant['rent'] + $waterAmount + $elecAmount + $otherFees - $discount;
        $statusSeed = $index % 20;
        $status = $statusSeed < 12 ? 'paid' : ($statusSeed < 19 ? 'unpaid' : 'overdue');
        $paidDate = $status === 'paid' ? $billDate->modify('+10 days')->format('Y-m-d') : null;

        $insertUtilityBill->execute([
            $tenant['id'],
            $tenant['room_id'],
            $billDate->format('Y-m'),
            $billDate->format('Y-m-d'),
            $tenant['rent'],
            100 + (($index - 1) * 3),
            100 + (($index - 1) * 3) + $waterUnits,
            $waterUnits,
            $waterRate,
            $waterAmount,
            1000 + (($index - 1) * 10),
            1000 + (($index - 1) * 10) + $elecUnits,
            $elecUnits,
            $elecRate,
            $elecAmount,
            $otherFees,
            $discount,
            $total,
            $status,
            $paidDate,
            'บิลค่าสาธารณูปโภคจำลอง',
            $adminUserId,
            hash('sha256', "demo-utility-payment-{$index}"),
        ]);

        if ($index <= 1000) {
            $utilityBillsForPayments[] = [
                'id' => (int) $pdo->lastInsertId(),
                'room_number' => $tenant['room_number'],
                'tenant_name' => $tenant['name'],
                'amount' => $total,
            ];
        }
    }

    $insertInvoice = $pdo->prepare(
        'INSERT INTO invoices
        (invoice_number, document_type, invoice_type, tenant_type, tenant_id, room_id, invoice_date, due_date, subtotal,
         vat_rate, vat_amount, discount, grand_total, customer_tax_id, customer_branch_code, seller_branch_code, status, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertInvoiceItem = $pdo->prepare(
        'INSERT INTO invoice_items (invoice_id, item_description, item_description_en, quantity, unit_price, total_price)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    for ($index = 1; $index <= $targets['invoices']; $index++) {
        $isDaily = $index % 2 === 1;
        $tenant = $isDaily ? $dailyTenants[($index - 1) % count($dailyTenants)] : $monthlyTenants[($index - 1) % count($monthlyTenants)];
        $total = $isDaily ? $tenant['amount'] : $tenant['rent'];
        $invoiceDate = demoDate('2025-01-01', $index % 365);
        $invoiceType = $isDaily ? 'daily' : 'monthly';
        $status = $index % 5 === 0 ? 'issued' : 'paid';

        $insertInvoice->execute([
            sprintf('DEMO-REC-%06d', $index),
            'receipt',
            $invoiceType,
            $invoiceType,
            $tenant['id'],
            $tenant['room_id'],
            $invoiceDate,
            demoDate($invoiceDate, 7),
            $total,
            0.00,
            0.00,
            0.00,
            $total,
            null,
            '00000',
            '00000',
            $status,
            'ใบเสร็จข้อมูลจำลอง',
            $adminUserId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();
        $insertInvoiceItem->execute([
            $invoiceId,
            $isDaily ? 'ค่าที่พักรายวัน (ข้อมูลจำลอง)' : 'ค่าเช่ารายเดือน (ข้อมูลจำลอง)',
            $isDaily ? 'Demo daily accommodation' : 'Demo monthly rent',
            1,
            $total,
            $total,
        ]);
    }

    $insertPaymentConfirmation = $pdo->prepare(
        'INSERT INTO payment_confirmations
        (bill_type, bill_id, room_number, tenant_name, amount, payment_method, transfer_date, transfer_time, slip_image,
         status, admin_note, verified_by, verified_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    for ($index = 1; $index <= $targets['payment_confirmations']; $index++) {
        $isMonthly = $index <= 1000;
        $source = $isMonthly
            ? $utilityBillsForPayments[$index - 1]
            : $dailyTenants[$index - 1001];
        $status = $index % 10 < 6 ? 'approved' : ($index % 10 < 8 ? 'pending_verify' : 'rejected');
        $createdAt = demoDateTime('2025-01-01', $index % 365);
        $verifiedAt = $status === 'pending_verify' ? null : demoDateTime('2025-01-01', ($index % 365) + 1);

        $insertPaymentConfirmation->execute([
            $isMonthly ? 'monthly' : 'daily',
            $source['id'],
            $source['room_number'],
            $isMonthly ? $source['tenant_name'] : $source['name'],
            $source['amount'],
            $index % 2 === 0 ? 'promptpay' : 'bank_transfer',
            substr($createdAt, 0, 10),
            '10:30:00',
            null,
            $status,
            $status === 'rejected' ? 'ข้อมูลจำลอง: โปรดตรวจสอบสลิปอีกครั้ง' : null,
            $status === 'pending_verify' ? null : $adminUserId,
            $verifiedAt,
            $createdAt,
        ]);
    }

    $insertRepairRequest = $pdo->prepare(
        'INSERT INTO repair_requests
        (ticket_number, room_id, room_number, reporter_name, phone, email, title, description, priority, status, image,
         admin_note, resolved_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    for ($index = 1; $index <= $targets['repair_requests']; $index++) {
        $room = $rooms[($index - 1) % count($rooms)];
        $status = $index % 10 < 4 ? 'pending' : ($index % 10 < 7 ? 'in_progress' : 'completed');
        $createdAt = demoDateTime('2025-01-01', $index % 365);
        $insertRepairRequest->execute([
            sprintf('DEMO-RP-%06d', $index),
            $room['id'],
            $room['number'],
            demoName('ผู้แจ้งซ่อมจำลอง', $index),
            sprintf('084%07d', $index),
            sprintf('repair%04d@example.test', $index),
            sprintf('ข้อมูลจำลอง: งานซ่อมรายการ %04d', $index),
            'รายละเอียดคำขอซ่อมสำหรับทดสอบการค้นหา การกรอง และการติดตามสถานะ',
            $index % 15 === 0 ? 'very_urgent' : ($index % 5 === 0 ? 'urgent' : 'normal'),
            $status,
            null,
            $status === 'completed' ? 'ข้อมูลจำลอง: ดำเนินการเรียบร้อยแล้ว' : null,
            $status === 'completed' ? demoDateTime('2025-01-01', ($index % 365) + 2) : null,
            $createdAt,
        ]);
    }

    $insertEmail = $pdo->prepare(
        'INSERT INTO email_queue
        (to_email, to_name, subject, html_body, plain_body, status, attempts, max_attempts, error_message, created_at, sent_at, attachments)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    for ($index = 1; $index <= $targets['email_queue']; $index++) {
        $status = $index % 10 < 8 ? 'sent' : 'failed';
        $createdAt = demoDateTime('2025-01-01', $index % 365);
        $insertEmail->execute([
            sprintf('demo-recipient+%04d@example.test', $index),
            demoName('ผู้รับอีเมลจำลอง', $index),
            sprintf('ข้อมูลจำลอง: แจ้งเตือนรายการ %04d', $index),
            '<p>ข้อความอีเมลสำหรับข้อมูลจำลอง</p>',
            'ข้อความอีเมลสำหรับข้อมูลจำลอง',
            $status,
            $status === 'sent' ? 1 : 3,
            3,
            $status === 'failed' ? 'ข้อมูลจำลอง: การส่งอีเมลล้มเหลว' : null,
            $createdAt,
            $status === 'sent' ? demoDateTime('2025-01-01', ($index % 365) + 1) : null,
            null,
        ]);
    }

    $pdo->commit();

    echo "Demo data created successfully:\n";
    foreach ($targets as $table => $count) {
        echo "- {$table}: {$count}\n";
    }
    echo "- invoice_items: {$targets['invoices']}\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
