/**
 * Diamond Size Checker (merged comparison tool + spread checker) and the
 * shape-hub scale explorer.
 *
 * Both features read the shared size_checker manifest embedded by the
 * renderer in #ldn-size-checker-manifest: per shape×carat percentile slices
 * (length/width/depth/face-up), geometry fill factors for manual L×W entry,
 * and the faceted SVG catalog for overlays.
 *
 * Checker: results are computed only when the Check button is pressed and
 * render in a separate results section (not live-on-input).
 */
(function () {
    'use strict';

    var manifestEl = document.getElementById('ldn-size-checker-manifest');
    if (!manifestEl) {
        return;
    }

    var manifest;
    try {
        manifest = JSON.parse(manifestEl.textContent || '{}');
    } catch (e) {
        return;
    }

    var QUARTER_MM = 24.26;
    var quarterImgUrl = (typeof window.ldnSizeChecker !== 'undefined'
        && window.ldnSizeChecker.quarterImgUrl)
        ? window.ldnSizeChecker.quarterImgUrl
        : '';
    var PERCENTILE_KNOTS = [
        [10, 'p10'],
        [25, 'p25'],
        [50, 'median'],
        [75, 'p75'],
        [90, 'p90']
    ];

    // ------------------------------------------------------------------
    // Shared helpers (mirror Sizing/spread_rank.py)
    // ------------------------------------------------------------------

    function parseNum(raw) {
        var n = parseFloat(raw);
        return isFinite(n) && n > 0 ? n : null;
    }

    function shapeLabel(shape) {
        return (shape || '').replace(/-/g, ' ').replace(/\b\w/g, function (c) {
            return c.toUpperCase();
        });
    }

    function assignCaratBand(carat, ranges) {
        for (var i = 0; i < ranges.length; i++) {
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
        for (var j = 0; j < ranges.length; j++) {
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

    function sizeQualityLabel(pct, band, shape) {
        if (pct === null) {
            return 'Insufficient market data';
        }
        var shapeLbl = shapeLabel(shape);
        var ctx = band + ' carat ' + shapeLbl + 's';
        if (pct >= 99) {
            return 'Top 1% for ' + ctx;
        }
        if (pct >= 95) {
            return 'Top 5% for ' + ctx;
        }
        if (pct >= 90) {
            return 'Top 10% for ' + ctx;
        }
        if (pct <= 1) {
            return 'Bottom 1% for ' + ctx;
        }
        if (pct <= 5) {
            return 'Bottom 5% for ' + ctx;
        }
        if (pct <= 10) {
            return 'Bottom 10% for ' + ctx;
        }
        var rounded = Math.round(pct);
        if (rounded >= 50) {
            return 'Bigger than ' + rounded + '% of ' + ctx;
        }
        return 'Smaller than ' + (100 - rounded) + '% of ' + ctx;
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
        if (!length || !width || width <= 0) {
            return null;
        }
        var ratio = length / width;
        var pclass = classifyProportion(shape, ratio, geo);
        var factor = fillFactor(shape, pclass, geo);
        if (factor === null) {
            return null;
        }
        return length * width * factor;
    }

    function entryFor(shape, caratBand) {
        if (!manifest.entries || caratBand === null) {
            return null;
        }
        return manifest.entries[shape + '|' + caratBand] || null;
    }

    function median(dist) {
        if (!dist || dist.median === undefined || dist.median === null) {
            return null;
        }
        var v = parseFloat(dist.median);
        return isFinite(v) ? v : null;
    }

    function bandsForShape(shape) {
        var bands = manifest.carat_bands || [];
        var out = [];
        for (var i = 0; i < bands.length; i++) {
            if (entryFor(shape, bands[i])) {
                out.push(bands[i]);
            }
        }
        return out;
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ------------------------------------------------------------------
    // Diamond Size Checker
    // ------------------------------------------------------------------

    function initChecker(root) {
        var form = document.getElementById('ldn-size-checker-form');
        var resultsEl = document.getElementById('ldn-size-checker-results');
        var cardsEl = document.getElementById('ldn-checker-cards');
        var comparisonEl = document.getElementById('ldn-checker-comparison');
        if (!form || !resultsEl || !cardsEl || !comparisonEl) {
            return;
        }

        var enableB = document.getElementById('ldn-checker-enable-b');
        var panelBWrap = document.getElementById('ldn-checker-panel-b-wrap');

        function bindModeToggle(prefix) {
            var refFields = document.getElementById('ldn-checker-ref-' + prefix);
            var manualFields = document.getElementById('ldn-checker-manual-' + prefix);
            var radios = form.querySelectorAll('input[name="mode_' + prefix + '"]');
            for (var i = 0; i < radios.length; i++) {
                radios[i].addEventListener('change', function () {
                    var manual = getMode(prefix) === 'manual';
                    if (refFields) {
                        refFields.hidden = manual;
                    }
                    if (manualFields) {
                        manualFields.hidden = !manual;
                    }
                });
            }
        }

        function getMode(prefix) {
            var checked = form.querySelector('input[name="mode_' + prefix + '"]:checked');
            return checked && checked.value === 'manual' ? 'manual' : 'reference';
        }

        function fieldValue(id) {
            var el = document.getElementById(id);
            return el ? el.value : '';
        }

        /**
         * Evaluate one stone panel. Returns null when required inputs are
         * missing; { error: ... } when inputs are present but unusable.
         */
        function evaluateStone(prefix) {
            var shape = fieldValue('ldn-checker-shape-' + prefix);
            if (!shape) {
                return null;
            }
            var geo = manifest.geometry || {};
            var ranges = manifest.carat_band_ranges || [];

            if (getMode(prefix) === 'reference') {
                var band = fieldValue('ldn-checker-band-' + prefix);
                var refEntry = entryFor(shape, band);
                if (!refEntry) {
                    return { error: 'No market data for ' + band + ' ct ' + shapeLabel(shape) + ' yet.' };
                }
                var refLength = median(refEntry.length_mm);
                var refWidth = median(refEntry.width_mm);
                var refFaceup = median(refEntry.faceup_area_mm2);
                var refCarat = parseFloat(band);
                return {
                    mode: 'reference',
                    shape: shape,
                    carat: refCarat,
                    band: band,
                    length: refLength,
                    width: refWidth,
                    depth: median(refEntry.depth_mm),
                    faceup: refFaceup,
                    perCarat: (refFaceup !== null && refCarat > 0) ? refFaceup / refCarat : null,
                    percentile: 50,
                    quality: 'Typical market size (median)',
                    n: refEntry.n || 0,
                    bandNote: ''
                };
            }

            var carat = parseNum(fieldValue('ldn-checker-carat-' + prefix));
            var length = parseNum(fieldValue('ldn-checker-length-' + prefix));
            var width = parseNum(fieldValue('ldn-checker-width-' + prefix));
            var depth = parseNum(fieldValue('ldn-checker-depth-' + prefix));
            if (carat === null || length === null || width === null) {
                return { error: 'Enter carat weight, length and width to check this diamond.' };
            }
            var refBand = resolveReferenceBand(carat, ranges);
            var market = entryFor(shape, refBand);
            var faceup = faceupArea(shape, length, width, geo);
            var pct = (market && faceup !== null)
                ? percentileRank(faceup, market.faceup_area_mm2)
                : null;
            var bandNote = '';
            if (assignCaratBand(carat, ranges) === null && refBand !== null) {
                bandNote = carat + ' ct compared against ' + refBand + ' ct '
                    + shapeLabel(shape) + ' market data.';
            }
            return {
                mode: 'manual',
                shape: shape,
                carat: carat,
                band: refBand,
                length: length,
                width: width,
                depth: depth,
                faceup: faceup,
                perCarat: faceup !== null ? faceup / carat : null,
                percentile: pct,
                quality: sizeQualityLabel(pct, band, shape),
                n: market ? (market.n || 0) : 0,
                marketDepth: market ? median(market.depth_mm) : null,
                bandNote: bandNote
            };
        }

        function stoneCardHtml(stone, title) {
            if (stone.error) {
                return '<div class="ldn-size-checker-card ldn-size-checker-card--error"><h3>'
                    + escapeHtml(title) + '</h3><p>' + escapeHtml(stone.error) + '</p></div>';
            }
            var html = '<div class="ldn-size-checker-card"><h3>' + escapeHtml(title) + '</h3>';
            html += '<p class="ldn-size-checker-card__stone">'
                + escapeHtml(stone.carat + ' carat ' + shapeLabel(stone.shape)) + '</p>';
            html += '<p class="ldn-size-checker-card__quality"><strong>'
                + escapeHtml(stone.quality) + '</strong></p>';
            html += '<ul class="ldn-size-checker-card__stats">';
            if (stone.length !== null && stone.width !== null) {
                var mm = stone.length.toFixed(2) + ' × ' + stone.width.toFixed(2);
                if (stone.depth !== null && stone.depth !== undefined) {
                    mm += ' × ' + stone.depth.toFixed(2);
                }
                html += '<li>Measurements: <strong>' + mm + ' mm</strong></li>';
            }
            if (stone.faceup !== null) {
                html += '<li>Face-up area: <strong>' + stone.faceup.toFixed(2) + ' mm²</strong></li>';
            }
            if (stone.perCarat !== null) {
                html += '<li>Per carat: <strong>' + stone.perCarat.toFixed(2) + ' mm²/ct</strong></li>';
            }
            if (stone.mode === 'manual' && stone.percentile !== null) {
                html += '<li>Size rank: <strong>'
                    + escapeHtml(sizeQualityLabel(stone.percentile, stone.band, stone.shape))
                    + '</strong></li>';
            }
            if (stone.mode === 'manual' && stone.depth !== null && stone.marketDepth !== null) {
                html += '<li>Depth: <strong>' + stone.depth.toFixed(2)
                    + ' mm</strong> (market median ' + stone.marketDepth.toFixed(2) + ' mm)</li>';
            }
            html += '</ul>';
            if (stone.n > 0) {
                html += '<p class="ldn-size-checker-card__note">Based on '
                    + Number(stone.n).toLocaleString() + ' real '
                    + escapeHtml(shapeLabel(stone.shape)) + ' diamonds.</p>';
            }
            if (stone.bandNote) {
                html += '<p class="ldn-size-checker-card__note">' + escapeHtml(stone.bandNote) + '</p>';
            }
            html += '</div>';
            return html;
        }

        function comparisonHtml(a, b) {
            if (!window.LdnFacetedOverlay
                || a.error || b.error
                || a.faceup === null || b.faceup === null
                || a.length === null || b.length === null
            ) {
                return '';
            }
            var fo = window.LdnFacetedOverlay;
            var footnoteParts = [];
            if (a.mode === 'manual' && b.mode === 'manual'
                && a.percentile !== null && b.percentile !== null
            ) {
                var winner = b.percentile > a.percentile ? b : a;
                footnoteParts.push('The ' + winner.carat + ' ct ' + shapeLabel(winner.shape)
                    + ' faces up better for its carat weight.');
            }
            if (a.perCarat !== null && b.perCarat !== null && a.carat !== b.carat) {
                footnoteParts.push('Per carat: ' + a.perCarat.toFixed(2) + ' vs '
                    + b.perCarat.toFixed(2) + ' mm²/ct.');
            }
            var html = fo.renderComparisonBlock({
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
                cssClass: 'ldn-size-compare-svg ldn-size-compare-svg--compact',
                maxPx: 200,
                ariaLabel: 'Face-up size comparison',
                footnote: footnoteParts.join(' ')
            });
            var link = comparisonPageUrl(a, b);
            if (link) {
                html += '<p class="ldn-size-checker-full-link"><a href="' + escapeHtml(link)
                    + '">See the full ' + escapeHtml(a.band + ' ct ' + shapeLabel(a.shape)
                    + ' vs ' + b.band + ' ct ' + shapeLabel(b.shape))
                    + ' comparison page →</a></p>';
            }
            return html;
        }

        /** Crawlable SSR pair page for two reference stones. */
        function comparisonPageUrl(a, b) {
            if (a.mode !== 'reference' || b.mode !== 'reference') {
                return null;
            }
            var base = root.getAttribute('data-compare-base') || '/diamond-size/compare/';
            if (base.slice(-1) !== '/') {
                base += '/';
            }
            var slugs = manifest.slug_shapes || {};
            var sa = (slugs[a.shape] || a.shape) + '-' + a.band + '-carat';
            var sb = (slugs[b.shape] || b.shape) + '-' + b.band + '-carat';
            return base + sa + '-vs-' + sb + '/';
        }

        function runCheck() {
            var a = evaluateStone('a');
            if (a === null) {
                return;
            }
            var compareOn = enableB && enableB.checked;
            var b = compareOn ? evaluateStone('b') : null;

            var cards = stoneCardHtml(a, compareOn ? 'Your diamond' : 'Your diamond vs the market');
            if (b !== null) {
                cards += stoneCardHtml(b, 'Second diamond');
            }
            cardsEl.innerHTML = cards;
            comparisonEl.innerHTML = (b !== null) ? comparisonHtml(a, b) : '';
            resultsEl.hidden = false;
            if (typeof resultsEl.scrollIntoView === 'function') {
                resultsEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        bindModeToggle('a');
        bindModeToggle('b');

        if (enableB && panelBWrap) {
            enableB.addEventListener('change', function () {
                panelBWrap.hidden = !enableB.checked;
            });
        }

        form.addEventListener('submit', function (evt) {
            evt.preventDefault();
            runCheck();
        });
    }

    // ------------------------------------------------------------------
    // Shape-hub scale explorer (carat slider + shape switch, US quarter ref)
    // ------------------------------------------------------------------

    function initScaleExplorer(root) {
        var controls = document.getElementById('ldn-scale-controls');
        var shapeSel = document.getElementById('ldn-scale-shape');
        var slider = document.getElementById('ldn-scale-carat');
        var output = document.getElementById('ldn-scale-carat-out');
        var figure = document.getElementById('ldn-scale-figure');
        if (!controls || !shapeSel || !slider || !output || !figure) {
            return;
        }

        var shapes = manifest.shapes || [];
        var currentShape = root.getAttribute('data-shape') || shapes[0];
        var currentBand = root.getAttribute('data-carat') || '1';
        var bands = bandsForShape(currentShape);
        if (bands.length === 0) {
            return;
        }

        for (var i = 0; i < shapes.length; i++) {
            var opt = document.createElement('option');
            opt.value = shapes[i];
            opt.textContent = shapeLabel(shapes[i]);
            if (shapes[i] === currentShape) {
                opt.selected = true;
            }
            shapeSel.appendChild(opt);
        }

        function syncSlider() {
            bands = bandsForShape(currentShape);
            var idx = bands.indexOf(currentBand);
            if (idx === -1) {
                idx = nearestBandIndex(bands, currentBand);
                currentBand = bands[idx];
            }
            slider.min = 0;
            slider.max = bands.length - 1;
            slider.step = 1;
            slider.value = idx;
            output.textContent = currentBand + ' ct';
        }

        function nearestBandIndex(list, band) {
            var target = parseFloat(band);
            var best = 0;
            var bestDist = null;
            for (var j = 0; j < list.length; j++) {
                var dist = Math.abs(parseFloat(list[j]) - target);
                if (bestDist === null || dist < bestDist) {
                    bestDist = dist;
                    best = j;
                }
            }
            return best;
        }

        function renderFigure() {
            var entry = entryFor(currentShape, currentBand);
            if (!entry) {
                return;
            }
            var length = median(entry.length_mm);
            var width = median(entry.width_mm);
            if (length === null || width === null) {
                return;
            }

            var pxPerMm = 7;
            var gapMm = 4;
            var coinD = QUARTER_MM;
            var canvasWmm = coinD + gapMm + width;
            var canvasHmm = Math.max(coinD, length) + 2;
            var qy = (canvasHmm - coinD) / 2;
            var dy = (canvasHmm - length) / 2;
            var dx = coinD + gapMm;
            var coinCx = coinD / 2;
            var coinCy = qy + coinD / 2;
            var coinR = coinD / 2;

            var stoneMarkup;
            var catalog = manifest.faceted_shapes || {};
            if (window.LdnFacetedOverlay && catalog[currentShape]) {
                stoneMarkup = '<g transform="translate(' + dx + ',' + dy
                    + ') scale(' + width + ',' + length + ')" style="color:#706cc8">'
                    + catalog[currentShape] + '</g>';
            } else {
                stoneMarkup = '<ellipse cx="' + (dx + width / 2) + '" cy="' + (dy + length / 2)
                    + '" rx="' + (width / 2) + '" ry="' + (length / 2)
                    + '" fill="none" stroke="#706cc8" stroke-width="0.2" />';
            }

            var aria = currentBand + ' carat ' + shapeLabel(currentShape)
                + ' (' + width.toFixed(2) + ' × ' + length.toFixed(2)
                + ' mm) next to a US quarter (24.26 mm)';
            var quarterMarkup;
            if (quarterImgUrl) {
                quarterMarkup = '<defs><clipPath id="ldn-scale-quarter-clip">'
                    + '<circle cx="' + coinCx + '" cy="' + coinCy + '" r="' + coinR + '"/>'
                    + '</clipPath></defs>'
                    + '<image href="' + escapeHtml(quarterImgUrl) + '" x="0" y="' + qy
                    + '" width="' + coinD + '" height="' + coinD + '"'
                    + ' clip-path="url(#ldn-scale-quarter-clip)" preserveAspectRatio="xMidYMid meet"/>';
            } else {
                quarterMarkup = '<circle cx="' + coinCx + '" cy="' + coinCy + '" r="' + coinR
                    + '" fill="#e5e0d5" stroke="#b8b0a0" stroke-width="0.15" />'
                    + '<text x="' + coinCx + '" y="' + coinCy + '" text-anchor="middle"'
                    + ' dominant-baseline="central" font-size="' + (coinR * 0.34)
                    + '" fill="#7a7261" font-family="sans-serif">quarter</text>';
            }
            var svg = '<svg viewBox="0 0 ' + canvasWmm + ' ' + canvasHmm + '" width="100%" role="img"'
                + ' class="ldn-size-scale-svg" aria-label="' + escapeHtml(aria) + '">'
                + quarterMarkup
                + stoneMarkup
                + '</svg>';

            var caption = 'Relative actual size (mm): US quarter (24.26 mm) beside the median '
                + currentBand + ' carat ' + shapeLabel(currentShape).toLowerCase()
                + ' — ' + width.toFixed(2) + ' × ' + length.toFixed(2) + ' mm.';

            figure.innerHTML = '<figure class="ldn-size-figure ldn-size-figure--scale">'
                + '<div class="ldn-size-outline ldn-size-outline--scale">' + svg + '</div>'
                + '<figcaption class="ldn-size-figure__caption">' + escapeHtml(caption)
                + '</figcaption></figure>';
        }

        shapeSel.addEventListener('change', function () {
            currentShape = shapeSel.value;
            syncSlider();
            renderFigure();
        });

        slider.addEventListener('input', function () {
            var idx = parseInt(slider.value, 10);
            if (bands[idx] !== undefined) {
                currentBand = bands[idx];
                output.textContent = currentBand + ' ct';
                renderFigure();
            }
        });

        syncSlider();
        renderFigure();
        controls.hidden = false;
    }

    var checkerRoot = document.getElementById('ldn-size-checker');
    if (checkerRoot) {
        initChecker(checkerRoot);
    }

    var explorerRoot = document.getElementById('ldn-size-scale-explorer');
    if (explorerRoot) {
        initScaleExplorer(explorerRoot);
    }
})();
