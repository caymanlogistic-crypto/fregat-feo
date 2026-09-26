<?php
declare(strict_types=1);

// =============================================================================
// СЛИЯНИЕ РАЗОРВАННЫХ СТРОК ТАБЛИЦЫ
// =============================================================================

function mergeFragmentedRows(array $rows): array {
    if (empty($rows)) return [];
    
    $merged = [];
    $lastIdx = -1;
    $lastKey = null;

    foreach ($rows as $r) {
        $id = trim(safe_str($r['row_id'] ?? ''));
        $svc = preg_replace('/\s+/', '', safe_str($r['service_type'] ?? ''));
        
        $filledCount = 0;
        foreach ($r as $k => $v) {
            if (!in_array($k, ['page', 'table_index', '_source_page'], true) && safe_str($v) !== '') {
                $filledCount++;
            }
        }

        $isContinuation = ($id === '' || $filledCount < 4);
        $currentKey = (!$isContinuation && $id !== '') ? "{$id}|{$svc}" : null;

        if ($currentKey !== null && $currentKey !== $lastKey) {
            $r['service_type'] = $svc;
            $r['waste_code_fkko'] = preg_replace('/\s+/', '', safe_str($r['waste_code_fkko'] ?? ''));
            $r['_page'] = $r['page'] ?? '';
            $merged[] = $r;
            $lastIdx = count($merged) - 1;
            $lastKey = $currentKey;
        } else {
            if ($lastIdx >= 0) {
                foreach ($r as $col => $val) {
                    if (in_array($col, ['page', 'table_index', '_source_page'], true)) continue;
                    $val = safe_str($val);
                    if ($val === '') continue;
                    $prev = safe_str($merged[$lastIdx][$col] ?? '');
                    $merged[$lastIdx][$col] = ($prev !== '') ? $prev . ' ' . $val : $val;
                }
            } else {
                $r['_page'] = $r['page'] ?? '';
                $merged[] = $r;
                $lastIdx = 0;
            }
        }
    }

    foreach ($merged as &$row) {
        foreach ($row as $col => $val) {
            if (is_string($val)) {
                $row[$col] = normalize_text($val);
            }
        }
    }
    
    return $merged;
}