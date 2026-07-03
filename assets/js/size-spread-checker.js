/**
 * Stone spread checker — rank face-up spread vs real market + two-stone compare.
 */
(function () {
    'use strict';

    var root = document.getElementById('ldn-size-spread-checker');
    if (!root) {
        return;
    }

    var manifestEl = document.getElementById('ldn-size-spread-manifest');
    if (!manifestEl) {
        return;
    }

    var manifest;
    try {
        manifest = JSON.parse(manifestEl.textContent || '{}');
    } catch (e) {
        return;
    }

    var PERCENTILE_KNOTS = [
        [10, 'p10'],
        [25, 'p25'],
        [50, 'median'],
        [75, 'p75'],
        [90, 'p90']
    ];

    function parseNum(raw) {
        var n = parseFloat(raw);
        return isFinite(n) && n > 0 ? n : null;
    }

    function assignCaratBand(carat, ranges) {
        var i;
        for (i = 0; i < ranges.length; i++) {
            if (carat >= ranges[i].min && carat < ranges[i].max) {
                return ranges[i].label;
            }
        }
        return null;
    }

    function resolveReferenceBand(carat, ranges) {
        var exact = assignCaratBand(carat, ranges);
        if (exact !== null) {
            return exact;
        }
        var best = null;
        var bestDist = null;
        var j;
        for (j = 0; j < ranges.length; j++) {
            var labelVal = parseFloat(ranges[j].label);
            if (!isFinite(labelVal)) {
                continue;
            }
            var dist = Math.abs(carat - labelVal);
            if (bestDist === null || dist < bestDist) {
                bestDist = dist;
                best = ranges[j].label;
            }
        }
        return best;
    }

    function percentileRank(value, dist) {
        if (value === null || !dist) {
            return null;
        }
        var points = [];
        var i;
        for (i = 0; i < PERCENTILE_KNOTS.length; i++) {
            var key = PERCENTILE_KNOTS[i][1];
            if (dist[key] !== undefined && dist[key] !== null) {
                points.push([parseFloat(dist[key]), PERCENTILE_KNOTS[i][0]]);
            }
        }
        if (points.length < 2) {
            return null;
        }
        points.sort(function (a, b) { return a[0] - b[0]; });
        var vmin = points[0][0];
        var pmin = points[0][1];
        var vmax = points[points.length - 1][0];
        var pmax = points[points.length - 1][1];
        if (value <= vmin) {
            if (vmin <= 0) {
                return pmin;
            }
            return Math.max(0, pmin * (value / vmin));
        }
        if (value >= vmax) {
            var prevV = points[points.length - 2][0];
            var span = vmax - prevV;
            if (span <= 0) {
                return Math.min(99.5, pmax);
            }
            return Math.min(99.5, pmax + (value - vmax) / span * (100 - pmax));
        }
        for (i = 0; i < points.length - 1; i++) {
            var v0 = points[i][0];
            var p0 = points[i][1];
            var v1 = points[i + 1][0];
            var p1 = points[i + 1][1];
            if (value >= v0 && value <= v1) {
                if (v1 === v0) {
                    return (p0 + p1) / 2;
                }
                return p0 + (value - v0) / (v1 - v0) * (p1 - p0);
            }
        }
        return null;
    }

    function spreadQualityLabel(pct) {
        if (pct === null) {
            return 'Insufficient market data';
        }
        if (pct >= 90) {
            return 'Excellent spread (top 10%)';
        }
        if (pct >= 75) {
            return 'Above average spread';
        }
        if (pct >= 40) {
            return 'Average spread';
        }
        if (pct >= 25) {
            return 'Below average spread';
        }
        return 'Poor spread (bottom 25%)';
    }

    function lwRatio(length, width) {
        if (!length || !width || width <= 0) {
            return null;
        }
        return length / width;
    }

    function classifyProportion(shape, ratio, geo) {
        var split = geo.split_shapes || [];
        if (split.indexOf(shape) === -1 || ratio === null) {
            return 'na';
        }
        return ratio >= (geo.proportion_threshold || 1.1) ? 'elongated' : 'square';
    }

    function fillFactor(shape, proportionClass, geo) {
        var factors = geo.fill_factors || {};
        var entry = factors[shape];
        if (entry === undefined || entry === null) {
            return null;
        }
        if (typeof entry === 'object') {
            return entry[proportionClass] !== undefined ? entry[proportionClass] : entry.square;
        }
        return entry;
    }

    function faceupArea(shape, length, width, geo) {
        var ratio = lwRatio(length, width);
        var pclass = classifyProportion(shape, ratio, geo);
        var factor = fillFactor(shape, pclass, geo);
        if (factor === null) {
            return null;
        }
        return length * width * factor;
    }

    function entryFor(shape, caratBand) {
        if (!manifest.entries) {
            return null;
        }
        return manifest.entries[shape + '|' + caratBand] || null;
    }

    function shapeLabel(shape) {
        return shape.replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function evaluateStone(prefix) {
        var shape = document.getElementById('ldn-spread-shape-' + prefix).value;
        var carat = parseNum(document.getElementById('ldn-spread-carat-' + prefix).value);
        var length = parseNum(document.getElementById('ldn-spread-length-' + prefix).value);
        var width = parseNum(document.getElementById('ldn-spread-width-' + prefix).value);
        if (carat === null || length === null || width === null) {
            return null;
        }
        var refBand = resolveReferenceBand(carat, manifest.carat_band_ranges || []);
        var market = entryFor(shape, refBand);
        var faceup = faceupArea(shape, length, width, manifest.geometry || {});
        var pct = market && faceup !== null
            ? percentileRank(faceup, market.faceup_area_mm2)
            : null;
        var exactBand = assignCaratBand(carat, manifest.carat_band_ranges || []);
        var bandNote = '';
        if (exactBand === null && refBand !== null) {
            bandNote = carat + ' ct compared against ' + refBand + ' ct ' + shapeLabel(shape) + ' market data';
        }
        return {
            prefix: prefix,
            shape: shape,
            carat: carat,
            length: length,
            width: width,
            refBand: refBand,
            bandNote: bandNote,
            faceup: faceup,
            percentile: pct,
            quality: spreadQualityLabel(pct),
            perCarat: faceup !== null ? faceup / carat : null,
            market: market
        };
    }

    function renderStoneCard(stone, el) {
        if (!stone || !el) {
            el.innerHTML = '';
            return;
        }
        var pctText = stone.percentile !== null
            ? Math.round(stone.percentile) + 'th percentile'
            : '—';
        var html = '<p class="ldn-spread-card__quality"><strong>' + stone.quality + '</strong></p>';
        html += '<ul class="ldn-spread-card__stats">';
        html += '<li>Face-up: <strong>' + stone.faceup.toFixed(2) + ' mm²</strong></li>';
        html += '<li>Spread rank: <strong>' + pctText + '</strong></li>';
        html += '<li>Per carat: <strong>' + stone.perCarat.toFixed(2) + ' mm²/ct</strong></li>';
        html += '<li>Measurements: ' + stone.length.toFixed(2) + ' × ' + stone.width.toFixed(2) + ' mm</li>';
        html += '</ul>';
        if (stone.bandNote) {
            html += '<p class="ldn-spread-card__note">' + stone.bandNote + '</p>';
        }
        el.innerHTML = html;
    }

    function renderVisual(a, b) {
        var visualEl = document.getElementById('ldn-spread-faceup-visual');
        if (!visualEl || !a || !b) {
            if (visualEl) {
                visualEl.innerHTML = '';
            }
            return;
        }
        if (!window.LdnFacetedOverlay || a.faceup === null || b.faceup === null) {
            visualEl.innerHTML = '';
            return;
        }
        var fo = window.LdnFacetedOverlay;
        var footnoteParts = [];
        if (a.percentile !== null && b.percentile !== null) {
            var spreadWinner = b.percentile > a.percentile ? 'B' : 'A';
            footnoteParts.push('Stone ' + spreadWinner + ' has better spread for its weight ('
                + (spreadWinner === 'A' ? a.quality : b.quality).toLowerCase() + ').');
        }
        if (a.carat !== b.carat) {
            footnoteParts.push('Per carat: A ' + a.perCarat.toFixed(2) + ' mm²/ct vs B '
                + b.perCarat.toFixed(2) + ' mm²/ct.');
        }
        visualEl.innerHTML = fo.renderComparisonBlock({
            widthA: a.width,
            lengthA: a.length,
            widthB: b.width,
            lengthB: b.length,
            shapeA: a.shape,
            shapeB: b.shape,
            faceupA: a.faceup,
            faceupB: b.faceup,
            labelA: fo.stoneLabel(a.shape, a.carat, a.length, a.width),
            labelB: fo.stoneLabel(b.shape, b.carat, b.length, b.width),
            catalog: manifest.faceted_shapes || {},
            widthPercent: true,
            cssClass: 'ldn-size-compare-svg',
            ariaLabel: 'Face-up comparison',
            footnote: footnoteParts.join(' '),
        });
    }

    function refresh() {
        var a = evaluateStone('a');
        var b = evaluateStone('b');
        renderStoneCard(a, document.getElementById('ldn-spread-result-a'));
        renderStoneCard(b, document.getElementById('ldn-spread-result-b'));
        renderVisual(a, b);
    }

    var form = document.getElementById('ldn-size-spread-form');
    if (form) {
        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
    }
    refresh();
})();
