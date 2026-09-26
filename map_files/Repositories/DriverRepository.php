<?php
class DriverRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function getByIds(array $driverIds)
    {
        $normalized = [];
        foreach ($driverIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        if (empty($normalized)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $sql = "SELECT id, full_name, vehicle_make_plate FROM drivers WHERE id IN ({$placeholders})";

        try {
            $stmt = $this->pdo->prepare($sql);
            if (!$stmt) {
                mapError('DriverRepository prepare failed');
                return [];
            }
            $ok = $stmt->execute(array_values($normalized));
            if (!$ok) {
                mapError('DriverRepository execute failed');
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            mapError('DriverRepository query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getAllForSelect()
    {
        $sql = "SELECT id, full_name, vehicle_make_plate FROM drivers ORDER BY full_name ASC";
        try {
            $stmt = $this->pdo->query($sql);
            if (!$stmt) {
                mapError('DriverRepository getAllForSelect query returned false');
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            mapError('DriverRepository getAllForSelect failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function existsById($driverId)
    {
        $driverId = (int)$driverId;
        if ($driverId <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT id FROM drivers WHERE id = ? LIMIT 1");
            if (!$stmt) {
                mapError('DriverRepository existsById prepare failed');
                return false;
            }
            if (!$stmt->execute([$driverId])) {
                mapError('DriverRepository existsById execute failed');
                return false;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) && !empty($row['id']);
        } catch (Throwable $e) {
            mapError('DriverRepository existsById failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
