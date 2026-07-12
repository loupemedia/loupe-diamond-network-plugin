/**
 * Shared faceted diamond overlay + face-up comparison visuals (diamdb-style).
 *
 * Stone A = Ringspo brand purple, Stone B = Ringspo signature green
 * (high contrast on overlay + bars). Expects unit-box faceted snippets in
 * manifest.faceted_shapes (built by Z3).
 */
(function (global) {
    'use strict';

    var STONE_A_COLOR = '#706cc8';
    var STONE_B_COLOR = '#6cc8be';

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatShape(shape) {
        return (shape || '').replace(/-/g, ' ').replace(/\b\w/g, function (c) {
            return c.toUpperCase();
        });
    }

    function stoneLabel(shape, carat, length, width) {
        var parts = [carat + ' ct', formatShape(shape)];
        if (length > 0 && width > 0) {
            parts.push('(' + Number(length).toFixed(2) + ' × ' + Number(width).toFixed(2) + ' mm)');
        }
        return parts.join(' ');
    }

    function ellipseLayer(w, l, canvasW, canvasL, color, opacity) {
        var x = (canvasW - w) / 2;
        var y = (canvasL - l) / 2;
        return '<ellipse cx="' + (x + w / 2) + '" cy="' + (y + l / 2)
            + '" rx="' + (w / 2) + '" ry="' + (l / 2)
            + '" fill="' + color + '" fill-opacity="' + opacity + '" />';
    }

    function facetedLayer(shape, w, l, canvasW, canvasL, color, opacity, catalog) {
        var markup = catalog && catalog[shape];
        if (!markup) {
            return ellipseLayer(w, l, canvasW, canvasL, color, opacity);
        }
        var x = (canvasW - w) / 2;
        var y = (canvasL - l) / 2;
        var op = opacity < 1 ? ' opacity="' + opacity + '"' : '';
        return '<g transform="translate(' + x + ',' + y + ') scale(' + w + ',' + l + ')"'
            + ' style="color:' + color + '"' + op + '>' + markup + '</g>';
    }

    function renderTwoStone(opts) {
        var widthA = opts.widthA || 0;
        var lengthA = opts.lengthA || 0;
        var widthB = opts.widthB || 0;
        var lengthB = opts.lengthB || 0;
        var maxW = Math.max(widthA, widthB);
        var maxL = Math.max(lengthA, lengthB);
        if (maxW <= 0 || maxL <= 0) {
            return '';
        }
        var maxPx = opts.maxPx || 280;
        var scale = maxPx / Math.max(maxW, maxL);
        var aw = widthA * scale;
        var al = lengthA * scale;
        var bw = widthB * scale;
        var bl = lengthB * scale;
        var canvasW = maxW * scale;
        var canvasL = maxL * scale;
        var catalog = opts.catalog || {};
        var colorA = opts.colorA || STONE_A_COLOR;
        var colorB = opts.colorB || STONE_B_COLOR;
        var opacityA = opts.opacityA !== undefined ? opts.opacityA : 0.88;
        var opacityB = opts.opacityB !== undefined ? opts.opacityB : 0.42;
        var aria = opts.ariaLabel || 'Face-up size preview';
        var cls = opts.cssClass ? ' class="' + opts.cssClass + '"' : '';
        var widthAttr = opts.widthPercent ? ' width="100%"' : ' width="' + canvasW + '" height="' + canvasL + '"';

        return '<svg viewBox="0 0 ' + canvasW + ' ' + canvasL + '"' + widthAttr + cls
            + ' role="img" aria-label="' + escapeHtml(aria) + '">'
            + facetedLayer(opts.shapeB, bw, bl, canvasW, canvasL, colorB, opacityB, catalog)
            + facetedLayer(opts.shapeA, aw, al, canvasW, canvasL, colorA, opacityA, catalog)
            + '</svg>';
    }

    function renderLegend(opts) {
        var colorA = opts.colorA || STONE_A_COLOR;
        var colorB = opts.colorB || STONE_B_COLOR;
        var labelA = opts.labelA || 'Stone A';
        var labelB = opts.labelB || 'Stone B';
        return '<ul class="ldn-faceup-legend" role="list">'
            + '<li class="ldn-faceup-legend__item ldn-faceup-legend__item--a">'
            + '<span class="ldn-faceup-legend__swatch" style="background:' + colorA + '"></span>'
            + '<span>' + escapeHtml(labelA) + '</span></li>'
            + '<li class="ldn-faceup-legend__item ldn-faceup-legend__item--b">'
            + '<span class="ldn-faceup-legend__swatch" style="background:' + colorB + '"></span>'
            + '<span>' + escapeHtml(labelB) + '</span></li>'
            + '</ul>';
    }

    function renderFaceupBars(opts) {
        var faceupA = opts.faceupA || 0;
        var faceupB = opts.faceupB || 0;
        if (faceupA <= 0 || faceupB <= 0) {
            return '';
        }
        var colorA = opts.colorA || STONE_A_COLOR;
        var colorB = opts.colorB || STONE_B_COLOR;
        var labelA = opts.labelA || 'Stone A';
        var labelB = opts.labelB || 'Stone B';
        var max = Math.max(faceupA, faceupB);
        var pctA = Math.round((faceupA / max) * 100);
        var pctB = Math.round((faceupB / max) * 100);

        function bar(label, faceup, pct, color, modifier) {
            return '<div class="ldn-faceup-bar ldn-faceup-bar--' + modifier + '">'
                + '<div class="ldn-faceup-bar__head">'
                + '<span class="ldn-faceup-bar__label">' + escapeHtml(label) + '</span>'
                + '<span class="ldn-faceup-bar__value">' + faceup.toFixed(2) + ' mm²</span>'
                + '</div>'
                + '<div class="ldn-faceup-bar__track" role="presentation">'
                + '<div class="ldn-faceup-bar__fill" style="width:' + pct + '%;background:' + color + '"></div>'
                + '</div></div>';
        }

        var diff = Math.abs(faceupA - faceupB);
        var smaller = Math.min(faceupA, faceupB);
        var pctDiff = smaller > 0 ? Math.round((diff / smaller) * 100) : 0;

        return '<div class="ldn-faceup-bars">'
            + bar(labelA, faceupA, pctA, colorA, 'a')
            + bar(labelB, faceupB, pctB, colorB, 'b')
            + '<p class="ldn-faceup-bars__diff">Difference: '
            + diff.toFixed(2) + ' mm² (' + pctDiff + '%)</p>'
            + '</div>';
    }

    function renderCallout(opts) {
        var faceupA = opts.faceupA || 0;
        var faceupB = opts.faceupB || 0;
        if (faceupA <= 0 || faceupB <= 0) {
            return '';
        }
        var labelA = opts.labelA || 'Stone A';
        var labelB = opts.labelB || 'Stone B';
        var biggerLabel;
        var pct;
        if (Math.abs(faceupA - faceupB) < 0.01) {
            return '<p class="ldn-faceup-callout ldn-faceup-callout--tie">'
                + 'Both stones have the same face-up area ('
                + faceupA.toFixed(2) + ' mm²).</p>';
        }
        if (faceupA >= faceupB) {
            biggerLabel = labelA;
            pct = Math.round(((faceupA - faceupB) / faceupB) * 100);
        } else {
            biggerLabel = labelB;
            pct = Math.round(((faceupB - faceupA) / faceupA) * 100);
        }
        return '<p class="ldn-faceup-callout"><strong>' + escapeHtml(biggerLabel)
            + '</strong> faces up about <strong>' + pct + '% larger</strong> on the finger.</p>';
    }

    /**
     * Full face-up block: callout + legend + overlay + bars (+ optional footnote).
     */
    function renderComparisonBlock(opts) {
        var faceupA = opts.faceupA || 0;
        var faceupB = opts.faceupB || 0;
        if (faceupA <= 0 || faceupB <= 0) {
            return '';
        }
        var colorA = opts.colorA || STONE_A_COLOR;
        var colorB = opts.colorB || STONE_B_COLOR;
        var common = {
            colorA: colorA,
            colorB: colorB,
            labelA: opts.labelA,
            labelB: opts.labelB,
            faceupA: faceupA,
            faceupB: faceupB,
        };
        var html = renderCallout(common);
        html += renderLegend(common);
        html += '<div class="ldn-faceup-overlay">' + renderTwoStone(opts) + '</div>';
        html += renderFaceupBars(common);
        if (opts.footnote) {
            html += '<p class="ldn-faceup-footnote">' + escapeHtml(opts.footnote) + '</p>';
        }
        return html;
    }

    global.LdnFacetedOverlay = {
        STONE_A_COLOR: STONE_A_COLOR,
        STONE_B_COLOR: STONE_B_COLOR,
        facetedLayer: facetedLayer,
        renderTwoStone: renderTwoStone,
        renderLegend: renderLegend,
        renderFaceupBars: renderFaceupBars,
        renderCallout: renderCallout,
        renderComparisonBlock: renderComparisonBlock,
        stoneLabel: stoneLabel,
        formatShape: formatShape,
    };
}(typeof window !== 'undefined' ? window : this));
