<?php
class TransportMatcherService
{
    public function parseTrackerName($name)
    {
        $name = (string)$name;
        if ($name === '') {
            return null;
        }

        if (!preg_match('/^\s*([^(]+?)\s*\(([^)]+)\)/u', $name, $m)) {
            return null;
        }

        $plate = $this->normalizePlate($m[1] ?? '');
        $surname = $this->normalizeSurname($m[2] ?? '');
        if ($plate === '' || $surname === '') {
            return null;
        }

        return [
            'plate' => $plate,
            'surname' => $surname,
        ];
    }

    public function normalizeSurname($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        $parts = explode(' ', $value);
        $surname = trim((string)($parts[0] ?? ''));
        if ($surname === '') {
            return '';
        }

        if (function_exists('mb_strtoupper')) {
            $surname = mb_strtoupper($surname, 'UTF-8');
        } else {
            $surname = strtoupper($surname);
        }
        $surname = str_replace('Ё', 'Е', $surname);
        $surname = preg_replace('/[^А-ЯA-Z]/u', '', $surname);

        return (string)$surname;
    }

    public function normalizePlate($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strtoupper')) {
            $value = mb_strtoupper($value, 'UTF-8');
        } else {
            $value = strtoupper($value);
        }

        $map = [
            'A' => 'А', 'B' => 'В', 'E' => 'Е', 'K' => 'К', 'M' => 'М', 'H' => 'Н', 'O' => 'О', 'P' => 'Р', 'C' => 'С', 'T' => 'Т', 'Y' => 'У', 'X' => 'Х',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^А-Я0-9]/u', '', $value);

        return (string)$value;
    }

    public function buildDriverKey(array $driver)
    {
        $surname = $this->normalizeSurname($driver['full_name'] ?? '');
        $plateSource = $this->extractPlateFromText($driver['vehicle_make_plate'] ?? '');
        $plate = $this->normalizePlate($plateSource);
        if ($surname === '' || $plate === '') {
            return null;
        }
        return $surname . '|' . $plate;
    }

    public function buildTrackerKey(array $tracker)
    {
        $parsed = $this->parseTrackerName($tracker['name'] ?? '');
        if (!is_array($parsed)) {
            return null;
        }
        return $parsed['surname'] . '|' . $parsed['plate'];
    }

    public function extractPlateFromText($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/([А-ЯA-Z]\s*\d{3}\s*[А-ЯA-Z]{2}\s*\d{2,3})/u', $value, $m)) {
            return (string)$m[1];
        }

        $parts = preg_split('/\s+/u', $value);
        if (is_array($parts) && !empty($parts)) {
            return (string)end($parts);
        }

        return $value;
    }
}
