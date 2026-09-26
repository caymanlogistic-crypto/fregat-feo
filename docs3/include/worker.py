#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys
import json
import re
import pdfplumber
import hashlib
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
    """Чистое преобразование в число — возвращает округленный float"""
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

    # ★ ДОБАВЛЯЕМ ПАРСИНГ ТЕЛЕФОНА ★
    m = re.search(r'2\.3\.\s*Телефон:\s*([^\n]+)', text, re.IGNORECASE)
    if m:
        phone = re.sub(r'\s+', ' ', m.group(1)).strip()
        # Очищаем от возможного мусора после номера
        phone = re.split(r'\s{2,}', phone)[0]
        meta["Телефон"] = phone

    m = re.search(r'Адрес места передачи.*?:\s*(.*?)(?:\s*4\.|\s*Ограничения|\s*Дополнительные)', text, re.IGNORECASE | re.DOTALL)
    if m:
        meta["Адрес места передачи (погрузки) отходов"] = normalize_text(m.group(1))

    return meta

def parse_tables(all_tables):
    raw_items = []
    
    for table in all_tables:
        if not table:
            continue
        for row in table:
            if not row or len(row) < 12:
                continue
            
            cells = [normalize_text(c) if c else "" for c in row]
            service = cells[11].lower() if len(cells) > 11 else ""
            if "трансп" not in service:
                continue

            item = {}
            
            if len(cells) > 1 and cells[1]:
                item["вид тары"] = cells[1]
            if len(cells) > 2:
                qty = clean_int(cells[2])
                if qty is not None:
                    item["количество"] = qty
            if len(cells) > 3 and cells[3]:
                # ★ УДАЛЯЕМ ВСЕ ПРОБЕЛЫ ИЗ СПОСОБА УПАКОВКИ ★
                item["способ упаковки"] = re.sub(r'\s+', '', cells[3])
            
            for idx, key in [(4, "длина"), (5, "ширина"), (6, "высота"), 
                             (7, "Масса брутто"), (8, "объем"), (12, "масса нетто")]:
                if len(cells) > idx:
                    val = clean_float(cells[idx])
                    if val is not None:
                        item[key] = val
            
            if len(cells) > 9:
                val = clean_fkko(cells[9])
                if val:
                    item["код ФККО"] = val
            if len(cells) > 10 and cells[10]:
                item["наименование отхода"] = cells[10]
            
            if item:
                raw_items.append(item)

    seen = set()
    unique = []
    for item in raw_items:
        sig_fields = ['код ФККО', 'вид тары', 'длина', 'ширина', 'высота', 'Масса брутто']
        sig = "|".join(str(item.get(f, '')) for f in sig_fields)
        h = hashlib.md5(sig.encode()).hexdigest()
        if h not in seen:
            seen.add(h)
            unique.append(item)
    
    return unique

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