/**
 * Facet Intelligence alternatives panel (PRD-009 Epic 5.4).
 *
 * Hydrates [data-ldn-facet-intelligence-panel] after first paint via wp-json.
 * Never blocks page render — loading and error states stay inside the panel.
 */
(function () {
	'use strict';

	var panelAbort = null;

	function apiBase() {
		if (window.ldnFacetIntelligencePanel && window.ldnFacetIntelligencePanel.restUrl) {
			return window.ldnFacetIntelligencePanel.restUrl;
		}
		return '/wp-json/ldn/v1/';
	}

	function readConstraints(panel) {
		var raw = panel.getAttribute('data-ldn-fi-constraints');
		if (!raw) {
			return {};
		}
		try {
			var parsed = JSON.parse(raw);
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch (e) {
			return {};
		}
	}

	function readAnchor(panel) {
		var raw = panel.getAttribute('data-ldn-fi-anchor');
		if (!raw) {
			return null;
		}
		try {
			var parsed = JSON.parse(raw);
			return parsed && typeof parsed === 'object' ? parsed : null;
		} catch (e) {
			return null;
		}
	}

	function setBusy(panel, busy) {
		panel.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	function showStatus(panel, state) {
		var loading = panel.querySelector('.ldn-fi-panel__status--loading');
		var error = panel.querySelector('.ldn-fi-panel__status--error');
		var empty = panel.querySelector('.ldn-fi-panel__status--empty');
		var list = panel.querySelector('.ldn-fi-panel__list');
		if (loading) {
			loading.hidden = state !== 'loading';
		}
		if (error) {
			error.hidden = state !== 'error';
		}
		if (empty) {
			empty.hidden = state !== 'empty';
		}
		if (list) {
			list.hidden = state !== 'ready';
		}
	}

	function formatComposite(value) {
		if (typeof value !== 'number' || !isFinite(value)) {
			return '';
		}
		return String(Math.round(value));
	}

	function formatPrice(value, currency) {
		if (typeof value !== 'number' || !isFinite(value)) {
			return '';
		}
		var symbol = currency === 'USD' || currency === 'US$' ? '$' : (currency || '$');
		return symbol + Math.round(value).toLocaleString();
	}

	function renderRecommendation(item) {
		var card = document.createElement('li');
		card.className = 'ldn-fi-panel__item';

		var title = document.createElement('h3');
		title.className = 'ldn-fi-panel__item-title';
		var headline = item.headline || item.listing_id || '';
		title.textContent = headline;

		var meta = document.createElement('p');
		meta.className = 'ldn-fi-panel__item-meta';
		var parts = [];
		if (item.composite !== undefined && item.composite !== null) {
			parts.push('SparkleScore ' + formatComposite(Number(item.composite)));
		}
		if (item.price !== undefined && item.price !== null) {
			parts.push(formatPrice(Number(item.price), item.currency));
		}
		if (item.carat !== undefined && item.carat !== null) {
			parts.push(String(item.carat) + ' ct');
		}
		meta.textContent = parts.join(' · ');

		card.appendChild(title);
		card.appendChild(meta);

		if (Array.isArray(item.reason_codes) && item.reason_codes.length) {
			var reasons = document.createElement('ul');
			reasons.className = 'ldn-fi-panel__reasons';
			for (var i = 0; i < item.reason_codes.length; i += 1) {
				var reason = document.createElement('li');
				reason.textContent = String(item.reason_codes[i]).replace(/_/g, ' ');
				reasons.appendChild(reason);
			}
			card.appendChild(reasons);
		}

		if (item.product_url) {
			var link = document.createElement('a');
			link.className = 'ldn-fi-panel__item-link';
			link.href = String(item.product_url);
			link.rel = 'noopener noreferrer';
			link.textContent = 'View listing';
			card.appendChild(link);
		}

		return card;
	}

	function renderRecommendations(panel, items) {
		var list = panel.querySelector('.ldn-fi-panel__list');
		if (!list) {
			return;
		}
		list.innerHTML = '';
		for (var i = 0; i < items.length; i += 1) {
			list.appendChild(renderRecommendation(items[i]));
		}
		if (!items.length) {
			showStatus(panel, 'empty');
			return;
		}
		showStatus(panel, 'ready');
	}

	function buildRequestBody(panel) {
		var mode = panel.getAttribute('data-ldn-fi-mode') || 'discover';
		var country = panel.getAttribute('data-ldn-fi-country') || 'us';
		var constraints = readConstraints(panel);
		var body = {
			country_code: country,
			constraints: constraints,
		};

		if (mode === 'recommend') {
			var anchor = readAnchor(panel);
			if (!anchor) {
				throw new Error('missing anchor');
			}
			body.anchor = anchor;
			body.category = panel.getAttribute('data-ldn-fi-category') || 'good_value';
			body.mode = 'substitute_diamond';
			return {
				endpoint: 'facet-intelligence/recommend',
				body: body,
			};
		}

		body.buyer_intent = panel.getAttribute('data-ldn-fi-buyer-intent') || 'good_value';
		var category = panel.getAttribute('data-ldn-fi-category');
		if (category) {
			body.category = category;
		}
		return {
			endpoint: 'facet-intelligence/discover',
			body: body,
		};
	}

	function fetchPanel(panel) {
		var request;
		try {
			request = buildRequestBody(panel);
		} catch (e) {
			showStatus(panel, 'error');
			setBusy(panel, false);
			return Promise.resolve();
		}

		if (panelAbort) {
			panelAbort.abort();
		}
		panelAbort = typeof AbortController !== 'undefined' ? new AbortController() : null;

		showStatus(panel, 'loading');
		setBusy(panel, true);

		var headers = {
			'Content-Type': 'application/json',
		};
		if (window.ldnFacetIntelligencePanel && window.ldnFacetIntelligencePanel.nonce) {
			headers['X-WP-Nonce'] = window.ldnFacetIntelligencePanel.nonce;
		}

		var opts = {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify(request.body),
		};
		if (panelAbort) {
			opts.signal = panelAbort.signal;
		}

		return fetch(apiBase() + request.endpoint, opts)
			.then(function (response) {
				return response.json().then(function (payload) {
					return { ok: response.ok, status: response.status, payload: payload };
				});
			})
			.then(function (result) {
				if (!result.ok) {
					throw new Error('facet intelligence unavailable');
				}
				var items = [];
				if (result.payload && Array.isArray(result.payload.recommendations)) {
					items = result.payload.recommendations;
				} else if (result.payload && Array.isArray(result.payload.alternatives)) {
					items = result.payload.alternatives;
				}
				renderRecommendations(panel, items);
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') {
					return;
				}
				showStatus(panel, 'error');
			})
			.finally(function () {
				setBusy(panel, false);
			});
	}

	function initPanel(panel) {
		if (panel.getAttribute('data-ldn-fi-wired') === '1') {
			return;
		}
		panel.setAttribute('data-ldn-fi-wired', '1');
		fetchPanel(panel);
	}

	function init() {
		document.querySelectorAll('[data-ldn-facet-intelligence-panel]').forEach(initPanel);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
	window.addEventListener('rocket-allScriptsLoaded', init);
})();
