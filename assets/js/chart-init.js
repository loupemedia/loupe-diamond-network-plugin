/**
 * LDN Plotly chart bootstrap — works with WP Rocket Delay JS.
 *
 * Plotly (~3 MB) stays in Rocket's delayed queue; this file is tiny and excluded
 * from delay so inline chart scripts can call LDN.plotChart() when Rocket runs them.
 */
(function (w) {
	'use strict';

	var queue = [];
	var waiting = false;
	var MAX_WAIT_MS = 15000;
	var waitStart = 0;

	function plotlyReady() {
		return !!(w.Plotly && typeof w.Plotly.newPlot === 'function');
	}

	function runQueued() {
		waiting = false;
		if (!plotlyReady()) {
			if (waitStart === 0) {
				waitStart = Date.now();
			}
			if (Date.now() - waitStart > MAX_WAIT_MS) {
				queue = [];
				return;
			}
			waiting = true;
			w.requestAnimationFrame(runQueued);
			return;
		}
		waitStart = 0;
		var pending = queue.slice();
		queue = [];
		for (var i = 0; i < pending.length; i++) {
			try {
				pending[i]();
			} catch (err) {
				if (w.LDNChartErrors && typeof w.LDNChartErrors.push === 'function') {
					w.LDNChartErrors.push({
						kind: 'plotly.init',
						detail: err && err.message ? err.message : String(err),
						ts: Date.now(),
					});
				}
				throw err;
			}
		}
	}

	function whenPlotlyReady(fn) {
		if (typeof fn !== 'function') {
			return;
		}
		if (plotlyReady()) {
			fn();
			return;
		}
		queue.push(fn);
		if (!waiting) {
			waiting = true;
			w.requestAnimationFrame(runQueued);
		}
	}

	function plotChart(domId, data, layout, config) {
		whenPlotlyReady(function () {
			w.Plotly.newPlot(domId, data, layout, config || { responsive: true });
		});
	}

	function plotChartEl(el, data, layout, config) {
		whenPlotlyReady(function () {
			w.Plotly.newPlot(el, data, layout, config || { responsive: true, displayModeBar: false });
		});
	}

	w.LDN = w.LDN || {};
	w.LDN.whenPlotlyReady = whenPlotlyReady;
	w.LDN.plotChart = plotChart;
	w.LDN.plotChartEl = plotChartEl;

	w.addEventListener('rocket-allScriptsLoaded', runQueued);
	w.addEventListener('load', runQueued);
})(window);
