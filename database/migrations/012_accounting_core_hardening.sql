ALTER TABLE accounting_periods ENGINE=InnoDB;
ALTER TABLE chart_of_accounts ENGINE=InnoDB;
ALTER TABLE journal_entries ENGINE=InnoDB;
ALTER TABLE journal_entry_lines ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS sp_validate_accounting_orphans;
DELIMITER //
CREATE PROCEDURE sp_validate_accounting_orphans()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM journal_entry_lines jel
        LEFT JOIN journal_entries je ON je.id = jel.journal_entry_id
        WHERE je.id IS NULL
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Orphan detected: journal_entry_lines.journal_entry_id';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM journal_entry_lines jel
        LEFT JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE coa.id IS NULL
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Orphan detected: journal_entry_lines.account_id';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM journal_entries je
        LEFT JOIN organizations o ON o.id = je.organization_id
        WHERE o.id IS NULL
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Orphan detected: journal_entries.organization_id';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM journal_entries je
        LEFT JOIN branches b ON b.id = je.branch_id
        WHERE je.branch_id IS NOT NULL AND b.id IS NULL
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Orphan detected: journal_entries.branch_id';
    END IF;
END//
DELIMITER ;

CALL sp_validate_accounting_orphans();
DROP PROCEDURE sp_validate_accounting_orphans;

ALTER TABLE journal_entry_lines
    DROP FOREIGN KEY fk_journal_entry_lines_entry,
    DROP FOREIGN KEY fk_journal_entry_lines_account;

ALTER TABLE journal_entries
    DROP FOREIGN KEY fk_journal_entries_org,
    DROP FOREIGN KEY fk_journal_entries_branch;

ALTER TABLE journal_entry_lines
    ADD CONSTRAINT fk_journal_entry_lines_entry
        FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_journal_entry_lines_account
        FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE journal_entries
    ADD CONSTRAINT fk_journal_entries_org
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_journal_entries_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

UPDATE journal_entries
SET correlation_id = UUID()
WHERE correlation_id IS NULL OR correlation_id = '';

ALTER TABLE journal_entries
    MODIFY correlation_id VARCHAR(64) NOT NULL;

ALTER TABLE journal_entries
    ADD INDEX idx_journal_entries_org_branch_entry_date (organization_id, branch_id, entry_date);

ALTER TABLE journal_entry_lines
    ADD INDEX idx_journal_entry_lines_account_journal (account_id, journal_entry_id);

ALTER TABLE accounting_periods
    DROP INDEX idx_accounting_periods_org_status,
    ADD INDEX idx_accounting_periods_org_status (organization_id, status);
