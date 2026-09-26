<?php
error_reporting(0); 
ini_set('display_errors', 0);
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    echo json_encode(['success' => false, 'message' => 'Неверный метод']); 
    exit; 
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Неверный JSON');
    
    // Получаем данные
    $name = trim($data['name'] ?? '');
    $zayavkiIds = $data['zayavki_ids'] ?? '';
    
    // Обработка стоимости: если пусто или 0, пишем NULL
    $costRaw = $data['cost'];
    $cost = ($costRaw !== '' && is_numeric($costRaw) && $costRaw != 0) ? floatval($costRaw) : null;
    
    // ID маршрута (если 0 или нет - значит создание)
    $routeId = isset($data['id']) ? intval($data['id']) : 0;

    if (empty($name) || empty($zayavkiIds)) throw new Exception('Название и ID заявок обязательны');

    $pdo = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4", $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = count(array_filter(explode(',', $zayavkiIds)));

    if ($routeId > 0) {
        // --- ОБНОВЛЕНИЕ СУЩЕСТВУЮЩЕГО МАРШРУТА ---
        $stmt = $pdo->prepare("UPDATE flights SET comment = :comment, cost = :cost, zayavki_ids = :zayavki_ids, zayavki_count = :count, block_date = NOW() WHERE id = :id AND status = 'planned_route'");
        $stmt->execute([
            ':comment' => $name, 
            ':cost' => $cost, 
            ':zayavki_ids' => $zayavkiIds, 
            ':count' => $count, 
            ':id' => $routeId
        ]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => "Маршрут «{$name}» обновлен"]);
        } else {
            // Если вдруг не нашлось (хотя ID был передан), можно выдать ошибку или молча обновить
            echo json_encode(['success' => true, 'message' => "Маршрут «{$name}» обновлен"]);
        }
    } else {
        // --- СОЗДАНИЕ НОВОГО МАРШРУТА ---
        $stmt = $pdo->prepare("INSERT INTO flights (status, comment, cost, zayavki_ids, zayavki_count, assigned_manager_id, block_date) VALUES ('planned_route', :comment, :cost, :zayavki_ids, :count, NULL, NOW())");
        $stmt->execute([
            ':comment' => $name, 
            ':cost' => $cost, 
            ':zayavki_ids' => $zayavkiIds, 
            ':count' => $count
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => "Маршрут «{$name}» создан"]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
}
exit;
?>