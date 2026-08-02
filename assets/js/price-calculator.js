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

	function readManifest() {
		var node = document.getElementById('ldn-price-calculator-manifest');
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

	function groupLabel(manifest, key, groupKey) {
		var groups = manifest[key];
		if (!Array.isArray(groups)) {
			return groupKey;
		}
		for (var i = 0; i < groups.length; i += 1) {
			if (groups[i] && String(groups[i].key) === String(groupKey)) {
				return groups[i].label || groupKey;
			}
		}
		return groupKey;
	}

	function pooledKey(manifest) {
		return manifest.pooled_cut_key || 'ALL';
	}

	function specLabel(manifest, selection) {
		var labels = manifest.labels;
		var parts = [];
		parts.push(sprintfOne(labels.colour, groupLabel(manifest, 'colour_groups', selection.colour)));
		parts.push(sprintfOne(labels.clarity, groupLabel(manifest, 'clarity_groups', selection.clarity)));
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

	function paragraph(text, className) {
		var p = document.createElement('p');
		if (className) {
			p.className = className;
		}
		p.textContent = text;
		return p;
	}

	function render(manifest, region, selection, quote) {
		var labels = manifest.labels;
		var symbol = labels.currency || '';
		var cell = findCell(manifest, selection);

		region.textContent = '';

		if (!cell) {
			region.appendChild(paragraph(labels.noCell, 'ldn-price-calculator__unavailable'));
			return;
		}

		var p = cell.percentiles;

		if (typeof p.p50 === 'number') {
			region.appendChild(
				paragraph(
					fill(labels.typical, { price: money(p.p50, symbol) }),
					'ldn-price-calculator__headline'
				)
			);
		}

		if (quote !== null) {
			var key = verdictKey(p, quote);
			if (key && labels.verdicts && labels.verdicts[key]) {
				region.appendChild(paragraph(labels.verdicts[key], 'ldn-price-calculator__verdict'));
			}
		}

		if (typeof p.p25 === 'number' && typeof p.p75 === 'number') {
			region.appendChild(
				paragraph(
					fill(labels.halfBetween, {
						low: money(p.p25, symbol),
						high: money(p.p75, symbol)
					})
				)
			);
		}

		if (typeof cell.sample_size === 'number' && cell.sample_size > 0) {
			var basis = cell.sample_size === 1 && labels.basedOnOne
				? labels.basedOnOne
				: labels.basedOn;
			region.appendChild(
				paragraph(
					fill(basis, {
						count: cell.sample_size.toLocaleString(),
						spec: specLabel(manifest, selection)
					}),
					'ldn-price-calculator__basis'
				)
			);
		}
	}

	function init() {
		var manifest = readManifest();
		if (!manifest || !manifest.labels || !manifest.cells) {
			return;
		}
		var root = document.querySelector('[data-ldn-price-calculator]');
		if (!root) {
			return;
		}
		var region = root.querySelector('[data-ldn-price-calculator-result]');
		if (!region) {
			return;
		}

		var inputs = {};
		['colour', 'clarity', 'cut'].forEach(function (name) {
			inputs[name] = root.querySelector('[data-ldn-price-calculator-input="' + name + '"]');
		});
		var quoteField = root.querySelector('[data-ldn-price-calculator-quote]');

		function selection() {
			return {
				colour: inputs.colour ? inputs.colour.value : '',
				clarity: inputs.clarity ? inputs.clarity.value : '',
				cut: inputs.cut ? inputs.cut.value : pooledKey(manifest)
			};
		}

		function update() {
			render(
				manifest,
				region,
				selection(),
				quoteField ? parseQuote(quoteField.value) : null
			);
		}

		Object.keys(inputs).forEach(function (name) {
			if (inputs[name]) {
				inputs[name].addEventListener('change', update);
			}
		});
		if (quoteField) {
			quoteField.addEventListener('input', update);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
