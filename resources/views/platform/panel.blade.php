<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Administración de plataforma · Acervo</title>
    <script nonce="{{ $nonce }}">
        // Read once, then remove email links before loading any assets. No server-side interpolation.
        (() => {
            const params = new URLSearchParams(location.hash ? location.hash.slice(1) : location.search);
            if (location.pathname === '/platform/activate' || location.pathname === '/platform/password-reset') {
                window.__platformLink = { email: params.get('email'), token: params.get('token') };
            }
            if (location.pathname === '/platform/access-verification') {
                window.__platformLink = { operation: params.get('operation'), token: params.get('token'), setup: params.get('setup') === '1' };
            }
            if (location.search || location.hash) history.replaceState(null, '', location.pathname);
        })();
    </script>
    @vite('resources/js/platform.js')
</head>
<body>
<div x-data="platformPanel" x-cloak>
    <header>
        <div class="platform">
            <div><strong>Acervo</strong> <span class="muted">/ Administración de plataforma</span></div>
            <template x-if="phase === 'admin'">
                <div class="actions">
                    <span x-text="operator?.name"></span>
                    <button type="button" class="secondary" @click="navigate('security')" :disabled="waiting">Seguridad</button>
                    <button type="button" class="secondary" @click="logout" :disabled="waiting">Cerrar sesión</button>
                </div>
            </template>
        </div>
    </header>
    <main class="platform" :aria-busy="waiting">
        <div x-ref="feedback" tabindex="-1" aria-live="polite">
            <div class="notice error" role="alert" x-show="error">
                <p x-text="error"></p>
                <ul class="errors" x-show="errorFields.length"><template x-for="(field, index) in errorFields" :key="index"><li x-text="field"></li></template></ul>
            </div>
            <p class="notice" role="status" x-show="notice" x-text="notice"></p>
            <p class="notice warning" x-show="now < retryAt">Puedes continuar en <span x-text="Math.ceil((retryAt - now) / 1000)"></span> segundos.</p>
        </div>
        <p role="status" x-show="busy || loading">Procesando solicitud…</p>
        @include('platform.access')
        @include('platform.recovery-verification')
        <template x-if="phase === 'admin'">
            <div>
                <nav aria-label="Administración">
                    <button type="button" :aria-current="screen === 'institutions' ? 'page' : null" class="secondary" @click="navigate('institutions')" :disabled="waiting">Instituciones</button>
                    <button type="button" :aria-current="screen === 'create' ? 'page' : null" class="secondary" @click="navigate('create')" :disabled="waiting">Crear institución</button>
                    <button type="button" :aria-current="screen === 'users' ? 'page' : null" class="secondary" @click="navigate('users')" :disabled="waiting">Usuarios</button>
                    <button type="button" class="secondary" @click="navigate('recoveries')" :disabled="waiting">Recuperaciones</button>
                    <button type="button" :aria-current="screen === 'plans' ? 'page' : null" class="secondary" @click="navigate('plans')" :disabled="waiting">Planes</button>
                    <button type="button" :aria-current="screen === 'audit' ? 'page' : null" class="secondary" @click="navigate('audit')" :disabled="waiting">Auditoría</button>
                    <button type="button" class="secondary" x-show="pending" @click="screen = 'pending'; resetFeedback()" :disabled="waiting">Operación pendiente</button>
                </nav>
                <p class="muted"><small>La sesión de operador existe solo en esta pestaña y se pierde al recargar. Vence: <span x-text="date(expiresAt)"></span>.</small></p>
                @include('platform.institutions')
                @include('platform.commercial')
                @include('platform.administrators')
                @include('platform.users')
                @include('platform.roles')
                <section class="card" x-show="roleUnknown"><p>Hay un cambio de acceso con resultado incierto. Consulta el estado y la auditoría antes de repetir una acción.</p><button @click="resolveRoleUnknown" :disabled="waiting">Consultar cambio pendiente</button></section>
                @include('platform.recoveries')
                @include('platform.audit')
                @include('platform.pending')
                <section class="card" x-show="screen === 'security'">
                    <h1>Seguridad del operador</h1>
                    <p x-text="operator?.email"></p>
                    <div class="grid">
                        <form @submit.prevent="changePassword">
                            <fieldset :disabled="waiting">
                                <legend>Cambiar contraseña</legend>
                                <p>Se revocarán todas tus sesiones de operador.</p>
                                <label>Contraseña actual<input type="password" x-model="auth.current_password" required autocomplete="current-password"></label>
                                <label>Nueva contraseña<input type="password" x-model="auth.password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
                                <label>Confirmar nueva contraseña<input type="password" x-model="auth.password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password"></label>
                                <button type="submit">Cambiar y cerrar sesiones</button>
                            </fieldset>
                        </form>
                        <form @submit.prevent="regenerate">
                            <fieldset :disabled="waiting">
                                <legend>Regenerar códigos de recuperación</legend>
                                <p>Los códigos anteriores quedarán invalidados. Los nuevos se mostrarán una sola vez. Usa un TOTP que no hayas usado antes.</p>
                                <label>Contraseña actual<input type="password" x-model="auth.current_password" required autocomplete="current-password"></label>
                                <label>Código TOTP<input x-model="auth.code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
                                <button type="submit">Invalidar anteriores y generar códigos</button>
                            </fieldset>
                        </form>
                    </div>
                </section>
            </div>
        </template>
        <dialog x-ref="confirmation" aria-labelledby="platform-confirm-title" @cancel="review = null; reviewRows = []; reviewExcess = []">
            <h2 id="platform-confirm-title" x-text="review?.title"></h2>
            <dl><template x-for="(row, index) in reviewRows" :key="index"><div><dt><strong x-text="row[0]"></strong></dt><dd x-text="row[1]"></dd></div></template></dl>
            <template x-if="reviewExcess.length">
                <div>
                    <h3>Uso y exceso resultante</h3>
                    <p>Los usuarios existentes se conservan. Se restringen nuevas admisiones que aumenten el uso por encima del límite.</p>
                    <div class="table-wrap"><table><thead><tr><th>Rol</th><th>Uso actual o inicial</th><th>Límite propuesto</th><th>Exceso</th></tr></thead><tbody>
                        <template x-for="row in reviewExcess" :key="row.role"><tr><td x-text="label(row.role)"></td><td x-text="row.used"></td><td x-text="quota(row.limit)"></td><td x-text="row.over_by"></td></tr></template>
                    </tbody></table></div>
                </div>
            </template>
            <p class="notice warning">Confirma únicamente después de revisar los datos y las consecuencias.</p>
            <div class="actions">
                <button type="button" @click="executeReview" :disabled="waiting">Confirmar operación</button>
                <button type="button" class="secondary" @click="$refs.confirmation.close(); review = null; reviewRows = []">Volver al formulario</button>
            </div>
        </dialog>
    </main>
</div>
<noscript><main class="platform"><h1>Administración de plataforma</h1><p>Activa JavaScript para usar el panel. El acceso administrativo requiere autenticación de operador y MFA.</p></main></noscript>
</body>
</html>
