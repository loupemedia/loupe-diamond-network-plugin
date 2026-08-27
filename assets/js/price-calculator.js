/**
 * Price calculator / position checker (CP 123).
 *
 * Resolves the reader's specification against the cell manifest embedded by
 * LDN_Trait_Price_Calculator. Everything is client-side: the manifest carries
 * every cell for the page, so changing a selector costs no request.
 *
 * Holds no English. All reader-facing strings arrive in manifest.labels so
 * translation stays in PHP.
 */
(function () {
	'use strict';

	var PERCENTILES = ['p10', 'p25', 'p50', 'p75', 'p90'];

	function readManifest(root) {
		var node = root
			? root.querySelector('script.ldn-price-calculator-manifest')
			: document.querySelector('script.ldn-price-calculator-manifest');
		if (!node) {
			return null;
		}
		try {
			return JSON.parse(node.textContent || node.innerText || '');
		} catch (e) {
			return null;
		}
	}

	function money(value, symbol) {
		if (typeof value !== 'number' || !isFinite(value)) {
			return '';
		}
		return symbol + Math.round(value).toLocaleString();
	}

	/**
	 * Digits only. A reader typing "$4,500" or "4500 usd" means 4500.
	 */
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

	function fill(template, tokens) {
		var out = String(template || '');
		Object.keys(tokens).forEach(function (key) {
			out = out.split('%' + key + '%').join(tokens[key]);
		});
		return out;
	}

	function sprintfOne(template, value) {
		return String(template || '').replace('%s', value);
	}

	function gradeOptions(manifest, key) {
		if (Array.isArray(manifest[key]) && manifest[key].length) {
			return manifest[key];
		}
		var legacyKey = key === 'colours' ? 'colour_groups' : (key === 'clarities' ? 'clarity_groups' : '');
		return legacyKey && Array.isArray(manifest[legacyKey]) ? manifest[legacyKey] : [];
	}

	function gradeLabel(manifest, key, gradeKey) {
		var grades = gradeOptions(manifest, key);
		for (var i = 0; i < grades.length; i += 1) {
			if (grades[i] && String(grades[i].key) === String(gradeKey)) {
				return grades[i].label || gradeKey;
			}
		}
		return gradeKey;
	}

	function gradeValueAtIndex(manifest, key, index) {
		var grades = gradeOptions(manifest, key);
		var entry = grades[index];
		return entry && entry.key ? String(entry.key) : '';
	}

	function gradeIndexForValue(manifest, key, value) {
		var grades = gradeOptions(manifest, key);
		for (var i = 0; i < grades.length; i += 1) {
			if (grades[i] && String(grades[i].key) === String(value)) {
				return i;
			}
		}
		return 0;
	}

	function pooledKey(manifest) {
		return manifest.pooled_cut_key || 'ALL';
	}

	function specLabel(manifest, selection) {
		var labels = manifest.labels;
		var parts = [];
		parts.push(sprintfOne(labels.colour, gradeLabel(manifest, 'colours', selection.colour)));
		parts.push(sprintfOne(labels.clarity, gradeLabel(manifest, 'clarities', selection.clarity)));
		if (manifest.has_cut_dimension) {
			parts.push(
				selection.cut === pooledKey(manifest)
					? labels.anyCut
					: sprintfOne(labels.cut, selection.cut)
			);
		}
		return parts.join(', ');
	}

	function findCell(manifest, selection) {
		var cells = manifest.cells;
		if (!cells || !cells[selection.colour]) {
			return null;
		}
		var byClarity = cells[selection.colour][selection.clarity];
		if (!byClarity) {
			return null;
		}
		var cell = byClarity[selection.cut];
		if (!cell || !cell.percentiles) {
			return null;
		}
		return cell;
	}

	/**
 * Which band a quoted price falls in. Bands, not an interpolated percentile:
 * five published points cannot support that precision.
 *
 * Canonical implementation: sparklescore/scoring/verdict_band.py — keep in sync.
 */
	function verdictKey(percentiles, quote) {
		var values = PERCENTILES.map(function (key) {
			return typeof percentiles[key] === 'number' ? percentiles[key] : null;
		});
		if (values[0] !== null && quote < values[0]) {
			return 'below_p10';
		}
		if (values[4] !== null && quote > values[4]) {
			return 'above_p90';
		}
		var bands = ['p10_p25', 'p25_p50', 'p50_p75', 'p75_p90'];
		for (var i = 0; i < bands.length; i += 1) {
			var lower = values[i];
			var upper = values[i + 1];
			if (lower !== null && upper !== null && quote >= lower && quote <= upper) {
				return bands[i];
			}
		}
		return null;
	}

	/**
	 * Hybrid slot: percentile band (p10–p25 … p75–p90) plus sub-position 0–3 within
	 * the band. Eighteen interior slots + below/above extremes.
	 */
	function quoteAnalysisKey(percentiles, quote) {
		var knots = ['p10', 'p25', 'p50', 'p75', 'p90'];
		var values = knots.map(function (key) {
			return typeof percentiles[key] === 'number' ? percentiles[key] : null;
		});
		if (values.indexOf(null) !== -1) {
			return null;
		}
		if (quote < values[0]) {
			return 'below_p10';
		}
		if (quote > values[4]) {
			return 'above_p90';
		}
		var bands = ['p10_p25', 'p25_p50', 'p50_p75', 'p75_p90'];
		for (var i = 0; i < bands.length; i += 1) {
			var lower = values[i];
			var upper = values[i + 1];
			if (quote >= lower && quote <= upper) {
				var ratio = (quote - lower) / (upper - lower);
				var sub = Math.min(3, Math.floor(ratio * 4));
				return bands[i] + '_' + sub;
			}
		}
		return null;
	}

	function quoteAnalysisText(labels, percentiles, quote) {
		if (quote === null || !labels || !labels.quoteAnalysis) {
			return '';
		}
		var key = quoteAnalysisKey(percentiles, quote);
		return key && labels.quoteAnalysis[key] ? labels.quoteAnalysis[key] : '';
	}

	function paragraph(text, className) {
		var p = document.createElement('p');
		if (className) {
			p.className = className;
		}
		p.textContent = text;
		return p;
	}

	function labelledPrice(className, label, value) {
		var node = document.createElement('span');
		node.className = className;
		var labelNode = document.createElement('span');
		labelNode.className = 'ldn-price-calculator__bound-label';
		labelNode.textContent = label;
		var valueNode = document.createElement('span');
		valueNode.className = className.indexOf('headline') !== -1
			? 'ldn-price-calculator__headline-value'
			: 'ldn-price-calculator__bound-value';
		valueNode.textContent = value;
		node.appendChild(labelNode);
		node.appendChild(valueNode);
		return node;
	}

	function priceRow(labels, low, centre, high) {
		var row = document.createElement('div');
		row.className = 'ldn-price-calculator__price-row';
		if (low) {
			row.appendChild(labelledPrice(
				'ldn-price-calculator__bound ldn-price-calculator__bound--low',
				labels.boundLow || 'Lower end',
				low
			));
		}
		if (centre) {
			row.appendChild(labelledPrice(
				'ldn-price-calculator__headline',
				labels.boundTypical || 'Typical',
				centre
			));
		}
		if (high) {
			row.appendChild(labelledPrice(
				'ldn-price-calculator__bound ldn-price-calculator__bound--high',
				labels.boundHigh || 'Higher end',
				high
			));
		}
		return row;
	}

	function sampleSentence(manifest, selection, cell) {
		var labels = manifest.labels || {};
		var n = cell && typeof cell.sample_size === 'number' ? cell.sample_size : 0;
		if (n < 1) {
			return '';
		}
		var count = n.toLocaleString();
		var spec = specLabel(manifest, selection);
		var template;
		if (spec) {
			template = n === 1 ? labels.basedOnOne : labels.basedOnMany;
			return fill(template || '', { count: count, spec: spec });
		}
		template = n === 1 ? labels.basedOnOneBare : labels.basedOnManyBare;
		return fill(template || '', { count: count });
	}

	function render(manifest, region, selection, quote, root) {
		var labels = manifest.labels;
		var symbol = labels.currency || '';
		var cell = findCell(manifest, selection);
		var onDestination = root && root.closest('[data-ldn-calculator-destination]');

		region.textContent = '';

		if (!cell) {
			region.appendChild(paragraph(labels.noCell, 'ldn-price-calculator__unavailable'));
			return;
		}

		var p = cell.percentiles;

		if (typeof p.p50 === 'number') {
			var low = typeof p.p10 === 'number' ? money(p.p10, symbol) : '';
			var high = typeof p.p90 === 'number' ? money(p.p90, symbol) : '';
			region.appendChild(priceRow(labels, low, money(p.p50, symbol), high));
		}

		var sample = sampleSentence(manifest, selection, cell);
		if (sample) {
			region.appendChild(paragraph(sample, 'ldn-price-calculator__sample'));
		}

		// Destination page renders quote analysis below the spectrum bar instead.
		if (quote !== null && !onDestination) {
			var analysis = quoteAnalysisText(labels, p, quote);
			if (analysis) {
				region.appendChild(paragraph(analysis, 'ldn-price-calculator__analysis'));
				if (typeof window.ldnTrackCalculatorSubmit === 'function') {
					window.ldnTrackCalculatorSubmit(true);
				}
			}
		}
	}

	function syncGradeSliderStops(sliderRoot, index) {
		var stops = sliderRoot.querySelectorAll('.ldn-grade-slider__stop');
		stops.forEach(function (stop, stopIndex) {
			stop.classList.toggle('ldn-grade-slider__stop--active', stopIndex === index);
		});
	}

	function initGradeSlider(root, manifest, name, manifestKey, onChange) {
		var sliderRoot = root.querySelector('[data-ldn-grade-slider="' + name + '"]');
		if (!sliderRoot) {
			return null;
		}
		var input = sliderRoot.querySelector('[data-ldn-price-calculator-input="' + name + '"]');
		if (!input) {
			return null;
		}

		function setIndex(index) {
			var value = gradeValueAtIndex(manifest, manifestKey, index);
			if (!value) {
				return;
			}
			input.value = String(index);
			syncGradeSliderStops(sliderRoot, index);
			onChange(value);
		}

		function onInput() {
			setIndex(parseInt(input.value, 10) || 0);
		}

		// input + change: some optimizers / mobile browsers only fire one of them.
		input.addEventListener('input', onInput);
		input.addEventListener('change', onInput);

		sliderRoot.querySelectorAll('.ldn-grade-slider__stop').forEach(function (stop) {
			stop.addEventListener('click', function () {
				var value = stop.getAttribute('data-grade-value');
				if (!value) {
					return;
				}
				setIndex(gradeIndexForValue(manifest, manifestKey, value));
			});
		});

		return input;
	}

	/**
	 * Close field-help tooltips on outside click or Escape.
	 * Tips also open on CSS :hover / :focus-within; click keeps them sticky on touch.
	 * Bound once; works for panels remounted via innerHTML.
	 */
	function initFieldHelpDismiss() {
		if (document.documentElement.getAttribute('data-ldn-field-help-wired') === '1') {
			return;
		}
		document.documentElement.setAttribute('data-ldn-field-help-wired', '1');

		document.addEventListener('click', function (event) {
			var target = event.target;
			if (!target || typeof target.closest !== 'function') {
				return;
			}
			var open = document.querySelectorAll('details.ldn-field-help[open]');
			if (!open.length) {
				return;
			}
			for (var i = 0; i < open.length; i += 1) {
				if (!open[i].contains(target)) {
					open[i].removeAttribute('open');
				}
			}
		});

		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape') {
				return;
			}
			document.querySelectorAll('details.ldn-field-help[open]').forEach(function (node) {
				node.removeAttribute('open');
			});
		});
	}

	function initCalculator(root) {
		var manifest = readManifest(root);
		if (!manifest || !manifest.labels || !manifest.cells) {
			return;
		}
		if (!root) {
			return;
		}
		var region = root.querySelector('[data-ldn-price-calculator-result]');
		if (!region) {
			return;
		}

		// Remounted panels are new nodes; skip only if this exact root was wired.
		if (root.getAttribute('data-ldn-calc-wired') === '1') {
			return;
		}
		root.setAttribute('data-ldn-calc-wired', '1');

		var state = {
			colour: manifest.default_cell && manifest.default_cell.color
				? manifest.default_cell.color
				: gradeValueAtIndex(manifest, 'colours', 0),
			clarity: manifest.default_cell && manifest.default_cell.clarity
				? manifest.default_cell.clarity
				: gradeValueAtIndex(manifest, 'clarities', 0),
			cut: manifest.default_cell && manifest.default_cell.cut_grade
				? manifest.default_cell.cut_grade
				: pooledKey(manifest)
		};

		var cutInput = root.querySelector('[data-ldn-price-calculator-input="cut"]');
		var quoteField = root.querySelector('[data-ldn-price-calculator-quote]');

		function update() {
			render(
				manifest,
				region,
				state,
				quoteField ? parseQuote(quoteField.value) : null,
				root
			);
		}

		initGradeSlider(root, manifest, 'colour', 'colours', function (value) {
			state.colour = value;
			update();
		});
		initGradeSlider(root, manifest, 'clarity', 'clarities', function (value) {
			state.clarity = value;
			update();
		});
		initGradeSlider(root, manifest, 'cut', 'cuts', function (value) {
			state.cut = value;
			update();
		});

		// Legacy select (pre-slider markup) if a cached panel still ships one.
		if (cutInput && cutInput.tagName === 'SELECT') {
			cutInput.addEventListener('change', function () {
				state.cut = cutInput.value;
				update();
			});
		}
		if (quoteField) {
			quoteField.addEventListener('input', update);
		}
	}

	function initHub() {
		var hub = document.querySelector('[data-ldn-price-calculator-hub]');
		if (!hub) {
			return;
		}
		var pills = hub.querySelectorAll('[data-ldn-shape-calc-pill]');
		var panels = hub.querySelectorAll('[data-ldn-shape-calc-panel]');
		pills.forEach(function (pill) {
			pill.addEventListener('click', function () {
				var shape = pill.getAttribute('data-ldn-shape-calc-pill');
				if (!shape) {
					return;
				}
				pills.forEach(function (other) {
					other.classList.toggle('ldn-shape-picker__option--active', other === pill);
				});
				panels.forEach(function (panel) {
					var active = panel.getAttribute('data-ldn-shape-calc-panel') === shape;
					panel.classList.toggle('ldn-shape-calc-panel--active', active);
					if (active) {
						panel.removeAttribute('hidden');
					} else {
						panel.setAttribute('hidden', 'hidden');
					}
				});
			});
		});
	}

	function init() {
		initFieldHelpDismiss();
		document.querySelectorAll('[data-ldn-price-calculator]').forEach(initCalculator);
		initHub();
	}

	window.ldnInitPriceCalculator = initCalculator;
	window.ldnQuoteAnalysisKey = quoteAnalysisKey;
	window.ldnQuoteAnalysisText = quoteAnalysisText;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
	// WP Rocket Delay JS may finish after first interaction; re-wire if needed.
	window.addEventListener('rocket-allScriptsLoaded', init);
})();
