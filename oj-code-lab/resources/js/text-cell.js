/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
/** Markdown notes keep the original textarea as their editable source. */
(function () {
    'use strict';

    function attach(textarea, onChange) {
        var doc = textarea.ownerDocument;
        var disposed = false;
        var composing = false;
        var inserting = false;
        var timer = null;
        var editing = !textarea.value.trim();
        var originalLabel = textarea.getAttribute('aria-label');
        var originalPlaceholder = textarea.placeholder;
        var listeners = [];
        var root = element('div', 'ocl-markdown-editor');
        var viewActions = element('div', 'ocl-markdown-view-actions');
        var toolbar = element('div', 'ocl-markdown-toolbar');
        toolbar.setAttribute('role', 'group');
        toolbar.setAttribute('aria-label', '마크다운 서식');
        var body = element('div', 'ocl-markdown-body');
        var source = element('div', 'ocl-markdown-source');
        var sourceLabel = element('div', 'ocl-markdown-pane-label', '마크다운');
        var previewPane = element('div', 'ocl-markdown-preview-pane');
        var previewLabel = element('div', 'ocl-markdown-pane-label', '미리보기');
        var preview = element('div', 'ocl-markdown');
        preview.setAttribute('aria-label', '마크다운 미리보기');
        var help = element('p', 'ocl-markdown-help', 'Ctrl+Enter 또는 Esc로 편집을 닫습니다. 텍스트를 두 번 클릭하면 다시 편집할 수 있습니다.');
        textarea.parentNode.insertBefore(root, textarea);
        source.appendChild(sourceLabel);
        source.appendChild(textarea);
        previewPane.appendChild(previewLabel);
        previewPane.appendChild(preview);
        body.appendChild(source);
        body.appendChild(previewPane);
        root.appendChild(viewActions);
        root.appendChild(toolbar);
        root.appendChild(body);
        root.appendChild(help);
        textarea.setAttribute('aria-label', '마크다운 텍스트');
        textarea.placeholder = '# 제목\n설명과 풀이 과정을 **마크다운**으로 기록하세요.';

        function element(tag, className, text) {
            var node = doc.createElement(tag);
            node.className = className;
            if (text !== undefined) { node.textContent = text; }
            return node;
        }

        function listen(node, type, handler) {
            node.addEventListener(type, handler);
            listeners.push([node, type, handler]);
        }

        function button(parent, name, text, label, action) {
            var node = element('button', 'ojp-button ojp-button--plain ojp-button--small ocl-md-action ocl-md-' + name, text);
            node.type = 'button';
            node.title = label;
            node.setAttribute('aria-label', label);
            node.setAttribute('data-markdown-action', name);
            // Keep the source selection when a pointer activates a format tool.
            listen(node, 'mousedown', function (event) { event.preventDefault(); });
            listen(node, 'click', function () {
                if (!disposed && !composing) { action(); }
            });
            parent.appendChild(node);
            return node;
        }

        function render() {
            if (timer !== null) { window.clearTimeout(timer); timer = null; }
            if (disposed) { return; }
            preview.classList.remove('ocl-markdown-plain');
            if (!textarea.value.trim()) {
                preview.textContent = '설명과 풀이 과정을 기록하세요.';
                preview.classList.add('ocl-markdown-empty');
                return;
            }
            preview.classList.remove('ocl-markdown-empty');
            try {
                if (!window.OclMarkdown || typeof window.OclMarkdown.render !== 'function') {
                    throw new Error('Markdown unavailable');
                }
                window.OclMarkdown.render(preview, textarea.value);
            } catch (error) {
                preview.classList.add('ocl-markdown-plain');
                preview.textContent = textarea.value;
            }
        }

        function scheduleRender() {
            if (disposed || composing || inserting) { return; }
            if (timer !== null) { window.clearTimeout(timer); timer = null; }
            if (textarea.value.length > 4000) {
                timer = window.setTimeout(render, 100);
            } else {
                render();
            }
        }

        function resize() {
            textarea.style.height = 'auto';
            textarea.style.height = Math.max(120, textarea.scrollHeight) + 'px';
        }

        function setEditing(value, focus) {
            if (disposed || composing) { return; }
            editing = value;
            root.classList.toggle('is-editing', editing);
            source.hidden = !editing;
            toolbar.hidden = !editing;
            viewActions.hidden = editing;
            previewLabel.hidden = !editing;
            help.hidden = !editing;
            render();
            if (editing) {
                resize();
                if (focus) { textarea.focus(); }
            } else if (focus) {
                editButton.focus();
            }
        }

        // insertText preserves the textarea's native undo stack in browsers that
        // implement it. setRangeText is a safe fallback when it is unavailable.
        function replace(start, end, replacement, selectStart, selectEnd) {
            if (disposed || composing || textarea.readOnly || textarea.disabled) { return; }
            var original = textarea.value;
            var expected = original.slice(0, start) + replacement + original.slice(end);
            if (textarea.maxLength >= 0 && expected.length > textarea.maxLength) { return; }
            textarea.focus();
            textarea.setSelectionRange(start, end);
            inserting = true;
            try {
                if (typeof doc.execCommand === 'function') {
                    try { doc.execCommand('insertText', false, replacement); } catch (error) { /* Use the fallback below. */ }
                }
                if (textarea.value !== expected) {
                    textarea.value = original;
                    textarea.setRangeText(replacement, start, end, 'preserve');
                }
            } finally {
                inserting = false;
            }
            textarea.setSelectionRange(selectStart, selectEnd);
            resize();
            render();
            if (textarea.value !== original && typeof onChange === 'function') { onChange(); }
        }

        function wrap(before, after, placeholder) {
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var selected = textarea.value.slice(start, end);
            // A single * toggles italic only when it is an odd-length run;
            // otherwise **bold** would lose one star instead of gaining italic.
            var selectedStars = before !== '*' ||
                ((selected.match(/^\*+/) || [''])[0].length % 2 === 1 &&
                (selected.match(/\*+$/) || [''])[0].length % 2 === 1);
            var surroundingStars = before !== '*' ||
                ((textarea.value.slice(0, start).match(/\*+$/) || [''])[0].length % 2 === 1 &&
                (textarea.value.slice(end).match(/^\*+/) || [''])[0].length % 2 === 1);
            if (selected.length >= before.length + after.length &&
                selectedStars &&
                selected.slice(0, before.length) === before &&
                selected.slice(-after.length) === after) {
                selected = selected.slice(before.length, -after.length);
                replace(start, end, selected, start, start + selected.length);
            } else if (start >= before.length &&
                surroundingStars &&
                textarea.value.slice(start - before.length, start) === before &&
                textarea.value.slice(end, end + after.length) === after) {
                replace(start - before.length, end + after.length, selected,
                    start - before.length, end - before.length);
            } else {
                selected = selected || placeholder;
                replace(start, end, before + selected + after,
                    start + before.length, start + before.length + selected.length);
            }
        }

        function prefixLines(prefix) {
            var start = textarea.value.lastIndexOf('\n', textarea.selectionStart - 1) + 1;
            if (textarea.selectionStart === 0) { start = 0; }
            var end = textarea.selectionEnd;
            // A selection ending at the next line's start excludes that line.
            if (end > start && textarea.value.charAt(end - 1) === '\n') { end -= 1; }
            end = textarea.value.indexOf('\n', end);
            if (end === -1) { end = textarea.value.length; }
            var lines = textarea.value.slice(start, end).split('\n');
            var numbered = prefix === '1. ';
            var replacement = lines.map(function (line, index) {
                return (numbered ? String(index + 1) + '. ' : prefix) + line;
            }).join('\n');
            replace(start, end, replacement, start, start + replacement.length);
        }

        function block(text, selectOffset, selectLength) {
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var before = textarea.value.slice(0, start);
            var after = textarea.value.slice(end);
            var leading = before && !/\n\n$/.test(before) ? (/\n$/.test(before) ? '\n' : '\n\n') : '';
            var trailing = after && !/^\n\n/.test(after) ? (/^\n/.test(after) ? '\n' : '\n\n') : '';
            replace(start, end, leading + text + trailing,
                start + leading.length + selectOffset,
                start + leading.length + selectOffset + selectLength);
        }

        function codeBlock() {
            var selected = textarea.value.slice(textarea.selectionStart, textarea.selectionEnd) || '코드';
            var fence = '```';
            var matches = selected.match(/`+/g) || [];
            matches.forEach(function (match) {
                if (match.length >= fence.length) { fence = '`'.repeat(match.length + 1); }
            });
            block(fence + '\n' + selected + '\n' + fence, fence.length + 1, selected.length);
        }

        function link(image) {
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var label = textarea.value.slice(start, end) || (image ? '이미지 설명' : '링크 텍스트');
            label = label.replace(/([\\[\]])/g, '\\$1');
            var prefix = (image ? '![' : '[') + label + '](';
            var url = 'https://';
            replace(start, end, prefix + url + ')', start + prefix.length, start + prefix.length + url.length);
        }

        var editButton = button(viewActions, 'edit', '편집', '마크다운 편집', function () { setEditing(true, true); });
        button(toolbar, 'heading', '제목', '제목', function () { prefixLines('## '); });
        button(toolbar, 'bold', 'B', '굵게 (Ctrl+B)', function () { wrap('**', '**', '굵은 텍스트'); });
        button(toolbar, 'italic', 'I', '기울임 (Ctrl+I)', function () { wrap('*', '*', '기울인 텍스트'); });
        button(toolbar, 'strike', 'S', '취소선', function () { wrap('~~', '~~', '취소선 텍스트'); });
        button(toolbar, 'code', '<>', '인라인 코드', function () { wrap('`', '`', '코드'); });
        button(toolbar, 'code-block', '{ }', '코드 블록', codeBlock);
        button(toolbar, 'link', '링크', '링크 (Ctrl+K)', function () { link(false); });
        button(toolbar, 'image', '이미지', '이미지', function () { link(true); });
        button(toolbar, 'quote', '❞', '인용구', function () { prefixLines('> '); });
        button(toolbar, 'ordered', '1.', '번호 목록', function () { prefixLines('1. '); });
        button(toolbar, 'unordered', '•', '글머리 목록', function () { prefixLines('- '); });
        button(toolbar, 'rule', '―', '구분선', function () { block('---', 3, 0); });
        button(toolbar, 'table', '표', '표', function () {
            block('| 제목 1 | 제목 2 |\n| --- | --- |\n| 내용 1 | 내용 2 |', 2, 4);
        });
        button(toolbar, 'close', '닫기', '마크다운 편집 닫기 (Ctrl+Enter 또는 Esc)', function () { setEditing(false, true); });
        listen(textarea, 'input', scheduleRender);
        listen(textarea, 'compositionstart', function () { composing = true; });
        listen(textarea, 'compositionend', function () { composing = false; scheduleRender(); });
        listen(textarea, 'keydown', function (event) {
            if (!editing || composing || event.isComposing || event.keyCode === 229 || event.altKey) { return; }
            var modifier = event.ctrlKey || event.metaKey;
            var key = String(event.key || '').toLowerCase();
            if (key === 'escape' || (modifier && key === 'enter')) {
                event.preventDefault();
                event.stopPropagation();
                setEditing(false, true);
            } else if (modifier && !event.shiftKey && (key === 'b' || key === 'i' || key === 'k')) {
                event.preventDefault();
                event.stopPropagation();
                if (key === 'b') { wrap('**', '**', '굵은 텍스트'); }
                if (key === 'i') { wrap('*', '*', '기울인 텍스트'); }
                if (key === 'k') { link(false); }
            }
        });
        listen(preview, 'dblclick', function (event) {
            if (event.target.closest && event.target.closest('a, button')) { return; }
            setEditing(true, true);
        });
        setEditing(editing, false);

        return {
            focus: function () { setEditing(true, true); },
            destroy: function () {
                if (disposed) { return; }
                disposed = true;
                if (timer !== null) { window.clearTimeout(timer); timer = null; }
                listeners.forEach(function (entry) { entry[0].removeEventListener(entry[1], entry[2]); });
                if (root.parentNode) { root.parentNode.insertBefore(textarea, root); }
                root.remove();
                if (originalLabel === null) { textarea.removeAttribute('aria-label'); }
                else { textarea.setAttribute('aria-label', originalLabel); }
                textarea.placeholder = originalPlaceholder;
            },
        };
    }

    window.OclTextCell = { attach: attach };
}());
