# Security Operations

## Session storage contract

Smart Goal requires `SESSION_DRIVER=database`. Password changes, first-password
setup, username changes, and administrator password resets revoke database-backed
sessions. `UserSessionSecurity` fails closed if a different driver is configured,
so changing this setting requires a replacement revocation design and security
regression tests before deployment.

## Protected attachment deployment

The `2026_08_24_000002_move_attachments_to_private_storage` migration is
forward-only. It moves Task and Project attachments from the public disk to the
private local disk and verifies file size and SHA-256 before removing a public
source.

Deployment requirements:

1. Back up the database and `storage/app/public` attachments.
2. Deploy the protected-media application code.
3. Run `php artisan migrate --force` before reopening application traffic.
4. Treat any attachment verification error as a failed deployment. Keep the
   public source, investigate the conflicting private file, and rerun the
   migration only after resolving the conflict.

Do not roll application code back to a version that reads attachments directly
from the public disk. The migration intentionally does not move private files
back during `migrate:rollback`, because doing so would restore the authorization
bypass. An application rollback must retain the protected media routes and
private-storage reader until a separate reviewed migration strategy is ready.
