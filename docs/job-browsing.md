# Public Job Browsing — Module 11B Phase 1

## Module 11C UI checkpoint — 2026-11-02

The Homepage and shared design system now extend to Authentication, public Jobs and
Client Job Posting views. Focused offline checks and selected public-page desktop/mobile
visual reviews were completed; this does not repeat or supersede historical 11B acceptance.
Protected Client-page visual checks, authenticated avatar/logout browser checks, and
populated Public Jobs browser acceptance remain pending. Full isolated acceptance is
deferred so core FYP website development can continue. These gaps are not recorded as PASS.

## Current acceptance status — 2026-11-01

Completed local Module 11B acceptance: 69 real SQL checks, 66 isolated HTTP checks, 28 browser
scenarios and 8 responsive layout checks passed. Catalogue/detail layouts were exercised at
1280, 768, 375 and 320px. Guest, Client and Freelancer browsing, filters, sorting, pagination,
detail privacy, overdue warnings, labels, keyboard focus and error associations were exercised.
No UI fix was required during browser acceptance.

Live startup-failure cleanup before readiness and normal fixture cleanup both passed. Temporary
privileges were fully revoked and effective grants restored exactly. All 20 isolated test tables
returned to empty; development database counts/content/schema fingerprints were unchanged.

Still explicitly unverified: 200% browser zoom, JavaScript-disabled browser behavior,
screen-reader speech and other browser engines. JavaScript-free implementation is not proof
of a completed JavaScript-disabled browser test. This is local FYP acceptance, not complete
public-deployment readiness; performance and shared abuse-protection limitations below remain.

The Phase 1 results and preparation instructions below are preserved as historical records.
Their pending-acceptance statements describe that phase, not this completed acceptance.
See docs/job-browsing-phase2-tests.md for the reconciled test and cleanup record.

GET `?route=/jobs` and `?route=/jobs/show&id=...` are public, read-only routes. Guests and signed-in users share the same projection. Existing Client-owned Job Posting routes and authorization remain unchanged. Home and account landing pages link to Browse jobs.

PublicJobRepository uses separate prepared SELECT queries. Both list and detail require an open job, a Client profile and an active, email-verified Client owner. Missing, malformed, non-public and ineligible-owner detail requests receive the same 404. Database failures produce sanitized 503 responses. No contact details, user identifiers, bids, submissions or financial records are selected for public output.

JobBrowseValidator accepts a trimmed, valid UTF-8 keyword of at most 100 code points (400-byte raw bound); literal case-insensitive substring matching covers title/description under the existing database collation. LIKE escapes !, % and _. Optional inclusive RM budget bounds use the existing RM0.01–RM99,999,999.99 policy and exact decimal-string comparison. Up to 10 distinct existing skill IDs use ANY matching skill, ANDed with other filters. Invalid input returns 422 with accessible errors; invalid values are not silently used to search. Valid normalized filters remain available in the form.

Allowlisted sorts: newest (creation/id descending), budget_low (budget/id ascending), budget_high (budget/id descending). Pages are bounded to 1–100000, with 20 displayed jobs and a 21st-row Next probe. Previous/Next links preserve normalized filters; Apply starts at page one. Ordering is deterministic but concurrent inserts/status changes can shift offset pages; this is not a frozen search snapshot. Skills are loaded in one batch per result set, not per card. Creation dates are explicitly labelled UTC; overdue warnings compare Malaysia-local calendar dates and never change job status.

Views reuse GigHub form/card styles, labels, error associations and responsive stacking. GET forms require no JavaScript. Descriptions are plain escaped text, summarized by Unicode code points on cards and shown fully on details. No bid, publish, edit or delete action is exposed through these public views.

## Verification and next acceptance

Phase 1 uses offline validator tests, real repository methods with PDO statement doubles, controller/view doubles and route/navigation assertions in tests/job-browsing.php. These prove query construction and local behavior, not MariaDB execution, optimizer plans, live privacy filtering or browser accessibility.

Phase 1 verification: 102 new browsing checks plus 232 focused Job Posting, Authentication/navigation, Foundation and UI regression checks passed (334 total). All 9 affected PHP files passed syntax checks; no JavaScript was added or changed. Git whitespace checks passed. No database fixture, HTTP mutation or browser acceptance test was executed in this phase.

Before database/HTTP fixture acceptance: obtain separate approval; use only the existing guarded gighub_schema_test bootstrap at 127.0.0.1:3309; review every main/setup/cleanup/subprocess path, fixture dependencies and exact privileges. Use marked synthetic active/pending/suspended/wrong-role owners and draft/open/non-public jobs, including overdue jobs and multiple overlapping skills. Verify real collation/wildcard semantics, inclusive ranges, ANY filtering without duplicate jobs, sort/page boundaries and uniform 404s. Preserve development integrity, use rollback where possible and separately approve exact-ID HTTP cleanup/grants. No test may fall back to development credentials or send SMTP.

At the end of Phase 1, browser checks remained pending: responsive layout, keyboard/focus/error links, 200% zoom, JavaScript-disabled behavior, screen-reader speech and other engines. Later Module 11B acceptance is recorded above; no result is inferred from Module 11A.

No schema/index change is included. Existing status/created/id and skill junction indexes support basic queries. Leading-wildcard searches, description scans, budget sorting and deep offsets can be expensive at scale; measure isolated EXPLAIN/timings before proposing indexes or FULLTEXT. There is no new shared public-search rate limiter. Bidding, messaging, saved jobs and Jaccard Matching remain separate future modules.
