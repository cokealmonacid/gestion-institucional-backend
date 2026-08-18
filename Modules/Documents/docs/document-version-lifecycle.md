# Document Version Lifecycle

## Versioning

`Document` is the logical document entity. `DocumentVersion` represents a private physical file and its version history. Version numbers are positive sequential integers scoped to a document.

The database protects uniqueness of `(document_id, version_number)`. Uploads lock the logical document, compute the next number, store the file privately, and compensate by deleting the stored file if the database transaction fails.

## Current version

A new version automatically becomes current. The nullable `current_marker` is the portable database invariant: `1` identifies the current row and `NULL` identifies historical rows. A unique `(document_id, current_marker)` index permits multiple historical rows while allowing at most one current row. `is_current` remains a compatibility mirror and public responses derive current state from `current_marker`.

The integrity migration preserves every positive, integral, unique historical number, including gaps. For duplicates, the oldest row by creation time and ID preserves the number. Duplicate followers and rows with null, zero, negative, or non-integral numbers receive deterministic numbers after the greatest existing positive number, ordered by creation time and ID. No UUID, relationship, version, or file is deleted. When multiple active rows were marked current, the greatest version number, creation time, and ID remains current. A document with no versions, or without an active legacy current version, legitimately has no current version.

`current_marker` is generated from `is_current`, its unique document-scoped index permits at most one current version, and a persistent database constraint requires every current version to be active. Rolling the migration back removes these constraints and the generated column, but cannot reconstruct repaired legacy numbers.

`PATCH /api/v1/documents/{document_id}/versions/{version_id}/current` lets an admin or editor make an active historical version current.

## Legacy activation operations

Legacy activate/deactivate operations remain outside the canonical lifecycle contract and are not expanded by this increment.

## Institutional snapshot and storage

`DocumentVersion` copies `institution_id` and `node_id` from `Document` when it is created. The legacy `url` column stores an internal Laravel filesystem path/key, never a public URL and never part of the API resource.

Files use private multi-tenant storage:

```text
institutions/{institution_id}/documents/{document_id}/versions/{version_id}/{filename}
```

Download records mean the backend authorized the request and emitted the download response; they do not prove transfer completion.

Storage and required download-record failures return controlled API errors. File deletion after a database failure is best-effort because an unavailable external storage service can leave an orphan that requires operational cleanup. A file can also disappear after the existence check and before filesystem streaming begins; the download record describes response emission, not completed transfer.
