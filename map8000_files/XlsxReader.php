<?php
declare(strict_types=1);

final class Map8000XlsxReader
{
    private const SOURCE_SHEET = 'Справочная информация';

    public function readRequests(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Файл заявок не найден.');
        }
        if (!class_exists(ZipArchive::class) || !class_exists(XMLReader::class)) {
            throw new RuntimeException('На сервере требуются расширения PHP zip и xmlreader.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть Excel-файл.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath = $this->resolveSheetPath($zip, self::SOURCE_SHEET);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new RuntimeException('Не найден лист с заявками.');
            }
        } finally {
            $zip->close();
        }

        $reader = new XMLReader();
        if (!$reader->XML($sheetXml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Не удалось прочитать лист Excel.');
        }

        $headers = [];
        $requests = [];
        $headerRow = null;
        $currentRow = 0;
        $rowValues = [];

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $currentRow = (int)($reader->getAttribute('r') ?: 0);
                $rowValues = [];
                continue;
            }

            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                $reference = (string)$reader->getAttribute('r');
                $type = (string)$reader->getAttribute('t');
                $columnIndex = $this->columnIndex($reference);
                $rowValues[$columnIndex] = $this->readCellValue($reader, $type, $sharedStrings);
                continue;
            }

            if ($reader->nodeType !== XMLReader::END_ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            if ($headerRow === null) {
                if (in_array('Регион МНО', $rowValues, true) && in_array('Адрес МНО', $rowValues, true)) {
                    $headerRow = $currentRow;
                    $maxColumn = $rowValues === [] ? -1 : max(array_keys($rowValues));
                    for ($i = 0; $i <= $maxColumn; $i++) {
                        $headers[$i] = trim((string)($rowValues[$i] ?? ''));
                    }
                }
                continue;
            }

            if ($currentRow <= $headerRow || $rowValues === []) {
                continue;
            }

            $request = $this->normalizeRow($currentRow, $headers, $rowValues);
            if ($request !== null) {
                $requests[] = $request;
            }
        }
        $reader->close();

        if ($headerRow === null) {
            throw new RuntimeException('Не найдена строка заголовков заявок.');
        }

        return [
            'headers' => array_values($headers),
            'requests' => $requests,
            'stats' => [
                'total' => count($requests),
                'source_file' => basename($path),
                'source_mtime' => date(DATE_ATOM, (int)filemtime($path)),
            ],
        ];
    }

    private function normalizeRow(int $rowNumber, array $headers, array $row): ?array
    {
        $allFields = [];
        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $allFields[$header] = $row[$index] ?? null;
            }
        }

        $sourceCoordinates = $this->parseCoordinates($row[3] ?? null);
        $status = trim((string)($row[18] ?? ''));
        $yandexLat = $this->numeric($row[12] ?? null);
        $yandexLon = $this->numeric($row[13] ?? null);
        $useYandex = $status === 'Обработано'
            && $this->validLatitude($yandexLat)
            && $this->validLongitude($yandexLon);

        $latitude = $useYandex ? $yandexLat : ($sourceCoordinates[0] ?? null);
        $longitude = $useYandex ? $yandexLon : ($sourceCoordinates[1] ?? null);
        if (!$this->validLatitude($latitude) || !$this->validLongitude($longitude)) {
            return null;
        }

        return [
            'id' => 'R' . $rowNumber,
            'source_row' => $rowNumber,
            'region' => trim((string)($row[0] ?? '')),
            'municipality' => trim((string)($row[1] ?? '')),
            'address' => trim((string)($row[2] ?? '')),
            'latitude' => round((float)$latitude, 7),
            'longitude' => round((float)$longitude, 7),
            'coordinate_source' => $useYandex ? 'Яндекс' : 'Исходные координаты',
            'fkko_code' => trim((string)($row[4] ?? '')),
            'waste_type' => trim((string)($row[5] ?? '')),
            'category' => trim((string)($row[6] ?? '')),
            'weight_t' => round((float)($this->numeric($row[7] ?? null) ?? 0.0), 6),
            'tdt' => trim((string)($row[8] ?? '')),
            'schedule' => trim((string)($row[9] ?? '')),
            'operator' => trim((string)($row[10] ?? '')),
            'operator_address' => trim((string)($row[11] ?? '')),
            'geocode_quality' => trim((string)($row[14] ?? '')),
            'geocode_precision' => trim((string)($row[15] ?? '')),
            'geocoded_address' => trim((string)($row[16] ?? '')),
            'object_type' => trim((string)($row[17] ?? '')),
            'geocode_status' => $status,
            'geocode_date' => trim((string)($row[19] ?? '')),
            'source_note' => trim((string)($row[20] ?? '')),
            'all_fields' => $allFields,
        ];
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }

        $strings = [];
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                continue;
            }
            $depth = $reader->depth;
            $text = '';
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                    $text .= $reader->readString();
                }
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si' && $reader->depth === $depth) {
                    break;
                }
            }
            $strings[] = $text;
        }
        $reader->close();
        return $strings;
    }

    private function resolveSheetPath(ZipArchive $zip, string $sheetName): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('Повреждена структура Excel-файла.');
        }

        $workbook = simplexml_load_string($workbookXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        $rels = simplexml_load_string($relsXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($workbook === false || $rels === false) {
            throw new RuntimeException('Не удалось прочитать структуру Excel-файла.');
        }

        $relationshipId = '';
        $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        foreach ($workbook->xpath('//main:sheets/main:sheet') ?: [] as $sheet) {
            if ((string)$sheet['name'] !== $sheetName) {
                continue;
            }
            $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationshipId = (string)$attributes['id'];
            break;
        }
        if ($relationshipId === '') {
            throw new RuntimeException('Лист «' . $sheetName . '» отсутствует в Excel-файле.');
        }

        $target = '';
        foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            if ((string)$relationship['Id'] === $relationshipId) {
                $target = (string)$relationship['Target'];
                break;
            }
        }
        if ($target === '') {
            throw new RuntimeException('Не найден XML листа Excel.');
        }

        $target = str_replace('\\', '/', $target);
        $target = ltrim($target, '/');
        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }

    private function readCellValue(XMLReader $reader, string $type, array $sharedStrings): mixed
    {
        if ($reader->isEmptyElement) {
            return null;
        }

        $depth = $reader->depth;
        $value = null;
        $inlineText = '';
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'v') {
                $value = $reader->readString();
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                $inlineText .= $reader->readString();
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c' && $reader->depth === $depth) {
                break;
            }
        }

        if ($type === 's') {
            return $sharedStrings[(int)$value] ?? '';
        }
        if ($type === 'inlineStr') {
            return $inlineText;
        }
        if ($type === 'b') {
            return $value === '1';
        }
        if ($value === null || $value === '') {
            return $inlineText !== '' ? $inlineText : null;
        }
        if ($type === 'str' || $type === 'e') {
            return $value;
        }
        return is_numeric($value) ? (float)$value : $value;
    }

    private function columnIndex(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return 0;
        }
        $index = 0;
        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }
        return max(0, $index - 1);
    }

    private function parseCoordinates(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }
        if (!preg_match('/^\s*(-?\d+(?:[.,]\d+)?)\s*[,;]\s*(-?\d+(?:[.,]\d+)?)\s*$/u', $value, $matches)) {
            return null;
        }
        $latitude = (float)str_replace(',', '.', $matches[1]);
        $longitude = (float)str_replace(',', '.', $matches[2]);
        return ($this->validLatitude($latitude) && $this->validLongitude($longitude))
            ? [$latitude, $longitude]
            : null;
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (!is_string($value)) {
            return null;
        }
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        return is_numeric($normalized) ? (float)$normalized : null;
    }

    private function validLatitude(?float $value): bool
    {
        return $value !== null && $value >= -90 && $value <= 90;
    }

    private function validLongitude(?float $value): bool
    {
        return $value !== null && $value >= -180 && $value <= 180;
    }
}
