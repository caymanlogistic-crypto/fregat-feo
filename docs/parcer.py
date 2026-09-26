#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys
import os
import json
import re
import logging
import pdfplumber

logging.basicConfig(level=logging.INFO, format='%(levelname)s: %(message)s')
logger = logging.getLogger(__name__)

def clean_text(text):
    if not text:
        return ""
    return " ".join(text.split()).strip()

def extract_metadata(full_text):
    meta = {
        "document_number": None,
        "document_date": None,
        "contract_ref": None,
        "shipper_name": None,
        "shipper_inn": None,
        "pickup_address": None
    }
    if not full_text:
        return meta

    # 1. Номер и дата
    m = re.search(r'ПОРУЧЕНИЕ\s*№\s*([\d]+)', full_text)
    if m:
        meta["document_number"] = m.group(1)

    m = re.search(r'от\s*«([^»]+)»\s*(.*?)(?:\n|\()', full_text)
    if m:
        d1 = m.group(1).strip()
        d2 = m.group(2).strip()
        meta["document_date"] = "«{}» {}".format(d1, d2)

    # 2. Договор
    m = re.search(r'договору\s*№\s*([^\s\)]+)', full_text)
    if m:
        meta["contract_ref"] = m.group(1)

    # 3. Грузоотправитель
    m = re.search(r'Полное наименование:\s*(.*?)(?:\n\d+\.\d+\.|\n.*?ИНН:|\Z)', full_text, re.DOTALL)
    if m:
        meta["shipper_name"] = clean_text(m.group(1))

    # 4. ИНН
    m = re.search(r'ИНН:\s*(\d{10,12})', full_text)
    if m:
        meta["shipper_inn"] = m.group(1)

    # 5. Адрес
    m = re.search(r'Адрес места передачи\(погрузки\) отходов:\s*(.*?)(?:\n\d\.|\n\d+\.\s|\Z)', full_text, re.DOTALL)
    if m:
        meta["pickup_address"] = clean_text(m.group(1))

    return meta

def parse_pdf_file(filepath, output_dir):
    logger.info("Processing: {}".format(os.path.basename(filepath)))
    result = {
        "filename": os.path.basename(filepath),
        "metadata": {},
        "tables": [],
        "parse_errors": []
    }

    try:
        with pdfplumber.open(filepath) as pdf:
            full_text = "\n".join(page.extract_text() or "" for page in pdf.pages)
            result["metadata"] = extract_metadata(full_text)

            settings = {
                "vertical_strategy": "lines",
                "horizontal_strategy": "lines",
                "snap_tolerance": 3,
                "join_tolerance": 3,
                "min_words_vertical": 1,
                "min_words_horizontal": 1
            }

            for page_num, page in enumerate(pdf.pages, 1):
                tables = page.extract_tables(settings)
                if not tables:
                    fallback = {"vertical_strategy": "text", "horizontal_strategy": "text"}
                    tables = page.extract_tables(fallback)

                if not tables:
                    continue

                for t_idx, table in enumerate(tables):
                    if not table or not any(any(c for c in row) for row in table):
                        continue

                    header_rows = []
                    keywords = ["№ п/п", "вид тары", "код отход", "наименован", "масс", "расцен", "цена", "габариты"]
                    for r_idx, row in enumerate(table[:3]):
                        row_text = " ".join(str(c) for c in row if c).lower()
                        if any(k in row_text for k in keywords):
                            header_rows.append(r_idx)
                        elif all(len((c or "").strip()) < 30 for c in row if c):
                            header_rows.append(r_idx)
                    header_rows = list(set(header_rows))

                    parsed_rows = []
                    for r_idx, row in enumerate(table):
                        if not any(c and c.strip() for c in row):
                            continue
                        cells = [{"col_idx": c_idx, "text": clean_text(c)} for c_idx, c in enumerate(row)]
                        parsed_rows.append({
                            "is_header": r_idx in header_rows,
                            "cells": cells
                        })

                    if parsed_rows:
                        result["tables"].append({
                            "page_num": page_num,
                            "table_idx": t_idx,
                            "row_count": len(parsed_rows),
                            "rows": parsed_rows
                        })

    except Exception as e:
        err_msg = "Error parsing {}: {}".format(os.path.basename(filepath), str(e))
        logger.error(err_msg)
        result["parse_errors"].append(err_msg)

    out_path = os.path.join(output_dir, "{}.json".format(os.path.splitext(os.path.basename(filepath))[0]))
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(result, f, ensure_ascii=False, indent=2)
    logger.info("Saved JSON: {}".format(out_path))
    return result

def main():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("-i", "--input", type=str, default=".", help="Input directory")
    parser.add_argument("-o", "--output", type=str, default="./output_json", help="Output directory")
    parser.add_argument("-f", "--format", type=str, default="json", help="Output format")
    args = parser.parse_args()

    input_dir = args.input
    output_dir = args.output

    if not os.path.exists(output_dir):
        os.makedirs(output_dir)

    pdf_files = [f for f in os.listdir(input_dir) if f.endswith('.pdf')]
    if not pdf_files:
        logger.warning("No PDF files found.")
        return

    for pdf_name in pdf_files:
        pdf_path = os.path.join(input_dir, pdf_name)
        parse_pdf_file(pdf_path, output_dir)

if __name__ == "__main__":
    main()