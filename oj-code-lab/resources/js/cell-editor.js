/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
/** Progressive Ace enhancement; the textarea remains usable if assets fail. */
(function () {
    'use strict';

    window.OclCellEditors = function (config) {
        config = config || {};
        var ready = null;
        var entries = new Map();
        var observer = typeof window.IntersectionObserver === 'function'
            ? new window.IntersectionObserver(function (items) {
                items.forEach(function (item) {
                    if (item.isIntersecting && entries.has(item.target)) {
                        entries.get(item.target).enhance();
                        observer.unobserve(item.target);
                    }
                });
            }, { rootMargin: '300px' }) : null;

        function script(url) {
            return new Promise(function (resolve, reject) {
                var tag = document.createElement('script');
                var timer = window.setTimeout(function () { finish(new Error('timeout')); }, 15000);
                function finish(error) {
                    window.clearTimeout(timer);
                    tag.onload = tag.onerror = null;
                    if (error) { tag.remove(); reject(error); } else { resolve(); }
                }
                tag.src = url;
                tag.async = true;
                tag.onload = function () { finish(); };
                tag.onerror = function () { finish(new Error('asset')); };
                document.head.appendChild(tag);
            });
        }

        function load() {
            if (!ready) {
                ready = Promise.resolve().then(function () {
                    var base = new URL(config.baseUrl, window.location.href);
                    var core = new URL(config.coreUrl, window.location.href);
                    if (!config.available || !config.baseUrl || !config.coreUrl
                        || base.origin !== window.location.origin || core.href !== base.href + 'ace.js'
                        || !/^[a-z0-9_]+$/.test(config.theme || '')) { throw new Error('unavailable'); }
                    return (window.ace ? Promise.resolve() : script(core.href)).then(function () {
                        if (!window.ace) { throw new Error('ace'); }
                        window.ace.config.set('basePath', base.href);
                        window.ace.config.set('modePath', base.href);
                        window.ace.config.set('themePath', base.href);
                        return Promise.all([
                            window.ace.require('ace/mode/python') ? Promise.resolve() : script(base.href + 'mode-python.js'),
                            window.ace.require('ace/theme/' + config.theme) ? Promise.resolve() : script(base.href + 'theme-' + config.theme + '.js'),
                        ]);
                    });
                });
            }
            return ready;
        }

        function attach(textarea, onChange, onRun) {
            var editor = null;
            var disposed = false;
            var loading = false;
            var composing = false;
            var loaded = false;
            // Debugger state, kept even before Ace mounts so a late mount
            // (or the textarea fallback) still shows it.
            var readOnly = false;
            var debugLine = null;
            var debugMarker = null;
            // Breakpoint rows (0-based). Ace 1.44 doesn't move breakpoints
            // when lines are inserted or removed, so shiftBreakpoints() keeps
            // each one on the line it was set on.
            var breakpoints = [];
            var host = document.createElement('div');
            host.className = 'ocl-ace-host';
            host.hidden = true;
            textarea.parentNode.insertBefore(host, textarea);

            function mount() {
                if (disposed || composing || editor || !loaded) { return; }
                var focused = document.activeElement === textarea;
                var start = textarea.selectionStart || 0;
                var end = textarea.selectionEnd || start;
                try {
                    host.hidden = false;
                    editor = window.ace.edit(host);
                    editor.session.setUseWorker(false);
                    editor.session.setMode('ace/mode/python');
                    editor.setTheme('ace/theme/' + config.theme);
                    editor.setOptions({
                        fontSize: ([14, 16, 18].indexOf(config.fontSize) >= 0 ? config.fontSize : 16) + 'px',
                        minLines: 3, maxLines: Infinity, showPrintMargin: false,
                        autoScrollEditorIntoView: true, textInputAriaLabel: 'Python 코드',
                        displayIndentGuides: true, highlightActiveLine: true,
                        useSoftTabs: true, tabSize: 4, wrap: false,
                    });
                    editor.setValue(textarea.value, -1);
                    editor.session.on('change', function (delta) {
                        shiftBreakpoints(delta);
                        textarea.value = editor.getValue();
                        onChange();
                    });
                    editor.commands.addCommand({
                        name: 'oclRunCell', bindKey: { win: 'Ctrl-Enter', mac: 'Command-Enter|Ctrl-Enter' },
                        exec: function () { if (!composing) { onRun(); } },
                    });
                    editor.commands.addCommand({
                        name: 'oclToggleBreakpoint', bindKey: { win: 'F9', mac: 'F9' },
                        exec: function () { toggleBreakpoint(editor.getCursorPosition().row); },
                    });
                    // Clicking a line number toggles a breakpoint — also while
                    // debugging, when the text itself is read-only.
                    editor.on('guttermousedown', function (event) {
                        var target = event.domEvent && event.domEvent.target;
                        if (!target || !/\bace_gutter-cell/.test(String(target.className))) { return; }
                        toggleBreakpoint(event.getDocumentPosition().row);
                        event.stop();
                    });
                    showBreakpoints();
                    editor.textInput.getElement().setAttribute('aria-label', 'Python 코드');
                    textarea.hidden = true;
                    editor.resize(true);
                    applyDebugState();
                    if (focused) {
                        editor.selection.setSelectionRange({
                            start: editor.session.doc.indexToPosition(start, 0),
                            end: editor.session.doc.indexToPosition(end, 0),
                        });
                        editor.focus();
                    }
                } catch (error) { fallback(); }
            }

            function applyDebugState() {
                textarea.readOnly = readOnly;
                if (!editor) { return; }
                try {
                    editor.setReadOnly(readOnly);
                    if (debugMarker !== null) {
                        editor.session.removeMarker(debugMarker.id);
                        editor.session.removeGutterDecoration(debugMarker.row, 'ocl-debug-gutter');
                        debugMarker = null;
                    }
                    if (debugLine !== null) {
                        var Range = window.ace.require('ace/range').Range;
                        var row = debugLine - 1;
                        debugMarker = { row: row, id: editor.session.addMarker(new Range(row, 0, row, Infinity), 'ocl-debug-line', 'fullLine') };
                        editor.session.addGutterDecoration(row, 'ocl-debug-gutter');
                        editor.scrollToLine(row, true, false, function () {});
                    }
                } catch (error) { /* Highlighting is cosmetic; the panel still names the line. */ }
            }

            function showBreakpoints() {
                if (editor) { editor.session.setBreakpoints(breakpoints); }
            }

            function toggleBreakpoint(row) {
                var index = breakpoints.indexOf(row);
                if (index >= 0) { breakpoints.splice(index, 1); } else { breakpoints.push(row); }
                breakpoints.sort(function (a, b) { return a - b; });
                showBreakpoints();
            }

            function shiftBreakpoints(delta) {
                var lines = delta.end.row - delta.start.row;
                if (!lines || !breakpoints.length) { return; }
                var from = delta.start.row;
                var next = [];
                breakpoints.forEach(function (row) {
                    if (delta.action === 'insert') {
                        // Text inserted at the very start of a line pushes that line down too.
                        next.push(row > from || (row === from && delta.start.column === 0) ? row + lines : row);
                    } else if (row < from) {
                        next.push(row);
                    } else if (row === from) {
                        // Dropped only when the whole line itself was deleted.
                        if (delta.start.column !== 0 || delta.end.column !== 0) { next.push(row); }
                    } else if (row === delta.end.row) {
                        // The rest of this line moves up onto `from`; it keeps
                        // its breakpoint only if none of it was deleted.
                        if (delta.end.column === 0) { next.push(from); }
                    } else if (row > delta.end.row) {
                        next.push(row - lines);
                    }
                });
                breakpoints = next.filter(function (row, i) { return next.indexOf(row) === i; });
                showBreakpoints();
            }

            function fallback() {
                if (disposed) { return; }
                if (editor) { editor.destroy(); editor = null; }
                host.hidden = true;
                textarea.hidden = false;
                textarea.title = '기본 편집기입니다. 새로고침하면 구문 강조를 다시 불러옵니다.';
            }

            function enhance() {
                if (loading || disposed) { return; }
                loading = true;
                load().then(function () { loaded = true; mount(); }).catch(fallback);
            }
            function compositionStart() { composing = true; }
            function compositionEnd() { composing = false; window.setTimeout(mount, 0); }
            textarea.addEventListener('compositionstart', compositionStart);
            textarea.addEventListener('compositionend', compositionEnd);
            textarea.addEventListener('focus', enhance);
            var entry = {
                enhance: enhance,
                // Debugging: lock edits so line numbers stay valid, and mark
                // the line about to run (1-based; null clears the mark).
                setReadOnly: function (value) { readOnly = !!value; applyDebugState(); },
                highlightLine: function (line) { debugLine = line || null; applyDebugState(); },
                // 1-based line numbers; the textarea fallback has none.
                getBreakpoints: function () { return breakpoints.map(function (row) { return row + 1; }); },
                focus: function () { if (editor) { editor.focus(); } else { textarea.focus({ preventScroll: true }); enhance(); } },
                destroy: function () {
                    disposed = true;
                    if (observer) { observer.unobserve(textarea); }
                    textarea.removeEventListener('compositionstart', compositionStart);
                    textarea.removeEventListener('compositionend', compositionEnd);
                    textarea.removeEventListener('focus', enhance);
                    if (editor) { editor.destroy(); }
                    host.remove();
                    entries.delete(textarea);
                },
            };
            entries.set(textarea, entry);
            if (observer) { observer.observe(textarea); } else { enhance(); }
            return entry;
        }

        return {
            attach: attach,
            destroy: function () {
                entries.forEach(function (entry) { entry.destroy(); });
                if (observer) { observer.disconnect(); }
            },
        };
    };
}());
