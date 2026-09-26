<?php
declare(strict_types=1);

// =============================================================================
// РЕНДЕРИНГ HTML ФОРМЫ
// =============================================================================

function renderEditableForm(array $rows, array $meta, string $fileName, string $message = ''): string {
    $html = '<div class="results-container">';
    
    if ($message) {
        $html .= '<div class="save-success">' . h($message) . '</div>';
    }
    
    $html .= '<div class="results-header">
        <h2>📄 Результат парсинга: ' . h($fileName) . '</h2>
        <div class="header-actions">
            <button type="button" class="btn-export-csv" onclick="exportToCSV()">📎 Экспорт в CSV</button>
        </div>
    </div>';
    
    // Метаданные
    $html .= '<div class="meta-editor card">
        <h3>📋 Метаданные документа</h3>
        <div class="meta-form-grid">
            <div class="meta-field">
                <label>№ Поручения (6 цифр):</label>
                <input type="text" id="meta_order_number" value="' . attr_escape($meta['order_number'] ?? '') . '" 
                       class="meta-input" placeholder="Например: 123456" maxlength="6">
            </div>
            <div class="meta-field">
                <label>Дата (ДД.ММ.ГГГГ):</label>
                <input type="text" id="meta_order_date" value="' . attr_escape($meta['order_date'] ?? '') . '" 
                       class="meta-input" placeholder="Например: 15.03.2025">
            </div>
            <div class="meta-field">
                <label>Договор:</label>
                <input type="text" id="meta_contract_number" value="' . attr_escape($meta['contract_number'] ?? '') . '" 
                       class="meta-input" placeholder="Например: Ц-ГИС/НД-09-2/13/26">
            </div>
            <div class="meta-field meta-field-full">
                <label>Грузоотправитель (полное наименование):</label>
                <textarea id="meta_shipper_name" class="meta-textarea" rows="3" placeholder="Полное наименование организации">' . attr_escape($meta['shipper_name'] ?? '') . '</textarea>
            </div>
            <div class="meta-field">
                <label>ИНН (10 или 12 цифр):</label>
                <input type="text" id="meta_shipper_inn" value="' . attr_escape($meta['shipper_inn'] ?? '') . '" 
                       class="meta-input" placeholder="Например: 1234567890" 
                       pattern="[0-9]{10,12}" maxlength="12">
            </div>
            <div class="meta-field meta-field-full">
                <label>Юридический адрес (заполняется автоматически по ИНН из DaData):</label>
                <textarea id="meta_legal_address" class="meta-textarea" rows="3" placeholder="Адрес загружается автоматически из DaData..." readonly>' . attr_escape($meta['legal_address'] ?? '') . '</textarea>
            </div>
            <div class="meta-field meta-field-full">
                <label>📍 Адрес загрузки (погрузки) отходов:</label>
                <textarea id="meta_loading_address" class="meta-textarea" rows="3" placeholder="Адрес места передачи (погрузки) отходов">' . attr_escape($meta['loading_address'] ?? '') . '</textarea>
            </div>
        </div>
    </div>';
    
    // Таблица (остается без изменений)
    $html .= '<form method="post" id="dataForm" class="data-form" onsubmit="return submitForm(event)">';
    $html .= '<input type="hidden" name="save_data" value="1">';
    $html .= '<input type="hidden" name="source_file_name" value="' . attr_escape($fileName) . '">';
    $html .= '<div class="table-container card">';
    $html .= '<div class="table-toolbar">
        <span class="row-count">📊 Всего записей: ' . count($rows) . '</span>
        <button type="submit" class="btn-save-all">💾 Сохранить все изменения</button>
    </div>';
    
    $html .= '<div class="table-scroll-wrapper">
        <table class="editable-table" id="dataTable">
            <thead>
                <tr>
                    <th width="40">#</th>
                    <th width="60">№ п/п</th>
                    <th width="250">Вид тары</th>
                    <th width="60">Кол-во</th>
                    <th width="250">Способ упаковки</th>
                    <th width="110">Код ФККО</th>
                    <th width="300">Наименование отхода</th>
                    <th width="60">Длина</th>
                    <th width="60">Ширина</th>
                    <th width="60">Высота</th>
                    <th width="80">Брутто (т)</th>
                    <th width="80">Нетто (т)</th>
                    <th width="80">Объем (м³)</th>
                </tr>
            </thead>
            <tbody>';
    
    if (empty($rows)) {
        $html .= '<tr><td colspan="13" style="text-align: center; padding: 40px;">📭 Нет данных для отображения</td></tr>';
    } else {
        foreach ($rows as $idx => $r) {
            $html .= renderEditableRow($idx, $r);
        }
    }
    
    $html .= '</tbody>
        </table>
    </div>';
    $html .= '</div></form>';
    
    $html .= '</div>';
    $html .= renderJavaScript();
    
    return $html;
}

function renderEditableRow(int $index, array $row): string {
    return '<tr>
        <td class="row-number">' . ($index + 1) . '</td>
        <td><input type="text" name="rows[' . $index . '][row_id]" value="' . attr_escape($row['row_id'] ?? '') . '" class="cell-input" placeholder="№ п/п"></td>
        <td><textarea name="rows[' . $index . '][packaging_type]" class="cell-textarea" rows="3" placeholder="Вид тары">' . attr_escape($row['packaging_type'] ?? '') . '</textarea></td>
        <td><input type="text" name="rows[' . $index . '][quantity]" value="' . attr_escape($row['quantity'] ?? '') . '" class="cell-input" placeholder="Кол-во"></td>
        <td><textarea name="rows[' . $index . '][packing_method]" class="cell-textarea" rows="3" placeholder="Способ упаковки">' . attr_escape($row['packing_method'] ?? '') . '</textarea></td>
        <td><input type="text" name="rows[' . $index . '][waste_code_fkko]" value="' . attr_escape($row['waste_code_fkko'] ?? '') . '" class="cell-input" placeholder="Код ФККО"></td>
        <td><textarea name="rows[' . $index . '][waste_name]" class="cell-textarea waste-name-input" rows="3" placeholder="Наименование отхода">' . attr_escape($row['waste_name'] ?? '') . '</textarea></td>
        <td><input type="text" name="rows[' . $index . '][length_m]" value="' . attr_escape($row['length_m'] ?? '') . '" class="cell-input" placeholder="Длина"></td>
        <td><input type="text" name="rows[' . $index . '][width_m]" value="' . attr_escape($row['width_m'] ?? '') . '" class="cell-input" placeholder="Ширина"></td>
        <td><input type="text" name="rows[' . $index . '][height_m]" value="' . attr_escape($row['height_m'] ?? '') . '" class="cell-input" placeholder="Высота"></td>
        <td><input type="text" name="rows[' . $index . '][gross_weight_t]" value="' . attr_escape($row['gross_weight_t'] ?? '') . '" class="cell-input" placeholder="Брутто"></td>
        <td><input type="text" name="rows[' . $index . '][net_weight_t]" value="' . attr_escape($row['net_weight_t'] ?? '') . '" class="cell-input" placeholder="Нетто"></td>
        <td><input type="text" name="rows[' . $index . '][volume_m3]" value="' . attr_escape($row['volume_m3'] ?? '') . '" class="cell-input" placeholder="Объем"></td>
    </tr>';
}

function renderJavaScript(): string {
    return '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const innInput = document.getElementById("meta_shipper_inn");
            const legalAddress = document.getElementById("meta_legal_address");
            
            // Если есть ИНН и юр. адрес пуст, получаем из DaData
            if (innInput && innInput.value && (!legalAddress.value || legalAddress.value === "")) {
                const inn = innInput.value.replace(/[^0-9]/g, "");
                if (inn.length === 10 || inn.length === 12) {
                    fetchAddressByINN(inn);
                }
            }
            
            // Следим за изменением ИНН
            let debounceTimer;
            innInput.addEventListener("input", function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    const inn = innInput.value.replace(/[^0-9]/g, "");
                    if (inn.length === 10 || inn.length === 12) {
                        fetchAddressByINN(inn);
                    }
                }, 500);
            });
        });
        
        function fetchAddressByINN(inn) {
            const addressTextarea = document.getElementById("meta_legal_address");
            const originalPlaceholder = addressTextarea.placeholder;
            addressTextarea.placeholder = "⏳ Загрузка адреса из DaData...";
            
            fetch(window.location.href, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: "get_address_by_inn=1&inn=" + encodeURIComponent(inn)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.address) {
                    addressTextarea.value = data.address;
                    addressTextarea.placeholder = originalPlaceholder;
                    showNotification("✅ Юридический адрес загружен из DaData!", "success");
                } else {
                    addressTextarea.value = "";
                    addressTextarea.placeholder = "❌ Адрес не найден в DaData";
                    showNotification("❌ Юридический адрес не найден. Проверьте ИНН.", "error");
                }
            })
            .catch(error => {
                console.error("Error:", error);
                addressTextarea.value = "";
                addressTextarea.placeholder = "❌ Ошибка запроса к DaData";
                showNotification("❌ Ошибка при запросе адреса", "error");
            });
        }
        
        function showNotification(message, type) {
            const notification = document.createElement("div");
            notification.className = type === "success" ? "save-success" : "error";
            notification.style.cssText = "position: fixed; top: 20px; right: 20px; z-index: 1000; min-width: 250px;";
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 3000);
        }
        
        function exportToCSV() {
            const rows = [];
            const headers = [
                "№ п/п", "Вид тары", "Кол-во", "Способ упаковки",
                "Код ФККО", "Наименование отхода", "Длина", "Ширина", 
                "Высота", "Брутто(т)", "Нетто(т)", "Объем(м³)"
            ];
            rows.push(headers);
            
            const dataRows = document.querySelectorAll("#dataTable tbody tr");
            dataRows.forEach(row => {
                const inputs = row.querySelectorAll("input");
                const textareas = row.querySelectorAll("textarea");
                const rowData = [];
                textareas.forEach(textarea => {
                    rowData.push(textarea.value);
                });
                inputs.forEach(input => {
                    rowData.push(input.value);
                });
                rows.push(rowData);
            });
            
            const metaData = [
                ["=== МЕТАДАННЫЕ ===", ""],
                ["№ Поручения", document.getElementById("meta_order_number")?.value || ""],
                ["Дата", document.getElementById("meta_order_date")?.value || ""],
                ["Договор", document.getElementById("meta_contract_number")?.value || ""],
                ["Грузоотправитель", document.getElementById("meta_shipper_name")?.value || ""],
                ["ИНН", document.getElementById("meta_shipper_inn")?.value || ""],
                ["Юридический адрес (DaData)", document.getElementById("meta_legal_address")?.value || ""],
                ["Адрес загрузки", document.getElementById("meta_loading_address")?.value || ""],
                ["", ""],
                ["=== ДАННЫЕ ===", ""],
                ...rows
            ];
            
            const csvContent = metaData.map(row => row.map(cell => `"${String(cell).replace(/"/g, \'""\')}"`).join(",")).join("\\n");
            const blob = new Blob(["\\ufeff" + csvContent], { type: "text/csv;charset=utf-8;" });
            const link = document.createElement("a");
            const url = URL.createObjectURL(blob);
            link.href = url;
            link.setAttribute("download", "parsed_data_" + new Date().toISOString().slice(0,19).replace(/:/g, "-") + ".csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }
        
        function submitForm(event) {
            event.preventDefault();
            const form = document.getElementById("dataForm");
            const formData = new FormData(form);
            
            formData.append("meta_order_number", document.getElementById("meta_order_number")?.value || "");
            formData.append("meta_order_date", document.getElementById("meta_order_date")?.value || "");
            formData.append("meta_contract_number", document.getElementById("meta_contract_number")?.value || "");
            formData.append("meta_shipper_name", document.getElementById("meta_shipper_name")?.value || "");
            formData.append("meta_shipper_inn", document.getElementById("meta_shipper_inn")?.value || "");
            formData.append("meta_legal_address", document.getElementById("meta_legal_address")?.value || "");
            formData.append("meta_loading_address", document.getElementById("meta_loading_address")?.value || "");
            
            fetch(window.location.href, {
                method: "POST",
                body: formData
            }).then(response => response.text())
              .then(html => {
                  const parser = new DOMParser();
                  const doc = parser.parseFromString(html, "text/html");
                  const newContent = doc.querySelector(".results-container");
                  if (newContent) {
                      document.querySelector(".results-container").innerHTML = newContent.innerHTML;
                  }
                  setTimeout(() => location.reload(), 1000);
              });
            return false;
        }
    </script>';
}
?>