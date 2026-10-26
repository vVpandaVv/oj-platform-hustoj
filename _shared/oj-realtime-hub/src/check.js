'use strict';
const fs = require('node:fs');
const { signature } = require('./protocol');
const config = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
(async () => {
    for (const app of config.apps) {
        const time = String(Math.floor(Date.now() / 1000));
        const response = await fetch(`http://${config.host}:${config.port}${app.path}/status`, {
            headers: { 'x-oj-realtime-time': time, 'x-oj-realtime-signature': signature(app.secret, 'GET', '/status', time) },
            signal: AbortSignal.timeout(3000),
        });
        const data = await response.json();
        if (!response.ok || data.service !== 'oj-realtime-hub' || data.app !== app.id || !data.authenticated) throw new Error('Addon authentication failed: ' + app.id);
    }
})().catch((error) => { console.error(error.message); process.exitCode = 1; });
