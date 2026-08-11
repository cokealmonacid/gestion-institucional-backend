# Acervo API contracts

## Canonical authority and current scope

The backend repository is the authority for Acervo API contracts. The canonical OpenAPI 3.1 contracts are:

- [`v1/authentication.json`](v1/authentication.json), contract version `1.0.0`;
- [`v1/document-explorer.json`](v1/document-explorer.json), contract version `1.0.0`;
- [`v1/institution-users.json`](v1/institution-users.json), contract version `1.0.0`.

The authentication contract scope is deliberately limited to:

- `POST /api/v1/auth/login`
- `GET /api/v1/user/profile`
- `POST /api/v1/auth/logout`

Password-recovery endpoints and every other API endpoint are outside the authentication contract version.

The document-explorer contract is deliberately read-only and limited to listing top-level nodes in the virtual institution root, retrieving a real node, listing its direct child nodes, and listing documents directly associated with it. The virtual root is not a node and does not contain documents.

The institution-users contract is admin-only and limited to listing the users of an institution, registering a user in an institution, updating a user's profile, and updating a user's role. Every operation requires `institution_id` to equal the authenticated admin's own institution, and update operations additionally require it to equal the target user's institution.

The authentication contract records the public request and response envelopes, status codes, schemas, Bearer security, institution and role data, and authentication lifecycle currently implemented by the backend. Login issues a Sanctum Personal Access Token in `data.token`; no refresh token is defined. Logout revokes only the access token used for that request.

## Relationship with code and tests

The OpenAPI document is the canonical integration interface. Routes, controllers, request validation, response helpers, resources, and authentication configuration implement that interface. Contract and regression tests verify the implementation and protect API behavior outside the contract from incidental changes.

Neither implementation code nor tests may silently redefine the public interface. A change that affects the documented interface must update the OpenAPI document and its tests in the same change set.

## Versioning and compatibility

Contract versions use semantic versioning through `info.version`:

- Patch: documentation or schema corrections that do not change valid requests or observable responses.
- Minor: backward-compatible additions.
- Major: breaking changes to paths, authentication, required request fields, response envelopes, status codes, or schemas.

The `v1` directory identifies the API contract family. A breaking change requires explicit review and either a major contract version with a documented migration or a new API path version, depending on its runtime compatibility impact.

## Modification procedure

1. Update the affected canonical document under `openapi/v1/` together with the corresponding implementation and tests.
2. Keep the contract limited to confirmed behavior; do not add planned endpoints or inferred fields.
3. Run formal OpenAPI validation.
4. Run the affected functional, contract, and regression tests.
5. Review the diff for compatibility, secrets, and environment-specific runtime addresses.

Validation is mandatory before the change is reviewed:

```shell
npm run api:contract:validate
```

Run the contractual and authentication regression tests with:

```shell
npm run api:contract:test
```

Run both checks in sequence with:

```shell
npm run api:contract:check
```

The validator is installed as a pinned development dependency and supports OpenAPI 3.1. Use `npm ci` to reproduce the locked toolchain before running these commands.

## Runtime addresses and secrets

Environment-specific API addresses are deployment configuration, not contract identity. The canonical document therefore does not declare a development, staging, or production `servers` URL. Consumers must receive their runtime base URL through their own environment configuration.

Never place tokens, credentials, API keys, private hostnames, or other secrets in an OpenAPI document, examples, validation configuration, or committed environment file.

## Future distribution

This layout is prepared for later publication as an immutable, checksummed artifact and consumption by other repositories. Until that distribution phase is implemented, this repository and canonical path remain the source of truth. Downstream copies or generated clients must identify the exact contract version and source revision and must never become independently edited authorities.
