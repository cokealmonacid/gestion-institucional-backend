<template x-if="['login', 'recovery', 'activation', 'reset', 'invalid-link', 'challenge', 'enrollment', 'codes'].includes(phase)">
    <section class="card access">
        <template x-if="phase === 'login'">
            <form @submit.prevent="login">
                <h1>Acceso de operadores</h1>
                <p>Usa tu cuenta de plataforma. La verificación MFA es obligatoria antes de acceder a la administración.</p>
                <fieldset :disabled="waiting">
                    <label>Correo de operador<input type="email" x-model="auth.email" required maxlength="255" autocomplete="username"></label>
                    <label>Contraseña<input type="password" x-model="auth.password" required autocomplete="current-password"></label>
                    <div class="actions"><button type="submit">Continuar con MFA</button><button type="button" class="secondary" @click="phase = 'recovery'; clearSecrets(); resetFeedback()">Recuperar contraseña</button></div>
                </fieldset>
            </form>
        </template>
        <template x-if="phase === 'recovery'">
            <form @submit.prevent="recover">
                <h1>Recuperar contraseña</h1>
                <fieldset :disabled="waiting">
                    <label>Correo de operador<input type="email" x-model="auth.email" required maxlength="255" autocomplete="username"></label>
                    <div class="actions"><button type="submit">Solicitar instrucciones</button><button type="button" class="secondary" @click="phase = 'login'; resetFeedback()">Volver al acceso</button></div>
                </fieldset>
            </form>
        </template>
        <template x-if="phase === 'activation' || phase === 'reset'">
            <form @submit.prevent="consumeLink">
                <h1 x-text="phase === 'activation' ? 'Activar cuenta de operador' : 'Restablecer contraseña'"></h1>
                <p>El enlace es de un solo uso y tiene una vigencia de 60 minutos. Después deberás iniciar sesión y completar MFA.</p>
                <p>Cuenta: <strong x-text="link?.email"></strong></p>
                <fieldset :disabled="waiting">
                    <label>Nueva contraseña<input type="password" x-model="auth.password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
                    <label>Confirmar contraseña<input type="password" x-model="auth.password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password"></label>
                    <p class="muted">Entre 8 y 72 bytes; los caracteres acentuados pueden ocupar más de un byte.</p>
                    <div class="actions"><button type="submit">Guardar contraseña</button><button type="button" class="secondary" @click="link = null; phase = 'login'; clearSecrets(); resetFeedback()">Cancelar y descartar enlace</button></div>
                </fieldset>
                <p class="muted">Si la conexión falla después de enviar, prueba iniciar sesión con la nueva contraseña antes de solicitar otro enlace.</p>
            </form>
        </template>
        <template x-if="phase === 'invalid-link'">
            <div><h1>Enlace no disponible</h1><p>El enlace está incompleto, es inválido, expiró o ya fue utilizado. Para una activación, solicita una nueva invitación al responsable de operaciones.</p><div class="actions"><button type="button" @click="phase = 'recovery'; resetFeedback()">Recuperar contraseña</button><button type="button" class="secondary" @click="phase = 'login'; resetFeedback()">Ir al acceso</button></div></div>
        </template>
        <template x-if="phase === 'challenge'">
            <form @submit.prevent="verify">
                <h1>Verificar segundo factor</h1>
                <p>La verificación vence: <span x-text="date(challenge?.expires_at)"></span>.</p>
                <fieldset :disabled="waiting">
                    <label><input type="checkbox" x-model="useRecovery" @change="auth.code = ''; auth.recovery_code = ''">Usar un código de recuperación</label>
                    <template x-if="!useRecovery"><label>Código TOTP de tu aplicación<input x-model="auth.code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" autofocus></label></template>
                    <template x-if="useRecovery"><label>Código de recuperación<input x-model="auth.recovery_code" required maxlength="64" autocomplete="off" spellcheck="false"></label></template>
                    <p x-show="useRecovery" class="notice warning">Este código es de un solo uso. Se revocarán las sesiones y deberás configurar MFA nuevamente antes de entrar.</p>
                    <div class="actions"><button type="submit">Verificar</button><button type="button" class="secondary" @click="clearSession(); resetFeedback()">Reiniciar acceso</button></div>
                </fieldset>
            </form>
        </template>
        <template x-if="phase === 'enrollment'">
            <div>
                <h1>Configurar autenticación TOTP</h1>
                <p>Agrega una cuenta en tu aplicación autenticadora mediante la clave manual: tipo TOTP, 6 dígitos, período de 30 segundos. No compartas esta clave.</p>
                <p>Vence: <span x-text="date(challenge?.expires_at)"></span>.</p>
                <button type="button" @click="enroll" :disabled="waiting" x-show="!enrollment">Obtener clave de configuración</button>
                <template x-if="enrollment">
                    <form @submit.prevent="verify">
                        <p>Clave manual</p><p class="secret" x-text="enrollment.manual_secret"></p>
                        <p class="muted">La clave se muestra únicamente en esta sesión. No se utiliza ningún servicio QR externo.</p>
                        <fieldset :disabled="waiting">
                            <label>Código TOTP generado<input x-model="auth.code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
                            <button type="submit">Confirmar configuración MFA</button>
                        </fieldset>
                    </form>
                </template>
                <p><button type="button" class="secondary" @click="clearSession(); resetFeedback()" :disabled="waiting">Cancelar y volver al acceso</button></p>
            </div>
        </template>
        <template x-if="phase === 'codes'">
            <div>
                <h1>Guarda tus códigos de recuperación</h1>
                <p class="notice warning">Se muestran una sola vez. Guárdalos en un gestor seguro antes de continuar. Cada código permite recuperar MFA una vez; los anteriores ya no son válidos.</p>
                <ul><template x-for="code in codes" :key="code"><li class="secret" x-text="code"></li></template></ul>
                <label><input type="checkbox" x-model="codesSaved">Guardé los códigos en un lugar seguro</label>
                <button type="button" @click="finishCodes" :disabled="!codesSaved">Ocultar códigos y continuar</button>
            </div>
        </template>
    </section>
</template>
