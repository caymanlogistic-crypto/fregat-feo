<?php
declare(strict_types=1);

// =============================================================================
// CSS СТИЛИ
// =============================================================================

$cssStyles = <<<'CSS'
* {
    box-sizing: border-box;
}

body {
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
    margin: 0;
    padding: 24px;
    background: #f5f7fb;
    color: #1a202c;
}

.container {
    max-width: 1600px;
    margin: 0 auto;
}

.upload-box {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 20px;
    padding: 48px 32px;
    text-align: center;
    border: 2px dashed #cbd5e1;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-bottom: 32px;
}

.upload-box:hover {
    border-color: #3b82f6;
    background: #f8fafc;
    transform: translateY(-2px);
}

input[type="file"] {
    display: none;
}

.error {
    background: #fee2e2;
    color: #b91c1c;
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    border-left: 4px solid #ef4444;
    font-weight: 500;
}

.results-container {
    background: transparent;
}

.results-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
    padding: 0 4px;
}

.results-header h2 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
    color: #1e293b;
}

.header-actions {
    display: flex;
    gap: 12px;
}

.card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    margin-bottom: 24px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

.meta-editor h3 {
    margin: 0;
    padding: 16px 20px;
    font-size: 1rem;
    font-weight: 600;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    color: #0f172a;
}

.meta-form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    padding: 20px;
}

.meta-field-full {
    grid-column: span 2;
}

.meta-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.meta-field label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
}

.meta-input {
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-size: 14px;
    transition: all 0.2s;
    background: #fff;
    width: 100%;
}

.meta-textarea {
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-size: 14px;
    transition: all 0.2s;
    background: #fff;
    width: 100%;
    resize: vertical;
    font-family: inherit;
    line-height: 1.5;
}

.meta-textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.meta-textarea[readonly] {
    background: #f8fafc;
    color: #475569;
    cursor: default;
}

.meta-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.meta-input[readonly] {
    background: #f8fafc;
    color: #475569;
    cursor: default;
}

.table-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.row-count {
    font-size: 14px;
    font-weight: 500;
    color: #475569;
    background: #e2e8f0;
    padding: 4px 12px;
    border-radius: 20px;
}

button {
    font-family: inherit;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    font-weight: 500;
}

.btn-export-csv, .btn-save-all {
    padding: 8px 16px;
    border-radius: 10px;
    font-size: 13px;
    background: #fff;
    border: 1px solid #cbd5e1;
    color: #334155;
}

.btn-export-csv:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

.btn-save-all {
    background: #3b82f6;
    border-color: #3b82f6;
    color: #fff;
}

.btn-save-all:hover {
    background: #2563eb;
}

.table-scroll-wrapper {
    overflow-x: auto;
    max-height: 600px;
    overflow-y: auto;
}

.editable-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 1400px;
}

.editable-table th {
    background: #f1f5f9;
    padding: 12px 8px;
    text-align: left;
    font-weight: 600;
    color: #1e293b;
    border-bottom: 2px solid #e2e8f0;
    position: sticky;
    top: 0;
    z-index: 10;
    font-size: 12px;
}

.editable-table td {
    padding: 8px;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: top;
}

.editable-table tr:hover td {
    background: #fefce8;
}

.cell-input {
    width: 100%;
    padding: 8px 6px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 12px;
    transition: all 0.2s;
    background: #fff;
}

.cell-textarea {
    width: 100%;
    padding: 8px 6px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 12px;
    transition: all 0.2s;
    background: #fff;
    resize: vertical;
    font-family: inherit;
    line-height: 1.4;
    min-height: 60px;
}

.cell-input:focus, .cell-textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59,130,246,0.1);
}

.waste-name-input {
    min-width: 250px;
}

.row-number {
    text-align: center;
    font-weight: 500;
    color: #64748b;
    background: #f8fafc;
    vertical-align: middle;
}

.save-success {
    background: #d1fae5;
    color: #065f46;
    padding: 14px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    border-left: 4px solid #10b981;
    font-weight: 500;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (max-width: 768px) {
    body {
        padding: 16px;
    }
    .meta-form-grid {
        grid-template-columns: 1fr;
    }
    .meta-field-full {
        grid-column: span 1;
    }
    .header-actions {
        flex-direction: column;
        width: 100%;
    }
}
CSS;
?>