/**
 * Client-side ad click tracking (CP 55).
 *
 * Sends click events to the hub track endpoint, then follows the outbound URL.
 */
(function () {
    'use strict';

    var config = window.ldnAdTracking || {};
    var endpoint = config.trackUrl || '';

    function trackClick(payload) {
        if (!endpoint) {
            return;
        }
        var body = JSON.stringify(payload);
        if (navigator.sendBeacon) {
            var blob = new Blob([body], { type: 'application/json' });
            navigator.sendBeacon(endpoint, blob);
            return;
        }
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: body,
            keepalive: true,
        }).catch(function () {});
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a.ldn-ad-link');
        if (!link) {
            return;
        }
        var slot = link.closest('.ldn-ad-slot');
        if (!slot) {
            return;
        }

        var clickUrl = slot.getAttribute('data-ldn-click-url') || link.getAttribute('href') || '';
        if (!clickUrl) {
            return;
        }

        trackClick({
            event_type: 'click',
            ad_slot_id: slot.getAttribute('data-ldn-layout-slot') || '',
            ad_id: slot.getAttribute('data-ldn-ad-id') || '',
            site_id: slot.getAttribute('data-ldn-site-id') || '',
            country_code: slot.getAttribute('data-ldn-country') || '',
            page_url: window.location.href,
            diamond_type: slot.getAttribute('data-ldn-diamond-type') || null,
        });

        if (link.getAttribute('href') !== clickUrl) {
            link.setAttribute('href', clickUrl);
        }
    });
})();
