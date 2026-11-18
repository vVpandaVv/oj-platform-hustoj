import { Engine } from './engine.js?v=1.0.2';
import { signal, pinWidth } from './model.js?v=1.0.2';

export const modeOf = project => project.settings?.mode === 'beginner' ? 'beginner' : 'advanced';

// Ideal logic is a separate runtime copy. Advanced timing in the saved circuit
// is never overwritten by entering beginner mode.
export class BeginnerEngine extends Engine {
    constructor(project, options = {}) {
        const ideal = structuredClone(project);
        super(ideal, { ...options, ideal: true });
        this.sourceProject = project; this.cycles = 0;
        this.clockIds = this.project.nodes.filter(n => n.config.manualClock).map(n => n.id);
    }
    setInput(id, value) {
        const source = this.sourceProject.nodes.find(n => n.id === id);
        if (!source || !['SWITCH', 'BUS_IN'].includes(source.type)) return;
        source.config.value = signal(value, pinWidth(source, 'Q', true));
        super.setInput(id, value);
    }
    rebind(source) { super.rebind(structuredClone(source)); this.sourceProject = source; }
    pulse() {
        if (!this.clockIds.length || this.fault) return false;
        // Apply all clock sources in one batch so synchronous circuits sample
        // the same pre-edge state regardless of device ordering.
        for (const value of [1, 0]) {
            this.now++;
            for (const id of this.clockIds) this.push({ time: this.now, kind: 'source', node: id, value });
            this.advanceTo(this.now);
            if (this.fault) return false;
        }
        this.cycles++; return true;
    }
}
export const createEngine = project => modeOf(project) === 'beginner' ? new BeginnerEngine(project) : new Engine(project);
