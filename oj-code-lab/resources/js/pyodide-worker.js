/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
/** Serialized Pyodide Worker. Input requires cross-origin isolation; execution never blocks the UI. */
(function () {
    'use strict';

    // sync[0]: 0 = worker waiting, 1 = main thread wrote a value, 2 = EOF/cancel.
    // sync[1]: byte length of the UTF-8 input sitting at STDIN_DATA_OFFSET.
    var SYNC_STATE = 0;
    var SYNC_LENGTH = 1;
    var STDIN_DATA_OFFSET = 8;

    var pyodide = null;
    var syncArray = null;
    var stdinBytes = null;
    var activeId = null;
    var queue = Promise.resolve();
    var streamBuffer = '';
    var outputBytes = 0;
    var lastFlush = 0;

    var HARNESS_PY = [
        'import sys, json, base64, io, traceback, os',
        "os.environ['MPLBACKEND'] = 'Agg'",
        '',
        '__ocl_globals = {}',
        '',
        'class __OclStream:',
        '    def __init__(self, emit):',
        '        self._emit = emit',
        '    def write(self, text):',
        '        if text:',
        '            self._emit(text)',
        '        return len(text)',
        '    def flush(self):',
        '        pass',
        '',
        // Streams text out as it's printed, instead of buffering a whole
        // cell's output into one string returned only at the very end —
        // otherwise anything printed right before an input() call sits
        // invisible until the *entire* cell finishes, which reads as
        // "nothing happened" right when a student most needs to see it.
        //
        // Deliberately does NOT `import matplotlib` itself (this harness
        // has to work for plain algorithm code that never touches it, and
        // JS now only loads matplotlib lazily — see ensurePackagesForCode()
        // — when a cell's own code actually imports it). Figure capture
        // below reads sys.modules instead, so it costs nothing when the
        // student's code never imported matplotlib.pyplot in the first place.
        "def __ocl_run(code, emit_text, debug_pause=None, debug_name=None, debug_breakpoints='[]'):",
        '    old_stdout, old_stderr = sys.stdout, sys.stderr',
        '    sys.stdout = __OclStream(emit_text)',
        '    sys.stderr = __OclStream(emit_text)',
        '    err = None',
        '    debug_end = []',
        '    try:',
        '        if debug_pause is None:',
        "            exec(compile(code, '<cell>', 'exec'), __ocl_globals)",
        '        else:',
        '            __ocl_debug_exec(code, debug_pause, debug_name, debug_breakpoints, debug_end)',
        '    except BaseException as error:',
        '        if debug_pause is None:',
        '            err = traceback.format_exc()',
        '        else:',
        "            err = ''.join(traceback.format_exception(type(error), error, __ocl_without_debug_frames(error.__traceback__)))",
        '    finally:',
        '        sys.stdout, sys.stderr = old_stdout, old_stderr',
        '    images = []',
        "    plt = sys.modules.get('matplotlib.pyplot')",
        '    if plt is not None:',
        '        image_bytes = 0',
        '        for num in plt.get_fignums()[:20]:',
        '            fig = plt.figure(num)',
        '            buf = io.BytesIO()',
        "            fig.savefig(buf, format='png', bbox_inches='tight')",
        '            image_bytes += len(buf.getvalue())',
        '            if image_bytes > 6000000:',
        "                err = '그래프 출력이 6MB를 초과했습니다. 그림 개수나 해상도를 줄여 주세요.'",
        '                break',
        "            images.append(base64.b64encode(buf.getvalue()).decode('ascii'))",
        "        plt.close('all')",
        '    result = {"error": err, "images": images}',
        '    if debug_pause is not None:',
        '        result["debugEnd"] = debug_end[0] if debug_end else None',
        '    return json.dumps(result, ensure_ascii=False)',
        '',
        'def __ocl_reset():',
        '    __ocl_globals.clear()',
        "    plt = sys.modules.get('matplotlib.pyplot')",
        '    if plt is not None:',
        "        plt.close('all')",
        '',
        // Step debugger. Only frames compiled from this one debug run (a
        // filename unique per run) are traced, so functions defined by
        // earlier cells and every library call run at full speed and never
        // pause with line numbers that don't belong to the visible cell.
        // `pause` blocks the Worker (Atomics.wait in JS) until the student
        // picks the next action, exactly like input() does.
        'import reprlib as __ocl_reprlib, types as __ocl_types',
        '__ocl_repr = __ocl_reprlib.Repr()',
        '__ocl_repr.maxlevel = 3',
        '__ocl_repr.maxlist = __ocl_repr.maxtuple = __ocl_repr.maxset = __ocl_repr.maxdict = 20',
        '__ocl_repr.maxfrozenset = __ocl_repr.maxdeque = __ocl_repr.maxarray = 20',
        '__ocl_repr.maxstring = 200',
        '__ocl_repr.maxlong = 100',
        '__ocl_repr.maxother = 300',
        '',
        'def __ocl_describe(name, value):',
        '    kind = type(value).__name__',
        '    try:',
        '        if isinstance(value, __ocl_types.FunctionType):',
        '            import inspect',
        "            text = 'def ' + value.__name__ + str(inspect.signature(value))",
        '        elif isinstance(value, type):',
        "            text = 'class ' + value.__name__",
        '        else:',
        '            text = __ocl_repr.repr(value)',
        '    except Exception:',
        "        text = '(표시할 수 없는 값)'",
        "    item = {'name': name, 'type': kind, 'value': text}",
        '    try:',
        "        shape = getattr(value, 'shape', None)",
        '        if isinstance(shape, tuple):',
        "            item['size'] = 'shape=' + str(shape)",
        '        elif isinstance(value, (str, bytes, list, tuple, dict, set, frozenset)):',
        "            item['size'] = 'len=' + str(len(value))",
        '    except Exception:',
        '        pass',
        '    return item',
        '',
        'def __ocl_variables(namespace):',
        '    items = []',
        '    for name, value in list(namespace.items()):',
        "        if name.startswith('__') or isinstance(value, __ocl_types.ModuleType):",
        '            continue',
        '        items.append(__ocl_describe(name, value))',
        '        if len(items) >= 200:',
        '            break',
        '    return items',
        '',
        // A debug run's traceback reads like a normal run's: the debugger's
        // own frame is left out.
        'def __ocl_without_debug_frames(tb):',
        '    kept = []',
        '    while tb is not None:',
        "        if tb.tb_frame.f_code.co_name != '__ocl_debug_exec':",
        '            kept.append(tb)',
        '        tb = tb.tb_next',
        '    result = None',
        '    for item in reversed(kept):',
        '        result = __ocl_types.TracebackType(result, item.tb_frame, item.tb_lasti, item.tb_lineno)',
        '    return result',
        '',
        'def __ocl_breakpoint_set(lines):',
        "    return set(n for n in lines if isinstance(n, int) and not isinstance(n, bool) and n > 0) if isinstance(lines, list) else set()",
        '',
        // `end` receives one extra snapshot after the run: the variables
        // when it finished, or — on an uncaught error — the frame and line
        // where it was raised, so the step history can show both.
        'def __ocl_debug_exec(code, pause, filename, initial_breakpoints, end):',
        '    compiled = compile(code, filename, "exec")',
        '    try:',
        '        breakpoints = __ocl_breakpoint_set(json.loads(initial_breakpoints))',
        '    except Exception:',
        '        breakpoints = set()',
        // With breakpoints, run to the first one; otherwise stop on line 1.
        "    state = {'mode': 'continue' if breakpoints else 'step', 'depth': 0, 'breakpoints': breakpoints, 'busy': False}",
        '',
        '    def user_frames(frame):',
        '        frames = []',
        '        while frame is not None:',
        '            if frame.f_code.co_filename == filename:',
        '                frames.append(frame)',
        '            frame = frame.f_back',
        '        return frames',
        '',
        '    def should_pause(frame):',
        "        if frame.f_lineno in state['breakpoints']:",
        '            return True',
        "        mode = state['mode']",
        "        if mode == 'step':",
        '            return True',
        "        if mode == 'over':",
        "            return len(user_frames(frame)) <= state['depth']",
        "        if mode == 'out':",
        "            return len(user_frames(frame)) < state['depth']",
        '        return False',
        '',
        '    def snapshot(frame, line=None):',
        '        stack = user_frames(frame)',
        "        in_function = frame.f_code.co_name != '<module>'",
        "        frames = [{'function': f.f_code.co_name, 'line': f.f_lineno} for f in reversed(stack)]",
        '        if line is not None and frames:',
        "            frames[-1]['line'] = line",
        '        return {',
        "            'line': frame.f_lineno if line is None else line,",
        "            'function': frame.f_code.co_name,",
        "            'stack': frames,",
        "            'locals': __ocl_variables(frame.f_locals) if in_function else None,",
        "            'globals': __ocl_variables(frame.f_globals),",
        '        }',
        '',
        // `busy` keeps the tracer out of code the snapshot itself runs —
        // e.g. a __repr__ the student defined in this same cell — so
        // describing a variable can never trigger a nested pause.
        '    def do_pause(frame):',
        "        state['busy'] = True",
        '        try:',
        '            data = json.dumps(snapshot(frame), ensure_ascii=False)',
        '        finally:',
        "            state['busy'] = False",
        '        reply = pause(data)',
        '        try:',
        '            command = json.loads(reply) if reply else {}',
        '        except Exception:',
        '            command = {}',
        '        if not isinstance(command, dict):',
        '            command = {}',
        "        action = command.get('action')",
        "        state['mode'] = action if action in ('step', 'over', 'out', 'continue') else 'continue'",
        "        state['depth'] = len(user_frames(frame))",
        "        state['breakpoints'] = __ocl_breakpoint_set(command.get('breakpoints'))",
        '',
        '    def local_trace(frame, event, arg):',
        "        if event == 'line' and not state['busy'] and should_pause(frame):",
        '            do_pause(frame)',
        '        return local_trace',
        '',
        '    def global_trace(frame, event, arg):',
        "        if state['busy'] or frame.f_code.co_filename != filename:",
        '            return None',
        '        return local_trace',
        '',
        '    sys.settrace(global_trace)',
        '    try:',
        '        exec(compiled, __ocl_globals)',
        '    except BaseException:',
        "        state['busy'] = True",
        '        sys.settrace(None)',
        '        tb = sys.exc_info()[2]',
        '        last = None',
        '        while tb is not None:',
        '            if tb.tb_frame.f_code.co_filename == filename:',
        '                last = tb',
        '            tb = tb.tb_next',
        '        if last is not None:',
        '            try:',
        '                item = snapshot(last.tb_frame, last.tb_lineno)',
        "                item['event'] = 'exception'",
        '                end.append(item)',
        '            except Exception:',
        '                pass',
        '        raise',
        '    else:',
        "        state['busy'] = True",
        '        sys.settrace(None)',
        "        end.append({'event': 'finished', 'line': None, 'function': '<module>', 'stack': [], 'locals': None, 'globals': __ocl_variables(__ocl_globals)})",
        '    finally:',
        '        sys.settrace(None)',
    ].join('\n');

    function post(message) {
        if (activeId !== null && message.id === undefined) { message.id = activeId; }
        self.postMessage(message);
    }

    function flushStream() {
        if (streamBuffer) { post({ type: 'stream', text: streamBuffer }); streamBuffer = ''; lastFlush = Date.now(); }
    }

    function emitText(text) {
        outputBytes += new TextEncoder().encode(text).length;
        if (outputBytes > 1000000) { flushStream(); throw new Error('출력이 1MB를 초과했습니다. 출력 횟수를 줄여 주세요.'); }
        streamBuffer += text;
        if (streamBuffer.length >= 1024 || Date.now() - lastFlush > 16) { flushStream(); }
    }

    // Posts `message`, then blocks this Worker until the main thread answers
    // through the shared buffer. Returns the answered text, or null for
    // EOF/cancel. Shared by input() and the step debugger's pauses.
    function waitForMain(message) {
        flushStream();
        Atomics.store(syncArray, SYNC_STATE, 0);
        post(message);
        Atomics.wait(syncArray, SYNC_STATE, 0);

        var state = Atomics.load(syncArray, SYNC_STATE);
        if (2 === state) {
            return null;
        }

        var length = Atomics.load(syncArray, SYNC_LENGTH);
        var bytes = stdinBytes.slice(0, length);
        return new TextDecoder().decode(bytes);
    }

    function workerStdin() {
        if (!syncArray) { throw new Error('input() 사용에는 HTTPS와 cross-origin isolation 지원이 필요합니다.'); }
        return waitForMain({ type: 'input-request' });
    }

    // Called from the Python tracer with a JSON snapshot; returns the
    // student's next command as JSON text ('{"action":"step"}', …).
    function debugPause(snapshotJson) {
        return waitForMain({ type: 'debug-pause', snapshot: JSON.parse(snapshotJson) });
    }

    function init(syncBuffer, indexUrl) {
        syncArray = syncBuffer ? new Int32Array(syncBuffer) : null;
        stdinBytes = syncBuffer ? new Uint8Array(syncBuffer, STDIN_DATA_OFFSET) : null;

        // importScripts() throws synchronously (e.g. the cross-origin
        // fetch was blocked, or the network failed) — outside a promise
        // chain, so without this try/catch that exception would escape
        // init() entirely. No 'init-error' would ever reach the main
        // thread, and — since nothing else here calls resolve()/reject() —
        // the main thread's startWorker() promise would hang forever with
        // no visible error at all: "click run, nothing happens."
        try {
            importScripts(indexUrl + 'pyodide.js');
        } catch (error) {
            post({ type: 'init-error', message: 'pyodide.js 로드 실패: ' + String((error && error.message) || error) });
            return Promise.resolve();
        }

        // Deliberately does NOT preload numpy/pandas/matplotlib/seaborn
        // here — this addon is a general Python practice notebook now, not
        // only a data-science one, and most cells never touch any of them.
        // run() below loads only what a given cell's own code actually
        // imports, the moment it needs it.
        return self.loadPyodide({ indexURL: indexUrl })
            .then(function (instance) {
                instance.setStdin({ stdin: workerStdin });
                return instance.runPythonAsync(HARNESS_PY).then(function () { return instance; });
            })
            .then(function (instance) {
                pyodide = instance;
                post({ type: 'status', text: '' });
                post({ type: 'ready' });
            })
            .catch(function (error) {
                post({ type: 'init-error', message: String((error && error.message) || error) });
            });
    }

    // Loads only the packages `code` actually imports, via Pyodide's own
    // import scanner — a plain algorithm-only cell triggers no download at
    // all. Idempotent: re-running a cell that already imported numpy
    // costs nothing, Pyodide tracks what's already loaded. Seaborn is
    // handled separately because it isn't always in Pyodide's own package
    // repository depending on version — same loadPackage-then-micropip
    // fallback this addon has always used, just triggered lazily now.
    // Progress chatter ("Loading numpy…") stays out of the console; the
    // status bar already says packages are loading. Errors still log.
    // Passing options replaces Pyodide's default {checkIntegrity: true},
    // so integrity checking must be restated or package hashes go unchecked.
    var QUIET_LOAD = { checkIntegrity: true, messageCallback: function () {} };

    function ensurePackagesForCode(code) {
        var needsSeaborn = /\bseaborn\b/.test(code);

        return pyodide.loadPackagesFromImports(code, QUIET_LOAD).then(function () {
            if (!needsSeaborn) {
                return null;
            }
            return pyodide.loadPackage(['seaborn'], QUIET_LOAD).catch(function () {
                return pyodide.loadPackage(['micropip'], QUIET_LOAD).then(function () {
                    return pyodide.runPythonAsync("import micropip\nawait micropip.install('seaborn==0.13.2')");
                });
            }).catch(function () {
                // Best-effort — if even micropip can't find it, the
                // student's own `import seaborn` surfaces a normal,
                // understandable ModuleNotFoundError instead of silently
                // stalling the whole run.
                return null;
            });
        });
    }

    var debugRuns = 0;

    function run(id, code, debug, breakpoints) {
        outputBytes = 0; streamBuffer = ''; lastFlush = 0;
        if (debug && !syncArray) {
            post({ type: 'result', id: id, error: '디버깅에는 HTTPS와 cross-origin isolation 지원이 필요합니다.', images: [] });
            return Promise.resolve();
        }
        post({ type: 'status', text: '필요한 패키지 확인 중…' });
        return ensurePackagesForCode(code)
            .then(function () {
                post({ type: 'status', text: '' });
                pyodide.globals.set('__ocl_user_code', code);
                pyodide.globals.set('__ocl_emit_text', emitText);
                if (!debug) {
                    return pyodide.runPythonAsync('__ocl_run(__ocl_user_code, __ocl_emit_text)');
                }
                debugRuns += 1;
                pyodide.globals.set('__ocl_debug_pause', debugPause);
                pyodide.globals.set('__ocl_debug_name', '<cell #' + debugRuns + '>');
                pyodide.globals.set('__ocl_debug_breakpoints', JSON.stringify(Array.isArray(breakpoints) ? breakpoints : []));
                return pyodide.runPythonAsync('__ocl_run(__ocl_user_code, __ocl_emit_text, __ocl_debug_pause, __ocl_debug_name, __ocl_debug_breakpoints)');
            })
            .then(function (resultJson) {
                flushStream();
                var result = JSON.parse(resultJson);
                post({ type: 'result', id: id, error: result.error, images: result.images || [], debugEnd: result.debugEnd || null });
            })
            .catch(function (error) {
                flushStream();
                post({ type: 'status', text: '' });
                post({ type: 'result', id: id, error: String((error && error.message) || error), images: [] });
            });
    }

    self.onmessage = function (event) {
        var data = event.data || {};
        queue = queue.then(function () {
            if (data.type === 'init') { return init(data.syncBuffer, data.indexUrl); }
            activeId = data.id;
            if (!pyodide) { throw new Error('실행 환경이 준비되지 않았습니다.'); }
            if (data.type === 'run') { return run(data.id, data.code, false); }
            if (data.type === 'debug') { return run(data.id, data.code, true, data.breakpoints); }
            if (data.type === 'writeFile') {
                var name = String(data.fileName).split(/[\\/]/).pop();
                if (!name || name === '.' || name === '..' || name.includes('\0')) { throw new Error('파일명이 올바르지 않습니다.'); }
                // A data file named pandas.py must never shadow an imported package.
                // Use an absolute subdirectory outside Python's default import paths.
                var directory = '/home/pyodide/ocl-datasets';
                pyodide.FS.mkdirTree(directory);
                var filePath = directory + '/' + name;
                pyodide.FS.writeFile(filePath, new Uint8Array(data.bytes));
                post({ type: 'ack', id: data.id, filePath: filePath });
            }
        }).catch(function (error) {
            post({ type: data.type === 'init' ? 'init-error' : 'ack', id: data.id, failed: true, error: String(error.message || error), message: String(error.message || error) });
        }).finally(function () { activeId = null; });
    };
})();
