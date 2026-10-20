<template x-if="screen === 'role-change' && roleUser?.roles && detail">
    <section class="card">
        <h1 x-text="roleAction === 'role' ? 'Cambiar rol / retirar administración' : (roleUser.active ? 'Desactivar acceso' : 'Reactivar acceso')"></h1>
        <p><span x-text="roleUser.name"></span> · <span x-text="roleUser.email"></span> · <span x-text="detail.name"></span></p>
        <p>Roles actuales: <span x-text="roleUser.roles.join(', ') || 'Sin rol'"></span>. Estado: <span x-text="roleUser.active ? 'Activa' : 'Inactiva'"></span>.</p>
        <p x-show="roleAction === 'role'">El rol elegido reemplaza todos los roles institucionales actuales. Retirar administración requiere elegir editor o lector. La actividad, identidad, contraseña y verificación se conservan. Se revocan las sesiones y enlaces anteriores al cambiar el rol.</p>
        <p x-show="roleAction === 'access'">Se conservan los roles y responsabilidades. Desactivar revoca el acceso; reactivar exige cupo y no verifica el correo. Esta acción ordinaria protege al último administrador utilizable; para un incidente excepcional usa Contener acceso.</p>
        <p x-show="!detail.status">La institución está suspendida: estos cambios ordinarios están bloqueados.</p>
        <table><thead><tr><th>Rol</th><th>Uso actual</th><th>Uso previsto</th><th>Límite</th></tr></thead><tbody><template x-for="row in roleQuotaPreview()" :key="row.role"><tr><td x-text="label(row.role)"></td><td x-text="row.before"></td><td x-text="row.after + (row.blocked ? ' · Sin cupo' : '')"></td><td x-text="row.limit ?? 'Sin límite'"></td></tr></template></tbody></table>
        <p class="muted">La confirmación vuelve a comprobar cupos y último administrador utilizable bajo bloqueo.</p>
        <p class="notice" x-show="roleResult" x-text="roleResult"></p>
        <p class="error" x-show="roleUnknown">Resultado incierto. No repitas la solicitud. Consulta el estado actual y la auditoría.</p>
        <button x-show="roleUnknown" @click="resolveRoleUnknown" :disabled="waiting">Consultar resultado actual</button>
        <form @submit.prevent="submitRoleChange"><fieldset :disabled="waiting || roleUnknown || !detail.status">
            <template x-if="roleAction === 'role'"><div>
                <label>Único rol de destino<select x-model="roleDestination" @change="roleImpact = null; roleConfirmation = false" required><option value="">Seleccionar</option><option value="admin">Administrador</option><option value="editor">Editor</option><option value="reader">Lector</option></select></label>
                <button type="button" @click="inspectRoleImpact" :disabled="!roleDestination">Revisar impacto</button>
                <p x-show="roleImpact">Documentos que quedarán sin responsable: <span x-text="roleImpact?.affected_documents_count"></span>. No se muestra contenido documental.</p>
            </div></template>
            <label>Motivo<textarea x-model="roleReason" required maxlength="1000"></textarea></label>
            <label><input type="checkbox" x-model="roleConfirmation" required> Confirmo el cambio descrito y la eliminación de responsabilidades indicada en la revisión, si corresponde.</label>
            <button type="submit" :disabled="!roleConfirmation || (roleAction === 'role' && !roleImpact)">Aplicar cambio</button>
        </fieldset></form>
        <button class="secondary" @click="navigate('users')" :disabled="waiting || roleUnknown">Volver a Usuarios</button>
    </section>
</template>
