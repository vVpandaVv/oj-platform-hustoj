/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
/* Cancellable, serialized Worker transport. No Python executes on the UI thread. */
(function (host) {
    'use strict';
    class OclWorkerRuntime {
        constructor(options) {
            this.options = options;
            this.queue = [];
            this.active = null;
            this.worker = null;
            this.ready = null;
            this.nextId = 1;
            this.sync = null;
            this.waitingInput = null;
            this.waitingDebug = null;
        }
        run(cellId, code, onStream, onStart) {
            return this.enqueue('run', { code: code }, { cellId, onStream, onStart });
        }
        // Like run(), but Python pauses before each line (or, given
        // breakpoints, at the first one) and calls onPause(snapshot, resume);
        // resume({action, breakpoints}) continues. Resolves with `debugEnd`,
        // the final or failing-line snapshot.
        debug(cellId, code, onStream, onStart, onPause, breakpoints) {
            if (!this.canShare()) {
                return Promise.reject(new Error('디버깅에는 HTTPS와 cross-origin isolation 지원이 필요합니다.'));
            }
            return this.enqueue('debug', { code: code, breakpoints: breakpoints || [] }, { cellId, onStream, onStart, onPause });
        }
        canShare() {
            return typeof SharedArrayBuffer !== 'undefined' && (this.options.sharedInput === true || host.crossOriginIsolated === true);
        }
        writeFile(fileName, bytes) {
            // Never allow a dataset to overwrite interpreter/site files.
            const name = String(fileName).split(/[\\/]/).pop();
            if (!name || name === '.' || name === '..' || name.includes('\0')) {
                return Promise.reject(new Error('데이터 파일명이 올바르지 않습니다.'));
            }
            return this.enqueue('writeFile', { fileName: name, bytes: bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) }, {});
        }
        enqueue(type, payload, callbacks) {
            if (this.queue.length >= 100) { return Promise.reject(new Error('실행 대기열이 가득 찼습니다.')); }
            return new Promise((resolve, reject) => {
                this.queue.push({ id: this.nextId++, type, payload, callbacks, resolve, reject });
                this.pump();
            });
        }
        pump() {
            if (this.active || !this.queue.length) { return; }
            const job = this.active = this.queue.shift();
            this.ensure().then(() => {
                if (this.active !== job) { return; }
                if (job.callbacks.onStart) { job.callbacks.onStart(); }
                this.worker.postMessage(Object.assign({ type: job.type, id: job.id }, job.payload));
            }).catch(error => {
                if (this.active === job) { this.stop(error.message); }
            });
        }
        ensure() {
            if (this.ready) { return this.ready; }
            this.ready = new Promise((resolve, reject) => {
                this.rejectInit = reject;
                let worker;
                try {
                    worker = this.options.workerFactory ? this.options.workerFactory(this.options.url) : new Worker(this.options.url);
                } catch (error) { reject(new Error('이 브라우저에서 실행 환경을 시작하지 못했습니다.')); return; }
                this.worker = worker;
                this.sync = this.canShare() ? new Int32Array(new SharedArrayBuffer(65544)) : null;
                this.initTimer = setTimeout(() => {
                    if (this.worker === worker) { this.stop('실행 환경을 불러오는 시간이 초과되었습니다. 다시 실행해 주세요.'); }
                }, this.options.initTimeout || 60000);
                worker.onerror = () => {
                    if (this.worker === worker) { this.stop('실행 환경에 오류가 발생했습니다. 다시 실행해 주세요.'); }
                };
                worker.onmessage = event => {
                    if (this.worker !== worker) { return; }
                    const data = event.data || {};
                    if (data.type === 'ready') {
                        clearTimeout(this.initTimer); this.rejectInit = null; resolve(); return;
                    }
                    if (data.type === 'init-error') { this.stop(data.message || '실행 환경 초기화 실패'); return; }
                    const job = this.active;
                    if (!job || data.id !== job.id) { return; }
                    if (data.type === 'stream' && job.callbacks.onStream) { job.callbacks.onStream(data.text); }
                    if (data.type === 'status' && this.options.onStatus) { this.options.onStatus(data.text); }
                    if (data.type === 'input-request' && this.options.onInput) {
                        this.waitingInput = job.id;
                        this.options.onInput(job.callbacks.cellId, value => this.submitInput(job.id, value), () => this.submitInput(job.id, null));
                    }
                    if (data.type === 'debug-pause' && job.type === 'debug' && job.callbacks.onPause) {
                        this.waitingDebug = job.id;
                        job.callbacks.onPause(data.snapshot || {}, command => this.resumeDebug(job.id, command));
                    }
                    if (((job.type === 'run' || job.type === 'debug') && data.type === 'result') || (job.type === 'writeFile' && data.type === 'ack')) {
                        this.waitingInput = null; this.waitingDebug = null; this.active = null;
                        if (data.failed) { job.reject(new Error(data.error || '실행 요청 실패')); }
                        else if (job.type === 'writeFile') {
                            if (data.filePath !== '/home/pyodide/ocl-datasets/' + job.payload.fileName) {
                                job.reject(new Error('데이터 파일 저장 경로를 확인하지 못했습니다.'));
                            } else { job.resolve(data.filePath); }
                        } else { job.resolve({ error: data.error, images: data.images || [], debugEnd: data.debugEnd || null }); }
                        this.pump();
                    }
                };
                worker.postMessage({ type: 'init', syncBuffer: this.sync ? this.sync.buffer : null, indexUrl: this.options.indexUrl });
            });
            return this.ready;
        }
        submitInput(id, value) {
            if (!this.sync || this.waitingInput !== id || !this.active || this.active.id !== id) { return; }
            if (value !== null && new TextEncoder().encode(value).length > 65536) {
                if (this.options.onStatus) { this.options.onStatus('입력은 UTF-8 기준 64KB까지 가능합니다.'); }
                // The inline control is already closed; end stdin instead of leaving a stuck worker.
                value = null;
            }
            this.waitingInput = null;
            this.answer(value);
        }
        resumeDebug(id, command) {
            if (!this.sync || this.waitingDebug !== id || !this.active || this.active.id !== id) { return false; }
            this.waitingDebug = null;
            this.answer(JSON.stringify(command || { action: 'continue' }));
            return true;
        }
        // Wakes the Worker blocked in waitForMain(): text, or null for EOF.
        answer(value) {
            if (value !== null) {
                const bytes = new TextEncoder().encode(value);
                new Uint8Array(this.sync.buffer, 8, bytes.length).set(bytes);
                Atomics.store(this.sync, 1, bytes.length);
            }
            Atomics.store(this.sync, 0, value === null ? 2 : 1);
            Atomics.notify(this.sync, 0);
        }
        cancelPendingInput() { if (this.waitingInput !== null) { this.submitInput(this.waitingInput, null); } }
        stop(message) {
            const error = new Error(message || '실행을 중단했습니다.'); error.cancelled = true;
            clearTimeout(this.initTimer);
            if (this.worker) { this.worker.terminate(); }
            this.worker = null;
            if (this.rejectInit) { this.rejectInit(error); this.rejectInit = null; }
            this.ready = null; this.sync = null; this.waitingInput = null; this.waitingDebug = null;
            if (this.active) { this.active.reject(error); this.active = null; }
            this.queue.splice(0).forEach(job => job.reject(error));
        }
        reset() { this.stop('런타임을 재시작했습니다.'); }
    }
    host.OclWorkerRuntime = OclWorkerRuntime;
    if (typeof module !== 'undefined' && module.exports) { module.exports = OclWorkerRuntime; }
})(typeof window !== 'undefined' ? window : globalThis);
