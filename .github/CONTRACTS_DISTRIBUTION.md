# Contract distribution setup

`sync-contracts.yml` opens or updates the human-reviewed pull request in `cokealmonacid/gestion-institucional-contracts` after a contract change is merged into backend `develop`.

## One-time GitHub App setup

Create a GitHub App named `acervo-contracts-sync` under the `cokealmonacid` account. Disable webhooks and install it only in `gestion-institucional-contracts`. Grant only these repository permissions:

- Contents: Read and write.
- Pull requests: Read and write.
- Metadata: Read.

Install the app for the `cokealmonacid` account and select **Only select repositories**, including `gestion-institucional-contracts`. Generating an app key is not enough: the installation must explicitly include that repository.

Generate a private key for the app. In the backend repository's **Settings → Secrets and variables → Actions**, create:

- `CONTRACTS_APP_PRIVATE_KEY`: full PEM private-key content.

Under **Variables**, create:

- `CONTRACTS_APP_CLIENT_ID`: GitHub App Client ID (not its numeric App ID).

Do not add the private key to Git, `.env` files, workflow output, or pull-request text. The old `CONTRACTS_APP_ID` secret is no longer used by the v3 workflow and can be removed after the workflow is merged.

## Expected behavior

After a merged backend change modifies `openapi/`, the workflow uses the app token to update `sync/backend-develop` in the contracts repository. It then creates, or refreshes, a pull request to `develop`. A human must review and merge that pull request before a release is published.
