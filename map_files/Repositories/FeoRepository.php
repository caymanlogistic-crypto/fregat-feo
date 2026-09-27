<?php
class FeoRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function fetchPointsWithCoords()
    {
        try {
            $stmt = $this->pdo->query("SELECT zayavka_id, mass_netto, mass_brutto, naim_oo_gruzootpravitel, mno_adres_pogruzki, mno_sh, mno_d, zakaz_s_ot_status, gruzopoluchatel, adres_dostavki_otkhodov, data_sozdaniya_porucheniya FROM feo WHERE mno_sh IS NOT NULL AND mno_d IS NOT NULL");
            if (!$stmt) {
                mapError('FeoRepository query returned false');
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            mapError('FeoRepository query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function fetchActiveUtilizersForMarkers()
    {
        try {
            $exists = $this->pdo->query("SHOW TABLES LIKE 'utilizers'");
            if (!$exists || !$exists->fetchColumn()) {
                return [];
            }

            $columns = $this->pdo->query('SHOW COLUMNS FROM utilizers')->fetchAll(PDO::FETCH_COLUMN);
            $hasTextColor = in_array('color_text', $columns, true);
            $hasDangerousCargo = in_array('dangerous_cargo', $columns, true);
            $textColorSelect = $hasTextColor ? 'color_text' : 'color_dark AS color_text';
            $dangerousCargoSelect = $hasDangerousCargo ? 'dangerous_cargo' : "'Нет' AS dangerous_cargo";
            $stmt = $this->pdo->query(
                'SELECT id, full_name, short_name, address, color_main, color_dark, ' . $textColorSelect . ', ' . $dangerousCargoSelect . ' ' .
                'FROM utilizers WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            );
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            mapError('FeoRepository utilizers query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getMassByZayavkaIds(array $zayavkaIds, string $massField = 'mass_netto')
    {
        $normalized = [];
        foreach ($zayavkaIds as $id) {
            $id = trim((string)$id);
            if ($id !== '' && preg_match('/^\d+$/', $id)) {
                $normalized[$id] = $id;
            }
        }

        if (empty($normalized)) {
            return [];
        }

        $massField = $massField === 'mass_brutto' ? 'mass_brutto' : 'mass_netto';
        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $sql = "SELECT zayavka_id, {$massField} AS display_mass FROM feo WHERE zayavka_id IN ({$placeholders})";

        try {
            $stmt = $this->pdo->prepare($sql);
            if (!$stmt) {
                mapError('FeoRepository mass prepare failed');
                return [];
            }
            $ok = $stmt->execute(array_values($normalized));
            if (!$ok) {
                mapError('FeoRepository mass execute failed');
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                return [];
            }

            $massMap = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string)($row['zayavka_id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $massMap[$id] = isset($row['display_mass']) ? (float)$row['display_mass'] : 0.0;
            }
            return $massMap;
        } catch (Throwable $e) {
            mapError('FeoRepository mass query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getRouteInfoByZayavkaIds(array $zayavkaIds)
    {
        $normalized = [];
        foreach ($zayavkaIds as $id) {
            $id = trim((string)$id);
            if ($id !== '' && preg_match('/^\d+$/', $id)) {
                $normalized[$id] = $id;
            }
        }

        if (empty($normalized)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $sql = "SELECT zayavka_id, mass_netto, mass_brutto, mno_adres_pogruzki FROM feo WHERE zayavka_id IN ({$placeholders})";

        try {
            $stmt = $this->pdo->prepare($sql);
            if (!$stmt) {
                mapError('FeoRepository route info prepare failed');
                return [];
            }
            if (!$stmt->execute(array_values($normalized))) {
                mapError('FeoRepository route info execute failed');
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                return [];
            }

            $result = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string)($row['zayavka_id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $result[$id] = [
                    'mass_netto' => isset($row['mass_netto']) ? (float)$row['mass_netto'] : 0.0,
                    'mass_brutto' => isset($row['mass_brutto']) ? (float)$row['mass_brutto'] : 0.0,
                    'mno_adres_pogruzki' => trim((string)($row['mno_adres_pogruzki'] ?? '')),
                ];
            }
            return $result;
        } catch (Throwable $e) {
            mapError('FeoRepository route info query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
