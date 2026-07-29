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

// Get parameters
$year = $_GET['year'] ?? date('Y');
$type = $_GET['type'] ?? 'monthly';
$month = normalizeMonthFilterValue($_GET['month'] ?? date('Y-m'));
$lang = $_SESSION['lang'] ?? 'th';

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

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();

// Remove default sheet
$spreadsheet->removeSheetByIndex(0);

// ==================== SHEET 1: รายได้รายเดือน (แสดงรายละเอียดรายวัน) ====================
$sheet1 = $spreadsheet->createSheet();
$sheet1->setTitle('รายได้รายเดือน');

// Style headers
$headerStyle = [
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];

list($yearNum, $monthNum) = explode('-', $month);
$monthName = $localizedMonthsFull[$monthNum - 1];

$sheet1->setCellValue('A1', 'สรุปรายได้รายเดือน - ' . $monthName . ' ' . $yearNum);
$sheet1->mergeCells('A1:D1');
$sheet1->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet1->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Headers
$sheet1->setCellValue('A2', 'วันที่');
$sheet1->setCellValue('B2', 'รายได้รายวัน');
$sheet1->setCellValue('C2', 'รายได้เพิ่มเติม');
$sheet1->setCellValue('D2', 'รายได้รวม');
$sheet1->getStyle('A2:D2')->applyFromArray($headerStyle);

// Daily data for selected month
$numDays = cal_days_in_month(CAL_GREGORIAN, $monthNum, $yearNum);
$monthlyTotalDaily = 0;
$monthlyTotalExtra = 0;
$row = 3;

$currentMonth = date('Y-m');
$isPastMonth = $month < $currentMonth;

// Check if snapshots exist for this month
$stmt = $pdo->prepare("SELECT * FROM income_snapshots WHERE year = ? AND month = ?");
$stmt->execute([(int) $yearNum, (int) $monthNum]);
$snapshot = $stmt->fetch();

$stmt = $pdo->prepare("SELECT * FROM income_daily_snapshots WHERE year = ? AND month = ? ORDER BY snapshot_date");
$stmt->execute([(int) $yearNum, (int) $monthNum]);
$selectedMonthDailySnapshots = $stmt->fetchAll();

$dailySnapshotsByDay = [];
foreach ($selectedMonthDailySnapshots as $rowSnap) {
    $dailySnapshotsByDay[(int)$rowSnap['day']] = $rowSnap;
}

$limitDay = $isPastMonth ? $numDays : min($numDays, (int) date('j'));

for ($d = 1; $d <= $limitDay; $d++) {
    $currentDate = sprintf('%04d-%02d-%02d', $yearNum, $monthNum, $d);
    
    // Live Data
    $dailyIncome = getDailyTenantRevenueByDate($currentDate);
    $dailyExtraIncome = getDailyTenantExtraRevenueByDate($currentDate);
    
    // Merge Snapshot
    if (isset($dailySnapshotsByDay[$d])) {
        $dailyIncome += (float)$dailySnapshotsByDay[$d]['daily_income'];
        $dailyExtraIncome += (float)$dailySnapshotsByDay[$d]['daily_extra_income'];
    } elseif ($snapshot) {
        $snapshotDayLimit = $month === $currentMonth ? min($numDays, (int) date('j')) : $numDays;
        if ($d === $snapshotDayLimit) {
            $dailyIncome += (float)$snapshot['daily_income'];
            $dailyExtraIncome += (float)$snapshot['daily_extra_income'];
        }
    }
    
    $dayTotal = $dailyIncome + $dailyExtraIncome;
    
    $sheet1->setCellValue('A' . $row, date('d/m/Y', strtotime($currentDate)));
    $sheet1->setCellValue('B' . $row, $dailyIncome);
    $sheet1->setCellValue('C' . $row, $dailyExtraIncome);
    $sheet1->setCellValue('D' . $row, $dayTotal);
    
    $sheet1->getStyle('B' . $row . ':D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    
    $monthlyTotalDaily += $dailyIncome;
    $monthlyTotalExtra += $dailyExtraIncome;
    $row++;
}

// Total row
$sheet1->setCellValue('A' . $row, 'รวม');
$sheet1->setCellValue('B' . $row, $monthlyTotalDaily);
$sheet1->setCellValue('C' . $row, $monthlyTotalExtra);
$sheet1->setCellValue('D' . $row, $monthlyTotalDaily + $monthlyTotalExtra);
$sheet1->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
$sheet1->getStyle('B' . $row . ':D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

// Set column widths
$sheet1->getColumnDimension('A')->setWidth(15);
$sheet1->getColumnDimension('B')->setWidth(25);
$sheet1->getColumnDimension('C')->setWidth(25);
$sheet1->getColumnDimension('D')->setWidth(20);

// ==================== SHEET 2: รายได้รายปี (แสดงรายละเอียดรายเดือน) ====================
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('รายได้รายปี');

$sheet2->setCellValue('A1', 'สรุปรายได้รายปี - ' . $year);
$sheet2->mergeCells('A1:E1');
$sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet2->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Headers
$sheet2->setCellValue('A2', 'เดือน');
$sheet2->setCellValue('B2', 'รายได้รายวัน');
$sheet2->setCellValue('C2', 'รายได้เพิ่มเติม');
$sheet2->setCellValue('D2', 'รายได้รายเดือน (ชำระแล้ว)');
$sheet2->setCellValue('E2', 'รายได้รายเดือน (ค้างชำระ)');
$sheet2->setCellValue('F2', 'รายได้รวม');
$sheet2->getStyle('A2:F2')->applyFromArray($headerStyle);

// Check if yearly snapshots exist
$stmt = $pdo->prepare("SELECT * FROM income_snapshots WHERE year = ? AND month BETWEEN 1 AND 12 ORDER BY month");
$stmt->execute([(int) $year]);
$yearlySnapshots = $stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);

// Monthly data for selected year
$yearlyTotalMonthly = 0;
$yearlyTotalDaily = 0;
$yearlyTotalExtra = 0;
$yearlyTotalUnpaid = 0;
$row = 3;

for ($m = 1; $m <= 12; $m++) {
    $monthStr = sprintf('%s-%02d', $year, $m);
    
    // 1. Live Data
    // Daily income
    $dailyIncome = getDailyTenantRevenueByMonth($monthStr);
    $dailyExtraIncome = getDailyTenantExtraRevenueByMonth($monthStr);
    
    // Monthly income - paid
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN ub.paid_amount > 0 THEN ub.paid_amount ELSE ub.total_amount END), 0) as amount 
        FROM utility_bills ub 
        WHERE DATE_FORMAT(ub.paid_date, '%Y-%m') = ? 
        AND ub.status = 'paid'
    ");
    $stmt->execute([$monthStr]);
    $monthlyIncome = $stmt->fetch()['amount'];
    
    // Forfeited deposit
    $depositIncome = reportForfeitedMonthlyTenantDepositByMonth($pdo, $monthStr);
    
    $monthlyPaid = $monthlyIncome + $depositIncome;
    
    // Monthly income - unpaid from bills
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ub.total_amount), 0) as amount 
        FROM utility_bills ub 
        WHERE ub.bill_month = ? 
        AND ub.status IN ('unpaid', 'overdue')
    ");
    $stmt->execute([$monthStr]);
    $unpaidFromBills = (float)$stmt->fetch()['amount'];
    
    // ผู้เช่าที่ยังไม่มีบิลเลยในเดือนนี้ (ไม่มี record ใน utility_bills สำหรับเดือนนี้)
    // คำนวณสะสมจากวันเริ่มสัญญาถึงเดือนที่เลือก
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
        // คำนวณจำนวนเดือนที่ควรจ่ายตั้งแต่เริ่มสัญญาถึงเดือนที่เลือก
        $contractStart = new DateTime($tenant['contract_start']);
        $selectedMonthStart = new DateTime($monthStr . '-01');
        $selectedMonthEnd = new DateTime($monthStr . '-01');
        $selectedMonthEnd->modify('last day of this month');
        
        // ถ้าสัญญาเริ่มหลังเดือนที่เลือก ให้ใช้เดือนเริ่มสัญญาแทน
        if ($contractStart > $selectedMonthStart) {
            $startDate = $contractStart;
        } else {
            $startDate = $selectedMonthStart;
        }
        
        // นับจำนวนเดือนจากวันเริ่มคิดถึงสิ้นเดือนที่เลือก
        $monthsDiff = ($selectedMonthEnd->format('Y') - $startDate->format('Y')) * 12;
        $monthsDiff += $selectedMonthEnd->format('n') - $startDate->format('n') + 1;
        
        // หักบิลที่มีอยู่แล้ว (existing_bills คือบิลก่อนเดือนที่เลือก)
        $existingBills = (int)$tenant['existing_bills'];
        $unpaidMonths = max(0, $monthsDiff - $existingBills);
        
        $unpaidFromNoBills += $tenant['monthly_rent'] * $unpaidMonths;
    }
    
    $monthlyUnpaid = $unpaidFromBills + $unpaidFromNoBills;
    
    // 2. Combine with Snapshot if exists
    if (isset($yearlySnapshots[$m])) {
        $snap = $yearlySnapshots[$m];
        $dailyIncome += (float)$snap['daily_income'];
        $dailyExtraIncome += (float)$snap['daily_extra_income'];
        $monthlyPaid += (float)$snap['monthly_income'] + (float)$snap['deposit_income'];
        $monthlyUnpaid += (float)$snap['monthly_unpaid'];
    }
    
    $periodLabel = $localizedMonthsShort[$m - 1] . ' ' . $year;
    $totalIncome = $dailyIncome + $dailyExtraIncome + $monthlyPaid;
    
    $sheet2->setCellValue('A' . $row, $periodLabel);
    $sheet2->setCellValue('B' . $row, $dailyIncome);
    $sheet2->setCellValue('C' . $row, $dailyExtraIncome);
    $sheet2->setCellValue('D' . $row, $monthlyPaid);
    $sheet2->setCellValue('E' . $row, $monthlyUnpaid);
    $sheet2->setCellValue('F' . $row, $totalIncome);
    
    $sheet2->getStyle('B' . $row . ':F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    
    $yearlyTotalDaily += $dailyIncome;
    $yearlyTotalExtra += $dailyExtraIncome;
    $yearlyTotalMonthly += $monthlyPaid;
    $yearlyTotalUnpaid += $monthlyUnpaid;
    $row++;
}

// Total row
$sheet2->setCellValue('A' . $row, 'รวม');
$sheet2->setCellValue('B' . $row, $yearlyTotalDaily);
$sheet2->setCellValue('C' . $row, $yearlyTotalExtra);
$sheet2->setCellValue('D' . $row, $yearlyTotalMonthly);
$sheet2->setCellValue('E' . $row, $yearlyTotalUnpaid);
$sheet2->setCellValue('F' . $row, $yearlyTotalDaily + $yearlyTotalExtra + $yearlyTotalMonthly);
$sheet2->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
$sheet2->getStyle('B' . $row . ':F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

// Set column widths
$sheet2->getColumnDimension('A')->setWidth(15);
$sheet2->getColumnDimension('B')->setWidth(20);
$sheet2->getColumnDimension('C')->setWidth(20);
$sheet2->getColumnDimension('D')->setWidth(30);
$sheet2->getColumnDimension('E')->setWidth(30);
$sheet2->getColumnDimension('F')->setWidth(20);

// Calculate grand totals
// Grand total daily income (selected year)
$grandTotalDaily = $yearlyTotalDaily;
$grandTotalExtra = $yearlyTotalExtra;

// Grand total monthly income (paid - all time)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN ub.paid_amount > 0 THEN ub.paid_amount ELSE ub.total_amount END), 0) as amount 
    FROM utility_bills ub 
    WHERE ub.status = 'paid'
");
$stmt->execute();
$grandTotalMonthly = $stmt->fetch()['amount'];

// Forfeited deposit (all time)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(deposit), 0) as amount
    FROM monthly_tenants
    WHERE deposit > 0
    AND status = 'terminated'
");
$stmt->execute();
$grandTotalDepositIncome = (float) $stmt->fetch()['amount'];

$grandTotalMonthly += $grandTotalDepositIncome;

// Grand total monthly income (unpaid - all time)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(ub.total_amount), 0) as amount 
    FROM utility_bills ub 
    WHERE ub.status IN ('unpaid', 'overdue')
");
$stmt->execute();
$grandTotalMonthlyUnpaid = $stmt->fetch()['amount'];

// ==================== SHEET 4: สรุปรวม ====================
$sheet4 = $spreadsheet->createSheet();
$sheet4->setTitle('สรุปรวม');

$sheet4->setCellValue('A1', 'สรุปรวมรายได้ทั้งหมด');
$sheet4->mergeCells('A1:B1');
$sheet4->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet4->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Summary data
$sheet4->setCellValue('A2', 'รายได้รายวันทั้งหมด (ปีนี้)');
$sheet4->setCellValue('B2', $grandTotalDaily);

$sheet4->setCellValue('A3', 'รายได้เพิ่มเติมทั้งหมด (ปีนี้)');
$sheet4->setCellValue('B3', $grandTotalExtra);

$sheet4->setCellValue('A4', 'รายได้รายเดือนทั้งหมด (ชำระแล้ว) (ทั้งหมด)');
$sheet4->setCellValue('B4', $grandTotalMonthly);

$sheet4->setCellValue('A5', 'รายได้รายเดือนทั้งหมด (ค้างชำระ) (ทั้งหมด)');
$sheet4->setCellValue('B5', $grandTotalMonthlyUnpaid);

$sheet4->setCellValue('A6', 'รายได้รวมทั้งหมด (ชำระแล้วเท่านั้น)');
$sheet4->setCellValue('B6', $yearlyTotalDaily + $yearlyTotalExtra + $yearlyTotalMonthly);

$sheet4->setCellValue('A7', 'รายได้รวมทั้งหมด (รวมค้างชำระ)');
$sheet4->setCellValue('B7', $yearlyTotalDaily + $yearlyTotalExtra + $yearlyTotalMonthly + $yearlyTotalUnpaid);

// Style summary
$sheet4->getStyle('A2:A7')->getFont()->setBold(true);
$sheet4->getStyle('B2:B7')->getNumberFormat()->setFormatCode('#,##0.00');

// Set column widths
$sheet4->getColumnDimension('A')->setWidth(40);
$sheet4->getColumnDimension('B')->setWidth(25);

// Set active sheet to first sheet
$spreadsheet->setActiveSheetIndex(0);

// Generate filename
$filename = 'income_report_' . date('Y-m-d') . '.xlsx';

// Headers for Excel download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Save to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
