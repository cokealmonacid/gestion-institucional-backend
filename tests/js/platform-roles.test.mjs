import test from 'node:test';
import assert from 'node:assert/strict';
import { rolePanel } from '../../resources/js/platform/roles.js';
import { PlatformError } from '../../resources/js/platform/client.js';

const user = { user_id: '11111111-1111-4111-8111-111111111111', institution_id: '22222222-2222-4222-8222-222222222222', access_revision: 3, roles: ['admin'], active: true };
const store = () => { const values = new Map(); return { getItem: k => values.get(k), setItem: (k, v) => values.set(k, v), removeItem: k => values.delete(k) }; };
function panel(storage = store()) {
    return Object.assign(rolePanel(storage), {
        roleUser: { ...user }, roleDestination: 'reader', roleReason: 'Reviewed correction', roleConfirmation: true,
        roleImpact: { access_revision: 3, confirmation_required: true, affected_documents_count: 2, impact_token: 'safe-impact' },
        async run(task) { try { await task(); } catch (error) { this.error = error; } },
        async refreshRoleViews() { this.refreshed = true; },
    });
}

test('confirmed role change sends reviewed revision and impact, preserves activity and refreshes views', async () => {
    const p = panel(); let sent;
    p.client = { async request(path, options) { sent = { path, options }; return { ...user, roles: ['reader'], access_revision: 4 }; } };
    await p.submitRoleChange();
    assert.equal(sent.options.body.expected_user_revision, 3);
    assert.equal(sent.options.body.role_impact_token, 'safe-impact');
    assert.equal(sent.options.body.confirm_responsibility_removal, true);
    assert.equal(sent.options.body.active, undefined);
    assert.equal(p.refreshed, true); assert.equal(p.roleUser.active, true);
    assert.match(p.roleResult, /confirmado/);
});

test('definitive rejection retains editable destination/reason but requires a new review', async () => {
    const p = panel(); p.client = { request: async () => { throw new PlatformError('USER_QUOTA_EXCEEDED', 409); } };
    await p.submitRoleChange();
    assert.equal(p.roleUnknown, false); assert.equal(p.roleDestination, 'reader');
    assert.equal(p.roleReason, 'Reviewed correction'); assert.equal(p.roleImpact, null); assert.equal(p.roleConfirmation, false);
});

test('uncertain mutation survives reload without storing a retry payload and blocks duplicate submission', async () => {
    const storage = store(); const p = panel(storage); let calls = 0;
    p.client = { request: async () => { calls++; throw new PlatformError('NETWORK_UNCERTAIN'); } };
    await p.submitRoleChange(); await p.submitRoleChange();
    assert.equal(calls, 1); assert.equal(p.roleUnknown, true);
    const restored = rolePanel(storage); assert.equal(restored.roleUnknown, true);
    assert.deepEqual(Object.keys(restored.roleUser).sort(), ['institution_id', 'user_id']);
    await p.resolveRoleUnknown(); assert.equal(p.refreshed, true); assert.equal(p.roleUnknown, false);
    assert.equal(rolePanel(storage).roleUnknown, false);
});

test('access deactivation sends no role or responsibility mutation; refresh failure does not undo confirmed result', async () => {
    const p = panel(); p.roleAction = 'access'; p.roleImpact = null; let body;
    p.client = { request: async (_, options) => { body = options.body; return { ...user, active: false, access_revision: 4 }; } };
    p.refreshRoleViews = async () => { throw new PlatformError('NETWORK_UNCERTAIN'); };
    await p.submitRoleChange();
    assert.equal(body.active, false); assert.equal(body.role, undefined); assert.equal(body.role_impact_token, undefined);
    assert.equal(p.roleUser.active, false); assert.equal(p.roleUnknown, false); assert.match(p.roleResult, /confirmado/);
});

test('an obsolete review cannot send a role mutation', async () => {
    const p = panel(); p.roleImpact.access_revision = 2;
    p.client = { request: () => { assert.fail('stale review submitted'); } };
    await p.submitRoleChange();
});

test('session generation changes after transport are treated as uncertain', async () => {
    const p = panel(); p.client = { request: async () => { throw new PlatformError('SESSION_CHANGED', 401); } };
    await p.submitRoleChange(); assert.equal(p.roleUnknown, true);
});

test('ordinary quota preview releases actual previous memberships and checks activity separately', () => {
    const p = panel();
    p.roleUser.roles = ['admin', 'editor']; p.roleDestination = 'reader';
    p.detail = { quotas: { roles: { admin: { used: 1, limit: 1 }, editor: { used: 1, limit: 1 }, reader: { used: 0, limit: 0 } } } };
    assert.deepEqual(p.roleQuotaPreview().map(row => [row.before, row.after, row.blocked]), [[1, 0, false], [1, 0, false], [0, 1, true]]);
    p.roleAction = 'access';
    assert.deepEqual(p.roleQuotaPreview().map(row => row.after), [0, 0, 0]);
    p.roleUser.active = false;
    assert.deepEqual(p.roleQuotaPreview().map(row => row.after), [2, 2, 0]);
});
