/* Fix tracker refresh after MAP_BOOTSTRAP refactor.
 * maptest.js still parsed legacy `const initialTrackers/allTrackers` declarations,
 * so the full tracker dataset was lost after the first refresh.
 */
(function () {
    'use strict';

    function extractBootstrapArray(html, key, nextKey) {
        const source = String(html || '');
        const pattern = new RegExp(
            key + '\\s*:\\s*([\\s\\S]*?)\\s*,\\s*' + nextKey + '\\s*:',
            'm'
        );
        const match = source.match(pattern);
        if (!match) return null;
        try {
            const parsed = JSON.parse(match[1]);
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            console.error('Tracker bootstrap parse failed for ' + key, error);
            return null;
        }
    }

    async function fetchTrackersFromBootstrap() {
        if (isUpdatingTrackers) return;
        isUpdatingTrackers = true;

        const btn = document.getElementById('refreshBtn');
        const originalText = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = 'Обновление...';
        }

        try {
            const response = await fetch(window.location.href, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const html = await response.text();
            const parsedActive = extractBootstrapArray(html, 'initialTrackers', 'allTrackers');
            const parsedAll = extractBootstrapArray(html, 'allTrackers', 'routeCardsMeta');

            // Do not erase a previously valid dataset just because parsing failed.
            if (parsedActive !== null) currentActiveTrackers = parsedActive;
            if (parsedAll !== null) currentAllTrackers = parsedAll;

            if (typeof renderTrackersByMode === 'function') {
                renderTrackersByMode();
            }
        } catch (error) {
            console.error('Ошибка обновления трекеров:', error);
        } finally {
            isUpdatingTrackers = false;
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
    }

    function installFix() {
        // Manual refresh calls fetchTrackers by name, so replace it too.
        fetchTrackers = fetchTrackersFromBootstrap;

        // The old interval captured the legacy function reference. Recreate it.
        if (trackerUpdateInterval) {
            clearInterval(trackerUpdateInterval);
        }
        trackerUpdateInterval = setInterval(fetchTrackersFromBootstrap, 60000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', installFix, { once: true });
    } else {
        installFix();
    }
})();
