<section class="card" x-show="screen === 'audit'">
    <h1 x-text="auditInstitution ? 'Auditoría institucional' : 'Auditoría de plataforma'"></h1>
    <p>Historial administrativo. Las fechas se muestran en horario de Chile. No incluye acceso al contenido documental.</p>
    <p x-show="auditInstitution">Institución: <span x-text="detail?.name"></span>.</p>
    <form @submit.prevent="run(() => loadAudit())"><fieldset :disabled="waiting"><div class="grid">
        <label>Acción exacta (opcional)<input x-model="auditFilters.action" maxlength="100" placeholder="institution.metadata_updated"></label>
        <label>Tipo de recurso (opcional)<input x-model="auditFilters.subject_kind" maxlength="100" placeholder="institution"></label>
        <label>ID del recurso (opcional)<input x-model="auditFilters.subject_id" pattern="[a-fA-F0-9-]{36}" maxlength="36" placeholder="UUID"></label>
    </div><button type="submit">Aplicar filtros</button></fieldset></form>
    <p x-show="!audit.items.length && !busy">No hay eventos para estos filtros.</p>
    <template x-for="event in audit.items" :key="event.id"><article class="card">
        <h2 x-text="label(event.action)"></h2>
        <p class="muted"><small>Código de acción para filtros: <span x-text="event.action"></span></small></p>
        <p><strong x-text="event.actor_name"></strong> · <span x-text="event.actor_kind"></span> · <time x-text="date(event.occurred_at)"></time></p>
        <p>Recurso: <span x-text="event.subject_kind"></span> · <span x-text="event.subject_id"></span>.</p>
        <p>Motivo: <span x-text="event.reason || 'Sin motivo adicional'"></span></p>
        <details><summary>Ver valores anteriores y posteriores</summary><div class="grid"><div><h3>Antes</h3><pre x-text="json(event.before_values)"></pre></div><div><h3>Después</h3><pre x-text="json(event.after_values)"></pre></div></div><p>ID de operación: <span x-text="event.operation_id || 'No aplica'"></span></p></details>
    </article></template>
    <button type="button" class="secondary" @click="run(() => loadAudit(audit.next_cursor))" x-show="audit.next_cursor" :disabled="waiting">Cargar eventos anteriores</button>
</section>
