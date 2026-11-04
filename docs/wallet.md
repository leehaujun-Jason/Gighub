# Module 14A — Client Wallet & Demo Credit MVP

## Scope & Architecture

Module 14A implements the simulated client wallet and one-time demo-credit functionality for verified Clients. It establishes the accounting foundation required prior to escrow funding, adhering to the database invariants in `docs/database-design.md` and approved decision #9 in `docs/database-decisions.md`.

All money is handled as exact decimal strings (`DECIMAL(10,2)`). This module operates entirely with simulated demo funds; no real money, credit cards, bank accounts, or external payment gateways are involved.

## Routes & User Experience

All routes use the query parameter convention `?route=...`:

- `GET /client/wallet`: Displays current available balance (defaults to RM0.00 if uncredited) and paginated append-only ledger history.
- `POST /client/wallet/claim-demo-credit`: Claims the approved one-time simulated demo credit of RM10,000.00.

Navigation links are integrated into the Client workspace header, account menu, and Client Account Home. Successful claims use Post/Redirect/Get (PRG) with a 303 redirect and session flash notice. Once claimed, the claim button is replaced with a confirmed badge.

## Security, Concurrency & Invariants

1. **Role-Based Access Control (RBAC):** Only active, email-verified accounts with role `client` can access the wallet view or claim demo credits. Unauthorized roles (freelancer, admin) receive HTTP 403; unauthenticated users redirect to login.
2. **Session-Bound Identity:** The wallet and ledger actor IDs are derived exclusively from the authenticated session via `AuthGuard`.
3. **Purpose-Specific CSRF:** State-changing requests require a valid `'wallet_credit'` purpose CSRF token, rotated upon successful processing.
4. **Transaction Ownership & Savepoint Discipline:** `WalletService` manages transaction boundaries and savepoints (`SAVEPOINT gighub_wallet`), ensuring that wallet row initialization/balance updates and ledger insertion succeed or roll back together. `WalletRepository` owns prepared SQL queries only.
5. **Idempotency & Duplicate Protection:**
   - Appends a ledger entry with unique `operation_key = 'demo:user:<id>'`.
   - The database engine enforces uniqueness via persistent generated column `demo_credit_user_id`.
   - Re-checks under lock before writing and catches duplicate key exceptions safely, returning HTTP 409 without exposing raw database errors.
   - Check constraint `ck_wallet_entry_semantics` validates `entry_type = 'demo_credit'`, `escrow_id IS NULL`, `amount_delta = 10000.00`, and `source = 'demo_seed'`.
   - Unconditional database triggers prevent `UPDATE` and `DELETE` on `wallet_entries`.

## Database Impact & Migrations

- **No migration required.** Tables `wallets` and `wallet_entries` already exist in the clean MariaDB schema (`database/schema.sql`).
- Table privileges for the application account (`SELECT/INSERT/UPDATE` on `wallets`, `SELECT/INSERT` on `wallet_entries`) were already provisioned.

## Verification & Manual Acceptance

### Offline Automated Verification
- `tests/wallet.php` executed offline with mocked PDO and repository: **45 PASS**.
  - Covers uncredited state, successful first claim, lock ordering, repeat rejection (409), race condition handling (409), masked SQL error on rollback, savepoint handling, anonymous redirect (303), CSRF validation, and cross-role denial.
- Full regression suite verified: **621 total offline checks passed** with zero regressions.
- PHP CLI syntax checks (`php -l`): all created and modified files passed with 0 errors.

### Confirmed Manual Acceptance (4 November 2026)
User confirmed five manual browser checks:
1. **Initial State:** Client wallet displayed RM0.00 balance, simulation notice, and active claim button.
2. **Successful Claim:** Clicking claim button issued 303 PRG redirect, set balance to RM10,000.00, badged claim state, and displayed one `+RM10,000.00` entry in ledger.
3. **Refresh Behavior:** Page refresh reloaded GET cleanly without re-triggering POST.
4. **Duplicate Prevention:** Re-submitting claim returned HTTP 409 Conflict without altering balance or ledger.
5. **Role Isolation:** Freelancer access to `/client/wallet` returned HTTP 403 Forbidden.

## Next Stage

- **Module 14B:** Job Milestone Management (defining and validating milestones for awarded jobs prior to escrow funding).
