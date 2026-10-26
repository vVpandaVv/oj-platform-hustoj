'use strict';

const fs = require('node:fs');
const path = require('node:path');
const config = require('./config');
const { signRequest } = require('./sign');

// Talks back to the WordPress plugin: round results go to
// POST {WP_API}/server/results, the free-room problem bank comes from
// GET {WP_API}/server/bank. Both are HMAC-signed with ARENA_SECRET.

const UNSENT = path.join(__dirname, '..', 'data', 'unsent-results.jsonl');
const FALLBACK_BANK = path.join(__dirname, '..', '..', 'data', 'default-bank.json');
const RETRY_MS = [2000, 10000, 60000];

let bankCache = null;
let bankAt = 0;

async function call(method, route, body) {
    const raw = body === undefined ? '' : JSON.stringify(body);
    const { time, signature } = signRequest(config.secret, method, route, raw);
    const res = await fetch(config.wpApi + route, {
        method,
        headers: {
            'content-type': 'application/json',
            'x-arena-time': time,
            'x-arena-signature': signature,
        },
        body: method === 'GET' ? undefined : raw,
        signal: AbortSignal.timeout(15000),
    });
    if (!res.ok) throw new Error(`${method} ${route} → HTTP ${res.status}`);
    return res.json();
}

function keepUnsent(result, error) {
    try {
        fs.mkdirSync(path.dirname(UNSENT), { recursive: true });
        fs.appendFileSync(UNSENT, JSON.stringify({ at: new Date().toISOString(), error: String(error), result }) + '\n');
    } catch (e) {
        console.error('[wp] could not keep unsent result:', e.message);
    }
}

function postResult(result, attempt = 0) {
    if (!config.wpApi || !config.secret) return;
    call('POST', '/server/results', result).catch((err) => {
        if (attempt < RETRY_MS.length) {
            setTimeout(() => postResult(result, attempt + 1), RETRY_MS[attempt]).unref?.();
        } else {
            console.error('[wp] result not delivered:', err.message);
            keepUnsent(result, err.message);
        }
    });
}

function readFallbackBank() {
    try {
        return JSON.parse(fs.readFileSync(FALLBACK_BANK, 'utf8'));
    } catch {
        return { normal: [{ code: "print('hello')", tags: ['출력'] }], boss: [] };
    }
}

// Bank for free rooms. Uses the WordPress bank when reachable, else the
// last good copy, else the default bank shipped with the plugin.
async function bank() {
    if (bankCache && Date.now() - bankAt < config.bankCacheMs) return bankCache;
    if (config.wpApi && config.secret) {
        try {
            const data = await call('GET', '/server/bank');
            if (data && Array.isArray(data.normal) && data.normal.length) {
                bankCache = { normal: data.normal, boss: Array.isArray(data.boss) ? data.boss : [], tags: data.tags || [] };
                bankAt = Date.now();
                return bankCache;
            }
        } catch (err) {
            console.error('[wp] bank fetch failed:', err.message);
        }
    }
    if (bankCache) return bankCache;
    const fallback = readFallbackBank();
    bankCache = fallback;
    bankAt = Date.now() - config.bankCacheMs + 30000; // retry WordPress in 30 s
    return fallback;
}

module.exports = { postResult, bank, readFallbackBank };
