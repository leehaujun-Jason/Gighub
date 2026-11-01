# Initial Skills Catalogue — controlled seed procedure

## Completed local seed — 2026-11-01

The approved 20 canonical names below were successfully seeded into local gighub_dev.skills in the separately authorized Phase 2D-2 operation. The catalogue grew from 0 to 20 rows. Only missing names were inserted in one transaction; existing IDs and relationships were preserved. Non-skills development data, the isolated test database and privilege state were unchanged. A second read-only plan found zero missing canonical names. The final development browser smoke test displayed all 20 names and no unavailable-catalogue notice.

A fresh environment still requires a separate controlled seed. The procedure below is a manual operational plan, not an executable seed included in application startup or tests. Reconfirm the intended environment and obtain authorization before executing it. Do not assume local numeric Skill IDs transfer to another environment.

The original Phase 1/2A proposal and observations below are historical; their empty-catalogue state no longer describes this local database.

## Proposed canonical names

HTML, CSS, JavaScript, PHP, MySQL, Python, Java, C++, React, Laravel, WordPress, UI/UX Design, Figma, Graphic Design, Logo Design, Video Editing, Content Writing, Copywriting, SEO, Data Entry.

This is the suggested 20-name list. Compared with Phase 1's proposal, replace MariaDB with Figma. MySQL covers the initial database-development skill category; this does not imply that MariaDB and MySQL are identical technologies. MariaDB can be added later if real demand warrants a distinct skill. Figma adds a design-tool skill without changing the schema. This is a practical broad demo catalogue, not a measured claim about Malaysian market demand.

Use these exact labels consistently for job requirements and freelancer profiles. Do not create parallel HTML5/HTML, JS/JavaScript, React.js/React or UI UX/UI/UX Design aliases in this initial seed. Future synonyms need a deliberate normalization decision, not ad-hoc duplicate rows. C++ punctuation must be retained. Skills are sets of canonical IDs for Jaccard; job_skills/user_skills uniqueness prevents repeated IDs. More/less specific skills can affect scores; no taxonomy weighting or synonym matching is introduced.

## Future idempotent seed operation — requires separate approval

1. Use a separately reviewed CLI-only operation, never an HTTP route or automatic bootstrap. Reconfirm exact clean server/datadir/port 3309 and database gighub_dev. Confirm a backup and disabled mail. Never target the damaged original server.
2. Read the current catalogue and compare using its utf8mb4_unicode_ci uniqueness semantics. Inspect whitespace/case variants or conflicting near-duplicates; stop for review instead of renaming/deleting existing rows.
3. Begin a short transaction. For each allowlisted canonical name, use a prepared SELECT by name, then INSERT only if absent. Preserve all existing IDs, names and links. Use the unique name constraint to reject concurrent duplication; a concurrent conflict aborts/rolls back the seed, with no silent retries.
4. Do not use REPLACE, broad UPDATE/DELETE, TRUNCATE, disabled constraints or INSERT IGNORE (which could conceal unrelated failures). No schema change or automatic grants. Only SELECT and INSERT on skills are needed for this operation.
5. Before commit verify every requested name resolves to exactly one canonical ID, newly inserted rows equal the missing-name count, and existing rows/relationships are unchanged. Commit once after all checks.
6. Verify a second plan-only pass finds zero missing names; record counts and names, not database credentials. A later approved actual second run should perform zero INSERTs. No fixed IDs should be assumed.
7. Rollback on any error before commit. After commit, prefer leaving harmless canonical rows rather than automatic deletion; any reversal needs separate approval and reference checks.

Do not seed production/development as part of integration tests. Synthetic test skills use their own unique run prefix and are isolated from this real catalogue. The last development catalogue observation (Phase 1, 2026-11-01) was zero rows; Phase 2A did not re-query gighub_dev.
