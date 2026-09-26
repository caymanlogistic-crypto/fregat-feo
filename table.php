<?php
session_start();
require_once 'config.php';

// ===== КЛЮЧЕВЫЕ ПЕРЕМЕННЫЕ =====
//$table = 'drivers'; // таблица, с которой работаем
// =================================

try {
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// Функция форматирования даты в европейский формат
function formatDate($value, $fieldType) {
    if (empty($value)) return '';
    if (strpos($fieldType, 'date') !== false || strpos($fieldType, 'datetime') !== false) {
        try {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('d.m.Y', $timestamp);
            }
        } catch (Exception $e) {
            return $value;
        }
    }
    return $value;
}

// Получаем структуру таблицы
function getTableColumns($pdo, $table) {
    $stmt = $pdo->prepare("DESCRIBE $table");
    $stmt->execute();
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row;
    }
    return $columns;
}

// Получаем комментарии к полям
function getColumnComments($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_COMMENT, DATA_TYPE 
                           FROM INFORMATION_SCHEMA.COLUMNS 
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    $comments = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $comments[$row['COLUMN_NAME']] = [
            'comment' => $row['COLUMN_COMMENT'],
            'data_type' => $row['DATA_TYPE']
        ];
    }
    return $comments;
}

$columns = getTableColumns($pdo, $table);
$columnComments = getColumnComments($pdo, $table);

// Служебные поля
$systemFields = ['id', 'created_at', 'updated_at'];

// AJAX-запрос для получения данных с пагинацией
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_data') {
    header('Content-Type: application/json');
    
    try {
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit = isset($_GET['limit']) ? min(100, max(10, (int)$_GET['limit'])) : 50;
        $filter = isset($_GET['filter']) ? trim($_GET['filter']) : '';
        
        $where = "";
        $params = [];
        
        if (!empty($filter)) {
            // Ищем по всем неслужебным полям
            $searchFields = [];
            foreach ($columns as $col) {
                $field = $col['Field'];
                if (!in_array($field, $systemFields)) {
                    $searchFields[] = "$field LIKE ?";
                    $params[] = "%$filter%";
                }
            }
            if (!empty($searchFields)) {
                $where = "WHERE (" . implode(" OR ", $searchFields) . ")";
            }
        }
        
        // Общее количество
        $countSql = "SELECT COUNT(*) as total FROM $table $where";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Данные с пагинацией
        $dataSql = "SELECT * FROM $table $where ORDER BY id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        $stmt = $pdo->prepare($dataSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Формируем HTML
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr data-id="' . htmlspecialchars($row['id']) . '">';
            foreach ($columns as $col) {
                $field = $col['Field'];
                if (in_array($field, $systemFields)) continue;
                
                $value = $row[$field] ?? '';
                $dataType = $columnComments[$field]['data_type'] ?? $col['Type'];
                
                // Форматируем дату в европейский формат
                if (strpos($dataType, 'date') !== false && !empty($value)) {
                    $value = formatDate($value, $dataType);
                }
                
                $displayValue = $value !== '' && $value !== null ? htmlspecialchars($value) : '<span style="color:#999;">—</span>';
                $html .= '<td>' . $displayValue . '';
            }
            $html .= '<td style="width: 100px; text-align: center; white-space: nowrap;">
                <button class="btn btn-sm" onclick="editRow(' . htmlspecialchars($row['id']) . ')">✎</button>
                <button class="btn btn-sm btn-delete" onclick="deleteRow(' . htmlspecialchars($row['id']) . ')">✖</button>
            </div>';
            $html .= '</div>';
        }
        
        echo json_encode([
            'success' => true,
            'html' => $html,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => ($offset + $limit) < $total
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Получение одной записи для редактирования
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_row'])) {
    header('Content-Type: application/json');
    
    try {
        $row_id = (int)$_GET['row_id'];
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$row_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            throw new Exception('Запись не найдена');
        }
        
        // Для редактирования возвращаем даты в формате YYYY-MM-DD для input type="date"
        foreach ($columns as $col) {
            $field = $col['Field'];
            $dataType = $columnComments[$field]['data_type'] ?? $col['Type'];
            if (strpos($dataType, 'date') !== false && !empty($row[$field])) {
                $timestamp = strtotime($row[$field]);
                if ($timestamp !== false) {
                    $row[$field] = date('Y-m-d', $timestamp);
                }
            }
        }
        
        echo json_encode(['success' => true, 'row' => $row]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Добавление записи
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_row'])) {
    header('Content-Type: application/json');
    
    try {
        $fields = [];
        $placeholders = [];
        $params = [];
        
        foreach ($columns as $col) {
            $field = $col['Field'];
            if (in_array($field, $systemFields)) continue;
            
            if (isset($_POST[$field])) {
                $value = $_POST[$field];
                if ($value === '') {
                    $value = null;
                }
                $fields[] = $field;
                $placeholders[] = "?";
                $params[] = $value;
            }
        }
        
        if (empty($fields)) {
            throw new Exception('Нет данных для добавления');
        }
        
        $sql = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'message' => 'Запись успешно добавлена', 'id' => $pdo->lastInsertId()]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Обновление записи
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_row'])) {
    header('Content-Type: application/json');
    
    try {
        $row_id = (int)$_POST['row_id'];
        $updateFields = [];
        $params = [];
        
        foreach ($columns as $col) {
            $field = $col['Field'];
            if (in_array($field, $systemFields)) continue;
            
            if (isset($_POST[$field])) {
                $value = $_POST[$field];
                if ($value === '') {
                    $value = null;
                }
                $updateFields[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (empty($updateFields)) {
            throw new Exception('Нет данных для обновления');
        }
        
        $params[] = $row_id;
        $sql = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'message' => 'Запись успешно обновлена']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Удаление записи
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_row'])) {
    header('Content-Type: application/json');
    
    try {
        $row_id = (int)$_POST['row_id'];
        $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
        $stmt->execute([$row_id]);
        
        echo json_encode(['success' => true, 'message' => 'Запись успешно удалена']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Получаем заголовки для таблицы (только неслужебные поля)
$headers = [];
foreach ($columns as $col) {
    $field = $col['Field'];
    if (in_array($field, $systemFields)) continue;
    
    $comment = isset($columnComments[$field]['comment']) && !empty($columnComments[$field]['comment']) 
        ? $columnComments[$field]['comment'] 
        : $field;
    $headers[] = ['field' => $field, 'label' => mb_strtoupper($comment)];
}

// Формируем поля для формы добавления/редактирования
$formFields = [];
foreach ($columns as $col) {
    $field = $col['Field'];
    if (in_array($field, $systemFields)) continue;
    
    $comment = isset($columnComments[$field]['comment']) && !empty($columnComments[$field]['comment']) 
        ? $columnComments[$field]['comment'] 
        : $field;
    $dataType = $columnComments[$field]['data_type'] ?? $col['Type'];
    
    $formFields[] = [
        'name' => $field,
        'label' => $comment,
        'type' => (strpos($dataType, 'date') !== false) ? 'date' : 'text'
    ];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление данными: <?= htmlspecialchars($table) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'menuadmin.php'; ?>
    <div class="app">
        <div class="toolbar">
            <h1><?=$title?></h1>
            <button class="btn btn-primary" id="addRecordBtn">+ Добавить запись</button>
        </div>
        
        <div class="filter-container">
            <div class="filter-input-group">
                <label for="filterInput" class="filter-label">Поиск:</label>
                <input 
                    type="text" 
                    id="filterInput" 
                    placeholder="Поиск по всем полям..." 
                    class="filter-input"
                    value=""
                >
                <button id="clearFilterBtn" class="btn">Очистить</button>
            </div>
            <div id="filterInfo" class="filter-stats">
                <span id="filterStats"></span>
            </div>
        </div>
        
        <div class="table-wrapper" id="tableWrapper">
            <table class="data-table" id="dataTable">
                <thead>
                    <tr>
                        <?php foreach ($headers as $header): ?>
                        <th><?= htmlspecialchars($header['label']) ?></th>
                        <?php endforeach; ?>
                        <th style="width: 100px; text-align: center;">ДЕЙСТВИЯ</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                </tbody>
            </table>
            <div id="loadingIndicator" class="loading-indicator" style="display: none;">
                Загрузка...
            </div>
            <div id="noMoreData" class="no-more-data" style="display: none;">
                Все записи загружены
            </div>
        </div>
    </div>
    
    <!-- Модальное окно добавления/редактирования -->
    <div id="editModal" class="modal">
        <div class="modal-content modal-content-wide">
            <div class="modal-header">
                <h3 id="modalTitle">Добавление записи</h3>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <div id="editModalContent"></div>
        </div>
    </div>
    
<script>
const tableBody = document.getElementById('tableBody');
const tableWrapper = document.querySelector('.table-wrapper');
const filterInput = document.getElementById('filterInput');
const clearFilterBtn = document.getElementById('clearFilterBtn');
const filterStats = document.getElementById('filterStats');
const loadingIndicator = document.getElementById('loadingIndicator');
const noMoreData = document.getElementById('noMoreData');
const addRecordBtn = document.getElementById('addRecordBtn');

let currentOffset = 0;
let currentLimit = 50;
let currentFilter = '';
let currentTotal = 0;
let isLoading = false;
let hasMore = true;
let loadTimeout = null;
let scrollTimeout = null;
let editMode = 'add';
let editRowId = null;

function loadData(reset = true) {
    if (isLoading) return;
    if (reset) {
        currentOffset = 0;
        hasMore = true;
        tableBody.innerHTML = '';
        noMoreData.style.display = 'none';
    }
    if (!hasMore) return;
    
    isLoading = true;
    loadingIndicator.style.display = 'block';
    
    const filterValue = filterInput.value.trim();
    
    fetch(`?ajax=get_data&offset=${currentOffset}&limit=${currentLimit}&filter=${encodeURIComponent(filterValue)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (reset) {
                    tableBody.innerHTML = data.html;
                } else {
                    tableBody.insertAdjacentHTML('beforeend', data.html);
                }
                
                currentTotal = data.total;
                currentFilter = filterValue;
                hasMore = data.has_more;
                
                const rowsCount = tableBody.querySelectorAll('tr').length;
                currentOffset = rowsCount;
                
                if (!hasMore && currentOffset > 0) {
                    noMoreData.style.display = 'block';
                } else {
                    noMoreData.style.display = 'none';
                }
                
                if (filterValue) {
                    filterStats.innerHTML = '🔍 Найдено записей: ' + data.total;
                } else {
                    filterStats.innerHTML = '📋 Всего записей: ' + data.total;
                }
            } else {
                tableBody.innerHTML = '<tr><td colspan="100%" style="text-align: center; padding: 40px; color: #dc3545;">Ошибка: ' + data.message + '</td></tr>';
            }
        })
        .catch(error => {
            console.error('Ошибка загрузки:', error);
            tableBody.innerHTML = '<tr><td colspan="100%" style="text-align: center; padding: 40px; color: #dc3545;">Ошибка загрузки данных</td></tr>';
        })
        .finally(() => {
            isLoading = false;
            loadingIndicator.style.display = 'none';
        });
}

function handleScroll() {
    if (!tableWrapper) return;
    
    if (scrollTimeout) clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(() => {
        const scrollTop = tableWrapper.scrollTop;
        const scrollHeight = tableWrapper.scrollHeight;
        const clientHeight = tableWrapper.clientHeight;
        
        if (scrollTop + clientHeight >= scrollHeight - 100) {
            if (!isLoading && hasMore) {
                loadData(false);
            }
        }
    }, 100);
}

function handleFilterInput() {
    if (loadTimeout) clearTimeout(loadTimeout);
    loadTimeout = setTimeout(() => {
        loadData(true);
        if (tableWrapper) {
            tableWrapper.scrollTop = 0;
        }
    }, 500);
}

function clearFilter() {
    filterInput.value = '';
    loadData(true);
    if (tableWrapper) {
        tableWrapper.scrollTop = 0;
    }
}

if (filterInput) {
    filterInput.addEventListener('input', handleFilterInput);
}
if (clearFilterBtn) {
    clearFilterBtn.addEventListener('click', clearFilter);
}
if (tableWrapper) {
    tableWrapper.addEventListener('scroll', handleScroll);
    const observer = new MutationObserver(function() {
        setTimeout(() => {
            if (tableWrapper.scrollHeight <= tableWrapper.clientHeight && hasMore && !isLoading) {
                loadData(false);
            }
        }, 100);
    });
    observer.observe(tableBody, { childList: true, subtree: true });
}

if (addRecordBtn) {
    addRecordBtn.addEventListener('click', function() {
        openAddModal();
    });
}

function openAddModal() {
    editMode = 'add';
    editRowId = null;
    document.getElementById('modalTitle').innerText = '➕ Добавление записи';
    showEditForm(null);
    document.getElementById('editModal').classList.add('show');
}

function editRow(rowId) {
    editMode = 'edit';
    editRowId = rowId;
    document.getElementById('modalTitle').innerText = '✏️ Редактирование записи';
    
    const modal = document.getElementById('editModal');
    const content = document.getElementById('editModalContent');
    
    content.innerHTML = '<div style="text-align: center; padding: 20px;">Загрузка данных...</div>';
    modal.classList.add('show');
    
    fetch('?get_row=1&row_id=' + rowId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showEditForm(data.row);
            } else {
                content.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Ошибка: ' + data.message + '</div>';
            }
        })
        .catch(error => {
            console.error('Ошибка:', error);
            content.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Ошибка загрузки данных</div>';
        });
}

function showEditForm(row) {
    const content = document.getElementById('editModalContent');
    
    const fields = <?= json_encode($formFields) ?>;
    
    let html = '<form id="editForm" onsubmit="return submitEdit(event)">';
    
    if (editMode === 'edit') {
        html += '<input type="hidden" name="update_row" value="1">';
        html += '<input type="hidden" name="row_id" value="' + editRowId + '">';
    } else {
        html += '<input type="hidden" name="add_row" value="1">';
    }
    
    html += '<div class="edit-form-grid">';
    
    for (let i = 0; i < fields.length; i++) {
        const field = fields[i];
        const fieldValue = row && row[field.name] !== null && row[field.name] !== undefined ? row[field.name] : '';
        
        html += '<div class="edit-form-label">' + escapeHtml(field.label) + ':</div>';
        html += '<div class="edit-form-field">';
        
        if (field.type === 'date') {
            let dateValue = '';
            if (fieldValue) {
                if (fieldValue.includes(' ')) {
                    dateValue = fieldValue.split(' ')[0];
                } else {
                    dateValue = fieldValue;
                }
            }
            html += '<input type="date" name="' + field.name + '" value="' + escapeHtml(dateValue) + '">';
        } else {
            html += '<input type="text" name="' + field.name + '" value="' + escapeHtml(fieldValue) + '">';
        }
        
        html += '</div>';
    }
    
    html += '</div>' +
        '<div class="modal-actions">' +
        '<button type="submit" class="btn btn-primary">Сохранить</button>' +
        '<button type="button" class="btn btn-secondary" onclick="closeEditModal()">Отмена</button>' +
        '</div>' +
        '</form>';
    
    content.innerHTML = html;
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function submitEdit(event) {
    event.preventDefault();
    
    const form = document.getElementById('editForm');
    const formData = new FormData(form);
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Сохранение...';
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeEditModal();
            loadData(true);
        } else {
            alert('Ошибка: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    })
    .catch(error => {
        alert('Ошибка при сохранении');
        console.error(error);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    });
    
    return false;
}

function deleteRow(rowId) {
    if (!confirm('Вы уверены, что хотите удалить эту запись?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('delete_row', '1');
    formData.append('row_id', rowId);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            loadData(true);
        } else {
            alert('Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        alert('Ошибка при удалении');
        console.error(error);
    });
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
}

window.onclick = function(event) {
    const editModalEl = document.getElementById('editModal');
    if (event.target === editModalEl) {
        closeEditModal();
    }
}

loadData(true);
</script>
</body>
</html>