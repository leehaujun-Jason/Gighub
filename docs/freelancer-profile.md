# Module 12A — Freelancer Profile MVP

Routes use the existing `public/index.php?route=...` convention:

- GET `/freelancer/profile`: verified Freelancer's own profile.
- GET/POST `/freelancer/profile/edit`: editor and CSRF-protected save.
- GET `/freelancers/show&id=...`: public professional profile; missing and
  ineligible accounts return the same 404.

Headline and biography are optional, limited to 150 and 10,000 Unicode characters
respectively. Skills are an optional set of existing catalogue IDs. Only display
name, headline, biography and selected skills are public. No email, authentication
or portfolio fields are returned. Users should avoid private information in bio.

Editing derives ownership from AuthGuard, locks and rechecks eligibility, updates
the existing profile and replaces its skills atomically. Portfolio fields remain
untouched. Missing profile records are not silently inserted. Successful saves use
Post/Redirect/Get and a one-use success message. No JavaScript is required.

The headline migration was separately applied to development. It is NOT reapplied
by this module. The isolated test schema has not been migrated by this task.

Verification: `C:\xampp\php\php.exe tests/freelancer-profile.php` uses only offline
doubles; no database fixtures.

Initial implementation checks: 49 focused profile checks, 58 shared navigation checks, 59
authentication checks and 11 authentication-navigation checks passed offline.
Read-only live metadata confirmed the headline column. No eligible active,
verified Freelancer profile was available for populated-page browser review;
no account or fixture was created or modified to enable that review.

## Completed local acceptance — 2 November 2026

After a verified Freelancer test account became available, the user approved a
sample profile save through the normal authenticated browser editor. The first
attempt failed because the application account lacked DELETE on user_skills;
the profile fields and skills remained unchanged after rollback. On the approved
retry, the required table-level grant was already present, so no GRANT was issued.
The headline, biography and five catalogue skills saved and persisted on refresh.
Guest public-profile checks confirmed the saved content, five skills, no email
address and no editor link. Owner/editor and public layouts were checked at
1280px and 375px with no horizontal overflow. No code fix was required.

The restored Registration resend link passed 12 offline navigation checks and a
read-only HTTP form check; no resend was submitted. Login still excludes resend
navigation. No historical acceptance harness was used.

Runtime access must include SELECT on the catalogue, SELECT/UPDATE on the
existing profile and SELECT/INSERT/DELETE on user_skills; eligibility locking also
uses the existing users access. Do not grant broader privileges automatically.
This is local FYP MVP acceptance, not complete accessibility or public-deployment
certification. Screen-reader speech, other browser engines and exhaustive
concurrency testing were not part of this focused acceptance.
