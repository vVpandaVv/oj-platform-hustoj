// Local student UI fixture. Only project persistence is stubbed; the shipped UI and Worker run unchanged.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const workspace = path.dirname(root);
const rows = new Map();
let nextId = 1;
let html;
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    res.setHeader('Cache-Control', 'no-store');
    if (url.pathname === '/' || url.pathname === '/oj-addons/oj-network-lab/learn') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(`<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Network student walkthrough</title><link rel="stylesheet" href="/resources/css/network-lab.css"><style>body{margin:16px;background:#f7f9fc;font-family:Arial,sans-serif}main{max-width:1550px;margin:auto}</style><main>${html}</main><script src="/resources/js/app.js"></script></html>`);
        return;
    }
    if (url.pathname.startsWith('/api/')) {
        res.setHeader('Content-Type', 'application/json');
        if (req.headers['x-wp-nonce'] !== 'test-nonce') { res.writeHead(403); res.end('{}'); return; }
        let body = '';
        req.on('data', c => { body += c; });
        req.on('end', () => {
            try {
                const id = Number(url.pathname.split('/')[3]);
                const data = body ? JSON.parse(body) : {};
                const old = rows.get(id);
                if (req.method === 'GET' && !id) res.end(JSON.stringify({projects: [...rows.values()].map(r => ({id: r.id, client_id: r.clientId, title: r.project.title, revision: r.revision, updated_at: '2026-11-11 00:00:00'}))}));
                else if (req.method === 'GET') { res.statusCode = old ? 200 : 404; res.end(JSON.stringify(old || {})); }
                else if (id && !old) { res.writeHead(404); res.end('{}'); }
                else if (id && data.revision !== old.revision) { res.writeHead(409); res.end('{"message":"Revision conflict"}'); }
                else if (req.method === 'DELETE') { rows.delete(id); res.end('{"deleted":true}'); }
                else {
                    const row = {id: id || nextId++, clientId: data.clientId || old?.clientId, revision: id ? old.revision + 1 : 1, project: data.project};
                    rows.set(row.id, row); res.end(JSON.stringify(row));
                }
            } catch { res.writeHead(400); res.end('{}'); }
        });
        return;
    }
    const book = process.env.OJNL_TEST_BOOK || path.join(workspace, '_releases/readcontents-library/content/network-selfstudy-2026-11-11-v7/network-osi-tcpip-course.html');
    const file = url.pathname === '/book' ? book : path.resolve(root, '.' + decodeURIComponent(url.pathname));
    if ((file !== book && !file.startsWith(root + path.sep)) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404); res.end('Not found'); return; }
    const types = {'.js': 'application/javascript', '.css': 'text/css', '.html': 'text/html', '.json': 'application/json', '.svg': 'image/svg+xml'};
    res.setHeader('Content-Type', (types[path.extname(file)] || 'application/octet-stream') + '; charset=utf-8');
    fs.createReadStream(file).pipe(res);
});
server.listen(Number(process.env.PORT || 8741), '127.0.0.1', () => {
    const origin = `http://127.0.0.1:${server.address().port}`;
    html = execFileSync(process.env.PHP_BINARY || 'D:/php/php.exe', [path.join(__dirname, 'render.php')], {encoding: 'utf8', env: {...process.env, OJNL_TEST_ORIGIN: origin}});
    console.log(origin);
});
