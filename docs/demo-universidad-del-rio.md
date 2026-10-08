# Universidad del Río demo scenario

This scenario is an explicit, opt-in demonstration load. It is not registered in `DatabaseSeeder` or any deployment hook.

## Commands

Inspect without database or object-storage writes:

```text
php artisan acervo:demo:universidad-rio --check
```

Load the scenario. Production requires the explicit `--force` option:

```text
php artisan acervo:demo:universidad-rio --force
```

The command creates data only when the scenario is entirely absent. Complete data is a no-op. Partial, modified, or conflicting data stops the command without repair or overwrite.

## Reset after a demonstration

Reset is destructive only for the document and node data owned by the institution whose stable ID is `755ab137-825b-5f22-a4e5-ab961fea03d3`. It preserves the institution, the three stable accounts, their passwords, email verification, access tokens, and roles. Additional or incompatible accounts block the operation.

First generate a read-only plan:

```text
php artisan acervo:demo:universidad-rio --reset --check
```

The plan lists affected records, preserved accounts, and exact storage keys with their cleanup eligibility. Its signed token expires after 15 minutes and becomes stale whenever the relevant database or storage inventory changes.

Execute the reviewed plan explicitly:

```text
php artisan acervo:demo:universidad-rio --reset --plan-token=<token> --force
```

Reset must run during a maintenance window with no document, node, responsibility, role, note, or download writes for this institution. The implementation locks the institution, users, nodes, documents, and versions and revalidates the plan, but not every existing write path coordinates on the institution lock. The token detects observed changes; it is not a substitute for the write blackout.

The database reconstruction is transactional. Old files are retained until the transaction commits, and files created by a failed reconstruction are compensated. After commit, only exact old keys proven to belong exclusively to the reset documents are deleted. Shared, missing, unreadable, or noncanonical keys are reported and retained.

If post-commit deletion fails, the command reports `Restablecimiento completado; limpieza incompleta`, an operation ID, and the pending keys. The reconstructed database remains authoritative; do not repeat reset merely to retry file cleanup.

## Laravel Cloud prerequisite

Before running the load in Laravel Cloud, confirm that `DOCUMENTS_FILESYSTEM_DISK` selects a persistent private disk and that its credentials are available to the command process. This repository does not verify the remote disk configuration. The read-only `--check` command can verify existing objects but intentionally does not write a probe object, so it cannot prove write permission.

The application database and object storage do not share a distributed transaction. The loader compensates files created by its own failed execution on a best-effort basis and never deletes pre-existing objects.

## Manual demonstration assets

These files are included in the deployment source but are not uploaded by the command:

- `resources/demo/universidad-del-rio/preguntas-frecuentes-admision.pdf`
- `resources/demo/universidad-del-rio/requisitos-ingreso-especial-2027-v3.pdf`

Copy them to the presenter's computer before the demonstration. They are not exposed through a public endpoint.
