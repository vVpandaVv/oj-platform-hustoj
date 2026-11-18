// One in-flight request plus the newest pointer position: no unbounded queue.
export class DragPlanner {
    constructor() { this.serial = 0; this.current = null; this.latest = null; this.worker = new Worker(new URL('./drag-worker.js?v=1.0.2', import.meta.url), { type: 'module' }); this.worker.onmessage = e => { const item = this.current; this.current = null; if (item) item.resolve(this.latest ? null : e.data.result); if (this.latest) { const next = this.latest; this.latest = null; this.send(next); } }; this.worker.onerror = () => { this.current?.resolve(null); this.latest?.resolve(null); this.current = this.latest = null; this.failed = true; }; }
    send(item) { this.current = item; this.worker.postMessage({ ...item.payload, id: ++this.serial }); }
    request(payload) { if (this.failed) return Promise.resolve(null); return new Promise(resolve => { const item = { payload, resolve }; if (this.current) { this.latest?.resolve(null); this.latest = item; } else this.send(item); }); }
    close() { this.worker.terminate(); this.current?.resolve(null); this.latest?.resolve(null); this.current = this.latest = null; }
}
