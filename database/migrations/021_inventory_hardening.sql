DROP TRIGGER IF EXISTS trg_stock_ledger_no_update;
CREATE TRIGGER trg_stock_ledger_no_update
BEFORE UPDATE ON stock_ledger
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'Stock ledger entries are immutable';

DROP TRIGGER IF EXISTS trg_stock_ledger_no_delete;
CREATE TRIGGER trg_stock_ledger_no_delete
BEFORE DELETE ON stock_ledger
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'Stock ledger entries cannot be deleted';

ALTER TABLE stock_ledger
    ADD INDEX idx_stock_ledger_org_product_entry_date_id (organization_id, product_id, entry_date, id);
