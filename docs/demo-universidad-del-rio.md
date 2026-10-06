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

## Laravel Cloud prerequisite

Before running the load in Laravel Cloud, confirm that `DOCUMENTS_FILESYSTEM_DISK` selects a persistent private disk and that its credentials are available to the command process. This repository does not verify the remote disk configuration. The read-only `--check` command can verify existing objects but intentionally does not write a probe object, so it cannot prove write permission.

The application database and object storage do not share a distributed transaction. The loader compensates files created by its own failed execution on a best-effort basis and never deletes pre-existing objects.

## Manual demonstration assets

These files are included in the deployment source but are not uploaded by the command:

- `resources/demo/universidad-del-rio/preguntas-frecuentes-admision.pdf`
- `resources/demo/universidad-del-rio/requisitos-ingreso-especial-2027-v3.pdf`

Copy them to the presenter's computer before the demonstration. They are not exposed through a public endpoint.
