# Greenfield ERP - Phase 1 Foundation

## Run order
1. Copy `.env.example` to `.env` and configure DB.
2. Run `database/migrations/001_phase1_core.sql`.
3. Run `database/migrations/002_phase1_hardening.sql`.
4. Run `database/migrations/010_accounting_core.sql`.
5. Run `database/migrations/011_username_auth.sql`.
6. Run `database/seeders/001_phase1_seed.sql`.
7. Run `database/seeders/002_phase1_hardening_seed.sql`.
8. Run `database/seeders/010_accounting_core_seed.sql`.
9. Serve `public/` through XAMPP.
10. Visit `/greenfield/public/login`.

## Default demo credentials
- Organization: `DEMO`
- Username: `admin`
- Password: `Admin@12345`

## Smoke test
`php tests/smoke/phase1_smoke.php`
`php tests/smoke/accounting_core_smoke.php`

## Hardening notes
- `/health` is restricted unless `HEALTH_PUBLIC=true`.
- `system_configs.config_value` is written encrypted via AES-256 (`enc:v1:` payload format).
- Repositories enforce organization context via shared base repository.
- AJAX POST requests must include `X-CSRF-TOKEN`.

## Accounting core notes
- Journal posting is centralized in `JournalPostingService` with atomic transaction boundaries.
- Journal tables are immutable by trigger (`UPDATE`/`DELETE` blocked).
- Accounting period guard blocks postings in closed periods and when no open period exists.
- Trial balance, P&L, and balance sheet are computed directly from journal tables.
