# Dispute evidence deployment
Migration 014 adds dispute_evidence_images, bringing the canonical inventory from 32 to 33 tables. No historical backfill is required.
Before deployment: verify target, current 32-table inventory, absence of the new table and compatible disputes.id; create and independently verify a fresh backup.
Apply only Migration 014 after approval. Grant the configured application account SELECT, INSERT on this table only. For the existing development account:
```sql
GRANT SELECT, INSERT ON gighub_dev.dispute_evidence_images TO 'gighub_app'@'127.0.0.1';
```
Update the external backup helper to require 33 tables and the new table after deployment; verify schema, historical records, effective grants and a post-deployment backup.
Images reuse configured private chat attachment storage and its existing ACL; no directory provisioning is required.
Fresh installations import canonical schema only; do not replay migrations. Add SELECT, INSERT on dispute_evidence_images to installation grants.
Runtime checks after deployment: PR01–PR15 from supervisor acceptance, including cross-party authorization, 0/1/6/7 images, invalid content, upload failures, duplicate requests and resolved history. Never run against existing business records automatically.
