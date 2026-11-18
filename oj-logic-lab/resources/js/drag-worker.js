import { moveGroup } from './editing.js?v=1.0.2';
import { previewWireMove } from './wire-move.js?v=1.0.2';
import { previewPointMove } from './wire-points.js?v=1.0.2';
self.onmessage = ({ data: m }) => { try { const result = m.kind === 'group' ? moveGroup(m.project, m.ids, m.x, m.y) : m.kind === 'route' ? previewWireMove(m.project, m.routing, m.segment, m.delta) : previewPointMove(m.project, m.routing, m.net, m.point, m.position); self.postMessage({ id: m.id, result }); } catch { self.postMessage({ id: m.id, result: null }); } };
