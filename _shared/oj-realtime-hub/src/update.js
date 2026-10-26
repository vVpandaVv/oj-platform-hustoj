'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { prepare, readConfig } = require('./prepare');
const { readEnv } = require('./nginx');
const { validateConfig } = require('./registration');

function existing(installed, plugin) {
    const manifest = JSON.parse(fs.readFileSync(path.join(plugin, 'realtime-addon.json'), 'utf8'));
    if (manifest.protocol !== 1 || !/^[a-z0-9][a-z0-9-]{0,47}$/.test(manifest.app)) throw new Error('Invalid addon manifest');
    let config = readConfig(installed);
    if (!config && manifest.app === 'teaching-tools' && fs.existsSync(path.join(installed, '.env'))) {
        const env = readEnv(path.join(installed, '.env'));
        config = validateConfig({ protocol: 1, host: '127.0.0.1', port: Number(env.PORT || 3210), apps: [{
            id: manifest.app, version: 'legacy', adapter: 'legacy-relay.js', secret: env.REALTIME_SECRET,
            path: env.WS_PATH || '/classroom-live', origins: [env.ALLOWED_ORIGINS], env,
        }] });
    }
    const app = config?.apps.find((entry) => entry.id === manifest.app);
    if (!app) throw new Error('This addon is not installed. Run the initial installer with its setup code first.');
    if (app.dataPath !== manifest.dataPath) throw new Error('The addon data layout changed. Use the initial installer to migrate it.');
    return { config, manifest, app };
}

function update(stage, installed, plugin) {
    const { config, manifest, app } = existing(installed, plugin);
    // Reuse the installer copy/data safeguards without taking new credentials.
    const env = { ...app.env, HOST: '127.0.0.1', PORT: '3210', WS_PATH: app.path,
        ALLOWED_ORIGINS: app.origins[0], [manifest.secretKey]: app.secret };
    const code = 'ojhub1.' + Buffer.from(JSON.stringify({ v: 1, app: app.id, env })).toString('base64url');
    const next = prepare(stage, installed, plugin, code);
    next.host = config.host;
    next.port = config.port;
    next.apps = config.apps.map((entry) => entry.id === app.id
        ? { ...entry, version: manifest.version, adapter: manifest.adapter } : entry);
    validateConfig(next);
    fs.writeFileSync(path.join(stage, 'config.json'), JSON.stringify(next, null, 2) + '\n', { mode: 0o640 });
    return next;
}

if (require.main === module) {
    try {
        const [mode, a, b, c] = process.argv.slice(2);
        if (mode === 'port') console.log(existing(a, b).config.port);
        else if (mode === 'prepare') update(a, b, c);
        else throw new Error('Unknown update operation');
    } catch (error) { console.error(error.message); process.exitCode = 1; }
}
module.exports = { existing, update };
