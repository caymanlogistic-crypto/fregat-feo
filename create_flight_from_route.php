<?php
error_reporting(0); ini_set('display_errors', 0);
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Метод не разрешен']); exit; }

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $roleId = intval($data['route_id'] ?? 0);
    if ($roleId <= 0) throw new Exception('Неверный ID маршрута');

    $pdo = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4", $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare("SELECT zayavki_ids, comment FROM flights WHERE id = :id AND status = 'planned_route'");
    $stmt->execute([':id' => $roleId]);
    $route = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$route) throw new Exception('Маршрут не найден');

    $count = count(array_filter(explode(',', $route['zayavki_ids'])));
    $ins = $pdo->prepare("INSERT INTO flights (status, comment, zayavki_ids, zayavki_count, assigned_manager_id, block_date) VALUES ('search', :comment, :zayavki_ids, :count, NULL, NOW())");
    $ins->execute([':comment' => 'Из маршрута: ' . $route['comment'], ':zayavki_ids' => $route['zayavki_ids'], ':count' => $count]);
    echo json_encode(['success' => true, 'message' => 'Рейс создан со статусом "Поиск подрядчика"']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
}
exit;
?>