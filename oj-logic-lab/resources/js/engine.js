import { spec, key, inv, combinational, isSequential, isFF, nodeWidth, pinWidth, signal } from './model.js?v=1.0.2';
import { compileProject } from './compile.js?v=1.0.2';

export class Engine {
    constructor(project, options = {}) {
        this.options = options;
        this.displayProject = project;
        const compiled = compileProject(project); project = compiled.project;
        this.aliases = compiled.aliases; this.owners = compiled.owners;
        if (options.ideal) for (const n of project.nodes) {
            Object.assign(n.config, { delay: 0, setup: 0, hold: 0, edge: 'rising' });
            if (n.type === 'CLOCK') { n.type = 'SWITCH'; n.config.value = 0; n.config.manualClock = true; }
        }
        for (const n of project.nodes) if (Object.hasOwn(options.initialState || {}, n.id)) n.config.initial = options.initialState[n.id];
        this.project = project; this.now = 0; this.events = []; this.serial = 0; this.outputs = new Map();
        this.records = new Map(); this.recordLimit = Math.max(1, Math.min(3000, Math.floor(100000 / Math.max(1, this.displayProject.nodes.reduce((sum, n) => sum + (spec(n).outputs.length || spec(n).inputs.length), 0))))); this.previous = new Map(); this.changedAt = new Map(); this.lastEdge = new Map();
        this.generation = new Map(); this.pending = new Map(); this.state = new Map(); this.stateEpoch = new Map(); this.warnings = []; this.fault = ''; this.ended = false;
        this.inputs = new Map(project.wires.map(w => [key(w.to.node, w.to.pin), key(w.from.node, w.from.pin)]));
        this.fanout = new Map();
        for (const w of project.wires) { const k = key(w.from.node, w.from.pin); if (!this.fanout.has(k)) this.fanout.set(k, new Set()); this.fanout.get(k).add(w.to.node); }
        this.nodes = new Map(project.nodes.map(n => [n.id, n]));
        this.outputWidths = new Map(project.nodes.flatMap(n => spec(n).outputs.map(p => [key(n.id, p), pinWidth(n, p, true)])));
        this.inputWidths = new Map(project.nodes.flatMap(n => spec(n).inputs.map(p => [key(n.id, p), pinWidth(n, p, false)])));
        for (const n of project.nodes) {
            for (const p of spec(n).outputs) this.outputs.set(key(n.id, p), signal('X', pinWidth(n, p, true)));
            if (isSequential(n.type)) { const initial = signal(n.config.initial, pinWidth(n, 'Q', true)); this.state.set(n.id, initial); this.outputs.set(key(n.id, 'Q'), initial); this.outputs.set(key(n.id, 'QB'), inv(initial)); }
            if (['SWITCH', 'ZERO', 'ONE', 'CLOCK', 'BUS_IN'].includes(n.type)) this.outputs.set(key(n.id, 'Q'), signal(n.type === 'ONE' ? 1 : ['SWITCH', 'BUS_IN'].includes(n.type) ? n.config.value : 0, pinWidth(n, 'Q', true)));
        }
        for (const n of project.nodes) {
            this.previous.set(n.id, this.readInputs(n));
            if (n.type === 'CLOCK') this.push({ time: n.config.phase, kind: 'clock', node: n.id, value: 1 });
            this.evaluate(n, new Set(), true);
        }
        if (!options.ideal) for (const s of this.displayProject.stimuli || []) this.push({ time: s.time, kind: 'source', node: s.node, value: signal(s.value, this.outputWidths.get(key(s.node, 'Q')) || 1) });
        this.record(); this.advanceTo(0);
    }
    push(e) { if (this.events.length >= 20000) { this.fault = '이벤트 대기열 한도를 초과했습니다. 클록 주기를 늘리거나 지연을 줄이세요.'; return; } e.serial = this.serial++; let lo = 0, hi = this.events.length; while (lo < hi) { const mid = (lo + hi) >>> 1, other = this.events[mid]; if (other.time < e.time || other.time === e.time && other.serial < e.serial) lo = mid + 1; else hi = mid; } this.events.splice(lo, 0, e); }
    rebind(source) { const compiled = compileProject(source); if (this.options.ideal) for (const n of compiled.project.nodes) { Object.assign(n.config, { delay: 0, setup: 0, hold: 0, edge: 'rising' }); if (n.type === 'CLOCK') { n.type = 'SWITCH'; n.config.value = 0; n.config.manualClock = true; } } this.displayProject = source; this.project = compiled.project; this.nodes = new Map(this.project.nodes.map(n => [n.id, n])); this.aliases = compiled.aliases; this.owners = compiled.owners; }
    scheduleState(n, value, invalidate = false) {
        const q = signal(value, pinWidth(n, 'Q', true)), previous = this.state.get(n.id);
        if (invalidate) this.stateEpoch.set(n.id, (this.stateEpoch.get(n.id) || 0) + 1);
        this.state.set(n.id, q);
        if (q === previous && !invalidate) return;
        this.push({ time: this.now + n.config.delay, kind: 'state-output', node: n.id, result: { Q: q, QB: inv(q) }, epoch: this.stateEpoch.get(n.id) || 0 });
    }
    endpoint(id, pin, output) { return this.aliases.get(`${id}:${output}:${pin}`) || { node: id, pin }; }
    value(id, pin = 'Q') { const e = this.endpoint(id, pin, true); return this.outputs.get(key(e.node, e.pin)) ?? signal('X', this.outputWidths.get(key(e.node, e.pin)) || 1); }
    input(id, pin) { const e = this.endpoint(id, pin, false), target = key(e.node, e.pin), k = this.inputs.get(target), width = this.inputWidths.get(target) || 1; return k && this.outputWidths.get(k) === width ? this.outputs.get(k) ?? signal('X', width) : signal('X', width); }
    readInputs(n) { return Object.fromEntries(spec(n).inputs.map(p => [p, p === 'RST' && !this.inputs.has(key(n.id, p)) ? 0 : this.input(n.id, p)])); }
    schedule(n, result, force = false) {
        if (!result) return;
        result = Object.fromEntries(Object.entries(result).map(([p, v]) => [p, signal(v, pinWidth(n, p, true))]));
        const signature = JSON.stringify(result);
        if (!force && this.pending.get(n.id)?.signature === signature) return;
        const gen = (this.generation.get(n.id) || 0) + 1; this.generation.set(n.id, gen);
        this.pending.delete(n.id);
        if (Object.entries(result).every(([p, v]) => this.value(n.id, p) === v)) return;
        const e = { time: this.now + n.config.delay, kind: 'output', node: n.id, result, gen, signature };
        this.pending.set(n.id, e); this.push(e);
    }
    warn(n, kind) {
        if (this.warnings.some(w => w.node === n.id && w.time === this.now && w.kind === kind)) return;
        this.warnings.push({ node: this.owners.get(n.id) || n.id, label: n.label, time: this.now, kind });
        if (this.warnings.length > 200) this.warnings.shift();
    }
    evaluate(n, changed, initial = false) {
        const v = this.readInputs(n), prev = this.previous.get(n.id) || v;
        const data = spec(n).inputs.filter(p => !['CLK', 'RST'].includes(p));
        const dataChanged = data.some(p => changed.has(p));
        let q = this.state.get(n.id) ?? this.value(n.id), result = combinational(n.type, v, nodeWidth(n)), invalidate = false;
        if (isSequential(n.type)) {
            if (v.RST === 1) { q = 0; invalidate = initial || changed.has('RST'); }
            else if (v.RST !== 0) { q = 'X'; invalidate = initial || changed.has('RST'); }
            else if (isFF(n.type)) {
                const edge = n.config.edge === 'falling' ? prev.CLK === 1 && v.CLK === 0 : prev.CLK === 0 && v.CLK === 1;
                const holdViolation = !initial && dataChanged && this.lastEdge.has(n.id) && this.now - this.lastEdge.get(n.id) < n.config.hold;
                if (holdViolation) { this.warn(n, 'hold'); q = 'X'; invalidate = true; }
                if (edge) {
                    this.lastEdge.set(n.id, this.now);
                    const setupViolation = n.config.setup > 0 && data.some(p => this.now - (this.changedAt.get(key(n.id, p)) ?? -Infinity) < n.config.setup);
                    if (setupViolation || holdViolation) { if (setupViolation) this.warn(n, 'setup'); q = 'X'; invalidate = true; }
                    else if (n.type === 'DFF' || n.type === 'WREG') q = typeof v.D === 'string' ? v.D.replaceAll('Z', 'X') : v.D;
                    else if (n.type === 'TFF') q = v.T === 0 ? q : v.T === 1 ? inv(q) : 'X';
                    else if (n.type === 'JKFF') q = v.J === 0 && v.K === 0 ? q : v.J === 0 && v.K === 1 ? 0 : v.J === 1 && v.K === 0 ? 1 : v.J === 1 && v.K === 1 ? inv(q) : 'X';
                    else q = v.S === 0 && v.R === 0 ? q : v.S === 0 && v.R === 1 ? 0 : v.S === 1 && v.R === 0 ? 1 : 'X';
                } else if (!holdViolation && !changed.has('RST') && !initial) { this.previous.set(n.id, v); return; }
            } else if (n.type === 'DLATCH') {
                if (v.EN === 1) q = v.D === 'Z' ? 'X' : v.D;
                else if (v.EN !== 0) q = 'X';
                else if (!changed.has('RST') && !initial) { this.previous.set(n.id, v); return; }
            } else q = v.S === 0 && v.R === 0 ? q : v.S === 1 && v.R === 0 ? 1 : v.S === 0 && v.R === 1 ? 0 : 'X';
            this.previous.set(n.id, v); this.scheduleState(n, q, invalidate); return;
        }
        this.previous.set(n.id, v); this.schedule(n, result);
    }
    setInput(id, value) {
        const n = this.nodes.get(id); if (!n || !['SWITCH', 'BUS_IN'].includes(n.type)) return;
        n.config.value = signal(value, pinWidth(n, 'Q', true)); this.push({ time: this.now, kind: 'source', node: id, value: n.config.value }); this.advanceTo(this.now);
    }
    advanceTo(target) {
        if (this.fault) return;
        target = Math.max(this.now, Math.round(target)); let count = 0;
        while (this.events.length && this.events[0].time <= target) {
            const t = this.events[0].time;
            if (++count > 10000) { this.fault = '이벤트 한도를 초과했습니다. 조합 피드백 또는 클록 설정을 확인하세요.'; return; }
            this.now = t;
            const batch = []; while (this.events.length && this.events[0].time === t) batch.push(this.events.shift());
            const affected = new Set();
            for (const e of batch) {
                if (e.kind === 'output' && e.gen !== this.generation.get(e.node)) continue;
                if (e.kind === 'state-output' && e.epoch !== (this.stateEpoch.get(e.node) || 0)) continue;
                if (e.kind === 'output') this.pending.delete(e.node);
                const values = e.kind === 'output' || e.kind === 'state-output' ? e.result : { Q: e.value };
                for (const [pin, value] of Object.entries(values)) {
                    const k = key(e.node, pin); if (this.outputs.get(k) === value) continue;
                    this.outputs.set(k, value); for (const id of this.fanout.get(k) || []) affected.add(id);
                }
                if (e.kind === 'clock') {
                    const n = this.nodes.get(e.node), high = Math.max(1, Math.min(n.config.period - 1, Math.round(n.config.period * n.config.duty / 100)));
                    this.push({ time: t + (e.value === 1 ? high : n.config.period - high), kind: 'clock', node: e.node, value: inv(e.value) });
                }
            }
            for (const id of affected) {
                const n = this.nodes.get(id), v = this.readInputs(n), prev = this.previous.get(id), changed = new Set();
                for (const p of spec(n).inputs) if (v[p] !== prev[p]) { changed.add(p); this.changedAt.set(key(id, p), t); }
                this.evaluate(n, changed);
            }
            this.record();
        }
        this.now = target;
    }
    step(delta) { this.advanceTo(this.now + delta); }
    next() { if (this.events.length) this.advanceTo(this.events[0].time); }
    record() {
        for (const n of this.displayProject.nodes) {
            const pins = spec(n).outputs.length ? spec(n).outputs : spec(n).inputs;
            for (const p of pins) {
                const k = key(n.id, p), v = spec(n).outputs.length ? this.value(n.id, p) : this.input(n.id, p);
                if (!this.records.has(k)) this.records.set(k, []);
                const a = this.records.get(k); if (a.at(-1)?.value === v) continue;
                if (a.at(-1)?.time === this.now) a.pop();
                a.push({ time: this.now, value: v }); if (a.length > this.recordLimit) a.shift();
            }
        }
    }
}
