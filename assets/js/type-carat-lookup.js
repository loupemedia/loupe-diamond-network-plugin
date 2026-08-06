/**
 * Type-level carat price lookup — snaps to published tier medians from type-summary.
 */
(function () {
	'use strict';

	function readManifest() {
		var node = document.getElementById('ldn-type-carat-lookup-manifest');
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

	function init(root) {
		var manifest = readManifest();
		if (!manifest || !Array.isArray(manifest.tiers) || manifest.tiers.length === 0) {
			return;
		}

		var slider = root.querySelector('[data-ldn-tier-slider]');
		var caratOut = root.querySelector('[data-ldn-tier-carat]');
		var priceOut = root.querySelector('[data-ldn-tier-price]');
		var ppcOut = root.querySelector('[data-ldn-tier-ppc]');
		var footnote = root.querySelector('[data-ldn-tier-footnote]');
		var link = root.querySelector('[data-ldn-tier-link]');
		var symbol = manifest.currency_symbol || '$';
		var labels = manifest.labels || {};

		function tierAt(index) {
			var idx = Math.max(0, Math.min(manifest.tiers.length - 1, index));
			return manifest.tiers[idx];
		}

		function render(index) {
			var tier = tierAt(index);
			if (!tier) {
				return;
			}
			if (caratOut) {
				caratOut.textContent = tier.label + ' ct';
			}
			if (priceOut) {
				priceOut.textContent = money(tier.price, symbol);
			}
			if (ppcOut) {
				ppcOut.textContent = money(tier.ppc, symbol);
			}
			if (footnote && labels.footnote) {
				footnote.textContent = labels.footnote;
			}
			if (link && tier.url) {
				link.href = tier.url;
			}
		}

		if (!slider) {
			return;
		}

		var defaultIdx = 0;
		if (manifest.default_carat) {
			for (var i = 0; i < manifest.tiers.length; i += 1) {
				if (String(manifest.tiers[i].carat) === String(manifest.default_carat)) {
					defaultIdx = i;
					break;
				}
			}
		}
		slider.value = String(defaultIdx);
		render(defaultIdx);

		slider.addEventListener('input', function () {
			render(parseInt(slider.value, 10) || 0);
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var nodes = document.querySelectorAll('[data-ldn-type-carat-lookup]');
		for (var i = 0; i < nodes.length; i += 1) {
			init(nodes[i]);
		}
	});
})();
