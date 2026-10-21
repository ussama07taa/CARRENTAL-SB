# Ben Sbih Car Rental

Modular monolith for one Moroccan rental agency, designed for tenant-safe growth.

- `apps/api`: Laravel API and workers.
- `apps/dashboard`: French Next.js management interface, port 3000.
- `apps/site`: French/Arabic Next.js public site, port 3001.
- `packages`: shared code only when actually needed.
- `docs/master-specification-ar.md`: business source of truth.
- `docs/page-map-ar.md`: page and action coverage.

Active checkout: `/home/taaouati/projects/bensebih-car-rental` in Ubuntu WSL. Run `bash scripts/dev.sh` inside Ubuntu; run `bash scripts/check.sh` for validation. The PowerShell scripts also work and start the database services. The old Documents checkout is a retained snapshot with forwarding launchers; do not develop in both copies.
Read [the local development guide](docs/development-ar.md) first.

## Current development snapshot — October 2026

The dashboard includes customer and vehicle files, vehicle documents, maintenance and garage payments, vehicle loans, reservation preparation and payments, departure/return inspections, rental documents, and damage follow-up. These are development workflows; this repository is not a claim of production readiness. The public site remains a foundation.

Read `docs/master-specification-ar.md` and `docs/development-ar.md` for agreed rules, validation history and open decisions. The sections below record earlier milestones and their scope at the time, rather than the complete current feature set.

Local secrets, uploaded documents, database backups, sandbox copies and generated exports are intentionally excluded from Git. Copy the example environment files and configure local credentials privately. Publishing source to GitHub does not deploy the demo or copy its database. Existing-data migrations require explicit review before execution.

## Phase 1A — company and responsible account foundation

Implemented: `Company`, global authentication identity `User`, tenant-scoped `CompanyMembership`, and append-only `company_setup_events`. `is_primary_responsible` identifies the founding responsible person; it does not grant business permissions. The Spatie role/permission matrix and login are separate, pending phases.

`app/Actions/Companies/CreateCompanyWithResponsible.php` owns the operator bootstrap transaction. The CLI `php artisan company:setup` asks for names, email and a hidden password with confirmation. It refuses non-interactive execution, existing agency setup and claiming an existing email. No default production accounts are seeded.

`Tenant::run` is for trusted commands/jobs. `Tenant::forUser` checks membership before entering context; its user must come from trusted authentication when login is implemented. Context and the Spatie team are restored in `finally`. Company/User registries and raw DB access are internal infrastructure, not tenant-authorized public APIs. Membership scope and model guards cover normal ORM access, not arbitrary raw SQL or deliberate scope bypass. Future tenant actions must not use unscoped queries or bulk updates to change ownership. Business endpoints still must not be exposed before authenticated context resolution is wired and tested.

The setup log records the CLI operating-system operator and the responsible membership separately; it does not pretend the newly created user performed an authenticated action. Composite keys prevent referencing a responsible membership from another company. PostgreSQL triggers reject normal update/delete/truncate of setup history; a database administrator can still alter the schema.

Verified on PostgreSQL: 32 tests / 100 assertions across the API suite. Local schema migration applied; no real agency/account rows created yet. User supplied agency name BENSEBIHI CARRENTAL and responsible name Yasser Bensebihi; email was explicitly deferred. Real provisioning waits for the email and a privately entered password. No login, employee management, public signup or automatic agency switching was added.

## Phase 1B — session login, logout and private identity

Implemented only `/login`, the protected dashboard root, and three cookie-session endpoints: `POST /api/v1/auth/login`, `GET /api/v1/auth/me`, `POST /api/v1/auth/logout`. Auth routes intentionally use Laravel's web/session middleware and `auth:web`; Sanctum supplies the CSRF-cookie bootstrap. No bearer tokens or public signup. Login rotates the session and CSRF token, stores the single company resolved from membership, and refuses ambiguous/missing membership. Every private request rechecks membership. The identity response exposes only the three names and demo flag. Logout invalidates the session. Limits are 5 login submissions/minute per normalized email+IP and 30/minute per IP; errors are in French. Business permissions remain a later step.

The demo is separate: `.env.demo`, PostgreSQL `bensebih_demo`, its own login role, Redis databases 4/5, cookie and key. `scripts/provision-demo.py` creates only a new demo environment. `php artisan migrate --env=demo --force` initializes its schema; `php artisan company:demo --env=demo` is guarded by both environment and database name, creates only fictitious details and saves a random password in ignored `.local/demo-account.json` with private permissions. Never run demo provisioning against real data. No real account has been created.

Run `scripts/demo.ps1` from the retained Windows folder (its launcher forwards here), or `BENSEBIH_DEMO=1 bash scripts/dev.sh` inside Ubuntu. Stop the currently running normal/demo server before starting the other mode; both use ports 3000/3001/8000. Normal `dev.ps1` still uses `.env`, not the demo. User-facing data is not shared between the two modes.

Validation: 47 PHP tests / 160 assertions, Next.js builds and TypeScript checks passed. Real-browser smoke test `scripts/verify-auth-browser.cjs` uses an externally installed Playwright (set `PLAYWRIGHT_MODULE` if needed), the isolated demo credentials and Chrome. It verifies redirects, incorrect password, login, refresh, names, demo flag, mobile layout, CSRF rejection, httpOnly cookie, logout, old-cookie invalidation and back navigation. Screenshots remain in ignored `.local`. API tests also cover throttle/recovery, cross-agency session tampering and revoked membership. Live `bensebih_local` remains at 0 agencies / 0 users. No further module was started.
