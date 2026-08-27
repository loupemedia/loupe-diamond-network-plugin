/**
 * Site-shell drawer and disclosure (PRD-018 CP125).
 *
 * First pass: one toggle with aria-expanded, Escape closes and returns focus,
 * parent items expand in the drawer without navigating. Focus trapping and
 * arrow-key menu traversal are out of scope.
 */
(function () {
	var shell = document.querySelector('.ldn-shell');
	if (!shell) {
		return;
	}

	var toggle = shell.querySelector('.ldn-shell-drawer-toggle');
	var drawer = shell.querySelector('.ldn-shell-drawer');
	if (!toggle || !drawer) {
		return;
	}

	function setDrawer(open) {
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		shell.classList.toggle('ldn-shell--drawer-open', open);
		if (open) {
			drawer.removeAttribute('hidden');
		} else {
			drawer.setAttribute('hidden', 'hidden');
			toggle.focus();
		}
	}

	toggle.addEventListener('click', function () {
		setDrawer(toggle.getAttribute('aria-expanded') !== 'true');
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
			setDrawer(false);
		}
	});

	shell.querySelectorAll('.ldn-shell-disclose').forEach(function (button) {
		button.addEventListener('click', function () {
			var item = button.closest('li');
			if (!item) {
				return;
			}
			var open = item.classList.toggle('ldn-nav-open');
			button.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	});
})();
