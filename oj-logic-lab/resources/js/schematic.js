import { spec, port, height, pinWidth, formatSignal, isSequential } from './model.js?v=1.0.2';
import { routeCircuit } from './router.js?v=1.0.2';
import { gateSymbol, sequentialSymbol } from './symbols.js?v=1.0.2';
const xml = v => String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]));
export function schematicSVG(project, engine = null) {
    if (!project.nodes.length) throw new Error('회로에 장치를 추가한 뒤 회로도를 생성하세요.');
    const routes = routeCircuit(project); if (routes.failures.length) throw new Error('배선 통로가 없는 연결을 수정한 뒤 회로도를 생성하세요.');
    const xs = project.nodes.flatMap(n => [n.x, n.x + 120]), ys = project.nodes.flatMap(n => [n.y - 20, n.y + height(n)]);
    for (const s of routes.segments) { xs.push(s.dir === 'h' ? s.start : s.fixed, s.dir === 'h' ? s.end : s.fixed); ys.push(s.dir === 'v' ? s.start : s.fixed - 10, s.dir === 'v' ? s.end : s.fixed); }
    for (const n of project.notes || []) { xs.push(n.x, n.x + 216); ys.push(n.y, n.y + 210); }
    const x = Math.min(...xs) - 30, y = Math.min(...ys) - 60, width = Math.max(...xs) - x + 30, h = Math.max(...ys) - y + 55;
    let body = `<rect x="${x}" y="${y}" width="${width}" height="${h}" fill="white"/><text x="${x + 20}" y="${y + 28}" font-size="18" font-weight="bold">${xml(project.title)}</text>`;
    for (const s of routes.segments) { const w = project.wires.find(w => `${w.from.node}:${w.from.pin}` === s.net), n = project.nodes.find(n => n.id === w?.from.node), bus = n && pinWidth(n, w.from.pin, true) > 1; body += `<path d="${s.d}" fill="none" stroke="white" stroke-width="8"/><path d="${s.d}" fill="none" stroke="#44536b" stroke-width="${bus ? 3.5 : 1.8}"/>`; }
    for (const dot of routes.junctions) body += `<circle cx="${dot.x}" cy="${dot.y}" r="3.5" fill="#44536b"/>`;
    const named = new Set(); for (const s of routes.segments) { const name = project.netNames?.[s.net]; if (name && !named.has(s.net) && s.end - s.start >= 48) { named.add(s.net); body += `<text x="${s.dir === 'h' ? (s.start + s.end) / 2 : s.fixed + 8}" y="${s.dir === 'h' ? s.fixed - 8 : (s.start + s.end) / 2}" font-size="11" fill="#44536b">${xml(name)}</text>`; } }
    for (const n of project.notes || []) { const lines = n.text.split('\n').flatMap(line => line.match(/.{1,24}/gu) || ['']).slice(0, 12); body += `<rect x="${n.x}" y="${n.y}" width="204" height="${Math.max(36, lines.length * 16 + 16)}" fill="white" stroke="#94a3b8"/>` + lines.map((line, i) => `<text x="${n.x + 8}" y="${n.y + 21 + i * 16}" font-size="12">${xml(line)}</text>`).join(''); }
    for (const n of project.nodes) {
        body += `<g><rect x="${n.x}" y="${n.y}" width="120" height="${height(n)}" rx="6" fill="white" stroke="#44536b"/><text x="${n.x}" y="${n.y - 9}" font-size="12">${xml(n.label)}</text>${sequentialSymbol(n, { x: n.x, y: n.y, ideal: project.settings?.mode === 'beginner' }) || gateSymbol(n.type, { x: n.x + 26, y: n.y + height(n) / 2 - 25, width: 68, height: 46 }) || `<text x="${n.x + 60}" y="${n.y + height(n) / 2 + 4}" text-anchor="middle" font-size="13">${xml(n.type === 'CUSTOM' ? 'MODULE' : n.type)}</text>`}`;
        for (const output of [false, true]) for (const p of spec(n)[output ? 'outputs' : 'inputs']) {
            const at = port(n, p, output), width = pinWidth(n, p, output), custom = n.config.ports?.[output ? 'outputs' : 'inputs']?.find(o => o.name === p), label = custom?.label || p;
            body += `<circle cx="${at.x}" cy="${at.y}" r="3" fill="white" stroke="#44536b"/>${isSequential(n.type) ? '' : `<text x="${at.x + (output ? -9 : 9)}" y="${at.y + 4}" text-anchor="${output ? 'end' : 'start'}" font-size="9">${xml(label)}${width > 1 ? `[${width - 1}:0]` : ''}</text>`}`;
        }
        if (engine && ['SWITCH', 'LED', 'PROBE', 'BUS_IN', 'BUS_OUT'].includes(n.type)) body += `<text x="${n.x + 60}" y="${n.y + height(n) - 10}" text-anchor="middle" font-size="10">${xml(formatSignal(spec(n).outputs.length ? engine.value(n.id) : engine.input(n.id, 'A')))}</text>`;
        body += '</g>';
    }
    body += `<text x="${x + 20}" y="${y + h - 15}" font-size="10" fill="#667085">Logic Lab · 접속점=연결 · 점프 곡선=비접속 · ${engine?.now || 0} ns</text>`;
    return `<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="${x} ${y} ${width} ${h}" width="${width}" height="${h}" font-family="sans-serif" role="img"><title>${xml(project.title)} 회로도</title>${body}</svg>`;
}
