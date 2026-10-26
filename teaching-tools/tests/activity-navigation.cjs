const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../resources/js/activity-navigation.js'), 'utf8');
function storage(blocked = false) {
    const data = new Map();
    return {
        getItem(key) { if (blocked) throw new Error('blocked'); return data.get(key) || null; },
        setItem(key, value) { if (blocked) throw new Error('blocked'); data.set(key, value); }
    };
}
function setup(localStorage = storage(), sessionStorage = storage()) {
    const opened = [];
    const window = { localStorage, sessionStorage, location: { href: 'https://school.test/problems', origin: 'https://school.test', assign(url) { opened.push(url); } } };
    vm.runInNewContext(source, { window, URL, Date });
    return { nav: window.HttActivityNavigation, opened };
}
const config = { currentUserId: 2, joinUrl: '/join' };
const latest = { joinCode: 'NEW123', autoOpen: true };
const older = { joinCode: 'OLD123', autoOpen: true };
const shared = storage();
const first = setup(shared);
assert.equal(first.nav.next([{ joinCode: 'OFF123', autoOpen: false }], '', 2), null);
assert.equal(first.nav.next([latest, older], '', 2).joinCode, 'NEW123');
assert.equal(first.nav.open(latest, config), true);
assert.equal(new URL(first.opened[0]).searchParams.get('code'), 'NEW123');
const second = setup(shared);
assert.equal(second.nav.next([latest, older], '', 2), null, 'Do not reopen older lessons after the newest lesson was visited');
assert.equal(second.nav.open(latest, config), false, 'Another tab must not reopen a lesson that was already opened');
assert.equal(second.nav.next([latest], '', 3).joinCode, 'NEW123', 'Visits must be scoped to the current student');
assert.equal(second.nav.open(older, { ...config, joinUrl: 'https://external.test/join' }), false);
const fallback = setup(storage(true));
assert.equal(fallback.nav.open(latest, config), true);
assert.equal(fallback.nav.next([latest], '', 2), null, 'Session storage must prevent loops when local storage is disabled');
const blocked = setup(storage(true), storage(true));
assert.equal(blocked.nav.open(latest, config), false, 'Do not navigate without durable loop prevention');
assert.equal(blocked.opened.length, 0);
console.log('Activity navigation tests passed: opt-in, latest-only, once-per-student, cross-tab, same-origin and storage fallback.');
const dismissalStore = storage();
const dismissal = setup(dismissalStore);
dismissal.nav.dismiss('NEW123', 2);
assert.equal(dismissal.nav.isDismissed('NEW123', 2), true);
assert.equal(setup(dismissalStore).nav.isDismissed('NEW123', 2), true, 'Leaving must survive reloads and other tabs');
assert.equal(dismissal.nav.isDismissed('NEW123', 3), false, 'Leaving is student-scoped');
assert.equal(dismissal.nav.next([latest, older], '', 2), null, 'Leaving must not open an older forced lesson');
dismissal.nav.resume('NEW123', 2);
assert.equal(setup(dismissalStore).nav.isDismissed('NEW123', 2), false, 'Explicit rejoin must clear the dismissal');
const volatile = setup(storage(true), storage(true));
volatile.nav.dismiss('NEW123', 2);
assert.equal(volatile.nav.isDismissed('NEW123', 2), true, 'Local leave must work even without browser storage');
console.log('Participation dismissal checks passed: reload/cross-tab, student isolation, explicit rejoin and blocked storage.');
