# API token hash migration

New API tokens are stored as SHA-256 hashes. Plaintext fallback is disabled by
default and must never remain enabled as a permanent compatibility mode.

For an existing production database that still contains plaintext tokens:

1. Take a database backup and record the start of a short maintenance window.
2. Set `API_TOKEN_ALLOW_LEGACY_PLAINTEXT=true` on every API node and reload the
   configuration. A successful request migrates that one token to its hash.
3. Keep the window bounded (normally 24–72 hours), monitor legacy matches, and
   ask inactive sessions to sign in again rather than extending the window.
4. Set the flag back to `false`, clear the configuration cache, and verify that
   no plaintext fallback matches appear in logs.

Fresh deployments and completed migrations use `false` from the first boot.
