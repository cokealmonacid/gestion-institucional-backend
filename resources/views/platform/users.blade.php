<template x-if="screen === 'users'">
    <section class="card">
        <h1>Usuarios institucionales</h1>
        <p>Estas cuentas pertenecen a instituciones. Los operadores de plataforma son identidades separadas.</p>
        <form @submit.prevent="run(() => loadUsers())"><fieldset :disabled="waiting">
            <div class="grid">
                <label>Nombre o correo<input type="search" x-model="userFilters.q" maxlength="100"></label>
                <label>Institución<select x-model="userFilters.institution_id"><option value="">Todas</option><template x-for="institution in userInstitutionOptions" :key="institution.id"><option :value="institution.id" x-text="institution.name"></option></template></select></label>
                <label>Rol<select x-model="userFilters.role"><option value="">Todos</option><option value="none">Sin rol</option><option value="admin">Administrador</option><option value="editor">Editor</option><option value="reader">Lector</option></select></label>
                <label>Actividad<select x-model="userFilters.active"><option value="">Todas</option><option value="true">Activa</option><option value="false">Inactiva</option></select></label>
            </div>
            <button type="submit">Buscar usuarios</button>
            <button type="button" class="secondary" @click="inviteFromUsers" :disabled="!userFilters.institution_id">Invitar administrador en la institución seleccionada</button>
        </fieldset></form>
        <div class="table-wrap"><table><thead><tr><th>Nombre / correo</th><th>Institución</th><th>Roles</th><th>Cuenta</th><th>Invitación</th><th>Acceso</th></tr></thead><tbody>
            <template x-for="user in users.items" :key="user.user_id"><tr>
                <td><span x-text="user.name"></span><br><span x-text="user.email"></span></td>
                <td x-text="user.institution_name"></td><td x-text="user.roles.length ? user.roles.map(role => label(role)).join(', ') : 'Sin rol'"></td>
                <td x-text="user.active ? 'Activa' : 'Inactiva'"></td>
                <td x-text="user.invitation ? (user.invitation.consumed_at ? 'Activación por invitación completada' : label(user.invitation.delivery_status)) : 'Sin invitación registrada'"></td>
                <td><button type="button" class="secondary" @click="openUserAccess(user)" :disabled="waiting">Consultar acceso</button>
                    <button type="button" @click="openRoleChange(user)" :disabled="waiting || roleUnknown">Cambiar rol / retirar administración</button>
                    <button type="button" class="secondary" @click="openRoleChange(user, 'access')" :disabled="waiting || roleUnknown" x-text="user.active ? 'Desactivar acceso' : 'Reactivar acceso'"></button>
                    <button type="button" x-show="user.roles.includes('admin')" @click="openRecovery(user, 'replacement')" :disabled="waiting">Reemplazar administrador</button>
                    <button type="button" class="secondary" @click="openRecovery(user, 'email_correction')" :disabled="waiting">Corregir correo</button>
                    <button type="button" class="danger" @click="openRecovery(user, 'containment')" :disabled="waiting">Contener acceso</button>
                </td>
            </tr></template>
        </tbody></table></div>
        <p x-show="!users.items.length && !busy">No hay usuarios para estos filtros.</p>
        <div class="actions"><button type="button" class="secondary" @click="run(() => loadUsers(users.page - 1))" :disabled="waiting || users.page <= 1">Anterior</button><span>Página <span x-text="users.page"></span> de <span x-text="users.last_page"></span></span><button type="button" class="secondary" @click="run(() => loadUsers(users.page + 1))" :disabled="waiting || users.page >= users.last_page">Siguiente</button></div>
    </section>
</template>
<template x-if="screen === 'user-access' && userAccess && detail">
    <section class="card">
        <h1 x-text="userAccess.name"></h1><p x-text="userAccess.email"></p>
        <p>Institución: <span x-text="detail.name"></span>. Cuenta institucional de solo lectura.</p>
        <dl class="access-fields">
            <dt>Roles</dt><dd x-text="userAccess.roles.length ? userAccess.roles.map(role => label(role)).join(', ') : 'Sin rol'"></dd>
            <dt>Estado</dt><dd x-text="userAccess.active ? 'Activa' : 'Inactiva'"></dd>
            <dt>Correo verificado</dt><dd x-text="userAccess.email_verified ? 'Sí' : 'No'"></dd>
        </dl>
        <h2>Invitación</h2>
        <p x-show="userAccess.invitation === null">Sin invitación registrada</p>
        <template x-if="userAccess.invitation"><dl class="access-fields">
            <dt>Envío</dt><dd x-text="label(userAccess.invitation.delivery_status)"></dd>
            <dt>Activación por invitación</dt><dd x-text="userAccess.invitation.consumed_at ? date(userAccess.invitation.consumed_at) : 'Sin completar'"></dd>
            <dt>Vencimiento</dt><dd x-text="date(userAccess.invitation.expires_at)"></dd>
            <dt>Revocación</dt><dd x-text="userAccess.invitation.revoked_at ? date(userAccess.invitation.revoked_at) : 'No revocada'"></dd>
        </dl></template>
        <p class="muted">La aceptación por el transporte no confirma entrega.</p>
        <button type="button" class="secondary" @click="openUserAccess(userAccess)" :disabled="waiting">Actualizar información</button>
        <button type="button" class="secondary" @click="navigate('users')" :disabled="waiting">Volver a Usuarios</button>
    </section>
</template>
