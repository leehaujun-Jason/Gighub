# Counterpart dispute evidence (Migration 016)

Each side may submit zero to six JPEG/PNG/WebP images, maximum 5 MB and 12 megapixels each.
The existing uploader and private ChatAttachmentStorage image validation/re-encoding are shared.
Responses and images are saved atomically under the existing Job/participant/Bid/Dispute locks.
The authenticated counterpart is derived from the actual job and awarded bid, never request-supplied
uploader metadata. A response cannot be replaced or supplemented after submission or resolution.
Each side has its own gallery with uploader identity, retaining the existing authorized image route/modal.

## Schema and compatibility

016_add_counterpart_dispute_evidence.sql alters only dispute_evidence_images. Inventory remains 34 tables.
Adds evidence_side (initiator/counterpart, default initiator) and nullable uploader_id referencing users.id.
The existing six-position constraint remains; position uniqueness now includes evidence_side.
Counterpart rows require an uploader. No historical rows, responses, files, ledger entries or balances
are rewritten. Historical NULL uploader IDs resolve to disputes.raised_by at read time.
Historical responses without images show an empty Counterpart Evidence gallery.
Old-schema evidence reads and initiator writes remain supported; new counterpart files fail closed
until the two metadata columns exist. Text-only responses remain supported before deployment.

## Deployment (requires separate approval)

1. Verify MariaDB endpoint, current 34-table inventory, old evidence schema and absence of 016 columns.
2. Quiesce dispute writes; record schema, grants, row/file fingerprints and wallet/escrow reconciliation.
3. Run the existing backup helper and independently verify completion, 34 tables, four triggers and SHA-256.
4. Apply only Migration 016 once. DDL implicitly commits: stop on errors, never blindly replay.
5. Verify metadata columns, FK, unchanged six-image CHECK and per-side unique index; compare historical rows.
6. Existing table-level SELECT/INSERT on dispute_evidence_images and existing disputes UPDATE are sufficient.
   No new privileges, database-wide grants, new storage directory or inventory-count change are needed.
7. Verify application compatibility, effective grants and financial fingerprints; produce a verified post-backup.
8. Run owner-approved manual acceptance for both roles and both galleries. Keep progressive activation OFF.

Partial migration: preserve the exact state and backup, pause evidence writes and prepare reviewed forward
recovery. Do not restore over development automatically. Old code must not accept counterpart writes;
old galleries do not distinguish sides, so deploy matching readers before enabling the feature.
If commit outcome is uncertain, files are retained to avoid broken committed references; investigate
database references before any orphan cleanup. No automatic deletion of historical images is provided.

## Focused verification

Migration 016 was rehearsed on a disposable MariaDB database and subsequently deployed to development,
preserving historical evidence, private files, row projections and grants. Progressive activation remains disabled.
32 HTTP checks passed for zero/one/six images, Client and Freelancer responders, seven-image rejection,
invalid/oversized content, partial-batch cleanup, CSRF, authorized/unauthorized/cross-dispute retrieval,
gallery attribution, empty states and unchanged financial fingerprints.
Additional HTTP checks passed for new initiator uploads alongside counterpart uploads, SVG rejection,
and over-12-megapixel rejection. Browser interaction verified a local thumbnail, individual removal,
the separate Admin galleries, and the existing image modal loading/closing under CSP.
The 28-check isolated progressive suite passed. Both remaining-only dispute decisions were also
exercised with counterpart evidence; historical images and prior RM300 payout remained intact.
Submitted/resolved responses rejected replacement. These checks used only newly created synthetic cases.
Full mobile visual acceptance, exhaustive connection-loss/file-removal failure injection, and the complete
Regular/Investigation Chat acceptance suites were not repeated. Shared chat authorization code is unchanged.
