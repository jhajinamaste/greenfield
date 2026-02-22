INSERT INTO chart_of_accounts (organization_id, code, name, type, is_system_account, is_active)
VALUES
    (1, '1000', 'Cash', 'asset', 1, 1),
    (1, '1010', 'Bank', 'asset', 1, 1),
    (1, '1100', 'Accounts Receivable', 'asset', 1, 1),
    (1, '1200', 'Gateway Clearing', 'asset', 1, 1),
    (1, '2000', 'Accounts Payable', 'liability', 1, 1),
    (1, '2100', 'Salary Payable', 'liability', 1, 1),
    (1, '4000', 'Sales', 'income', 1, 1),
    (1, '4010', 'Sales Return', 'income', 1, 1),
    (1, '5000', 'Expense', 'expense', 1, 1),
    (1, '5100', 'Salary Expense', 'expense', 1, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    type = VALUES(type),
    is_system_account = VALUES(is_system_account),
    is_active = VALUES(is_active);

INSERT INTO accounting_periods (organization_id, period_start, period_end, status)
VALUES (1, '2026-01-01', '2026-12-31', 'open')
ON DUPLICATE KEY UPDATE
    status = VALUES(status);
