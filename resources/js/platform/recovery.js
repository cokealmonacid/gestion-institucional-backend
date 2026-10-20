import { PendingOperation, PlatformError, query } from './client.js';

const form = () => ({ successor_id: '', successor_name: '', successor_email: '', new_email: '', same_person: false,
    reason: '', confirmation: false, authority: { requester: '', relationship: '', channel: '', reference: '' } });
const clone = value => JSON.parse(JSON.stringify(value));
const intent = value => JSON.stringify(normalize(value));
function normalize(value) {
    if (typeof value === 'string') return value.trim();
    if (!value || typeof value !== 'object') return value;
    return Object.fromEntries(Object.entries(value).sort(([a], [b]) => a.localeCompare(b)).map(([key, item]) => [key,
        key.endsWith('email') && typeof item === 'string' ? item.trim().toLowerCase() : normalize(item)]));
}

export function recoveryPanel() {
    return {
        recoveryStore: null, recoveryTarget: null, recoveryKind: 'replacement', recoveryForm: form(), recoveryCandidates: [],
        recoveryOperation: null, recoveryRecords: { items: [], page: 1, last_page: 1 }, recoveryLookup: '', recoveryReason: '', recoveryUnknown: false,
        initRecovery() { this.recoveryStore = new PendingOperation(globalThis.sessionStorage, 'acervo.platform.recovery.v1'); },
        clearRecovery() { this.recoveryTarget = null; this.recoveryForm = form(); this.recoveryCandidates = []; this.recoveryOperation = null; this.recoveryRecords = { items: [], page: 1, last_page: 1 }; this.recoveryLookup = ''; this.recoveryReason = ''; this.recoveryUnknown = false; },
        recoveryLabel(value) { return ({ replacement: 'Reemplazar administrador', containment: 'Desactivar y revocar acceso', email_correction: 'Corregir correo de la misma persona',
            awaiting_verification: 'Esperando verificación del correo', verified: 'Correo verificado; falta completar', completed: 'Completada', cancelled: 'Cancelada', expired: 'Expirada', not_required: 'No requiere correo' })[value] || this.label(value); },
        recoveryQuotaPreview() {
            const op = this.recoveryOperation;
            if (!op) return [];
            const s = op.snapshot;
            return ['admin', 'editor', 'reader'].map(role => {
                const before = s.quota_before.roles[role].used, limit = s.quota_before.roles[role].limit;
                let after = before;
                if (op.kind !== 'email_correction' && s.target_active && s.target_roles.includes(role)) after--;
                if (op.kind === 'replacement') {
                    if (s.successor_active && s.successor_roles.includes(role)) after--;
                    if (role === 'admin') after++;
                }
                return { role, before, after, limit, excess: limit == null ? 0 : Math.max(0, after - limit),
                    blocked: limit != null && Math.max(0, after - limit) > Math.max(0, before - limit) };
            });
        },
        async loadRecoveries(page = 1) { this.recoveryRecords = await this.client.request(`access-recoveries?${query({ page })}`); this.screen = 'recoveries'; },
        async openRecovery(user, kind) {
            if (this.recoveryUnknown && this.recoveryOperation) { this.screen = 'recovery-operation'; this.notice = 'Consulta primero el resultado de la transición pendiente. No prepares una copia con otra clave.'; return; }
            if (this.recoveryStore?.value) { this.screen = 'recoveries'; this.notice = 'Resuelve la preparación conservada antes de iniciar otra.'; return; }
            await this.run(async () => {
                const institutionId = user.institution_id || this.detail.id;
                const row = await this.client.request(`users?${query({ user_id: user.user_id, institution_id: institutionId, per_page: 1 })}`);
                if (!row.items[0]) throw new PlatformError('RESOURCE_NOT_FOUND', 404);
                this.recoveryTarget = row.items[0]; this.recoveryKind = kind; this.recoveryForm = form(); this.recoveryOperation = null;
                await this.fetchDetail(institutionId);
                const candidates = []; let page = 1; let current;
                do { current = await this.client.request(`users?${query({ institution_id: institutionId, per_page: 100, page: page++ })}`); candidates.push(...current.items); } while (current.page < current.last_page);
                this.recoveryCandidates = candidates.filter(candidate => candidate.user_id !== user.user_id && candidate.email_verified);
                this.screen = 'recovery-edit';
            });
        },
        reviewRecovery() {
            const f = this.recoveryForm;
            const body = { kind: this.recoveryKind, institution_id: this.detail.id, target_id: this.recoveryTarget.user_id,
                expected_target_revision: this.recoveryTarget.access_revision, expected_institution_revision: this.detail.operational_revision,
                authority: clone(f.authority), reason: f.reason, confirmation: f.confirmation };
            if (this.recoveryKind === 'replacement') Object.assign(body, f.successor_id ? { successor_id: f.successor_id } : { successor_name: f.successor_name, successor_email: f.successor_email });
            if (this.recoveryKind === 'email_correction') Object.assign(body, { new_email: f.new_email, same_person: f.same_person });
            const successor = this.recoveryCandidates.find(user => user.user_id === f.successor_id);
            this.confirm('recovery-prepare', body, this.recoveryLabel(this.recoveryKind), [
                ['Institución', this.detail.name], ['Cuenta actual', `${this.recoveryTarget.name} · ${this.recoveryTarget.email}`],
                ['Sucesor / correo propuesto', successor ? `${successor.name} · ${successor.email}` : f.successor_email || f.new_email || 'No cambia la identidad'],
                ['Efecto', this.recoveryKind === 'containment' ? 'Se desactiva la cuenta y se revocan sus accesos. La institución puede quedar temporalmente sin administrador habilitado; debe iniciar recuperación.' : 'Solo se prepara y verifica. El cambio requiere una confirmación posterior; no se inicia sesión.'],
                ['Cupos', 'El reemplazo no puede aumentar el exceso en ningún rol. Preparar no reserva un cupo activo.'],
                ['Suspensión', this.detail.status ? 'La institución permanece activa.' : 'La institución permanece suspendida, incluso al completar.'],
                ['Autoridad', `${f.authority.requester} · ${f.authority.relationship} · ${f.authority.channel} · ${f.authority.reference}`], ['Motivo', f.reason],
            ], 'access-recoveries');
        },
        async submitRecovery(body) {
            this.recoveryStore.prepare(this.operator.id, 'access_recovery', 'access-recoveries', body);
            try { this.recoveryOperation = await this.recoveryStore.submit(this.client); this.recoveryStore.dismiss(); this.recoveryUnknown = false; this.screen = 'recovery-operation'; }
            catch (error) { this.screen = 'recoveries'; this.recoveryUnknown = true; throw error; }
        },
        async resumeRecovery(id = this.recoveryLookup) {
            await this.run(async () => {
                if (!/^[a-f0-9-]{36}$/i.test(id)) throw new PlatformError('CLIENT_INVALID_PATH');
                this.recoveryOperation = await this.client.request(`access-recoveries/${id}`);
                if (this.recoveryStore.value?.key === id && intent(this.recoveryStore.value.body) === intent(this.recoveryOperation.request)) { this.recoveryStore.value.status = 'committed'; this.recoveryStore.dismiss(); }
                this.recoveryUnknown = false; this.recoveryReason = ''; this.screen = 'recovery-operation';
            });
        },
        async retryRecoveryPreparation() {
            await this.run(async () => {
                if (this.recoveryStore.value?.owner !== this.operator.id) throw new PlatformError('PLATFORM_ACCESS_DENIED', 403);
                this.recoveryOperation = await this.recoveryStore.submit(this.client);
                this.recoveryStore.dismiss(); this.recoveryUnknown = false; this.screen = 'recovery-operation';
            });
        },
        discardRejectedRecovery() { try { this.recoveryStore.dismiss(); this.recoveryUnknown = false; if (this.recoveryTarget && this.detail) this.screen = 'recovery-edit'; } catch (error) { this.explain(error); } },
        reviewRecoveryTransition(action) {
            const op = this.recoveryOperation;
            this.confirm('recovery-transition', { expected_revision: op.revision, confirmation: true, ...(this.recoveryReason ? { reason: this.recoveryReason } : {}) },
                action === 'complete' ? 'Completar recuperación' : action === 'cancel' ? 'Cancelar recuperación' : 'Reenviar verificación', [
                    ['Operación', op.id], ['Acción', this.recoveryLabel(op.kind)], ['Cuenta', `${op.snapshot.target_name} · ${op.snapshot.target_email}`],
                    ['Nuevo correo / sucesor', op.snapshot.successor_email || 'No cambia'],
                    ['Efecto', action === 'complete' ? (op.kind === 'replacement' ? 'El sucesor obtiene rol administrador; el saliente se desactiva. Se revocan credenciales; se conservan roles e historial del saliente y todas las responsabilidades.' : 'Cambia el correo de la misma persona y se revocan accesos. Se conservan contraseña, actividad, roles e institución.') : action === 'cancel' ? 'La prueba deja de ser válida; no se modifica la cuenta.' : 'Invalida la prueba anterior y exige verificar otra vez; no amplía el plazo.'],
                    ['Suspensión', 'No se modifica el estado operativo de la institución.'], ['Autoridad registrada', `${op.request.authority.requester} · ${op.request.authority.reference}`], ['Motivo', this.recoveryReason || op.request.reason],
                ], `access-recoveries/${op.id}/${action}`);
        },
        async performRecoveryTransition(path, body) {
            try { this.recoveryOperation = await this.client.request(path, { method: 'POST', body }); this.recoveryUnknown = false; }
            catch (error) { this.recoveryUnknown = true; throw error; }
        },
        async verifyRecoveryAddress() {
            await this.run(async () => {
                try {
                    await this.client.request('auth/access-recovery-verification', { method: 'POST', body: { operation_id: this.link.operation, proof: this.link.token,
                        ...(this.link.setup ? { password: this.auth.password, password_confirmation: this.auth.password_confirmation } : {}) } });
                    this.notice = 'Correo verificado. No se habilitó acceso ni se inició sesión. El operador debe completar la recuperación.';
                    this.phase = 'recovery-verified'; this.link = null;
                } finally { this.clearSecrets(); }
            });
        },
    };
}
