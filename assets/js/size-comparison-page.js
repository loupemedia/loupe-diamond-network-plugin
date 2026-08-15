/**
 * Face-up overlay for long-tail comparison pages (no pre-baked overlay_svg).
 *
 * Reads stone dimensions from #ldn-comparison-overlay-data and faceted shape
 * snippets from the shared size-checker manifest when available.
 */
(function () {
    'use strict';

    function readJsonScript(id) {
        var el = document.getElementById(id);
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (err) {
            return null;
        }
    }

    function init() {
        var host = document.getElementById('ldn-comparison-overlay');
        if (!host || typeof globalThis.LdnFacetedOverlay === 'undefined') {
            return;
        }
        var data = readJsonScript('ldn-comparison-overlay-data');
        if (!data || !data.a || !data.b) {
            host.removeAttribute('aria-busy');
            return;
        }

        var catalog = {};
        var manifest = readJsonScript('ldn-size-checker-manifest');
        if (manifest && manifest.faceted_shapes) {
            catalog = manifest.faceted_shapes;
        }

        var svg = globalThis.LdnFacetedOverlay.renderTwoStone({
            shapeA: data.a.shape,
            widthA: data.a.width_mm,
            lengthA: data.a.length_mm,
            shapeB: data.b.shape,
            widthB: data.b.width_mm,
            lengthB: data.b.length_mm,
            catalog: catalog,
            widthPercent: true,
            maxPx: 200,
            cssClass: 'ldn-comparison-overlay-svg ldn-size-compare-svg ldn-size-compare-svg--compact',
            ariaLabel: 'Face-up size comparison',
        });

        host.setAttribute('aria-busy', 'false');
        if (svg) {
            host.innerHTML = svg;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}(typeof window !== 'undefined' ? window : this));
