/* Warehouse marker v2: keep SKLAD label, show request count and formatted total netto weight in top row. */
(function () {
    'use strict';

    const WAREHOUSE_MARKER_WIDTH = 58;
    const WAREHOUSE_MARKER_HEIGHT = 32;

    function escapeSvgText(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatWarehouseWeightKg(massNettoTons) {
        const kg = Math.round(Number(massNettoTons || 0) * 1000);
        if (!Number.isFinite(kg)) return '0 кг';
        return String(kg).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' кг';
    }

    function createWarehouseMarkerV2SvgDataUri(label, bgColor, borderColor, requestCount, massNettoTons) {
        const safeLabel = escapeSvgText(label || 'СКЛАД');
        const safeCount = escapeSvgText(String(Math.max(0, Number(requestCount || 0))));
        const safeWeight = escapeSvgText(formatWarehouseWeightKg(massNettoTons));
        const bodyPath = 'M4 1 H54 Q57 1 57 4 V22 Q57 25 54 25 H36 L29 31 L22 25 H4 Q1 25 1 22 V4 Q1 1 4 1 Z';
        const svg =
            '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"58\" height=\"32\" viewBox=\"0 0 58 32\">' +
            '<path d=\"' + bodyPath + '\" fill=\"' + bgColor + '\" stroke=\"' + borderColor + '\" stroke-width=\"2\"/>' +
            '<line x1=\"2\" y1=\"11\" x2=\"56\" y2=\"11\" stroke=\"' + borderColor + '\" stroke-width=\"1.4\"/>' +
            '<text x=\"5\" y=\"8.2\" text-anchor=\"start\" font-family=\"Arial,sans-serif\" font-size=\"6.5\" font-weight=\"700\" fill=\"#ffffff\">' + safeCount + '</text>' +
            '<text x=\"53\" y=\"8.2\" text-anchor=\"end\" font-family=\"Arial,sans-serif\" font-size=\"5.9\" font-weight=\"700\" fill=\"#ffffff\">' + safeWeight + '</text>' +
            '<text x=\"29\" y=\"21\" text-anchor=\"middle\" font-family=\"Arial,sans-serif\" font-size=\"9.5\" font-weight=\"700\" fill=\"#ffffff\">' + safeLabel + '</text>' +
            '</svg>';
        return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
    }

    window.WAREHOUSE_MARKERS_V2 = {
        width: WAREHOUSE_MARKER_WIDTH,
        height: WAREHOUSE_MARKER_HEIGHT,
        formatWeightKg: formatWarehouseWeightKg,
        createSvgDataUri: createWarehouseMarkerV2SvgDataUri
    };

    createWarehouseMarkerSvgDataUri = function (label, bgColor, borderColor, requestCount, massNettoTons) {
        return createWarehouseMarkerV2SvgDataUri(label, bgColor, borderColor, requestCount, massNettoTons);
    };

    renderWarehousesLayer = function () {
        if (!warehousesCollection || !map) return;
        warehousesCollection.removeAll();
        if (!warehousesVisible) return;

        (Array.isArray(warehousesData) ? warehousesData : []).forEach((warehouse) => {
            if (!warehouse) return;
            const lat = Number(warehouse.latitude);
            const lon = Number(warehouse.longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
            const id = String(warehouse.id || '').trim();
            if (!id) return;

            const stock = getWarehouseStockById(id);
            const hasStock = stock
                && Number(stock.request_count || 0) > 0
                && Number(stock.mass_netto_sum || 0) > 0
                && Array.isArray(stock.requests)
                && stock.requests.length > 0;
            if (!hasStock) return;

            const name = String(warehouse.name || ('Склад #' + id)).trim();
            const isSelectedWH = String(selectedWarehouseId || '') === id;
            const bgColor = isSelectedWH ? '#27ae60' : '#6c757d';
            const borderColor = isSelectedWH ? '#1f8f4d' : '#4f565c';
            const requestCount = Number(stock.request_count || 0);
            const massNettoSum = Number(stock.mass_netto_sum || 0);
            const iconHref = createWarehouseMarkerV2SvgDataUri(
                UI.labelWarehouseMarker || 'СКЛАД',
                bgColor,
                borderColor,
                requestCount,
                massNettoSum
            );
            const totalKgFormatted = formatWarehouseWeightKg(massNettoSum);

            const placemark = new ymaps.Placemark([lat, lon], {
                hintContent: name + ' — ' + requestCount + ' заяв. — ' + totalKgFormatted,
                balloonContent: buildWarehouseBalloon(warehouse)
            }, {
                iconLayout: 'default#image',
                iconImageHref: iconHref,
                iconImageSize: [WAREHOUSE_MARKER_WIDTH, WAREHOUSE_MARKER_HEIGHT],
                iconImageOffset: [-29, -32],
                openBalloonOnClick: false,
                openHintOnHover: true,
                zIndex: 480
            });

            placemark.events.add('click', function (e) {
                if (e && typeof e.preventDefault === 'function') {
                    e.preventDefault();
                }
                toggleWarehouseSelection(id);
            });

            placemark.events.add('dblclick', function (e) {
                if (e && typeof e.preventDefault === 'function') {
                    e.preventDefault();
                }
                placemark.balloon.open();
            });

            warehousesCollection.add(placemark);
        });
    };

    function rerenderWarehousesWhenReady(attempt) {
        const tryNo = Number(attempt || 0);
        if (typeof map !== 'undefined' && map && warehousesCollection && Array.isArray(warehousesData)) {
            renderWarehousesLayer();
            return;
        }
        if (tryNo < 40) {
            setTimeout(() => rerenderWarehousesWhenReady(tryNo + 1), 100);
        }
    }

    if (typeof ymaps !== 'undefined' && ymaps.ready) {
        ymaps.ready(() => setTimeout(() => rerenderWarehousesWhenReady(0), 0));
    } else {
        window.addEventListener('load', () => rerenderWarehousesWhenReady(0));
    }
})();
