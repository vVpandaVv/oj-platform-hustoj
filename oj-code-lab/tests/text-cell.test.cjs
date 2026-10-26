const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../resources/js/text-cell.js'), 'utf8');

// Exercise the shipped adapter; only DOM editing and timer delivery are fakes.
class Element {
    constructor(doc, tag) {
        Object.assign(this, {ownerDocument: doc, tag, children: [], attributes: {}, events: {}, style: {},
            className: '', value: '', placeholder: '', hidden: false, readOnly: false, disabled: false,
            selectionStart: 0, selectionEnd: 0, scrollHeight: 160, maxLength: -1});
    }
    get classList() {
        const node = this;
        return {
            contains(name) { return node.className.split(' ').includes(name); },
            add(name) { this.toggle(name, true); },
            remove(name) { this.toggle(name, false); },
            toggle(name, force) {
                const classes = new Set(node.className.split(' ').filter(Boolean));
                const add = force === undefined ? !classes.has(name) : force;
                if (add) classes.add(name); else classes.delete(name);
                node.className = Array.from(classes).join(' ');
                return add;
            },
        };
    }
    appendChild(node) { return this.insertBefore(node, null); }
    insertBefore(node, before) {
        if (node.parentNode) node.remove();
        const index = before === null ? this.children.length : this.children.indexOf(before);
        assert.notEqual(index, -1);
        this.children.splice(index, 0, node); node.parentNode = this; return node;
    }
    remove() {
        if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(node => node !== this);
        this.parentNode = null;
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name] ?? null; }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(type, handler) { (this.events[type] ||= []).push(handler); }
    removeEventListener(type, handler) { this.events[type] = (this.events[type] || []).filter(fn => fn !== handler); }
    focus() { this.ownerDocument.activeElement = this; }
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
    setRangeText(value, start, end) { this.value = this.value.slice(0, start) + value + this.value.slice(end); }
    fire(type, supplied = {}) {
        const event = {target: this, preventDefault() { this.prevented = true; },
            stopPropagation() { this.stopped = true; }, ...supplied};
        (this.events[type] || []).slice().forEach(handler => handler(event));
        return event;
    }
    closest(selector) {
        if (selector.split(',').map(part => part.trim()).includes(this.tag)) return this;
        return this.parentNode?.closest(selector) || null;
    }
    descendants() { return this.children.flatMap(node => [node, ...node.descendants()]); }
    find(className) { return this.descendants().find(node => node.classList.contains(className)); }
}

function setup(value = '', {renderer = true, native = false} = {}) {
    const renders = [], timers = new Map(), changes = [];
    const document = {createElement(tag) { return new Element(document, tag); }};
    const parent = new Element(document, 'section');
    const textarea = new Element(document, 'textarea');
    textarea.value = value; textarea.className = 'ocl-text';
    textarea.setAttribute('aria-label', '이전 이름'); textarea.placeholder = '이전 힌트';
    parent.appendChild(textarea);
    let nextTimer = 0;
    const window = {
        setTimeout(callback, delay) { const id = ++nextTimer; timers.set(id, {callback, delay}); return id; },
        clearTimeout(id) { timers.delete(id); },
    };
    if (renderer) window.OclMarkdown = {render(preview, source) { renders.push(source); preview.textContent = source; }};
    if (native) document.execCommand = (command, ui, replacement) => {
        assert.equal(command, 'insertText'); assert.equal(document.activeElement, textarea);
        textarea.setRangeText(replacement, textarea.selectionStart, textarea.selectionEnd);
        textarea.fire('input'); return true;
    };
    vm.runInNewContext(script, {window, document, console});
    const editor = window.OclTextCell.attach(textarea, () => changes.push(textarea.value));
    const find = name => parent.find(name);
    return {window, document, parent, textarea, editor, renders, timers, changes, find,
        click(name) { find('ocl-md-' + name).fire('click'); },
        flush() { const pending = Array.from(timers.values()); timers.clear(); pending.forEach(item => item.callback()); }};
}

test('existing text opens as a preview and editing never changes the source', () => {
    const a = setup('# 기록\n**중요**');
    assert.equal(a.find('ocl-markdown-source').hidden, true);
    assert.equal(a.find('ocl-markdown-toolbar').hidden, true);
    assert.equal(a.find('ocl-markdown-view-actions').hidden, false);
    assert.deepEqual(a.renders, ['# 기록\n**중요**']);
    a.click('edit');
    assert.equal(a.find('ocl-markdown-source').hidden, false);
    assert.equal(a.document.activeElement, a.textarea);
    a.click('close');
    assert.equal(a.find('ocl-markdown-source').hidden, true);
    assert.equal(a.document.activeElement, a.find('ocl-md-edit'));
    assert.deepEqual(a.changes, []);
    assert.equal(a.textarea.value, '# 기록\n**중요**');
});

test('new empty text starts editing and input previews without duplicating model notifications', () => {
    const a = setup();
    assert.equal(a.find('ocl-markdown-source').hidden, false);
    a.textarea.value = '**입력**'; a.textarea.fire('input');
    assert.equal(a.find('ocl-markdown').textContent, '**입력**');
    assert.deepEqual(a.changes, []);
    a.textarea.setSelectionRange(2, 4);
    a.click('bold');
    assert.equal(a.textarea.value, '입력');
    assert.deepEqual(a.changes, ['입력']);
});

test('formatting preserves the selected text and supports toggling wrappers', () => {
    const a = setup('앞 선택 뒤');
    a.editor.focus(); a.textarea.setSelectionRange(2, 4); a.click('bold');
    assert.equal(a.textarea.value, '앞 **선택** 뒤');
    assert.equal(a.textarea.value.slice(a.textarea.selectionStart, a.textarea.selectionEnd), '선택');
    a.click('bold'); assert.equal(a.textarea.value, '앞 선택 뒤');
    a.textarea.setSelectionRange(2, 4); a.click('italic');
    assert.equal(a.textarea.value, '앞 *선택* 뒤');
    a.textarea.setSelectionRange(2, 6); a.click('italic');
    assert.equal(a.textarea.value, '앞 선택 뒤');
    assert.equal(a.changes.length, 4);
});

test('native insertText emits one adapter notification and restores selection', () => {
    const a = setup('native', {native: true});
    a.editor.focus(); a.textarea.setSelectionRange(0, 6); a.click('bold');
    assert.equal(a.textarea.value, '**native**');
    assert.deepEqual([a.textarea.selectionStart, a.textarea.selectionEnd], [2, 8]);
    assert.deepEqual(a.changes, ['**native**']);
});

test('shortcuts support both modifier keys, close editing and ignore IME composition', () => {
    const a = setup(); a.textarea.value = '문장'; a.textarea.setSelectionRange(0, 2);
    a.textarea.fire('compositionstart');
    let event = a.textarea.fire('keydown', {key: 'b', ctrlKey: true});
    assert.equal(event.prevented, undefined); a.click('bold');
    assert.equal(a.textarea.value, '문장');
    a.textarea.fire('keydown', {key: 'Enter', ctrlKey: true});
    assert.equal(a.find('ocl-markdown-source').hidden, false);
    a.textarea.fire('compositionend');
    event = a.textarea.fire('keydown', {key: 'b', metaKey: true});
    assert.equal(event.prevented, true); assert.equal(a.textarea.value, '**문장**');
    a.textarea.fire('keydown', {key: 'i', ctrlKey: true});
    assert.equal(a.textarea.value, '***문장***');
    a.textarea.fire('keydown', {key: 'Enter', ctrlKey: true});
    assert.equal(a.find('ocl-markdown-source').hidden, true);
    a.editor.focus(); a.textarea.fire('keydown', {key: 'Escape'});
    assert.equal(a.find('ocl-markdown-source').hidden, true);
});

test('multi-line block tools affect only selected lines and preserve surrounding content', () => {
    for (const [action, expected] of [
        ['heading', '## 가\n## 나'], ['quote', '> 가\n> 나'],
        ['ordered', '1. 가\n2. 나'], ['unordered', '- 가\n- 나'],
    ]) {
        const a = setup('앞\n가\n나\n뒤'); a.editor.focus();
        a.textarea.setSelectionRange(2, 6); a.click(action);
        assert.equal(a.textarea.value, '앞\n' + expected + '\n뒤');
        assert.equal(a.changes.length, 1);
    }
});

test('link and image tools select the destination and escape bracketed labels', () => {
    for (const [action, prefix] of [['link', ''], ['image', '!']]) {
        const a = setup('보기[1]'); a.editor.focus(); a.textarea.setSelectionRange(0, 5); a.click(action);
        assert.equal(a.textarea.value, prefix + '[보기\\[1\\]](https://)');
        assert.equal(a.textarea.value.slice(a.textarea.selectionStart, a.textarea.selectionEnd), 'https://');
    }
    const a = setup('링크'); a.editor.focus(); a.textarea.setSelectionRange(0, 2);
    a.textarea.fire('keydown', {key: 'k', ctrlKey: true});
    assert.equal(a.textarea.value, '[링크](https://)');
});

test('code blocks use fences longer than the selected backticks and separate block boundaries', () => {
    const a = setup('앞```code```뒤'); a.editor.focus(); a.textarea.setSelectionRange(1, 11); a.click('code-block');
    assert.equal(a.textarea.value, '앞\n\n````\n```code```\n````\n\n뒤');
    assert.equal(a.textarea.value.slice(a.textarea.selectionStart, a.textarea.selectionEnd), '```code```');
    const b = setup('앞뒤'); b.editor.focus(); b.textarea.setSelectionRange(1, 1); b.click('rule');
    assert.equal(b.textarea.value, '앞\n\n---\n\n뒤');
    const c = setup(); c.click('table');
    assert.match(c.textarea.value, /^\| 제목 1 \| 제목 2 \|\n\| --- \| --- \|\n/);
    assert.equal(c.textarea.value.slice(c.textarea.selectionStart, c.textarea.selectionEnd), '제목 1');
});

test('preview double-click reopens editing while links retain their default interaction', () => {
    const a = setup('기록');
    const link = a.document.createElement('a'); a.find('ocl-markdown').appendChild(link);
    a.find('ocl-markdown').fire('dblclick', {target: link});
    assert.equal(a.find('ocl-markdown-source').hidden, true);
    a.find('ocl-markdown').fire('dblclick');
    assert.equal(a.find('ocl-markdown-source').hidden, false);
    assert.equal(a.document.activeElement, a.textarea);
});

test('large edits are debounced, closing flushes the preview and destruction cancels timers', () => {
    const a = setup(); a.textarea.value = 'x'.repeat(5000); a.textarea.fire('input');
    assert.equal(a.timers.size, 1); assert.equal([...a.timers.values()][0].delay, 100);
    a.textarea.value += '나중'; a.textarea.fire('input'); assert.equal(a.timers.size, 1);
    a.click('close'); assert.equal(a.timers.size, 0);
    assert.equal(a.renders.at(-1), 'x'.repeat(5000) + '나중');
    a.editor.focus(); a.textarea.fire('input'); assert.equal(a.timers.size, 1);
    a.editor.destroy(); assert.equal(a.timers.size, 0);
    assert.equal(a.parent.children.length, 1); assert.equal(a.parent.children[0], a.textarea);
    assert.equal(a.textarea.getAttribute('aria-label'), '이전 이름'); assert.equal(a.textarea.placeholder, '이전 힌트');
    a.textarea.value = 'disposed'; a.textarea.fire('input'); a.editor.focus(); a.editor.destroy();
    assert.equal(a.timers.size, 0); assert.deepEqual(a.changes, []);
});

test('missing or failed renderer preserves literal source safely', () => {
    const a = setup('<img onerror=alert(1)>\n다음', {renderer: false});
    assert.equal(a.find('ocl-markdown').textContent, a.textarea.value);
    assert.equal(a.find('ocl-markdown').classList.contains('ocl-markdown-plain'), true);
    a.window.OclMarkdown = {render() { throw new Error('failed'); }};
    a.editor.focus(); assert.equal(a.find('ocl-markdown').textContent, a.textarea.value);
    assert.deepEqual(a.changes, []);
});

test('format tools respect read-only state and existing input length limits', () => {
    const a = setup('글'); a.editor.focus(); a.textarea.setSelectionRange(0, 1);
    a.textarea.readOnly = true; a.click('bold'); assert.equal(a.textarea.value, '글');
    a.textarea.readOnly = false; a.textarea.maxLength = 3; a.click('bold');
    assert.equal(a.textarea.value, '글'); assert.deepEqual(a.changes, []);
});
