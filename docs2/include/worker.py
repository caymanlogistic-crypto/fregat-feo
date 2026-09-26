#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys
import json
import re
import pdfplumber
from decimal import Decimal, ROUND_HALF_UP

TABLE_SETTINGS = {
    "vertical_strategy": "lines",
    "horizontal_strategy": "lines",
    "snap_tolerance": 3,
    "join_tolerance": 3,
    "edge_min_length": 3,
    "intersection_tolerance": 3
}

def normalize_text(text):
    if not text:
        return ""
    t = str(text)
    t = re.sub(r'[\r\n]+', ' ', t)
    t = re.sub(r'\s+', ' ', t)
    return t.strip()

def clean_float(val):
    try:
        if val is None or val == '':
            return None
        s = str(val).replace(",", ".").strip()
        if not s:
            return None
        d = Decimal(s).quantize(Decimal('0.000001'), rounding=ROUND_HALF_UP)
        result = float(d)
        if abs(result - round(result)) < 1e-10:
            return int(round(result))
        return round(result, 6)
    except:
        return None

def clean_int(val):
    try:
        if val is None or val == '':
            return None
        s = re.sub(r'\D', '', str(val))
        return int(s) if s else None
    except:
        return None

def clean_fkko(text):
    if not text:
        return ""
    return re.sub(r"\D", "", str(text))[:11]

def extract_metadata(text):
    meta = {}
    m = re.search(r'ПОРУЧЕНИЕ\s*№\s*(\d+)', text, re.IGNORECASE)
    meta["Номер поручения"] = clean_int(m.group(1)) if m else None
    m = re.search(r'от\s*[«"]?(\d{1,2})[»"]?\s*([а-яА-Я]+)\s*(\d{4})', text, re.IGNORECASE)
    if m:
        months = {'января':'01','февраля':'02','марта':'03','апреля':'04',
                  'мая':'05','июня':'06','июля':'07','августа':'08',
                  'сентября':'09','октября':'10','ноября':'11','декабря':'12'}
        meta["Дата поручения"] = f"{m.group(1).zfill(2)}.{months.get(m.group(2).lower(), '00')}.{m.group(3)}"
    m = re.search(r'договору\s*№\s*([^\s]+)\s*от\s*(\d{2}\.\d{2}\.\d{4})', text, re.IGNORECASE)
    if m:
        meta["номер договора"] = m.group(1)
        meta["Дата договора"] = m.group(2)
    m = re.search(r'2\.1\.\s*Полное наименование:\s*(.*?)\s*2\.2\.', text, re.IGNORECASE | re.DOTALL)
    if m:
        meta["Полное наименование"] = normalize_text(m.group(1))
    m = re.search(r'2\.2\.\s*ИНН:\s*(\d{10,12})', text, re.IGNORECASE)
    if m:
        meta["ИНН"] = clean_int(m.group(1))
    m = re.search(r'2\.3\.\s*Телефон:\s*([^\n]+)', text, re.IGNORECASE)
    if m:
        phone = re.sub(r'\s+', ' ', m.group(1)).strip()
        phone = re.split(r'\s{2,}', phone)[0]
        meta["Телефон"] = phone
    m = re.search(r'Адрес места передачи.*?:\s*(.*?)(?:\s*4\.|\s*Ограничения|\s*Дополнительные)', text, re.IGNORECASE | re.DOTALL)
    if m:
        meta["Адрес места передачи (погрузки) отходов"] = normalize_text(m.group(1))
    return meta

def parse_tables(all_tables):
    """Парсит ВСЕ строки — без дедупликации!"""
    items = []
    
    # 1. Собираем все строки в плоский список для сквозной обработки
    all_rows = []
    for table in all_tables:
        if table:
            for row in table:
                if row and len(row) >= 13:
                    all_rows.append(row)

    # 2. Безопасно склеиваем строки, разорванные разрывом страницы.
    # Продолжение всегда имеет пустую первую колонку ("№ п/п"), так как нумерация идет только на первой странице блока.
    merged_rows = []
    for row in all_rows:
        if not merged_rows:
            merged_rows.append(list(row))
            continue

        is_continuation = (row[0] is None or str(row[0]).strip() == '')

        if is_continuation:
            last_row = merged_rows[-1]
            # Аккуратно дописываем текст только в ячейки, где есть новые данные
            for i in range(min(len(last_row), len(row))):
                if row[i]:
                    last_row[i] = f"{last_row[i] or ''} {row[i]}".strip()
        else:
            merged_rows.append(list(row))

    # 3. Оригинальная логика парсинга (не изменена)
    for row in merged_rows:
        cells = [normalize_text(c) if c else "" for c in row]
        
        # Колонка 12 (индекс 11) — услуга
        service = cells[11].lower() if len(cells) > 11 else ""
        if "трансп" not in service:
            continue
            
        item = {}
        # Колонка 2: вид тары
        if len(cells) > 1 and cells[1]:
            item["вид тары"] = cells[1]
        # Колонка 3: количество
        if len(cells) > 2:
            qty = clean_int(cells[2])
            if qty is not None:
                item["количество"] = qty
        # Колонка 4: способ упаковки
        if len(cells) > 3 and cells[3]:
            item["способ упаковки"] = re.sub(r'\s+', '', cells[3])
        # Колонка 5: длина
        if len(cells) > 4:
            val = clean_float(cells[4])
            if val is not None:
                item["длина"] = val
        # Колонка 6: ширина
        if len(cells) > 5:
            val = clean_float(cells[5])
            if val is not None:
                item["ширина"] = val
        # Колонка 7: высота
        if len(cells) > 6:
            val = clean_float(cells[6])
            if val is not None:
                item["высота"] = val
        # Колонка 8: масса брутто
        if len(cells) > 7:
            val = clean_float(cells[7])
            if val is not None:
                item["Масса брутто"] = val
        # Колонка 9: объем
        if len(cells) > 8:
            val = clean_float(cells[8])
            if val is not None:
                item["объем"] = val
        # Колонка 10: код ФККО
        if len(cells) > 9:
            val = clean_fkko(cells[9])
            if val:
                item["код ФККО"] = val
        # Колонка 11: наименование отхода
        if len(cells) > 10 and cells[10]:
            item["наименование отхода"] = cells[10]
        # Колонка 13 (индекс 12) — МАССА НЕТТО
        if len(cells) > 12:
            val = clean_float(cells[12])
            if val is not None:
                item["масса нетто"] = val
                
        if item:
            items.append(item)

    return items

def process_pdf(filepath):
    result = {"document": {}, "items": []}
    try:
        with pdfplumber.open(filepath) as pdf:
            first_text = pdf.pages[0].extract_text() or ""
            result["document"] = extract_metadata(first_text)
            all_tables = []
            for page in pdf.pages:
                tables = page.extract_tables(TABLE_SETTINGS)
                if tables:
                    all_tables.extend(tables)
            result["items"] = parse_tables(all_tables)
            print(f"Найдено строк с транспортированием: {len(result['items'])}", file=sys.stderr)
    except Exception as e:
        result["error"] = f"Ошибка: {str(e)}"
    return result

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Использование: python worker.py <файл.pdf>")
        sys.exit(1)
    for pdf_path in sys.argv[1:]:
        data = process_pdf(pdf_path)
        print(json.dumps(data, ensure_ascii=False, indent=2))