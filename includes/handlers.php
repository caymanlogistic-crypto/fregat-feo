<?php
declare(strict_types=1);

// =============================================================================
// ОБРАБОТЧИКИ ЗАПРОСОВ
// =============================================================================

function handleRequest(&$resultHtml, &$errorMsg, &$saveMessage, &$allRows, &$meta, &$sourceFileName) {
    
    // AJAX запрос для получения адреса по ИНН
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_address_by_inn'])) {
        header('Content-Type: application/json');
        $inn = $_POST['inn'] ?? '';
        $company = getCompanyByINN($inn);
        if ($company && $company['address'] !== '—') {
            echo json_encode(['success' => true, 'address' => $company['address']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Адрес не найден']);
        }
        exit;
    }
    
    $isSaveRequest = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_data']));
    $isUploadRequest = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file']) && !$isSaveRequest);
    
    // Обработка сохранения
    if ($isSaveRequest) {
        $saveMessage = saveEditedData($_POST);
        if (isset($_POST['rows'])) {
            $allRows = $_POST['rows'];
            foreach ($allRows as &$row) {
                foreach ($row as &$val) {
                    $val = safe_str($val);
                }
            }
        }
        $meta = [
            'order_number' => $_POST['meta_order_number'] ?? '',
            'order_date' => $_POST['meta_order_date'] ?? '',
            'contract_number' => $_POST['meta_contract_number'] ?? '',
            'shipper_name' => $_POST['meta_shipper_name'] ?? '',
            'shipper_inn' => $_POST['meta_shipper_inn'] ?? '',
            'legal_address' => $_POST['meta_legal_address'] ?? '',
            'loading_address' => $_POST['meta_loading_address'] ?? ''
        ];
        $sourceFileName = $_POST['source_file_name'] ?? 'document.pdf';
        $resultHtml = renderEditableForm($allRows, $meta, $sourceFileName, $saveMessage);
    }
    // Обработка загрузки файла
    elseif ($isUploadRequest) {
        $file = $_FILES['pdf_file'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                $errorMsg = 'Ошибка: Загрузите файл формата PDF.';
            } else {
                if (!is_dir(TEMP_DIR)) mkdir(TEMP_DIR, 0755, true);
                
                $tempFilePath = TEMP_DIR . '/' . uniqid('upload_', true) . '.pdf';
                
                if (move_uploaded_file($file['tmp_name'], $tempFilePath)) {
                    $cmd = sprintf('%s %s %s 2>&1', escapeshellarg(PYTHON_EXEC), escapeshellarg(WORKER_SCRIPT), escapeshellarg($tempFilePath));
                    
                    $output = [];
                    $returnVar = 0;
                    exec($cmd, $output, $returnVar);
                    
                    unlink($tempFilePath);
                    
                    if ($returnVar === 0 && !empty($output)) {
                        $jsonString = implode("\n", $output);
                        $data = json_decode($jsonString, true);
                        
                        if (json_last_error() === JSON_ERROR_NONE && isset($data['tables'])) {
                            $rawRows = [];
                            foreach ($data['tables'] as $t) {
                                if (isset($t['data'])) foreach ($t['data'] as $r) $rawRows[] = $r;
                            }
                            
                            $mergedRows = mergeFragmentedRows($rawRows);
                            
                            $filteredRows = [];
                            foreach ($mergedRows as $r) {
                                $svc = preg_replace('/\s+/', '', safe_str($r['service_type'] ?? ''));
                                if ($svc === 'транспортирование' || $svc === 'утилизация') {
                                    $filteredRows[] = $r;
                                }
                            }
                            
                            $allRows = $filteredRows;
                            $meta = fixMetadata($data);
                            $sourceFileName = $file['name'];
                            
                            // АВТОМАТИЧЕСКАЯ ЗАГРУЗКА ЮР. АДРЕСА ИЗ DaData ПО ИНН
                            if (!empty($meta['shipper_inn'])) {
                                $inn = preg_replace('/[^0-9]/', '', $meta['shipper_inn']);
                                if (strlen($inn) === 10 || strlen($inn) === 12) {
                                    $company = getCompanyByINN($inn);
                                    if ($company && $company['address'] !== '—') {
                                        $meta['legal_address'] = $company['address'];
                                    }
                                }
                            }
                            
                            $resultHtml = renderEditableForm($allRows, $meta, $sourceFileName, '');
                        } else {
                            $errorMsg = 'Ошибка Python: Невалидный JSON ответ.';
                        }
                    } else {
                        $errorMsg = 'Ошибка запуска Python или скрипта worker.py.';
                    }
                } else {
                    $errorMsg = 'Ошибка сохранения временного файла.';
                }
            }
        } else {
            $errorMsg = 'Ошибка загрузки файла.';
        }
    }
}
?>