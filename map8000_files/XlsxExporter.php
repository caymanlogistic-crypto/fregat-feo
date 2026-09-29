<?php
declare(strict_types=1);

final class Map8000XlsxExporter
{
    public function create(array $routes): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('На сервере требуется расширение PHP zip.');
        }

        $path = tempnam(sys_get_temp_dir(), 'map8000-export-');
        if ($path === false) {
            throw new RuntimeException('Не удалось создать временный файл выгрузки.');
        }

        $summary = [[
            'ID рейса', 'Название', 'Дата', 'Конечный город', 'Заявок', 'Вес, т',
            'Расстояние, км', 'Время, мин', 'Источник расстояния', 'Транспорт',
            'Водитель', 'Примечание', 'Создан', 'Изменён',
        ]];
        $details = [[
            'ID рейса', 'Название рейса', 'Порядок', 'ID заявки', 'Строка Excel',
            'Регион', 'МО/МНО', 'Адрес МНО', 'Широта', 'Долгота', 'Вес, т',
            'Код ФККО', 'Вид отхода', 'Категория ФККО', 'Признак ТДТ',
            'График вывоза', 'ОП', 'Адрес ОП', 'Источник координат',
            'Качество определения', 'Статус геокодирования', 'Конечный город',
        ]];
        $points = [[
            'ID рейса', 'Название рейса', 'Порядок', 'Тип точки', 'ID заявки',
            'Адрес/город', 'Широта', 'Долгота', 'Вес, т',
        ]];

        foreach ($routes as $route) {
            $requests = is_array($route['requests'] ?? null) ? $route['requests'] : [];
            $totalWeight = array_reduce(
                $requests,
                static fn(float $sum, array $request): float => $sum + (float)($request['weight_t'] ?? 0),
                0.0
            );
            $summary[] = [
                (int)($route['id'] ?? 0),
                (string)($route['name'] ?? ''),
                (string)($route['date'] ?? ''),
                (string)($route['destination_label'] ?? $route['destination'] ?? ''),
                count($requests),
                round($totalWeight, 6),
                round((float)($route['distance_km'] ?? 0), 2),
                (int)round((float)($route['duration_min'] ?? 0)),
                (string)($route['distance_source'] ?? ''),
                (string)($route['vehicle'] ?? ''),
                (string)($route['driver'] ?? ''),
                (string)($route['notes'] ?? ''),
                (string)($route['created_at'] ?? ''),
                (string)($route['updated_at'] ?? ''),
            ];

            foreach ($requests as $index => $request) {
                $order = $index + 1;
                $details[] = [
                    (int)($route['id'] ?? 0),
                    (string)($route['name'] ?? ''),
                    $order,
                    (string)($request['id'] ?? ''),
                    (int)($request['source_row'] ?? 0),
                    (string)($request['region'] ?? ''),
                    (string)($request['municipality'] ?? ''),
                    (string)($request['address'] ?? ''),
                    (float)($request['latitude'] ?? 0),
                    (float)($request['longitude'] ?? 0),
                    (float)($request['weight_t'] ?? 0),
                    (string)($request['fkko_code'] ?? ''),
                    (string)($request['waste_type'] ?? ''),
                    (string)($request['category'] ?? ''),
                    (string)($request['tdt'] ?? ''),
                    (string)($request['schedule'] ?? ''),
                    (string)($request['operator'] ?? ''),
                    (string)($request['operator_address'] ?? ''),
                    (string)($request['coordinate_source'] ?? ''),
                    (string)($request['geocode_quality'] ?? ''),
                    (string)($request['geocode_status'] ?? ''),
                    (string)($route['destination_label'] ?? $route['destination'] ?? ''),
                ];
                $points[] = [
                    (int)($route['id'] ?? 0),
                    (string)($route['name'] ?? ''),
                    $order,
                    $order === 1 ? 'Начальная заявка' : 'Заявка',
                    (string)($request['id'] ?? ''),
                    (string)($request['address'] ?? ''),
                    (float)($request['latitude'] ?? 0),
                    (float)($request['longitude'] ?? 0),
                    (float)($request['weight_t'] ?? 0),
                ];
            }

            $destination = is_array($route['destination_coords'] ?? null) ? $route['destination_coords'] : [null, null];
            $points[] = [
                (int)($route['id'] ?? 0),
                (string)($route['name'] ?? ''),
                count($requests) + 1,
                'Конечный город',
                '',
                (string)($route['destination_label'] ?? $route['destination'] ?? ''),
                $destination[0] ?? '',
                $destination[1] ?? '',
                '',
            ];
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Не удалось сформировать Excel-архив.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('docProps/app.xml', $this->appProperties());
            $zip->addFromString('docProps/core.xml', $this->coreProperties());
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($summary, [10, 28, 14, 20, 10, 12, 17, 13, 20, 18, 18, 34, 24, 24]));
            $zip->addFromString('xl/worksheets/sheet2.xml', $this->worksheet($details, [10, 28, 10, 14, 12, 20, 24, 42, 13, 13, 12, 16, 34, 18, 14, 20, 22, 38, 22, 28, 22, 20]));
            $zip->addFromString('xl/worksheets/sheet3.xml', $this->worksheet($points, [10, 28, 10, 20, 14, 42, 13, 13, 12]));
        } finally {
            $zip->close();
        }

        return $path;
    }

    private function worksheet(array $rows, array $widths): string
    {
        $lastColumn = $this->columnName(max(1, count($rows[0] ?? [])));
        $lastRow = max(1, count($rows));
        $columns = '';
        foreach ($widths as $index => $width) {
            $number = $index + 1;
            $columns .= '<col min="' . $number . '" max="' . $number . '" width="' . $width . '" customWidth="1"/>';
        }

        $sheetData = '';
        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $cells = '';
            foreach (array_values($row) as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1) . $excelRow;
                $style = $rowIndex === 0 ? 1 : ($this->isNumber($value) ? 2 : 0);
                if ($this->isNumber($value)) {
                    $cells .= '<c r="' . $reference . '" s="' . $style . '"><v>' . $this->xml((string)$value) . '</v></c>';
                } else {
                    $cells .= '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                        . $this->xml((string)$value) . '</t></is></c>';
                }
            }
            $sheetData .= '<row r="' . $excelRow . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastColumn . $lastRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/><cols>' . $columns . '</cols><sheetData>' . $sheetData . '</sheetData>'
            . '<autoFilter ref="A1:' . $lastColumn . $lastRow . '"/></worksheet>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Рейсы" sheetId="1" r:id="rId1"/><sheet name="Заявки рейсов" sheetId="2" r:id="rId2"/><sheet name="Маршрутные точки" sheetId="3" r:id="rId3"/></sheets>'
            . '</workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
            . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="10"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F4E78"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border/><border><left style="thin"><color rgb="FFD9E2F3"/></left><right style="thin"><color rgb="FFD9E2F3"/></right><top style="thin"><color rgb="FFD9E2F3"/></top><bottom style="thin"><color rgb="FFD9E2F3"/></bottom></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function appProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>FREGAT map8000</Application></Properties>';
    }

    private function coreProperties(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>FREGAT map8000</dc:creator><cp:lastModifiedBy>FREGAT map8000</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created></cp:coreProperties>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
