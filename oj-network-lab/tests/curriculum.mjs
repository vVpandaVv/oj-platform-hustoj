import assert from 'node:assert/strict';
import fs from 'node:fs';
import {CURRICULUM, resolveLaunch} from '../resources/js/curriculum.js';
import {LESSONS, scenario} from '../resources/js/scenarios.js';
import {validateProject, VERSION} from '../resources/js/model.js';

assert.equal(CURRICULUM.length, 23);
for (const [i, exercise] of CURRICULUM.entries()) {
    assert.equal(exercise.id, `l${String(i + 1).padStart(2, '0')}`);
    assert(LESSONS.some(lesson => lesson.id === exercise.scenario), `${exercise.id}: known preset`);
    assert(exercise.title && exercise.goal);
    const launch = resolveLaunch(`?exercise=${exercise.id}&scenario=not-a-preset`);
    assert.equal(launch.exercise, exercise);
    assert.equal(launch.lesson.id, exercise.scenario);
    assert.equal(launch.invalid, false);
    const p = validateProject(scenario(exercise.scenario));
    for (const version of ['1.3.0-rc.2', '1.4.0-rc.3']) {
        const legacy = structuredClone(p);
        legacy.engine = version;
        assert.deepEqual(validateProject(legacy), p, `${exercise.id}: ${version} configuration preserved`);
    }
}
for (const lesson of LESSONS) {
    assert.deepEqual(resolveLaunch(`?scenario=${lesson.id}`).lesson, lesson);
    assert.deepEqual(resolveLaunch(`?exercise=unknown&scenario=${lesson.id}`).lesson, lesson);
}
for (const query of ['', '?exercise=', '?exercise=l00', '?exercise=l24', '?exercise=L01', '?exercise=__proto__', '?exercise=constructor', '?exercise=l01&exercise=l02', '?exercise=l02&exercise=l02', '?scenario=lan&scenario=dhcp', '?exercise=%3Cimg%20src=x%20onerror=alert(1)%3E', '?exercise=..%2Fwindows', '?scenario=unknown']) {
    const launch = resolveLaunch(query);
    assert.equal(launch.exercise, null, query);
    assert.equal(launch.lesson, null, query);
    assert.equal(launch.invalid, query !== '', query);
}
assert.equal(resolveLaunch('?exercise=l02&scenario=broken').lesson.id, 'lan');
assert.equal(resolveLaunch('?exercise=l02&scenario=broken&scenario=empty').lesson.id, 'lan');
assert.equal(resolveLaunch('?exercise=l02&exercise=l03&scenario=dhcp').lesson.id, 'dhcp');
assert.equal(resolveLaunch('?exercise=%6c02').exercise.id, 'l02');
const root = new URL('../', import.meta.url);
for (const file of ['oj-network-lab.php', 'plugin-package.php', 'tests/backend.php', 'tests/render.php']) {
    assert(fs.readFileSync(new URL(file, root), 'utf8').includes(`'${VERSION}'`), file);
}
assert.equal(fs.readFileSync(new URL('oj-network-lab.php', root), 'utf8').match(/Version:\s*(\S+)/)[1], VERSION);
for (const file of fs.readdirSync(new URL('resources/js/', root)).filter(f => f.endsWith('.js'))) {
    const text = fs.readFileSync(new URL('resources/js/' + file, root), 'utf8');
    for (const match of text.matchAll(/\?ver=([^'"\s]+)/g)) assert.equal(match[1], VERSION, file);
}
console.log('Curriculum checks passed: 23 mappings, URL allowlist/precedence/duplicates, legacy presets, 1.3 rc.2 / 1.4 rc.3 imports and asset versions.');
