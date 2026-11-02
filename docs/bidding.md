# Module 12B — Freelancer Job Bidding MVP

## Routes

All routes use the existing `public/index.php?route=...` convention.

- GET `/freelancer/bids`: My Bids (20 per page).
- GET/POST `/freelancer/bids/create`, with `job_id`: submit a proposal.
- GET `/freelancer/bids/edit`, with bid `id`: edit or resubmit an owned bid.
- POST `/freelancer/bids/update`, with bid `id`: save/resubmit.
- POST `/freelancer/bids/withdraw`, with bid `id`: withdraw a pending bid.
- GET `/client/jobs/bids`, with job `id`: owner-only proposals (20 per page).

Navigation is integrated into public Job Details, Freelancer Header/workspace,
My Jobs and owner-only Job Details. All screens reuse the shared shell and tokens.
No JavaScript is needed. No awarding, messaging, escrow, payments or email occurs.

## Rules and security

Bid amount is a total MYR fixed price, RM0.01–RM99,999,999.99, at most two decimal
places, normalized as a decimal string. It need not equal the advertised budget.
Proposal is 1–10,000 Unicode characters within TEXT capacity. Delivery duration
is an integer from 1 to 65,535 calendar days (existing SMALLINT UNSIGNED capacity),
estimated from eventual project start; it does not alter the job deadline. Overdue
open jobs remain visible and may receive bids under the existing browsing policy.

Only active, verified Freelancers with a profile may submit/edit/withdraw. Jobs
must be open, unselected and owned by active, verified Clients. Own-job bidding is
rejected. Ownership comes from AuthGuard; submitted account IDs are never trusted.
Client reads are scoped to the authenticated job owner. Proposal and amount are
private to the bidder and job owner; public Job/Profile queries are unchanged.

Each write uses a transaction/savepoint: lock job, recheck/lock Client and
Freelancer eligibility, then lock the bid. Unique `(job_id, freelancer_id)` remains
the database backstop. Pending bids may be edited/withdrawn while eligible;
accepted/rejected bids and jobs with an award pointer are locked. Withdrawn bids
may be edited and resubmitted in the same row, preserving IDs and references.
Duplicate create requests fail; the create form directs existing bidders to
their existing bid. No historical bid is deleted. Success uses fixed PRG redirects;
POST actions require purpose-specific CSRF. Dynamic output is escaped and raw
database failures are withheld.

## Withdrawal migration and deployment

Initial read-only inspection confirmed the live bids enum contained only pending,
accepted and rejected. Migration `database/migrations/002_add_bid_withdrawn_status.sql`
adds withdrawn at the end, preserving existing enum ordinals, row IDs, constraints
and relationships. After a separately approved, validated backup, it was applied once
to local gighub_dev. It was not applied to gighub_schema_test. The fresh
schema and field dictionary reflect the intended new definition. Back up and
obtain separate approval before applying the migration to each existing database.
Withdrawal fails closed with 503 before changing a bid if the enum is missing.

Application operations require existing SELECT/INSERT/UPDATE on bids plus
existing job/account/profile reads and locking permissions. No DELETE or DDL
permission is needed by the application. No grants are changed by this module.

## Verification and remaining acceptance

`C:\xampp\php\php.exe tests/bidding.php` uses mocked PDO/repositories only.
It covers money/Unicode/duration boundaries, ownership, eligibility, duplicate
conflicts, pending/withdrawn/locked transitions, transactions/savepoints, CSRF,
role denial, form errors and private Client proposal rendering. It does not
prove MariaDB locking behavior or simulate a live concurrency stress test.

Initial implementation used no live writes or synthetic fixtures. Subsequent
authorized local browser acceptance completed submission, editing, withdrawal
and resubmission on the same bid row, My Bids and owner Client review. Values and
statuses persisted on refresh. My Bids, editor and Client review were inspected
at 1280px and 375px without horizontal overflow. Only the designated bid changed;
the other 19 development tables matched baseline counts and fingerprints.
Non-owner real-SQL/service reads returned no rows/404 and cross-role browser
access was denied. A second Client browser login remains untested because no
second Client account was available. No historical acceptance harness, service
operation or privilege change was performed during bid acceptance.

Focused implementation verification: 99 bidding checks, 102 public browsing
checks, 48 Job Posting controller/view checks, 58 shared navigation checks and
49 profile checks passed offline. A stale Job Posting test expectation for the
pre-12A Freelancer workspace placeholder was updated to assert the current
Profile/My Bids navigation; production Job Posting behavior was not changed.
