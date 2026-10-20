<template x-if="pending && screen === 'pending'">
    <section class="card">
        <h1>Resultado de creación</h1>
        <p>Tipo: <span x-text="pending.type === 'institution_onboarding' ? 'Institución y administrador inicial' : 'Invitación de administrador'"></span>.</p>
        <p>Clave de operación: <code x-text="pending.key"></code></p>
        <p><strong x-text="label(pending.status)"></strong></p>
        <p class="notice warning" x-show="pending.status === 'uncertain'">Conservamos la clave y la solicitud en esta pestaña, incluso si necesitas iniciar sesión otra vez. No repitas la creación con una clave nueva ante un resultado incierto. El mismo operador debe resolverla. No cierres la pestaña hasta terminar.</p>
        <p x-show="pending.status === 'rejected'">La solicitud fue rechazada sin confirmar recursos nuevos. Puedes corregir los datos antes de revisar otra solicitud.</p>
        <button type="button" x-show="pending.status === 'rejected' && pending.type === 'administrator_invitation'" @click="correctRejectedInvitation" :disabled="waiting">Corregir invitación</button>
        <details><summary>Revisar la solicitud conservada</summary><pre x-text="json(pending.body)"></pre></details>
        <template x-if="pending.result"><div>
            <h2>Recursos confirmados</h2>
            <p>Institución: <code x-text="pending.result.institution_id"></code></p>
            <p>Administrador: <code x-text="pending.result.initial_admin_id || pending.result.administrator_id"></code></p>
            <p>ID de invitación: <code x-text="pending.result.invitation_id"></code></p>
            <p>Publicación: <strong x-text="label(pending.result.publication_status)"></strong></p>
            <p>Transporte: <span x-text="label(pending.result.delivery_status)"></span></p>
            <p>Activación al crear: <span x-text="label(pending.result.activation_status)"></span></p>
            <p class="notice warning" x-show="pending.result.publication_status !== 'queued'">Los recursos ya existen aunque la publicación haya fallado o sea desconocida. Consulta el acceso del administrador y usa un reenvío explícito si corresponde.</p>
            <p>Este es el resultado de la operación original. El estado actual se consulta en los accesos de la institución. La aceptación por el transporte no confirma entrega.</p>
            <button type="button" @click="openInstitution(pending.result.institution_id)" :disabled="waiting">Abrir institución confirmada</button>
        </div></template>
        <div class="actions">
            <button type="button" @click="reconcile" :disabled="waiting">Consultar operación por su clave</button>
            <button type="button" class="secondary" @click="retryPending" :disabled="waiting" x-show="['ready', 'uncertain'].includes(pending.status)">Reenviar misma solicitud y misma clave</button>
            <button type="button" class="secondary" @click="dismissPending" :disabled="waiting" x-show="['committed', 'rejected', 'ready'].includes(pending.status)">Cerrar resultado y descartar borrador</button>
        </div>
    </section>
</template>
