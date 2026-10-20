<template x-if="detail && screen === 'admins'">
    <div>
        <section class="card">
            <h1>Administradores · <span x-text="detail.name"></span></h1>
            <p>Cupos de administrador: <span x-text="detail.quotas.roles.admin.used"></span> usados de <span x-text="quota(detail.quotas.roles.admin.limit)"></span>.</p>
            <p class="notice warning" x-show="!detail.status">La institución está suspendida. Reactívala antes de invitar, reenviar o reactivar administradores.</p>
            <form @submit.prevent="run(() => loadAdmins())"><fieldset :disabled="waiting"><label>Buscar administrador por nombre o correo<input type="search" x-model="adminQuery" maxlength="100"></label><button type="submit">Buscar administradores</button></fieldset></form>
            <p x-show="!admins.items.length && !busy">No hay administradores para esta búsqueda. Puedes invitar una cuenta nueva si hay cupo.</p>
            <div class="table-wrap"><table><thead><tr><th>Nombre</th><th>Correo</th><th>Estado</th><th>Activación</th><th>Acción</th></tr></thead><tbody>
                <template x-for="user in admins.items" :key="user.user_id"><tr><td x-text="user.name"></td><td x-text="user.email"></td><td x-text="user.active ? 'Activo' : 'Inactivo'"></td><td x-text="label(user.activation_status)"></td><td><button type="button" class="secondary" @click="selectAdmin(user)" :disabled="waiting">Consultar acceso</button></td></tr></template>
            </tbody></table></div>
            <div class="actions"><button type="button" class="secondary" @click="run(() => loadAdmins(admins.page - 1))" :disabled="waiting || admins.page <= 1">Anterior</button><span>Página <span x-text="admins.page"></span> de <span x-text="admins.last_page"></span></span><button type="button" class="secondary" @click="run(() => loadAdmins(admins.page + 1))" :disabled="waiting || admins.page >= admins.last_page">Siguiente</button></div>
        </section>
        <template x-if="admin"><section class="card">
            <h2 x-text="admin.name"></h2>
            <p x-text="admin.email"></p>
            <div class="actions">
                <button type="button" @click="openRecovery(admin, 'replacement')" :disabled="waiting">Reemplazar administrador</button>
                <button type="button" @click="openRoleChange({...admin, institution_id: detail.id})" :disabled="waiting || roleUnknown">Cambiar rol / retirar administración</button>
                <button type="button" class="secondary" @click="openRoleChange({...admin, institution_id: detail.id}, 'access')" :disabled="waiting || roleUnknown" x-text="admin.active ? 'Desactivar acceso' : 'Reactivar acceso'"></button>
                <button type="button" class="danger" @click="openRecovery(admin, 'containment')" :disabled="waiting">Contener acceso individual</button>
                <button type="button" class="secondary" @click="openRecovery(admin, 'email_correction')" :disabled="waiting">Corregir correo de la misma persona</button>
            </div>
            <div class="grid access-groups">
                <section><h3>Cuenta</h3><dl class="access-fields">
                    <dt>Estado</dt><dd x-text="admin.active ? 'Activa' : 'Inactiva'"></dd>
                    <dt>Activación</dt><dd x-text="label(admin.activation_status)"></dd>
                    <dt>Administrador habilitado</dt><dd x-text="admin.usable_administrator ? 'Sí' : 'No'"></dd>
                </dl></section>
                <section><h3>Invitación</h3>
                    <p x-show="admin.invitation_id === null">Sin invitación registrada</p>
                    <dl class="access-fields" x-show="admin.invitation_id !== null">
                        <dt>Publicación</dt><dd x-text="label(admin.publication_status)"></dd>
                        <dt>Envío</dt><dd x-text="label(admin.delivery_status)"></dd>
                        <dt>Vencimiento</dt><dd x-text="date(admin.expires_at)"></dd>
                        <dt>Revocación</dt><dd x-text="admin.revoked_at ? date(admin.revoked_at) : 'No revocada'"></dd>
                    </dl>
                </section>
            </div>
            <p class="muted">La aceptación por el transporte no confirma entrega al destinatario.</p>
            <details><summary>Detalles técnicos</summary><dl class="access-fields">
                <dt>Generación</dt><dd x-text="admin.generation ?? 'No disponible'"></dd>
                <dt>Revisión de acceso</dt><dd x-text="admin.access_revision"></dd>
            </dl></details>
            <form x-show="!admin.active || admin.activation_status === 'pending_activation'" @submit.prevent="reviewAdminAction(admin.active ? 'invitation' : 'reactivation')">
                <fieldset :disabled="waiting || uncertain || !detail.status">
                    <label>Motivo de la acción<textarea x-model="actionReason" required maxlength="1000"></textarea></label>
                    <button type="submit" x-show="!admin.active">Revisar reactivación</button>
                    <button type="submit" x-show="admin.active && admin.activation_status === 'pending_activation'">Revisar reenvío de invitación</button>
                </fieldset>
            </form>
            <button type="button" class="secondary" @click="selectAdmin(admin)" :disabled="waiting">Actualizar información</button>
        </section></template>
        <section class="card notice" x-show="invitationResult">
            <h2>Cuenta creada</h2><p>Administrador: <code x-text="invitationResult?.administrator_id"></code></p>
            <p>Publicación al crear: <strong x-text="label(invitationResult?.publication_status)"></strong>. Envío: <span x-text="label(invitationResult?.delivery_status)"></span>.</p>
            <p>La cuenta existe aunque falle la publicación. Consulta su acceso y revisa un reenvío explícito si corresponde.</p>
        </section>
        <section class="card"><h2>Invitar nuevo administrador</h2>
            <p>Esta acción crea una cuenta nueva; no promueve cuentas existentes ni asigna contraseñas. La cuenta pendiente reserva un cupo.</p>
            <form @submit.prevent="reviewInvitation"><fieldset :disabled="waiting || !detail.status || Boolean(pendingStore?.value)">
                <div class="grid"><label>Nombre<input x-model="invitation.name" required maxlength="255"></label><label>Correo<input type="email" x-model="invitation.email" @input="invitationEmailError = ''" :aria-invalid="Boolean(invitationEmailError)" aria-describedby="invitation-email-error" required maxlength="255" autocomplete="off"><p id="invitation-email-error" class="notice error" x-show="invitationEmailError" x-text="invitationEmailError" role="alert"></p></label></div>
                <label>Motivo de la invitación<textarea x-model="invitation.reason" required maxlength="1000"></textarea></label>
                <button type="submit">Revisar invitación</button>
            </fieldset></form>
            <p x-show="pendingStore?.value">Resuelve la operación pendiente antes de iniciar otra invitación.</p>
        </section>
    </div>
</template>
