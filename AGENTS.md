# AGENTS.md — Car Rental Platform

Car-rental management for one Moroccan agency initially (7 vehicles, employees), designed to grow to 300+ vehicles and eventually multiple agencies. Modular monolith, one developer + AI assistance.

## Active checkout
- Develop in `/home/taaouati/projects/bensebih-car-rental` (Ubuntu WSL filesystem), not the old Windows Documents snapshot. Run commands through `wsl -d Ubuntu --cd /home/taaouati/projects/bensebih-car-rental --exec ...` when invoked from Windows.

## Current source of truth
- Read [the unified specification](docs/master-specification-ar.md) before implementation. It consolidates the conversation and supersedes conflicting rules in architecture v3.9 and the four older Arabic documents.
- Use [the screen map](docs/page-map-ar.md) for page/tab/action coverage and navigation. It elaborates the unified specification without approving unresolved policies or permissions. A rental file spans booking, customer/drivers, payments/deposit, documents, pickup, ongoing rental, amendments, return and later settlement; do not reduce it to a website request/payment wizard.
- Development setup was explicitly authorized on 2026-10-17. See `docs/development-ar.md` for the local Ubuntu/WSL toolchain and startup commands. Do not treat requests to discuss or revise requirements as authorization for unrelated implementation.
- Preserve distinctions between agreed requirements, implementation proposals, and unresolved decisions. Proposed state names, detailed permissions, rounding rules, and commercial/legal details are not implicitly approved by a demo.
- Explain product decisions to the user in Arabic-script Darija. This does not change product languages.

## Layout
- `apps/api` — Laravel, REST API under `/api/v1`
- `apps/site` — Next.js public site (FR + AR, full RTL)
- `apps/dashboard` — Next.js responsive dashboard (FR ONLY); offline/PWA workflows deferred
- `packages/` — shared TS packages (Turborepo)

## Stack
PHP 8.x · Laravel · Next.js/TypeScript · Sanctum SPA (httpOnly session cookies) · PostgreSQL 16 baseline + `btree_gist` (managed)
Pin compatible supported versions at implementation start; do not assume the old baseline is the latest release.
Redis · Horizon (bounded PDF worker resources; only needed queues) · Spatie Permission (teams mode)
R2 (public + private buckets) · Browsershot for PDFs — **DomPDF is forbidden**

## GOLDEN RULES — never break these
1. **Money = integer centimes.** Never floats, never `float` columns.
2. **Multi-tenancy:** every business table has `company_id`. Fail closed: no tenant context → 403. Use `Tenant::run(companyId, …)` in jobs/commands. Composite FKs `(company_id, id)` on tenant-owned relations.
3. **Reservation state machine:** only transitions in the reviewed implementation matrix; otherwise → `422 UNTRANSITIONABLE`. State change + one `reservation_events` row (append-only) in the SAME DB transaction. Review the simplified proposal in the unified specification before implementing enums/transitions; do not revive the old mandatory call chain.
4. **Availability:** half-open `[)` ranges. PostgreSQL exclusion constraint guards planned occupancy; overlap violation → `409 UNIT_UNAVAILABLE`. A vehicle currently out MAY be booked after expected return + stored buffer. Once overdue, block new bookings and flag threatened existing bookings. Actual pickup always requires recorded return and readiness. Use locking and operational checks as well as the interval constraint. Never reject recording a real late return merely because it conflicts with a later planned booking.
5. `fin_bloquee_le` = company buffer captured at booking/assignment time (from `company_settings`, never from user input). Old rows keep their stored buffer.
6. **Price frozen at confirmation.** Before: recalculable. After: extensions/penalties/damages create NEW lines — never rewrite frozen lines.
7. **Invoices & contracts = immutable snapshots.** Store issued original PDF bytes and checksum; redownload serves that original. Do not promise byte-identical browser regeneration. Corrections create linked versions/adjustments. Paper signature and uploaded signed copy of the same version are MVP; electronic signing is deferred.
8. **Payments:** confirmation requires the FULL rental amount verified, not 30% or another deposit threshold. `virement` stays `pending` until verified; uploaded proof is not receipt and pending does NOT count toward paid totals. Pickup requires `reste_a_payer = 0`; the old exception for debt arising after confirmation remains unresolved and must not be silently enabled. A real receipt survives failure to allocate/confirm a reservation. Preserve it for reconciliation rather than rolling it back as if money never arrived.
9. **Caution (deposit):** variable per rental; employee or manager may set the amount with an audit trail. Pickup requires recorded receipt/blocking (amount + actual method) or manager waiver with reason. Track multiple deductions and refunds, not just one terminal status. Each damage deduction needs manager approval, reason, amount and photos. Total refunds + allocations cannot exceed received funds. Refunds preserve history; never delete payment rows. Deposit is not rental revenue.
10. **Permissions:** employees cannot change rental prices, approve damage/deposit deductions or waivers, approve rental refunds, see agency financial totals/margins, change settings or manage users/roles. They may set the deposit amount and return it on a clean return as agreed; these are not blanket pricing/refund permissions. Detailed bank-verification, refund-execution, publication, repair-readiness and export permissions must be resolved before implementation. Sensitive actions → immutable audit rows (actor + timestamp, reason where required).
11. **i18n:** dashboard is FR-only (no AR toggle, no RTL). Public site is FR+AR, 100% translated or nothing. The customer's language (`reservations.langue`) follows them everywhere THEY see: confirmation page, WhatsApp, contract PDF, invoice PDF.
12. **WhatsApp MVP = manual `wa.me` links only.** `wa.me` opened ≠ message sent. No auto-sending (Phase 2, Meta Cloud API).

## Workflow and scope
- Requests alone do not block inventory. Staff activates a 2-hour hold after agreement; manager extensions need an explicit expiry and reason. Expiry releases occupancy, retaining the request, history and payments. Check expiry during confirmation as well as in scheduled jobs.
- Online requests arrive with chosen vehicle, dates, duration and quoted price. Staff calls, sends transfer details manually, resumes the saved file later, verifies full funds and confirms. Walk-ins need no artificial call step.
- The proposed simpler lifecycle is request → hold → confirmed → out → returned, with cancellation/hold expiry transitions listed in the unified specification. Financial settlement, vehicle readiness, calls, deposit and insurer collection are separate axes. Finalize the implementation matrix before coding.
- The old automatic 24-hour request cancellation is not an active default; stale-request policy remains open. Exclusive 10-minute call claims are deferred unless needed.

**Pickup gates (ALL required, else blocked):** identity/licence verified · payment `reste = 0` · caution blocked|waived · departure inspection done · contract signed.

**Renewable contracts:** end date is mandatory. Manual renewal requires availability, agreed and paid added period, new lines and a recorded agreement. Added periods use their applicable tariff by default; manager pricing exceptions are audited. Never rewrite the original frozen price. Alert two days before renewal; overdue without renewal is lateness, not automatic extension. Future allocation on a renewable contract requires manager review.

**Operations and money:** permanent reusable client files; one vehicle file with public marketing controls and private operational records. Record physical return even with unresolved debt. Repair readiness, customer settlement and insurer collection are independent. Insurer requested/approved/received amounts are distinct; insurer refusal never automatically creates customer debt. Maintenance, loans, expenses, cash reconciliation and basic per-car reporting are MVP, not optional future modules. Do not label cash surplus or rental income minus direct costs as net accounting profit.

**Phasing:** one agency UI now, tenant-safe data from day one. Defer SaaS billing/onboarding, online payment gateways, automatic WhatsApp, advanced accounting, offline writes and automatic vehicle reassignment. No fixed 16-table ceiling or inherited four-person delivery estimate.

## Testing
- **Real PostgreSQL in CI. NEVER SQLite** for DB-behavior tests.
- Cover the acceptance scenarios in the unified specification: concurrent double-booking, hold expiry/payment races, pending exclusion, idempotency, partial refund, multiple caution deductions, extension balance, employee 403s, cross-tenant access, soft-delete restore conflict (clear message, not 500), renewable contracts, allowed future booking while out, overdue blocking and actual pickup readiness. Also cover independent incident settlement, installment/expense reconciliation and immutable original documents.

## Conventions
- Errors: `422 UNTRANSITIONABLE`, `409 UNIT_UNAVAILABLE` (stable codes, FR messages).
- Invoice numbering: atomic UPSERT on `company_counters` with `RETURNING`.
- Secrets (encryption keys, DB creds, R2 keys): vault + secured offline copy. Never commit secrets.
- Retention: 24 months provisional (pending CNDP/legal confirmation — do NOT hardcode as final).

## Commands
- Planned API commands once scaffolded: `composer test` · `php artisan migrate` · `php artisan horizon`
- Planned web commands once scaffolded: `pnpm --filter <app> dev|build|test`
- Ask before: any data-layer migration on existing data, any new permission, any pricing-logic change.
