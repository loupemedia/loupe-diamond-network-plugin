/**
 * dataLayer helper. GTM (when injected) maps named events to GA4.
 */
(function (window) {
	window.dataLayer = window.dataLayer || [];

	window.ldnTrack = function (eventName, params) {
		if (!eventName || typeof eventName !== 'string') {
			return;
		}
		var payload = { event: eventName };
		if (params && typeof params === 'object') {
			Object.keys(params).forEach(function (key) {
				var value = params[key];
				if (value !== null && value !== undefined && value !== '') {
					payload[key] = value;
				}
			});
		}
		window.dataLayer.push(payload);
	};

	var quoteTimer = null;
	var lastQuoteKey = '';

	window.ldnTrackCalculatorSubmit = function (quotePresent) {
		if (!quotePresent) {
			return;
		}
		var cfg = window.ldnAnalytics || {};
		clearTimeout(quoteTimer);
		quoteTimer = setTimeout(function () {
			var key = [
				cfg.siteId || '',
				cfg.country || '',
				cfg.pageType || 'calculator',
			].join('|');
			if (key === lastQuoteKey) {
				return;
			}
			lastQuoteKey = key;
			if (typeof window.ldnTrack !== 'function') {
				return;
			}
			window.ldnTrack('calculator_submit', {
				site_id: cfg.siteId || '',
				country: cfg.country || '',
				page_type: cfg.pageType || 'calculator',
			});
		}, 800);
	};
})(window);
