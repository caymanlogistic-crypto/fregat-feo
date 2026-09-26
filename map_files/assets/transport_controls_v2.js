/* Slitex transport controls v3.
 * Owns transport refresh lifecycle and transport display modes after the map refactor.
 * Keeps last valid data on network/API failures and preserves the selected radio mode.
 */
(function () {
    'use strict';

    const ENDPOINT = 'map_files/get_transport_trackers.php';
    const REFRESH_MS = 60000;
    const REQUEST_TIMEOUT_MS = 20000;
    const ACTIVE_24H_MINUTES = 24 * 60;
    let installed = false;
    let refreshPromise = null;

    function validMode(mode) {
        return mode === 'active' || mode === 'recent24' || mode === 'all' || mode === 'none';
    }

    function currentMode() {
        return validMode(transportDisplayMode) ? transportDisplayMode : 'active';
    }

    function ensureTransportUi() {
        const activeRadio = document.getElementById('transport_mode_active');
        if (!activeRadio) return;

        const group = activeRadio.closest('.layer-group');
        if (group) {
            const title = group.querySelector('.layer-group-title');
            if (title) title.textContent = 'Транспорт';
        }

        if (!document.getElementById('transport_mode_recent24')) {
            const activeRow = activeRadio.closest('.checkbox-item');
            if (activeRow && activeRow.parentNode) {
                const row = document.createElement('div');
                row.className = 'checkbox-item';
                row.innerHTML = '<input type="radio" name="transportMode" id="transport_mode_recent24" value="recent24">' +
                    '<label for="transport_mode_recent24">Активный транспорт</label>';
                activeRow.insertAdjacentElement('afterend', row);
            }
        }
    }

    function radioState() {
        return {
            active: document.getElementById('transport_mode_active'),
            recent24: document.getElementById('transport_mode_recent24'),
            all: document.getElementById('transport_mode_all'),
            none: document.getElementById('transport_mode_none')
        };
    }

    function syncRadios() {
        const mode = currentMode();
        const radios = radioState();
        if (radios.active) radios.active.checked = mode === 'active';
        if (radios.recent24) radios.recent24.checked = mode === 'recent24';
        if (radios.all) radios.all.checked = mode === 'all';
        if (radios.none) radios.none.checked = mode === 'none';
    }

    function trackerMinutesSafe(tracker) {
        if (typeof getTrackerMinutes === 'function') {
            const value = Number(getTrackerMinutes(tracker));
            return Number.isFinite(value) ? value : 999999;
        }
        const candidates = [
            tracker && tracker.minutes,
            tracker && tracker.time_diff_minutes,
            tracker && tracker.minutes_ago,
            tracker && tracker.age_minutes
        ];
        for (let i = 0; i < candidates.length; i += 1) {
            const n = Number(candidates[i]);
            if (Number.isFinite(n)) return n;
        }
        return 999999;
    }

    function datasetsForMode() {
        if (currentMode() === 'none') return [];

        const allDataset = Array.isArray(currentAllTrackers) && currentAllTrackers.length
            ? currentAllTrackers
            : (Array.isArray(currentActiveTrackers) ? currentActiveTrackers : []);

        if (currentMode() === 'all') {
            return allDataset.filter(function (tracker) {
                return typeof hasTrackerCoords === 'function' ? hasTrackerCoords(tracker) : !!tracker;
            });
        }

        if (currentMode() === 'recent24') {
            return allDataset.filter(function (tracker) {
                const hasCoords = typeof hasTrackerCoords === 'function' ? hasTrackerCoords(tracker) : !!tracker;
                return hasCoords && trackerMinutesSafe(tracker) < ACTIVE_24H_MINUTES;
            });
        }

        const activeDataset = typeof managerFilteredTrackers === 'function'
            ? managerFilteredTrackers(Array.isArray(currentActiveTrackers) ? currentActiveTrackers : [])
            : (Array.isArray(currentActiveTrackers) ? currentActiveTrackers : []);

        if (typeof mergeWithRecentActivatedTrackers === 'function') {
            return mergeWithRecentActivatedTrackers(activeDataset, allDataset);
        }
        return activeDataset;
    }

    function renderCurrentMode() {
        if (typeof addTrackerMarkers === 'function') {
            addTrackerMarkers(datasetsForMode());
        }
    }

    function applyMode(mode) {
        const nextMode = validMode(mode) ? mode : 'active';
        transportDisplayMode = nextMode;
        showTransport = nextMode !== 'none';
        syncRadios();
        renderCurrentMode();
    }

    function setRefreshButtonBusy(isBusy) {
        const btn = document.getElementById('refreshBtn');
        if (!btn) return function () {};
        const previousText = btn.innerHTML;
        const previousDisabled = !!btn.disabled;
        if (isBusy) {
            btn.innerHTML = '⏳ ...';
            btn.disabled = true;
        }
        return function restore() {
            btn.innerHTML = previousText;
            btn.disabled = previousDisabled;
        };
    }

    async function fetchJsonWithTimeout(url) {
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timeoutId = controller ? setTimeout(function () { controller.abort(); }, REQUEST_TIMEOUT_MS) : null;
        try {
            const response = await fetch(url + '?_=' + Date.now(), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller ? controller.signal : undefined
            });
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseError) {
                throw new Error('Transport endpoint returned invalid JSON');
            }
            if (!response.ok || !data || data.success !== true) {
                throw new Error((data && data.message) || ('Transport HTTP ' + response.status));
            }
            if (!Array.isArray(data.active_trackers) || !Array.isArray(data.all_trackers)) {
                throw new Error('Transport endpoint returned invalid tracker arrays');
            }
            return data;
        } finally {
            if (timeoutId) clearTimeout(timeoutId);
        }
    }

    async function refreshTransportData(source) {
        if (refreshPromise) return refreshPromise;

        const restoreButton = setRefreshButtonBusy(source === 'manual');
        isUpdatingTrackers = true;

        refreshPromise = (async function () {
            try {
                if (typeof loadRecentActivatedTrackers === 'function') {
                    try {
                        await loadRecentActivatedTrackers();
                    } catch (activationError) {
                        console.warn('Не удалось обновить список новых трекеров:', activationError);
                    }
                }

                const data = await fetchJsonWithTimeout(ENDPOINT);
                currentActiveTrackers = data.active_trackers.slice();
                currentAllTrackers = data.all_trackers.slice();

                syncRadios();
                renderCurrentMode();

                window.dispatchEvent(new CustomEvent('transport:refreshed', {
                    detail: {
                        source: source || 'unknown',
                        mode: currentMode(),
                        activeCount: currentActiveTrackers.length,
                        active24Count: currentAllTrackers.filter(function (tracker) {
                            const hasCoords = typeof hasTrackerCoords === 'function' ? hasTrackerCoords(tracker) : !!tracker;
                            return hasCoords && trackerMinutesSafe(tracker) < ACTIVE_24H_MINUTES;
                        }).length,
                        allCount: currentAllTrackers.length,
                        generatedAt: data.generated_at || null
                    }
                }));
                return true;
            } catch (error) {
                console.error('Ошибка обновления транспорта:', error);
                syncRadios();
                window.dispatchEvent(new CustomEvent('transport:refresh-error', {
                    detail: { source: source || 'unknown', message: String(error && error.message || error) }
                }));
                return false;
            } finally {
                isUpdatingTrackers = false;
                restoreButton();
                refreshPromise = null;
            }
        })();

        return refreshPromise;
    }

    function installRefreshButton() {
        const btn = document.getElementById('refreshBtn');
        if (!btn || btn.dataset.transportV2Bound === '1') return;
        btn.dataset.transportV2Bound = '1';
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
            refreshTransportData('manual');
        }, true);
    }

    function installRadioGuard() {
        ensureTransportUi();
        const radios = document.querySelectorAll('input[name="transportMode"]');
        radios.forEach(function (radio) {
            if (radio.dataset.transportV3Bound === '1') return;
            radio.dataset.transportV3Bound = '1';
            radio.addEventListener('change', function (event) {
                if (!this.checked) return;
                event.stopImmediatePropagation();
                applyMode(this.value);
            }, true);
        });
        syncRadios();
    }

    function takeOverAutoRefresh() {
        if (trackerUpdateInterval) clearInterval(trackerUpdateInterval);
        trackerUpdateInterval = setInterval(function () {
            refreshTransportData('auto');
        }, REFRESH_MS);
    }

    function validateControls() {
        const radios = radioState();
        const checked = [radios.active, radios.recent24, radios.all, radios.none].filter(function (el) { return el && el.checked; });
        const issues = [];
        if (!radios.active || !radios.recent24 || !radios.all || !radios.none) issues.push('missing-radio');
        if (checked.length !== 1) issues.push('radio-checked-count-' + checked.length);
        if (!validMode(currentMode())) issues.push('invalid-mode');
        if (!Array.isArray(currentActiveTrackers)) issues.push('active-dataset-not-array');
        if (!Array.isArray(currentAllTrackers)) issues.push('all-dataset-not-array');
        if (currentMode() === 'none' && showTransport !== false) issues.push('none-mode-visible');
        if (currentMode() !== 'none' && showTransport !== true) issues.push('visible-mode-hidden');

        const expected = datasetsForMode().length;
        const rendered = Array.isArray(trackerPlacemarks) ? trackerPlacemarks.length : -1;
        if (rendered >= 0 && rendered !== expected) {
            issues.push('rendered-count-mismatch-' + rendered + '-expected-' + expected);
        }

        return {
            ok: issues.length === 0,
            issues: issues,
            mode: currentMode(),
            activeCount: Array.isArray(currentActiveTrackers) ? currentActiveTrackers.length : -1,
            active24Count: Array.isArray(currentAllTrackers) ? currentAllTrackers.filter(function (tracker) {
                const hasCoords = typeof hasTrackerCoords === 'function' ? hasTrackerCoords(tracker) : !!tracker;
                return hasCoords && trackerMinutesSafe(tracker) < ACTIVE_24H_MINUTES;
            }).length : -1,
            allCount: Array.isArray(currentAllTrackers) ? currentAllTrackers.length : -1,
            expectedRenderedCount: expected,
            renderedCount: rendered
        };
    }

    function ready() {
        return typeof addTrackerMarkers === 'function' &&
            typeof currentActiveTrackers !== 'undefined' &&
            typeof currentAllTrackers !== 'undefined' &&
            typeof transportDisplayMode !== 'undefined';
    }

    function install(attempt) {
        if (installed) return;
        if (!ready()) {
            if ((attempt || 0) < 200) setTimeout(function () { install((attempt || 0) + 1); }, 50);
            return;
        }

        ensureTransportUi();

        // Own these globals so every legacy caller also respects the new recent24 mode.
        getTrackersForCurrentMode = datasetsForMode;
        renderTrackersByMode = renderCurrentMode;
        setTransportDisplayMode = applyMode;
        fetchTrackers = function () { return refreshTransportData('legacy-call'); };

        installRefreshButton();
        installRadioGuard();
        setTimeout(takeOverAutoRefresh, 500);
        setTimeout(takeOverAutoRefresh, 2000);

        installed = true;
        window.TRANSPORT_CONTROLS_V2 = {
            endpoint: ENDPOINT,
            refresh: refreshTransportData,
            validate: validateControls,
            syncRadios: syncRadios,
            setMode: applyMode,
            getTrackersForMode: datasetsForMode,
            active24Minutes: ACTIVE_24H_MINUTES,
            takeOverAutoRefresh: takeOverAutoRefresh,
            refreshMs: REFRESH_MS,
            installed: true,
            version: '3'
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { install(0); }, { once: true });
    } else {
        install(0);
    }
})();
