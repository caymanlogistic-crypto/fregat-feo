/* Approved request marker format: 50x40 visible body, 16px utilizer ribbon, rounded corners, selected glow, ADR badge. */
(function () {
    'use strict';

    const REQUEST_MARKER_BODY_WIDTH = 50;
    const REQUEST_MARKER_BODY_HEIGHT = 40;
    const REQUEST_MARKER_GLOW_PAD = 8;
    const REQUEST_MARKER_WIDTH = REQUEST_MARKER_BODY_WIDTH + REQUEST_MARKER_GLOW_PAD * 2;
    const REQUEST_MARKER_HEIGHT = REQUEST_MARKER_BODY_HEIGHT + REQUEST_MARKER_GLOW_PAD * 2;
    const REQUEST_MARKER_STACK_STEP = 36;
    const REQUEST_MARKER_ADR_EXTRA_GAP = 6;
    const DEFAULT_UTILIZER_MAIN = '#6c757d';
    const DEFAULT_UTILIZER_TEXT = '#ffffff';
    const DEFAULT_PRE_FLIGHT_STROKE = '#000000';
    const REQUEST_DEADLINE_STROKE = '#dc3545';
    const REQUEST_DEADLINE_GLOW = '#ff2d2d';
    const dangerousUtilizerMap = Object.create(null);
    const availableUtilizerFilters = Object.create(null);

    function validHex(value, fallback) {
        const color = String(value || '').trim();
        return /^#[0-9a-fA-F]{6}$/.test(color) ? color : fallback;
    }

    function normalizeUtilizerKey(value) {
        return String(value || '').trim().toUpperCase().replace(/Ё/g, 'Е');
    }

    function availableUtilizerKey(group) {
        if (!group) return '';
        const id = Number(group.utilizer_id || 0);
        if (Number.isFinite(id) && id > 0) return 'id:' + String(id);
        const shortName = normalizeUtilizerKey(group.utilizer_short_name);
        return shortName ? 'name:' + shortName : '';
    }

    function compactUtilizerLabel(value) {
        const raw = normalizeUtilizerKey(value);
        if (!raw) return '';
        if (raw === 'ЭКО+') return raw;
        return Array.from(raw).slice(0, 3).join('');
    }

    function escapeSvgText(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function markerBackground(weightInKg, isSelected) {
        if (isSelected) return '#f1f6ff';
        if (weightInKg > 10000) return '#fd4221';
        if (weightInKg > 5000) return '#FF7400';
        if (weightInKg > 3000) return '#FFA459';
        if (weightInKg > 1000) return '#FFCBA1';
        return '#ffffff';
    }

    function isDangerousGroup(group) {
        if (!group) return false;
        if (group.utilizer_dangerous_cargo === true || group.utilizer_dangerous_cargo === 1) return true;
        const explicit = normalizeUtilizerKey(group.utilizer_dangerous_cargo);
        if (explicit === 'ДА' || explicit === 'YES' || explicit === '1') return true;
        const key = normalizeUtilizerKey(group.utilizer_short_name);
        return !!(key && dangerousUtilizerMap[key]);
    }

    function markerStrokeColorForGroup(group) {
        if (!group || !group.in_flight) return DEFAULT_PRE_FLIGHT_STROKE;
        return validHex(group.marker_color, DEFAULT_PRE_FLIGHT_STROKE);
    }

    function deadlineStateForGroup(group) {
        if (!group || !Array.isArray(group.points)) return 0;
        return group.points.some(point => point && point.is_overdue === true) ? 2 : 0;
    }

    function buildAvailableUtilizerFilters() {
        if (typeof document === 'undefined' || !Array.isArray(groupsData)) return false;
        const panel = document.querySelector('.layer-panel');
        if (!panel) return false;

        const stats = Object.create(null);
        groupsData.forEach(group => {
            if (!group || group.in_flight) return;
            const key = availableUtilizerKey(group);
            if (!key) return;
            if (!stats[key]) {
                stats[key] = {
                    key,
                    name: String(group.utilizer_short_name || '').trim() || 'Утилизатор',
                    color: validHex(group.utilizer_color_main, DEFAULT_UTILIZER_MAIN),
                    count: 0
                };
            }
            const groupCount = Number(group.count || 0);
            stats[key].count += Number.isFinite(groupCount) && groupCount > 0
                ? groupCount
                : (Array.isArray(group.points) ? group.points.length : 0);
        });

        const items = Object.values(stats).filter(item => item.count > 0);
        items.sort((a, b) => a.name.localeCompare(b.name, 'ru'));

        const existing = document.getElementById('available-utilizer-layer-group');
        if (existing) existing.remove();
        if (!items.length) return false;

        items.forEach(item => {
            if (!(item.key in availableUtilizerFilters)) availableUtilizerFilters[item.key] = true;
        });

        const groupEl = document.createElement('div');
        groupEl.className = 'layer-group';
        groupEl.id = 'available-utilizer-layer-group';

        const title = document.createElement('div');
        title.className = 'layer-group-title';
        title.textContent = 'Утилизаторы';
        groupEl.appendChild(title);

        items.forEach((item, index) => {
            const row = document.createElement('div');
            row.className = 'checkbox-item';

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.id = 'available_utilizer_' + index;
            input.checked = availableUtilizerFilters[item.key] !== false;
            input.addEventListener('change', function () {
                availableUtilizerFilters[item.key] = !!this.checked;
                if (typeof updateMap === 'function') updateMap();
            });

            const label = document.createElement('label');
            label.htmlFor = input.id;
            label.textContent = item.name + ' (' + String(item.count) + ')';

            const indicator = document.createElement('div');
            indicator.className = 'color-indicator';
            indicator.style.background = item.color;

            row.appendChild(input);
            row.appendChild(label);
            row.appendChild(indicator);
            groupEl.appendChild(row);
        });

        const groups = Array.from(panel.querySelectorAll(':scope > .layer-group'));
        const extraGroup = groups.find(el => {
            const heading = el.querySelector('.layer-group-title');
            return heading && normalizeUtilizerKey(heading.textContent) === 'ДОПОЛНИТЕЛЬНО';
        });
        const routeGroup = groups.find(el => {
            const heading = el.querySelector('.layer-group-title');
            return heading && normalizeUtilizerKey(heading.textContent) === 'РЕЙСЫ';
        });

        if (extraGroup) {
            panel.insertBefore(groupEl, extraGroup);
        } else if (routeGroup && routeGroup.nextSibling) {
            panel.insertBefore(groupEl, routeGroup.nextSibling);
        } else if (routeGroup) {
            panel.appendChild(groupEl);
        } else {
            panel.appendChild(groupEl);
        }
        return true;
    }

    async function loadDangerousUtilizers() {
        try {
            const response = await fetch('map_files/get_utilizers.php', { cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data || !data.success || !Array.isArray(data.utilizers)) return false;
            Object.keys(dangerousUtilizerMap).forEach(key => delete dangerousUtilizerMap[key]);
            data.utilizers.forEach(utilizer => {
                if (!utilizer) return;
                const key = normalizeUtilizerKey(utilizer.short_name);
                if (!key) return;
                dangerousUtilizerMap[key] = normalizeUtilizerKey(utilizer.dangerous_cargo) === 'ДА';
            });
            if (typeof updateMap === 'function') updateMap();
            return true;
        } catch (error) {
            return false;
        }
    }

    function buildRequestMarkerSvg(totalMass, markerColor, flightId, hideTriangle, isSelected, utilizerShortName, utilizerMain, utilizerText, isDangerous, deadlineState) {
        const weightInKg = Math.round(Number(totalMass || 0) * 1000);
        const statusColor = validHex(markerColor, DEFAULT_PRE_FLIGHT_STROKE);
        const normalizedDeadlineState = Number(deadlineState || 0);
        const deadlineWarning = normalizedDeadlineState >= 1;
        const deadlineOverdue = normalizedDeadlineState >= 2;
        const strokeColor = isSelected ? '#1f73ff' : (deadlineWarning ? REQUEST_DEADLINE_STROKE : statusColor);
        const dividerColor = isSelected ? '#1f73ff' : statusColor;
        const glowColor = isSelected ? '#1976ff' : (deadlineOverdue ? REQUEST_DEADLINE_GLOW : '');
        const fillColor = markerBackground(weightInKg, isSelected);
        const weightTextColor = isSelected ? '#174ea6' : '#000000';
        const strokeWidth = isSelected ? 2.6 : 2;
        const ribbonColor = validHex(utilizerMain, DEFAULT_UTILIZER_MAIN);
        const ribbonTextColor = validHex(utilizerText, DEFAULT_UTILIZER_TEXT);
        const utilizerLabel = escapeSvgText(compactUtilizerLabel(utilizerShortName));
        const flightLabel = flightId ? escapeSvgText('#' + String(Number(flightId))) : '';
        const clipId = 'request-marker-v2-clip';
        const glowId = 'request-marker-v2-glow';
        const p = REQUEST_MARKER_GLOW_PAD;

        const bodyPath = hideTriangle
            ? 'M2 5.5 Q2 1 6.5 1 H43.5 Q48 1 48 5.5 V29 Q48 33 43.5 33 H6.5 Q2 33 2 29 Z'
            : 'M2 5.5 Q2 1 6.5 1 H43.5 Q48 1 48 5.5 V29 Q48 33 43.5 33 H29 L25 39 L21 33 H6.5 Q2 33 2 29 Z';

        let svg = `<svg width=\"${REQUEST_MARKER_WIDTH}\" height=\"${REQUEST_MARKER_HEIGHT}\" viewBox=\"0 0 ${REQUEST_MARKER_WIDTH} ${REQUEST_MARKER_HEIGHT}\" xmlns=\"http://www.w3.org/2000/svg\">`;
        svg += `<defs><clipPath id=\"${clipId}\"><path d=\"${bodyPath}\"/></clipPath>`;
        if (glowColor) {
            svg += `<filter id=\"${glowId}\" x=\"-70%\" y=\"-70%\" width=\"240%\" height=\"240%\"><feGaussianBlur stdDeviation=\"3.4\" result=\"blur\"/><feFlood flood-color=\"${glowColor}\" flood-opacity=\"1\"/><feComposite in2=\"blur\" operator=\"in\"/><feMerge><feMergeNode/><feMergeNode in=\"SourceGraphic\"/></feMerge></filter>`;
        }
        svg += `</defs><g transform=\"translate(${p} ${p})\">`;
        if (glowColor) {
            svg += `<path d=\"${bodyPath}\" fill=\"none\" stroke=\"${glowColor}\" stroke-width=\"8.5\" stroke-linejoin=\"round\" opacity=\"0.7\" filter=\"url(#${glowId})\"/>`;
        }
        svg += `<path d=\"${bodyPath}\" fill=\"${fillColor}\" stroke=\"none\"/>`;
        svg += `<g clip-path=\"url(#${clipId})\"><rect x=\"2\" y=\"1\" width=\"46\" height=\"15\" fill=\"${ribbonColor}\"/></g>`;
        svg += `<path d=\"${bodyPath}\" fill=\"none\" stroke=\"${strokeColor}\" stroke-width=\"${strokeWidth}\" stroke-linejoin=\"round\"/>`;
        svg += `<line x1=\"2\" y1=\"16\" x2=\"48\" y2=\"16\" stroke=\"${dividerColor}\" stroke-width=\"${strokeWidth}\"/>`;
        if (utilizerLabel) {
            svg += `<text x=\"5.3\" y=\"11.4\" font-family=\"Arial,sans-serif\" font-size=\"8\" font-weight=\"700\" fill=\"${ribbonTextColor}\" text-anchor=\"start\">${utilizerLabel}</text>`;
        }
        if (flightLabel) {
            svg += `<text x=\"44.8\" y=\"11.4\" font-family=\"Arial,sans-serif\" font-size=\"8\" font-weight=\"700\" fill=\"${ribbonTextColor}\" text-anchor=\"end\">${flightLabel}</text>`;
        }
        svg += `<text x=\"25\" y=\"28.6\" font-family=\"Arial,sans-serif\" font-size=\"10.5\" font-weight=\"700\" fill=\"${weightTextColor}\" text-anchor=\"middle\">${weightInKg}</text>`;
        if (isDangerous) {
            svg += '<g><rect x=\"34.5\" y=\"-5\" width=\"14.5\" height=\"8\" rx=\"1.6\" fill=\"#FFC107\" stroke=\"#111111\" stroke-width=\"1\"/>';
            svg += '<text x=\"41.75\" y=\"0.45\" text-anchor=\"middle\" font-family=\"Arial,sans-serif\" font-size=\"5\" font-weight=\"700\" fill=\"#111111\">ADR</text></g>';
        }
        svg += '</g></svg>';
        return svg;
    }

    function requestMarkerDataUri(group, hideTriangle, isSelected) {
        if (!group) return '';
        const dangerous = isDangerousGroup(group);
        const effectiveMarkerColor = markerStrokeColorForGroup(group);
        const deadlineState = deadlineStateForGroup(group);
        const cacheKey = [
            'v2-deadline-outline-glow', group.total_mass, effectiveMarkerColor, group.flight_id || '', hideTriangle ? 1 : 0, isSelected ? 1 : 0, dangerous ? 1 : 0, deadlineState,
            group.utilizer_short_name || '', group.utilizer_color_main || '', group.utilizer_color_text || ''
        ].join('|');
        if (typeof svgCache !== 'undefined' && svgCache[cacheKey]) return svgCache[cacheKey];
        const svg = buildRequestMarkerSvg(
            group.total_mass,
            effectiveMarkerColor,
            group.flight_id,
            hideTriangle,
            isSelected,
            group.utilizer_short_name,
            group.utilizer_color_main,
            group.utilizer_color_text,
            dangerous,
            deadlineState
        );
        const uri = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
        if (typeof svgCache !== 'undefined') svgCache[cacheKey] = uri;
        return uri;
    }

    window.REQUEST_MARKERS_V2 = {
        width: REQUEST_MARKER_WIDTH,
        height: REQUEST_MARKER_HEIGHT,
        bodyWidth: REQUEST_MARKER_BODY_WIDTH,
        bodyHeight: REQUEST_MARKER_BODY_HEIGHT,
        glowPad: REQUEST_MARKER_GLOW_PAD,
        stackStep: REQUEST_MARKER_STACK_STEP,
        adrExtraGap: REQUEST_MARKER_ADR_EXTRA_GAP,
        preFlightStroke: DEFAULT_PRE_FLIGHT_STROKE,
        buildSvg: buildRequestMarkerSvg,
        dataUri: requestMarkerDataUri,
        isDangerousGroup: isDangerousGroup,
        markerStrokeColorForGroup: markerStrokeColorForGroup,
        availableUtilizerFilters: availableUtilizerFilters,
        buildAvailableUtilizerFilters: buildAvailableUtilizerFilters
    };

    function markerOffsetY(shiftY) {
        return -(REQUEST_MARKER_GLOW_PAD + 39) - shiftY;
    }

    refreshMarkerStyles = function () {
        placemarks.forEach(pm => {
            if (!pm.options.get('visible')) return;
            const g = pm.properties.get('groupData');
            const hideTriangle = !!pm.properties.get('hideTriangle');
            const isSelected = g.points.some(p => selectedOrder.includes(String(p.zayavka_id)));
            pm.options.set({
                iconImageHref: requestMarkerDataUri(g, hideTriangle, isSelected),
                iconImageSize: [REQUEST_MARKER_WIDTH, REQUEST_MARKER_HEIGHT]
            });
        });
    };

    updateMap = function () {
        const visibleByCoord = {};
        placemarks.forEach((pm, idx) => {
            const g = pm.properties.get('groupData');
            let visible = true;
            if (g.in_flight) {
                const filterVal = flightStatusFilters[g.flight_status];
                if (filterVal === false) visible = false;
            } else {
                const defaultLayerVisible = customLayerFilters.default !== false;
                if (!defaultLayerVisible) {
                    visible = false;
                } else {
                    const layer = g.custom_layer || 'default';
                    const layerVisible = customLayerFilters[layer];
                    if (layerVisible === false) visible = false;
                }
                if (visible) {
                    const utilizerKey = availableUtilizerKey(g);
                    if (utilizerKey && availableUtilizerFilters[utilizerKey] === false) visible = false;
                }
            }
            if (!visible) {
                pm.options.set('visible', false);
                return;
            }
            const key = `${g.mno_sh}|${g.mno_d}`;
            if (!visibleByCoord[key]) visibleByCoord[key] = [];
            visibleByCoord[key].push({ pm, idx });
        });

        for (const key in visibleByCoord) {
            const items = visibleByCoord[key];
            items.sort((a, b) => (groupsData[a.idx].base_index || 0) - (groupsData[b.idx].base_index || 0));
            let shiftY = 0;
            items.forEach((item, pos) => {
                const pm = item.pm;
                const g = groupsData[item.idx];
                const isFirst = pos === 0;
                if (pos > 0) {
                    const lowerGroup = groupsData[items[pos - 1].idx];
                    shiftY += REQUEST_MARKER_STACK_STEP + (isDangerousGroup(lowerGroup) ? REQUEST_MARKER_ADR_EXTRA_GAP : 0);
                }
                const isSelected = g.points.some(p => selectedOrder.includes(String(p.zayavka_id)));
                pm.properties.set('hideTriangle', !isFirst);
                pm.options.set({
                    iconImageHref: requestMarkerDataUri(g, !isFirst, isSelected),
                    iconImageSize: [REQUEST_MARKER_WIDTH, REQUEST_MARKER_HEIGHT],
                    iconImageOffset: [-(REQUEST_MARKER_GLOW_PAD + 25), markerOffsetY(shiftY)],
                    balloonOffset: [0, -32 - shiftY],
                    visible: true
                });
            });
        }
        updateSelectionUI();
    };

    function rerenderWhenReady(attempt) {
        const tryNo = Number(attempt || 0);
        if (typeof map !== 'undefined' && map && Array.isArray(placemarks) && placemarks.length > 0) {
            buildAvailableUtilizerFilters();
            updateMap();
            return;
        }
        if (tryNo < 40) {
            setTimeout(() => rerenderWhenReady(tryNo + 1), 100);
        }
    }

    if (typeof ymaps !== 'undefined' && ymaps.ready) {
        ymaps.ready(() => {
            setTimeout(() => rerenderWhenReady(0), 0);
            setTimeout(() => loadDangerousUtilizers(), 0);
        });
    } else {
        window.addEventListener('load', () => {
            rerenderWhenReady(0);
            loadDangerousUtilizers();
        });
    }
})();
