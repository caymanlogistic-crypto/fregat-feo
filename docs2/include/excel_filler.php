<?php
if (defined('EXCEL_FILLER_LOADED')) {
    return;
}
define('EXCEL_FILLER_LOADED', true);

require_once __DIR__ . '/../../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

include ("include/addSummaryToDocument,.php");

function fillExcelTemplate($parsedData, $templatePath) {
    try {
        if (!file_exists($templatePath)) {
            throw new Exception('Шаблон не найден: ' . $templatePath);
        }        
		// ========== ДОПОЛНИТЕЛЬНЫЕ ПОЛЯ ==========
        $parsedData = addSummaryToDocument($parsedData);
        // ================================
        
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        $orderNumber = isset($parsedData['document']['Номер поручения']) ? $parsedData['document']['Номер поручения'] : '';
        $orderDate = isset($parsedData['document']['Дата поручения']) ? $parsedData['document']['Дата поручения'] : '';
        $contractNumber = isset($parsedData['document']['номер договора']) ? $parsedData['document']['номер договора'] : '';
		$contractDate = isset($parsedData['document']['Дата договора']) ? $parsedData['document']['Дата договора'] : '';
        $fullName = isset($parsedData['document']['Полное наименование']) ? $parsedData['document']['Полное наименование'] : '';
        $inn = isset($parsedData['document']['ИНН']) ? $parsedData['document']['ИНН'] : '';
        $phone = isset($parsedData['document']['Телефон']) ? $parsedData['document']['Телефон'] : '';
        $address = isset($parsedData['document']['Адрес места передачи (погрузки) отходов']) ? $parsedData['document']['Адрес места передачи (погрузки) отходов'] : '';
        $legalAddress = isset($parsedData['document']['Юридический адрес']) ? $parsedData['document']['Юридический адрес'] : '';
        $mgContract = isset($parsedData['document']['Договор МГ']) ? $parsedData['document']['Договор МГ'] : '';
        
        $item = isset($parsedData['items'][0]) ? $parsedData['items'][0] : [];
        
        $wasteCode = isset($item['код ФККО']) ? $item['код ФККО'] : '';
        $wasteName = isset($item['наименование отхода']) ? $item['наименование отхода'] : '';
        $packing = isset($item['способ упаковки']) ? $item['способ упаковки'] : '';
        
        $totalPlaces = isset($parsedData['document']['Общее количество мест']) ? $parsedData['document']['Общее количество мест'] : 0;
        $totalNetto = isset($parsedData['document']['Общий вес нетто, т']) ? $parsedData['document']['Общий вес нетто, т'] : 0;
        $totalBrutto = isset($parsedData['document']['Общий вес брутто, т']) ? $parsedData['document']['Общий вес брутто, т'] : 0;
        $placesDescription = isset($parsedData['document']['Описание мест']) ? $parsedData['document']['Описание мест'] : '';
        
        $sheet->setCellValue('AC8', $orderNumber);
        $sheet->setCellValue('CQ8', $orderNumber);
        $sheet->setCellValue('BU8', $orderDate);
        $sheet->setCellValue('BP16', "Договор № ".$contractNumber." от ".$contractDate);
        $sheet->setCellValue('B14', $fullName." ИНН: ".$inn." Телефон:".$phone." ".$legalAddress);
// Формируем строки для обеих ячеек
// Ячейка BF24
$a = $totalPlaces." мест. ".$packing;
$wrappedA = wordwrap($a, 130, "\n", true);
$sheet->setCellValue('BF24', $wrappedA);
$sheet->getStyle('BF24')->getAlignment()->setWrapText(true);
$lineCountA = substr_count($wrappedA, "\n") + 1;

// Ячейка B24
$b = "КОД ФККО ".$wasteCode." ".$wasteName;
$wrappedB = wordwrap($b, 130, "\n", true);
$sheet->setCellValue('B24', $wrappedB);
$sheet->getStyle('B24')->getAlignment()->setWrapText(true);
$lineCountB = substr_count($wrappedB, "\n") + 1;

// Берём максимальное количество строк
$maxLineCount = max($lineCountA, $lineCountB);

// Устанавливаем высоту строки
$heightPerLine = 7;
$totalHeight = $maxLineCount * $heightPerLine;
$sheet->getRowDimension(24)->setRowHeight($totalHeight);
		
        //
		
		
		
		$sheet->setCellValue('Z26', $totalBrutto*1000);
        $sheet->setCellValue('AY26', $totalNetto*1000);
        
        $a = $placesDescription . "\nвысота, ширина, длина";
        $sheet->setCellValue('BX26', $a);
        $sheet->getStyle('BX26')->getAlignment()->setWrapText(true);
        
        $lineCount = substr_count($a, "\n") + 1;
        $heightPerLine = 7;
        $totalHeight = $lineCount * $heightPerLine;
        $sheet->getRowDimension(26)->setRowHeight($totalHeight);
        
        $sheet->setCellValue('B55', $fullName." ИНН: ".$inn);
        $sheet->setCellValue('B57', $fullName." ИНН: ".$inn);
        $sheet->setCellValue('B59', $address);
        $sheet->setCellValue('Z63', ($totalBrutto*1000)." кг");
        $sheet->setCellValue('B65', $totalPlaces);
        $sheet->setCellValue('BF83', $totalPlaces);
        $sheet->setCellValue('B52', " Договор аренды ".$mgContract);
// Ячейка BF65

$wrappedC = wordwrap($packing, 130, "\n", true);
$sheet->setCellValue('BF65', $wrappedC);
$sheet->getStyle('BF65')->getAlignment()->setWrapText(true);

$lineCountC = substr_count($wrappedC, "\n") + 1;
$heightPerLine = 7;
$totalHeight = $lineCountC * $heightPerLine;
$sheet->getRowDimension(65)->setRowHeight($totalHeight); 


 
        $tempFile = tempnam(sys_get_temp_dir(), 'ttn_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);
        
        return $tempFile;
        
    } catch (Exception $e) {
        error_log('Excel filler error: ' . $e->getMessage());
        return false;
    }
}

function handleTtnDownload($parsedData) {
    if (!$parsedData) {
        die('Нет данных. Сначала загрузите PDF.');
    }
    
    $templatePath = __DIR__ . '/../xls/sample.xlsx';
    $tempFile = fillExcelTemplate($parsedData, $templatePath);
    
    if ($tempFile === false) {
        die('Ошибка при создании Excel файла');
    }
    
    $filename = isset($parsedData['document']['Номер поручения']) 
        ? $parsedData['document']['Номер поручения'] . '.xlsx'
        : 'ttn.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Content-Length: ' . filesize($tempFile));
    
    readfile($tempFile);
    unlink($tempFile);
}
?>