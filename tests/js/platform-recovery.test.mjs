import test from 'node:test';
import assert from 'node:assert/strict';
import { recoveryPanel } from '../../resources/js/platform/recovery.js';
import { PendingOperation, PlatformError } from '../../resources/js/platform/client.js';

const key = '11111111-1111-4111-8111-111111111111';
const storage = () => { const values = new Map(); return { getItem: k => values.get(k), setItem: (k, v) => values.set(k, v), removeItem: k => values.delete(k) }; };
function panel(tab = storage()) {
    return Object.assign(recoveryPanel(), { operator: { id: key }, recoveryStore: new PendingOperation(tab, 'recovery'),
        async run(task) { try { await task(); } catch (error) { this.error = error; } }, label: value => value, clearSecrets() { this.auth.password = ''; this.auth.password_confirmation = ''; } });
}

test('lost preparation response retains exact key/body across reload; missing lookup never permits fresh creation', async () => {
    const tab = storage(); const p = panel(tab); const body = { kind: 'replacement', successor_email: 'fixture@example.test' };
    p.client = { request: async () => { throw new PlatformError('NETWORK_UNCERTAIN'); } };
    await assert.rejects(() => p.submitRecovery(body));
    const saved = p.recoveryStore.value; body.successor_email = 'changed@example.test';
    const next = panel(tab);
    assert.equal(next.recoveryStore.value.body.successor_email, 'fixture@example.test');
    next.client = { request: async () => { throw new PlatformError('RESOURCE_NOT_FOUND', 404); } };
    await next.resumeRecovery(saved.key); assert.equal(next.recoveryStore.value.status, 'uncertain');
    assert.throws(() => next.recoveryStore.dismiss());
    let sent;
    next.client = { request: async (path, options) => { sent = { path, options }; return { id: saved.key, state: 'awaiting_verification', publication_status: 'failed' }; } };
    await next.retryRecoveryPreparation();
    assert.equal(sent.options.key, saved.key); assert.equal(sent.options.body.successor_email, 'fixture@example.test');
    assert.equal(next.recoveryStore.value, null); assert.equal(next.recoveryOperation.publication_status, 'failed');
});

test('durable operation lookup works without a browser draft; uncertain completion refreshes instead of creating again', async () => {
    const p = panel(); let method;
    p.client = { request: async (_, options = {}) => { method = options.method; return { id: key, state: 'verified', revision: 2 }; } };
    await p.resumeRecovery(key); assert.equal(method, undefined); assert.equal(p.screen, 'recovery-operation');
    p.client.request = async () => { throw new PlatformError('NETWORK_UNCERTAIN'); };
    await assert.rejects(() => p.performRecoveryTransition(`access-recoveries/${key}/complete`, { expected_revision: 2, confirmation: true }));
    assert.equal(p.recoveryUnknown, true);
    await p.openRecovery({ user_id: key, institution_id: key }, 'replacement');
    assert.equal(p.screen, 'recovery-operation'); assert.equal(p.recoveryOperation.id, key);
    p.client.request = async () => ({ id: key, state: 'completed', revision: 3 });
    await p.resumeRecovery(key); assert.equal(p.recoveryUnknown, false); assert.equal(p.recoveryOperation.state, 'completed');
});

test('recipient verification never sends existing-user passwords or creates a session; new setup secrets are cleared', async () => {
    const p = panel(); let body;
    p.auth = { password: 'Synthetic-password-123', password_confirmation: 'Synthetic-password-123' };
    p.link = { operation: key, token: 'synthetic-proof', setup: false };
    p.client = { request: async (_, options) => { body = options.body; return { verified: true, access_granted: false }; } };
    await p.verifyRecoveryAddress(); assert.equal(body.password, undefined); assert.equal(p.auth.password, ''); assert.equal(p.link, null); assert.equal(p.phase, 'recovery-verified');
    p.link = { operation: key, token: 'another-synthetic-proof', setup: true }; p.auth.password = 'Synthetic-new-password'; p.auth.password_confirmation = p.auth.password;
    await p.verifyRecoveryAddress(); assert.equal(body.password, 'Synthetic-new-password'); assert.equal(p.auth.password, '');
});

test('replacement quota projection releases previous successor roles and flags only worsening excess', () => {
    const p = panel();
    p.recoveryOperation = { kind: 'replacement', snapshot: { target_active: true, target_roles: ['admin'], successor_active: true,
        successor_roles: ['editor', 'reader'], quota_before: { roles: { admin: { used: 1, limit: 0 }, editor: { used: 1, limit: 0 }, reader: { used: 1, limit: 0 } } } } };
    assert.deepEqual(p.recoveryQuotaPreview().map(r => [r.before, r.after, r.blocked]), [[1, 1, false], [1, 0, false], [1, 0, false]]);
    p.recoveryOperation.snapshot.successor_active = false;
    assert.equal(p.recoveryQuotaPreview()[1].blocked, false);
});

test('another authorized operator can resolve a matching durable preparation without inheriting its retry authority', async () => {
    const p = panel();
    const body = { kind: 'email_correction', new_email: 'Fixture@Example.Test', reason: 'Fixture' };
    p.recoveryStore.prepare('original-operator', 'access_recovery', 'access-recoveries', body, () => key);
    p.recoveryStore.value.status = 'uncertain';
    p.client = { request: async () => ({ id: key, state: 'verified', request: { reason: 'Fixture', new_email: 'fixture@example.test', kind: 'email_correction' } }) };
    await p.resumeRecovery(key);
    assert.equal(p.recoveryStore.value, null); assert.equal(p.recoveryOperation.state, 'verified');
});
