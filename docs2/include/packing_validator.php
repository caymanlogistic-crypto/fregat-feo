<?php

function validatePackings($data, $pdo) {
    if (!isset($data['items']) || !is_array($data['items'])) {
        return [
            'status' => 'error',
            'data' => $data,
            'wrong_packings' => [],
            'message' => 'Нет позиций для проверки'
        ];
    }
    
    $wrongPackings = [];
    
    $uniquePackings = [];
    foreach ($data['items'] as $index => $item) {
        $packing = isset($item['способ упаковки']) ? trim($item['способ упаковки']) : '';
        if (!empty($packing)) {
            $uniquePackings[$packing] = $index;
        }
    }
    
    if (empty($uniquePackings)) {
        return [
            'status' => 'ok',
            'data' => $data,
            'wrong_packings' => [],
            'message' => 'Нет способов упаковки для проверки'
        ];
    }
    
    $placeholders = implode(',', array_fill(0, count($uniquePackings), '?'));
    $stmt = $pdo->prepare("
        SELECT wrong_value, correct_value 
        FROM packing_dict 
        WHERE wrong_value IN ($placeholders)
    ");
    $stmt->execute(array_keys($uniquePackings));
    $existing = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($uniquePackings as $packing => $index) {
        if (!isset($existing[$packing])) {
            $wrongPackings[$packing] = [
                'original' => $packing
            ];
        }
    }
    
    if (!empty($wrongPackings)) {
        $_SESSION['pending_packing_fix'] = [
            'data' => $data,
            'wrong_packings' => $wrongPackings,
            'timestamp' => time()
        ];
        
        return [
            'status' => 'need_fix',
            'data' => $data,
            'wrong_packings' => $wrongPackings,
            'message' => 'Найдены некорректные способы упаковки'
        ];
    }
    
    $data = applyPackingCorrections($data, $existing);
    
    return [
        'status' => 'ok',
        'data' => $data,
        'wrong_packings' => [],
        'message' => 'Все способы упаковки корректны'
    ];
}

function applyPackingCorrections($data, $corrections) {
    if (!isset($data['items'])) {
        return $data;
    }
    
    foreach ($data['items'] as &$item) {
        $packing = isset($item['способ упаковки']) ? trim($item['способ упаковки']) : '';
        if (isset($corrections[$packing])) {
            $item['способ упаковки'] = $corrections[$packing];
        }
    }
    
    return $data;
}

function handlePackingFixRequest() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }
    
    if (!isset($_POST['fix_packing_action'])) {
        return null;
    }
    
    if (!isset($_SESSION['pending_packing_fix'])) {
        return null;
    }
    
    global $dbConfig;
    
    $action = $_POST['fix_packing_action'];
    $pending = $_SESSION['pending_packing_fix'];
    $data = $pending['data'];
    $wrongPackings = $pending['wrong_packings'];
    $currentWrong = isset($_POST['wrong_value']) ? $_POST['wrong_value'] : null;
    
    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
            $dbConfig['username'],
            $dbConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        if ($action === 'save_one' && $currentWrong) {
$correctValue = isset($_POST['correct_value']) ? trim($_POST['correct_value']) : '';
            
            if (!empty($correctValue) && $correctValue !== $currentWrong) {
                $stmt = $pdo->prepare("
                    INSERT INTO packing_dict (wrong_value, correct_value) 
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE correct_value = VALUES(correct_value)
                ");
                $stmt->execute([$currentWrong, $correctValue]);
                
                // Применяем исправление к данным
                $corrections = [$currentWrong => $correctValue];
                $data = applyPackingCorrections($data, $corrections);
            }
            
            // Удаляем обработанное значение из списка
            unset($wrongPackings[$currentWrong]);
            
            if (empty($wrongPackings)) {
                unset($_SESSION['pending_packing_fix']);
                return $data;
            } else {
                $_SESSION['pending_packing_fix']['data'] = $data;
                $_SESSION['pending_packing_fix']['wrong_packings'] = $wrongPackings;
                return false;
            }
        }
        
        if ($action === 'skip_one' && $currentWrong) {
            unset($wrongPackings[$currentWrong]);
            
            if (empty($wrongPackings)) {
                unset($_SESSION['pending_packing_fix']);
                return $data;
            } else {
                $_SESSION['pending_packing_fix']['wrong_packings'] = $wrongPackings;
                return false;
            }
        }
        
        if ($action === 'cancel_all') {
            unset($_SESSION['pending_packing_fix']);
            return null;
        }
        
    } catch (PDOException $e) {
        error_log("Packing fix error: " . $e->getMessage());
        return null;
    }
    
    return null;
}

function needPackingFixForm() {
    return isset($_SESSION['pending_packing_fix']) && 
           !empty($_SESSION['pending_packing_fix']['wrong_packings']) &&
           !isset($_POST['fix_packing_action']);
}
?>