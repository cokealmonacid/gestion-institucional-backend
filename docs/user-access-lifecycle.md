# User access lifecycle operations

This increment adds invitation-based account creation, activation, password recovery, and authenticated password changes. Public registration and the legacy Breeze password, verification, profile-mutation, and institution-administration web routes are disabled.

## Transitional API contract

The currently published password-based `POST /api/v1/institution/users` and its response remain unchanged temporarily. New clients must create users with `POST /api/v1/institution/user-invitations`. Activation and delivery state are intentionally obtained from `GET /api/v1/institution/users/{user_id}/invitation`; the closed list rows are not widened until the coordinated major release.

After the frontend adopts the invitation operations, the closing release must remove password-based creation, add the required state fields to the canonical institutional user resource, and publish the corresponding major contract version.

## Required Cloud configuration

- `USER_ACCESS_FRONTEND_URL`: trusted frontend origin used to build access links. It must never be derived from request headers.
- `USER_INVITATION_EXPIRE_MINUTES`: defaults to 2880 (48 hours).
- `USER_INVITATION_THROTTLE_SECONDS`: defaults to 60.
- `USER_ACCESS_MAIL_TRIES`: defaults to 3.
- `USER_ACCESS_MAIL_BACKOFF_SECONDS`: defaults to 60.
- `PASSWORD_RESET_EXPIRE_MINUTES`: defaults to 60.
- `PASSWORD_RESET_THROTTLE_SECONDS`: defaults to 60.
- `MAIL_MAILER` and provider credentials: configure a real transactional transport. The `log` mailer is rejected for access-link jobs.
- `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`: verified sender identity.

Run a supervised queue worker in Cloud because access mail jobs are encrypted and queued after database commit. Queue publication, transport acceptance, and recipient delivery are different states. `accepted` only means the configured transport accepted the message; no delivery webhook is implemented.

Failed invitation publication or transport leaves the pending account discoverable through its UUID and allows an administrator to resend. Resending replaces the token and generation, so delayed jobs cannot send or update the state of a newer invitation.

## Deployment sequence

1. Deploy backend migrations and additive operations.
2. Configure the trusted frontend URL, transactional provider, verified sender, secrets, and worker.
3. Adopt the invitation, activation, recovery, and password-change operations in the frontend.
4. Verify activation and recovery from received messages in controlled mailboxes.
5. Coordinate the major institutional-users release that removes legacy password-based creation and widens the canonical user resource.

Existing verified users and demo accounts are unchanged. Existing unverified users without an invitation remain `legacy_unverified` until an administrator explicitly starts the invitation flow; migrations do not verify them or send mail.

SQLite tests prove application behavior and rollback, but not MySQL row-lock concurrency. Executable process-level coverage is provided by `phpunit.mysql.xml`. It refuses cached configuration, non-testing environments, non-local hosts, database URLs, and every database name except `acervo_user_access_concurrency_test`.

Create that otherwise disposable database on a local MySQL instance, apply a least-privilege test account, and run from PowerShell without placing credentials in the repository or command history:

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='mysql'
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='3306'
$env:DB_DATABASE='acervo_user_access_concurrency_test'
$env:DB_USERNAME='<local-test-user>'
$env:DB_PASSWORD='<set-outside-the-repository>'
$env:DB_URL=''
$env:MYSQL_CONCURRENCY_TESTS='1'
php -d memory_limit=512M vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml
```

The suite applies outstanding migrations to that dedicated database without using `migrate:fresh`. Independent PHP processes synchronize behind a filesystem barrier and contend on the same institution row through separate MySQL connections. It covers invitation consume/consume, invitation resend/consume, recovery consume/consume, recovery issue/consume, activation and recovery against administrative deactivation, and authenticated password change against a pending recovery. Do not point this command at a working or Cloud database.
