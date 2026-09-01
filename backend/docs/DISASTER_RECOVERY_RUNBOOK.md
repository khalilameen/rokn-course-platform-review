# Disaster recovery: restore verification

Backups are not considered healthy because a job reported success. At least
monthly, and before any irreversible production migration, restore the latest
encrypted database backup into an isolated MySQL server or account. Never run
this drill against production and never copy restored customer data to a
developer workstation.

## Required inputs

- A readable `.sql` or `.sql.gz` backup made from the intended production
  database, with its backup-system retention identifier recorded separately.
- A disposable database name beginning `rokn_restore_verify_` on the isolated
  restore server; it must not equal `DB_DATABASE`.
- MySQL client available on the worker (`MYSQL_BINARY` may override its path).
- The production-compatible `APP_KEY`, `RECOVERY_ENCRYPTION_KEY_ID` and the
  separate `RECOVERY_EVIDENCE_SIGNING_KEY`. The command decrypts only the
  random recovery probe and exports counts/fingerprints, never row contents.
- A signed record produced first by `ops:verify-backup` for this exact artifact.

## Drill command

```bash
php artisan ops:verify-restore \
  --dump=/secure/backups/rokn-2026-08-12.sql.gz \
  --database=rokn_restore_verify_20260812 \
  --confirm=RESTORE_rokn_restore_verify_20260812 \
  --evidence=/secure/restore-evidence/rokn-20260812.json
```

The command refuses the configured primary database, requires the exact
confirmation and restores only the disposable database. It verifies that the
artifact hash matches signed backup evidence, decrypts the restored random
probe, applies current migrations with the default connection temporarily
bound to the disposable database, checks financial and foreign-reference
invariants, and samples durable files and Bunny media. The signed JSON contains
only checksums, counts, provider identity and measured RPO/RTO. By default it
drops the disposable database after evidence is written; use `--keep` only
while investigating a failed drill and remove it afterward.

## First reconciled-baseline cutover

The first reconciled-baseline cutover is blocked until a restore drill of the
latest production backup succeeds. Run the command above on the isolated
restore server before approving
`database/migration-baseline-manifest.json`. The evidence must contain a
`artifact_sha256`, `schema_fingerprint`, table count, migration count, zero
pending migrations, zero financial/orphan findings and zero missing sampled
objects. A second
operator must review it and record the protected evidence location, backup
retention identifier, evidence SHA-256, disposable-database cleanup result,
and review time in the deployment change ticket. Do not commit production
restore evidence to this repository.

The manifest freezes every migration at or before its `cutoff`. After the
cutover, schema changes must use a newly timestamped migration at or after
`tailStartsAt`; never edit, delete, rename, or backdate a frozen migration.
Changing the cutover manifest is a new controlled baseline operation and again
requires restore-drill evidence and two-person review.

`scripts/verify-migration-upgrade.php` only rebuilds the reconciled baseline in
a disposable SQLite database, applies the forward-only tail, and checks
idempotency plus failure/restart behavior. Its JSON sets
`productionUpgradeVerified` to `false`: the CI result does not verify a
production upgrade and cannot replace this restore drill or a deployment
preflight against the real production migration ledger.

## Acceptance checklist

1. Store the evidence JSON beside the backup-system run identifier, not inside
   the restored database.
2. Compare its dump SHA-256 against the backup provider's checksum.
3. Record elapsed time against the recovery-time objective and confirm the
   backup timestamp meets the recovery-point objective.
4. Have a second operator verify the evidence and the cleanup of the temporary
   database. Escalate any mismatch, failed restore, or missed RPO/RTO; do not
   silently mark the drill complete.
