/**
 * Progressive interactivity for LDN finished jewellery surfaces (PRD-020 CP 135).
 *
 * Hydrates from `#ldn-jewellery-payload` embedded by `LDN_Jewellery_Renderer`
 * (zero client S3 fetches) and reads retailer display names from
 * `window.ldnJewelleryConfig.retailers` (populated from `config/retailers.yaml`).
 */
(function () {
	'use strict';

	var payloadEl = document.getElementById('ldn-jewellery-payload');
	if (!payloadEl) {
		return;
	}

	var PAYLOAD;
	try {
		PAYLOAD = JSON.parse(payloadEl.textContent || '{}');
	} catch (err) {
		return;
	}

	var RETAILERS = (window.ldnJewelleryConfig && window.ldnJewelleryConfig.retailers) || {};

	var METAL_LABELS = {
		white_gold: 'White Gold',
		yellow_gold: 'Yellow Gold',
		rose_gold: 'Rose Gold',
		platinum: 'Platinum',
		silver: 'Silver'
	};

	var METAL_CODES = {
		white_gold: 'WG',
		yellow_gold: 'YG',
		rose_gold: 'RG',
		platinum: 'PT',
		silver: 'AG'
	};

	var SETTING_LABELS = {
		basket_4_prong: '4-Prong Basket',
		four_prong: '4-Prong',
		martini_3_prong: '3-Prong Martini',
		three_prong: '3-Prong',
		bezel: 'Bezel',
		halo: 'Halo',
		channel: 'Channel',
		pave: 'Pave',
		prong: 'Classic Prong',
		illusion_miracle: 'Illusion',
		shared_prong: 'Shared Prong',
		bar: 'Bar'
	};

	var METAL_ORDER = ['white_gold', 'yellow_gold', 'rose_gold', 'platinum'];

	function usd(n) {
		return '$' + Math.round(Number(n) || 0).toLocaleString('en-US');
	}

	function usdK(n) {
		var v = Number(n) || 0;
		if (v >= 1000) {
			return '$' + (Math.round(v / 100) / 10).toString().replace(/\.0$/, '') + 'k';
		}
		return usd(v);
	}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
		});
	}

	function humanLabel(val, map) {
		if (map && map[val]) {
			return map[val];
		}
		return String(val || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) {
			return c.toUpperCase();
		});
	}

	if (PAYLOAD.mode === 'atelier') {
		initAtelier(PAYLOAD);
	} else if (PAYLOAD.mode === 'explorer') {
		initExplorer(PAYLOAD);
	}

	/* =========================================================================
	   Mode B: Atelier Lookbook (`metal_setting_led` — The Diamond Advisors)
	   ========================================================================= */
	function initAtelier(data) {
		var allCards = Array.isArray(data.cards) ? data.cards : [];
		var meanPrice = Number(data.headlinePrice) || 1;
		var minPrice = Number(data.minPrice) || 0;
		var maxPrice = Math.max(minPrice + 1, Number(data.maxPrice) || 1);

		var state = {
			metal: 'all',
			setting: 'all',
			sort: 'featured',
			visibleCount: Number(data.initialVisible) || 12,
			showAllTableRows: false,
			cardMetalOverride: {}
		};

		// Sticky bar shadow via IntersectionObserver (no scroll listener)
		var sentinel = document.querySelector('.ldn-jwl-sticky-sentinel');
		var stickyBar = document.getElementById('ldn-jwl-sticky-bar');
		if (sentinel && stickyBar && 'IntersectionObserver' in window) {
			var io = new IntersectionObserver(function (entries) {
				stickyBar.classList.toggle('is-stuck', !entries[0].isIntersecting);
			});
			io.observe(sentinel);
		}

		function advisorPriceContext(price, avg, setting) {
			var ratio = price / Math.max(1, avg);
			var avgStr = usd(avg);
			if (ratio <= 0.72) {
				return 'Opening tier \u00b7 well under the ' + avgStr + ' average';
			}
			if (ratio < 0.92) {
				return 'Below the ' + avgStr + ' average';
			}
			if (ratio <= 1.10) {
				return 'Close to the ' + avgStr + ' average';
			}
			if (setting === 'bezel' || setting === 'halo') {
				return 'Commands a setting premium';
			}
			return 'Commands a premium \u00b7 heavier mount or higher spec';
		}

		function interleaveCards2D(cards) {
			var queues = new Map();
			cards.forEach(function (card) {
				var rc = card.retailer_code || '';
				if (!queues.has(rc)) {
					queues.set(rc, []);
				}
				queues.get(rc).push(card);
			});
			var rankOf = new Map();
			cards.forEach(function (c, idx) {
				rankOf.set(c, idx);
			});
			var placedCount = {};
			var result = [];

			while (queues.size > 0) {
				var prev1 = result.length >= 1 ? result[result.length - 1].retailer_code : null;
				var prev2 = result.length >= 2 ? result[result.length - 2].retailer_code : null;
				var allCodes = Array.from(queues.keys());
				var eligible = allCodes.filter(function (code) {
					return code !== prev1 && code !== prev2;
				});
				if (eligible.length === 0) {
					eligible = allCodes.filter(function (code) {
						return code !== prev1;
					});
				}
				if (eligible.length === 0) {
					eligible = allCodes;
				}
				eligible.sort(function (a, b) {
					var ca = placedCount[a] || 0;
					var cb = placedCount[b] || 0;
					if (ca !== cb) {
						return ca - cb;
					}
					return rankOf.get(queues.get(a)[0]) - rankOf.get(queues.get(b)[0]);
				});
				var chosen = eligible[0];
				var list = queues.get(chosen);
				result.push(list.shift());
				placedCount[chosen] = (placedCount[chosen] || 0) + 1;
				if (list.length === 0) {
					queues.delete(chosen);
				}
			}
			return result;
		}

		function activeVariant(c) {
			var key = (c.retailer_code || '') + '|' + (c.design_key || '');
			var metals = Array.isArray(c.metals_available) ? c.metals_available : [];
			var chosenMetal = state.cardMetalOverride[key]
				|| (state.metal !== 'all' && metals.indexOf(state.metal) >= 0 ? state.metal : metals[0] || '');
			var bm = (c.by_metal && c.by_metal[chosenMetal]) || null;
			return {
				key: key,
				chosenMetal: chosenMetal,
				price: bm && bm.price != null ? Number(bm.price) : Number(c.price || 0),
				title: (bm && bm.product_title) || c.product_title || '',
				image: (bm && bm.image_url) || c.image_url || '',
				url: (bm && bm.product_url) || c.product_url || '',
				karat: (bm && bm.metal_karat) || (Array.isArray(c.karats_available) && c.karats_available[0]) || '',
				setting: (bm && bm.setting) || (Array.isArray(c.settings_available) && c.settings_available[0]) || ''
			};
		}

		function curateDesigns(cards) {
			function imgTier(c) {
				var img = activeVariant(c).image || '';
				if (img.indexOf('cloudfront') >= 0) {
					return 0;
				}
				return img ? 1 : 2;
			}
			function byMeanDistance(a, b) {
				return Math.abs(activeVariant(a).price - meanPrice) - Math.abs(activeVariant(b).price - meanPrice);
			}
			var t0 = cards.filter(function (c) { return imgTier(c) === 0; }).sort(byMeanDistance);
			var t1 = cards.filter(function (c) { return imgTier(c) === 1; }).sort(byMeanDistance);
			var t2 = cards.filter(function (c) { return imgTier(c) === 2; }).sort(byMeanDistance);
			return interleaveCards2D(t0).concat(interleaveCards2D(t1), interleaveCards2D(t2));
		}

		function renderPlate(c, v, isLead) {
			var house = RETAILERS[c.retailer_code] || c.retailer_code || '';
			var specParts = [];
			if (v.karat && v.karat.toLowerCase().indexOf('platinum') < 0) {
				specParts.push(v.karat.replace('_950', ''));
			}
			if (v.chosenMetal && METAL_LABELS[v.chosenMetal]) {
				specParts.push(METAL_LABELS[v.chosenMetal].toLowerCase());
			}
			if (v.setting) {
				specParts.push(humanLabel(v.setting, SETTING_LABELS).toLowerCase());
			}
			var specLine = specParts.join(' \u00b7 ') || (data.origin === 'lab' ? 'Lab-grown diamond' : 'Natural diamond');
			var note = advisorPriceContext(v.price, meanPrice, v.setting);

			var stamps = (c.metals_available || []).map(function (m) {
				var lbl = METAL_LABELS[m] || m;
				var code = METAL_CODES[m] || m.slice(0, 2).toUpperCase();
				return '<button type="button" class="ldn-jwl-metal-stamp" title="' + esc(lbl) + '" aria-label="' + esc(code + ' - ' + lbl) + '"'
					+ ' aria-pressed="' + String(v.chosenMetal === m) + '" style="--bar: var(--ldn-metal-' + esc(m) + ')"'
					+ ' data-card="' + esc(v.key) + '" data-metal="' + esc(m) + '">' + esc(code) + '</button>';
			}).join('');

			var media = v.image
				? '<img src="' + esc(v.image) + '" alt="' + esc(v.title) + '" width="400" height="400" decoding="async" loading="lazy" />'
				: '<div class="ldn-jwl-plate__no-photo">Photograph unavailable from ' + esc(house) + '</div>';

			return '<article class="ldn-jwl-plate' + (isLead ? ' ldn-jwl-plate--lead' : '') + '" data-card="' + esc(v.key) + '">'
				+ '<div class="ldn-jwl-plate__frame">' + media + '</div>'
				+ '<div class="ldn-jwl-plate__caption">'
				+ '<div class="ldn-jwl-plate__kicker-row"><span class="ldn-jwl-plate__house">' + esc(house) + '</span>'
				+ '<span class="ldn-jwl-plate__price">' + usd(v.price) + '</span></div>'
				+ '<h3 class="ldn-jwl-plate__title">' + esc(v.title) + '</h3>'
				+ '<div class="ldn-jwl-plate__spec">' + esc(specLine) + '</div>'
				+ '<div class="ldn-jwl-plate__note">' + esc(note) + '</div>'
				+ '<div class="ldn-jwl-plate__foot"><div class="ldn-jwl-metal-stamps">' + stamps + '</div>'
				+ (v.url ? '<a class="ldn-jwl-btn-details" href="' + esc(v.url) + '" target="_blank" rel="sponsored noopener">More details</a>' : '')
				+ '</div></div></article>';
		}

		function syncTableSelection() {
			document.querySelectorAll('#price-by-metal-setting tbody tr.is-interactive').forEach(function (tr) {
				var rm = tr.getAttribute('data-metal') || '';
				var rs = tr.getAttribute('data-setting') || '';
				var selected = false;
				if (rm && rs) {
					selected = (state.metal === rm && state.setting === rs);
				} else if (rm) {
					selected = (state.metal === rm);
				} else if (rs) {
					selected = (state.setting === rs);
				}
				tr.classList.toggle('is-selected', selected);
			});
		}

		function renderLookbook() {
			document.querySelectorAll('#ldn-jwl-metal-tabs .ldn-jwl-metal-tab').forEach(function (btn) {
				btn.setAttribute('aria-selected', String(btn.getAttribute('data-metal') === state.metal));
			});
			document.querySelectorAll('#ldn-jwl-setting-chips .ldn-jwl-setting-btn').forEach(function (btn) {
				btn.setAttribute('aria-pressed', String(btn.getAttribute('data-setting') === state.setting));
			});

			var filtered = allCards.filter(function (c) {
				if (state.metal !== 'all' && (c.metals_available || []).indexOf(state.metal) < 0) {
					return false;
				}
				if (state.setting !== 'all' && (c.settings_available || []).indexOf(state.setting) < 0) {
					return false;
				}
				return true;
			});

			if (state.sort === 'price_asc') {
				filtered.sort(function (a, b) { return activeVariant(a).price - activeVariant(b).price; });
			} else if (state.sort === 'price_desc') {
				filtered.sort(function (a, b) { return activeVariant(b).price - activeVariant(a).price; });
			} else {
				filtered = curateDesigns(filtered);
			}

			var shown = filtered.slice(0, state.visibleCount);
			var metaEl = document.getElementById('ldn-jwl-showing-meta');
			if (metaEl) {
				metaEl.textContent = shown.length + ' of ' + filtered.length + ' designs';
			}

			var featEl = document.getElementById('ldn-jwl-featured');
			var dosEl = document.getElementById('ldn-jwl-dossier');
			if (featEl && dosEl) {
				if (shown.length >= 2) {
					featEl.hidden = false;
					featEl.innerHTML = shown.slice(0, 2).map(function (c) { return renderPlate(c, activeVariant(c), true); }).join('');
					dosEl.hidden = shown.length <= 2;
					dosEl.innerHTML = shown.slice(2).map(function (c) { return renderPlate(c, activeVariant(c), false); }).join('');
				} else {
					featEl.hidden = true;
					featEl.innerHTML = '';
					dosEl.hidden = false;
					dosEl.innerHTML = shown.length
						? shown.map(function (c) { return renderPlate(c, activeVariant(c), false); }).join('')
						: '<p class="ldn-jwl-table-hint" style="padding:1.5rem 0;">No designs match this combination. Select All Metals or All Settings above.</p>';
				}
			}

			var moreWrap = document.getElementById('ldn-jwl-more-wrap');
			var moreBtn = document.getElementById('ldn-jwl-btn-more');
			if (moreWrap && moreBtn) {
				var rem = filtered.length - shown.length;
				moreWrap.hidden = rem <= 0;
				if (rem > 0) {
					moreBtn.textContent = 'View ' + Math.min(10, rem) + ' more designs (' + rem + ' remaining)';
				}
			}

			syncTableSelection();
		}

		// Delegated listeners
		var tabsWrap = document.getElementById('ldn-jwl-metal-tabs');
		if (tabsWrap) {
			tabsWrap.addEventListener('click', function (e) {
				var btn = e.target.closest('.ldn-jwl-metal-tab');
				if (!btn) {
					return;
				}
				state.metal = btn.getAttribute('data-metal') || 'all';
				state.visibleCount = 12;
				renderLookbook();
			});
		}

		var setWrap = document.getElementById('ldn-jwl-setting-chips');
		if (setWrap) {
			setWrap.addEventListener('click', function (e) {
				var btn = e.target.closest('.ldn-jwl-setting-btn');
				if (!btn) {
					return;
				}
				state.setting = btn.getAttribute('data-setting') || 'all';
				state.visibleCount = 12;
				renderLookbook();
			});
		}

		var sortEl = document.getElementById('ldn-jwl-sort');
		if (sortEl) {
			sortEl.addEventListener('change', function () {
				state.sort = sortEl.value;
				renderLookbook();
			});
		}

		var lookbookSec = document.getElementById('lookbook-section');
		if (lookbookSec) {
			lookbookSec.addEventListener('click', function (e) {
				var stamp = e.target.closest('.ldn-jwl-metal-stamp');
				if (stamp) {
					state.cardMetalOverride[stamp.getAttribute('data-card')] = stamp.getAttribute('data-metal');
					renderLookbook();
				}
			});
		}

		var moreBtn = document.getElementById('ldn-jwl-btn-more');
		if (moreBtn) {
			moreBtn.addEventListener('click', function () {
				state.visibleCount += 10;
				renderLookbook();
			});
		}

		// Expandable lead breakdown table + clickable rows
		var tablesSec = document.getElementById('price-by-metal-setting');
		if (tablesSec) {
			tablesSec.addEventListener('click', function (e) {
				var tr = e.target.closest('tr.is-interactive');
				if (!tr) {
					return;
				}
				var rm = tr.getAttribute('data-metal') || '';
				var rs = tr.getAttribute('data-setting') || '';
				if (rm && rs) {
					if (state.metal === rm && state.setting === rs) {
						state.metal = 'all';
						state.setting = 'all';
					} else {
						state.metal = rm;
						state.setting = rs;
						if (lookbookSec) {
							lookbookSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
						}
					}
				} else if (rm) {
					if (state.metal === rm) {
						state.metal = 'all';
					} else {
						state.metal = rm;
						if (lookbookSec) {
							lookbookSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
						}
					}
				} else if (rs) {
					if (state.setting === rs) {
						state.setting = 'all';
					} else {
						state.setting = rs;
						if (lookbookSec) {
							lookbookSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
						}
					}
				}
				state.visibleCount = 12;
				renderLookbook();
			});
		}

		var tableMoreBtn = document.getElementById('ldn-jwl-btn-table-more');
		if (tableMoreBtn && data.qualityGrid && data.qualityGrid.lead_table) {
			var leadId = data.qualityGrid.lead_table;
			var allLeadRows = ((data.qualityGrid.tables && data.qualityGrid.tables[leadId]) || []).slice().sort(function (a, b) {
				return (Number(a.headline_price) || 0) - (Number(b.headline_price) || 0);
			});
			tableMoreBtn.addEventListener('click', function () {
				state.showAllTableRows = !state.showAllTableRows;
				var rows = state.showAllTableRows ? allLeadRows : allLeadRows.slice(0, 8);
				var hasSetting = allLeadRows[0] && ('setting' in allLeadRows[0]);
				var spanDenom = Math.max(1, maxPrice - minPrice);
				var tbody = document.getElementById('ldn-jwl-lead-tbody');
				if (tbody) {
					tbody.innerHTML = rows.map(function (r) {
						var hl = Number(r.headline_price) || 0;
						var rLo = r.min_price != null ? Number(r.min_price) : Number(r.range_low || hl);
						var rHi = r.max_price != null ? Number(r.max_price) : Number(r.range_high || hl);
						var left = Math.max(0, Math.min(100, ((rLo - minPrice) / spanDenom) * 100));
						var right = Math.max(0, Math.min(100, ((rHi - minPrice) / spanDenom) * 100));
						var width = Math.max(3, right - left);
						var dot = Math.max(0, Math.min(100, ((hl - minPrice) / spanDenom) * 100));
						var rangeStr = Math.round(rLo) === Math.round(rHi) ? usd(rLo) : (usd(rLo) + ' - ' + usd(rHi));
						var mTxt = humanLabel(r.metal, METAL_LABELS);
						var sTxt = hasSetting ? humanLabel(r.setting, SETTING_LABELS) : '';
						return '<tr class="is-interactive" data-metal="' + esc(r.metal || '') + '"'
							+ (hasSetting ? ' data-setting="' + esc(r.setting || '') + '"' : '') + '>'
							+ '<td class="cell-title"><strong>' + esc(mTxt) + '</strong>'
							+ (hasSetting ? '<span class="ldn-jwl-mobile-sub"> \u00b7 ' + esc(sTxt) + '</span>' : '') + '</td>'
							+ (hasSetting ? '<td class="cell-hide-mobile">' + esc(sTxt) + '</td>' : '')
							+ '<td class="cell-mean">' + usd(hl) + '</td>'
							+ '<td class="cell-span"><div class="ldn-jwl-row-span"><span>' + esc(rangeStr) + '</span>'
							+ '<span class="ldn-jwl-row-span__track" aria-hidden="true">'
							+ '<span class="ldn-jwl-row-span__fill" style="left:' + left.toFixed(1) + '%;width:' + width.toFixed(1) + '%"></span>'
							+ '<span class="ldn-jwl-row-span__dot" style="left:' + dot.toFixed(1) + '%"></span>'
							+ '</span></div></td>'
							+ '<td class="num cell-count">' + Number(r.design_count || 0) + '</td></tr>';
					}).join('');
					syncTableSelection();
				}
				tableMoreBtn.textContent = state.showAllTableRows
					? 'Show top 8 lowest-to-highest rows'
					: 'Show all ' + allLeadRows.length + ' combinations (lowest to highest)';
			});
		}
	}

	/* =========================================================================
	   Mode A: Interactive Explorer (`quality_led` — Ringspo `v8`)
	   ========================================================================= */
	function initExplorer(cfg) {
		var ALL = {
			natural: cfg.natural || null,
			lab: cfg.lab || null
		};
		var type = cfg.initialType === 'lab' && ALL.lab ? 'lab' : 'natural';
		var D = ALL[type];
		if (!D || !Array.isArray(D.band_order) || !D.band_order.length) {
			return;
		}

		var POSITION = {
			below_typical: 'Value price',
			typical: 'Typical price',
			above_typical: 'Premium price'
		};
		var POSITION_RANK = { below_typical: 0, typical: 1, above_typical: 2 };
		var PREMIUM_WHY = 'Often fewer, larger diamonds or a heavier setting';
		var PRICE_STEPS = 1000;

		var initIdx = Math.max(0, D.band_order.indexOf(D.initial_band));
		var lo = initIdx;
		var hi = initIdx;
		var band = D.band_order[lo];
		var plo = 0;
		var phi = 0;
		var ext = { min: 100, max: 10000, step: 100 };
		var hidden = new Set();
		var shown = 24;
		var hot = null;
		var touch = false;
		var autoBand = {};
		var userBand = {};

		function byId(id) {
			return document.getElementById(id);
		}

		function ct(b) {
			return b + ' ct';
		}

		function words(b) {
			return String(b).replace('-', ' to ');
		}

		function toPrice(t) {
			if (t <= 0) {
				return ext.min;
			}
			if (t >= PRICE_STEPS) {
				return ext.max;
			}
			var loSafe = Math.max(1, ext.min);
			var hiSafe = Math.max(loSafe + 1, ext.max);
			var v = Math.exp(Math.log(loSafe) + (Math.log(hiSafe) - Math.log(loSafe)) * t / PRICE_STEPS);
			return Math.round(v / ext.step) * ext.step;
		}

		function selectedBands() {
			return D.band_order.slice(lo, hi + 1);
		}

		function single() {
			return lo === hi;
		}

		function variant(k, b) {
			var d = D.designs[k];
			var vs = (d && d.variants && d.variants[b]) || {};
			var rep = (d && d.rep_metal && d.rep_metal[b]) || '';
			var metal = (!hidden.has(rep) && vs[rep])
				? rep
				: (METAL_ORDER.find(function (m) { return !hidden.has(m) && vs[m]; }) || rep);
			var raw = vs[metal] || Object.values(vs)[0] || {};
			return {
				title: raw.product_title || raw.title || '',
				image: raw.image_url || raw.image || '',
				url: raw.product_url || raw.url || ''
			};
		}

		function pos(price, b) {
			var s = D.bands[b || band].stats;
			var sMin = Number(s.min_price) || 0;
			var sMax = Number(s.max_price) || 1;
			return (price - sMin) / Math.max(1, sMax - sMin);
		}

		function okAt(k, b) {
			var d = D.designs[k];
			if (!d) {
				return false;
			}
			var p = d.prices[b];
			var metals = d.metals_available || d.metals || [];
			return p !== undefined && p >= plo && p <= phi && metals.some(function (m) { return !hidden.has(m); });
		}

		function untouched() {
			var sortEl = byId('ldn-exp-sort');
			return single() && (!sortEl || sortEl.value === 'spread') && !hidden.size && plo <= ext.min && phi >= ext.max;
		}

		function cardBandOf(k) {
			return userBand[k] || autoBand[k] || band;
		}

		function visible() {
			var sortEl = byId('ldn-exp-sort');
			var sort = sortEl ? sortEl.value : 'spread';
			autoBand = {};
			var keys;
			if (single()) {
				keys = D.bands[band].order.filter(function (k) { return okAt(k, band); });
			} else {
				var sel = selectedBands();
				Object.keys(D.designs).forEach(function (k) {
					for (var i = sel.length - 1; i >= 0; i--) {
						if (okAt(k, sel[i])) {
							autoBand[k] = sel[i];
							break;
						}
					}
				});
				keys = Object.keys(autoBand).sort(function (a, c) {
					var ba = D.band_order.indexOf(autoBand[a]);
					var bc = D.band_order.indexOf(autoBand[c]);
					if (ba !== bc) {
						return bc - ba;
					}
					var ra = POSITION_RANK[D.bands[autoBand[a]].positions[a]] || 1;
					var rc = POSITION_RANK[D.bands[autoBand[c]].positions[c]] || 1;
					return ra - rc || D.designs[a].prices[autoBand[a]] - D.designs[c].prices[autoBand[c]];
				});
			}
			var priceOf = function (k) { return D.designs[k].prices[autoBand[k] || band]; };
			var weightOf = function (k) { return D.band_order.indexOf(autoBand[k] || band); };
			if (sort === 'low' || sort === 'high') {
				keys = keys.slice().sort(function (a, c) { return (priceOf(a) - priceOf(c)) * (sort === 'low' ? 1 : -1); });
			} else if (sort === 'carat_low' || sort === 'carat_high') {
				keys = keys.slice().sort(function (a, c) {
					return (weightOf(a) - weightOf(c)) * (sort === 'carat_low' ? 1 : -1) || priceOf(a) - priceOf(c);
				});
			}
			return keys;
		}

		function label(k, b) {
			var cb = b || band;
			var p = D.bands[cb].positions[k] || 'typical';
			return (POSITION[p] || 'Typical price') + ' for ' + ct(cb);
		}

		function positionPillText(k, b) {
			var cb = b || band;
			var p = D.bands[cb].positions[k] || 'typical';
			return POSITION[p] || 'Typical price';
		}

		function dotClass(k, b) {
			if (k === hot && cardBandOf(k) === b) {
				return 'dot dot--hot';
			}
			return okAt(k, b) ? 'dot' : 'dot dot--out';
		}

		function drawSingle(svg) {
			var w = svg.clientWidth || 800;
			var h = svg.clientHeight || 110;
			svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
			var pad = 14;
			var s = D.bands[band].stats;
			var x = function (v) { return pad + Math.min(Math.max(pos(v, band), 0), 1) * (w - 2 * pad); };
			var base = h - 26;
			var out = '';
			if (plo > ext.min || phi < ext.max) {
				out += '<rect class="budget" x="' + x(plo) + '" y="2" width="' + Math.max(0, x(phi) - x(plo)) + '" height="' + (base - 2) + '"/>';
				out += '<line class="budget-edge" x1="' + x(plo) + '" x2="' + x(plo) + '" y1="2" y2="' + base + '"/>'
					+ '<line class="budget-edge" x1="' + x(phi) + '" x2="' + x(phi) + '" y1="2" y2="' + base + '"/>';
			}
			out += '<rect x="' + x(s.p25_price) + '" y="10" width="' + Math.max(2, x(s.p75_price) - x(s.p25_price)) + '" height="' + (base - 10) + '" fill="var(--ldn-jwl-band)" rx="4"/>';
			out += '<line x1="' + x(s.median_price) + '" x2="' + x(s.median_price) + '" y1="6" y2="' + base + '" stroke="var(--ldn-text)" stroke-width="1.5"/>';
			out += '<line x1="' + pad + '" x2="' + (w - pad) + '" y1="' + base + '" y2="' + base + '" stroke="var(--ldn-jwl-line)"/>';
			var placed = [];
			D.bands[band].order.slice().sort(function (a, c) {
				return D.designs[a].prices[band] - D.designs[c].prices[band];
			}).forEach(function (k) {
				var cx = x(D.designs[k].prices[band]);
				var cy = base - 8;
				while (placed.some(function (p) { return Math.abs(p[0] - cx) < 9 && Math.abs(p[1] - cy) < 9; })) {
					cy -= 9;
				}
				placed.push([cx, cy]);
				out += '<circle class="' + dotClass(k, band) + '" tabindex="0" data-key="' + esc(k) + '" data-band="' + esc(band) + '" cx="' + cx + '" cy="' + Math.max(cy, 8) + '" r="4"/>';
			});
			out += '<text x="' + pad + '" y="' + (h - 6) + '">' + usd(s.min_price) + '</text>'
				+ '<text x="' + (w - pad) + '" y="' + (h - 6) + '" text-anchor="end">' + usd(s.max_price) + '</text>'
				+ '<text class="median-label" x="' + x(s.median_price) + '" y="' + (h - 6) + '" text-anchor="middle">' + usd(s.median_price) + '</text>';
			return out;
		}

		function drawColumns(svg) {
			var w = svg.clientWidth || 800;
			var h = svg.clientHeight || 170;
			svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
			var padL = 44;
			var padR = 8;
			var top = 8;
			var bottom = 24;
			var sel = selectedBands();
			var colW = (w - padL - padR) / sel.length;
			var ymin = Math.max(1, Math.min.apply(null, [plo].concat(sel.map(function (b) { return D.bands[b].stats.min_price; }))));
			var ymax = Math.max.apply(null, [phi, ymin + 1].concat(sel.map(function (b) { return D.bands[b].stats.max_price; })));
			var y = function (v) {
				var vs = Math.max(1, v);
				return top + (1 - (Math.log(vs) - Math.log(ymin)) / (Math.log(ymax) - Math.log(ymin) || 1)) * (h - top - bottom);
			};
			var out = '';
			[1000, 2000, 5000, 10000, 20000, 50000, 100000].filter(function (v) { return v >= ymin && v <= ymax; }).forEach(function (v) {
				out += '<line x1="' + padL + '" x2="' + (w - padR) + '" y1="' + y(v) + '" y2="' + y(v) + '" stroke="var(--ldn-jwl-line)"/>'
					+ '<text x="' + (padL - 6) + '" y="' + (y(v) + 4) + '" text-anchor="end">' + usdK(v) + '</text>';
			});
			if (plo > ext.min || phi < ext.max) {
				out += '<rect class="budget" x="' + padL + '" y="' + y(phi) + '" width="' + (w - padL - padR) + '" height="' + Math.max(0, y(plo) - y(phi)) + '"/>';
				out += '<line class="budget-edge" x1="' + padL + '" x2="' + (w - padR) + '" y1="' + y(phi) + '" y2="' + y(phi) + '"/>'
					+ '<line class="budget-edge" x1="' + padL + '" x2="' + (w - padR) + '" y1="' + y(plo) + '" y2="' + y(plo) + '"/>';
			}
			sel.forEach(function (b, i) {
				var s = D.bands[b].stats;
				var cx = padL + colW * (i + 0.5);
				var bw = Math.min(colW * 0.6, 44);
				out += '<rect x="' + (cx - bw / 2) + '" y="' + y(s.p75_price) + '" width="' + bw + '" height="' + Math.max(2, y(s.p25_price) - y(s.p75_price)) + '" fill="var(--ldn-jwl-band)" rx="3"/>';
				out += '<line x1="' + (cx - bw / 2) + '" x2="' + (cx + bw / 2) + '" y1="' + y(s.median_price) + '" y2="' + y(s.median_price) + '" stroke="var(--ldn-text)" stroke-width="1.5"/>';
				var placed = [];
				var spread = colW * 0.45;
				D.bands[b].order.slice().sort(function (a, c) { return D.designs[a].prices[b] - D.designs[c].prices[b]; }).forEach(function (k) {
					var cy = y(D.designs[k].prices[b]);
					var dx = 0;
					for (var step = 1; placed.some(function (p) { return Math.abs(p[0] - (cx + dx)) < 7 && Math.abs(p[1] - cy) < 7; }) && step < 40; step++) {
						dx = (step % 2 ? 1 : -1) * Math.ceil(step / 2) * 7;
						if (Math.abs(dx) > spread) {
							dx = (((placed.length * 37) % 100) / 100 - 0.5) * 2 * spread;
							break;
						}
					}
					placed.push([cx + dx, cy]);
					out += '<circle class="' + dotClass(k, b) + '" tabindex="0" data-key="' + esc(k) + '" data-band="' + esc(b) + '" cx="' + (cx + dx) + '" cy="' + cy + '" r="3.5"/>';
				});
				out += '<text class="col-label" x="' + cx + '" y="' + (h - 6) + '" text-anchor="middle">' + ct(b) + '</text>';
			});
			return out;
		}

		function hideTip() {
			var tip = byId('ldn-exp-tip');
			if (tip) {
				tip.hidden = true;
			}
		}

		function showTip(dot, isTouch) {
			var k = dot.getAttribute('data-key');
			var b = dot.getAttribute('data-band');
			var d = D.designs[k];
			var tip = byId('ldn-exp-tip');
			var tool = byId('ldn-exp-tool');
			if (!d || !tip || !tool) {
				return;
			}
			var v = variant(k, b);
			var metals = d.metals_available || d.metals || [];
			var house = RETAILERS[d.retailer_code] || d.retailer_code || '';
			tip.innerHTML = '<div class="ldn-jwl-tip__img" style="' + (v.image ? "background-image:url('" + esc(v.image) + "')" : '') + '"></div>'
				+ '<div><div class="ldn-jwl-tip__price">' + usd(d.prices[b]) + '</div>'
				+ '<div class="ldn-jwl-tip__title">' + esc(v.title) + '</div>'
				+ '<div class="ldn-jwl-tip__meta">' + esc(label(k, b)) + '<br>' + esc(house) + ' \u00b7 ' + metals.length + ' metal' + (metals.length > 1 ? 's' : '')
				+ (okAt(k, b) ? '' : '<br>Outside your filters') + '</div></div>'
				+ '<button type="button" class="ldn-jwl-tip__go">See this ' + esc(cfg.pieceNoun || 'piece') + '</button>';
			tip.classList.toggle('ldn-jwl-tip--touch', Boolean(isTouch));
			tip.hidden = false;
			var r = dot.getBoundingClientRect();
			var t = tool.getBoundingClientRect();
			var left = Math.min(Math.max(r.left - t.left - tip.offsetWidth / 2, 8), t.width - tip.offsetWidth - 8);
			tip.style.left = left + 'px';
			tip.style.top = (r.top - t.top - tip.offsetHeight - 10) + 'px';
			if (isTouch) {
				var go = tip.querySelector('.ldn-jwl-tip__go');
				if (go) {
					go.onclick = function () {
						hideTip();
						focusCard(k, b);
					};
				}
			}
		}

		function focusCard(key, b) {
			if (!okAt(key, b)) {
				return;
			}
			var keys = visible();
			var i = keys.indexOf(key);
			if (i < 0) {
				return;
			}
			if (b !== (autoBand[key] || band)) {
				userBand[key] = b;
			}
			hot = key;
			if (i >= shown) {
				shown = i + 1;
			}
			render(false);
			var el = document.querySelector('.ldn-jwl-card[data-key="' + CSS.escape(key) + '"]');
			if (el) {
				el.removeAttribute('data-desktop-only');
				el.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
		}

		function drawPicture() {
			var svg = byId('ldn-exp-picture');
			if (!svg) {
				return;
			}
			svg.classList.toggle('ldn-jwl-picture--cols', !single());
			svg.innerHTML = single() ? drawSingle(svg) : drawColumns(svg);
			svg.querySelectorAll('.dot').forEach(function (dot) {
				dot.addEventListener('pointerdown', function (e) { touch = e.pointerType !== 'mouse'; });
				dot.addEventListener('mouseenter', function () { if (!touch) { showTip(dot, false); } });
				dot.addEventListener('focus', function () { showTip(dot, false); });
				dot.addEventListener('mouseleave', function () { if (!touch) { hideTip(); } });
				dot.addEventListener('blur', function () { if (!touch) { hideTip(); } });
				dot.addEventListener('click', function (e) {
					e.stopPropagation();
					if (touch) {
						showTip(dot, true);
					} else {
						focusCard(dot.getAttribute('data-key'), dot.getAttribute('data-band'));
					}
				});
				dot.addEventListener('keydown', function (e) {
					if (e.key === 'Enter') {
						focusCard(dot.getAttribute('data-key'), dot.getAttribute('data-band'));
					}
				});
			});
		}

		function renderCard(k, i, phoneCut) {
			var cb = cardBandOf(k);
			var d = D.designs[k];
			var s = D.bands[cb].stats;
			var p = D.bands[cb].positions[k] || 'typical';
			var loPct = Math.max(0, Math.min(100, pos(s.p25_price, cb) * 100));
			var hiPct = Math.max(0, Math.min(100, pos(s.p75_price, cb) * 100));
			var mePct = Math.max(0, Math.min(100, pos(d.prices[cb], cb) * 100));
			var metals = METAL_ORDER.filter(function (m) { return (d.metals_available || []).indexOf(m) >= 0; });
			var swatches = metals.map(function (m) {
				return '<span class="ldn-jwl-swatch" style="--sw: var(--ldn-metal-' + esc(m) + ')" title="' + esc(METAL_LABELS[m] || m) + '"></span>';
			}).join('');
			var weights = D.band_order.filter(function (b) { return d.prices[b] != null; }).map(function (b) {
				return '<button type="button" class="ldn-jwl-wt" data-band="' + esc(b) + '"' + (b === cb ? ' aria-current="true"' : '') + '>' + esc(b) + '</button>';
			}).join('');
			var v = variant(k, cb);
			var url = esc(v.url);
			var house = RETAILERS[d.retailer_code] || d.retailer_code || '';
			var whyId = 'why-' + k.replace(/[^a-zA-Z0-9_\-]/g, '-');

			return '<article class="ldn-jwl-card' + (k === hot ? ' ldn-jwl-card--hot' : '') + '" data-key="' + esc(k) + '"' + (i >= phoneCut ? ' data-desktop-only' : '') + '>'
				+ '<div class="ldn-jwl-card__media">'
				+ '<a href="' + url + '" target="_blank" rel="sponsored noopener" class="ldn-jwl-card__img">'
				+ (v.image ? '<img src="' + esc(v.image) + '" alt="' + esc(v.title) + '" width="400" height="400" decoding="async" loading="lazy" />' : '<span class="ldn-jwl-card__noimg">No photo from retailer</span>')
				+ '</a>'
				+ (p === 'above_typical'
					? '<div class="ldn-jwl-card__badge"><button type="button" class="ldn-jwl-pill ldn-jwl-pill--why" aria-expanded="false" aria-describedby="' + esc(whyId) + '">' + esc(positionPillText(k, cb)) + '</button>'
						+ '<span class="ldn-jwl-pill__tip" id="' + esc(whyId) + '" role="tooltip">' + esc(PREMIUM_WHY) + '</span></div>'
					: '<span class="ldn-jwl-pill' + (p === 'typical' ? ' ldn-jwl-pill--typical' : '') + '">' + esc(positionPillText(k, cb)) + '</span>')
				+ '</div>'
				+ '<div class="ldn-jwl-card__priceline"><span class="ldn-jwl-card__price">' + usd(d.prices[cb]) + '</span>'
				+ '<span class="ldn-jwl-meter" title="' + esc(label(k, cb)) + '"><span class="ldn-jwl-meter__band" style="left:' + loPct.toFixed(1) + '%;width:' + Math.max(2, hiPct - loPct).toFixed(1) + '%"></span>'
				+ '<span class="ldn-jwl-meter__dot" style="left:' + mePct.toFixed(1) + '%"></span></span></div>'
				+ '<a href="' + url + '" target="_blank" rel="sponsored noopener" class="ldn-jwl-card__title">' + esc(v.title) + '</a>'
				+ '<div class="ldn-jwl-card__row"><span>Metals</span><span class="ldn-jwl-card__vals ldn-jwl-card__metals" tabindex="0" data-names="Available in '
				+ esc(metals.map(function (m) { return METAL_LABELS[m].toLowerCase(); }).join(', ')) + '">' + swatches + '</span></div>'
				+ '<div class="ldn-jwl-card__row"><span>Carats</span><span class="ldn-jwl-card__vals">' + weights + '</span></div>'
				+ '<a href="' + url + '" target="_blank" rel="sponsored noopener" class="ldn-jwl-card__cta">View at ' + esc(house) + '</a>'
				+ '</article>';
		}

		function syncSliders() {
			var max = Math.max(1, D.band_order.length - 1);
			var cDual = byId('ldn-exp-carat-dual');
			var pDual = byId('ldn-exp-price-dual');
			var pLoEl = byId('ldn-exp-price-lo');
			var pHiEl = byId('ldn-exp-price-hi');
			if (cDual) {
				cDual.style.setProperty('--lo', String(lo / max));
				cDual.style.setProperty('--hi', String(hi / max));
			}
			if (pDual && pLoEl && pHiEl) {
				pDual.style.setProperty('--lo', String(+pLoEl.value / PRICE_STEPS));
				pDual.style.setProperty('--hi', String(+pHiEl.value / PRICE_STEPS));
			}
			var cOut = byId('ldn-exp-carat-out');
			if (cOut) {
				cOut.textContent = single() ? ct(band) : (D.band_order[lo] + ' to ' + ct(D.band_order[hi]));
			}
			var bOut = byId('ldn-exp-budget-out');
			if (bOut) {
				bOut.textContent = usd(plo) + ' to ' + usd(phi);
			}
			document.querySelectorAll('.ldn-jwl-stop').forEach(function (st) {
				var i = D.band_order.indexOf(st.getAttribute('data-band'));
				st.setAttribute('data-in', String(i >= lo && i <= hi));
				if (single() && i === lo) {
					st.setAttribute('aria-current', 'true');
				} else {
					st.removeAttribute('aria-current');
				}
			});
			document.querySelectorAll('#ldn-exp-by-carat tr').forEach(function (tr) {
				var i = D.band_order.indexOf(tr.getAttribute('data-band'));
				if (i >= lo && i <= hi) {
					tr.setAttribute('aria-current', 'true');
				} else {
					tr.removeAttribute('aria-current');
				}
			});
		}

		function render(reset) {
			var keys = visible();
			var featured = single() ? (D.bands[band].featured_count || 24) : 24;
			var phone = single() ? (D.bands[band].featured_phone_count || 12) : 12;
			if (reset) {
				userBand = {};
				shown = untouched() ? featured : 24;
				hot = null;
			}
			hideTip();
			syncSliders();
			var noun = type === 'lab' ? 'lab-grown diamond' : 'natural diamond';
			var plural = cfg.piecePlural || 'designs';
			var rangeEl = byId('ldn-exp-range');
			var subEl = byId('ldn-exp-range-sub');
			var titleEl = byId('ldn-exp-grid-title');
			var cntEl = byId('ldn-exp-count');

			if (single()) {
				var s = D.bands[band].stats;
				if (rangeEl) {
					rangeEl.innerHTML = words(band) + ' carat ' + noun + ' ' + plural + ' typically cost <span class="ldn-jwl-answer__nums">' + usd(s.p25_price) + ' to ' + usd(s.p75_price) + '</span>';
				}
				if (subEl) {
					subEl.textContent = 'Middle price ' + usd(s.median_price) + ' across ' + s.design_count + ' designs from ' + s.retailer_count + ' US retailers' + (cfg.checkedDate ? ', checked ' + cfg.checkedDate : '') + '.';
				}
				if (titleEl) {
					titleEl.textContent = ct(band) + ' ' + noun + ' ' + plural;
				}
				if (cntEl) {
					cntEl.textContent = keys.length + ' of ' + s.design_count + ' designs';
				}
			} else {
				var biggest = keys.length ? D.band_order[Math.max.apply(null, keys.map(function (k) { return D.band_order.indexOf(autoBand[k]); }))] : null;
				if (rangeEl) {
					rangeEl.innerHTML = keys.length
						? keys.length + ' ' + noun + ' ' + plural + ' from ' + words(D.band_order[lo]) + ' to ' + words(D.band_order[hi]) + ' carats <span class="ldn-jwl-answer__nums">' + usd(plo) + ' to ' + usd(phi) + '</span>'
						: 'No ' + noun + ' ' + plural + ' from ' + words(D.band_order[lo]) + ' to ' + words(D.band_order[hi]) + ' carats in this price range';
				}
				if (subEl) {
					subEl.textContent = keys.length
						? 'Largest in your price range is ' + ct(biggest) + '. Each design is shown at the largest weight that fits.'
						: 'Widen the price range or the carat range.';
				}
				if (titleEl) {
					titleEl.textContent = words(D.band_order[lo]) + ' to ' + words(D.band_order[hi]) + ' carat ' + noun + ' ' + plural;
				}
				if (cntEl) {
					cntEl.textContent = keys.length + ' designs';
				}
			}

			drawPicture();
			var phoneCut = shown <= 24 ? phone : Infinity;
			var gridEl = byId('ldn-exp-grid');
			if (gridEl) {
				gridEl.innerHTML = keys.length
					? keys.slice(0, shown).map(function (k, i) { return renderCard(k, i, phoneCut); }).join('')
					: '<p class="ldn-jwl-empty">No designs match. Widen the price or carat range, or show more metals.</p>';
			}
			var moreEl = byId('ldn-exp-more');
			if (moreEl) {
				var left = keys.length - Math.min(shown, keys.length);
				moreEl.hidden = left <= 0;
				moreEl.textContent = 'Show more ' + plural + ' (' + left + ' more)';
			}
			var doneEl = byId('ldn-exp-done-filters');
			if (doneEl) {
				doneEl.textContent = keys.length ? ('Show ' + keys.length + ' ' + (keys.length === 1 ? (cfg.pieceNoun || 'design') : plural)) : 'No matches';
			}
			var sumEl = byId('ldn-exp-filter-summary');
			var cOutEl = byId('ldn-exp-carat-out');
			if (sumEl && cOutEl) {
				var priceBit = (plo > ext.min || phi < ext.max) ? (usd(plo) + ' to ' + usd(phi)) : 'any price';
				var metalBit = hidden.size ? (METAL_ORDER.filter(function (m) { return !hidden.has(m); }).length + ' metals') : 'all metals';
				sumEl.textContent = cOutEl.textContent + ' \u00b7 ' + priceBit + ' \u00b7 ' + metalBit;
			}
		}

		function renderMetals() {
			var all = new Set();
			Object.values(D.designs).forEach(function (d) {
				(d.metals_available || []).forEach(function (m) { all.add(m); });
			});
			var wrap = byId('ldn-exp-metals');
			if (!wrap) {
				return;
			}
			wrap.innerHTML = METAL_ORDER.filter(function (m) { return all.has(m); }).map(function (m) {
				var on = !hidden.has(m);
				return '<button type="button" class="ldn-jwl-metal" aria-pressed="' + on + '" data-metal="' + m + '">'
					+ '<span class="ldn-jwl-metal__swatch" style="--sw: var(--ldn-metal-' + m + ')"></span>'
					+ '<span class="ldn-jwl-metal__name">' + METAL_LABELS[m] + '</span>'
					+ '<span class="ldn-jwl-metal__icon" aria-hidden="true">' + (on ? '\u00d7' : '+') + '</span></button>';
			}).join('');
			wrap.querySelectorAll('.ldn-jwl-metal').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var m = btn.getAttribute('data-metal');
					if (hidden.has(m)) {
						hidden.delete(m);
					} else {
						hidden.add(m);
					}
					renderMetals();
					render(true);
				});
			});
		}

		function setCarat() {
			var cLo = byId('ldn-exp-carat-lo');
			var cHi = byId('ldn-exp-carat-hi');
			lo = cLo ? +cLo.value : 0;
			hi = cHi ? +cHi.value : lo;
			band = D.band_order[lo];
			var sel = selectedBands();
			var rawMin = Math.min.apply(null, sel.map(function (b) { return D.bands[b].stats.min_price; }));
			var rawMax = Math.max.apply(null, sel.map(function (b) { return D.bands[b].stats.max_price; }));
			var step = rawMax - rawMin > 20000 ? 250 : 100;
			ext = { min: Math.max(step, Math.floor(rawMin / step) * step), max: Math.ceil(rawMax / step) * step, step: step };
			var pLo = byId('ldn-exp-price-lo');
			var pHi = byId('ldn-exp-price-hi');
			if (pLo && pHi) {
				pLo.value = '0';
				pHi.value = String(PRICE_STEPS);
			}
			plo = ext.min;
			phi = ext.max;
			var bMin = byId('ldn-exp-budget-min');
			var bMax = byId('ldn-exp-budget-max');
			if (bMin) {
				bMin.textContent = usd(ext.min);
			}
			if (bMax) {
				bMax.textContent = usd(ext.max);
			}
			render(true);
		}

		function nearestOnTop(dual, a, b) {
			if (!dual || !a || !b) {
				return;
			}
			var pick = function (e) {
				if (e.buttons) {
					return;
				}
				var r = dual.getBoundingClientRect();
				var th = r.height;
				var span = (+a.max - +a.min) || 1;
				var f = (e.clientX - r.left - th / 2) / Math.max(1, r.width - th);
				var fa = (+a.value - +a.min) / span;
				var fb = (+b.value - +b.min) / span;
				var useA = Math.abs(f - fa) < Math.abs(f - fb) || (fa === fb && f < fa);
				a.style.zIndex = useA ? 2 : 1;
				b.style.zIndex = useA ? 1 : 2;
			};
			dual.addEventListener('pointermove', pick);
			dual.addEventListener('pointerdown', pick, true);
		}

		function setType(t) {
			if (!ALL[t]) {
				return;
			}
			type = t;
			D = ALL[t];
			document.querySelectorAll('.ldn-jwl-seg__btn').forEach(function (b) {
				if (b.getAttribute('data-type') === t) {
					b.setAttribute('aria-current', 'page');
				} else {
					b.removeAttribute('aria-current');
				}
			});
			var max = Math.max(0, D.band_order.length - 1);
			var cLo = byId('ldn-exp-carat-lo');
			var cHi = byId('ldn-exp-carat-hi');
			if (cLo && cHi) {
				cLo.max = String(max);
				cHi.max = String(max);
				cLo.value = String(Math.min(lo, max));
				cHi.value = String(Math.min(hi, max));
			}
			var stops = byId('ldn-exp-carat-stops');
			if (stops) {
				stops.innerHTML = D.band_order.map(function (b, i) {
					var at = max > 0 ? (i / max) : 0;
					return '<button type="button" class="ldn-jwl-stop" data-band="' + esc(b) + '" style="--at:' + at + '">' + esc(b) + '</button>';
				}).join('');
				stops.querySelectorAll('.ldn-jwl-stop').forEach(function (st) {
					st.addEventListener('click', function () {
						var idx = D.band_order.indexOf(st.getAttribute('data-band'));
						if (cLo && cHi && idx >= 0) {
							cLo.value = String(idx);
							cHi.value = String(idx);
							setCarat();
						}
					});
				});
			}
			renderMetals();
			setCarat();
		}

		// Instant switcher when both Natural + Lab explorers are loaded; else follow link
		document.querySelectorAll('.ldn-jwl-seg__btn').forEach(function (link) {
			link.addEventListener('click', function (e) {
				var targetType = link.getAttribute('data-type');
				if (ALL[targetType]) {
					e.preventDefault();
					setType(targetType);
				}
			});
		});

		var cLo = byId('ldn-exp-carat-lo');
		var cHi = byId('ldn-exp-carat-hi');
		var pLo = byId('ldn-exp-price-lo');
		var pHi = byId('ldn-exp-price-hi');
		if (cLo && cHi) {
			cLo.addEventListener('input', function () {
				if (+cLo.value > +cHi.value) { cLo.value = cHi.value; }
				setCarat();
			});
			cHi.addEventListener('input', function () {
				if (+cHi.value < +cLo.value) { cHi.value = cLo.value; }
				setCarat();
			});
			nearestOnTop(byId('ldn-exp-carat-dual'), cLo, cHi);
		}
		if (pLo && pHi) {
			pLo.addEventListener('input', function () {
				if (+pLo.value > +pHi.value) { pLo.value = pHi.value; }
				plo = toPrice(+pLo.value);
				render(true);
			});
			pHi.addEventListener('input', function () {
				if (+pHi.value < +pLo.value) { pHi.value = pLo.value; }
				phi = toPrice(+pHi.value);
				render(true);
			});
			nearestOnTop(byId('ldn-exp-price-dual'), pLo, pHi);
		}

		var sortEl = byId('ldn-exp-sort');
		if (sortEl) {
			sortEl.addEventListener('change', function () { render(true); });
		}
		var moreEl = byId('ldn-exp-more');
		if (moreEl) {
			moreEl.addEventListener('click', function () { shown += 24; render(false); });
		}
		document.addEventListener('click', function (e) {
			if (!e.target.closest('.ldn-jwl-tip')) {
				hideTip();
			}
		});

		var gridEl = byId('ldn-exp-grid');
		if (gridEl) {
			gridEl.addEventListener('click', function (e) {
				var why = e.target.closest('.ldn-jwl-pill--why');
				document.querySelectorAll('.ldn-jwl-pill--why[aria-expanded="true"]').forEach(function (p) {
					if (p !== why) { p.setAttribute('aria-expanded', 'false'); }
				});
				if (why) {
					why.setAttribute('aria-expanded', why.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
					return;
				}
				var btn = e.target.closest('.ldn-jwl-wt');
				if (!btn || btn.hasAttribute('aria-current')) {
					return;
				}
				var el = btn.closest('.ldn-jwl-card');
				var k = el.getAttribute('data-key');
				userBand[k] = btn.getAttribute('data-band');
				var fresh = document.createElement('div');
				fresh.innerHTML = renderCard(k, 0, Infinity);
				var next = fresh.firstElementChild;
				if (el.hasAttribute('data-desktop-only')) {
					next.setAttribute('data-desktop-only', '');
				}
				el.replaceWith(next);
			});
		}

		window.addEventListener('resize', drawPicture);

		// Mobile bottom filter sheet + spread toggle
		var rootPage = document.querySelector('.ldn-jewellery-page--explorer') || document.body;
		var openBtn = byId('ldn-exp-open-filters');
		var closeBtn = byId('ldn-exp-close-filters');
		var doneBtn = byId('ldn-exp-done-filters');
		var setFilters = function (open) {
			rootPage.classList.toggle('ldn-jwl-filters-open', open);
			if (openBtn) {
				openBtn.setAttribute('aria-expanded', String(open));
			}
		};
		if (openBtn) {
			openBtn.addEventListener('click', function () { setFilters(true); });
		}
		if (closeBtn) {
			closeBtn.addEventListener('click', function () { setFilters(false); });
		}
		if (doneBtn) {
			doneBtn.addEventListener('click', function () {
				setFilters(false);
				var gt = byId('ldn-exp-grid-title');
				if (gt) { gt.scrollIntoView({ block: 'start' }); }
			});
		}
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { setFilters(false); }
		});

		var spreadBtn = byId('ldn-exp-spread-toggle');
		if (spreadBtn) {
			spreadBtn.addEventListener('click', function () {
				var open = !rootPage.classList.contains('ldn-jwl-spread-open');
				rootPage.classList.toggle('ldn-jwl-spread-open', open);
				spreadBtn.setAttribute('aria-expanded', String(open));
				if (open) { drawPicture(); }
			});
		}

		setType(type);
	}
})();
