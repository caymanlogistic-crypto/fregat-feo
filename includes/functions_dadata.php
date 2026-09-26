<?php
declare(strict_types=1);

// =============================================================================
// ИНТЕГРАЦИЯ С DADATA API
// =============================================================================

function getCompanyByINN($inn) {
    $options = [
        'http' => [
            'header' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Token ' . DADATA_API_KEY
            ],
            'method' => 'POST',
            'timeout' => 5,
            'content' => json_encode(['query' => preg_replace('/[^0-9]/', '', $inn)])
        ]
    ];
    
    $result = @file_get_contents(DADATA_API_URL, false, stream_context_create($options));
    if (!$result) return null;
    
    $response = json_decode($result, true);
    if (empty($response['suggestions'][0])) return null;
    
    $c = $response['suggestions'][0]['data'];
    return [
        'full_name' => $c['name']['full_with_opf'] ?? '—',
        'short_name' => $c['name']['short_with_opf'] ?? '—',
        'inn' => $c['inn'] ?? '—',
        'kpp' => $c['kpp'] ?? '—',
        'ogrn' => $c['ogrn'] ?? '—',
        'address' => $c['address']['value'] ?? '—',
        'status' => ($c['state']['status'] ?? '') == 'ACTIVE' ? 'Действует' : ($c['state']['status'] ?? '—'),
        'management' => ($c['management']['name'] ?? '—') . ' (' . ($c['management']['post'] ?? '—') . ')',
        'okved' => ($c['okved'] ?? '—') . ' (' . (substr($c['okved'] ?? '', 0, 2) ? 'ОКВЭД' : '—') . ')'
    ];
}