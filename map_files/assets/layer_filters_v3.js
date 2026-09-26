/* Keep original custom-layer logic; only format utilizer request counts with square brackets. */
(function () {
    'use strict';

    function convertUtilizerCountsToSquareBrackets() {
        const group = document.getElementById('available-utilizer-layer-group');
        if (!group) return false;

        let changed = false;
        group.querySelectorAll('.checkbox-item label').forEach(label => {
            const text = String(label.textContent || '');
            const next = text.replace(/\s*\((\d+)\)\s*$/, ' [$1]');
            if (next !== text) {
                label.textContent = next;
                changed = true;
            }
        });
        return changed;
    }

    function init() {
        convertUtilizerCountsToSquareBrackets();
        setTimeout(convertUtilizerCountsToSquareBrackets, 100);
        setTimeout(convertUtilizerCountsToSquareBrackets, 300);
        setTimeout(convertUtilizerCountsToSquareBrackets, 800);

        const panel = document.querySelector('.layer-panel');
        if (panel && typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(() => {
                convertUtilizerCountsToSquareBrackets();
            });
            observer.observe(panel, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
