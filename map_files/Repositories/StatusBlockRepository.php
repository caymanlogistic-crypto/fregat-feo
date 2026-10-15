<?php
class StatusBlockRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function fetchAvailableBlocks()
    {
        try {
            // Read the whole legacy row: the visible block title is stored separately
            // from comment on available.php. Newer block id must win for duplicate requests.
            $stmt = $this->pdo->query("SELECT * FROM status_blocks WHERE status_type = 'dostupno' ORDER BY id ASC");
            if (!$stmt) {
                mapError('StatusBlockRepository query returned false');
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                return [];
            }

            foreach ($rows as &$row) {
                if (!is_array($row)) {
                    continue;
                }

                $row['block_id'] = (int)($row['id'] ?? 0);

                $nameCandidates = [
                    $row['name'] ?? null,
                    $row['nazvanie'] ?? null,
                    $row['title'] ?? null,
                    $row['block_name'] ?? null,
                ];
                $blockName = '';
                foreach ($nameCandidates as $candidate) {
                    $candidate = trim((string)$candidate);
                    if ($candidate !== '') {
                        $blockName = $candidate;
                        break;
                    }
                }

                // Legacy rows may contain explanatory text after/before the color.
                $comment = trim((string)($row['comment'] ?? ''));
                $commentColor = '';
                $commentName = '';
                if ($comment !== '' && preg_match('/#[0-9A-Fa-f]{6}\b|#[0-9A-Fa-f]{3}\b/', $comment, $match, PREG_OFFSET_CAPTURE)) {
                    $commentColor = strtoupper((string)$match[0][0]);
                    $offset = (int)$match[0][1];
                    $before = trim(substr($comment, 0, $offset));
                    $after = trim(substr($comment, $offset + strlen($commentColor)));
                    $commentName = trim($before !== '' ? $before : $after, " \t\n\r\0\x0B|-–—:;");
                }

                // Old custom layers stored the title together with the color in comment.
                // Current available.php has a separate visible "Название" column.
                if ($blockName === '' && $commentName !== '') {
                    $blockName = $commentName;
                }

                $colorCandidates = [
                    $row['color'] ?? null,
                    $row['custom_color'] ?? null,
                    $row['marker_color'] ?? null,
                    $row['layer_color'] ?? null,
                    $commentColor,
                ];
                $blockColor = '';
                foreach ($colorCandidates as $candidate) {
                    $candidate = trim((string)$candidate);
                    if (preg_match('/^#[0-9A-Fa-f]{6}$|^#[0-9A-Fa-f]{3}$/', $candidate)) {
                        $blockColor = strtoupper($candidate);
                        break;
                    }
                }

                $row['block_name'] = $blockName;
                $row['block_color'] = $blockColor !== '' ? $blockColor : '#000000';

                // Keep the original MapDataService semantics: it expects
                // "Layer title #RRGGBB" in comment. Feeding the real block title here
                // lets processedGroups decide whether the layer is still active.
                // Therefore a layer disappears automatically when no unexported request remains.
                if ($blockName !== '') {
                    $row['comment'] = $blockName . ' ' . $row['block_color'];
                }
            }
            unset($row);

            return $rows;
        } catch (Throwable $e) {
            mapError('StatusBlockRepository query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function fetchOverdueBlocks()
    {
        try {
            $table = $this->pdo->query("SHOW TABLES LIKE 'overdue_blocks'");
            if (!$table || !$table->fetchColumn()) {
                return [];
            }

            $stmt = $this->pdo->query("SELECT id, name, zayavki_count, total_mass_tonn, zayavki_ids, created_at FROM overdue_blocks ORDER BY id ASC");
            if (!$stmt) {
                mapError('StatusBlockRepository overdue query returned false');
                return [];
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            mapError('StatusBlockRepository overdue query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
