const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
test('ticket role follows viewing intent without granting publishing rights', () => {
    const result = spawnSync(process.env.PHP_BINARY || 'D:/php/php.exe', [path.join(__dirname, 'realtime-ticket-fixture.php')]);
    assert.equal(result.status, 0, result.stderr.toString());
    const f = JSON.parse(result.stdout);
    const role = (info) => JSON.parse(Buffer.from(info.ticket.split('.')[0], 'base64url')).role;
    assert.equal(role(f.teacher), 'host'); assert.equal(role(f.ownerView), 'viewer'); assert.equal(role(f.student), 'viewer');
    assert.equal(role(f.cohost), 'host'); assert.equal(role(f.cohostView), 'viewer'); assert.equal(role(f.observer), 'viewer');
    assert.equal(f.forbiddenStatus, 403); assert.equal(f.observerHostStatus, 403); assert.equal(f.outsiderStatus, 404); assert.equal(f.invalidStatus, 400);
    assert.deepEqual(f.rateLimit, { allowed: 30, lastStatus: 429 });
});
