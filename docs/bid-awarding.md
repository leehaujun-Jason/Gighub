# Module 13 — Bid Awarding MVP

Clients select one eligible pending proposal from their own open job's bid review
page. POST `/client/jobs/award` receives job_id, bid_id and a purpose-specific
bid_award CSRF token. Actor identity comes only from the verified Client session.
Successful selection redirects to the owner-only bid review with a success notice.
Guests, Freelancers and Admins cannot perform the Client action. GET is rejected.

## Selection state, not funding

This follows the existing database design's separate selection/funding contract:

- `jobs.status`: open -> awarded.
- `jobs.awarded_bid_id`: NULL -> the selected same-job bid ID.
- `jobs.awarded_at`: NULL -> UTC timestamp in the same UPDATE/transaction.
- Selected bid remains pending; other bid statuses are unchanged.
- Client review and My Bids display **Selected — awaiting funding** using the
  canonical job pointer. Other pending proposals are read-only while selection
  awaits funding; they are not prematurely labelled rejected.
- No wallet, escrow, payment, notification or work-start operation is performed.
  Accepted/rejected bid mirrors belong to the future funding transition.

No migration is required. Existing enums, award/time CHECK constraints and
`fk_job_awarded_bid (id, awarded_bid_id) -> bids(job_id, id)` support this state.
No schema, grants or live business records were changed during implementation.

## Integrity and concurrency

Transaction/savepoint scope matches bidding. Lock the job first, verify owner and
open/unselected state, lock/recheck the active verified Client and Freelancer,
then re-read the same-job bid FOR UPDATE and require pending status. Update the
job conditionally with owner/status/NULL-pointer guards and require one affected
row. The job lock serializes competing awards and bid edits/withdrawals; a later
attempt sees awarded and fails. Bid identity/status are rechecked after locking.
Failures roll back; raw database details are withheld. SQL uses bound values.

Withdrawn/rejected/accepted bids, ineligible bidders, mismatched job/bid pairs,
foreign-owned jobs and already-selected jobs are rejected. Existing Bidding
checks prevent editing or withdrawing proposals once the job is awarded.
Public browsing continues to expose only open jobs; award details remain private
to owner review and each Freelancer's own My Bids projection.

## Verification and live acceptance

`C:\xampp\php\php.exe tests/awarding.php` runs mocked/offline service, controller
and view checks. It covers selection, repeated/conflicting awards, eligibility,
ownership, withdrawn/locked bids, concurrent-state changes at re-read, rollback,
savepoints, CSRF/RBAC, POST-only routing and selected/non-selected presentation.
These checks do not replace a real MariaDB concurrency or browser acceptance run.

Implementation verification passed: 55 awarding checks, 99 bidding checks, 48
Job Posting controller/view checks and 58 shared navigation checks (260 total),
11 PHP syntax checks and Git whitespace checks. No live award was submitted
during implementation.

Local acceptance on 2 November 2026: an authorized Client selected a designated
pending bid. The job award pointer/time persisted, the bid remained pending, and
both participants saw selection awaiting funding. A repeat returned 409, a
non-owner service request returned 404, and a stale form and Freelancer Client-page
access were denied. Client and Freelancer layouts were checked at 1280px and 375px
without horizontal overflow. Only the jobs table changed. Withdrawn/foreign-bid
rejections were checked offline; a second Client browser session was unavailable.
No historical harness was used. Award reversal and funding remain out of scope;
a live award closes bidding and is not automatically undone by this module.
