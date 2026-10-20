<section class="card" x-show="screen === 'recoveries'">
    <h1>Recuperaciones institucionales</h1>
    <p>Las operaciones se conservan en el servidor. Puedes recuperarlas tras cerrar la pestaña o con otro operador autorizado.</p>
    <form @submit.prevent="resumeRecovery()"><fieldset :disabled="waiting"><label>ID de operación<input x-model="recoveryLookup" required pattern="[a-fA-F0-9-]{36}" maxlength="36"></label><button type="submit">Consultar recuperación</button></fieldset></form>
    <template x-if="recoveryStore?.value"><div class="notice warning">
        <p>Solicitud conservada: <code x-text="recoveryStore.value.key"></code>. <span x-text="label(recoveryStore.value.status)"></span>.</p>
        <p>Un resultado ausente no autoriza una clave nueva. Consulta o reenvía la misma solicitud. El operador original debe reintentar la preparación.</p>
        <button type="button" @click="resumeRecovery(recoveryStore.value.key)" :disabled="waiting">Consultar solicitud conservada</button>
        <button type="button" @click="retryRecoveryPreparation" :disabled="waiting" x-show="['ready', 'uncertain'].includes(recoveryStore.value.status)">Reintentar misma clave y solicitud</button>
        <button type="button" class="secondary" @click="discardRejectedRecovery" :disabled="waiting" x-show="recoveryStore.value.status === 'rejected'">Cerrar rechazo definitivo para corregir</button>
    </div></template>
    <div class="table-wrap"><table><thead><tr><th>Operación</th><th>Cuenta</th><th>Propósito</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
        <template x-for="operation in recoveryRecords.items" :key="operation.id"><tr><td><code x-text="operation.id"></code></td><td x-text="operation.snapshot.target_email"></td><td x-text="recoveryLabel(operation.kind)"></td><td x-text="recoveryLabel(operation.state)"></td><td><button type="button" @click="resumeRecovery(operation.id)" :disabled="waiting">Abrir recuperación</button></td></tr></template>
    </tbody></table></div>
    <div class="actions"><button type="button" class="secondary" @click="run(() => loadRecoveries(recoveryRecords.page - 1))" :disabled="waiting || recoveryRecords.page <= 1">Anterior</button><span>Página <span x-text="recoveryRecords.page"></span></span><button type="button" class="secondary" @click="run(() => loadRecoveries(recoveryRecords.page + 1))" :disabled="waiting || recoveryRecords.page >= recoveryRecords.last_page">Siguiente</button></div>
</section>
<template x-if="screen === 'recovery-edit' && recoveryTarget && detail">
    <section class="card">
        <h1 x-text="recoveryLabel(recoveryKind)"></h1>
        <p><strong x-text="recoveryTarget.name"></strong> · <span x-text="recoveryTarget.email"></span> · <span x-text="detail.name"></span>.</p>
        <p class="notice warning" x-show="!detail.status">La institución está suspendida. Se puede preparar y completar la recuperación, pero permanecerá inaccesible hasta una reactivación explícita posterior.</p>
        <p x-show="recoveryKind === 'replacement'">La persona saliente quedará inactiva. Conserva identidad, roles, historial y responsabilidades. El sucesor queda únicamente como administrador al completar; preparar no modifica su acceso actual ni reserva un cupo activo.</p>
        <p class="notice warning" x-show="recoveryKind === 'containment'">Se desactiva esta cuenta y se revocan sus accesos. Si es el único administrador habilitado, la institución quedará temporalmente sin administración. No es una recuperación completada.</p>
        <p x-show="recoveryKind === 'email_correction'">Solo para la misma persona. Se conserva el correo actual hasta verificar y completar; no cambia contraseña, actividad, roles ni institución. Para otra persona usa reemplazo.</p>
        <form @submit.prevent="reviewRecovery"><fieldset :disabled="waiting || Boolean(recoveryStore?.value)">
            <template x-if="recoveryKind === 'replacement'"><div>
                <label>Sucesor de esta institución<select x-model="recoveryForm.successor_id"><option value="">Nueva persona</option><template x-for="candidate in recoveryCandidates" :key="candidate.user_id"><option :value="candidate.user_id" x-text="candidate.name + ' · ' + candidate.email"></option></template></select></label>
                <template x-if="!recoveryForm.successor_id"><div class="grid"><label>Nombre de la nueva persona<input x-model="recoveryForm.successor_name" required maxlength="255"></label><label>Correo propio del sucesor<input type="email" x-model="recoveryForm.successor_email" required maxlength="255"></label></div></template>
            </div></template>
            <template x-if="recoveryKind === 'email_correction'"><div><label>Nuevo correo de la misma persona<input type="email" x-model="recoveryForm.new_email" required maxlength="255"></label><label><input type="checkbox" x-model="recoveryForm.same_person" required>Verifiqué que es la misma persona; no estoy transfiriendo su identidad.</label></div></template>
            <h2>Autoridad institucional verificada</h2>
            <p>El control del correo del sucesor no basta. Registra la comprobación de la autoridad de quien solicita la recuperación. No adjuntes credenciales.</p>
            <div class="grid"><label>Solicitante<input x-model="recoveryForm.authority.requester" required maxlength="255"></label><label>Relación con la institución<input x-model="recoveryForm.authority.relationship" required maxlength="255"></label><label>Canal de verificación<input x-model="recoveryForm.authority.channel" required maxlength="255"></label><label>Referencia de verificación<input x-model="recoveryForm.authority.reference" required maxlength="500"></label></div>
            <label>Motivo<textarea x-model="recoveryForm.reason" required maxlength="1000"></textarea></label>
            <label><input type="checkbox" x-model="recoveryForm.confirmation" required>Confirmo la autoridad comprobada y las consecuencias descritas.</label>
            <button type="submit">Revisar recuperación</button>
        </fieldset></form>
        <p x-show="recoveryStore?.value">Resuelve la preparación conservada en Recuperaciones antes de preparar otra.</p>
    </section>
</template>
<template x-if="screen === 'recovery-operation' && recoveryOperation">
    <section class="card">
        <h1 x-text="recoveryLabel(recoveryOperation.kind)"></h1>
        <p>Operación: <code x-text="recoveryOperation.id"></code>. <strong x-text="recoveryLabel(recoveryOperation.state)"></strong>.</p>
        <dl class="access-fields"><dt>Cuenta anterior</dt><dd x-text="recoveryOperation.snapshot.target_name + ' · ' + recoveryOperation.snapshot.target_email"></dd><dt>Correo verificado / sucesor</dt><dd x-text="recoveryOperation.snapshot.successor_email || 'No cambia'"></dd><dt>Publicación</dt><dd x-text="recoveryLabel(recoveryOperation.publication_status)"></dd><dt>Verificación</dt><dd x-text="date(recoveryOperation.verified_at)"></dd><dt>Plazo máximo</dt><dd x-text="date(recoveryOperation.expires_at)"></dd></dl>
        <p>La cola y la aceptación del transporte no confirman entrega ni verificación. Verificar no concede acceso; completar no inicia sesión ni reactiva una institución suspendida.</p>
        <h2>Cupos: antes y después propuesto</h2>
        <p>Proyección según el estado leído al preparar. El servidor vuelve a comprobar revisiones y cupos al completar; un exceso mayor impide el cambio.</p>
        <div class="table-wrap"><table><thead><tr><th>Rol</th><th>Antes</th><th>Después</th><th>Límite</th><th>Exceso final</th></tr></thead><tbody><template x-for="row in recoveryQuotaPreview()" :key="row.role"><tr><td x-text="label(row.role)"></td><td x-text="row.before"></td><td x-text="row.after"></td><td x-text="quota(row.limit)"></td><td x-text="row.blocked ? row.excess + ' · Aumentaría el exceso; bloqueado' : row.excess"></td></tr></template></tbody></table></div>
        <p x-text="recoveryOperation.snapshot.institution_suspended ? 'La institución estaba suspendida al preparar. Esta recuperación no la reactiva.' : 'La recuperación no modifica el estado operativo institucional.'"></p>
        <template x-if="recoveryOperation.result"><div class="notice"><h2>Resultado confirmado</h2>
            <p x-show="recoveryOperation.kind === 'replacement'">El sucesor está habilitado como administrador y el saliente está inactivo. No se inició sesión; se conservaron las responsabilidades documentales.</p>
            <p x-show="recoveryOperation.kind === 'email_correction'">Se corrigió el correo de la misma persona, conservando contraseña, actividad y roles.</p>
            <p x-show="recoveryOperation.kind === 'containment'">La cuenta está inactiva y sus accesos fueron revocados. La contención no sustituye una recuperación.</p>
            <p x-show="recoveryOperation.result.institution_suspended">La institución permanece suspendida e inaccesible.</p>
            <details><summary>Identificadores y resultado técnico</summary><pre x-text="json(recoveryOperation.result)"></pre></details>
        </div></template>
        <p class="notice warning" x-show="recoveryOperation.result?.recovery_required">La institución no tiene administrador técnicamente habilitado. Inicia un reemplazo para recuperar el control.</p>
        <button type="button" x-show="recoveryOperation.kind === 'containment' && recoveryOperation.state === 'completed' && recoveryOperation.snapshot.target_roles.includes('admin')" @click="openRecovery({user_id: recoveryOperation.target_id, institution_id: recoveryOperation.institution_id}, 'replacement')" :disabled="waiting">Preparar reemplazo del administrador</button>
        <p x-show="recoveryOperation.kind === 'email_correction' && recoveryOperation.state === 'completed' && recoveryOperation.result?.recovery_eligible">La misma persona puede abrir <code>/olvide-mi-contrasena</code> en el frontend institucional y solicitar la recuperación con el correo corregido. Ese formulario utiliza el flujo institucional existente; no se envía nada automáticamente desde esta operación.</p>
        <p x-show="recoveryOperation.kind === 'email_correction' && recoveryOperation.state === 'completed' && !recoveryOperation.result?.recovery_eligible">La recuperación de contraseña requiere cuenta activa e institución habilitada. Corregir el correo no modifica esos estados.</p>
        <p class="notice warning" x-show="recoveryUnknown">Resultado incierto o revisión desactualizada. Actualiza la operación antes de decidir. No prepares otra con una clave nueva.</p>
        <button type="button" class="secondary" @click="resumeRecovery(recoveryOperation.id)" :disabled="waiting">Actualizar información</button>
        <button type="button" @click="reviewRecoveryTransition('complete')" x-show="recoveryOperation.state === 'verified'" :disabled="waiting || recoveryUnknown">Revisar finalización</button>
        <form x-show="['awaiting_verification', 'verified', 'expired'].includes(recoveryOperation.state)" @submit.prevent="reviewRecoveryTransition('cancel')"><fieldset :disabled="waiting || recoveryUnknown">
            <label>Motivo de cancelación o reenvío<textarea x-model="recoveryReason" required maxlength="1000"></textarea></label>
            <button type="submit" class="secondary">Revisar cancelación</button>
            <button type="button" class="secondary" @click="recoveryReason.trim() && reviewRecoveryTransition('resend')" x-show="recoveryOperation.state !== 'expired'">Revisar reenvío de verificación</button>
        </fieldset></form>
        <p x-show="['expired', 'cancelled'].includes(recoveryOperation.state)">Esta operación no permite cambios de cuenta. Puedes preparar otra tras revisar el estado actual; si hubo una respuesta perdida, consulta primero esta operación.</p>
    </section>
</template>
