# Job Posting Implementation Plan

**Goal:** Implement approved Module 11A core without schema or database writes during development verification.
**Architecture:** Existing response-array controllers, AuthGuard, PDO repositories and transaction-owning service. Plain-text views with scoped CSS and optional progressive enhancement.
**Spec:** User-approved Module 11A Phase 1 rules in this conversation.

- [x] Validator: Unicode, exact decimal strings, Malaysia calendar dates, bounded existing skill IDs; prove offline boundaries first.
- [x] Repository/service: owner scope, live Client recheck, job/skill atomicity, complete drafts, strict publication and immutable open jobs; offline PDO/repository doubles.
- [x] Controller/routes/views: dedicated CSRF, PRG, bounded escaped recovery, save-draft Enter default, minimal Client navigation and responsive design.
- [x] Verify: offline suites, PHP/JS syntax, diff checks. Read only the skills catalogue. No fixtures, schema/config changes, grants, SMTP or commits.
- [x] Document proposed isolated fixture privileges/cleanup and untested integration/browser behavior.
