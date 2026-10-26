(function () {
    'use strict';
    var memory = {};
    var MAX_AGE = 24 * 60 * 60 * 1000;

    function key(userId, category) { return 'teachingTools.activities.' + (category || 'opened') + '.v1.' + Number(userId); }
    function read(userId, category) {
        var storageKey = key(userId, category);
        var saved = memory[storageKey] || {};
        ['localStorage', 'sessionStorage'].some(function (name) {
            try {
                var value = window[name].getItem(storageKey);
                if (value) { saved = JSON.parse(value); return true; }
            } catch (error) {}
            return false;
        });
        if (!saved || typeof saved !== 'object' || Array.isArray(saved)) { saved = {}; }
        Object.keys(saved).forEach(function (code) {
            if (!/^[A-Z0-9]{6}$/.test(code) || !Number.isFinite(Number(saved[code])) || Date.now() - Number(saved[code]) > MAX_AGE) { delete saved[code]; }
        });
        return saved;
    }
    function remember(code, userId, category, remove) {
        if (!/^[A-Z0-9]{6}$/.test(code || '') || !Number(userId)) { return; }
        var saved = read(userId, category);
        if (remove) { delete saved[code]; }
        else { saved[code] = Date.now(); }
        var codes = Object.keys(saved).sort(function (a, b) { return saved[b] - saved[a]; });
        codes.slice(100).forEach(function (old) { delete saved[old]; });
        memory[key(userId, category)] = saved;
        try { window.localStorage.setItem(key(userId, category), JSON.stringify(saved)); return true; }
        catch (error) {
            try { window.sessionStorage.setItem(key(userId, category), JSON.stringify(saved)); return true; } catch (ignore) { return false; }
        }
    }
    function mark(code, userId) { return remember(code, userId, 'opened'); }
    function isDismissed(code, userId) { return Boolean(read(userId, 'dismissed')[code]); }
    function dismiss(code, userId) { mark(code, userId); return remember(code, userId, 'dismissed'); }
    function resume(code, userId) { return remember(code, userId, 'dismissed', true); }
    function next(items, currentCode, userId) {
        if (!Number(userId)) { return null; }
        // The server orders newest first. Never reopen an older activity after it.
        var latest = items.find(function (item) { return item.autoOpen === true && /^[A-Z0-9]{6}$/.test(item.joinCode || ''); });
        if (!latest) { return null; }
        if (isDismissed(latest.joinCode, userId)) { return null; }
        if (latest.joinCode === currentCode) { mark(currentCode, userId); return null; }
        return read(userId)[latest.joinCode] ? null : latest;
    }
    function open(item, config) {
        var url = new URL(config.joinUrl, window.location.href);
        if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) { return false; }
        if (isDismissed(item.joinCode, config.currentUserId) || read(config.currentUserId)[item.joinCode]) { return false; }
        if (!mark(item.joinCode, config.currentUserId)) { return false; }
        url.searchParams.set('code', item.joinCode);
        window.location.assign(url.href);
        return true;
    }
    window.HttActivityNavigation = { next: next, mark: mark, open: open, dismiss: dismiss, isDismissed: isDismissed, resume: resume };
}());
