'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { createHost } = require('./host');
const { validateConfig } = require('./registration');
const file = process.env.OJ_REALTIME_CONFIG || path.join(__dirname, '../config.json');
const settings = validateConfig(JSON.parse(fs.readFileSync(file, 'utf8')));
const host = createHost(settings, { root: path.dirname(file) });
host.server.listen(settings.port, settings.host, () => console.log(JSON.stringify({
    time: new Date().toISOString(), level: 'info', event: 'server-started',
    host: settings.host, port: settings.port, apps: settings.apps.length,
})));
for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => host.close().then(() => process.exit(0)));
