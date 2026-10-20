import Alpine from 'alpinejs';
import '../css/platform.css';
import { PlatformClient, PlatformError, PendingOperation, query, limits, agreementPayload, excessPreview } from './platform/client';
import { recoveryPanel } from './platform/recovery';
import { rolePanel } from './platform/roles';

const copy = value => JSON.parse(JSON.stringify(value));
const blankCustomer = () => ({ type: '', name: '', rut: '' });
const blankAgreement = () => ({ plan_id: '', agreement_kind: 'paid', lifecycle: 'agreed', price_clp: '0', tax_treatment: 'inclusive', periodicity: 'monthly', starts_on: new Date().toLocaleDateString('en-CA', { timeZone: 'America/Santiago' }), ends_on: '', has_trial: false, trial_type: 'trial', trial_start: '', trial_end: '', limits: { admin: '', editor: '', reader: '' }, limit_reasons: { admin: '', editor: '', reader: '' }, extra_exceptions: [], reason: '' });
const emptyPage = () => ({ items: [], page: 1, last_page: 1, total: 0 });
const messages = {
    ROLE_CHANGE_IMPACT_CONFIRMATION_REQUIRED: 'Revisa el impacto en responsabilidades y confirma su eliminación.',
    ROLE_CHANGE_IMPACT_STALE: 'El impacto cambió. Revisa nuevamente antes de confirmar.',
    INSTITUTIONAL_ROLE_CONFLICT: 'Una asignación debe dejar un único rol institucional. Revisa los roles actuales.',
    RECOVERY_STALE: 'Cambió la cuenta, el convenio o el estado institucional. No se aplicó el cambio. Consulta la operación; cancela esta preparación y revisa una nueva con los datos actuales.',
    RECOVERY_EXPIRED: 'La recuperación expiró. Consulta su estado y cancélala antes de preparar otra; no se modificó la cuenta.',
    RECOVERY_STATE_CONFLICT: 'Esta operación ya no permite esa acción. Actualiza la información.',
    RECOVERY_VERIFICATION_REQUIRED: 'Falta verificar el correo con el enlace enviado. La aceptación del transporte no constituye verificación.',
    RECOVERY_PROOF_INVALID: 'La verificación es inválida, expiró, fue cancelada o ya se utilizó. Solicita al operador que consulte la recuperación.',
    RECOVERY_SUCCESSOR_NOT_READY: 'El sucesor existente debe ser otra persona de la misma institución, con correo verificado y contraseña ya establecida. Su contraseña no se cambiará.',
    RECOVERY_TARGET_NOT_ADMIN: 'La cuenta saliente ya no tiene rol administrador. Actualiza sus datos antes de preparar un reemplazo.',
    RECOVERY_EXISTING_CREDENTIALS_UNCHANGED: 'Esta verificación no permite cambiar una contraseña existente. Abre el enlace correcto.',
    AUTH_INVALID_CREDENTIALS: 'Correo o contraseña incorrectos.', AUTH_UNAUTHENTICATED: 'La sesión expiró. Ingresa nuevamente y completa MFA.',
    PLATFORM_ACCESS_DENIED: 'No tienes permiso o tu acceso fue revocado. Vuelve a ingresar si corresponde.',
    PLATFORM_OPERATOR_INACTIVE: 'La cuenta de operador no está habilitada. Contacta al responsable de operaciones.',
    PLATFORM_LINK_INVALID: 'El enlace es inválido, expiró o ya fue utilizado. Solicita uno nuevo al responsable de operaciones o recupera tu contraseña.',
    MFA_CHALLENGE_INVALID: 'La verificación no fue aceptada o expiró. Revisa el código; si persiste, inicia sesión de nuevo. No reutilices un código TOTP ya aceptado.',
    CURRENT_PASSWORD_INVALID: 'La contraseña actual no es correcta.', VALIDATION_ERROR: 'Revisa los campos indicados y sus formatos.',
    RESOURCE_REVISION_CONFLICT: 'Otra operación modificó este registro. Conservamos tu formulario. Recarga la versión actual y revisa los cambios antes de confirmar otra vez.',
    PLAN_REVISION_CONFLICT: 'El plan cambió. Recarga los planes, selecciona nuevamente y revisa las condiciones.',
    PLAN_NOT_AVAILABLE: 'Este plan ya no está disponible. Selecciona otro plan.', USER_QUOTA_EXCEEDED: 'No hay cupo para esta operación. Revisa el uso y los límites del convenio.',
    INSTITUTION_SUSPENDED: 'La institución está suspendida. Reactívala antes de gestionar accesos.',
    INSTITUTION_REQUIRES_USABLE_ADMIN: 'La institución necesita conservar al menos un administrador utilizable.',
    EMAIL_UNAVAILABLE: 'Ese correo no está disponible para crear esta cuenta.', INVITATION_NOT_AVAILABLE: 'Esta cuenta no admite reenvío. Revisa su estado de activación y actividad.',
    INVITATION_RESEND_THROTTLED: 'El reenvío está temporalmente limitado. Espera antes de volver a intentarlo.', AUTH_RATE_LIMITED: 'Demasiados intentos. Espera antes de continuar.',
    IDEMPOTENCY_CONFLICT: 'La clave tiene una operación diferente. Consulta el resultado; no generes otra clave para repetir la creación.',
    PENDING_OPERATION_EXISTS: 'Resuelve o cierra la operación pendiente antes de iniciar otra creación.',
    DRAFT_STORAGE_UNAVAILABLE: 'El navegador no permite conservar la operación en esta pestaña. Habilita el almacenamiento de sesión antes de crear recursos.',
    NETWORK_UNCERTAIN: 'No se pudo confirmar el resultado. Una mutación puede haberse completado. Consulta el estado antes de repetirla.',
    PLATFORM_MUTATION_FAILED: 'El servidor no pudo confirmar la operación. Consulta el estado antes de repetirla.',
    RESOURCE_NOT_FOUND: 'El recurso no existe o no está disponible en este ámbito.',
    EXCEPTION_REASON_REQUIRED: 'Explica cada límite negociado que difiera del plan.', PLAN_REQUIRED: 'Selecciona un plan disponible.',
    SESSION_CHANGED: 'La sesión cambió durante la solicitud. Ingresa nuevamente para consultar el resultado.',
};

Alpine.data('platformPanel', () => ({
    ...recoveryPanel(),
    ...rolePanel(),
    client: null, pendingStore: null, pending: null, phase: 'login', screen: 'institutions', busy: false, loading: false,
    error: '', notice: '', errorFields: [], retryAt: 0, now: Date.now(), timer: null, expiresAt: null, operator: null,
    auth: { email: '', password: '', password_confirmation: '', current_password: '', code: '', recovery_code: '' },
    link: null, challenge: null, enrollment: null, codes: [], codesSaved: false, useRecovery: false,
    institutions: emptyPage(), plans: emptyPage(), planOptions: [], admins: emptyPage(), admin: null, detail: null,
    users: emptyPage(), userFilters: { q: '', institution_id: '', role: '', active: '' }, userInstitutionOptions: [], userAccess: null,
    invitationEmailError: '', invitationResult: null,
    filters: { q: '', status: '', commercial_state: '' }, planFilters: { q: '', available: '' }, adminQuery: '',
    audit: { items: [], next_cursor: null }, auditFilters: { action: '', subject_kind: '', subject_id: '' }, auditInstitution: null,
    history: { items: [], next_cursor: null }, metadata: { name: '', customer: blankCustomer(), reason: '', expected_revision: 0 },
    planForm: { id: null, code: '', name: '', description: '', available_for_new_contracts: true, limits: { admin: '', editor: '', reader: '' }, reason: '', expected_revision: 1 },
    creation: { name: '', customer: blankCustomer(), admin_name: '', admin_email: '' }, agreement: blankAgreement(),
    invitation: { name: '', email: '', reason: '' }, actionReason: '', review: null, reviewRows: [], reviewExcess: [],
    uncertain: false, roles: ['admin', 'editor', 'reader'],
    init() {
        this.client = new PlatformClient(globalThis.fetch.bind(globalThis), () => this.expire());
        this.initRecovery();
        this.pendingStore = new PendingOperation();
        this.link = window.__platformLink || null;
        delete window.__platformLink;
        if (location.pathname === '/platform/access-verification') this.phase = this.link?.operation && this.link?.token ? 'recovery-verification' : 'invalid-link';
        if (['/platform/activate', '/platform/password-reset'].includes(location.pathname)) {
            this.phase = this.link?.token && this.link?.email ? (location.pathname.endsWith('/activate') ? 'activation' : 'reset') : 'invalid-link';
            this.auth.email = this.link?.email || '';
        }
        this.timer = setInterval(() => {
            this.now = Date.now();
            if (this.expiresAt && this.now >= Date.parse(this.expiresAt)) this.expire();
            if (this.challenge && this.now >= Date.parse(this.challenge.expires_at)) {
                this.challenge = null; this.enrollment = null; this.phase = 'login'; this.error = 'La verificación MFA expiró. Inicia sesión otra vez.';
            }
        }, 1000);
        window.addEventListener('pagehide', () => this.clearSession());
        window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
    },
    destroy() { clearInterval(this.timer); this.clearSession(); },
    label(value) {
        return ({ admin: 'Administradores', editor: 'Editores', reader: 'Lectores', configured: 'Configurada', legacy_unconfigured: 'Sin convenio configurado',
            natural_person: 'Persona natural', legal_entity: 'Persona jurídica', paid: 'Pagado', free_trial: 'Prueba gratuita', agreed: 'Acordado', terminated: 'Terminado',
            inclusive: 'Impuestos incluidos', exclusive: 'Impuestos excluidos', monthly: 'Mensual', annual: 'Anual', one_time_pilot: 'Piloto, pago único',
            pending_activation: 'Pendiente de activación', activated: 'Activación confirmada', queued: 'Publicación aceptada por la cola',
            pending: 'Pendiente', accepted: 'Aceptado por el transporte (no confirma entrega)', failed: 'Falló', unknown: 'Desconocido',
            current: 'Vigente por fecha', expired: 'Vencido', not_started: 'Aún no inicia', trial: 'Prueba', pilot: 'Piloto',
            ready: 'Preparada', uncertain: 'Resultado incierto', committed: 'Recursos confirmados', rejected: 'Solicitud rechazada',
            NO_USABLE_ADMIN: 'No hay administrador utilizable. Revisa los accesos, invita o reactiva un administrador elegible.',
            'institution.onboarded': 'Institución creada', 'institution.metadata_updated': 'Metadatos institucionales editados',
            'institution.suspended': 'Institución suspendida', 'institution.reactivated': 'Institución reactivada',
            'administrator.invited': 'Administrador invitado', 'administrator.reactivated': 'Administrador reactivado',
            'administrator.invitation_reissued': 'Invitación de administrador reemitida', 'invitation.publication_recorded': 'Estado de publicación registrado',
            'plan.created': 'Plan creado', 'plan.updated': 'Plan editado', 'contract.revised': 'Convenio revisado',
            'auth.login': 'Inicio de sesión', 'auth.logout': 'Cierre de sesión', 'auth.challenge_issued': 'Verificación MFA solicitada',
            'auth.enrollment_started': 'Inscripción MFA iniciada', 'auth.mfa_enrolled': 'MFA configurado', 'auth.recovery_used': 'Código de recuperación utilizado',
            'auth.codes_regenerated': 'Códigos de recuperación regenerados', 'auth.password_changed': 'Contraseña cambiada',
            'auth.password_reset': 'Contraseña restablecida', 'auth.activated': 'Operador activado', 'auth.recovery_requested': 'Recuperación solicitada',
            'access_recovery.prepared': 'Recuperación preparada', 'access_recovery.address_verified': 'Control del correo verificado',
            'access_recovery.complete': 'Recuperación completada', 'access_recovery.cancel': 'Recuperación cancelada',
            'access_recovery.resend': 'Verificación reenviada', 'access_recovery.contained': 'Acceso individual contenido',
            'limits.admin': 'Cupos de administradores', 'limits.editor': 'Cupos de editores', 'limits.reader': 'Cupos de lectores',
            price_clp: 'Precio CLP', periodicity: 'Periodicidad', starts_on: 'Inicio del convenio', ends_on: 'Término del convenio', tax_treatment: 'Impuestos',
        })[value] || value || 'Sin información';
    },
    quota(value) { return value == null || value === '' ? 'Sin límite' : String(value); },
    date(value) { return value ? new Date(value).toLocaleString('es-CL', { timeZone: 'America/Santiago' }) : 'Sin información'; },
    json(value) { return JSON.stringify(value, null, 2); },
    get waiting() { return this.busy || this.loading || this.now < this.retryAt; },
    resetFeedback() { this.error = ''; this.errorFields = []; this.notice = ''; },
    explain(error) {
        this.error = messages[error.code] || (error.status === 403 ? messages.PLATFORM_ACCESS_DENIED : error.status === 404 ? messages.RESOURCE_NOT_FOUND : 'No se pudo completar la solicitud. Revisa el estado e inténtalo de nuevo cuando corresponda.');
        this.errorFields = (error.fields || []).map(field => ({
            name: 'Nombre', email: 'Correo', password: 'Contraseña (8 a 72 bytes)', password_confirmation: 'Confirmación de contraseña', current_password: 'Contraseña actual',
            customer: 'Datos del cliente y RUT', limits: 'Límites por rol', trial: 'Fechas de prueba o piloto', exceptions: 'Excepciones negociadas',
            institution: 'Institución', initial_admin: 'Administrador inicial', contract: 'Convenio', reason: 'Motivo', starts_on: 'Fecha de inicio', ends_on: 'Fecha de término',
            price_clp: 'Precio CLP entero (máximo 18 dígitos)', code: 'Código', description: 'Descripción', plan_id: 'Plan',
        })[field.split('.')[0]] || 'Revisa las condiciones del formulario');
        if (error.status === 429) {
            const seconds = Number(error.retryAfter);
            this.retryAt = Date.now() + (Number.isFinite(seconds) && seconds > 0 ? seconds : 60) * 1000;
        }
        this.$nextTick(() => this.$refs.feedback?.focus());
    },
    async run(task) {
        if (this.waiting) return;
        this.busy = true; this.resetFeedback();
        try { await task(); } catch (error) { this.explain(error); } finally { this.busy = false; }
    },
    clearSecrets() {
        this.auth.password = ''; this.auth.password_confirmation = ''; this.auth.current_password = ''; this.auth.code = ''; this.auth.recovery_code = '';
    },
    clearSession() {
        if (!this.roleUnknown) this.roleUser = null;
        else if (this.roleUser) this.roleUser = { user_id: this.roleUser.user_id, institution_id: this.roleUser.institution_id };
        this.roleReason = ''; this.roleDestination = ''; this.roleImpact = null; this.roleConfirmation = false; this.roleResult = '';
        this.clearRecovery();
        this.client?.clear(); this.operator = null; this.expiresAt = null; this.challenge = null; this.enrollment = null; this.codes = []; this.link = null; this.clearSecrets();
        this.auth.email = ''; this.codesSaved = false; this.useRecovery = false;
        this.detail = null; this.admin = null; this.pending = null; this.institutions = emptyPage(); this.plans = emptyPage(); this.admins = emptyPage(); this.planOptions = [];
        this.users = emptyPage(); this.userInstitutionOptions = []; this.userAccess = null; this.userFilters = { q: '', institution_id: '', role: '', active: '' }; this.invitationEmailError = ''; this.invitationResult = null;
        this.history = { items: [], next_cursor: null }; this.audit = { items: [], next_cursor: null };
        this.metadata = { name: '', customer: blankCustomer(), reason: '', expected_revision: 0 }; this.creation = { name: '', customer: blankCustomer(), admin_name: '', admin_email: '' };
        this.invitation = { name: '', email: '', reason: '' }; this.agreement = blankAgreement(); this.actionReason = ''; this.review = null; this.reviewRows = [];
        this.planForm = { id: null, code: '', name: '', description: '', available_for_new_contracts: true, limits: { admin: '', editor: '', reader: '' }, reason: '', expected_revision: 1 };
        this.planCurrent = null; this.reviewExcess = []; this.adminQuery = ''; this.auditInstitution = null;
        this.filters = { q: '', status: '', commercial_state: '' }; this.planFilters = { q: '', available: '' }; this.auditFilters = { action: '', subject_kind: '', subject_id: '' };
        this.$refs.confirmation?.close(); this.phase = 'login';
    },
    expire() { this.clearSession(); this.error = messages.AUTH_UNAUTHENTICATED; },
    async login() {
        await this.run(async () => {
            let data;
            try { data = await this.client.request('auth/login', { method: 'POST', body: { email: this.auth.email, password: this.auth.password } }); }
            finally { this.clearSecrets(); }
            this.challenge = data; this.phase = data.next_step === 'mfa_enrollment' ? 'enrollment' : 'challenge'; this.useRecovery = false;
        });
    },
    proof() { return { challenge_id: this.challenge.challenge_id, challenge_token: this.challenge.challenge_token }; },
    async enroll() { await this.run(async () => { this.enrollment = await this.client.request('auth/mfa/enrollment', { method: 'POST', body: this.proof() }); }); },
    async verify() {
        await this.run(async () => {
            const enrolling = this.phase === 'enrollment';
            const body = { ...this.proof(), ...(this.useRecovery && !enrolling ? { recovery_code: this.auth.recovery_code } : { code: this.auth.code }) };
            let result;
            try { result = await this.client.request(`auth/mfa/${enrolling ? 'confirmation' : 'challenge'}`, { method: 'POST', body }); } finally { this.clearSecrets(); }
            this.enrollment = null;
            if (result.recovery_codes) { this.challenge = null; this.codes = result.recovery_codes; this.phase = 'codes'; this.codesSaved = false; return; }
            if (result.next_step) { this.challenge = result; this.phase = 'enrollment'; this.useRecovery = false; return; }
            this.challenge = null; this.client.token = result.token; this.expiresAt = result.expires_at;
            try { this.operator = (await this.client.request('auth/profile')).operator; } catch (error) { this.clearSession(); throw error; }
            this.phase = 'admin'; this.screen = 'institutions'; this.syncPending(); await this.loadInstitutions();
        });
    },
    finishCodes() {
        if (!this.codesSaved) return;
        this.codes = []; this.codesSaved = false;
        if (this.operator) { this.phase = 'admin'; this.screen = 'security'; }
        else { this.phase = 'login'; this.notice = 'MFA configurado. Ingresa nuevamente y utiliza un código TOTP nuevo.'; }
    },
    async recover() { await this.run(async () => { await this.client.request('auth/password-recovery', { method: 'POST', body: { email: this.auth.email } }); this.notice = 'Si la cuenta es elegible, recibirá instrucciones de recuperación. Revisa también la carpeta de correo no deseado.'; }); },
    async consumeLink() {
        await this.run(async () => {
            try {
                await this.client.request(`auth/${this.phase === 'activation' ? 'activation' : 'password-reset'}`, { method: 'POST', body: { email: this.link.email, token: this.link.token, password: this.auth.password, password_confirmation: this.auth.password_confirmation } });
                this.link = null; this.phase = 'login'; this.notice = 'Contraseña guardada. Ingresa para completar MFA.';
            } catch (error) { if (error.code === 'PLATFORM_LINK_INVALID') { this.link = null; this.phase = 'invalid-link'; } throw error; }
            finally { this.clearSecrets(); }
        });
    },
    async logout() {
        await this.run(async () => {
            try { await this.client.request('auth/logout', { method: 'POST' }); }
            catch { this.notice = 'Se cerró el acceso en esta pestaña. No pudimos confirmar la revocación remota; el token vence como máximo en una hora.'; }
            finally { this.clearSession(); }
        });
    },
    async changePassword() {
        await this.run(async () => {
            try { await this.client.request('auth/password', { method: 'PATCH', body: { current_password: this.auth.current_password, password: this.auth.password, password_confirmation: this.auth.password_confirmation } }); this.clearSession(); this.notice = 'Contraseña cambiada. Todas las sesiones fueron revocadas. Ingresa nuevamente.'; }
            finally { this.clearSecrets(); }
        });
    },
    async regenerate() {
        await this.run(async () => {
            try { const data = await this.client.request('auth/mfa/recovery-codes', { method: 'POST', body: { current_password: this.auth.current_password, code: this.auth.code } }); this.codes = data.recovery_codes; this.codesSaved = false; this.phase = 'codes'; }
            finally { this.clearSecrets(); }
        });
    },
    async navigate(screen) {
        if (this.roleUnknown) { this.notice = 'Consulta el cambio de acceso pendiente antes de preparar otra acción.'; return; }
        if (this.waiting) return;
        this.clearSecrets(); this.resetFeedback(); this.uncertain = false;
        await this.run(async () => {
            this.screen = screen;
            if (screen === 'institutions') await this.loadInstitutions();
            if (screen === 'users') { await this.loadUserInstitutions(); await this.loadUsers(); }
            if (screen === 'recoveries') await this.loadRecoveries();
            if (screen === 'plans') await this.loadPlans();
            if (screen === 'create') { this.agreement = blankAgreement(); await this.loadPlanOptions(); }
            if (screen === 'audit') { this.auditInstitution = null; this.auditFilters = { action: '', subject_kind: '', subject_id: '' }; await this.loadAudit(); }
        });
    },
    async loadInstitutions(page = 1) { this.institutions = await this.client.request(`institutions?${query({ ...this.filters, page })}`); },
    async loadUsers(page = 1) { this.users = await this.client.request(`users?${query({ ...this.userFilters, page })}`); },
    async loadUserInstitutions() {
        const items = []; let page = 1; let result;
        do { result = await this.client.request(`institutions?per_page=100&page=${page++}`); items.push(...result.items); } while (result.page < result.last_page);
        this.userInstitutionOptions = items;
    },
    async openUserAccess(user) {
        await this.run(async () => {
            const current = await this.client.request(`users?${query({ user_id: user.user_id, institution_id: user.institution_id, per_page: 1 })}`);
            user = current.items[0];
            if (!user) throw new PlatformError('RESOURCE_NOT_FOUND', 404);
            await this.fetchDetail(user.institution_id); this.userAccess = copy(user);
            if (user.roles.includes('admin')) {
                await this.loadAdmins();
                this.admin = { ...user, ...await this.client.request(`institutions/${user.institution_id}/administrators/${user.user_id}/invitation`) };
                this.actionReason = ''; this.uncertain = false; this.screen = 'admins';
            } else this.screen = 'user-access';
        });
    },
    async inviteFromUsers() {
        if (!this.userFilters.institution_id) return;
        await this.run(async () => { await this.fetchDetail(this.userFilters.institution_id); this.adminQuery = ''; await this.loadAdmins(); this.screen = 'admins'; });
    },
    async loadPlans(page = 1) { this.plans = await this.client.request(`plans?${query({ ...this.planFilters, page })}`); },
    async loadPlanOptions() {
        // Complete pagination; a plan is never silently missing after the first 100 rows.
        const items = []; let page = 1; let result;
        do { result = await this.client.request(`plans?available=true&per_page=100&page=${page++}`); items.push(...result.items); } while (result.page < result.last_page);
        this.planOptions = items;
    },
    async openInstitution(id) {
        await this.run(async () => { await this.fetchDetail(id); this.screen = 'detail'; this.uncertain = false; });
    },
    async fetchDetail(id = this.detail.id) {
        this.detail = await this.client.request(`institutions/${id}`);
        this.metadata = { name: this.detail.name, customer: { ...blankCustomer(), ...this.detail.customer }, reason: '', expected_revision: this.detail.operational_revision };
        this.admin = null; this.actionReason = '';
    },
    async refreshDetail() {
        await this.run(async () => {
            const input = copy(this.metadata); await this.fetchDetail();
            this.metadata = { ...input, expected_revision: this.detail.operational_revision };
            this.uncertain = false; this.notice = 'Estado actualizado. El formulario conserva tus valores; compáralos con el registro actual antes de guardar.';
        });
    },
    confirm(kind, body, title, rows, path, method = 'POST') {
        if (this.waiting) return;
        this.resetFeedback(); this.review = { kind, body: copy(body), title, path, method }; this.reviewRows = rows; this.reviewExcess = [];
        this.$refs.confirmation.showModal();
    },
    customerPayload(customer) { return customer.type || customer.name || customer.rut ? { type: customer.type || null, name: customer.name || null, rut: customer.rut || null } : null; },
    reviewMetadata() {
        const body = { ...copy(this.metadata), customer: this.customerPayload(this.metadata.customer) };
        this.confirm('metadata', body, 'Confirmar edición de metadatos', [ ['Institución actual', this.detail.name], ['Nombre propuesto', body.name], ['Tipo de cliente', this.label(body.customer?.type)], ['Cliente', body.customer?.name || 'Sin cliente'], ['RUT', body.customer?.rut || 'Sin RUT'], ['Revisión leída', body.expected_revision], ['Motivo', body.reason] ], `institutions/${this.detail.id}`, 'PATCH');
    },
    reviewStatus() {
        const action = this.detail.status ? 'suspension' : 'reactivation';
        this.confirm('status', { expected_revision: this.detail.operational_revision, reason: this.actionReason }, this.detail.status ? 'Confirmar suspensión' : 'Confirmar reactivación', [ ['Institución', this.detail.name], ['Consecuencia', this.detail.status ? 'Se bloqueará el acceso institucional y se revocarán sesiones y credenciales. Las operaciones ya admitidas por servicios externos no se pueden retirar.' : 'Se habilitará el acceso institucional. Las credenciales revocadas no se restauran; los usuarios deben iniciar sesión de nuevo.'], ['Motivo', this.actionReason] ], `institutions/${this.detail.id}/${action}`);
    },
    editPlan(plan = null) {
        this.resetFeedback(); this.uncertain = false; this.planCurrent = null;
        this.planForm = plan ? { ...copy(plan), expected_revision: plan.revision, reason: '' } : { id: null, code: '', name: '', description: '', available_for_new_contracts: true, limits: { admin: '', editor: '', reader: '' }, reason: '', expected_revision: 1 };
        this.screen = 'plan-edit';
    },
    reviewPlan() {
        const f = this.planForm;
        const body = { name: f.name, description: f.description, available_for_new_contracts: f.available_for_new_contracts, limits: limits(f.limits), ...(f.id ? { expected_revision: f.expected_revision, reason: f.reason } : { code: f.code }) };
        this.confirm('plan', body, f.id ? 'Confirmar edición del catálogo' : 'Crear plan de catálogo', [ ['Código', f.code], ['Nombre', f.name], ['Descripción', f.description], ['Disponible para nuevos convenios', f.available_for_new_contracts ? 'Sí' : 'No'], ...this.roles.map(role => [this.label(role), this.quota(body.limits[role])]), ['Efecto', 'Los convenios existentes conservan sus condiciones.'], ['Motivo', f.reason || 'Creación de plan'] ], f.id ? `plans/${f.id}` : 'plans', f.id ? 'PATCH' : 'POST');
    },
    async refreshPlan() {
        await this.run(async () => {
            if (this.planForm.id) { const latest = await this.client.request(`plans/${this.planForm.id}`); this.planForm.expected_revision = latest.revision; this.notice = `Versión actual: ${latest.revision}. Revisa el registro actual a continuación y vuelve a confirmar tus valores.`; this.planCurrent = latest; }
            else { await this.loadPlans(); this.notice = 'Revisa la lista de planes antes de volver a intentar una creación. Si el código ya existe, ábrelo para editarlo.'; }
            this.uncertain = false;
        });
    },
    planCurrent: null,
    sourcePlan() {
        const current = this.screen === 'agreement' ? this.detail?.current_contract?.conditions.source_plan : null;
        if (current?.id === this.agreement.plan_id) return current;
        return this.planOptions.find(plan => plan.id === this.agreement.plan_id);
    },
    selectPlan() {
        const source = this.sourcePlan();
        if (source) this.agreement.limits = copy(source.limits);
        this.agreement.limit_reasons = { admin: '', editor: '', reader: '' };
    },
    async openAgreement() {
        await this.run(async () => {
            await this.loadPlanOptions(); this.agreement = blankAgreement();
            const current = this.detail.current_contract;
            if (current) {
                const c = current.conditions;
                this.agreement = { ...blankAgreement(), ...copy(c), plan_id: c.source_plan.id, has_trial: Boolean(c.trial), trial_type: c.trial?.type || 'trial', trial_start: c.trial?.starts_on || '', trial_end: c.trial?.ends_on || '', ends_on: c.ends_on || '', reason: '', extra_exceptions: c.exceptions.filter(e => !e.condition.startsWith('limits.')).map(e => ({ condition: e.condition, reason: e.reason })) };
                for (const item of c.exceptions.filter(e => e.condition.startsWith('limits.'))) this.agreement.limit_reasons[item.condition.split('.')[1]] = item.reason;
            }
            this.screen = 'agreement'; this.uncertain = false;
        });
    },
    async refreshAgreement() {
        await this.run(async () => {
            const form = copy(this.agreement); await this.fetchDetail(); await this.loadPlanOptions(); this.agreement = form;
            this.uncertain = false;
            this.notice = 'Convenio actual actualizado. Conservamos tus condiciones propuestas. Compara la nueva revisión y revisa otra vez las excepciones y el exceso antes de confirmar.';
        });
    },
    agreementRows(body) {
        return [ ['Plan de origen', `${this.sourcePlan().name} · revisión ${body.expected_plan_revision}`], ['Tipo', this.label(body.agreement_kind)], ['Estado', this.label(body.lifecycle)], ['Precio CLP', body.price_clp], ['Impuestos', this.label(body.tax_treatment)], ['Periodicidad', this.label(body.periodicity)], ['Desde', body.starts_on], ['Hasta', body.ends_on || 'Sin fecha de término'], ...this.roles.map(role => [this.label(role), this.quota(body.limits[role])]), ['Prueba o piloto', body.trial ? `${this.label(body.trial.type)}: ${body.trial.starts_on} a ${body.trial.ends_on}` : 'No'], ...body.exceptions.map(e => [`Excepción: ${this.label(e.condition)}`, `${e.condition.startsWith('limits.') ? this.quota(e.base_value) : 'Sin valor base de catálogo'} → ${e.condition.startsWith('limits.') ? this.quota(e.agreed_value) : (e.agreed_value === null ? 'Sin término' : this.label(String(e.agreed_value)))}. ${e.reason}`]), ['Motivo', body.reason] ];
    },
    reviewAgreement() {
        try {
            const body = agreementPayload(this.agreement, this.sourcePlan(), this.detail.current_contract?.revision || 0);
            this.confirm('agreement', body, 'Confirmar nueva revisión del convenio', this.agreementRows(body), `institutions/${this.detail.id}/contract/revisions`);
            this.reviewExcess = excessPreview(body.limits, this.detail.quotas.roles);
        } catch (error) { this.explain(error); }
    },
    reviewCreation() {
        try {
            const contract = agreementPayload(this.agreement, this.sourcePlan());
            const body = { institution: { name: this.creation.name }, customer: this.customerPayload(this.creation.customer), initial_admin: { name: this.creation.admin_name, email: this.creation.admin_email }, contract };
            this.confirm('onboarding', body, 'Revisar creación de institución', [ ['Institución', body.institution.name], ['Tipo de cliente', this.label(body.customer?.type)], ['Cliente', body.customer?.name || 'Sin cliente'], ['RUT', body.customer?.rut || 'Sin RUT'], ['Administrador inicial', `${body.initial_admin.name} · ${body.initial_admin.email}`], ...this.agreementRows(contract) ], 'institutions');
            this.reviewExcess = excessPreview(contract.limits, { admin: { used: 1 } });
        } catch (error) { this.explain(error); }
    },
    async openHistory() { await this.run(async () => { this.history = await this.client.request(`institutions/${this.detail.id}/contract/revisions`); this.screen = 'history'; }); },
    async moreHistory() { await this.run(async () => { const next = await this.client.request(`institutions/${this.detail.id}/contract/revisions?cursor=${this.history.next_cursor}`); this.history = { items: [...this.history.items, ...next.items], next_cursor: next.next_cursor }; }); },
    async openAdmins() { await this.run(async () => { this.adminQuery = ''; await this.loadAdmins(); this.screen = 'admins'; }); },
    async loadAdmins(page = 1) { this.admins = await this.client.request(`institutions/${this.detail.id}/administrators?${query({ q: this.adminQuery, page })}`); },
    async selectAdmin(user) { await this.run(async () => { this.admin = { ...user, ...await this.client.request(`institutions/${this.detail.id}/administrators/${user.user_id}/invitation`) }; this.actionReason = ''; this.uncertain = false; }); },
    reviewAdminAction(kind) {
        const body = { reason: this.actionReason, ...(kind === 'reactivation' ? { expected_user_revision: this.admin.access_revision } : {}) };
        this.confirm('admin', body, kind === 'reactivation' ? 'Confirmar reactivación del administrador' : 'Confirmar reenvío de invitación', [ ['Administrador', `${this.admin.name} · ${this.admin.email}`], ['Consecuencia', kind === 'reactivation' ? 'La cuenta vuelve a estar activa si sus roles tienen cupo disponible. No se modifica su contraseña ni se confirma su correo.' : 'Se invalida el enlace anterior y se publica una nueva invitación. La publicación no confirma entrega ni activación.'], ['Motivo', this.actionReason] ], `institutions/${this.detail.id}/administrators/${this.admin.user_id}/${kind}`);
    },
    reviewInvitation() { this.invitationEmailError = ''; this.confirm('invitation', copy(this.invitation), 'Invitar nuevo administrador', [ ['Institución', this.detail.name], ['Nombre', this.invitation.name], ['Correo', this.invitation.email], ['Motivo', this.invitation.reason], ['Consecuencia', 'Se crea una cuenta pendiente que reserva un cupo de administrador. No habilita el acceso hasta la activación.'] ], `institutions/${this.detail.id}/administrator-invitations`); },
    async settleInvitation(error = null) {
        const draft = this.pendingStore.value;
        if (draft?.type !== 'administrator_invitation') return;
        if (!['committed', 'rejected'].includes(draft.status)) { this.syncPending(); this.screen = 'pending'; return; }
        const institutionId = draft.path.split('/')[1];
        this.invitation = copy(draft.body);
        this.invitationResult = draft.status === 'committed' ? copy(draft.result) : null;
        this.invitationEmailError = error?.code === 'EMAIL_UNAVAILABLE' ? 'Este correo ya pertenece a una cuenta institucional. Corrígelo o busca la cuenta en Usuarios; esta solicitud no creó otra cuenta.' : '';
        this.pendingStore.dismiss(); this.syncPending(); this.screen = 'admins';
        if (!this.detail || this.detail.id !== institutionId || this.invitationResult) await this.fetchDetail(institutionId);
        if (this.invitationResult) {
            this.invitation = { name: '', email: '', reason: '' }; this.adminQuery = '';
            await this.loadAdmins();
            this.admin = { name: draft.body.name, email: draft.body.email, ...await this.client.request(`institutions/${institutionId}/administrators/${draft.result.administrator_id}/invitation`) };
            this.actionReason = ''; this.uncertain = false;
            this.notice = 'Cuenta creada y cupo actualizado. Revisa el estado de publicación; la aceptación no confirma entrega.';
        }
    },
    syncPending() {
        const draft = this.pendingStore.value;
        this.pending = draft?.owner === this.operator?.id ? copy(draft) : null;
        if (draft && !this.pending) this.notice = 'Esta pestaña conserva una operación de otro operador. Ingresa con el operador original para resolverla.';
    },
    async executeReview() {
        const review = this.review;
        this.$refs.confirmation.close();
        await this.run(async () => {
            if (review.kind === 'recovery-prepare') { await this.submitRecovery(review.body); return; }
            if (review.kind === 'recovery-transition') { await this.performRecoveryTransition(review.path, review.body); return; }
            if (['onboarding', 'invitation'].includes(review.kind)) {
                this.pendingStore.prepare(this.operator.id, review.kind === 'onboarding' ? 'institution_onboarding' : 'administrator_invitation', review.path, review.body);
                try { await this.pendingStore.submit(this.client); }
                catch (error) { await this.settleInvitation(error); throw error; }
                finally { this.syncPending(); if (this.pending) this.screen = 'pending'; }
                if (review.kind === 'invitation') { await this.settleInvitation(); return; }
                this.screen = 'pending'; this.notice = 'Recursos confirmados. Revisa por separado el estado de publicación y activación.';
                return;
            }
            try {
                await this.client.request(review.path, { method: review.method, body: review.body });
                this.notice = 'Operación completada.'; this.uncertain = false;
                if (review.kind === 'plan') { this.screen = 'plans'; await this.loadPlans(); }
                else if (review.kind === 'admin') { this.admin = { ...this.admin, ...await this.client.request(`institutions/${this.detail.id}/administrators/${this.admin.user_id}/invitation`) }; await this.loadAdmins(this.admins.page); }
                else { await this.fetchDetail(); this.screen = 'detail'; }
            } catch (error) { if (error.uncertain || error.status === 409) this.uncertain = true; throw error; }
        });
        this.review = null; this.reviewRows = [];
    },
    async reconcile() {
        await this.run(async () => {
            try { await this.pendingStore.reconcile(this.client); await this.settleInvitation(); this.notice = 'Se recuperó la operación confirmada; no se repitió la creación.'; }
            catch (error) { if (error.status === 404) this.error = 'Todavía no hay un resultado confirmado para esta clave. Puedes consultar otra vez o reenviar exactamente la misma solicitud y clave.'; else throw error; }
            finally { this.syncPending(); }
        });
    },
    async retryPending() { await this.run(async () => { try { await this.pendingStore.submit(this.client); await this.settleInvitation(); } catch (error) { await this.settleInvitation(error); throw error; } finally { this.syncPending(); } }); },
    async correctRejectedInvitation() { await this.run(() => this.settleInvitation(new PlatformError(this.pendingStore.value?.errorCode || 'REQUEST_FAILED', 409))); },
    dismissPending() { try { this.pendingStore.dismiss(); this.pending = null; this.creation = { name: '', customer: blankCustomer(), admin_name: '', admin_email: '' }; this.invitation = { name: '', email: '', reason: '' }; this.screen = 'institutions'; } catch (error) { this.explain(error); } },
    async institutionAudit() { await this.run(async () => { this.auditInstitution = this.detail.id; this.auditFilters = { action: '', subject_kind: '', subject_id: '' }; await this.loadAudit(); this.screen = 'audit'; }); },
    async loadAudit(cursor = null) {
        const path = this.auditInstitution ? `institutions/${this.auditInstitution}/audit` : 'audit';
        const data = await this.client.request(`${path}?${query({ ...this.auditFilters, cursor })}`);
        this.audit = cursor ? { items: [...this.audit.items, ...data.items], next_cursor: data.next_cursor } : data;
    },
}));

Alpine.start();
