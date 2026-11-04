'use strict';

// Shared test transport. Login fields/nonces and cookies come from native WP;
// callers retain private authentication state only in memory and dispose it.
const assert = require('node:assert/strict');
function decodeHtml(value) { return value.replace(/&quot;/g, '"').replace(/&#0?39;|&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'); }
function attributes(value) { return Object.fromEntries([...value.matchAll(/([\w:-]+)\s*=\s*(["'])(.*?)\2/gs)].map(match => [match[1], decodeHtml(match[3])])); }
async function nativeCookieLogin(playwright, baseUrl, account, options = {}) {
    const request = await playwright.request.newContext({ baseURL: baseUrl, timeout: 60000, ...options });
    try {
        const response = await request.get('/login'); assert.equal(response.status(), 200, 'The actual login page must render.');
        const html = await response.text(); let form;
        for (const match of html.matchAll(/<form\b([^>]*)>([\s\S]*?)<\/form>/gi)) {
            const fields = {};
            for (const input of match[2].matchAll(/<input\b([^>]*)>/gi)) {
                const attrs = attributes(input[1]); if (attrs.name && attrs.type === 'hidden') fields[attrs.name] = attrs.value || '';
            }
            if (fields.action === 'oj_login') { form = { url: new URL(attributes(match[1]).action, baseUrl).href, fields }; break; }
        }
        assert.ok(form, 'The native Platform login form must exist.'); assert.equal(new URL(form.url).origin, new URL(baseUrl).origin);
        const result = await request.post(form.url, { form: { ...form.fields, user_login: account.username, password: account.password }, maxRedirects: 10 });
        assert.equal(result.status(), 200, 'The native login form must complete.');
        assert.ok((await request.storageState()).cookies.some(cookie => cookie.name.startsWith('wordpress_logged_in_')), 'The native WordPress cookie must be issued.');
        return request;
    } catch (error) { await request.dispose(); throw error; }
}
function bootstrap(html) {
    const match = html.match(/<script\b[^>]*\bid=["']focus-tracker-config["'][^>]*>([\s\S]*?)<\/script>/i);
    assert.ok(match, 'A real signed Focus bootstrap must be rendered.'); return JSON.parse(match[1]);
}
function dashboardConfig(html) {
    const tag = html.match(/<[^>]*\bdata-ftk-dashboard\b[^>]*>/i); assert.ok(tag, 'The real teacher dashboard must render.');
    const value = attributes(tag[0])['data-config']; assert.ok(value, 'The teacher nonce/config must come from its native dashboard.');
    return JSON.parse(value);
}
module.exports = { nativeCookieLogin, bootstrap, dashboardConfig };
