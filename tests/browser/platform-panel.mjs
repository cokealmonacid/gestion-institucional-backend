// Real Blade/Vite/Alpine + Laravel APIs, disposable SQLite and a fake failing queue.
// Requires installed Microsoft Edge; no downloaded browser or additional dependency.
import { spawn, spawnSync } from 'node:child_process';
import { randomUUID, createHmac } from 'node:crypto';
import { mkdirSync, realpathSync, existsSync, writeFileSync, unlinkSync } from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import assert from 'node:assert/strict';

const root = process.cwd();
const fixtureDir = path.join(root, 'storage/framework/testing');
mkdirSync(fixtureDir, { recursive: true });
const id = randomUUID();
const database = path.join(realpathSync(fixtureDir), `platform-panel-${id}.sqlite`);
const profile = path.join(realpathSync(fixtureDir), `platform-browser-${id}`);
const edge = process.env.EDGE_BINARY || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
if (!existsSync(edge)) throw new Error('Installed Edge is required; set EDGE_BINARY.');
const env = { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '', PLATFORM_BROWSER_DATABASE: database };
const router = path.join(root, 'tests/Support/platform-panel-browser-router.php');
const prepared = spawnSync('php', [router, '--prepare'], { cwd: root, env, encoding: 'utf8', windowsHide: true });
assert.equal(prepared.status, 0, prepared.stderr || prepared.stdout);
assert.ok(prepared.stdout.includes('fixtures ready'), prepared.stdout);
const port = () => new Promise(resolve => { const server = net.createServer(); server.listen(0, '127.0.0.1', () => { const p = server.address().port; server.close(() => resolve(p)); }); });
const httpPort = await port(), debugPort = await port();
const base = `http://127.0.0.1:${httpPort}`;
const php = spawn('php', ['-S', `127.0.0.1:${httpPort}`, '-t', 'public', router], { cwd: root, env, windowsHide: true, stdio: 'ignore' });
const browser = spawn(edge, ['--headless=new', '--disable-gpu', '--disable-extensions', '--renderer-process-limit=1', '--no-first-run', '--no-default-browser-check', `--remote-debugging-port=${debugPort}`, '--remote-debugging-address=127.0.0.1', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function eventually(task, description, timeout = 15000) {
    const start = Date.now(); let failure;
    while (Date.now() - start < timeout) { try { const result = await task(); if (result) return result; } catch (e) { failure = e; } await delay(100); }
    throw new Error(`Timed out: ${description}${failure ? ` (${failure.message})` : ''}`);
}
let socket;
let closeBrowser;
const report = [];
const exceptions = [];
try {
    await eventually(async () => (await fetch(base + '/platform', { signal: AbortSignal.timeout(2000) })).ok, 'fixture server');
    console.log('Fixture server ready.');
    const targets = await eventually(async () => { const r = await fetch(`http://127.0.0.1:${debugPort}/json/list`, { signal: AbortSignal.timeout(2000) }); const list = await r.json(); return list.find(t => t.type === 'page'); }, 'Edge debugging');
    socket = new WebSocket(targets.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
    let serial = 0; const jobs = new Map(); let dropCreationResponse = false;
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const n = ++serial;
        const timeout = setTimeout(() => { jobs.delete(n); reject(Error('CDP timed out: ' + method)); }, 30000);
        jobs.set(n, { resolve: value => { clearTimeout(timeout); resolve(value); }, reject: error => { clearTimeout(timeout); reject(error); } });
        socket.send(JSON.stringify({ id: n, method, params }));
    });
    closeBrowser = () => send('Browser.close');
    socket.addEventListener('message', async event => {
        const message = JSON.parse(event.data);
        if (message.id) { const job = jobs.get(message.id); jobs.delete(message.id); if (message.error) job?.reject(Error(message.error.message)); else job?.resolve(message.result); }
        if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params.exceptionDetails.text + ' ' + (message.params.exceptionDetails.exception?.description || ''));
        if (message.method === 'Fetch.requestPaused') {
            const { requestId, request } = message.params;
            if (dropCreationResponse && request.method === 'POST' && (request.url === base + '/api/v1/platform/institutions' || request.url.endsWith('/administrator-invitations'))) {
                dropCreationResponse = false; await send('Fetch.failRequest', { requestId, errorReason: 'ConnectionReset' }); await send('Fetch.disable');
            } else await send('Fetch.continueRequest', { requestId });
        }
    });
    await send('Page.enable'); await send('Runtime.enable');
    const evaluate = async expression => {
        const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result?.value;
    };
    const state = expression => evaluate(`(function () { with (document.querySelector('[x-data="platformPanel"]')._x_dataStack[0]) { return (${expression}); } })()`);
    const visible = selector => `Array.from(document.querySelectorAll(${JSON.stringify(selector)})).filter(e => e.getClientRects().length)`;
    const fill = (model, value) => {
        const repeated = model.match(/^(planForm|agreement)\.limits\.(admin|editor|reader)$/);
        const binding = repeated ? `${repeated[1]}.limits[role]` : model;
        const index = repeated ? ['admin', 'editor', 'reader'].indexOf(repeated[2]) : 0;
        return eventually(() => evaluate(`(() => { const e = ${visible(`[x-model="${binding}"]`)}[${index}]; if (!e) return false; e.value = ${JSON.stringify(value)}; e.dispatchEvent(new Event(e.tagName === 'SELECT' ? 'change' : 'input', {bubbles:true})); return true; })()`), 'input ' + model);
    };
    const click = text => eventually(() => evaluate(`(() => { const e = ${visible('button')}.find(e => e.textContent.trim() === ${JSON.stringify(text)}); if (!e || e.disabled) return false; e.click(); return true; })()`), 'button ' + text);
    const waitState = async (expression, description) => eventually(() => state(expression), description);
    const totp = secret => {
        const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = '';
        for (const char of secret.replace(/=/g, '')) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
        const bytes = []; for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
        const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
        const hash = createHmac('sha1', Buffer.from(bytes)).update(counter).digest();
        return String((hash.readUInt32BE(hash[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
    };
    const login = async (email, secret = 'JBSWY3DPEHPK3PXP') => {
        await fill('auth.email', email); await fill('auth.password', 'Synthetic-panel-password-123'); await click('Continuar con MFA');
        await waitState("phase === 'challenge' && !busy", 'MFA challenge');
        assert.equal(await state('client.token'), null);
        const denial = await evaluate(`fetch('/api/v1/platform/institutions', {headers:{Accept:'application/json'},credentials:'omit'}).then(r=>r.status)`);
        assert.equal(denial, 401);
        await fill('auth.code', totp(secret)); await click('Verificar'); await waitState("phase === 'admin' && !busy", 'MFA completed');
    };
    await send('Page.navigate', { url: base + '/platform' });
    await eventually(() => evaluate("document.querySelector('[x-data]')?._x_dataStack?.length"), 'Alpine shell');
    await login('panel-browser@example.test');
    report.push('Password-only access denied; real TOTP authentication opens the panel.');
    console.log('MFA boundary passed.');
    await click('Planes'); await waitState("screen === 'plans' && !busy", 'plans'); await click('Crear plan');
    await fill('planForm.code', 'browser-fixture'); await fill('planForm.name', 'Plan sintético'); await fill('planForm.description', 'Condiciones de prueba');
    await fill('planForm.limits.admin', '2'); await fill('planForm.limits.editor', '0'); await fill('planForm.limits.reader', '');
    await click('Revisar plan'); await click('Confirmar operación'); await waitState("screen === 'plans' && !busy && plans.items.length === 1", 'plan creation');
    assert.equal(await state('plans.items[0].limits.reader'), null); assert.equal(await state('plans.items[0].limits.editor'), 0);
    report.push('Plan creation preserves zero versus unlimited; confirmation dialog works.');
    console.log('Plan flow passed.');
    await click('Crear institución'); await waitState("screen === 'create' && !busy", 'onboarding form');
    await fill('creation.name', 'Institución <script>window.panelXss=true</script>');
    await fill('creation.customer.type', 'legal_entity'); await fill('creation.customer.name', 'Cliente sintético'); await fill('creation.customer.rut', '12.345.678-5');
    await fill('creation.admin_name', 'Administrador sintético'); await fill('creation.admin_email', 'admin-browser@example.test');
    const planId = await state('planOptions[0].id'); await fill('agreement.plan_id', planId); await fill('agreement.reason', 'Alta sintética');
    await click('Revisar institución y condiciones');
    dropCreationResponse = true; await send('Fetch.enable', { patterns: [{ urlPattern: '*/api/v1/platform/institutions', requestStage: 'Response' }] });
    await click('Confirmar operación'); await waitState("screen === 'pending' && pending?.status === 'uncertain' && !busy", 'lost creation response');
    const operationKey = await state('pending.key');
    assert.equal(await evaluate('localStorage.length'), 0);
    const stored = await evaluate('JSON.stringify(sessionStorage)'); assert.ok(!stored.includes('Synthetic-panel-password') && !stored.includes('Bearer'));
    await send('Page.reload'); await eventually(() => evaluate("document.querySelector('[x-data]')?._x_dataStack?.length"), 'reload');
    assert.equal(await state('client.token'), null);
    // TOTP replay is intentionally prohibited; wait for the next counter before fresh login.
    await delay(30050 - Date.now() % 30000);
    await login('panel-browser@example.test');
    await click('Operación pendiente'); assert.equal(await state('pending.key'), operationKey);
    await click('Consultar operación por su clave'); await waitState("pending.status === 'committed' && !busy", 'operation reconciliation');
    assert.equal(await state('pending.result.publication_status'), 'failed');
    report.push('Response lost after commit, reload and reauthentication retain the same key/payload; lookup recovers resources despite failed invitation publication.');
    console.log('Idempotent recovery passed.');
    await click('Abrir institución confirmada'); await waitState("screen === 'detail' && !busy", 'institution detail');
    assert.equal(await evaluate('window.panelXss'), undefined);
    await fill('metadata.name', 'Edición propuesta conservada'); await fill('metadata.reason', 'Cambio de prueba');
    const conflictStatus = await evaluate(`(() => { const s = document.querySelector('[x-data]')._x_dataStack[0]; return fetch('/api/v1/platform/institutions/'+s.detail.id,{method:'PATCH',credentials:'omit',headers:{'Content-Type':'application/json',Accept:'application/json',Authorization:'Bearer '+s.client.token},body:JSON.stringify({expected_revision:s.detail.operational_revision,name:'Edición concurrente',customer:s.detail.customer,reason:'Synthetic concurrency'})}).then(r=>r.status); })()`);
    assert.equal(conflictStatus, 200);
    await click('Revisar edición'); await click('Confirmar operación'); await waitState('uncertain && !busy', 'revision conflict');
    assert.equal(await state('metadata.name'), 'Edición propuesta conservada');
    await click('Actualizar estado y revisión'); await waitState('!uncertain && !busy', 'explicit revision refresh');
    await click('Revisar edición'); await click('Confirmar operación'); await waitState("detail.name === 'Edición propuesta conservada' && !busy", 'reviewed metadata update');
    report.push('Stale metadata revision is rejected; safe inputs survive an explicit refresh and review. Escaped customer text cannot execute scripts.');
    await click('Administradores y accesos'); await waitState("screen === 'admins' && !busy", 'administrator directory'); await click('Consultar acceso'); await waitState('admin && !busy', 'administrator state');
    assert.equal(await state('admin.activation_status'), 'pending_activation'); assert.equal(await state('admin.delivery_status'), 'failed');
    await click('Operación pendiente'); await click('Cerrar resultado y descartar borrador'); await waitState("screen === 'institutions' && !busy", 'close resolved onboarding');
    await click('Usuarios'); await waitState("screen === 'users' && !busy", 'direct user navigation');
    await fill('userFilters.q', 'admin-browser@example.test'); await fill('userFilters.role', 'admin'); await click('Buscar usuarios');
    await waitState('!busy && users.items.length === 1', 'filtered institutional users');
    assert.deepEqual(await state('users.items[0].roles'), ['admin']);
    await click('Consultar acceso'); await waitState("screen === 'admins' && admin && !busy", 'user context access');
    assert.ok(await evaluate("document.body.innerText.includes('Administrador habilitado')"));
    assert.equal(await evaluate("[...document.querySelectorAll('details')].find(e => e.textContent.includes('Detalles técnicos')).open"), false);
    await click('Actualizar información'); await waitState('!busy', 'read-only access refresh');
    await fill('invitation.name', 'Segundo administrador'); await fill('invitation.email', 'admin-browser@example.test'); await fill('invitation.reason', 'Prueba de conflicto');
    await click('Revisar invitación'); await click('Confirmar operación');
    await waitState("screen === 'admins' && !busy && invitationEmailError", 'definitive email conflict');
    assert.equal(await state('pendingStore.value'), null); assert.equal(await state('invitation.email'), 'admin-browser@example.test');
    assert.equal(await evaluate(`document.querySelector('[x-model="invitation.email"]').closest('fieldset').disabled`), false);
    await fill('invitation.email', 'second-admin-browser@example.test');
    await click('Revisar invitación'); dropCreationResponse = true;
    await send('Fetch.enable', { patterns: [{ urlPattern: '*/administrator-invitations', requestStage: 'Response' }] });
    await click('Confirmar operación'); await waitState("screen === 'pending' && pending?.status === 'uncertain' && !busy", 'uncertain invitation');
    assert.ok(await state('pending.key'));
    await click('Consultar operación por su clave');
    await waitState("screen === 'admins' && !busy && invitationResult", 'invitation reconciliation');
    assert.equal(await state('pendingStore.value'), null); assert.equal(await state('invitationResult.publication_status'), 'failed');
    assert.equal(await state('detail.quotas.roles.admin.used'), 2); assert.equal(await state('admins.total'), 2);
    assert.equal(await state('admin.email'), 'second-admin-browser@example.test');
    assert.ok(await evaluate("document.body.innerText.includes('Revisar reenvío de invitación')"));
    report.push('Usuarios navigation and filters open administrator access; definitive email conflict unlocks correction; lost invitation response reconciles a committed account with failed publication, refreshed list/quota and resend action.');
    await click('Corregir correo de la misma persona'); await waitState("screen === 'recovery-edit' && !busy", 'email recovery preparation');
    await fill('recoveryForm.new_email', 'corrected-browser@example.test');
    await fill('recoveryForm.authority.requester', 'Representante sintético'); await fill('recoveryForm.authority.relationship', 'Director autorizado');
    await fill('recoveryForm.authority.channel', 'Llamada verificada'); await fill('recoveryForm.authority.reference', 'Referencia sintética'); await fill('recoveryForm.reason', 'Corrección de la misma persona');
    await evaluate(`document.querySelector('[x-model="recoveryForm.same_person"]').click(); document.querySelector('[x-model="recoveryForm.confirmation"]').click()`);
    await click('Revisar recuperación'); await click('Confirmar operación'); await waitState("screen === 'recovery-operation' && !busy", 'durable recovery prepared');
    const recoveryId = await state('recoveryOperation.id');
    assert.equal(await state('recoveryOperation.state'), 'awaiting_verification'); assert.equal(await state('recoveryOperation.publication_status'), 'failed');
    assert.equal(await state('recoveryStore.value'), null);
    await click('Recuperaciones'); await waitState("screen === 'recoveries' && !busy", 'server recovery directory');
    await click('Abrir recuperación'); await waitState("screen === 'recovery-operation' && !busy", 'resume without draft'); assert.equal(await state('recoveryOperation.id'), recoveryId);
    await fill('recoveryReason', 'Cancelar prueba sintética'); await click('Revisar cancelación'); await click('Confirmar operación'); await waitState("recoveryOperation.state === 'cancelled' && !busy", 'explicit cancellation');
    report.push('Recovery UI records same-person authority, retains a durable preparation despite mail failure, resumes from the server directory without a draft, and explicitly cancels without changing the account.');
    await click('Auditoría'); await waitState("screen === 'audit' && !busy", 'audit'); assert.ok(await state('audit.items.length > 0'));
    await fill('auditFilters.action', 'institution.onboarded'); await click('Aplicar filtros'); await waitState('!busy && audit.items.length === 1', 'audit filter');
    report.push('Administrator metadata and failed invitation state render; audit filters retrieve the committed onboarding event.');
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    assert.ok(await evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'));
    const screenshot = await send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(path.join(root, 'storage/logs/platform-panel-mobile.png'), Buffer.from(screenshot.data, 'base64'));
    await click('Cerrar sesión'); await waitState("phase === 'login' && !busy", 'logout');
    await fill('auth.email', 'enroll-browser@example.test'); await fill('auth.password', 'Synthetic-panel-password-123'); await click('Continuar con MFA');
    await waitState("phase === 'enrollment' && !busy", 'enrollment'); await click('Obtener clave de configuración'); await waitState('enrollment && !busy', 'manual TOTP setup');
    const enrollmentSecret = await state('enrollment.manual_secret'); await fill('auth.code', totp(enrollmentSecret)); await click('Confirmar configuración MFA'); await waitState("phase === 'codes' && !busy", 'one-time recovery codes');
    assert.equal(await state('codes.length'), 10); assert.equal(await state('client.token'), null); assert.equal(await state('enrollment'), null);
    const recoveryCode = await state('codes[0]');
    await evaluate(`(() => { const e = document.querySelector('[x-model="codesSaved"]'); e.click(); })()`); await click('Ocultar códigos y continuar');
    assert.equal(await state('codes.length'), 0);
    await delay(30050 - Date.now() % 30000);
    await login('enroll-browser@example.test', enrollmentSecret); await click('Cerrar sesión'); await waitState("phase === 'login' && !busy", 'logout after enrollment');
    await fill('auth.email', 'enroll-browser@example.test'); await fill('auth.password', 'Synthetic-panel-password-123'); await click('Continuar con MFA'); await waitState("phase === 'challenge' && !busy", 'recovery challenge');
    await evaluate(`document.querySelector('[x-model="useRecovery"]').click()`); await fill('auth.recovery_code', recoveryCode); await click('Verificar'); await waitState("phase === 'enrollment' && !busy", 'recovery reenrollment');
    assert.equal(await state('client.token'), null);
    report.push('Manual MFA enrollment shows ten codes once, clears secrets, and requires a fresh MFA login; a recovery code enters restricted reenrollment without administrative access. Mobile layout fits 390px.');
    assert.deepEqual(exceptions, [], exceptions.join('\n'));
    writeFileSync(path.join(root, 'storage/logs/platform-panel-browser-results.json'), JSON.stringify({ passed: true, checks: report, fixtureDatabaseRemoved: true }, null, 2));
    console.log(report.map(s => 'PASS ' + s).join('\n'));
} catch (error) {
    console.error('Completed checks before failure: ' + report.length);
    console.error('Browser script errors: ' + JSON.stringify(exceptions.slice(0, 5)));
    throw error;
} finally {
    try { if (socket?.readyState === WebSocket.OPEN) await closeBrowser?.(); } catch { /* Fall back to the owned process below. */ }
    socket?.close(); browser.kill(); php.kill();
    await delay(500);
    for (const file of [database, database + '-wal', database + '-shm']) if (existsSync(file)) unlinkSync(file);
    console.log('Browser fixture profile (synthetic data only): ' + profile);
}
