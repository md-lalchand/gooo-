# Production operations and release checklist

## Required secrets and infrastructure

### Node API
- `SESSION_SECRET`: at least 32 random characters; keep it in the host secret manager.
- `REDIS_URL`: production is fail-closed if Redis is missing or unavailable. All Node instances must use the same Redis database/namespace.
- `TRUST_PROXY_HOPS`: exact trusted reverse-proxy hop count. Defaults to `0` (no forwarded headers trusted). Do not set this to `1` unless every request is guaranteed to pass through exactly one trusted proxy.
- Use HTTPS at the edge and restrict direct access to application ports.

### Laravel portable backups
- `BACKUP_ENCRYPTION_KEY`: generate 32 cryptographically random bytes and store the 64-character hex value (or base64 encoding) in a secret manager independent of the application host.
- Escrow a second protected copy of this key outside the server. Without it, encrypted environment backups cannot be decrypted.
- The key is intentionally independent of `APP_KEY`; rotating/restoring Laravel's `APP_KEY` does not prevent decrypting the backup environment file.
- A restore writes the decrypted environment to a private 0600 review file. An operator must review it, transfer it securely into the target environment, and restart services. It is not silently installed over the active `.env`, because doing so could switch database credentials or application encryption keys without operator review.

## Release and upgrade sequence

1. Provision PHP 8.3+, required Laravel extensions (including ZIP), Node 22+, a supported database, and Redis.
2. Configure production secrets above; never commit real values to Git.
3. Verify Redis is reachable from every Node instance and that all instances share the same Redis endpoint.
4. Set `TRUST_PROXY_HOPS` to the exact network topology. Keep it at `0` if the app is directly exposed.
5. Run CI: TypeScript lint, frontend build, Node tests, Laravel feature suite, and runtime smoke checks.
6. Before applying a release, create a portable backup and verify its manifest/checksums. Copy the ZIP and the backup encryption key escrow reference to a separate protected location.
7. Deploy code to a staging environment first. Run migrations, then verify:
   - Node `/api/public/bootstrap` responds successfully.
   - Laravel `/up` responds successfully.
   - Login, course listing/enrollment, learner progress, payment webhook signature checks, uploads, and backup download/restore work.
   - Two Node processes sharing Redis enforce one common guest-IP limit (CI includes this cross-process test).
8. For production, use the host's established release/rollback mechanism. Apply migrations only after a verified pre-release backup; restart Node/PHP workers and run the same health and business-flow checks.
9. Keep the previous release and backup until the post-deploy smoke checks pass.

## Rollback

- Roll back application code using the host's release mechanism.
- Restore database and upload data from a verified portable archive if the release changed data incompatibly.
- Review the decrypted environment file before changing the active `.env`; restart services after the change.
- Never delete the only copy of the pre-release backup or the separately escrowed `BACKUP_ENCRYPTION_KEY`.

## Deployment credentials

Actual production deployment is intentionally not run by repository CI. It requires the owner's production host, SSH/deployment credentials, service names, domain/health URL, and approval. Add these to the hosting secret manager and use the organization's existing deployment process; do not place them in this repository.
