import assert from 'node:assert/strict';
import { readdir, readFile, mkdir, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { validateProject } from '../resources/js/model.js';
const directory = new URL('./fixtures/', import.meta.url);
if (process.argv[2]) {
    await mkdir(directory, { recursive: true });
    for (const version of ['0.1.0', '0.2.0', '0.3.0', '0.4.0', '0.5.0']) {
        const module = await import(pathToFileURL(resolve(process.argv[2], version, 'oj-logic-lab/resources/js/examples.js')));
        for (const name of ['half', 'dff']) {
            const p = module.example(name); p.title = `${version} 기존 회로 · ${name}`; p.settings.step = 17; p.settings.speed = 31;
            for (const n of p.nodes) { n.config.delay = 7; if (n.type === 'CLOCK') Object.assign(n.config, { period: 74, phase: 19, duty: 35 }); }
            await writeFile(new URL(`${version}-${name}.json`, directory), JSON.stringify({ version, project: p }, null, 2) + '\n', 'utf8');
            if (name === 'half' && ['0.4.0', '0.5.0'].includes(version)) {
                const base = resolve(process.argv[2], version, 'oj-logic-lab/resources/js'), router = await import(pathToFileURL(resolve(base, 'router.js'))), moves = await import(pathToFileURL(resolve(base, 'wire-move.js')));
                const routing = router.routeCircuit(p), segment = routing.segments.find(s => s.net === 'a:Q' && s.dir === 'h' && s.end - s.start > 12), edited = moves.previewWireMove(p, routing, segment, -24);
                assert.ok(edited); const manual = { ...p, wires: edited.wires }; await writeFile(new URL(`${version}-manual.json`, directory), JSON.stringify({ version, project: manual }, null, 2) + '\n', 'utf8');
                if (version === '0.5.0') { const points = await import(pathToFileURL(resolve(base, 'wire-points.js'))), added = points.previewAddWaypoint(manual, edited.routing, 'a:Q', { x: 336, y: 120 }); assert.ok(added); await writeFile(new URL(`${version}-waypoint.json`, directory), JSON.stringify({ version, project: { ...p, wires: added.wires } }, null, 2) + '\n', 'utf8'); }
            }
        }
    }
}
const files = (await readdir(directory)).filter(f => f.endsWith('.json')); assert.ok(files.length >= 10);
for (const file of files) {
    const { version, project, rcExact } = JSON.parse(await readFile(new URL(file, directory), 'utf8')), migrated = validateProject(project);
    if (rcExact) assert.deepEqual(migrated, project, 'all RC fields remain byte-for-byte equivalent after canonical import');
    assert.equal(migrated.title, project.title); assert.equal(migrated.settings.step, 17); assert.equal(migrated.settings.speed, 31);
    assert.equal(migrated.settings.mode, project.settings.mode || 'advanced'); assert.equal(migrated.formatVersion, 1);
    for (const old of project.nodes) { const n = migrated.nodes.find(n => n.id === old.id); assert.equal(n.x, old.x); assert.equal(n.y, old.y); assert.equal(n.label, old.label); for (const [k, v] of Object.entries(old.config)) assert.deepEqual(n.config[k], v, `${version} ${old.id}.${k}`); }
    assert.deepEqual(migrated.wires.map(w => [w.from, w.to]), project.wires.map(w => [w.from, w.to]));
    assert.deepEqual(migrated.wires.map(w => w.path), project.wires.map(w => w.path), 'manual path and waypoint flags preserved');
    assert.deepEqual(validateProject(JSON.parse(JSON.stringify(migrated))), migrated);
}
console.log(`Migration checks passed: ${files.length} actual historical archive examples, 0.1 through RC, with saved input, clock, timing, labels, geometry, connections and RC metadata preserved.`);
