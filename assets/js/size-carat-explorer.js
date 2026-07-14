/**
 * Carat-hub scale explorer — fixed carat, variable shape, mm ruler (no quarter).
 *
 * Reads #ldn-carat-scale-manifest embedded by carat_hub_scale_html().
 */
(function () {
    'use strict';

    var root = document.getElementById('ldn-carat-scale-explorer');
    var manifestEl = document.getElementById('ldn-carat-scale-manifest');
    if (!root || !manifestEl) {
        return;
    }

    var manifest;
    try {
        manifest = JSON.parse(manifestEl.textContent || '{}');
    } catch (e) {
        return;
    }

    var controls = document.getElementById('ldn-carat-scale-controls');
    var shapeSel = document.getElementById('ldn-carat-scale-shape');
    var figure = document.getElementById('ldn-carat-scale-figure');
    var grid = document.getElementById('ldn-carat-scale-grid');
    if (!shapeSel || !figure) {
        return;
    }

    var entries = manifest.entries || [];
    var currentShape = root.getAttribute('data-shape') || (entries[0] && entries[0].shape) || '';
    var caratBand = manifest.carat_band || root.getAttribute('data-carat') || '';

    function shapeLabel(shape) {
        return (shape || '').replace(/-/g, ' ').replace(/\b\w/g, function (c) {
            return c.toUpperCase();
        });
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function entryFor(shape) {
        for (var i = 0; i < entries.length; i++) {
            if (entries[i].shape === shape) {
                return entries[i];
            }
        }
        return null;
    }

    function parseNum(value) {
        var n = parseFloat(value);
        return isFinite(n) && n > 0 ? n : null;
    }

    function highlightGrid(shape) {
        if (!grid) {
            return;
        }
        var cells = grid.querySelectorAll('.ldn-size-carat-scale__cell');
        for (var i = 0; i < cells.length; i++) {
            var cell = cells[i];
            if (cell.getAttribute('data-shape') === shape) {
                cell.classList.add('ldn-size-carat-scale__cell--active');
            } else {
                cell.classList.remove('ldn-size-carat-scale__cell--active');
            }
        }
    }

    function renderRuler() {
        var entry = entryFor(currentShape);
        if (!entry) {
            return;
        }
        var length = parseNum(entry.length_mm);
        var width = parseNum(entry.width_mm);
        if (length === null || width === null) {
            return;
        }

        var pxPerMm = 7;
        var padMm = 3;
        var rulerMax = Math.max(20, Math.ceil((width + padMm * 2) / 5) * 5);
        var canvasWmm = rulerMax;
        var canvasHmm = length + padMm * 2 + 6;
        var stoneX = padMm;
        var stoneY = padMm;
        var baselineY = stoneY + length;

        var ticks = '';
        for (var mm = 0; mm <= rulerMax; mm += 5) {
            var x = mm;
            var major = mm % 10 === 0;
            ticks += '<line x1="' + x + '" y1="' + baselineY + '" x2="' + x + '" y2="'
                + (baselineY + (major ? 1.8 : 1)) + '" stroke="#64748b" stroke-width="0.15" />';
            if (major) {
                ticks += '<text x="' + x + '" y="' + (baselineY + 4.2) + '" text-anchor="middle"'
                    + ' font-size="1.6" fill="#64748b" font-family="sans-serif">' + mm + '</text>';
            }
        }
        ticks += '<line x1="0" y1="' + baselineY + '" x2="' + rulerMax + '" y2="' + baselineY
            + '" stroke="#94a3b8" stroke-width="0.2" />';

        var stoneMarkup;
        if (window.LdnFacetedOverlay) {
            stoneMarkup = '<g transform="translate(' + stoneX + ',' + stoneY
                + ') scale(' + width + ',' + length + ')" style="color:#1a1a2e">'
                + '<ellipse cx="' + (width / 2) + '" cy="' + (length / 2) + '" rx="'
                + (width / 2) + '" ry="' + (length / 2)
                + '" fill="none" stroke="currentColor" stroke-width="0.2" /></g>';
        } else {
            stoneMarkup = '<ellipse cx="' + (stoneX + width / 2) + '" cy="' + (stoneY + length / 2)
                + '" rx="' + (width / 2) + '" ry="' + (length / 2)
                + '" fill="none" stroke="#1a1a2e" stroke-width="0.2" />';
        }

        var aria = caratBand + ' carat ' + shapeLabel(currentShape)
            + ' — ' + width.toFixed(2) + ' × ' + length.toFixed(2) + ' mm on millimetre ruler';
        var svg = '<svg viewBox="0 0 ' + canvasWmm + ' ' + canvasHmm + '" width="100%" role="img"'
            + ' class="ldn-size-carat-scale-svg" aria-label="' + escapeHtml(aria) + '">'
            + stoneMarkup + ticks + '</svg>';
        var caption = shapeLabel(currentShape) + ' at ' + caratBand + ' ct — '
            + width.toFixed(2) + ' × ' + length.toFixed(2) + ' mm (true scale).';

        figure.innerHTML = '<figure class="ldn-size-figure ldn-size-figure--carat-scale">'
            + '<div class="ldn-size-outline ldn-size-outline--carat-scale">' + svg + '</div>'
            + '<figcaption class="ldn-size-figure__caption">' + escapeHtml(caption)
            + '</figcaption></figure>';

        highlightGrid(currentShape);
    }

    shapeSel.addEventListener('change', function () {
        currentShape = shapeSel.value;
        root.setAttribute('data-shape', currentShape);
        renderRuler();
    });

    renderRuler();
    if (controls) {
        controls.hidden = false;
    }
})();
