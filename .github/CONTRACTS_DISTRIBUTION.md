# Contract distribution setup

`sync-contracts.yml` opens or updates the human-reviewed pull request in `cokealmonacid/gestion-institucional-contracts` after a contract change is merged into backend `develop`.

## One-time GitHub App setup

Create a GitHub App named `acervo-contracts-sync` under the `cokealmonacid` account. Disable webhooks and install it only in `gestion-institucional-contracts`. Grant only these repository permissions:

- Contents: Read and write.
- Pull requests: Read and write.
- Metadata: Read.

Generate a private key for the app. In the backend repository's **Settings → Secrets and variables → Actions**, create:

- `CONTRACTS_APP_ID`: GitHub App ID.
- `CONTRACTS_APP_PRIVATE_KEY`: full PEM private-key content.

Do not add either value to Git, `.env` files, workflow output, or pull-request text.

## Expected behavior

After a merged backend change modifies `openapi/`, the workflow uses the app token to update `sync/backend-develop` in the contracts repository. It then creates, or refreshes, a pull request to `develop`. A human must review and merge that pull request before a release is published.
