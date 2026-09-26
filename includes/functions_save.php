<?php
declare(strict_types=1);

// =============================================================================
// СОХРАНЕНИЕ ДАННЫХ
// =============================================================================

function saveEditedData(array $postData): string {
    $savedCount = 0;
    
    // Сохраняем метаданные
    $metadata = [
        'order_number' => $postData['meta_order_number'] ?? '',
        'order_date' => $postData['meta_order_date'] ?? '',
        'contract_number' => $postData['meta_contract_number'] ?? '',
        'shipper_name' => $postData['meta_shipper_name'] ?? '',
        'shipper_inn' => $postData['meta_shipper_inn'] ?? '',
        'legal_address' => $postData['meta_legal_address'] ?? '',
        'loading_address' => $postData['meta_loading_address'] ?? ''
    ];
    
    // Сохраняем строки таблицы
    $rows = [];
    if (isset($postData['rows']) && is_array($postData['rows'])) {
        foreach ($postData['rows'] as $idx => $rowData) {
            $rows[] = [
                'row_id' => safe_str($rowData['row_id'] ?? ''),
                'packaging_type' => safe_str($rowData['packaging_type'] ?? ''),
                'quantity' => safe_str($rowData['quantity'] ?? ''),
                'packing_method' => safe_str($rowData['packing_method'] ?? ''),
                'waste_code_fkko' => safe_str($rowData['waste_code_fkko'] ?? ''),
                'waste_name' => safe_str($rowData['waste_name'] ?? ''),
                'length_m' => safe_str($rowData['length_m'] ?? ''),
                'width_m' => safe_str($rowData['width_m'] ?? ''),
                'height_m' => safe_str($rowData['height_m'] ?? ''),
                'gross_weight_t' => safe_str($rowData['gross_weight_t'] ?? ''),
                'net_weight_t' => safe_str($rowData['net_weight_t'] ?? ''),
                'volume_m3' => safe_str($rowData['volume_m3'] ?? '')
            ];
            $savedCount++;
        }
    }
    
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'metadata' => $metadata,
        'rows_count' => $savedCount,
        'rows' => $rows
    ];
    
    // Создаём директорию если нет
    if (!is_dir(SAVE_DIR)) {
        mkdir(SAVE_DIR, 0755, true);
    }
    
    // Сохраняем JSON
    $filename = SAVE_DIR . '/data_' . date('Y-m-d_H-i-s') . '.json';
    file_put_contents($filename, json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    // Сохраняем CSV
    if (!empty($rows)) {
        $csvFile = SAVE_DIR . '/data_' . date('Y-m-d_H-i-s') . '.csv';
        $fp = fopen($csvFile, 'w');
        fputcsv($fp, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
    }
    
    return "✅ Данные сохранены! Записей: {$savedCount}. Файл сохранен: " . basename($filename);
}

function fixMetadata(array $doc): array {
    $meta = $doc['metadata'] ?? [];
    return [
        'order_number' => $meta['order_number'] ?? '',
        'order_date' => $meta['order_date'] ?? '',
        'contract_number' => $meta['contract_number'] ?? '',
        'shipper_name' => $meta['shipper_name'] ?? '',
        'shipper_inn' => $meta['shipper_inn'] ?? '',
        'legal_address' => $meta['legal_address'] ?? '',
        'loading_address' => $meta['loading_address'] ?? ''
    ];
}
?>