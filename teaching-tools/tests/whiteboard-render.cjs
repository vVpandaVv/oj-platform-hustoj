const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

function fixture(navigator = {}) {
    const calls = [];
    function canvas() {
        const target = { width: 1000, height: 600 };
        const ctx = { canvas: target };
        for (const name of ['save', 'restore', 'clearRect', 'drawImage', 'beginPath', 'moveTo', 'lineTo', 'stroke', 'strokeRect', 'ellipse', 'fillText']) {
            ctx[name] = (...args) => calls.push({ name, args, composite: ctx.globalCompositeOperation });
        }
        target.getContext = () => ctx;
        return target;
    }
    const window = {};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../resources/js/whiteboard-render.js'), 'utf8'), {
        window, navigator, document: { createElement: canvas }, Image: class {},
    });
    return { render: window.HttWhiteboardRender, canvas: canvas(), calls };
}
function stroke(count, type = 'draw') {
    return { type, color: '#111111', width: 4, points: Array.from({ length: count }, (_, i) => [i % 1000, (i % 2) * 10 + 100]) };
}
test('live ink only draws new segments, regardless of committed board size or stroke length', () => {
    const f = fixture(); const layer = f.render.createLiveLayer(f.canvas, {});
    layer.setBase(Array.from({ length: 200 }, () => stroke(600)));
    const active = stroke(10000); layer.preview(active, 'pen'); f.calls.length = 0;
    active.points.push([200, 300], [210, 310]); layer.preview(active, 'pen');
    assert.equal(f.calls.filter((call) => call.name === 'lineTo').length, 2);
    assert.equal(f.calls.some((call) => call.name === 'clearRect' || call.name === 'drawImage'), false);
    f.calls.length = 0; layer.preview(active, 'pen'); assert.equal(f.calls.length, 0);
});
test('eraser operates on ink, cancellation restores committed bitmap, and shapes do not accumulate', () => {
    const f = fixture(); const layer = f.render.createLiveLayer(f.canvas, {}); layer.setBase([stroke(20)]);
    f.calls.length = 0; layer.preview(stroke(3, 'erase'), 'eraser');
    assert.equal(f.calls.find((call) => call.name === 'stroke').composite, 'destination-out');
    f.calls.length = 0; layer.preview(null, null);
    assert.deepEqual(f.calls.map((call) => call.name), ['clearRect', 'drawImage']);
    const shape = { type: 'rect', color: '#111111', width: 4, x1: 10, y1: 10, x2: 30, y2: 30 };
    layer.preview(shape, 'shape'); f.calls.length = 0; shape.x2 = 100; layer.preview(shape, 'shape');
    assert.deepEqual(f.calls.slice(0, 2).map((call) => call.name), ['clearRect', 'drawImage']);
    assert.equal(f.calls.filter((call) => call.name === 'strokeRect').length, 1);
});
test('resizing rebuilds at current density and short replacement previews start over', () => {
    const f = fixture(); const layer = f.render.createLiveLayer(f.canvas, {}); layer.setBase([]);
    layer.preview(stroke(100), 'pen'); f.calls.length = 0; layer.preview(stroke(3), 'pen');
    assert.equal(f.calls.filter((call) => call.name === 'lineTo').length, 2);
    assert.equal(f.calls.some((call) => call.name === 'drawImage'), true);
    f.canvas.width = 3000; f.canvas.height = 1800; layer.setBase([stroke(10)]);
    const copied = f.calls.filter((call) => call.name === 'drawImage').at(-1).args[0];
    assert.equal(copied.width, 3000); assert.equal(copied.height, 1800);
});
test('committing a host stroke does not replay all prior ink', () => {
    const f = fixture(); const layer = f.render.createLiveLayer(f.canvas, {});
    const elements = Array.from({ length: 200 }, () => stroke(600)); layer.setBase(elements);
    const next = stroke(5); elements.push(next); f.calls.length = 0; layer.append(next);
    assert.equal(f.calls.filter((call) => call.name === 'lineTo').length, 4);
    assert.equal(f.calls.filter((call) => call.name === 'drawImage').length, 1);
});
test('iPad including desktop UA uses window expansion; ordinary desktop uses native fullscreen', () => {
    assert.equal(fixture({ userAgent: 'iPad Safari', platform: 'iPad', maxTouchPoints: 5 }).render.prefersWindowExpansion(), true);
    assert.equal(fixture({ userAgent: 'Macintosh Safari', platform: 'MacIntel', maxTouchPoints: 5 }).render.prefersWindowExpansion(), true);
    assert.equal(fixture({ userAgent: 'Macintosh Safari', platform: 'MacIntel', maxTouchPoints: 0 }).render.prefersWindowExpansion(), false);
    assert.equal(fixture({ userAgent: 'Windows Chrome', platform: 'Win32', maxTouchPoints: 10 }).render.prefersWindowExpansion(), false);
});
