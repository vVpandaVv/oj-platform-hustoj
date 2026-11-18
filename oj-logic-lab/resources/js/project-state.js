import { validateProject } from './model.js?v=1.0.2';

// Geometry and presentation are deliberately outside the simulation contract.
export function simulationSignature(project) {
    const circuit = p => ({ nodes: p.nodes.map(n => ({ id: n.id, type: n.type, config: n.config })).sort((a, b) => a.id.localeCompare(b.id)), wires: p.wires.map(w => ({ from: w.from, to: w.to })).sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b))) });
    return JSON.stringify({ ...circuit(project), components: (project.components || []).map(d => ({ id: d.id, ...circuit(d), inputs: d.inputs, outputs: d.outputs })), stimuli: project.stimuli || [], mode: project.settings.mode });
}

// Only committed changes are written. Animation, dragging previews and clock
// ticks never write storage or issue a server/DB request.
export class RecoveryStore {
    constructor(storage, key) { this.storage = storage; this.key = key; this.last = null; this.pending = null; this.timer = null; this.error = ''; try { this.saved = storage?.getItem(key) || ''; } catch { this.saved = ''; this.error = '브라우저 저장 공간을 사용할 수 없습니다.'; } }
    read(suffix) { try { const raw = this.storage?.getItem(this.key + suffix); return raw ? JSON.parse(raw) : null; } catch { return null; } }
    draft() { const item = this.read(':draft'); if (!item?.project || JSON.stringify(item.project) === this.saved) return null; try { return { ...item, project: validateProject(item.project) }; } catch { return null; } }
    backups() { const items = this.memoryBackups || this.read(':backups'); return Array.isArray(items) ? items.slice(0, 3).flatMap(item => { try { return [{ ...item, project: validateProject(item.project) }]; } catch { return []; } }) : []; }
    archive(project) { const items = this.backups(), data = JSON.stringify(project); if (items[0] && JSON.stringify(items[0].project) === data) return true; this.memoryBackups = [{ time: Date.now(), project: structuredClone(project) }, ...items].slice(0, 3); try { this.storage.setItem(this.key + ':backups', JSON.stringify(this.memoryBackups)); return true; } catch { this.error = '전환 전 사본은 현재 창에서만 유지합니다. JSON으로 내보내세요.'; return false; } }
    schedule(project, callback = () => {}) { this.pending = JSON.stringify(project); clearTimeout(this.timer); this.timer = setTimeout(() => { this.flush(); callback(); }, 500); }
    flush() { clearTimeout(this.timer); if (this.pending === null) return !this.error; try { if (this.pending !== this.last) this.storage.setItem(this.key + ':draft', JSON.stringify({ time: Date.now(), project: JSON.parse(this.pending) })); this.last = this.pending; this.pending = null; this.error = ''; return true; } catch { this.error = '자동 임시 저장에 실패했습니다. JSON으로 내보내세요.'; return false; } }
    save(project) { const data = JSON.stringify(project); this.storage.setItem(this.key, data); this.saved = data; this.pending = data; this.flush(); }
    dirty(project) { return JSON.stringify(project) !== this.saved; }
}
