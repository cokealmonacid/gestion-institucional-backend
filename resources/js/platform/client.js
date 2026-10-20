const ROOT = '/api/v1/platform/';
const DRAFT_KEY = 'acervo.platform.pending.v1';

export class PlatformError extends Error {
    constructor(code, status = 0, fields = [], retryAfter = null) {
        super(code);
        this.code = code;
        this.status = status;
        this.fields = fields;
        this.retryAfter = retryAfter;
    }
    get uncertain() { return this.status === 0 || this.status >= 500 || this.status === 408; }
}

// Separate from the institutional Axios client. Neither cookies nor its credentials participate.
export class PlatformClient {
    constructor(transport = globalThis.fetch.bind(globalThis), expired = () => {}) {
        this.transport = transport;
        this.expired = expired;
        this.token = null;
        this.generation = 0;
    }
    clear() { this.token = null; this.generation++; }
    async request(path, { method = 'GET', body, key } = {}) {
        if (!/^[a-z0-9/?=&_%.,+\-]+$/i.test(path) || path.startsWith('/') || path.split('?')[0].split('/').includes('..')) {
            throw new PlatformError('CLIENT_INVALID_PATH');
        }
        const headers = { Accept: 'application/json' };
        const authenticated = Boolean(this.token);
        const generation = this.generation;
        if (authenticated) headers.Authorization = `Bearer ${this.token}`;
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        if (key) headers['Idempotency-Key'] = key;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 30000);
        try {
            const response = await this.transport(ROOT + path, {
                method, headers, body: body === undefined ? undefined : JSON.stringify(body),
                credentials: 'omit', cache: 'no-store', referrerPolicy: 'no-referrer', redirect: 'error', signal: controller.signal,
            });
            let result;
            try { result = await response.json(); } catch { throw new PlatformError('NETWORK_UNCERTAIN', 0); }
            if (generation !== this.generation) throw new PlatformError('SESSION_CHANGED', 401);
            if (!response.ok) {
                if (authenticated && response.status === 401) { this.clear(); this.expired(); }
                throw new PlatformError(result.error?.code || 'REQUEST_FAILED', response.status,
                    Object.keys(result.error?.fields || result.error?.details || {}), response.headers.get('Retry-After'));
            }
            if (result.success !== true) throw new PlatformError('NETWORK_UNCERTAIN');
            return result.data;
        } catch (error) {
            if (error instanceof PlatformError) throw error;
            throw new PlatformError('NETWORK_UNCERTAIN');
        } finally { clearTimeout(timeout); }
    }
}

export function query(values) {
    return new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '' && value != null)).toString();
}

// Only a safe operation draft is retained in this tab. No auth, passwords, links or MFA material.
export class PendingOperation {
    constructor(storage = globalThis.sessionStorage, storageKey = DRAFT_KEY) {
        this.storage = storage;
        this.storageKey = storageKey;
        this.value = null;
        try {
            const saved = JSON.parse(storage.getItem(this.storageKey));
            if (saved && ['institution_onboarding', 'administrator_invitation', 'access_recovery'].includes(saved.type)
                && /^[a-f0-9-]{36}$/i.test(saved.key) && typeof saved.owner === 'string'
                && typeof saved.body === 'object' && saved.body && typeof saved.path === 'string'
                && /^(?:institutions(?:\/[a-f0-9-]{36}\/administrator-invitations)?|access-recoveries)$/i.test(saved.path)) {
                this.value = saved;
            }
        } catch { /* Storage can be unavailable; the in-memory draft still works. */ }
    }
    save() {
        // Refuse a mutation if the tab cannot durably retain its reconciliation key.
        this.storage.setItem(this.storageKey, JSON.stringify(this.value));
    }
    prepare(owner, type, path, body, uuid = () => globalThis.crypto.randomUUID()) {
        if (this.value) throw new PlatformError('PENDING_OPERATION_EXISTS');
        this.value = { owner, type, path, body: JSON.parse(JSON.stringify(body)), key: uuid(), status: 'ready', result: null };
        try { this.save(); } catch { this.value = null; throw new PlatformError('DRAFT_STORAGE_UNAVAILABLE'); }
        return this.value;
    }
    async submit(client) {
        const draft = this.value;
        if (!draft) throw new PlatformError('NO_PENDING_OPERATION');
        draft.status = 'uncertain';
        this.save();
        try {
            const result = await client.request(draft.path, { method: 'POST', body: draft.body, key: draft.key });
            draft.result = result;
            draft.status = 'committed';
            this.save();
            return result;
        } catch (error) {
            if (error instanceof PlatformError && !error.uncertain && ![401, 403, 429].includes(error.status)
                && !['IDEMPOTENCY_CONFLICT', 'SESSION_CHANGED'].includes(error.code)) {
                draft.status = 'rejected';
                draft.errorCode = error.code;
            }
            this.save();
            throw error;
        }
    }
    async reconcile(client) {
        const draft = this.value;
        const operation = await client.request(draft.type === 'access_recovery' ? `access-recoveries/${encodeURIComponent(draft.key)}` : `operations/${encodeURIComponent(draft.key)}?operation_type=${draft.type}`);
        draft.result = draft.type === 'access_recovery' ? operation : operation.result;
        draft.status = 'committed';
        this.save();
        return draft.result;
    }
    dismiss() {
        if (this.value && !['rejected', 'committed', 'ready'].includes(this.value.status)) throw new PlatformError('PENDING_OPERATION_EXISTS');
        this.storage.removeItem(this.storageKey);
        this.value = null;
    }
}

export function limits(values) {
    return Object.fromEntries(['admin', 'editor', 'reader'].map(role => [role, values[role] === '' || values[role] == null ? null : Number(values[role])]));
}

export function agreementPayload(form, source, revision = null) {
    if (!source) throw new PlatformError('PLAN_REQUIRED');
    const agreed = limits(form.limits);
    const exceptions = [];
    for (const role of ['admin', 'editor', 'reader']) {
        if (agreed[role] !== source.limits[role]) {
            if (!form.limit_reasons[role]?.trim()) throw new PlatformError('EXCEPTION_REASON_REQUIRED');
            exceptions.push({ condition: `limits.${role}`, base_value: source.limits[role], agreed_value: agreed[role], reason: form.limit_reasons[role].trim() });
        }
    }
    // Non-quota exceptions are explicit negotiated annotations. The catalog has no base prices/dates.
    for (const item of form.extra_exceptions || []) {
        exceptions.push({ condition: item.condition, base_value: null, agreed_value: item.condition === 'ends_on' ? (form.ends_on || null) : form[item.condition], reason: item.reason });
    }
    const payload = {
        plan_id: source.id, expected_plan_revision: source.revision,
        agreement_kind: form.agreement_kind, lifecycle: form.lifecycle, limits: agreed,
        price_clp: form.price_clp, currency: 'CLP', tax_treatment: form.tax_treatment,
        periodicity: form.periodicity, starts_on: form.starts_on, ends_on: form.ends_on || null,
        trial: form.has_trial ? { type: form.trial_type, starts_on: form.trial_start, ends_on: form.trial_end } : null,
        exceptions, reason: form.reason,
    };
    if (revision !== null) payload.expected_revision = revision;
    return payload;
}

export function excessPreview(agreed, roles = {}) {
    return ['admin', 'editor', 'reader'].map(role => ({ role, used: roles[role]?.used || 0, limit: agreed[role],
        over_by: agreed[role] === null ? 0 : Math.max(0, (roles[role]?.used || 0) - agreed[role]) }));
}
