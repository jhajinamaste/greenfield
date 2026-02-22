# Greenfield ERP - Phase 1 Foundation

## Run order
1. Copy `.env.example` to `.env` and configure DB.
2. Run `database/migrations/001_phase1_core.sql`.
3. Run `database/migrations/002_phase1_hardening.sql`.
4. Run `database/migrations/010_accounting_core.sql`.
5. Run `database/migrations/011_username_auth.sql`.
6. Run `database/migrations/012_accounting_core_hardening.sql`.
7. Run `database/migrations/020_inventory_core.sql`.
8. Run `database/migrations/021_inventory_hardening.sql`.
9. Run `database/seeders/001_phase1_seed.sql`.
10. Run `database/seeders/002_phase1_hardening_seed.sql`.
11. Run `database/seeders/010_accounting_core_seed.sql`.
12. Run `database/seeders/020_inventory_core_seed.sql`.
13. Serve `public/` through XAMPP.
14. Visit `/greenfield/public/login`.

## Default demo credentials
- Organization: `DEMO`
- Username: `admin`
- Password: `Admin@12345`

## Smoke test
`php tests/smoke/phase1_smoke.php`
`php tests/smoke/accounting_core_smoke.php`
`php tests/smoke/inventory_accounting_smoke.php`

## Feature catalog
- See `IMPLEMENTED_FEATURES.txt` for grouped, marketing-ready implemented features.
- Keep `IMPLEMENTED_FEATURES.txt` updated after each implementation phase.

## Hardening notes
- `/health` is restricted unless `HEALTH_PUBLIC=true`.
- `system_configs.config_value` is written encrypted via AES-256 (`enc:v1:` payload format).
- Repositories enforce organization context via shared base repository.
- AJAX POST requests must include `X-CSRF-TOKEN`.
- `ACCOUNTING_STRICT_MODE` controls invariant behavior:
  - strict mode `true`: invariant violations throw exceptions.
  - strict mode `false`: violations are logged and surfaced as warnings.

## Accounting core notes
- Journal posting is centralized in `JournalPostingService` with atomic transaction boundaries.
- Journal tables are immutable by trigger (`UPDATE`/`DELETE` blocked).
- Accounting period guard blocks postings in closed periods and when no open period exists.
- Trial balance, P&L, and balance sheet are computed directly from journal tables.
- Correlation IDs are mandatory on journal entries and auto-generated when missing.
- Balance sheet invariants are validated in strict mode and audited on violations.

## Inventory integration notes
- Inventory movements post value changes through `InventoryPostingService` and `JournalPostingService` in one transaction.
- Weighted-average costing is derived from `stock_ledger` and enforced against negative stock.
- Inventory valuation and COGS reporting reconcile stock movements with journal balances.
- `stock_ledger` is immutable at DB level (update/delete blocked by triggers).
- Inventory movements are blocked if entry date is not after the latest closed accounting period end.
- Transfer lifecycle supports issue-to-transit and receive-from-transit accounting completion.
- Stock-to-journal correlation integrity checks detect missing journal links and orphan inventory journals.
- Manual direct journal postings to Inventory account (`1300`) are blocked unless sourced from inventory flow.
- Negative inventory drift detection is available in report integrity checks.
- Global journal imbalance check is available at organization level (`SUM(debit) == SUM(credit)` across all journals).
