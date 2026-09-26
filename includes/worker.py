#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Worker: Извлекает данные из PDF и выводит JSON в stdout.
"""
import sys
import json
import re
from datetime import datetime

import pdfplumber

# =============================================================================
# CONFIGURATION
# =============================================================================
TABLE_SETTINGS = {
    "vertical_strategy": "lines",
    "horizontal_strategy": "lines",
    "snap_tolerance": 3,
    "snap_x_tolerance": 3,
    "snap_y_tolerance": 3,
    "join_tolerance": 2,
    "join_x_tolerance": 2,
    "join_y_tolerance": 2,
    "edge_min_length": 5,
    "min_words_vertical": 1,
    "min_words_horizontal": 1,
    "intersection_tolerance": 3,
}

# =============================================================================
# UTILS
# =============================================================================
def normalize_cell_text(text):
    if not text or not isinstance(text, str):
        return ""
    cleaned = re.sub(r'(\w)\s+\n\s+(\w)', r'\1\2', text)
    cleaned = re.sub(r'[\s\n\r]+', ' ', cleaned)
    return cleaned.strip()

def clean_address(address):
    """Очистка адреса от лишних символов и нормализация"""
    if not address:
        return ""
    # Убираем лишние пробелы и переносы строк
    address = re.sub(r'\s+', ' ', address)
    # Убираем лишние запятые в начале и конце
    address = address.strip(' ,')
    return address

def extract_metadata(page):
    metadata = {}
    text = page.extract_text() or ""
    
    # =========================================================
    # 1. ПОРУЧЕНИЕ № и ДАТА
    # =========================================================
    order_match = re.search(r'ПОРУЧЕНИЕ\s*№\s*(\d+)', text, re.IGNORECASE)
    if order_match:
        metadata["order_number"] = order_match.group(1)
    
    date_match = re.search(r'от\s*[«"\'»]?(\d+)[»«"\'»]?\s+([а-яА-Я]+)\s+(\d{4})', text, re.IGNORECASE)
    if date_match:
        day = date_match.group(1)
        month_name = date_match.group(2).lower()
        year = date_match.group(3)
        
        month_map = {
            'января': '01', 'февраля': '02', 'марта': '03', 'апреля': '04',
            'мая': '05', 'июня': '06', 'июля': '07', 'августа': '08',
            'сентября': '09', 'октября': '10', 'ноября': '11', 'декабря': '12',
            'январь': '01', 'февраль': '02', 'март': '03', 'апрель': '04',
            'май': '05', 'июнь': '06', 'июль': '07', 'август': '08',
            'сентябрь': '09', 'октябрь': '10', 'ноябрь': '11', 'декабрь': '12'
        }
        
        month_num = month_map.get(month_name, '01')
        day_formatted = day.zfill(2)
        metadata["order_date"] = f"{day_formatted}.{month_num}.{year}"
    
    # =========================================================
    # 2. ДОГОВОР
    # =========================================================
    contract_match = re.search(r'договору\s*№\s*([^\s]+)\s+от\s+(\d{2}\.\d{2}\.\d{4})', text, re.IGNORECASE)
    if contract_match:
        metadata["contract_number"] = contract_match.group(1)
        metadata["contract_date"] = contract_match.group(2)
    
    # =========================================================
    # 3. ГРУЗООТПРАВИТЕЛЬ (из пункта 2.1)
    # =========================================================
    shipper_match = re.search(r'2\.1\.\s*Полное наименование:\s*([^\n]+)', text, re.IGNORECASE)
    if shipper_match:
        shipper = shipper_match.group(1).strip()
        shipper = re.sub(r'^["\']|["\']$', '', shipper)
        metadata["shipper_name"] = normalize_cell_text(shipper)
    
    # =========================================================
    # 4. ИНН (из пункта 2.2)
    # =========================================================
    inn_match = re.search(r'2\.2\.\s*ИНН:\s*(\d{10,12})', text, re.IGNORECASE)
    if not inn_match:
        inn_match = re.search(r'ИНН:\s*(\d{10,12})', text)
    
    if inn_match:
        metadata["shipper_inn"] = inn_match.group(1)
    
    # =========================================================
    # 5. АДРЕС ЗАГРУЗКИ (из пункта 3) - многострочный
    # =========================================================
    # Ищем "3. Адрес места передачи (погрузки) отходов:" и берем все до следующего пункта "4."
    address_match = re.search(r'3\.\s*Адрес места передачи[^:]*:\s*([\s\S]+?)(?=\n\s*4\.|\n\s*Ограничения)', text, re.IGNORECASE)
    if address_match:
        address = address_match.group(1).strip()
        # Очищаем от лишних переносов и пробелов
        address = clean_address(address)
        metadata["loading_address"] = address
    
    # =========================================================
    # 6. ЮРИДИЧЕСКИЙ АДРЕС (будет заполнен из DaData, пока пусто)
    # =========================================================
    metadata["legal_address"] = ""
    
    return metadata

# =============================================================================
# PARSING LOGIC
# =============================================================================
def parse_waste_table(raw_table, page_num, table_idx):
    rows = []
    if not raw_table:
        return rows

    col_keys = [
        "row_id", "packaging_type", "quantity", "packing_method", "length_m", "width_m", "height_m",
        "gross_weight_t", "volume_m3", "waste_code_fkko", "waste_name", "service_type",
        "net_weight_t", "unit_price_no_vat", "unit_price_vat", "service_price_no_vat",
        "service_price_vat", "notes"
    ]

    for row_idx, row in enumerate(raw_table):
        cells = [normalize_cell_text(c) for c in row]
        row_text = " ".join(cells).lower()

        skip_kw = ["№ п/п", "вид тары", "---", "здесь далее", "указывается", "согласно"]
        if any(kw in row_text for kw in skip_kw):
            continue
        if all(c in ("", "-") or c.isdigit() for c in cells):
            continue

        keep_kw = ["код:", "аккуму", "свинц", "трансп", "утилиз", "фкко", "источн", "беспер"]
        if not any(kw in row_text for kw in keep_kw):
            continue

        row_data = {"page": page_num, "table_index": table_idx, "table_row_index": row_idx}
        for col_idx, cell_val in enumerate(cells):
            if col_idx < len(col_keys):
                key = col_keys[col_idx]
                if cell_val and isinstance(cell_val, str) and cell_val.strip():
                    row_data[key] = cell_val.strip()

        p_type = row_data.get("packaging_type", "")
        if p_type and "Код:" in p_type:
            code_match = re.search(r'Код:\s*([^\s]+)', p_type)
            if code_match:
                row_data["packaging_code"] = code_match.group(1)
                row_data["packaging_type"] = re.sub(r'Код:\s*[^\s]+\s*', '', p_type).strip()
        
        if len(row_data) > 3:
            rows.append(row_data)

    return rows

def process_pdf(filepath):
    result = {"metadata": {}, "tables": []}
    try:
        with pdfplumber.open(filepath) as pdf:
            if pdf.pages:
                result["metadata"] = extract_metadata(pdf.pages[0])
            
            for pn, page in enumerate(pdf.pages, 1):
                tables = page.extract_tables(TABLE_SETTINGS)
                for t_idx, t_rows in enumerate(tables):
                    parsed = parse_waste_table(t_rows, pn, t_idx)
                    if parsed:
                        result["tables"].append({
                            "page": pn, 
                            "table_index": t_idx, 
                            "data": parsed
                        })
    except Exception as e:
        result["error"] = str(e)
    return result

# =============================================================================
# MAIN
# =============================================================================
if __name__ == "__main__":
    if len(sys.argv) > 1:
        input_path = sys.argv[1]
        res = process_pdf(input_path)
        print(json.dumps(res, ensure_ascii=False))
    else:
        print(json.dumps({"error": "No file path provided"}))