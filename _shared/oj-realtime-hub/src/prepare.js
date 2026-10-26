'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { register, validateConfig } = require('./registration');
const { readEnv, plan } = require('./nginx');

function child(root, relative) {
    if (typeof relative !== 'string' || path.isAbsolute(relative)) throw new Error('Invalid module path');
    const result = path.resolve(root, relative);
    if (!result.startsWith(path.resolve(root) + path.sep)) throw new Error('Module path escapes root');
    return result;
}
function copy(source, destination) {
    if (fs.lstatSync(source).isSymbolicLink()) throw new Error('Linked runtime files are not supported');
    if (fs.statSync(source).isDirectory()) {
        fs.mkdirSync(destination, { recursive: true });
        for (const name of fs.readdirSync(source)) {
            if (['node_modules', '.git', '.env', '.env.bak'].includes(name)) continue;
            copy(path.join(source, name), path.join(destination, name));
        }
    } else { fs.mkdirSync(path.dirname(destination), { recursive: true }); fs.copyFileSync(source, destination); }
}
function readConfig(directory) {
    const file = path.join(directory, 'config.json');
    return fs.existsSync(file) ? validateConfig(JSON.parse(fs.readFileSync(file, 'utf8'))) : null;
}
function migrateRelay(stage, installed) {
    const envFile = path.join(installed, '.env');
    if (!fs.existsSync(envFile)) return null;
    const env = readEnv(envFile);
    if (!env.REALTIME_SECRET) return null;
    const root = path.join(stage, 'modules/teaching-tools');
    copy(path.join(installed, 'src'), path.join(root, 'realtime-server/src'));
    copy(path.join(installed, 'package.json'), path.join(root, 'realtime-server/package.json'));
    const legacyLicense = path.join(installed, 'LICENSE.txt');
    copy(fs.existsSync(legacyLicense) ? legacyLicense : path.join(__dirname, '../LICENSE.txt'), path.join(root, 'realtime-server/LICENSE.txt'));
    copy(path.join(__dirname, 'legacy-relay.js'), path.join(root, 'legacy-relay.js'));
    return validateConfig({ protocol: 1, host: '127.0.0.1', port: 3210, apps: [{ id: 'teaching-tools', version: 'legacy', adapter: 'legacy-relay.js', secret: env.REALTIME_SECRET, path: env.WS_PATH || '/classroom-live', origins: [env.ALLOWED_ORIGINS], env }] });
}
function prepare(stage, installed, plugin, code) {
    const manifest = JSON.parse(fs.readFileSync(path.join(plugin, 'realtime-addon.json'), 'utf8'));
    let config = readConfig(installed);
    if (config) copy(path.join(installed, 'modules'), path.join(stage, 'modules'));
    else config = migrateRelay(stage, installed);
    const next = register(config, manifest, code);
    const root = path.join(stage, 'modules', manifest.app);
    const retainedData = path.join(stage, '.retained-data');
    const old = config?.apps.find((app) => app.id === manifest.app);
    if (old?.dataPath && fs.existsSync(child(root, old.dataPath))) copy(child(root, old.dataPath), retainedData);
    fs.rmSync(root, { recursive: true, force: true });
    for (const item of manifest.include) copy(child(plugin, item), child(root, item));
    const app = next.apps.find((entry) => entry.id === manifest.app);
    if (manifest.dataPath) {
        app.dataPath = manifest.dataPath;
        fs.mkdirSync(child(root, app.dataPath), { recursive: true });
        if (fs.existsSync(retainedData)) { copy(retainedData, child(root, app.dataPath)); fs.rmSync(retainedData, { recursive: true }); }
    }
    // Preserve operator tuning while replacing only this addon's connection credentials.
    app.env = { ...old?.env, ...app.env };
    validateConfig(next);
    fs.writeFileSync(path.join(stage, 'config.json'), JSON.stringify(next, null, 2) + '\n', { mode: 0o640 });
    return next;
}
function nginxPlan(stage, siteFile, snippetDirectory) {
    const config = readConfig(stage);
    let site = fs.readFileSync(siteFile, 'utf8');
    const snippets = [];
    for (const app of config.apps) {
        const name = app.id === 'teaching-tools' ? 'oj-realtime-hub.conf' : app.id === 'oj-code-arena' ? 'oj-code-arena.conf' : `oj-realtime-${app.id}.conf`;
        const env = { ...app.env, PORT: String(config.port), HOST: config.host, WS_PATH: app.path,
            ALLOWED_ORIGINS: app.origins[0], WP_API: app.env.WP_API || app.origins[0] };
        const result = plan(env, site, path.posix.join(snippetDirectory, name));
        site = result.site;
        snippets.push({ name, text: result.snippet });
    }
    return { site, snippets };
}
function refreshData(stage, installed, legacyArena) {
    const config = readConfig(stage);
    for (const app of config.apps) {
        if (!app.dataPath) continue;
        const relative = `modules/${app.id}/${app.dataPath}`;
        const current = child(installed, relative);
        if (fs.existsSync(current)) {
            fs.rmSync(child(stage, relative), { recursive: true, force: true });
            copy(current, child(stage, relative));
        }
        else if (app.id === 'oj-code-arena' && legacyArena && fs.existsSync(path.join(legacyArena, 'game-server/.env'))) {
            const env = readEnv(path.join(legacyArena, 'game-server/.env'));
            if (env.WP_API !== app.env.WP_API) throw new Error('Legacy arena belongs to a different site');
            app.env = { ...env, ...app.env };
            const data = path.join(legacyArena, 'game-server/data');
            if (fs.existsSync(data)) copy(data, child(stage, relative));
        }
    }
    validateConfig(config);
    fs.writeFileSync(path.join(stage, 'config.json'), JSON.stringify(config, null, 2) + '\n', { mode: 0o640 });
}
if (require.main === module) {
    try {
        const [mode, stage, a, b, c] = process.argv.slice(2);
        if (mode === 'register') prepare(stage, a, b, c || fs.readFileSync(0, 'utf8').trim());
        else if (mode === 'nginx') {
            const result = nginxPlan(stage, a, b);
            fs.mkdirSync(path.join(stage, '.nginx'), { recursive: true });
            fs.writeFileSync(path.join(stage, '.nginx/site'), result.site);
            for (const item of result.snippets) fs.writeFileSync(path.join(stage, '.nginx', item.name), item.text);
        } else if (mode === 'data') refreshData(stage, a, b);
        else throw new Error('Unknown installer operation');
    } catch (error) { console.error(error.message); process.exit(1); }
}
module.exports = { child, copy, readConfig, prepare, migrateRelay, nginxPlan, refreshData };
