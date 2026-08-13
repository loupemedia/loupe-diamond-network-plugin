/**
 * Natural / lab-grown toggle — instant active-pill feedback on click while
 * the browser navigates to the sibling diamond-type page.
 */
(function () {
	'use strict';

	var ACTIVE = 'ldn-nat-lab-toggle__pill--active';
	var PENDING = 'ldn-nat-lab-toggle--pending';

	function bind(nav) {
		nav.addEventListener('click', function (event) {
			var pill = event.target.closest
				? event.target.closest('.ldn-nat-lab-toggle__pill')
				: null;
			if (!pill || !nav.contains(pill)) {
				return;
			}
			if (pill.classList.contains(ACTIVE)) {
				return;
			}
			var pills = nav.querySelectorAll('.ldn-nat-lab-toggle__pill');
			for (var i = 0; i < pills.length; i++) {
				pills[i].classList.remove(ACTIVE);
				pills[i].removeAttribute('aria-current');
			}
			pill.classList.add(ACTIVE);
			pill.setAttribute('aria-current', 'page');
			nav.classList.add(PENDING);
		});
	}

	function init() {
		var nodes = document.querySelectorAll('.ldn-nat-lab-toggle');
		for (var i = 0; i < nodes.length; i++) {
			bind(nodes[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
