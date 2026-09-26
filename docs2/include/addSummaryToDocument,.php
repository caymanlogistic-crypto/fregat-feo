<?php
function addSummaryToDocument(array $data)
{
    if (!isset($data['items']) || !is_array($data['items'])) {
        $data['document']['Общее количество мест'] = 0;
        $data['document']['Общий вес нетто, т'] = 0;
        $data['document']['Общий вес брутто, т'] = 0;
        $data['document']['Описание мест'] = '';
        $data['document']['Описание способ упаковки'] = '';
        return $data;
    }
    
    $totalPlaces = 0;
    $totalNetto = 0.0;
    $totalBrutto = 0.0;
    
    // Массив для группировки: ключ = "(H×W×D)", значение = количество
    $sizeGroups = [];
    
    // Массив для уникальных способов упаковки
    $uniquePackings = [];
    
    foreach ($data['items'] as $item) {
        // Количество мест
        $places = isset($item['количество']) ? (int)$item['количество'] : 0;
        $totalPlaces += $places;
        
        // Вес нетто (оставляем в тоннах)
        if (isset($item['масса нетто']) && is_numeric($item['масса нетто'])) {
            $totalNetto += (float)$item['масса нетто'];
        }
        
        // Вес брутто (оставляем в тоннах)
        if (isset($item['Масса брутто']) && is_numeric($item['Масса брутто'])) {
            $totalBrutto += (float)$item['Масса брутто'];
        }
        
        // Группировка по размерам
        $height = isset($item['высота']) ? (float)$item['высота'] : null;
        $width = isset($item['ширина']) ? (float)$item['ширина'] : null;
        $length = isset($item['длина']) ? (float)$item['длина'] : null;
        
        if ($places > 0 && $height !== null && $width !== null && $length !== null) {
            $h = rtrim(rtrim(sprintf('%.2f', $height), '0'), '.');
            $w = rtrim(rtrim(sprintf('%.2f', $width), '0'), '.');
            $l = rtrim(rtrim(sprintf('%.2f', $length), '0'), '.');
            
            $sizeKey = "({$h}*{$w}*{$l})";
            
            if (!isset($sizeGroups[$sizeKey])) {
                $sizeGroups[$sizeKey] = 0;
            }
            $sizeGroups[$sizeKey] += $places;
        }
        
        // Собираем уникальные способы упаковки (уже исправленные)
        $packing = isset($item['способ упаковки']) ? trim($item['способ упаковки']) : '';
        if (!empty($packing)) {
            $uniquePackings[$packing] = true;
        }
    }
    
    // Формируем "Описание мест"
    $formattedParts = [];
    foreach ($sizeGroups as $size => $count) {
        $formattedParts[] = "{$size} - {$count} шт";
    }
    
    $lines = [];
    $chunks = array_chunk($formattedParts, 2);
    foreach ($chunks as $chunk) {
        $lines[] = implode(' ', $chunk);
    }
    $placesDescription = implode("\n", $lines);
    
    // Формируем "Описание способ упаковки"
    $packingDescription = implode("; ", array_keys($uniquePackings));
    
    // Сохраняем в document
    $data['document']['Общее количество мест'] = $totalPlaces;
    $data['document']['Общий вес нетто, т'] = round($totalNetto, 3);
    $data['document']['Общий вес брутто, т'] = round($totalBrutto, 3);
    $data['document']['Описание мест'] = $placesDescription;
    $data['document']['Описание способ упаковки'] = $packingDescription;
    
    return $data;
}
?>