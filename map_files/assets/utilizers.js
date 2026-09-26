var utilizersCollection = null;
var utilizersData = [];
var utilizersVisible = true;
var UTILIZERS_UI_VERSION = 'U-2026.08.06.1';
var UTILIZER_COLOR_MAIN_DEFAULT = '#f4b183';
var UTILIZER_COLOR_DARK_DEFAULT = '#7f4125';
var UTILIZER_COLOR_TEXT_DEFAULT = '#7f4125';

function utilizerEscape(value) {
    if (typeof escapeHtml === 'function') return escapeHtml(value);
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function utilizerColor(value, fallback) {
    var color = String(value || '').trim();
    return /^#[0-9a-fA-F]{6}$/.test(color) ? color : fallback;
}

function ensureUtilizerLayerControl() {
    var popupsCheckbox = document.getElementById('disable-popups');
    var popupsRow = popupsCheckbox ? popupsCheckbox.closest('.checkbox-item') : null;
    var popupsGroup = popupsRow ? popupsRow.closest('.layer-group') : null;
    if (!popupsRow || !popupsGroup || !popupsGroup.parentNode) return;

    var checkbox = document.getElementById('utilizer_layer_visible');
    var row = checkbox ? checkbox.closest('.checkbox-item') : null;
    var group = row ? row.closest('.layer-group') : null;

    if (!checkbox) {
        checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = 'utilizer_layer_visible';
        checkbox.checked = true;
        checkbox.addEventListener('change', function () {
            toggleUtilizersLayer(this.checked);
        });
    }

    if (!row) {
        row = document.createElement('div');
        row.className = 'checkbox-item';

        var label = document.createElement('label');
        label.htmlFor = 'utilizer_layer_visible';
        label.textContent = 'Утилизаторы';

        row.appendChild(checkbox);
        row.appendChild(label);
    }

    var version = row.querySelector('.utilizer-ui-version');
    if (!version) {
        version = document.createElement('span');
        version.className = 'utilizer-ui-version';
        version.style.marginLeft = 'auto';
        version.style.fontSize = '10px';
        version.style.fontWeight = '600';
        version.style.opacity = '0.75';
        version.style.whiteSpace = 'nowrap';
        row.appendChild(version);
    }
    version.textContent = 'Версия: ' + UTILIZERS_UI_VERSION;

    row.style.display = 'flex';
    row.style.alignItems = 'center';
    row.style.gap = '6px';
    row.style.background = '#fff3cd';
    row.style.border = '1px solid #ffc107';

    if (!group || group === popupsGroup) {
        group = document.createElement('div');
        group.className = 'layer-group';
        group.appendChild(row);
    } else {
        group.innerHTML = '';
        group.appendChild(row);
    }

    group.style.borderBottom = 'none';
    group.style.marginBottom = '0';
    group.style.paddingBottom = '0';
    group.style.marginTop = '6px';

    popupsGroup.insertAdjacentElement('afterend', group);
}

function createUtilizerMarkerSvgDataUri(label, colorMain, colorDark, colorText) {
    var safeLabel = utilizerEscape(String(label || 'УТИЛ').trim());
    var main = utilizerColor(colorMain, UTILIZER_COLOR_MAIN_DEFAULT);
    var dark = utilizerColor(colorDark, UTILIZER_COLOR_DARK_DEFAULT);
    var text = utilizerColor(colorText, dark || UTILIZER_COLOR_TEXT_DEFAULT);
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="74" height="30" viewBox="0 0 74 30">' +
        '<path d="M4 1 H70 Q73 1 73 4 V20 Q73 23 70 23 H44 L37 29 L30 23 H4 Q1 23 1 20 V4 Q1 1 4 1 Z" fill="' + main + '" stroke="' + dark + '" stroke-width="2"/>' +
        '<text x="37" y="14" dominant-baseline="middle" text-anchor="middle" font-family="Arial,sans-serif" font-size="9" font-weight="700" fill="' + text + '">' + safeLabel + '</text></svg>';
    return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

function buildUtilizerBalloon(utilizer) {
    var fullName = String(utilizer.full_name || utilizer.short_name || 'Утилизатор').trim();
    var shortName = String(utilizer.short_name || '').trim();
    var address = String(utilizer.address || '').trim();
    var fkkoType = String(utilizer.fkko_type || '').trim();
    var dangerous = String(utilizer.dangerous_cargo || '').trim();
    var dark = utilizerColor(utilizer.color_dark, UTILIZER_COLOR_DARK_DEFAULT);
    var lat = Number(utilizer.latitude), lon = Number(utilizer.longitude);
    var html = '<div style="padding:10px;min-width:340px;max-width:620px;font-size:13px;line-height:1.5">';
    html += '<div style="font-size:18px;font-weight:700;margin-bottom:5px;color:' + dark + '">' + utilizerEscape(fullName) + '</div>';
    if (shortName) html += '<div><strong>Сокращённое наименование:</strong> ' + utilizerEscape(shortName) + '</div>';
    html += '<div><strong>Адрес:</strong> ' + utilizerEscape(address || 'Не указан') + '</div>';
    html += '<div><strong>Вид ФККО:</strong> ' + utilizerEscape(fkkoType || 'Не указан') + '</div>';
    html += '<div><strong>Опасный груз:</strong> ' + utilizerEscape(dangerous || 'Не указан') + '</div>';
    html += '<div><strong>Координаты:</strong> ' + lat.toFixed(6) + ', ' + lon.toFixed(6) + '</div></div>';
    return html;
}

function renderUtilizersLayer() {
    if (!utilizersCollection || typeof map === 'undefined' || !map) return;
    utilizersCollection.removeAll();
    if (!utilizersVisible) return;
    (Array.isArray(utilizersData) ? utilizersData : []).forEach(function (utilizer) {
        var lat = Number(utilizer.latitude), lon = Number(utilizer.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
        var label = String(utilizer.short_name || utilizer.full_name || 'УТИЛ').trim();
        var placemark = new ymaps.Placemark([lat, lon], {
            hintContent: String(utilizer.full_name || label),
            balloonContent: buildUtilizerBalloon(utilizer)
        }, {
            iconLayout: 'default#image',
            iconImageHref: createUtilizerMarkerSvgDataUri(label, utilizer.color_main, utilizer.color_dark, utilizer.color_text),
            iconImageSize: [74, 30],
            iconImageOffset: [-37, -30],
            openBalloonOnClick: true,
            openHintOnHover: true,
            zIndex: 470
        });
        utilizersCollection.add(placemark);
    });
}

function toggleUtilizersLayer(visible) {
    utilizersVisible = !!visible;
    renderUtilizersLayer();
}

async function loadUtilizers() {
    try {
        var response = await fetch('map_files/get_utilizers.php', {cache: 'no-store'});
        var data = await response.json();
        utilizersData = response.ok && data && data.success && Array.isArray(data.utilizers) ? data.utilizers : [];
        renderUtilizersLayer();
        return utilizersData.length > 0;
    } catch (error) {
        console.error('loadUtilizers error:', error);
        utilizersData = [];
        renderUtilizersLayer();
        return false;
    }
}

function initUtilizersLayer(attempt) {
    var tryNo = Number(attempt || 0);
    if (typeof map === 'undefined' || !map || typeof ymaps === 'undefined') {
        if (tryNo < 50) setTimeout(function () { initUtilizersLayer(tryNo + 1); }, 100);
        return;
    }
    ensureUtilizerLayerControl();
    if (!utilizersCollection) {
        utilizersCollection = new ymaps.GeoObjectCollection();
        map.geoObjects.add(utilizersCollection);
    }
    var checkbox = document.getElementById('utilizer_layer_visible');
    utilizersVisible = checkbox ? !!checkbox.checked : true;
    loadUtilizers();
}

if (typeof ymaps !== 'undefined' && ymaps.ready) {
    ymaps.ready(function () { initUtilizersLayer(0); });
} else {
    window.addEventListener('load', function () { initUtilizersLayer(0); });
}
