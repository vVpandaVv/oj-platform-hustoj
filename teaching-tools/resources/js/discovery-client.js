(function (window, document) {
    'use strict';
    if (window.HttDiscovery) { return; }
    var entries = new Map();

    // Both global widgets share one authenticated response. This cache lives
    // only in the current document and never bypasses server authorization.
    window.HttDiscovery = {
        read: function (config, force) {
            var key = String(config.restUrl) + ':' + String(config.currentUserId || config.currentStudentId || '') + ':' + String(config.nonce);
            var entry = entries.get(key);
            if (!entry) {
                entry = { pending: null, data: null, error: null, nextAt: 0, lastAt: -Infinity, idle: 0 };
                entries.set(key, entry);
            }
            if (entry.pending) { return entry.pending; }
            if (document.visibilityState === 'hidden') { return Promise.resolve(entry.data); }
            var now = Date.now();
            if (now < entry.nextAt && (!force || now - entry.lastAt < 1000)) {
                return entry.error ? Promise.reject(entry.error) : Promise.resolve(entry.data);
            }
            var abort = new AbortController();
            var timer = window.setTimeout(function () { abort.abort(); }, 10000);
            entry.pending = window.fetch(String(config.restUrl).replace(/\/$/, '') + '/discovery', {
                headers: { 'X-WP-Nonce': config.nonce }, credentials: 'same-origin', signal: abort.signal,
            }).then(function (response) {
                if (!response.ok) { throw new Error('discovery failed'); }
                return response.json();
            }).then(function (data) {
                entry.data = data;
                entry.error = null;
                entry.lastAt = Date.now();
                var active = (data.items || []).length > 0 || Boolean(data.classroom && data.classroom.active);
                entry.idle = active ? 0 : Math.min(4, entry.idle + 1);
                entry.nextAt = entry.lastAt + (active ? 5000 : Math.min(30000, 5000 * Math.pow(2, entry.idle - 1)));
                return data;
            }).catch(function (error) {
                entry.error = error;
                entry.lastAt = Date.now();
                entry.nextAt = Date.now() + 10000;
                throw error;
            }).finally(function () {
                window.clearTimeout(timer);
                entry.pending = null;
            });
            return entry.pending;
        },
    };
})(window, document);
