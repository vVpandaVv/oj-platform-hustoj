import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('../resources/js/addon.js', import.meta.url), 'utf8');
const csv = source.match(/\tfunction csvCell\(value\) \{[\s\S]*?\n\t\}/);
assert.ok(csv);
const cell = vm.runInNewContext('(' + csv[0].trim() + ')');
assert.equal(cell('일반 학생'), '"일반 학생"');
assert.equal(cell('=WEBSERVICE("https://example.invalid")'), '"\'=WEBSERVICE(""https://example.invalid"")"');
for (const prefix of ['=', '+', '-', '@', ' \t=', '\r\n=']) {
    assert.ok(cell(prefix + 'danger').startsWith('"\''), 'formula must be neutralized');
}
assert.equal(cell('a,"b"\n끝'), '"a,""b""\n끝"');
assert.equal(cell(null), '""');
assert.match(source, /row\.map\(csvCell\)/);
assert.match(source, /name="maintenanceMode"/);
assert.match(source, /form\.elements\.maintenanceMode\.checked/);
assert.match(source, /records\.map/);
assert.match(source, /historyPage = previousPage/);
assert.match(source, /xhr\.timeout = 120000/);
console.log('OJ ML release tools UI regression passed.');

