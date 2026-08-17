# Node creation integrity

`Node` is the canonical container resource. The institution root is virtual and is represented by a null `parent_id`.

## Canonical and compatibility operations

- `POST /api/v1/institution/tree-directory` is the canonical operation. Its body requires `name` and a nullable `parent_id`; it returns `201` through `NodeResource`.
- `POST /api/v1/institution/tree-directory/{node_id}` is retained for compatibility. It returns the historical `200` model envelope and is intentionally absent from OpenAPI. Both operations use `CreateNodeAction` and therefore share authorization, normalization, integrity and transaction rules.

## Name identity

The public name is trimmed and normalized to NFC. A separate key applies Unicode case folding. The database stores that normalized value for diagnostics and a SHA-256 fingerprint for a compact cross-engine unique key.

`parent_scope` is `R` for the virtual root and `P:{parent UUID}` otherwise. The unique key over institution, parent scope and name fingerprint protects active and inactive siblings, including root nodes. An application pre-check improves the error and the database constraint resolves races.

## Transaction and order

Creation locks the owning institution, which serializes node creation within that tenant on databases supporting `SELECT ... FOR UPDATE`. This deliberately favors a simple correctness boundary over maximum write concurrency. SQLite serializes writes and the two unique constraints remain the final safeguards.

Within the same transaction, the action resolves the active parent, calculates `MAX(order) + 1`, assigns the UUID and ID path, and inserts the node. The sibling-order unique key prevents duplicate positions and transient order conflicts are retried up to three times.

## Materialized path and depth

`parent_id` remains hierarchy authority. `path` is derived as slash-separated textual UUIDs and includes the node itself. UUID strings and separators consume `37n - 1` bytes for `n` levels:

- 10 levels: 369 bytes;
- 50 levels: 1,849 bytes;
- 100 levels: 3,699 bytes.

MySQL `TEXT` holds 65,535 bytes, approximately 1,771 textual UUID levels. The application limit is depth 100 (a root at depth 0 plus at most 100 descendants), well below storage limits. It is an operational bound for future transactional subtree moves, cycle checks and API payload sizes, not a database capacity claim.

No current query filters by materialized path, so the text content is not indexed. The navigation index covers institution, parent and active state.

## Migration behavior

The first migration expands the schema with nullable staging columns. The second migration:

1. rejects non-NFC, untrimmed, invalid or duplicate legacy names;
2. rejects empty, negative, decimal, non-numeric or out-of-range legacy order values;
3. detects missing parents, cross-institution parents and cycles;
4. reconstructs ID paths and depth;
5. deterministically resequences sibling order by legacy numeric order, name and UUID;
6. replaces the legacy path/order columns and installs constraints.

Gaps, leading zeros and repeated convertible orders are safe because resequencing preserves deterministic relative order. Invalid data causes the migration to stop with the affected node identifier; it is never silently deleted or renamed.

Production is configured as MySQL in the repository, while tests use in-memory SQLite. The repository does not pin or expose the production MySQL version or effective table collation. The design avoids functional, partial and collation-dependent unique indexes, but the migration and locking behavior must still be rehearsed against the actual production engine before deployment.
