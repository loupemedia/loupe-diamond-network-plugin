/**
 * LDN front-end chart error beacon — PRD-005 CP54.
 *
 * Logs Plotly.newPlot failures and uncaught chart-init errors so staging
 * catches broken S3 payloads before consumer launch.
 */
(function () {
	'use strict';

	function report(kind, detail) {
		if (typeof console !== 'undefined' && console.error) {
			console.error('[LDN chart]', kind, detail);
		}
		if (window.LDNChartErrors && typeof window.LDNChartErrors.push === 'function') {
			window.LDNChartErrors.push({ kind: kind, detail: detail, ts: Date.now() });
		}
	}

	window.LDNChartErrors = window.LDNChartErrors || [];

	window.addEventListener('error', function (event) {
		var msg = event && event.message ? String(event.message) : '';
		if (msg.indexOf('Plotly') !== -1 || msg.indexOf('ldn-chart') !== -1) {
			report('window.error', msg);
		}
	});

	if (window.Plotly && typeof window.Plotly.newPlot === 'function') {
		var original = window.Plotly.newPlot;
		window.Plotly.newPlot = function () {
			try {
				return original.apply(this, arguments);
			} catch (err) {
				report('plotly.newPlot', err && err.message ? err.message : String(err));
				throw err;
			}
		};
	} else {
		document.addEventListener('DOMContentLoaded', function () {
			if (!window.Plotly) {
				report('plotly.missing', 'Plotly.js did not load from CDN');
			}
		});
	}
})();
