<?php
function mapLog($message, array $context = [])
{
    $line = '[map] ' . $message;
    if (!empty($context)) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    error_log($line);
}

function mapError($message, array $context = [])
{
    $line = '[map:error] ' . $message;
    if (!empty($context)) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    error_log($line);
}

function utcToMoscow($utcTime)
{
    if (empty($utcTime)) {
        return '';
    }

    try {
        $dt = new DateTime($utcTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Moscow'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        mapError('Invalid UTC datetime', ['value' => $utcTime, 'error' => $e->getMessage()]);
        return '';
    }
}

function getTimeDiff($moscowTime)
{
    if (empty($moscowTime)) {
        return ['text' => 'Нет данных', 'minutes' => 9999];
    }

    try {
        $now = new DateTime('now', new DateTimeZone('Europe/Moscow'));
        $then = new DateTime($moscowTime, new DateTimeZone('Europe/Moscow'));
        $interval = $now->diff($then);
        $totalMin = ($interval->days * 1440) + ($interval->h * 60) + $interval->i;

        if ($interval->d > 0) {
            $text = $interval->d . 'д ' . $interval->h . 'ч ' . $interval->i . 'м';
        } elseif ($interval->h > 0) {
            $text = $interval->h . 'ч ' . $interval->i . 'м';
        } else {
            $text = $interval->i . 'м';
        }

        return ['text' => $text, 'minutes' => $totalMin];
    } catch (Exception $e) {
        mapError('Invalid Moscow datetime', ['value' => $moscowTime, 'error' => $e->getMessage()]);
        return ['text' => 'Нет данных', 'minutes' => 9999];
    }
}

function getShortName($fullName)
{
    $fullName = (string)$fullName;
    if (preg_match('/\(([^)]+)\)/', $fullName, $matches)) {
        return $matches[1];
    }

    return $fullName;
}

function canMergePoints($a, $b)
{
    if (!is_array($a) || !is_array($b)) {
        return false;
    }

    if (($a['mno_sh'] ?? null) != ($b['mno_sh'] ?? null) || ($a['mno_d'] ?? null) != ($b['mno_d'] ?? null)) {
        return false;
    }

    if (($a['in_flight'] ?? null) !== ($b['in_flight'] ?? null)) {
        return false;
    }

    $utilizerA = (string)($a['utilizer_match_key'] ?? ($a['utilizer_id'] ?? ''));
    $utilizerB = (string)($b['utilizer_match_key'] ?? ($b['utilizer_id'] ?? ''));
    if ($utilizerA !== $utilizerB) {
        return false;
    }

    if (empty($a['in_flight']) && (($a['custom_layer'] ?? null) || ($b['custom_layer'] ?? null))) {
        return ($a['custom_layer'] ?? null) === ($b['custom_layer'] ?? null);
    }

    if (!empty($a['in_flight']) && !empty($b['in_flight'])) {
        return ($a['flight_id'] ?? null) === ($b['flight_id'] ?? null);
    }

    return true;
}

function mapNormalizeText($value)
{
    if (!is_string($value) || $value === '') {
        return $value;
    }

    if (preg_match('//u', $value) !== 1) {
        $converted = @iconv('CP1251', 'UTF-8//IGNORE', $value);
        return (is_string($converted) && $converted !== '') ? $converted : $value;
    }

    if (strpos($value, 'Р') !== false || strpos($value, 'С') !== false) {
        $step = @iconv('UTF-8', 'CP1251//IGNORE', $value);
        if (is_string($step) && $step !== '') {
            $fixed = @iconv('CP1251', 'UTF-8//IGNORE', $step);
            if (is_string($fixed) && $fixed !== '') {
                $origBad = substr_count($value, 'Р') + substr_count($value, 'С');
                $newBad = substr_count($fixed, 'Р') + substr_count($fixed, 'С');
                if ($newBad < $origBad) {
                    return $fixed;
                }
            }
        }
    }

    return $value;
}
