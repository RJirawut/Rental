<?php
// Load Composer autoload directly
require_once __DIR__ . '/../../vendor/autoload.php';

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Debug: Check if PhpSpreadsheet is loaded
if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    die('PhpSpreadsheet not loaded. Please run: composer install');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Get year parameter
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$lang = $_SESSION['lang'] ?? 'th';

$localizedMonthsFull = localizedMonthNames($lang);

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();

// Remove default sheet
$spreadsheet->removeSheetByIndex(0);

// Style headers
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1F4E79'] // Slate Blue
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN]
    ]
];

$titleStyle = [
    'font' => [
        'bold' => true,
        'size' => 14,
        'color' => ['rgb' => '1F4E79']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ]
];

$dataBorder = [
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0D0D0']]
    ]
];

$totalStyle = [
    'font' => ['bold' => true],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'F2F2F2']
    ],
    'borders' => [
        'top' => ['borderStyle' => Border::BORDER_THIN],
        'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]
    ]
];

// ==========================================
// SHEET 1: ผู้เช่ารายวัน (Daily Tenants)
// ==========================================
$sheet1 = $spreadsheet->createSheet();
$sheet1->setTitle($lang === 'en' ? 'Daily Tenants' : 'ผู้เช่ารายวัน');

// Set title row
$sheet1->setCellValue('A1', ($lang === 'en' ? 'Daily Tenants Report - Year ' : 'รายงานผู้เข้าพักรายวัน - ปี ') . $year);
$sheet1->mergeCells('A1:J1');
$sheet1->getStyle('A1')->applyFromArray($titleStyle);
$sheet1->getRowDimension(1)->setRowHeight(35);

// Set Headers
$headers1 = [
    $lang === 'en' ? 'Room' : 'ห้อง',
    $lang === 'en' ? 'Booking Date' : 'วันที่จอง',
    $lang === 'en' ? 'Guest Name' : 'ชื่อผู้เข้าพัก',
    $lang === 'en' ? 'Phone' : 'เบอร์โทรศัพท์',
    $lang === 'en' ? 'Email' : 'อีเมล',
    $lang === 'en' ? 'Check In' : 'เช็คอิน',
    $lang === 'en' ? 'Check Out' : 'เช็คเอาท์',
    $lang === 'en' ? 'Nights' : 'จำนวนวัน',
    $lang === 'en' ? 'Total Amount' : 'ยอดเงิน',
    $lang === 'en' ? 'Status' : 'สถานะ'
];

$cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
foreach ($cols as $idx => $col) {
    $sheet1->setCellValue($col . '2', $headers1[$idx]);
}
$sheet1->getStyle('A2:J2')->applyFromArray($headerStyle);
$sheet1->getRowDimension(2)->setRowHeight(25);

// Query daily tenants
$stmt1 = $pdo->prepare("
    SELECT dt.*, r.room_number, rt.type_name, rt.type_name_en 
    FROM daily_tenants dt 
    JOIN rooms r ON dt.room_id = r.id 
    JOIN room_types rt ON r.room_type_id = rt.id 
    WHERE dt.check_in_date >= ? AND dt.check_in_date <= ?
    ORDER BY dt.check_in_date ASC, r.room_number ASC
");
$stmt1->execute(["$year-01-01", "$year-12-31"]);
$dailyTenants = $stmt1->fetchAll();

$row = 3;
$totalDailyAmount = 0;

foreach ($dailyTenants as $tenant) {
    $isCancelledRefunded = $tenant['status'] === 'cancelled' && !empty($tenant['cancel_refunded']);
    $rowTotal = (float) $tenant['total_amount'];
    $displayDays = (int) $tenant['total_days'];

    if ($isCancelledRefunded) {
        $rowTotal = 0;
    }

    $actualStartDate = !empty($tenant['actual_check_in_date']) ? $tenant['actual_check_in_date'] : $tenant['check_in_date'];
    $actualEndDate = $tenant['check_out_date'];

    if (!empty($tenant['actual_check_out_date']) && $tenant['actual_check_out_date'] > $tenant['check_out_date']) {
        $actualEndDate = $tenant['actual_check_out_date'];
    } elseif (empty($tenant['actual_check_out_date']) && $tenant['status'] === 'checked_in' && date('Y-m-d') > $tenant['check_out_date']) {
        $actualEndDate = date('Y-m-d');
    }

    if (!empty($actualStartDate) && !empty($actualEndDate)) {
        $displayDays = max(1, (new DateTime($actualStartDate))->diff(new DateTime($actualEndDate))->days);
    }

    // Early check-in charge
    if (!$isCancelledRefunded && !empty($tenant['actual_check_in_date']) && $tenant['actual_check_in_date'] < $tenant['check_in_date']) {
        $actualIn = new DateTime($tenant['actual_check_in_date']);
        $scheduledIn = new DateTime($tenant['check_in_date']);
        $rowTotal += $actualIn->diff($scheduledIn)->days * (float) $tenant['daily_rate'];
    }
    // Late checkout charge
    if (!$isCancelledRefunded && !empty($tenant['actual_check_out_date']) && $tenant['actual_check_out_date'] > $tenant['check_out_date']) {
        $expected = new DateTime($tenant['check_out_date']);
        $actual = new DateTime($tenant['actual_check_out_date']);
        $rowTotal += $expected->diff($actual)->days * (float) $tenant['daily_rate'];
    } elseif (!$isCancelledRefunded && empty($tenant['actual_check_out_date']) && $tenant['status'] === 'checked_in' && date('Y-m-d') > $tenant['check_out_date']) {
        $expected = new DateTime($tenant['check_out_date']);
        $today = new DateTime();
        $rowTotal += $expected->diff($today)->days * (float) $tenant['daily_rate'];
    }

    // Translate status
    $statusLabel = '';
    if ($tenant['status'] === 'checked_out') {
        $statusLabel = t('checked_out');
    } elseif ($tenant['status'] === 'cancelled') {
        $statusLabel = t('cancelled');
    } elseif (empty($tenant['status'])) {
        if ($tenant['check_in_date'] == date('Y-m-d')) {
            $statusLabel = t('check_in_today');
        } elseif ($tenant['check_in_date'] > date('Y-m-d')) {
            $statusLabel = t('reserved');
        } else {
            $statusLabel = t('no_show');
        }
    } elseif ($tenant['status'] === 'checked_in') {
        if ($tenant['check_out_date'] == date('Y-m-d')) {
            $statusLabel = t('checkout_today');
        } elseif ($tenant['check_out_date'] < date('Y-m-d')) {
            $statusLabel = t('overdue_checkout');
        } else {
            $statusLabel = t('currently_staying');
        }
    } else {
        $statusLabel = $tenant['status'];
    }

    $sheet1->setCellValue('A' . $row, $tenant['room_number']);
    $sheet1->setCellValue('B' . $row, date('d/m/Y', strtotime($tenant['created_at'])));
    $sheet1->setCellValue('C' . $row, $tenant['guest_name']);
    $sheet1->setCellValue('D' . $row, $tenant['phone']);
    $sheet1->setCellValue('E' . $row, $tenant['email'] ?? '');
    $sheet1->setCellValue('F' . $row, date('d/m/Y', strtotime($tenant['check_in_date'])));
    $sheet1->setCellValue('G' . $row, date('d/m/Y', strtotime($tenant['check_out_date'])));
    $sheet1->setCellValue('H' . $row, $displayDays);
    $sheet1->setCellValue('I' . $row, $rowTotal);
    $sheet1->setCellValue('J' . $row, $statusLabel);

    // Formats and Alignments
    $sheet1->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet1->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle('G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle('H' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet1->getStyle('J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle('A' . $row . ':J' . $row)->applyFromArray($dataBorder);

    $totalDailyAmount += $rowTotal;
    $row++;
}

// Total row for Sheet 1
$sheet1->setCellValue('A' . $row, $lang === 'en' ? 'Total' : 'รวม');
$sheet1->mergeCells('A' . $row . ':H' . $row);
$sheet1->setCellValue('I' . $row, $totalDailyAmount);
$sheet1->getStyle('A' . $row . ':J' . $row)->applyFromArray($totalStyle);
$sheet1->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet1->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet1->getRowDimension($row)->setRowHeight(22);

// Auto size columns for Sheet 1
foreach ($cols as $col) {
    $sheet1->getColumnDimension($col)->setAutoSize(true);
}


// ==========================================
// SHEET 2: ผู้เช่ารายเดือน (Monthly Tenants)
// ==========================================
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle($lang === 'en' ? 'Monthly Tenants' : 'ผู้เช่ารายเดือน');

// Set title row
$sheet2->setCellValue('A1', ($lang === 'en' ? 'Monthly Tenants Report - Year ' : 'รายงานผู้เช่ารายเดือน - ปี ') . $year);
$sheet2->mergeCells('A1:M1');
$sheet2->getStyle('A1')->applyFromArray($titleStyle);
$sheet2->getRowDimension(1)->setRowHeight(35);

// Set Headers
$headers2 = [
    $lang === 'en' ? 'Month' : 'เดือน',
    $lang === 'en' ? 'Room' : 'ห้อง',
    $lang === 'en' ? 'Tenant Name' : 'ชื่อผู้เช่า',
    $lang === 'en' ? 'Monthly Rent' : 'ค่าเช่ารายเดือน',
    $lang === 'en' ? 'Water' : 'ค่าน้ำ',
    $lang === 'en' ? 'Electric' : 'ค่าไฟ',
    $lang === 'en' ? 'Other Fees' : 'อื่นๆ',
    $lang === 'en' ? 'Discount' : 'ส่วนลด',
    $lang === 'en' ? 'Total' : 'ยอดรวม',
    $lang === 'en' ? 'Contract Status' : 'สถานะสัญญา',
    $lang === 'en' ? 'Payment Status' : 'สถานะการชำระเงิน',
    $lang === 'en' ? 'Phone' : 'เบอร์โทรศัพท์',
    $lang === 'en' ? 'Email' : 'อีเมล'
];

$cols2 = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'];
foreach ($cols2 as $idx => $col) {
    $sheet2->setCellValue($col . '2', $headers2[$idx]);
}
$sheet2->getStyle('A2:M2')->applyFromArray($headerStyle);
$sheet2->getRowDimension(2)->setRowHeight(25);

$row = 3;
$paymentDueDay = $pdo->query("SELECT payment_due_day FROM settings LIMIT 1")->fetchColumn() ?: 5;

$grandTotalRent = 0;
$grandTotalWater = 0;
$grandTotalElec = 0;
$grandTotalOthers = 0;
$grandTotalDiscount = 0;
$grandTotalMonthlyTotal = 0;

for ($m = 1; $m <= 12; $m++) {
    $monthStr = sprintf('%s-%02d', $year, $m);
    $monthStart = $monthStr . '-01';
    $nextMonthStart = date('Y-m-d', strtotime($monthStart . ' +1 month'));
    $monthEnd = date('Y-m-d', strtotime($monthStart . ' last day of this month'));
    
    // Get active tenants for this month
    $stmt2 = $pdo->prepare("
        SELECT mt.*, r.room_number, rt.type_name, rt.type_name_en, rt.price_monthly,
               ub.water_amount, ub.elec_amount, ub.other_fees, ub.discount, ub.total_amount,
               ub.bill_month, ub.status as bill_status,
               DATE_FORMAT(mt.updated_at, '%Y-%m') as termination_month
        FROM monthly_tenants mt
        JOIN rooms r ON mt.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN (
            SELECT ub1.*
            FROM utility_bills ub1
            JOIN (
                SELECT tenant_id, bill_month, MAX(id) AS max_id
                FROM utility_bills
                WHERE bill_month = ?
                GROUP BY tenant_id, bill_month
            ) latest_ub ON latest_ub.max_id = ub1.id
        ) ub ON ub.tenant_id = mt.id
        WHERE mt.contract_start < ?
          AND mt.contract_end >= ?
          AND (mt.status != 'terminated' OR mt.updated_at >= ?)
        ORDER BY r.room_number ASC
    ");
    $stmt2->execute([$monthStr, $nextMonthStart, $monthStart, $monthStart]);
    $tenants = $stmt2->fetchAll();

    foreach ($tenants as $tenant) {
        $contractStartMonth = date('Y-m', strtotime($tenant['contract_start']));
        $contractEndMonth = date('Y-m', strtotime($tenant['contract_end']));
        $isTerminationMonth = $tenant['status'] === 'terminated' && $tenant['termination_month'] === $monthStr;
        $isContractEndMonth = $contractEndMonth === $monthStr;

        if ($isTerminationMonth) {
            $statusLabel = t('contract_terminated');
        } elseif ($isContractEndMonth && (date('Y-m-d') > $tenant['contract_end'] || $tenant['status'] === 'expired')) {
            $statusLabel = t('contract_expired');
        } elseif ($isContractEndMonth && strtotime($tenant['contract_end']) <= strtotime($monthEnd)) {
            $statusLabel = t('contract_expiring');
        } elseif ($contractStartMonth === $monthStr && strtotime($tenant['contract_start']) > strtotime($monthStart) && date('Y-m-d') < $tenant['contract_start']) {
            $statusLabel = t('contract_pending');
        } else {
            $statusLabel = t('contract_active');
        }

        // Calculate payment status
        $paymentStatus = '';
        if ($tenant['bill_status'] === 'paid') {
            $paymentStatus = t('paid');
        } elseif ($tenant['bill_month'] === null) {
            $paymentStatus = t('waiting_meter');
        } elseif ($tenant['bill_month'] !== null && ($tenant['bill_status'] === null || $tenant['bill_status'] !== 'paid')) {
            $billDate = new DateTime($tenant['bill_month'] . '-01');
            $dueDate = clone $billDate;
            $dueDate->modify('+1 month');
            $dueDate->setDate($dueDate->format('Y'), $dueDate->format('m'), $paymentDueDay);
            
            $today = new DateTime();
            if ($dueDate < $today) {
                $paymentStatus = t('overdue');
            } else {
                $paymentStatus = t('unpaid');
            }
        } else {
            $paymentStatus = '-';
        }

        $rentVal = (float)$tenant['monthly_rent'];
        $waterVal = (float)($tenant['water_amount'] ?? 0);
        $elecVal = (float)($tenant['elec_amount'] ?? 0);
        $othersVal = (float)($tenant['other_fees'] ?? 0);
        $discountVal = (float)($tenant['discount'] ?? 0);
        $totalVal = (float)($tenant['total_amount'] ?? $tenant['monthly_rent']);

        $sheet2->setCellValue('A' . $row, $localizedMonthsFull[$m - 1]);
        $sheet2->setCellValue('B' . $row, $tenant['room_number']);
        $sheet2->setCellValue('C' . $row, $tenant['tenant_name']);
        $sheet2->setCellValue('D' . $row, $rentVal);
        $sheet2->setCellValue('E' . $row, $waterVal);
        $sheet2->setCellValue('F' . $row, $elecVal);
        $sheet2->setCellValue('G' . $row, $othersVal);
        $sheet2->setCellValue('H' . $row, $discountVal);
        $sheet2->setCellValue('I' . $row, $totalVal);
        $sheet2->setCellValue('J' . $row, $statusLabel);
        $sheet2->setCellValue('K' . $row, $paymentStatus);
        $sheet2->setCellValue('L' . $row, $tenant['phone']);
        $sheet2->setCellValue('M' . $row, $tenant['email'] ?? '');

        // Formatting and Alignment
        $sheet2->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet2->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet2->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet2->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet2->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet2->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet2->getStyle('J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle('K' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle('L' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle('M' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet2->getStyle('A' . $row . ':M' . $row)->applyFromArray($dataBorder);

        $grandTotalRent += $rentVal;
        $grandTotalWater += $waterVal;
        $grandTotalElec += $elecVal;
        $grandTotalOthers += $othersVal;
        $grandTotalDiscount += $discountVal;
        $grandTotalMonthlyTotal += $totalVal;
        $row++;
    }
}

// Total row for Sheet 2
$sheet2->setCellValue('A' . $row, $lang === 'en' ? 'Total' : 'รวม');
$sheet2->mergeCells('A' . $row . ':C' . $row);
$sheet2->setCellValue('D' . $row, $grandTotalRent);
$sheet2->setCellValue('E' . $row, $grandTotalWater);
$sheet2->setCellValue('F' . $row, $grandTotalElec);
$sheet2->setCellValue('G' . $row, $grandTotalOthers);
$sheet2->setCellValue('H' . $row, $grandTotalDiscount);
$sheet2->setCellValue('I' . $row, $grandTotalMonthlyTotal);
$sheet2->getStyle('A' . $row . ':M' . $row)->applyFromArray($totalStyle);
$sheet2->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet2->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet2->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet2->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet2->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet2->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet2->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet2->getRowDimension($row)->setRowHeight(22);

// Auto size columns for Sheet 2
foreach ($cols2 as $col) {
    $sheet2->getColumnDimension($col)->setAutoSize(true);
}


// ==========================================
// SHEET 3: ค่าน้ำ-ค่าไฟ (Utility Bills)
// ==========================================
$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle($lang === 'en' ? 'Utility Bills' : 'บันทึกมิเตอร์');

// Set title row
$sheet3->setCellValue('A1', ($lang === 'en' ? 'Utility Bills Report - Year ' : 'รายงานบันทึกมิเตอร์ค่าน้ำค่าไฟ - ปี ') . $year);
$sheet3->mergeCells('A1:G1');
$sheet3->getStyle('A1')->applyFromArray($titleStyle);
$sheet3->getRowDimension(1)->setRowHeight(35);

// Set Headers
$headers3 = [
    $lang === 'en' ? 'Room' : 'ห้อง',
    $lang === 'en' ? 'Tenant' : 'ผู้เช่า',
    $lang === 'en' ? 'Month' : 'เดือน',
    $lang === 'en' ? 'Water Units' : 'หน่วยน้ำ',
    $lang === 'en' ? 'Water Amount' : 'ค่าน้ำ',
    $lang === 'en' ? 'Electric Units' : 'หน่วยไฟ',
    $lang === 'en' ? 'Electric Amount' : 'ค่าไฟ'
];

$cols3 = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
foreach ($cols3 as $idx => $col) {
    $sheet3->setCellValue($col . '2', $headers3[$idx]);
}
$sheet3->getStyle('A2:G2')->applyFromArray($headerStyle);
$sheet3->getRowDimension(2)->setRowHeight(25);

// Query utility bills
$stmt3 = $pdo->prepare("
    SELECT ub.*, mt.tenant_name, r.room_number 
    FROM utility_bills ub 
    JOIN monthly_tenants mt ON ub.tenant_id = mt.id 
    JOIN rooms r ON ub.room_id = r.id 
    WHERE ub.bill_month >= ? AND ub.bill_month <= ?
    ORDER BY ub.bill_month ASC, r.room_number ASC
");
$stmt3->execute(["$year-01", "$year-12"]);
$bills = $stmt3->fetchAll();

$row = 3;
$totalWaterUnits = 0;
$totalWaterAmt = 0;
$totalElecUnits = 0;
$totalElecAmt = 0;

foreach ($bills as $bill) {
    list($bYear, $bMonth) = explode('-', $bill['bill_month']);
    $billMonthLabel = $localizedMonthsFull[intval($bMonth) - 1] . ' ' . $bYear;

    $wUnits = (int)$bill['water_units'];
    $wAmount = (float)$bill['water_amount'];
    $eUnits = (int)$bill['elec_units'];
    $eAmount = (float)$bill['elec_amount'];

    $sheet3->setCellValue('A' . $row, $bill['room_number']);
    $sheet3->setCellValue('B' . $row, $bill['tenant_name']);
    $sheet3->setCellValue('C' . $row, $billMonthLabel);
    $sheet3->setCellValue('D' . $row, $wUnits);
    $sheet3->setCellValue('E' . $row, $wAmount);
    $sheet3->setCellValue('F' . $row, $eUnits);
    $sheet3->setCellValue('G' . $row, $eAmount);

    // Formats and Alignments
    $sheet3->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet3->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet3->getStyle('A' . $row . ':G' . $row)->applyFromArray($dataBorder);

    $totalWaterUnits += $wUnits;
    $totalWaterAmt += $wAmount;
    $totalElecUnits += $eUnits;
    $totalElecAmt += $eAmount;
    $row++;
}

// Total row for Sheet 3
$sheet3->setCellValue('A' . $row, $lang === 'en' ? 'Total' : 'รวม');
$sheet3->mergeCells('A' . $row . ':C' . $row);
$sheet3->setCellValue('D' . $row, $totalWaterUnits);
$sheet3->setCellValue('E' . $row, $totalWaterAmt);
$sheet3->setCellValue('F' . $row, $totalElecUnits);
$sheet3->setCellValue('G' . $row, $totalElecAmt);
$sheet3->getStyle('A' . $row . ':G' . $row)->applyFromArray($totalStyle);
$sheet3->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet3->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet3->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet3->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet3->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet3->getRowDimension($row)->setRowHeight(22);

// Auto size columns for Sheet 3
foreach ($cols3 as $col) {
    $sheet3->getColumnDimension($col)->setAutoSize(true);
}


// ==========================================
// SHEET 4: ใบเสร็จ/ใบกำกับภาษี (Invoices)
// ==========================================
$sheet4 = $spreadsheet->createSheet();
$sheet4->setTitle($lang === 'en' ? 'Invoices' : 'ใบเสร็จ-ใบกำกับภาษี');

// Set title row
$sheet4->setCellValue('A1', ($lang === 'en' ? 'Invoices/Receipts Report - Year ' : 'รายงานใบเสร็จและใบกำกับภาษี - ปี ') . $year);
$sheet4->mergeCells('A1:F1');
$sheet4->getStyle('A1')->applyFromArray($titleStyle);
$sheet4->getRowDimension(1)->setRowHeight(35);

// Set Headers
$headers4 = [
    $lang === 'en' ? 'Invoice Number' : 'เลขที่ใบเสร็จ',
    $lang === 'en' ? 'Type' : 'ประเภท',
    $lang === 'en' ? 'Room' : 'ห้อง',
    $lang === 'en' ? 'Customer' : 'ลูกค้า',
    $lang === 'en' ? 'Date' : 'วันที่',
    $lang === 'en' ? 'Grand Total' : 'ยอดรวม'
];

$cols4 = ['A', 'B', 'C', 'D', 'E', 'F'];
foreach ($cols4 as $idx => $col) {
    $sheet4->setCellValue($col . '2', $headers4[$idx]);
}
$sheet4->getStyle('A2:F2')->applyFromArray($headerStyle);
$sheet4->getRowDimension(2)->setRowHeight(25);

// Query invoices
$stmt4 = $pdo->prepare("
    SELECT i.*, r.room_number,
           COALESCE(dt.guest_name, mt.tenant_name) as tenant_name
    FROM invoices i
    JOIN rooms r ON i.room_id = r.id
    LEFT JOIN daily_tenants dt ON i.tenant_type = 'daily' AND dt.id = i.tenant_id
    LEFT JOIN monthly_tenants mt ON i.tenant_type = 'monthly' AND mt.id = i.tenant_id
    WHERE i.created_at >= ? AND i.created_at < ?
    ORDER BY i.created_at ASC, i.invoice_number ASC
");
$stmt4->execute(["$year-01-01", ($year + 1) . "-01-01"]);
$invoices = $stmt4->fetchAll();

$row = 3;
$totalInvoiceAmt = 0;

foreach ($invoices as $invoice) {
    $invTypeLabel = $invoice['invoice_type'] === 'daily' ? t('daily') : t('monthly');
    $grandT = (float)$invoice['grand_total'];

    $sheet4->setCellValue('A' . $row, $invoice['invoice_number']);
    $sheet4->setCellValue('B' . $row, $invTypeLabel);
    $sheet4->setCellValue('C' . $row, $invoice['room_number']);
    $sheet4->setCellValue('D' . $row, $invoice['tenant_name'] ?? 'N/A');
    $sheet4->setCellValue('E' . $row, date('d/m/Y', strtotime($invoice['created_at'])));
    $sheet4->setCellValue('F' . $row, $grandT);

    // Formats and Alignments
    $sheet4->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet4->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet4->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet4->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet4->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet4->getStyle('A' . $row . ':F' . $row)->applyFromArray($dataBorder);

    $totalInvoiceAmt += $grandT;
    $row++;
}

// Total row for Sheet 4
$sheet4->setCellValue('A' . $row, $lang === 'en' ? 'Total' : 'รวม');
$sheet4->mergeCells('A' . $row . ':E' . $row);
$sheet4->setCellValue('F' . $row, $totalInvoiceAmt);
$sheet4->getStyle('A' . $row . ':F' . $row)->applyFromArray($totalStyle);
$sheet4->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet4->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet4->getRowDimension($row)->setRowHeight(22);

// Auto size columns for Sheet 4
foreach ($cols4 as $col) {
    $sheet4->getColumnDimension($col)->setAutoSize(true);
}


// Set active sheet to first sheet
$spreadsheet->setActiveSheetIndex(0);

// Generate filename
$filename = 'yearly_report_' . $year . '_' . date('Ymd_His') . '.xlsx';

// Headers for Excel download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Save to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
