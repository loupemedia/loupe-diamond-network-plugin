/**
 * Diamond size comparison tool — live preview + navigation to SSR pair pages.
 */
(function () {
    'use strict';

    var root = document.getElementById('ldn-size-compare-tool');
    if (!root) {
        return;
    }

    var manifestEl = document.getElementById('ldn-size-compare-manifest');
    if (!manifestEl) {
        return;
    }

    var manifest;
    try {
        manifest = JSON.parse(manifestEl.textContent || '{}');
    } catch (e) {
        return;
    }

    var shapeA = document.getElementById('ldn-compare-shape-a');
    var shapeB = document.getElementById('ldn-compare-shape-b');
    var caratA = document.getElementById('ldn-compare-carat-a');
    var caratB = document.getElementById('ldn-compare-carat-b');
    var form = document.getElementById('ldn-size-compare-form');
    var visualEl = document.getElementById('ldn-compare-faceup-visual');

    function entry(shape, carat) {
        if (!manifest.entries) {
            return null;
        }
        return manifest.entries[shape + '|' + carat] || null;
    }

    function slugFor(shape, carat) {
        var shapes = manifest.slug_shapes || {};
        var slugShape = shapes[shape] || shape.replace(/_/g, '-');
        return slugShape + '-' + carat + '-carat';
    }

    function comparisonUrl(shapeAVal, caratAVal, shapeBVal, caratBVal) {
        var ka = slugFor(shapeAVal, caratAVal);
        var kb = slugFor(shapeBVal, caratBVal);
        var base = root.getAttribute('data-compare-base') || '/diamond-size/compare/';
        if (base.slice(-1) !== '/') {
            base += '/';
        }
        return base + ka + '-vs-' + kb + '/';
    }

    function renderVisual(a, b) {
        if (!visualEl || !a || !b || !window.LdnFacetedOverlay) {
            if (visualEl) {
                visualEl.innerHTML = '';
            }
            return;
        }
        var fo = window.LdnFacetedOverlay;
        var fa = a.faceup_area_mm2 || 0;
        var fb = b.faceup_area_mm2 || 0;
        if (fa <= 0 || fb <= 0) {
            visualEl.innerHTML = '';
            return;
        }
        visualEl.innerHTML = fo.renderComparisonBlock({
            widthA: a.width_mm || 0,
            lengthA: a.length_mm || 0,
            widthB: b.width_mm || 0,
            lengthB: b.length_mm || 0,
            shapeA: a.shape,
            shapeB: b.shape,
            faceupA: fa,
            faceupB: fb,
            labelA: fo.stoneLabel(a.shape, a.carat, a.length_mm, a.width_mm),
            labelB: fo.stoneLabel(b.shape, b.carat, b.length_mm, b.width_mm),
            catalog: manifest.faceted_shapes || {},
            widthPercent: true,
            cssClass: 'ldn-size-compare-svg',
            ariaLabel: 'Face-up size comparison',
        });
    }

    function refresh() {
        if (!shapeA || !shapeB || !caratA || !caratB) {
            return;
        }
        renderVisual(entry(shapeA.value, caratA.value), entry(shapeB.value, caratB.value));
    }

    [shapeA, shapeB, caratA, caratB].forEach(function (el) {
        if (el) {
            el.addEventListener('change', refresh);
        }
    });

    if (form) {
        form.addEventListener('submit', function (evt) {
            evt.preventDefault();
            if (!shapeA || !shapeB || !caratA || !caratB) {
                return;
            }
            window.location.href = comparisonUrl(
                shapeA.value,
                caratA.value,
                shapeB.value,
                caratB.value
            );
        });
    }

    refresh();
})();
