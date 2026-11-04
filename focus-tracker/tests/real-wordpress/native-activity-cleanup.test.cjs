'use strict';
const test = require('node:test'); const assert = require('node:assert/strict');
const { cleanupFailedActivities } = require('./native-activity-cleanup.cjs');
function fixture(overrides = {}) {
    const target = { id: 71, name: 'A', contextType: 'group', contextId: 'group:1', startsAt: '2026-10-28T09:00:00Z', endsAt: '2026-10-28T09:01:00Z' };
    const unrelated = { ...target, id: 9, name: 'Other' }; let rows = [target, unrelated]; const calls = [];
    const input = { activities: [target], hasRecordedEvents: false, teacherUserId: 2, identity: async () => 2,
        request: async (suffix, method, body) => {
            calls.push({ suffix, method, body });
            if (method === 'GET') return { status: 200, data: rows.map(row => ({ ...row })) };
            if (method === 'DELETE') { const id = Number(suffix.split('/').at(-1)); rows = rows.filter(row => row.id !== id); return { status: 200, data: { id, deleted: true } }; }
            return { status: 200, data: {} };
        }, ...overrides };
    return { input, calls, target };
}
test('an empty scheduled preparation deletes only exact owner-listed IDs and preserves the rest', async () => {
    const { input, calls } = fixture(); const result = await cleanupFailedActivities(input);
    assert.equal(result.mode, 'delete_empty_preparation'); assert.equal(result.actorVerified, true);
    assert.equal(result.ownerListingVerified, true); assert.equal(result.otherActivityIdsPreserved, true);
    assert.deepEqual(calls.map(row => [row.suffix, row.method]), [['/activities', 'GET'], ['/activities/71', 'DELETE'], ['/activities', 'GET']]);
});
test('any observed event keeps the close path and never deletes or reads other schedules', async () => {
    const { input, calls } = fixture({ hasRecordedEvents: true, identity: () => { throw new Error('must not be called'); } });
    const result = await cleanupFailedActivities(input);
    assert.equal(result.mode, 'close_preserving_records'); assert.deepEqual(calls, [{ suffix: '/activities/71', method: 'PATCH', body: { status: 'closed' } }]);
});
test('an account mismatch performs no activity mutation', async () => {
    const { input, calls } = fixture({ identity: async () => 3 });
    await assert.rejects(cleanupFailedActivities(input), /exact authenticated teacher/); assert.equal(calls.length, 0);
});
test('a missing owner-listed ID fails closed before deletion', async () => {
    const { input, calls } = fixture(); input.activities = [{ ...input.activities[0], id: 99 }];
    await assert.rejects(cleanupFailedActivities(input), /owner-only/); assert.equal(calls.filter(row => row.method === 'DELETE').length, 0);
});
for (const key of ['name', 'contextType', 'contextId', 'startsAt', 'endsAt']) test('a changed report field blocks deletion: ' + key, async () => {
    const { input, calls } = fixture(); input.activities = [{ ...input.activities[0], [key]: 'different' }];
    await assert.rejects(cleanupFailedActivities(input)); assert.equal(calls.filter(row => row.method === 'DELETE').length, 0);
});
test('a delete refusal stays a cleanup failure without issuing another mutation', async () => {
    const { input, calls } = fixture(); const original = input.request;
    input.request = async (...args) => args[1] === 'DELETE' ? { status: 403, data: {} } : original(...args);
    await assert.rejects(cleanupFailedActivities(input)); assert.equal(calls.filter(row => row.method === 'PATCH').length, 0);
});
test('the helper detects any unrelated activity loss in the post-cleanup listing', async () => {
    const { input } = fixture(); const original = input.request; let gets = 0;
    input.request = async (...args) => { const value = await original(...args); if (args[1] === 'GET' && ++gets === 2) value.data = []; return value; };
    await assert.rejects(cleanupFailedActivities(input), /preserve every other/);
});
test('a close failure preserves the original experiment outcome and never falls back to delete', async () => {
    const { input, calls } = fixture({ hasRecordedEvents: true }); const original = input.request;
    input.request = async (...args) => { if (args[1] === 'PATCH') { calls.push({ method: 'PATCH' }); throw new Error('private request details'); } return original(...args); };
    const result = await cleanupFailedActivities(input); assert.equal(result.actions[0].error, 'request_failed');
    assert.equal(JSON.stringify(result).includes('private request details'), false); assert.equal(calls.filter(row => row.method === 'DELETE').length, 0);
});
