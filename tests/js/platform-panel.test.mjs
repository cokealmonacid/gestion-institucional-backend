import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import * as client from '../../resources/js/platform/client.js';
import { recoveryPanel } from '../../resources/js/platform/recovery.js';
import { rolePanel } from '../../resources/js/platform/roles.js';

const id = '11111111-1111-4111-8111-111111111111';
function panel() {
    let factory;
    const source = readFileSync(new URL('../../resources/js/platform.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '');
    vm.runInNewContext(source, { ...client, recoveryPanel, rolePanel, Alpine: { data: (_, value) => { factory = value; }, start() {} }, globalThis, setTimeout, clearTimeout });
    const p = factory();
    p.$refs = { confirmation: { close() {} } }; p.$nextTick = () => {};
    const values = new Map();
    p.pendingStore = new client.PendingOperation({ getItem: k => values.get(k), setItem: (k, v) => values.set(k, v), removeItem: k => values.delete(k) });
    p.operator = { id }; p.detail = { id, name: 'Fixture', status: true, customer: {}, quotas: { roles: { admin: { used: 1 } } } };
    p.invitation = { name: 'Fixture admin', email: 'fixture@example.test', reason: 'Fixture' };
    p.review = { kind: 'invitation', path: `institutions/${id}/administrator-invitations`, body: { ...p.invitation } };
    p.screen = 'admins';
    return p;
}
function transport(p, outcome) {
    const calls = [];
    p.client = { async request(path, options = {}) {
        calls.push({ path, options });
        if (options.method === 'POST') return outcome();
        if (path.includes('/administrators/')) return { user_id: id, active: true, invitation_id: id, delivery_status: 'failed', activation_status: 'pending_activation' };
        if (path.includes('/administrators?')) return { items: [{ user_id: id }], page: 1, last_page: 1 };
        if (path.startsWith('operations/')) throw new client.PlatformError('RESOURCE_NOT_FOUND', 404);
        return { id, name: 'Fixture', customer: {}, status: true, quotas: { roles: { admin: { used: 2 } } } };
    } };
    return calls;
}

test('definitive 409 email conflict unlocks the editable invitation, preserves values and shows field guidance', async () => {
    const p = panel(); const original = JSON.stringify(p.invitation);
    transport(p, () => { throw new client.PlatformError('EMAIL_UNAVAILABLE', 409); });
    await p.executeReview();
    assert.equal(p.pendingStore.value, null); assert.equal(p.pending, null); assert.equal(p.screen, 'admins');
    assert.equal(JSON.stringify(p.invitation), original); assert.match(p.invitationEmailError, /Corrígelo/); assert.equal(p.busy, false);
});

for (const status of ['queued', 'failed', 'unknown']) {
    test(`confirmed invitation (${status}) unlocks form and refreshes list, quota and access`, async () => {
        const p = panel(); const calls = transport(p, () => ({ institution_id: id, administrator_id: id, publication_status: status }));
        await p.executeReview();
        assert.equal(p.error, ''); assert.equal(p.pendingStore.value, null); assert.equal(p.screen, 'admins');
        assert.equal(p.invitationResult.publication_status, status); assert.equal(p.detail.quotas.roles.admin.used, 2);
        assert.equal(p.admin.user_id, id); assert.equal(p.admin.delivery_status, 'failed'); assert.equal(p.admins.items.length, 1);
        assert.equal(p.invitation.email, ''); assert.ok(calls.some(c => c.path.includes('/administrators?')));
    });
}

for (const error of [new client.PlatformError('NETWORK_UNCERTAIN'), new client.PlatformError('IDEMPOTENCY_CONFLICT', 409)]) {
    test(`uncertain ${error.code} retains exact key/body through missing lookup and explicit replay`, async () => {
        const p = panel(); let resolved = false;
        const calls = transport(p, () => { if (!resolved) throw error; return { institution_id: id, administrator_id: id, publication_status: 'failed' }; });
        await p.executeReview();
        const saved = JSON.stringify(p.pendingStore.value.body); const key = p.pendingStore.value.key;
        assert.equal(p.pending.status, 'uncertain'); assert.equal(p.screen, 'pending');
        await p.reconcile(); assert.equal(p.pending.status, 'uncertain'); assert.equal(p.pending.key, key);
        p.invitation.email = 'edited@example.test'; resolved = true; await p.retryPending();
        const posts = calls.filter(c => c.options.method === 'POST');
        assert.equal(posts.length, 2); assert.equal(posts[1].options.key, key); assert.equal(JSON.stringify(posts[1].options.body), saved);
        assert.equal(p.pendingStore.value, null); assert.equal(p.screen, 'admins');
    });
}

test('lookup confirmation clears pending state and refreshes created account despite publication failure', async () => {
    const p = panel(); const request = transport(p, () => { throw new client.PlatformError('NETWORK_UNCERTAIN'); });
    await p.executeReview(); const previous = p.client.request;
    p.client.request = (path, options) => path.startsWith('operations/') ? Promise.resolve({ result: { institution_id: id, administrator_id: id, publication_status: 'failed' } }) : previous(path, options);
    await p.reconcile();
    assert.equal(p.pendingStore.value, null); assert.equal(p.invitationResult.publication_status, 'failed'); assert.equal(p.admins.items.length, 1);
    assert.equal(request.filter(c => c.options.method === 'POST').length, 1);
});

test('Usuarios navigation filters directory and opens administrator actions or read-only access in institution context', async () => {
    const p = panel(); const calls = [];
    let userRow = { user_id: id, institution_id: id, roles: ['editor', 'reader'] };
    p.client = { async request(path) { calls.push(path); if (path.startsWith('institutions?')) return { items: [{ id, name: 'Fixture' }], page: 1, last_page: 1 };
        if (path.startsWith('users?user_id=')) return { items: [userRow] };
        if (path.startsWith('users?')) return { items: [], page: 1, last_page: 1 };
        if (path.includes('/administrators/')) return { user_id: id, active: true };
        if (path.includes('/administrators?')) return { items: [], page: 1, last_page: 1 };
        return { id, name: 'Fixture', customer: {} }; } };
    p.userFilters = { q: 'fixture', institution_id: id, role: 'editor', active: 'false' };
    await p.navigate('users'); assert.equal(p.screen, 'users'); assert.ok(calls.some(path => path.startsWith('users?') && path.includes('role=editor') && path.includes('active=false')));
    await p.openUserAccess({ user_id: id, institution_id: id, roles: ['editor', 'reader'] }); assert.equal(p.screen, 'user-access'); assert.equal(p.detail.id, id);
    userRow = { ...userRow, roles: ['admin', 'editor'] };
    await p.openUserAccess({ user_id: id, institution_id: id, roles: ['reader'] }); assert.equal(p.screen, 'admins'); assert.equal(p.admin.user_id, id);
});
