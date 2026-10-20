<section class="card" x-show="screen === 'institutions'">
    <h1>Instituciones</h1>
    <form @submit.prevent="run(() => loadInstitutions())">
        <fieldset :disabled="waiting"><div class="grid">
            <label>Buscar por nombre<input type="search" x-model="filters.q" maxlength="100"></label>
            <label>Estado operativo<select x-model="filters.status"><option value="">Todos</option><option value="true">Activa</option><option value="false">Suspendida</option></select></label>
            <label>Estado comercial<select x-model="filters.commercial_state"><option value="">Todos</option><option value="configured">Configurada</option><option value="legacy_unconfigured">Sin convenio configurado</option></select></label>
        </div><button type="submit">Buscar</button></fieldset>
    </form>
    <p x-show="institutions.items.length === 0 && !busy">No hay instituciones para estos filtros.</p>
    <div class="table-wrap" x-show="institutions.items.length"><table>
        <caption class="muted">Instituciones encontradas: <span x-text="institutions.total"></span></caption>
        <thead><tr><th>Institución</th><th>Estado operativo</th><th>Estado comercial</th><th>Cliente</th><th>Acción</th></tr></thead>
        <tbody><template x-for="item in institutions.items" :key="item.id"><tr>
            <td x-text="item.name"></td><td x-text="item.status ? 'Activa' : 'Suspendida'"></td><td x-text="label(item.commercial_state)"></td><td x-text="item.customer.name || 'Sin configurar'"></td>
            <td><button type="button" class="secondary" @click="openInstitution(item.id)" :disabled="waiting" :aria-label="'Ver institución ' + item.name">Ver detalle</button></td>
        </tr></template></tbody>
    </table></div>
    <div class="actions"><button type="button" class="secondary" @click="run(() => loadInstitutions(institutions.page - 1))" :disabled="waiting || institutions.page <= 1">Anterior</button><span>Página <span x-text="institutions.page"></span> de <span x-text="institutions.last_page"></span></span><button type="button" class="secondary" @click="run(() => loadInstitutions(institutions.page + 1))" :disabled="waiting || institutions.page >= institutions.last_page">Siguiente</button></div>
</section>
<template x-if="detail && screen === 'detail'">
    <div>
        <section class="card">
            <h1 x-text="detail.name"></h1>
            <p><span class="badge" x-text="detail.status ? 'Activa' : 'Suspendida'"></span> <span class="badge" x-text="label(detail.commercial_state)"></span></p>
            <p>Cliente actual: <strong x-text="detail.customer.name || 'Sin configurar'"></strong> · <span x-text="label(detail.customer.type)"></span> · RUT: <span x-text="detail.customer.rut || 'Sin configurar'"></span></p>
            <p class="muted">ID: <span x-text="detail.id"></span> · Revisión operativa: <span x-text="detail.operational_revision"></span></p>
            <template x-for="warning in detail.warnings" :key="warning"><p class="notice warning" x-text="label(warning)"></p></template>
            <h2>Uso real por rol</h2>
            <p>Las cuentas activas pendientes de activación también reservan cupo. Un usuario con varios roles cuenta en cada rol.</p>
            <div class="table-wrap"><table><thead><tr><th>Rol</th><th>Uso</th><th>Límite</th><th>Exceso</th></tr></thead><tbody>
                <template x-for="role in roles" :key="role"><tr><td x-text="label(role)"></td><td x-text="detail.quotas.roles[role].used"></td><td x-text="quota(detail.quotas.roles[role].limit)"></td><td x-text="detail.quotas.roles[role].over_by"></td></tr></template>
            </tbody></table></div>
            <p class="muted">Calculado: <span x-text="date(detail.quotas.computed_at)"></span>.</p>
            <div class="actions"><button type="button" class="secondary" @click="openAdmins" :disabled="waiting">Administradores y accesos</button><button type="button" class="secondary" @click="openHistory" :disabled="waiting">Historial del convenio</button><button type="button" class="secondary" @click="institutionAudit" :disabled="waiting">Auditoría institucional</button><button type="button" class="secondary" @click="refreshDetail" :disabled="waiting">Actualizar estado y revisión</button></div>
        </section>
        <section class="card">
            <h2>Convenio actual</h2>
            <template x-if="detail.current_contract"><div>
                <p>Revisión <span x-text="detail.current_contract.revision"></span> · <span x-text="label(detail.current_contract.validity)"></span></p>
                <p><strong x-text="detail.current_contract.conditions.source_plan.name"></strong> · Origen de catálogo: revisión <span x-text="detail.current_contract.conditions.source_plan.revision"></span>.</p>
                <p><span x-text="detail.current_contract.conditions.price_clp"></span> CLP · <span x-text="label(detail.current_contract.conditions.tax_treatment)"></span> · <span x-text="label(detail.current_contract.conditions.periodicity)"></span>.</p>
                <p><span x-text="label(detail.current_contract.conditions.agreement_kind)"></span> · <span x-text="label(detail.current_contract.conditions.lifecycle)"></span> · Desde <span x-text="detail.current_contract.conditions.starts_on"></span> hasta <span x-text="detail.current_contract.conditions.ends_on || 'sin fecha de término'"></span>.</p>
                <p>El estado de pago no se registra en este sistema.</p>
                <h3>Excepciones acordadas</h3><p x-show="!detail.current_contract.conditions.exceptions.length">Sin excepciones.</p>
                <template x-for="(item, index) in detail.current_contract.conditions.exceptions" :key="index"><p><strong x-text="label(item.condition)"></strong>: <span x-text="item.condition.startsWith('limits.') ? quota(item.base_value) : 'Sin valor base de catálogo'"></span> → <span x-text="item.condition.startsWith('limits.') ? quota(item.agreed_value) : label(item.agreed_value)"></span>. <span x-text="item.reason"></span></p></template>
            </div></template>
            <p x-show="!detail.current_contract">Esta institución aún no tiene un convenio configurado.</p>
            <button type="button" @click="openAgreement" :disabled="waiting">Crear enmienda o configurar convenio</button>
        </section>
        <div class="grid">
            <section class="card"><h2>Editar metadatos</h2><form @submit.prevent="reviewMetadata">
                <fieldset :disabled="waiting || uncertain">
                    <label>Nombre de institución<input x-model="metadata.name" required maxlength="255"></label>
                    @include('platform.customer-fields', ['model' => 'metadata.customer'])
                    <label>Motivo de la edición<textarea x-model="metadata.reason" required maxlength="1000"></textarea></label>
                    <button type="submit">Revisar edición</button>
                </fieldset>
            </form></section>
            <section class="card"><h2 x-text="detail.status ? 'Suspender institución' : 'Reactivar institución'"></h2>
                <p>La transición revoca credenciales y sesiones institucionales. Siempre registra un motivo.</p>
                <form @submit.prevent="reviewStatus"><fieldset :disabled="waiting || uncertain">
                    <label>Motivo de la transición<textarea x-model="actionReason" required maxlength="1000"></textarea></label>
                    <button type="submit" :class="detail.status ? 'danger' : ''" x-text="detail.status ? 'Revisar suspensión' : 'Revisar reactivación'"></button>
                </fieldset></form>
            </section>
        </div>
        <p class="notice warning" x-show="uncertain">Actualiza el estado y la revisión antes de volver a guardar. El formulario conservará tus datos para compararlos.</p>
    </div>
</template>
