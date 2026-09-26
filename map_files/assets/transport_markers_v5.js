/* Staging transport marker visual v5.
 * Selected variant #1: colored map pin with white truck + surname inline.
 * Rendering only. Filtering, tracker interaction and balloons remain in maptest.js.
 */
(function () {
    'use strict';

    const PIN_WIDTH = 24;
    const PIN_HEIGHT = 29;
    const PIN_TIP_X = 12;
    const PIN_TIP_Y = 28;
    const CANVAS_HEIGHT = 32;
    const TEXT_GAP = 5;
    const FONT_SIZE = 11.2;
    const MIN_WIDTH = 60;
    const MAX_WIDTH = 186;
    let installed = false;

    function palette(minutes, isNew) {
        // Selected visual states: blue / orange / red / blue+NEW.
        if (isNew) return { main: '#247FE0', dark: '#0D5BAE' };
        if (minutes < 10) return { main: '#247FE0', dark: '#0D5BAE' };
        if (minutes < 180) return { main: '#FF8A16', dark: '#B85B00' };
        return { main: '#EF3E3A', dark: '#A92220' };
    }

    function safeText(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&apos;');
    }

    function markerWidth(label) {
        const textWidth = Math.max(0, String(label || '').length) * 6.65;
        return Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, Math.ceil(PIN_WIDTH + TEXT_GAP + textWidth + 6)));
    }

    function markerSvg(tracker) {
        const minutes = typeof getTrackerMinutes === 'function' ? getTrackerMinutes(tracker) : 9999;
        const label = String((tracker && (tracker.short_name || tracker.name)) || '').trim() || 'ТС';
        const isNew = !!(tracker && tracker.is_new_tracker);
        const colors = palette(minutes, isNew);
        const width = markerWidth(label);
        const textX = PIN_WIDTH + TEXT_GAP;
        const newBadge = isNew
            ? `<g transform="translate(17 0)"><circle cx="4" cy="4" r="4" fill="#1677FF" stroke="#FFFFFF" stroke-width="1.2"/><text x="4" y="5.5" text-anchor="middle" font-family="Arial,sans-serif" font-size="3.6" font-weight="700" fill="#FFFFFF">N</text></g>`
            : '';

        return {
            width,
            height: CANVAS_HEIGHT,
            anchorX: PIN_TIP_X,
            anchorY: PIN_TIP_Y,
            svg: `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${CANVAS_HEIGHT}" viewBox="0 0 ${width} ${CANVAS_HEIGHT}" style="overflow:visible">
                <g>
                    <path d="M12 1.5 C5.9 1.5 2 5.8 2 11.1 C2 18.2 12 28 12 28 C12 28 22 18.2 22 11.1 C22 5.8 18.1 1.5 12 1.5 Z"
                          fill="${colors.main}" stroke="${colors.dark}" stroke-width="1.7" stroke-linejoin="round"/>
                    <g transform="translate(5.0 6.35) scale(.92)">
                        <rect x="0" y="1.2" width="9.8" height="6.6" rx="1.3" fill="#FFFFFF"/>
                        <path d="M9.8 3 H13.1 L15.2 5.1 V7.8 H9.8 Z" fill="#FFFFFF"/>
                        <rect x="10.8" y="3.9" width="1.8" height="1.4" rx=".25" fill="${colors.main}" opacity=".72"/>
                        <circle cx="3" cy="8.5" r="1.5" fill="#FFFFFF" stroke="${colors.dark}" stroke-width=".7"/>
                        <circle cx="11.9" cy="8.5" r="1.5" fill="#FFFFFF" stroke="${colors.dark}" stroke-width=".7"/>
                    </g>
                </g>
                ${newBadge}
                <text x="${textX}" y="17.0" text-anchor="start" font-family="Arial,sans-serif" font-size="${FONT_SIZE}" font-weight="700"
                      fill="#18232E" stroke="#FFFFFF" stroke-width="3.1" stroke-linejoin="round" paint-order="stroke fill">${safeText(label)}</text>
            </svg>`
        };
    }

    function svgDataUri(svg) {
        return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    }

    function addTrackerMarkersV5(trackers) {
        if (!trackersCollection) return;

        trackerPlacemarks.forEach(pm => trackersCollection.remove(pm));
        trackerPlacemarks = [];
        if (!showTransport) return;

        (Array.isArray(trackers) ? trackers : []).forEach(tracker => {
            if (!tracker || typeof tracker !== 'object') return;
            if (typeof hasTrackerCoords === 'function' && !hasTrackerCoords(tracker)) return;

            const built = markerSvg(tracker);
            const placemark = new ymaps.Placemark(
                [Number(tracker.lat), Number(tracker.lon)],
                {
                    hintContent: `${tracker.name || ''} | ${tracker.time_diff_text || ''} назад`,
                    balloonContent: typeof createTrackerBalloon === 'function' ? createTrackerBalloon(tracker) : String(tracker.name || ''),
                    trackerData: tracker
                },
                {
                    iconLayout: 'default#image',
                    iconImageHref: svgDataUri(built.svg),
                    iconImageSize: [built.width, built.height],
                    iconImageOffset: [-built.anchorX, -built.anchorY],
                    visible: showTransport,
                    zIndex: 1000
                }
            );

            trackerPlacemarks.push(placemark);
            trackersCollection.add(placemark);
        });
    }

    function readyToInstall() {
        return typeof ymaps !== 'undefined' &&
            typeof addTrackerMarkers === 'function' &&
            typeof renderTrackersByMode === 'function' &&
            typeof trackersCollection !== 'undefined' &&
            trackersCollection;
    }

    function installWhenReady(attempt) {
        if (installed) return;
        const n = Number(attempt || 0);
        if (!readyToInstall()) {
            if (n < 200) setTimeout(() => installWhenReady(n + 1), 50);
            return;
        }

        addTrackerMarkers = addTrackerMarkersV5;
        installed = true;
        window.TRANSPORT_MARKERS_V5 = {
            palette,
            markerSvg,
            pinWidth: PIN_WIDTH,
            pinHeight: PIN_HEIGHT,
            installed: true,
            variant: 'selected-icon-1-pin-truck-inline-surname'
        };
        renderTrackersByMode();
    }

    if (typeof ymaps !== 'undefined' && ymaps.ready) {
        ymaps.ready(() => installWhenReady(0));
    } else {
        window.addEventListener('load', () => installWhenReady(0), { once: true });
    }
})();
