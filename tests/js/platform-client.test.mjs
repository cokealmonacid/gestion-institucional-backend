import test from 'node:test';
import assert from 'node:assert/strict';
import { PlatformClient, PlatformError, PendingOperation, agreementPayload, excessPreview, query } from '../../resources/js/platform/client.js';

const storage = () => { const values = new Map(); return { getItem: k => values.get(k) || null, setItem: (k, v) => values.set(k, v), removeItem: k => values.delete(k), values }; };
const response = (data, status = 200, code = '') => new Response(JSON.stringify(status < 400 ? { success: true, data } : { success: false, error: { code } }), { status, headers: { 'Content-Type': 'application/json' } });
const key = '12345678-1234-4123-8123-123456789abc';

test('dedicated client omits institutional cookies, referrers and storage; only sends explicit memory Bearer', async () => {
    const requests = [];
    const client = new PlatformClient(async (url, options) => { requests.push({ url, options }); return response({}); });
    await client.request('auth/login', { method: 'POST', body: { email: 'synthetic@example.test', password: 'synthetic' } });
    assert.equal(requests[0].options.headers.Authorization, undefined);
    client.token = 'synthetic-platform';
    await client.request('institutions');
    assert.equal(requests[1].options.headers.Authorization, 'Bearer synthetic-platform');
    for (const { options } of requests) {
        assert.equal(options.credentials, 'omit'); assert.equal(options.referrerPolicy, 'no-referrer'); assert.equal(options.redirect, 'error'); assert.equal(options.cache, 'no-store');
    }
    await assert.rejects(() => client.request('https://external.test'), PlatformError);
    await assert.rejects(() => client.request('../institution/users'), PlatformError);
    await client.request('institutions?' + query({ q: 'Institución de prueba..', page: 2 }));
    assert.match(requests[2].url, /q=Instituci%C3%B3n\+de\+prueba/);
});

test('expiry clears Bearer; stale in-flight responses cannot restore a cleared session', async () => {
    let expired = false;
    const client = new PlatformClient(async () => response(null, 401, 'AUTH_UNAUTHENTICATED'), () => { expired = true; });
    client.token = 'synthetic';
    await assert.rejects(() => client.request('auth/profile'), e => e.status === 401);
    assert.equal(client.token, null); assert.equal(expired, true);
    let resolve;
    const late = new PlatformClient(() => new Promise(r => { resolve = r; }));
    late.token = 'synthetic';
    const pending = late.request('institutions'); late.clear(); resolve(response({ items: ['private'] }));
    await assert.rejects(pending, e => e.code === 'SESSION_CHANGED');
});

test('lost response retains frozen payload and key across reload; owner lookup recovers committed failed publication', async () => {
    const tab = storage();
    const draft = new PendingOperation(tab);
    const payload = { name: 'Synthetic admin', email: 'admin@example.test', reason: 'Synthetic fixture' };
    draft.prepare('owner', 'administrator_invitation', `institutions/${key}/administrator-invitations`, payload, () => key);
    payload.name = 'Changed after review';
    const calls = [];
    const lost = new PlatformClient(async (url, options) => { calls.push({ url, options }); throw new Error('Lost response'); });
    await assert.rejects(() => draft.submit(lost), e => e.uncertain);
    assert.equal(draft.value.status, 'uncertain');
    assert.throws(() => draft.dismiss(), e => e.code === 'PENDING_OPERATION_EXISTS');
    const recovered = new PendingOperation(tab);
    assert.equal(recovered.value.key, key); assert.equal(recovered.value.body.name, 'Synthetic admin');
    const result = { institution_id: key, administrator_id: key, publication_status: 'failed', activation_status: 'pending_activation' };
    const lookup = new PlatformClient(async (url, options) => { calls.push({ url, options }); return response({ result }); });
    await recovered.reconcile(lookup);
    assert.equal(recovered.value.status, 'committed'); assert.equal(recovered.value.result.publication_status, 'failed');
    assert.match(calls[1].url, /operations\/.*operation_type=administrator_invitation/);
    assert.equal(calls[1].options.method, 'GET');
    recovered.dismiss(); assert.equal(tab.values.size, 0);
});

test('404 lookup does not dismiss uncertainty; retry sends identical request and key', async () => {
    const draft = new PendingOperation(storage());
    draft.prepare('owner', 'institution_onboarding', 'institutions', { institution: { name: 'Synthetic' } }, () => key);
    await assert.rejects(() => draft.submit(new PlatformClient(async () => { throw Error(); })));
    await assert.rejects(() => draft.reconcile(new PlatformClient(async () => response(null, 404, 'RESOURCE_NOT_FOUND'))));
    assert.equal(draft.value.status, 'uncertain');
    let sent;
    await draft.submit(new PlatformClient(async (url, options) => { sent = options; return response({ institution_id: key, publication_status: 'unknown' }, 201); }));
    assert.equal(sent.headers['Idempotency-Key'], key);
    assert.deepEqual(JSON.parse(sent.body), { institution: { name: 'Synthetic' } });
});

test('conflicting key is retained, whereas definitive validation can be corrected in a new reviewed draft', async () => {
    const draft = new PendingOperation(storage());
    draft.prepare('owner', 'institution_onboarding', 'institutions', {}, () => key);
    await assert.rejects(() => draft.submit(new PlatformClient(async () => response(null, 409, 'IDEMPOTENCY_CONFLICT'))));
    assert.equal(draft.value.status, 'uncertain');
    await assert.rejects(() => draft.submit(new PlatformClient(async () => response(null, 422, 'VALIDATION_ERROR'))));
    assert.equal(draft.value.status, 'rejected'); draft.dismiss();
    assert.equal(draft.value, null);
});

test('storage failure prevents submission before a key can be lost', () => {
    const draft = new PendingOperation({ getItem: () => null, setItem: () => { throw Error(); } });
    assert.throws(() => draft.prepare('owner', 'institution_onboarding', 'institutions', {}, () => key), e => e.code === 'DRAFT_STORAGE_UNAVAILABLE');
    assert.equal(draft.value, null);
});

test('auth and throttling responses during replay cannot erase an earlier uncertain outcome', async () => {
    const draft = new PendingOperation(storage());
    draft.prepare('owner', 'administrator_invitation', `institutions/${key}/administrator-invitations`, { email: 'fixture@example.test' }, () => key);
    await assert.rejects(() => draft.submit(new PlatformClient(async () => { throw Error(); })));
    for (const status of [401, 403, 429]) {
        await assert.rejects(() => draft.submit(new PlatformClient(async () => response(null, status, 'REQUEST_FAILED'))));
        assert.equal(draft.value.status, 'uncertain'); assert.equal(draft.value.key, key);
        assert.throws(() => draft.dismiss());
    }
});

test('agreements preserve CLP precision, zero/unlimited, explicit exceptions and expected revisions', () => {
    const source = { id: key, revision: 4, limits: { admin: 2, editor: 5, reader: null } };
    const form = { limits: { admin: '0', editor: '', reader: '7' }, limit_reasons: { admin: 'Paused', editor: 'Negotiated', reader: 'Agreed' },
        price_clp: '999999999999999999', agreement_kind: 'paid', lifecycle: 'agreed', tax_treatment: 'inclusive', periodicity: 'monthly',
        starts_on: '2026-10-15', ends_on: '', has_trial: false, reason: 'Synthetic', extra_exceptions: [] };
    const body = agreementPayload(form, source, 3);
    assert.equal(body.price_clp, form.price_clp); assert.deepEqual(body.limits, { admin: 0, editor: null, reader: 7 });
    assert.equal(body.expected_revision, 3); assert.equal(body.expected_plan_revision, 4); assert.equal(body.exceptions.length, 3);
    assert.equal(body.exceptions[2].base_value, null);
    assert.equal(excessPreview(body.limits, { admin: { used: 2 } })[0].over_by, 2);
    delete form.limit_reasons.admin;
    assert.throws(() => agreementPayload(form, source), e => e.code === 'EXCEPTION_REASON_REQUIRED');
});
