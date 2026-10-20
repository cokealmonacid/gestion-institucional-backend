<template x-if="['recovery-verification', 'recovery-verified'].includes(phase)">
    <section class="card access">
        <h1>Verificación de recuperación institucional</h1>
        <p>Verificar este correo no acredita autoridad institucional, no inicia sesión y no habilita acceso. Un operador debe completar la recuperación.</p>
        <form x-show="phase === 'recovery-verification'" @submit.prevent="verifyRecoveryAddress"><fieldset :disabled="waiting">
            <template x-if="link?.setup"><div>
                <p>Prepara tu propia contraseña como nueva persona. No se modifica la contraseña de otra cuenta.</p>
                <label>Contraseña<input type="password" x-model="auth.password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
                <label>Confirmar contraseña<input type="password" x-model="auth.password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password"></label>
            </div></template>
            <button type="submit">Verificar mi correo</button>
        </fieldset></form>
        <p x-show="phase === 'recovery-verified'">Verificación completada. Puedes cerrar esta página y avisar al operador. No reutilices el enlace.</p>
    </section>
</template>
