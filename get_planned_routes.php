<?php
error_reporting(0); 
ini_set('display_errors', 0);
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4", $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Запрос выбирает cost
    $stmt = $pdo->query("SELECT id, comment as name, cost, zayavki_ids, zayavki_count FROM flights WHERE status = 'planned_route' ORDER BY block_date DESC");
    echo json_encode(['success' => true, 'routes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка загрузки: ' . $e->getMessage()]);
}
exit;
?>