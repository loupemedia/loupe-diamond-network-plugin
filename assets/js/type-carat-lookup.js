/**
 * Type-level carat price lookup — snaps to published tier medians from type-summary.
 *
 * Also keeps the Natural / Lab-grown toggle's inactive pill pointed at the same
 * carat (`?carat=`) so switching type does not jump to the sibling's most-listed weight.
 *
 * Exposes LDN.initTypeCaratLookup() so nat-lab-toggle.js can re-wire the control
 * after it swaps the page body in place, optionally carrying the reader's carat
 * across the switch.
 */
(function (w, d) {
	'use strict';

	function readManifest(root) {
		var node = (root && root.querySelector('#ldn-type-carat-lookup-manifest'))
			|| d.getElementById('ldn-type-carat-lookup-manifest');
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

	function caratFromLocation() {
		try {
			return new URLSearchParams(w.location.search).get('carat') || '';
		} catch (e) {
			return '';
		}
	}

	function withCaratQuery(url, carat) {
		if (!url || carat === '' || carat === null || typeof carat === 'undefined') {
			return url;
		}
		try {
			var parsed = new URL(url, w.location.href);
			parsed.searchParams.set('carat', String(carat));
			return parsed.href;
		} catch (e) {
			return url;
		}
	}

	function syncToggleCarat(carat) {
		var pills = d.querySelectorAll(
			'.ldn-nat-lab-toggle__pill:not(.ldn-nat-lab-toggle__pill--active)'
		);
		for (var i = 0; i < pills.length; i += 1) {
			var href = pills[i].getAttribute('href');
			if (!href) {
				continue;
			}
			pills[i].setAttribute('href', withCaratQuery(href, carat));
		}
	}

	function indexForCarat(tiers, carat) {
		if (!carat) {
			return -1;
		}
		for (var i = 0; i < tiers.length; i += 1) {
			if (String(tiers[i].carat) === String(carat)) {
				return i;
			}
		}
		var asFloat = parseFloat(carat);
		if (!isFinite(asFloat)) {
			return -1;
		}
		for (var j = 0; j < tiers.length; j += 1) {
			if (parseFloat(tiers[j].carat) === asFloat) {
				return j;
			}
		}
		return -1;
	}

	function init(root, options) {
		// A swap replaces the root node, so this only guards against initAll()
		// being called twice over the same DOM and double-binding the slider.
		if (root.getAttribute('data-ldn-lookup-bound') === '1') {
			return;
		}
		root.setAttribute('data-ldn-lookup-bound', '1');

		var manifest = readManifest(root);
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
			// Published so a page swap can carry the reader's weight to the
			// sibling type without re-reading the slider's index mapping.
			root.setAttribute('data-ldn-selected-carat', String(tier.carat));
			syncToggleCarat(tier.carat);
		}

		if (!slider) {
			return;
		}

		// Precedence: an explicit carry from a page swap, then ?carat= on the
		// request, then this type's most-listed weight.
		var carried = (options && options.carat) ? String(options.carat) : '';
		var defaultIdx = 0;
		var fromCarry = indexForCarat(manifest.tiers, carried);
		var fromQuery = fromCarry >= 0 ? -1 : indexForCarat(manifest.tiers, caratFromLocation());
		if (fromCarry >= 0) {
			defaultIdx = fromCarry;
		} else if (fromQuery >= 0) {
			defaultIdx = fromQuery;
		} else if (manifest.default_carat) {
			var fromManifest = indexForCarat(manifest.tiers, manifest.default_carat);
			if (fromManifest >= 0) {
				defaultIdx = fromManifest;
			}
		}
		slider.value = String(defaultIdx);
		render(defaultIdx);

		slider.addEventListener('input', function () {
			render(parseInt(slider.value, 10) || 0);
		});
	}

	function initAll(options) {
		var nodes = d.querySelectorAll('[data-ldn-type-carat-lookup]');
		for (var i = 0; i < nodes.length; i += 1) {
			init(nodes[i], options);
		}
	}

	w.LDN = w.LDN || {};
	w.LDN.initTypeCaratLookup = initAll;

	if (d.readyState === 'loading') {
		d.addEventListener('DOMContentLoaded', function () {
			initAll();
		});
	} else {
		initAll();
	}
})(window, document);
