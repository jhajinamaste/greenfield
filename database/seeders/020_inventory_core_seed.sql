INSERT INTO chart_of_accounts (organization_id, code, name, type, is_system_account, is_active)
VALUES
    (1, '1300', 'Inventory', 'asset', 1, 1),
    (1, '1305', 'Stock In Transit', 'asset', 1, 1),
    (1, '2200', 'Purchase Clearing', 'liability', 1, 1),
    (1, '5200', 'COGS', 'expense', 1, 1),
    (1, '5201', 'Stock Adjustment', 'expense', 1, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    type = VALUES(type),
    is_system_account = VALUES(is_system_account),
    is_active = VALUES(is_active);
