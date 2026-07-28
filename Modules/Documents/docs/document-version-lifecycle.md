# Document Version Lifecycle

## Versionado

`Document` representa la entidad logica documental. `DocumentVersion` representa el archivo fisico asociado a esa entidad y su historial de versiones.

Un documento puede tener muchas versiones. El numero de version se asigna automaticamente como un entero incremental dentro del documento.

## Version vigente

`is_current` indica la version vigente del documento.

Para el MVP, cada nueva version queda vigente automaticamente:

- la nueva version queda con `is_current = true`;
- las versiones anteriores del mismo documento quedan con `is_current = false`.

El endpoint manual `PATCH /api/v1/documents/{document_id}/versions/{version_id}/current` permite marcar una version activa existente como vigente y desmarca todas las demas versiones del mismo documento.

Un documento puede quedar temporalmente sin version vigente si se desactiva la version vigente. Este PR no reasigna automaticamente otra version.

## Desactivacion logica

`active` indica si la version esta habilitada.

`DELETE /api/v1/documents/{document_id}/versions/{version_id}` no elimina fisicamente la version ni el archivo. Solo cambia `active = false`.

`PATCH /api/v1/documents/{document_id}/versions/{version_id}/activate` vuelve a dejar `active = true`, sin marcarla automaticamente como vigente.

## Snapshot institucional

`DocumentVersion` copia `institution_id` y `node_id` desde `Document` al momento de crearse. Estos campos funcionan como snapshot historico y facilitan el scope multi-tenant.

## Storage

El campo legacy `url` se usa temporalmente para almacenar el path/key interno entregado por el filesystem de Laravel. No debe interpretarse como URL publica.

El backend debe poder cambiar el disk local por storage externo sin modificar el contrato API.

Los archivos se guardan con estructura multi-tenant:

```txt
institutions/{institution_id}/documents/{document_id}/versions/{version_id}/{filename}
```

## Aprobacion futura

El flujo de aprobacion no esta implementado en este PR.

Cuando exista aprobacion, `approval_status` debe ser un estado separado de `is_current`. Una version vigente no necesariamente equivale a aprobada, salvo que una regla futura lo defina explicitamente.
