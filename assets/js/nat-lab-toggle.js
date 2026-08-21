/**
 * Natural / lab-grown toggle — swap the page body in place instead of navigating.
 *
 * The two diamond types are separate WordPress pages, so a click used to cost a
 * full navigation: PHP render, S3 artefact fetches, theme JS and Plotly boot.
 * Prefetching only helped when the browser had finished warming the sibling
 * before the click, which it usually had not.
 *
 * Here the sibling document is fetched once, `<main>` is replaced, the chart
 * bootstraps are re-run and the URL is pushed. The server stays the only thing
 * that renders a page, so the swapped copy, tables and charts cannot drift from
 * what a crawler or a reader without JavaScript sees.
 *
 * Every failure path falls through to normal navigation.
 */
(function (w, d) {
	'use strict';

	var ACTIVE = 'ldn-nat-lab-toggle__pill--active';
	var PENDING = 'ldn-nat-lab-toggle--pending';
	var MAIN_SELECTOR = 'main.ldn-price-page';

	var cache = {};
	var inflight = {};
	var historyBound = false;

	function supported() {
		return !!(w.fetch && w.DOMParser && w.history && w.history.pushState && w.URL);
	}

	/**
	 * Fetches are keyed on the carat-free URL. Page caches (and WP Rocket's link
	 * preloader) skip anything with a query string, so warming `…/?carat=2` would
	 * miss the cache on every switch. The reader's weight is re-applied to the
	 * slider after the swap instead of being asked for from the server.
	 */
	function documentUrl(href) {
		try {
			var url = new w.URL(href, w.location.href);
			url.searchParams.delete('carat');
			url.hash = '';
			return url.href;
		} catch (e) {
			return href;
		}
	}

	function sameOrigin(href) {
		try {
			return new w.URL(href, w.location.href).origin === w.location.origin;
		} catch (e) {
			return false;
		}
	}

	function load(url) {
		if (cache[url]) {
			return Promise.resolve(cache[url]);
		}
		if (inflight[url]) {
			return inflight[url];
		}
		inflight[url] = w.fetch(url, { credentials: 'same-origin' })
			.then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.text();
			})
			.then(function (html) {
				cache[url] = html;
				delete inflight[url];
				return html;
			})
			.catch(function (error) {
				delete inflight[url];
				throw error;
			});
		return inflight[url];
	}

	function whenIdle(fn) {
		if (typeof w.requestIdleCallback === 'function') {
			w.requestIdleCallback(fn, { timeout: 2000 });
			return;
		}
		w.setTimeout(fn, 400);
	}

	function warm(url) {
		load(url).catch(function () {});
	}

	function selectedCarat() {
		var root = d.querySelector('[data-ldn-type-carat-lookup]');
		return (root && root.getAttribute('data-ldn-selected-carat')) || '';
	}

	/**
	 * Only scripts the renderer tagged as chart bootstraps are re-run. Parser
	 * created scripts never execute on insertion, so each one is replaced with a
	 * fresh element; ad and analytics scripts in the same subtree are left inert
	 * so a swap cannot double-fire them.
	 */
	function runChartScripts(scope) {
		var scripts = scope.querySelectorAll('script[data-ldn-chart]');
		for (var i = 0; i < scripts.length; i += 1) {
			var stale = scripts[i];
			var fresh = d.createElement('script');
			fresh.setAttribute('data-ldn-chart', '1');
			fresh.text = stale.textContent || '';
			if (stale.parentNode) {
				stale.parentNode.replaceChild(fresh, stale);
			}
		}
	}

	function announce(scope) {
		var pill = scope.querySelector('.' + ACTIVE);
		if (pill && typeof pill.focus === 'function') {
			// The clicked node is gone with the old subtree; focusing the new
			// active pill is what tells a screen reader the type changed.
			pill.focus({ preventScroll: true });
		}
	}

	function notify() {
		try {
			w.dispatchEvent(new w.CustomEvent('ldn:page-swapped'));
		} catch (e) {
			// Event constructors are not worth a fallback shim here.
		}
	}

	function swapIn(html, carat) {
		var parsed;
		try {
			parsed = new w.DOMParser().parseFromString(html, 'text/html');
		} catch (e) {
			return false;
		}
		var next = parsed.querySelector(MAIN_SELECTOR);
		var current = d.querySelector(MAIN_SELECTOR);
		if (!next || !current || !current.parentNode) {
			return false;
		}

		current.parentNode.replaceChild(d.importNode(next, true), current);
		var live = d.querySelector(MAIN_SELECTOR);
		if (!live) {
			return false;
		}

		runChartScripts(live);

		var title = parsed.querySelector('title');
		if (title) {
			d.title = title.textContent || d.title;
		}
		var nextCanonical = parsed.querySelector('link[rel="canonical"]');
		var liveCanonical = d.querySelector('link[rel="canonical"]');
		if (nextCanonical && liveCanonical) {
			liveCanonical.setAttribute('href', nextCanonical.getAttribute('href') || '');
		}

		if (w.LDN && typeof w.LDN.initTypeCaratLookup === 'function') {
			w.LDN.initTypeCaratLookup({ carat: carat });
		}
		bindAll();
		announce(live);
		notify();
		return true;
	}

	function bindHistory() {
		if (historyBound) {
			return;
		}
		historyBound = true;
		w.addEventListener('popstate', function () {
			var url = documentUrl(w.location.href);
			if (!cache[url]) {
				// Only pages this script pulled are in the cache; anything else
				// (or a cleared cache) has to be a real load.
				w.location.reload();
				return;
			}
			var state = w.history.state || {};
			swapIn(cache[url], state.carat || '');
		});
	}

	function markPending(nav, pill) {
		var pills = nav.querySelectorAll('.ldn-nat-lab-toggle__pill');
		for (var i = 0; i < pills.length; i += 1) {
			pills[i].classList.remove(ACTIVE);
			pills[i].removeAttribute('aria-current');
		}
		pill.classList.add(ACTIVE);
		pill.setAttribute('aria-current', 'page');
		nav.classList.add(PENDING);
	}

	function bind(nav) {
		if (nav.getAttribute('data-ldn-toggle-bound') === '1') {
			return;
		}
		nav.setAttribute('data-ldn-toggle-bound', '1');

		var inactive = nav.querySelector('.ldn-nat-lab-toggle__pill:not(.' + ACTIVE + ')');
		if (inactive && supported() && sameOrigin(inactive.getAttribute('href') || '')) {
			// Warming on idle rather than on hover: the switch is a two-state
			// control, so there is exactly one sibling worth holding.
			whenIdle(function () {
				var href = inactive.getAttribute('href') || '';
				if (href) {
					warm(documentUrl(href));
				}
			});
		}

		nav.addEventListener('click', function (event) {
			var pill = event.target.closest
				? event.target.closest('.ldn-nat-lab-toggle__pill')
				: null;
			if (!pill || !nav.contains(pill) || pill.classList.contains(ACTIVE)) {
				return;
			}

			var href = pill.getAttribute('href') || '';
			if (href === '') {
				return;
			}

			// Let the browser own modified clicks (new tab, download, middle).
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
				|| (typeof event.button === 'number' && event.button !== 0)) {
				return;
			}

			markPending(nav, pill);

			if (!supported() || !sameOrigin(href)) {
				return;
			}

			var url = documentUrl(href);
			var carat = selectedCarat();
			event.preventDefault();

			load(url)
				.then(function (html) {
					var previous = documentUrl(w.location.href);
					if (!swapIn(html, carat)) {
						w.location.assign(href);
						return;
					}
					bindHistory();
					// The weight is stamped on both entries so Back returns the
					// page the reader left, not this type's most-listed default.
					w.history.replaceState({ ldnSwap: 1, carat: carat }, '', previous);
					w.history.pushState({ ldnSwap: 1, carat: carat }, '', url);
					// Back is only instant if the page we came from is held too,
					// and that is only worth a request once someone has switched.
					whenIdle(function () {
						warm(previous);
					});
				})
				.catch(function () {
					w.location.assign(href);
				});
		});
	}

	function bindAll() {
		var nodes = d.querySelectorAll('.ldn-nat-lab-toggle');
		for (var i = 0; i < nodes.length; i += 1) {
			bind(nodes[i]);
		}
	}

	if (d.readyState === 'loading') {
		d.addEventListener('DOMContentLoaded', bindAll);
	} else {
		bindAll();
	}
})(window, document);
