(function () {
    'use strict';
    function ready(callback) {
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', callback, { once: true }); }
        else { callback(); }
    }
    ready(function () {
        if (document.querySelector('[data-htt-join]')) { return; }
        var configEl = document.getElementById('htt-global-classroom-config');
        if (!configEl) { return; }
        var config;
        try { config = JSON.parse(configEl.textContent || '{}'); } catch (error) { return; }
        if (!config.canJoin) { return; }
        var panel = null;
        var busy = false;
        var signature = '';
        var joined = new Map();

        function joinOne(item) {
            if (window.HttActivityNavigation && window.HttActivityNavigation.isDismissed(item.joinCode, config.currentUserId)) { return Promise.resolve(false); }
            // Discovery already rechecks membership. A document joins each
            // assigned activity once; only live session heartbeats refresh it.
            if (joined.has(item.joinCode)) { return Promise.resolve(true); }
            return fetch(config.restUrl + '/sessions/' + encodeURIComponent(item.joinCode) + '/join', {
                method: 'POST', headers: { 'X-WP-Nonce': config.nonce }, credentials: 'same-origin',
            }).then(function (response) {
                if (!response.ok) { throw new Error('join failed'); }
                joined.set(item.joinCode, Date.now());
                return true;
            }).catch(function () { return false; });
        }

        function joinAssigned(items) {
            var active = new Set(items.map(function (item) { return item.joinCode; }));
            joined.forEach(function (value, code) { if (!active.has(code)) { joined.delete(code); } });
            // Only use server-assigned codes; discovery is not a code-guessing loop.
            return items.reduce(function (pending, item) {
                return pending.then(function () {
                    return joinOne(item);
                });
            }, Promise.resolve());
        }

        function discover(force) {
            if (busy || document.visibilityState === 'hidden') { return; }
            busy = true;
            var discovery = window.HttDiscovery ? window.HttDiscovery.read(config, force === true) : fetch(config.restUrl + '/sessions/assigned', {
                headers: { 'X-WP-Nonce': config.nonce }, credentials: 'same-origin',
            }).then(function (response) {
                if (!response.ok) { throw new Error('discovery failed'); }
                return response.json();
            });
            discovery.then(function (data) {
                if (!data) { return; }
                var assigned = (data.items || []).filter(function (item) { return /^[A-Z0-9]{6}$/.test(item.joinCode || ''); });
                var navigation = window.HttActivityNavigation;
                var automatic = navigation && navigation.next(assigned, '', config.currentUserId);
                if (automatic) {
                    return joinOne(automatic).then(function (success) { if (success) { navigation.open(automatic, config); } });
                }
                var items = assigned.filter(function (item) { return item.toolType !== 'classroom'; });
                var nextSignature = JSON.stringify(items);
                var joining = joinAssigned(items);
                if (nextSignature === signature) { return joining; }
                signature = nextSignature;
                if (panel) { panel.remove(); panel = null; }
                if (!items.length) { return; }
                panel = document.createElement('aside');
                panel.className = 'htt-assigned-activities';
                panel.setAttribute('aria-label', '배정된 수업 활동');
                var title = document.createElement('strong');
                title.textContent = '배정된 수업 활동';
                panel.appendChild(title);
                items.forEach(function (item) {
                    var link = document.createElement('a');
                    var url = new URL(config.joinUrl, window.location.href);
                    url.searchParams.set('code', item.joinCode);
                    link.href = url.href;
                    link.textContent = item.label + (item.contextLabel ? ' · ' + item.contextLabel : '');
                    panel.appendChild(link);
                });
                document.body.appendChild(panel);
                return joining;
            }).catch(function () {}).finally(function () { busy = false; });
        }
        discover();
        window.setInterval(discover, 5000);
        document.addEventListener('visibilitychange', function () { discover(true); });
    });
}());
