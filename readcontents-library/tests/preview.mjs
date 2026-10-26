import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const workspace = path.dirname(root);
const port = Number(process.env.PORT || 8744);
let nodes, documents, mutations, failSave, failDelete;
function reset() {
  nodes = new Map(); documents = new Map(); mutations = []; failSave = failDelete = false;
  for (const base of [0, 100]) {
    const titles = ['읽기자료 A', '첫 번째 챕터', '첫 번째 장', '첫 번째 레슨', '두 번째 레슨'];
    ['topic', 'chapter', 'section', 'lesson', 'lesson'].forEach((type, index) => {
      const id = String(base + index + 1), parentId = index === 0 ? null : String(base + Math.min(index, 3));
      const node = { id, parentId, type, title: base ? `보조 ${titles[index]}` : titles[index], status: base ? 'draft' : 'published', slug: `item-${id}`, order: index, contentType: 'default', schemaVersion: '1.0.0', metadata: {} };
      nodes.set(id, node);
      if (type === 'lesson') documents.set(id, { lessonId: id, revision: 1, schemaVersion: '1.0.0', plateValue: [{ type: 'p', children: [{ text: `${node.title}의 보존할 본문입니다.` }] }], assets: [], visuals: [] });
    });
  }
}
reset();
const children = (id) => [...nodes.values()].filter(n => n.parentId === id && n.status !== 'archived');
const tree = topic => ({ topic, chapters: children(topic.id).map(chapter => ({ chapter, sections: children(chapter.id).map(section => ({ section, lessons: children(section.id) })) })) });
function remove(id) { for (const child of [...nodes.values()].filter(n => n.parentId === id)) remove(child.id); nodes.delete(id); documents.delete(id); }
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://127.0.0.1:${port}`);
  const send = (data, status = 200) => { res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' }); res.end(JSON.stringify(data)); };
  if (url.pathname === '/test/reset') { reset(); send({ ok: true }); return; }
  if (url.pathname === '/test/state') { send({ nodes: [...nodes.values()], documents: Object.fromEntries(documents), mutations }); return; }
  if (url.pathname === '/test/fail-save') { failSave = true; send({ ok: true }); return; }
  if (url.pathname === '/test/fail-delete') { failDelete = true; send({ ok: true }); return; }
  if (url.pathname.startsWith('/wp-json/')) {
    let raw = ''; for await (const chunk of req) raw += chunk;
    const body = raw ? JSON.parse(raw) : {};
    const route = url.pathname.replace('/wp-json/readcontents-library/v1', '');
    if (req.method !== 'GET') {
      mutations.push({ route, method: req.method, body, nonce: req.headers['x-wp-nonce'] });
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    if (route === '/topics') send([...nodes.values()].filter(n => n.type === 'topic' && n.status !== 'archived').map(tree));
    else if (route === '/nodes') send([...nodes.values()].filter(n => (!url.searchParams.has('status') || n.status === url.searchParams.get('status')) && (!url.searchParams.has('type') || n.type === url.searchParams.get('type'))));
    else if (/^\/lessons\/\d+\/document$/.test(route)) {
      const id = route.split('/')[2];
      if (req.method === 'PUT' && failSave) { send({ message: '저장 실패 테스트', code: 'save_failed' }, 500); return; }
      if (req.method === 'PUT') documents.set(id, { ...body, lessonId: id, revision: documents.get(id).revision + 1 });
      send(documents.get(id));
    } else if (/^\/nodes\/\d+(?:\/(?:restore|delete))?$/.test(route)) {
      const id = route.split('/')[2], node = nodes.get(id);
      if (!node) { send({ message: '항목이 없습니다.' }, 404); return; }
      if (route.endsWith('/restore')) { node.status = 'draft'; send(node); }
      else if (route.endsWith('/delete')) {
        if (failDelete) { send({ message: '삭제 실패 테스트' }, 500); return; }
        const before = [...nodes.values()]; remove(id);
        const deleted = { topics: 0, chapters: 0, sections: 0, lessons: 0 };
        for (const item of before) if (!nodes.has(item.id)) deleted[`${item.type}s`]++;
        send({ node, deleted });
      } else { node.status = 'archived'; send(node); }
    } else send({ message: `Unmocked endpoint: ${route}` }, 404);
    return;
  }
  if (url.pathname === '/') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(`<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>읽기자료 보관·삭제 검증</title>
      <link rel="stylesheet" href="/host.css"><link rel="stylesheet" href="/assets/editor/admin.css"><link rel="stylesheet" href="/assets/editor/workspace-layout.css">
      <style>body{margin:0;background:white}main{padding:16px;max-width:1440px;margin:auto;min-width:0}</style></head><body><main>
      <div id="contents-editor-admin-root" class="rcis-editor-root" data-rest-url="http://127.0.0.1:${port}/wp-json/" data-rest-namespace="readcontents-library/v1" data-rest-nonce="test-nonce" data-can-publish="${url.searchParams.has('no-publish') ? '0' : '1'}" data-can-reimport-json="0" data-can-import-server="0" data-version="3.3.4"></div><div id="contents-editor-overlay-root"></div>
      </main>${url.searchParams.has('native') ? '' : '<script src="/host.js"></script>'}<script type="module" src="/assets/editor/admin.js"></script></body></html>`);
    return;
  }
  let file = path.resolve(root, url.pathname.slice(1));
  if (url.pathname === '/host.js') file = path.join(workspace, 'oj-integration-rebuild/oj-platform/resources/ui/js/oj-ui.js');
  else if (url.pathname === '/host.css') file = path.join(workspace, 'oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css');
  else if (!file.startsWith(root + path.sep)) { res.writeHead(403); res.end(); return; }
  if (!fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404); res.end(); return; }
  res.setHeader('Content-Type', { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' }[path.extname(file)] || 'application/octet-stream');
  fs.createReadStream(file).pipe(res);
});
server.listen(port, '127.0.0.1', () => console.log(`Preview http://127.0.0.1:${port}`));
