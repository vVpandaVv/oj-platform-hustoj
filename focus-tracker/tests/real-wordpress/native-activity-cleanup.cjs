'use strict';

// Harness cleanup only. Empty failed preparations may remove their own exact
// schedules; any observed event keeps the existing history-preserving close.
const assert = require('node:assert/strict');
async function cleanupFailedActivities({ activities, hasRecordedEvents, teacherUserId, identity, request }) {
    const result = { mode: hasRecordedEvents ? 'close_preserving_records' : 'delete_empty_preparation', actions: [] };
    if (!activities.length) return result;
    if (hasRecordedEvents) {
        for (const activity of activities) {
            try {
                const value = await request('/activities/' + activity.id, 'PATCH', { status: 'closed' });
                result.actions.push({ activityId: activity.id, status: value.status });
            } catch (_) { result.actions.push({ activityId: activity.id, status: 0, error: 'request_failed' }); }
        }
        return result;
    }
    assert.ok(Number.isInteger(Number(teacherUserId)) && Number(teacherUserId) > 0);
    assert.equal(Number(await identity()), Number(teacherUserId), 'Cleanup requires the exact authenticated teacher identity.');
    result.actorVerified = true;
    assert.ok(activities.every(row => Number.isInteger(Number(row.id)) && Number(row.id) > 0));
    const ids = new Set(activities.map(row => Number(row.id)));
    assert.equal(ids.size, activities.length, 'Cleanup IDs must be unique.');
    const before = await request('/activities', 'GET');
    assert.equal(before.status, 200); assert.ok(Array.isArray(before.data));
    for (const activity of activities) {
        const owned = before.data.find(row => Number(row.id) === Number(activity.id));
        assert.ok(owned, 'A cleanup target must be present in the authenticated owner-only activity listing.');
        for (const key of ['name', 'contextType', 'contextId', 'startsAt', 'endsAt']) assert.equal(owned[key], activity[key]);
    }
    result.ownerListingVerified = true;
    for (const activity of activities) {
        const value = await request('/activities/' + activity.id, 'DELETE');
        result.actions.push({ activityId: activity.id, status: value.status, deleted: value.data?.deleted === true });
        assert.equal(value.status, 200); assert.equal(Number(value.data?.id), Number(activity.id)); assert.equal(value.data?.deleted, true);
    }
    const after = await request('/activities', 'GET');
    assert.equal(after.status, 200); assert.ok(Array.isArray(after.data));
    assert.deepEqual(after.data.map(row => Number(row.id)).sort((a, b) => a - b),
        before.data.filter(row => !ids.has(Number(row.id))).map(row => Number(row.id)).sort((a, b) => a - b), 'Cleanup must preserve every other activity ID.');
    result.otherActivityIdsPreserved = true;
    return result;
}
module.exports = { cleanupFailedActivities };
