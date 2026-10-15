<?php
class MapDataService
{
    private $flightRepository;
    private $statusBlockRepository;
    private $feoRepository;
    private $statusColors;
    private $statusNames;
    private $displayMassField;

    public function __construct($flightRepository, $statusBlockRepository, $feoRepository, $statusColors, $statusNames, $displayMassField = null)
    {
        $this->flightRepository = $flightRepository;
        $this->statusBlockRepository = $statusBlockRepository;
        $this->feoRepository = $feoRepository;
        $this->statusColors = $statusColors;
        $this->statusNames = $statusNames;
        $entryPoint = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        $this->displayMassField = $displayMassField === 'mass_brutto' || ($displayMassField === null && $entryPoint === 'map2.php')
            ? 'mass_brutto'
            : 'mass_netto';
    }

    public function build()
    {
        $flightStatuses = $this->getActualStatuses();
        $availableZayavki = $this->getAvailableZayavkiIds();
        $overdueZayavki = $this->getOverdueZayavkiIds();
        $utilizerIndexes = $this->buildUtilizerIndexes();
        $points = $this->feoRepository->fetchPointsWithCoords();
        if (!is_array($points)) {
            $points = [];
        }

        $filteredPoints = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }
            $zayavkaId = isset($point['zayavka_id']) ? trim((string)$point['zayavka_id']) : '';
            if ($zayavkaId === '') {
                continue;
            }
            $point['zayavka_id'] = $zayavkaId;
            $point['mass_netto'] = isset($point['mass_netto']) ? (float)$point['mass_netto'] : 0;
            $point['mass_brutto'] = isset($point['mass_brutto']) ? (float)$point['mass_brutto'] : 0;
            $point['display_mass'] = $point[$this->displayMassField];
            $point['mno_sh'] = $point['mno_sh'] ?? null;
            $point['mno_d'] = $point['mno_d'] ?? null;
            $point['naim_oo_gruzootpravitel'] = mapNormalizeText((string)($point['naim_oo_gruzootpravitel'] ?? ''));
            $point['mno_adres_pogruzki'] = mapNormalizeText((string)($point['mno_adres_pogruzki'] ?? ''));
            $point['zakaz_s_ot_status'] = mapNormalizeText((string)($point['zakaz_s_ot_status'] ?? ''));
            $point['gruzopoluchatel'] = mapNormalizeText((string)($point['gruzopoluchatel'] ?? ''));
            $point['adres_dostavki_otkhodov'] = mapNormalizeText((string)($point['adres_dostavki_otkhodov'] ?? ''));
            $point['data_sozdaniya_porucheniya'] = trim((string)($point['data_sozdaniya_porucheniya'] ?? ''));
            $point['is_overdue'] = isset($overdueZayavki[$zayavkaId]);

            $utilizer = $this->resolveUtilizerForPoint($point, $utilizerIndexes);
            $point['utilizer_id'] = $utilizer['id'];
            $point['utilizer_short_name'] = $utilizer['short_name'];
            $point['utilizer_color_main'] = $utilizer['color_main'];
            $point['utilizer_color_dark'] = $utilizer['color_dark'];
            $point['utilizer_color_text'] = $utilizer['color_text'];
            $point['utilizer_match_key'] = $utilizer['match_key'];

            $inFlight = isset($flightStatuses[$point['zayavka_id']]);
            $isAvailable = isset($availableZayavki[$point['zayavka_id']]);
            if (!$inFlight && !$isAvailable) {
                continue;
            }

            // Заявка, которую забирают со склада, физически уже не находится
            // на исходном адресе ОО. Складской маркер показывает её остаток,
            // поэтому отдельный FEO-маркер по старым координатам не создаём.
            if ($inFlight && (int)($flightStatuses[$point['zayavka_id']]['source_warehouse_id'] ?? 0) > 0) {
                continue;
            }

            $point['flight_status'] = $inFlight ? $flightStatuses[$point['zayavka_id']]['status'] : null;
            $point['flight_id'] = $inFlight ? $flightStatuses[$point['zayavka_id']]['flight_id'] : null;

            if ($inFlight) {
                $point['marker_color'] = $flightStatuses[$point['zayavka_id']]['color'];
                $point['in_flight'] = true;
                $point['custom_layer'] = null;
                $point['custom_color'] = null;
            } else {
                $point['in_flight'] = false;
                $data = $availableZayavki[$point['zayavka_id']];
                if ($data['layer_name'] && $data['color']) {
                    $point['marker_color'] = $data['color'];
                    $point['custom_layer'] = $data['layer_name'];
                    $point['custom_color'] = $data['color'];
                } else {
                    $point['marker_color'] = '#000';
                    $point['custom_layer'] = null;
                    $point['custom_color'] = null;
                }
            }

            $filteredPoints[] = $point;
        }

        $groupedPoints = [];
        $processed = [];
        foreach ($filteredPoints as $i => $p) {
            if (isset($processed[$i])) {
                continue;
            }

            $utilizerGroupKey = (string)($p['utilizer_match_key'] ?? '');
            $key = "{$p['mno_sh']}|{$p['mno_d']}|u|{$utilizerGroupKey}" . ($p['in_flight'] ? "|f|{$p['flight_status']}|{$p['flight_id']}" : "|n|" . ($p['custom_layer'] ?? 'def'));
            $groupedPoints[$key] = [
                'mno_sh' => $p['mno_sh'],
                'mno_d' => $p['mno_d'],
                'mno_adres_pogruzki' => $p['mno_adres_pogruzki'],
                'naim_oo_gruzootpravitel' => $p['naim_oo_gruzootpravitel'],
                'flight_status' => $p['flight_status'],
                'flight_id' => $p['flight_id'],
                'marker_color' => $p['marker_color'],
                'in_flight' => $p['in_flight'],
                'custom_layer' => $p['custom_layer'],
                'custom_color' => $p['custom_color'],
                'utilizer_id' => $p['utilizer_id'],
                'utilizer_short_name' => $p['utilizer_short_name'],
                'utilizer_color_main' => $p['utilizer_color_main'],
                'utilizer_color_dark' => $p['utilizer_color_dark'],
                'utilizer_color_text' => $p['utilizer_color_text'],
                'utilizer_match_key' => $p['utilizer_match_key'],
                'points' => [$p],
                'total_mass' => $p['display_mass'],
                'count' => 1,
                'group_key' => $key,
            ];

            $processed[$i] = $key;

            foreach ($filteredPoints as $j => $o) {
                if ($j <= $i || isset($processed[$j])) {
                    continue;
                }

                if (canMergePoints($p, $o)) {
                    $groupedPoints[$key]['points'][] = $o;
                    $groupedPoints[$key]['total_mass'] += $o['display_mass'];
                    $groupedPoints[$key]['count']++;
                    $processed[$j] = $key;
                }
            }
        }

        $processedGroups = array_values($groupedPoints);
        $coordMap = [];
        foreach ($processedGroups as $idx => $g) {
            $coordMap["{$g['mno_sh']}|{$g['mno_d']}"][] = $idx;
        }
        foreach ($coordMap as $arr) {
            foreach ($arr as $i => $idx) {
                $processedGroups[$idx]['base_index'] = $i;
            }
        }

        $flightStatusList = [];
        $customLayers = [];
        $hasDefault = false;
        foreach ($processedGroups as $g) {
            if ($g['in_flight']) {
                if (!empty($g['flight_status'])) {
                    $flightStatusList[$g['flight_status']] = true;
                }
            } else {
                if ($g['custom_layer']) {
                    $customLayers[$g['custom_layer']] = [
                        'name' => $g['custom_layer'],
                        'color' => $g['custom_color'],
                    ];
                } else {
                    $hasDefault = true;
                }
            }
        }

        $flightStatusList = array_intersect_key(array_flip(array_keys($this->statusNames)), $flightStatusList);

        return [
            'processedGroups' => $processedGroups,
            'flightStatusList' => $flightStatusList,
            'customLayers' => $customLayers,
            'hasDefault' => $hasDefault,
        ];
    }

    private function buildUtilizerIndexes()
    {
        $rows = method_exists($this->feoRepository, 'fetchActiveUtilizersForMarkers')
            ? $this->feoRepository->fetchActiveUtilizersForMarkers()
            : [];
        $result = ['rows' => [], 'by_full' => [], 'by_address' => []];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            $fullName = trim((string)($row['full_name'] ?? ''));
            $shortName = trim((string)($row['short_name'] ?? ''));
            if ($id <= 0 || $shortName === '') {
                continue;
            }
            $dark = $this->normalizeColor($row['color_dark'] ?? '', '#7f4125');
            $item = [
                'id' => $id,
                'full_name' => $fullName,
                'short_name' => $shortName,
                'address' => trim((string)($row['address'] ?? '')),
                'color_main' => $this->normalizeColor($row['color_main'] ?? '', '#f4b183'),
                'color_dark' => $dark,
                'color_text' => $this->normalizeColor($row['color_text'] ?? '', $dark),
                'full_key' => $this->normalizeUtilizerLookupKey($fullName),
                'short_key' => $this->normalizeUtilizerLookupKey($shortName),
                'address_key' => $this->normalizeUtilizerLookupKey($row['address'] ?? ''),
            ];
            $result['rows'][] = $item;
            if ($item['full_key'] !== '') {
                $result['by_full'][$item['full_key']] = $item;
            }
            if ($item['address_key'] !== '') {
                $result['by_address'][$item['address_key']] = $item;
            }
        }
        return $result;
    }

    private function resolveUtilizerForPoint(array $point, array $indexes)
    {
        $recipientKey = $this->normalizeUtilizerLookupKey($point['gruzopoluchatel'] ?? '');
        $addressKey = $this->normalizeUtilizerLookupKey($point['adres_dostavki_otkhodov'] ?? '');
        $match = null;

        if ($recipientKey !== '' && isset($indexes['by_full'][$recipientKey])) {
            $match = $indexes['by_full'][$recipientKey];
        }
        if ($match === null && $recipientKey !== '') {
            foreach (($indexes['rows'] ?? []) as $candidate) {
                $shortKey = (string)($candidate['short_key'] ?? '');
                $fullKey = (string)($candidate['full_key'] ?? '');
                if (($shortKey !== '' && strpos($recipientKey, $shortKey) !== false)
                    || ($fullKey !== '' && strpos($fullKey, $recipientKey) !== false)) {
                    $match = $candidate;
                    break;
                }
            }
        }
        if ($match === null && $addressKey !== '') {
            if (isset($indexes['by_address'][$addressKey])) {
                $match = $indexes['by_address'][$addressKey];
            } else {
                foreach (($indexes['rows'] ?? []) as $candidate) {
                    $candidateAddress = (string)($candidate['address_key'] ?? '');
                    if ($candidateAddress !== '' && (strpos($addressKey, $candidateAddress) !== false || strpos($candidateAddress, $addressKey) !== false)) {
                        $match = $candidate;
                        break;
                    }
                }
            }
        }

        if ($match !== null) {
            return [
                'id' => (int)$match['id'],
                'short_name' => (string)$match['short_name'],
                'color_main' => (string)$match['color_main'],
                'color_dark' => (string)$match['color_dark'],
                'color_text' => (string)$match['color_text'],
                'match_key' => 'id:' . (int)$match['id'],
            ];
        }

        $fallbackKey = $recipientKey !== '' ? $recipientKey : $addressKey;
        return [
            'id' => 0,
            'short_name' => '',
            'color_main' => '#6c757d',
            'color_dark' => '#4f565c',
            'color_text' => '#ffffff',
            'match_key' => 'unmatched:' . $fallbackKey,
        ];
    }

    private function normalizeUtilizerLookupKey($value)
    {
        $value = mapNormalizeText(trim((string)$value));
        if ($value === '') {
            return '';
        }
        $value = str_replace('Ё', 'Е', $value);
        if (function_exists('mb_strtoupper')) {
            $value = mb_strtoupper($value, 'UTF-8');
        } else {
            $value = strtoupper($value);
        }
        $normalized = preg_replace('/[^\p{L}\p{N}+]+/u', '', $value);
        return is_string($normalized) ? $normalized : $value;
    }

    private function normalizeColor($value, $fallback)
    {
        $value = trim((string)$value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
    }

    private function getActualStatuses()
    {
        $rows = $this->flightRepository->fetchStatusesForActual();
        if (!is_array($rows)) {
            $rows = [];
        }
        $statuses = [];
        $processed = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = $row['status'] ?? null;
            $flightId = $row['id'] ?? null;
            foreach (explode(',', (string)($row['zayavki_ids'] ?? '')) as $id) {
                $id = trim($id);
                if ($id === '') {
                    continue;
                }
                if (!isset($processed[$id])) {
                    $statuses[$id] = [
                        'status' => $status,
                        'color' => $this->statusColors[$status] ?? '#000',
                        'flight_id' => $flightId,
                        'source_warehouse_id' => (int)($row['source_warehouse_id'] ?? 0),
                    ];
                    $processed[$id] = true;
                }
            }
        }

        return $statuses;
    }

    private function getAvailableZayavkiIds()
    {
        $rows = $this->statusBlockRepository->fetchAvailableBlocks();
        if (!is_array($rows)) {
            $rows = [];
        }
        $available = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $comment = $row['comment'] ?? '';
            $layer = null;
            $color = null;

            if (strpos($comment, '#') !== false) {
                $pos = strpos($comment, '#');
                $name = trim(substr($comment, 0, $pos));
                $col = trim(substr($comment, $pos));
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $col) || preg_match('/^#[0-9A-Fa-f]{3}$/', $col)) {
                    $layer = $name;
                    $color = $col;
                }
            }

            foreach (explode(',', (string)($row['zayavki_ids'] ?? '')) as $id) {
                $id = trim($id);
                if ($id) {
                    $available[$id] = ['layer_name' => $layer, 'color' => $color];
                }
            }
        }

        return $available;
    }

    private function getOverdueZayavkiIds()
    {
        if (!method_exists($this->statusBlockRepository, 'fetchOverdueBlocks')) {
            return [];
        }

        $rows = $this->statusBlockRepository->fetchOverdueBlocks();
        if (!is_array($rows)) {
            return [];
        }

        $overdue = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ids = preg_split('/[,;\s]+/', (string)($row['zayavki_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ((array)$ids as $id) {
                $id = trim((string)$id);
                if ($id !== '' && preg_match('/^\d+$/', $id)) {
                    $overdue[$id] = true;
                }
            }
        }

        return $overdue;
    }
}
