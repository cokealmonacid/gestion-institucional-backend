<section class="card" x-show="screen === 'plans'">
    <h1>Planes de catálogo</h1><p>Editar el catálogo no cambia los convenios ya acordados.</p>
    <form @submit.prevent="run(() => loadPlans())"><fieldset :disabled="waiting"><div class="grid">
        <label>Buscar por nombre<input type="search" x-model="planFilters.q" maxlength="100"></label>
        <label>Disponibilidad<select x-model="planFilters.available"><option value="">Todos</option><option value="true">Disponibles para nuevos convenios</option><option value="false">Retirados</option></select></label>
    </div><div class="actions"><button type="submit">Buscar planes</button><button type="button" class="secondary" @click="editPlan()">Crear plan</button></div></fieldset></form>
    <p x-show="!plans.items.length && !busy">No hay planes para estos filtros.</p>
    <div class="table-wrap"><table><caption>Planes encontrados: <span x-text="plans.total"></span></caption><thead><tr><th>Plan</th><th>Disponibilidad</th><th>Administradores</th><th>Editores</th><th>Lectores</th><th>Revisión</th><th>Acción</th></tr></thead><tbody>
        <template x-for="plan in plans.items" :key="plan.id"><tr><td><strong x-text="plan.name"></strong><br><small x-text="plan.code"></small></td><td x-text="plan.available_for_new_contracts ? 'Disponible' : 'Retirado'"></td><td x-text="quota(plan.limits.admin)"></td><td x-text="quota(plan.limits.editor)"></td><td x-text="quota(plan.limits.reader)"></td><td x-text="plan.revision"></td><td><button type="button" class="secondary" @click="editPlan(plan)" :disabled="waiting" :aria-label="'Editar plan ' + plan.name">Editar</button></td></tr></template>
    </tbody></table></div>
    <div class="actions"><button type="button" class="secondary" @click="run(() => loadPlans(plans.page - 1))" :disabled="waiting || plans.page <= 1">Anterior</button><span>Página <span x-text="plans.page"></span> de <span x-text="plans.last_page"></span></span><button type="button" class="secondary" @click="run(() => loadPlans(plans.page + 1))" :disabled="waiting || plans.page >= plans.last_page">Siguiente</button></div>
</section>
<section class="card" x-show="screen === 'plan-edit'">
    <h1 x-text="planForm.id ? 'Editar plan' : 'Crear plan'"></h1>
    <form @submit.prevent="reviewPlan"><fieldset :disabled="waiting || uncertain">
        <div class="grid"><label>Código<input x-model="planForm.code" required pattern="[a-z0-9][a-z0-9_-]{0,63}" maxlength="64" :readonly="Boolean(planForm.id)"></label><label>Nombre del plan<input x-model="planForm.name" required maxlength="255"></label></div>
        <label>Descripción<textarea x-model="planForm.description" required maxlength="5000"></textarea></label>
        <label><input type="checkbox" x-model="planForm.available_for_new_contracts">Disponible para nuevos convenios</label>
        <p>Un límite vacío significa sin límite; cero significa sin cupos.</p>
        <div class="grid"><template x-for="role in roles" :key="role"><label><span x-text="label(role)"></span><input type="number" x-model="planForm.limits[role]" min="0" max="2147483647" step="1" placeholder="Sin límite"></label></template></div>
        <label x-show="planForm.id">Motivo del cambio<textarea x-model="planForm.reason" maxlength="1000" :required="Boolean(planForm.id)"></textarea></label>
        <button type="submit">Revisar plan</button>
    </fieldset></form>
    <p class="notice warning" x-show="uncertain">Revisa el estado actual antes de volver a guardar.</p>
    <button type="button" class="secondary" @click="refreshPlan" :disabled="waiting">Consultar versión actual</button>
    <template x-if="planCurrent"><div><h2>Registro actual para comparar</h2><pre x-text="json(planCurrent)"></pre></div></template>
</section>
<section class="card" x-show="screen === 'create'">
    <h1>Crear institución</h1>
    <p>Se crearán de forma atómica la institución, su convenio y la cuenta del administrador inicial. La invitación se publica después de confirmar los recursos.</p>
    <p class="notice warning" x-show="pendingStore?.value">Ya hay una operación en esta pestaña. Resuélvela con el operador original antes de crear otra.</p>
    <form @submit.prevent="reviewCreation"><fieldset :disabled="waiting || Boolean(pendingStore?.value)">
        <h2>Institución y cliente</h2>
        <label>Nombre de institución<input x-model="creation.name" required maxlength="255"></label>
        <div class="grid">@include('platform.customer-fields', ['model' => 'creation.customer'])</div>
        <h2>Administrador inicial</h2><div class="grid"><label>Nombre<input x-model="creation.admin_name" required maxlength="255"></label><label>Correo<input type="email" x-model="creation.admin_email" required maxlength="255" autocomplete="off"></label></div>
        <h2>Condiciones acordadas</h2>
        @include('platform.agreement-fields')
        <button type="submit">Revisar institución y condiciones</button>
    </fieldset></form>
    <button type="button" class="secondary" @click="run(() => loadPlanOptions())" :disabled="waiting">Recargar planes disponibles</button>
    <p x-show="!planOptions.length && !busy">No hay planes disponibles. Crea o habilita un plan antes de continuar.</p>
</section>
<template x-if="detail && screen === 'agreement'">
    <section class="card">
        <h1>Enmienda de convenio · <span x-text="detail.name"></span></h1>
        <p>Se creará una revisión inmutable de efecto inmediato. El historial existente se conserva. La fecha de vigencia comercial no programa el cambio de cupos.</p>
        <p>Revisión actual del convenio: <span x-text="detail.current_contract?.revision || 0"></span>.</p>
        <form @submit.prevent="reviewAgreement"><fieldset :disabled="waiting || uncertain">
            @include('platform.agreement-fields')
            <button type="submit">Revisar enmienda y excesos</button>
        </fieldset></form>
        <p class="notice warning" x-show="uncertain">Actualiza el convenio actual y compara sus condiciones antes de intentar otra enmienda.</p>
        <button type="button" class="secondary" @click="refreshAgreement" :disabled="waiting">Actualizar revisión conservando propuesta</button>
        <button type="button" class="secondary" @click="openInstitution(detail.id)" :disabled="waiting">Volver al convenio y actualizar</button>
    </section>
</template>
<section class="card" x-show="screen === 'history'">
    <h1>Historial inmutable del convenio</h1>
    <p x-show="!history.items.length && !busy">No hay revisiones registradas.</p>
    <template x-for="item in history.items" :key="item.id"><article class="card">
        <h2>Revisión <span x-text="item.revision"></span></h2><p>Aplicada: <span x-text="date(item.effective_at)"></span>. <span x-text="label(item.validity)"></span>.</p>
        <p><strong x-text="item.conditions.source_plan.name"></strong> · <span x-text="item.conditions.price_clp"></span> CLP · <span x-text="label(item.conditions.periodicity)"></span></p>
        <p>Motivo: <span x-text="item.conditions.reason"></span></p>
        <details><summary>Ver instantánea completa de condiciones</summary><pre x-text="json(item.conditions)"></pre></details>
    </article></template>
    <button type="button" class="secondary" @click="moreHistory" x-show="history.next_cursor" :disabled="waiting">Cargar revisiones anteriores</button>
</section>
