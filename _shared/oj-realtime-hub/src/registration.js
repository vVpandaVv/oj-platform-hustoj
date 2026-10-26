'use strict';

const ID = /^[a-z0-9][a-z0-9-]{0,47}$/;
const ROUTE = /^\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/;
const relativePath = (value) => typeof value === 'string' && /^[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*$/.test(value)
    && !value.split('/').some((part) => part === '.' || part === '..');
function validEnv(env) {
    return env && typeof env === 'object' && !Array.isArray(env)
        && Object.entries(env).every(([key, value]) => /^[A-Z][A-Z0-9_]*$/.test(key) && typeof value === 'string' && !/[\r\n\x00]/.test(value));
}
function validateConfig(config) {
    if (!config || config.protocol !== 1 || !Array.isArray(config.apps) || config.apps.length > 50
        || config.host !== '127.0.0.1' || !Number.isInteger(config.port) || config.port < 1024 || config.port > 65535) throw new Error('Invalid hub configuration');
    const ids = new Set();
    const routes = [];
    for (const app of config.apps) {
        if (!app || !ID.test(app.id) || ids.has(app.id) || !ROUTE.test(app.path) || typeof app.secret !== 'string' || app.secret.length < 32
            || !Array.isArray(app.origins) || app.origins.length !== 1 || typeof app.adapter !== 'string'
            || !relativePath(app.adapter) || !app.adapter.endsWith('.js') || !validEnv(app.env)
            || (app.dataPath !== undefined && (!relativePath(app.dataPath) || app.adapter.startsWith(app.dataPath + '/')))) throw new Error('Invalid addon registration');
        const origin = new URL(app.origins[0]);
        if (!['http:', 'https:'].includes(origin.protocol) || origin.origin !== app.origins[0]) throw new Error('Invalid addon origin');
        if (routes.some((route) => route === app.path || route.startsWith(app.path + '/') || app.path.startsWith(route + '/'))) throw new Error('Addon route collision');
        ids.add(app.id); routes.push(app.path);
    }
    return config;
}

function decode(code) {
    if (!String(code).startsWith('ojhub1.')) throw new Error('Copy the new common server command from plugin settings.');
    const value = JSON.parse(Buffer.from(code.slice(7), 'base64url').toString('utf8'));
    if (!value || value.v !== 1 || !ID.test(value.app) || !validEnv(value.env)) throw new Error('Invalid setup code');
    return value;
}

function register(config, manifest, code) {
    const input = decode(code);
    if (manifest.protocol !== 1 || input.app !== manifest.app || !ID.test(manifest.app)) throw new Error('Installer/addon mismatch');
    const env = input.env;
    const secret = env[manifest.secretKey];
    const app = { id: manifest.app, version: manifest.version, adapter: manifest.adapter, secret, path: env.WS_PATH, origins: [env.ALLOWED_ORIGINS], env };
    if (env.HOST !== '127.0.0.1' || Number(env.PORT) !== 3210) throw new Error('Common hub uses 127.0.0.1:3210. Update the internal URL before installing.');
    const next = structuredClone(config || { protocol: 1, host: '127.0.0.1', port: 3210, apps: [] });
    const old = next.apps.find((entry) => entry.id === app.id);
    if (next.apps.some((entry) => entry.origins[0] !== app.origins[0]) || (old && old.env.WP_API && old.env.WP_API !== env.WP_API)) throw new Error('This hub belongs to a different site.');
    next.apps = next.apps.filter((entry) => entry.id !== app.id).concat(app).sort((a, b) => a.id.localeCompare(b.id));
    return validateConfig(next);
}

module.exports = { validateConfig, decode, register };
