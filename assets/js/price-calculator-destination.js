/**
 * Calculator destination page — fetch panel per selection + quote spectrum.
 */
(function () {
	'use strict';

	function apiBase() {
		if (window.ldnCalculatorDestination && window.ldnCalculatorDestination.restUrl) {
			return window.ldnCalculatorDestination.restUrl;
		}
		return '/wp-json/ldn/v1/';
	}

	var panelCache = Object.create(null);
	var panelAbort = null;

	function panelCacheKey(country, type, carat, shape) {
		return [country, type, carat, shape].join('|');
	}

	function fetchPanel(country, type, carat, shape) {
		var key = panelCacheKey(country, type, carat, shape);
		if (panelCache[key]) {
			return Promise.resolve(panelCache[key]);
		}
		if (panelAbort) {
			panelAbort.abort();
		}
		panelAbort = typeof AbortController !== 'undefined' ? new AbortController() : null;
		var url = apiBase() + 'price-calculator-panel'
			+ '?country=' + encodeURIComponent(country)
			+ '&type=' + encodeURIComponent(type)
			+ '&carat=' + encodeURIComponent(carat)
			+ '&shape=' + encodeURIComponent(shape);
		var opts = { credentials: 'same-origin' };
		if (panelAbort) {
			opts.signal = panelAbort.signal;
		}
		return fetch(url, opts).then(function (response) {
			if (!response.ok) {
				throw new Error('panel unavailable');
			}
			return response.json();
		}).then(function (payload) {
			if (payload && payload.html) {
				panelCache[key] = payload;
			}
			return payload;
		});
	}

	function money(value, symbol) {
		if (typeof value !== 'number' || !isFinite(value)) {
			return '';
		}
		return symbol + Math.round(value).toLocaleString();
	}

	function quotePositionPercent(percentiles, quote) {
		var low = percentiles.p10;
		var high = percentiles.p90;
		if (typeof low !== 'number' || typeof high !== 'number' || high <= low) {
			return 50;
		}
		var ratio = (quote - low) / (high - low);
		return Math.max(0, Math.min(100, ratio * 100));
	}

	var PERCENTILES = ['p10', 'p25', 'p50', 'p75', 'p90'];

	function quoteAnalysisText(labels, percentiles, quote) {
		if (typeof window.ldnQuoteAnalysisText === 'function') {
			return window.ldnQuoteAnalysisText(labels, percentiles, quote);
		}
		return '';
	}

	function renderSpectrum(region, percentiles, quote, symbol, labels) {
		var existing = region.querySelector('.ldn-price-calculator__spectrum');
		if (existing) {
			existing.remove();
		}
		if (quote === null || !percentiles) {
			return;
		}
		var low = percentiles.p10;
		var high = percentiles.p90;
		if (typeof low !== 'number' || typeof high !== 'number') {
			return;
		}

		var position = quotePositionPercent(percentiles, quote);
		var analysis = quoteAnalysisText(labels, percentiles, quote);
		var spectrum = document.createElement('div');
		spectrum.className = 'ldn-price-calculator__spectrum';
		spectrum.innerHTML = ''
			+ '<div class="ldn-price-calculator__spectrum-track">'
			+ '<span class="ldn-price-calculator__spectrum-bound ldn-price-calculator__spectrum-bound--low">'
			+ money(low, symbol) + '</span>'
			+ '<span class="ldn-price-calculator__spectrum-line" aria-hidden="true">'
			+ '<span class="ldn-price-calculator__spectrum-marker" style="left:' + position + '%"></span>'
			+ '</span>'
			+ '<span class="ldn-price-calculator__spectrum-bound ldn-price-calculator__spectrum-bound--high">'
			+ money(high, symbol) + '</span>'
			+ '</div>'
			+ '<p class="ldn-price-calculator__spectrum-quote">Your quote: <strong>'
			+ money(quote, symbol) + '</strong></p>';
		if (analysis) {
			var analysisEl = document.createElement('p');
			analysisEl.className = 'ldn-price-calculator__spectrum-analysis';
			analysisEl.textContent = analysis;
			spectrum.appendChild(analysisEl);
		}
		region.appendChild(spectrum);
		if (typeof window.ldnTrackCalculatorSubmit === 'function') {
			window.ldnTrackCalculatorSubmit(true);
		}
	}

	function parseQuote(raw) {
		if (typeof raw !== 'string') {
			return null;
		}
		var cleaned = raw.replace(/[^0-9.]/g, '');
		if (cleaned === '') {
			return null;
		}
		var value = parseFloat(cleaned);
		return isFinite(value) && value > 0 ? value : null;
	}

	function readManifest(calcRoot) {
		var node = calcRoot.querySelector('script.ldn-price-calculator-manifest');
		if (!node) {
			return null;
		}
		try {
			return JSON.parse(node.textContent || node.innerText || '');
		} catch (e) {
			return null;
		}
	}

	function gradeValueAtIndex(manifest, key, index) {
		var grades = manifest[key] || [];
		if (!grades.length && key === 'colours' && Array.isArray(manifest.colour_groups)) {
			grades = manifest.colour_groups;
		}
		if (!grades.length && key === 'clarities' && Array.isArray(manifest.clarity_groups)) {
			grades = manifest.clarity_groups;
		}
		var entry = grades[index];
		return entry && entry.key ? String(entry.key) : '';
	}

	function currentCell(manifest, calcRoot) {
		if (!manifest || !manifest.cells) {
			return null;
		}
		var colourInput = calcRoot.querySelector('[data-ldn-price-calculator-input="colour"]');
		var clarityInput = calcRoot.querySelector('[data-ldn-price-calculator-input="clarity"]');
		var cutInput = calcRoot.querySelector('[data-ldn-price-calculator-input="cut"]');
		var colour = manifest.default_cell && manifest.default_cell.color
			? manifest.default_cell.color
			: gradeValueAtIndex(manifest, 'colours', 0);
		var clarity = manifest.default_cell && manifest.default_cell.clarity
			? manifest.default_cell.clarity
			: gradeValueAtIndex(manifest, 'clarities', 0);
		var cut = manifest.pooled_cut_key || 'ALL';
		if (colourInput) {
			colour = gradeValueAtIndex(manifest, 'colours', parseInt(colourInput.value, 10) || 0) || colour;
		}
		if (clarityInput) {
			clarity = gradeValueAtIndex(manifest, 'clarities', parseInt(clarityInput.value, 10) || 0) || clarity;
		}
		if (cutInput) {
			// Range slider stores an index; a legacy <select> stores the grade key.
			if (cutInput.tagName === 'SELECT') {
				cut = cutInput.value || cut;
			} else {
				var cuts = Array.isArray(manifest.cuts) ? manifest.cuts : [];
				var cutEntry = cuts[parseInt(cutInput.value, 10) || 0];
				if (cutEntry && cutEntry.key) {
					cut = String(cutEntry.key);
				}
			}
		}
		var byClarity = manifest.cells[colour];
		if (!byClarity) {
			return null;
		}
		var byCut = byClarity[clarity];
		if (!byCut) {
			return null;
		}
		return byCut[cut] || null;
	}

	function cellMedian(manifest, colour, clarity, cut) {
		var byClarity = manifest.cells && manifest.cells[colour];
		var byCut = byClarity && byClarity[clarity];
		var cell = byCut && byCut[cut];
		if (!cell || !cell.percentiles || typeof cell.percentiles.p50 !== 'number') {
			return null;
		}
		return cell.percentiles.p50;
	}

	function optionIndex(options, value) {
		for (var i = 0; i < options.length; i += 1) {
			if (options[i].value === value) {
				return i;
			}
		}
		return null;
	}

	function gradeOptions(manifest, key) {
		var grades = manifest[key] || [];
		var options = [];
		for (var i = 0; i < grades.length; i += 1) {
			if (!grades[i] || !grades[i].key) {
				continue;
			}
			options.push({
				value: String(grades[i].key),
				label: grades[i].label ? String(grades[i].label) : String(grades[i].key),
			});
		}
		return options;
	}

	function interpolateDriverTemplate(template, altLabel, currentLabel, dimensionWord, pct) {
		return String(template)
			.replace(/%alt%/g, altLabel)
			.replace(/%current%/g, currentLabel)
			.replace(/%dimension%/g, dimensionWord)
			.replace(/%pct%/g, String(pct));
	}

	function driverDeltaLine(base, alt, currentLabel, altLabel, dimensionWord, labels) {
		if (typeof alt !== 'number' || alt <= 0) {
			return null;
		}
		var pct = Math.round(((alt - base) / base) * 100);
		if (pct === 0) {
			return null;
		}
		if (!labels) {
			return null;
		}
		if (pct > 0 && labels.driverIncrease) {
			return interpolateDriverTemplate(
				labels.driverIncrease,
				altLabel,
				currentLabel,
				dimensionWord,
				Math.abs(pct)
			);
		}
		if (pct < 0 && labels.driverDecrease) {
			return interpolateDriverTemplate(
				labels.driverDecrease,
				altLabel,
				currentLabel,
				dimensionWord,
				Math.abs(pct)
			);
		}
		return null;
	}

	function selectedGrades(manifest, calcRoot) {
		var colourInput = calcRoot.querySelector('[data-ldn-price-calculator-input="colour"]');
		var clarityInput = calcRoot.querySelector('[data-ldn-price-calculator-input="clarity"]');
		var cutInput = calcRoot.querySelector('[data-ldn-price-calculator-input="cut"]');
		var colours = gradeOptions(manifest, 'colours');
		var clarities = gradeOptions(manifest, 'clarities');
		var colour = manifest.default_cell && manifest.default_cell.color
			? manifest.default_cell.color
			: (colours[0] ? colours[0].value : '');
		var clarity = manifest.default_cell && manifest.default_cell.clarity
			? manifest.default_cell.clarity
			: (clarities[0] ? clarities[0].value : '');
		var cut = manifest.pooled_cut_key || 'ALL';
		if (colourInput) {
			colour = gradeValueAtIndex(manifest, 'colours', parseInt(colourInput.value, 10) || 0) || colour;
		}
		if (clarityInput) {
			clarity = gradeValueAtIndex(manifest, 'clarities', parseInt(clarityInput.value, 10) || 0) || clarity;
		}
		if (cutInput) {
			var cuts = Array.isArray(manifest.cuts) ? manifest.cuts : [];
			var cutEntry = cuts[parseInt(cutInput.value, 10) || 0];
			if (cutEntry && cutEntry.key) {
				cut = String(cutEntry.key);
			}
		}
		return { colour: colour, clarity: clarity, cut: cut };
	}

	function buildDriverLines(manifest, calcRoot) {
		if (!manifest || !manifest.cells) {
			return [];
		}
		var grades = selectedGrades(manifest, calcRoot);
		var base = cellMedian(manifest, grades.colour, grades.clarity, grades.cut);
		if (base === null || base <= 0) {
			return [];
		}
		var lines = [];
		var colours = gradeOptions(manifest, 'colours');
		var clarities = gradeOptions(manifest, 'clarities');
		var colorIdx = optionIndex(colours, grades.colour);
		var clarityIdx = optionIndex(clarities, grades.clarity);
		var colorWord = manifest.labels && manifest.labels.colorWord
			? String(manifest.labels.colorWord).toLowerCase()
			: 'color';
		var labels = manifest.labels || null;

		if (colorIdx !== null && colorIdx > 0) {
			var betterColour = colours[colorIdx - 1];
			var colourLine = driverDeltaLine(
				base,
				cellMedian(manifest, betterColour.value, grades.clarity, grades.cut),
				colours[colorIdx].label,
				betterColour.label,
				colorWord,
				labels
			);
			if (colourLine) {
				lines.push(colourLine);
			}
		}

		if (clarityIdx !== null && clarityIdx > 0) {
			var betterClarity = clarities[clarityIdx - 1];
			var clarityLine = driverDeltaLine(
				base,
				cellMedian(manifest, grades.colour, betterClarity.value, grades.cut),
				clarities[clarityIdx].label,
				betterClarity.label,
				'clarity',
				labels
			);
			if (clarityLine) {
				lines.push(clarityLine);
			}
		}

		if (manifest.has_cut_dimension && Array.isArray(manifest.cuts) && manifest.cuts.length) {
			var pooled = manifest.pooled_cut_key || 'ALL';
			var cutOptions = [{ value: pooled, label: 'Any' }];
			for (var c = manifest.cuts.length - 1; c >= 0; c -= 1) {
				cutOptions.push({
					value: String(manifest.cuts[c].key),
					label: manifest.cuts[c].label ? String(manifest.cuts[c].label) : String(manifest.cuts[c].key),
				});
			}
			var cutIdx = optionIndex(cutOptions, grades.cut);
			if (cutIdx !== null && cutIdx > 0 && grades.cut !== pooled) {
				var betterCut = cutOptions[cutIdx - 1];
				if (betterCut.value === pooled && cutOptions[cutIdx - 2]) {
					betterCut = cutOptions[cutIdx - 2];
				}
				var cutLine = driverDeltaLine(
					base,
					cellMedian(manifest, grades.colour, grades.clarity, betterCut.value),
					cutOptions[cutIdx].label,
					betterCut.label,
					'cut',
					labels
				);
				if (cutLine) {
					lines.push(cutLine);
				}
			} else if (cutIdx === 0 || grades.cut === pooled) {
				var nextCut = cutOptions[1];
				if (nextCut) {
					var anyCutLine = driverDeltaLine(
						base,
						cellMedian(manifest, grades.colour, grades.clarity, nextCut.value),
						'Any',
						nextCut.label,
						'cut',
						labels
					);
					if (anyCutLine) {
						lines.push(anyCutLine);
					}
				}
			}
		}

		return lines;
	}

	function refreshDrivers(calcRoot) {
		if (!calcRoot) {
			return;
		}
		var destination = calcRoot.closest('[data-ldn-calculator-destination]');
		if (!destination) {
			return;
		}
		var page = destination.closest('.ldn-calculator-destination-page');
		var block = page
			? page.querySelector('[data-ldn-calculator-drivers]')
			: document.querySelector('[data-ldn-calculator-drivers]');
		var list = block && block.querySelector('[data-ldn-calculator-drivers-list]');
		if (!list) {
			return;
		}
		var manifest = readManifest(calcRoot);
		var lines = buildDriverLines(manifest, calcRoot);
		list.innerHTML = '';
		for (var i = 0; i < lines.length; i += 1) {
			var item = document.createElement('li');
			item.textContent = lines[i];
			list.appendChild(item);
		}
	}

	function wireQuoteSpectrum(calcRoot) {
		// Absent whenever the panel resolved to the unavailable message, which is
		// the common case while only some shapes have published cells.
		if (!calcRoot) {
			return;
		}
		var quoteField = calcRoot.querySelector('[data-ldn-price-calculator-quote]');
		var region = calcRoot.querySelector('[data-ldn-price-calculator-result]');
		if (!quoteField || !region) {
			return;
		}

		function updateSpectrum() {
			var manifest = readManifest(calcRoot);
			var labels = manifest && manifest.labels ? manifest.labels : null;
			var symbol = labels && labels.currency ? labels.currency : '';
			var quote = parseQuote(quoteField.value);
			var cell = currentCell(manifest, calcRoot);
			if (!cell || !cell.percentiles) {
				renderSpectrum(region, null, null, symbol, labels);
				return;
			}
			renderSpectrum(region, cell.percentiles, quote, symbol, labels);
			refreshDrivers(calcRoot);
		}

		quoteField.addEventListener('input', updateSpectrum);
		calcRoot.addEventListener('input', updateSpectrum);
		calcRoot.addEventListener('change', updateSpectrum);
		updateSpectrum();
	}

	function mountPanel(root, html) {
		var tool = root.querySelector('[data-ldn-calculator-tool]');
		if (!tool) {
			return;
		}
		tool.innerHTML = html;
		var calcRoot = tool.querySelector('[data-ldn-price-calculator]');
		if (calcRoot && typeof window.ldnInitPriceCalculator === 'function') {
			window.ldnInitPriceCalculator(calcRoot);
		}
		wireQuoteSpectrum(calcRoot);
		refreshDrivers(calcRoot);
	}

	function initDestination(root) {
		if (root.getAttribute('data-ldn-destination-wired') === '1') {
			return;
		}
		root.setAttribute('data-ldn-destination-wired', '1');

		var country = root.getAttribute('data-ldn-calculator-country') || 'us';
		var typeInputs = root.querySelectorAll('[data-ldn-calculator-type]');
		var caratField = root.querySelector('[data-ldn-calculator-carat]');
		var caratSlider = root.querySelector('[data-ldn-calculator-carat-slider]');
		var shapeInputs = root.querySelectorAll('[data-ldn-calculator-shape]');
		if (!typeInputs.length || !caratField || !shapeInputs.length) {
			return;
		}

		function currentShape() {
			for (var i = 0; i < shapeInputs.length; i++) {
				if (shapeInputs[i].checked) {
					return shapeInputs[i].value;
				}
			}
			return shapeInputs[0].value;
		}

		function currentType() {
			for (var i = 0; i < typeInputs.length; i++) {
				if (typeInputs[i].checked) {
					return typeInputs[i].value;
				}
			}
			return typeInputs[0].value;
		}

		function clampCarat(raw) {
			var value = parseFloat(raw);
			var min = parseFloat(caratField.getAttribute('min')) || 0.3;
			var max = parseFloat(caratField.getAttribute('max')) || 12;
			if (!isFinite(value) || value <= 0) {
				return null;
			}
			return Math.min(max, Math.max(min, value));
		}

		function syncCaratControls(source) {
			var value = clampCarat(source.value);
			if (value === null) {
				return null;
			}
			// Number keeps hundredths for precision; slider snaps to its step.
			caratField.value = String(Math.round(value * 100) / 100);
			if (caratSlider) {
				caratSlider.value = String(value);
			}
			return value;
		}

		var calcRoot = root.querySelector('[data-ldn-price-calculator]');
		wireQuoteSpectrum(calcRoot);
		refreshDrivers(calcRoot);

		var timer = null;
		var lastKey = null;

		function refreshNow() {
			var carat = clampCarat(caratField.value);
			if (carat === null) {
				return;
			}
			var type = currentType();
			var shape = currentShape();
			var caratStr = String(carat);
			var key = panelCacheKey(country, type, caratStr, shape);
			if (key === lastKey && root.querySelector('[data-ldn-price-calculator]')) {
				return;
			}
			fetchPanel(country, type, caratStr, shape)
				.then(function (payload) {
					if (!payload || !payload.html) {
						throw new Error('empty panel');
					}
					lastKey = key;
					mountPanel(root, payload.html);
				})
				.catch(function (err) {
					if (err && err.name === 'AbortError') {
						return;
					}
					var tool = root.querySelector('[data-ldn-calculator-tool]');
					if (tool) {
						tool.innerHTML = '<p class="ldn-price-calculator__unavailable">'
							+ 'We do not have calculator data for that specification yet.</p>';
					}
				});
		}

		function scheduleRefresh(delayMs) {
			if (timer) {
				clearTimeout(timer);
			}
			var wait = typeof delayMs === 'number' ? delayMs : 120;
			timer = setTimeout(refreshNow, wait);
		}

		for (var t = 0; t < typeInputs.length; t++) {
			typeInputs[t].addEventListener('change', function () {
				scheduleRefresh(0);
			});
		}
		for (var i = 0; i < shapeInputs.length; i++) {
			shapeInputs[i].addEventListener('change', function () {
				scheduleRefresh(0);
			});
		}
		caratField.addEventListener('input', function () {
			syncCaratControls(caratField);
			scheduleRefresh(180);
		});
		caratField.addEventListener('change', function () {
			syncCaratControls(caratField);
			scheduleRefresh(0);
		});
		if (caratSlider) {
			caratSlider.addEventListener('input', function () {
				syncCaratControls(caratSlider);
				scheduleRefresh(220);
			});
			caratSlider.addEventListener('change', function () {
				syncCaratControls(caratSlider);
				scheduleRefresh(0);
			});
			caratSlider.addEventListener('pointerup', function () {
				syncCaratControls(caratSlider);
				scheduleRefresh(0);
			});
		}
	}

	function init() {
		document.querySelectorAll('[data-ldn-calculator-destination]').forEach(initDestination);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
	window.addEventListener('rocket-allScriptsLoaded', init);
})();
