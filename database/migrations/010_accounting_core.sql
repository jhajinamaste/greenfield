CREATE TABLE accounting_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    closed_at TIMESTAMP NULL,
    closed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounting_periods_org_start_end (organization_id, period_start, period_end),
    KEY idx_accounting_periods_org_status (organization_id, status),
    CONSTRAINT fk_accounting_periods_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT fk_accounting_periods_closed_by FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE chart_of_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(190) NOT NULL,
    type ENUM('asset', 'liability', 'equity', 'income', 'expense') NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    is_system_account TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chart_of_accounts_org_code (organization_id, code),
    KEY idx_chart_of_accounts_org_type_active (organization_id, type, is_active),
    CONSTRAINT fk_chart_of_accounts_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT fk_chart_of_accounts_parent FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(id)
) ENGINE=InnoDB;

CREATE TABLE journal_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    entry_date DATE NOT NULL,
    reference_type VARCHAR(80) NOT NULL,
    reference_id VARCHAR(120) NULL,
    description VARCHAR(255) NOT NULL,
    period_id BIGINT UNSIGNED NULL,
    correlation_id VARCHAR(64) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_journal_entries_org_entry_date (organization_id, entry_date),
    KEY idx_journal_entries_period (period_id),
    KEY idx_journal_entries_correlation_id (correlation_id),
    CONSTRAINT fk_journal_entries_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT fk_journal_entries_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_journal_entries_period FOREIGN KEY (period_id) REFERENCES accounting_periods(id),
    CONSTRAINT fk_journal_entries_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE journal_entry_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    debit_amount DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
    credit_amount DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_journal_entry_lines_journal_entry (journal_entry_id),
    KEY idx_journal_entry_lines_account_id (account_id),
    CONSTRAINT fk_journal_entry_lines_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_journal_entry_lines_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id),
    CONSTRAINT chk_journal_entry_lines_non_negative CHECK (debit_amount >= 0 AND credit_amount >= 0)
) ENGINE=InnoDB;

DROP TRIGGER IF EXISTS trg_journal_entries_no_update;
CREATE TRIGGER trg_journal_entries_no_update
BEFORE UPDATE ON journal_entries
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'journal_entries are immutable and cannot be updated';

DROP TRIGGER IF EXISTS trg_journal_entries_no_delete;
CREATE TRIGGER trg_journal_entries_no_delete
BEFORE DELETE ON journal_entries
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'journal_entries are immutable and cannot be deleted';

DROP TRIGGER IF EXISTS trg_journal_entry_lines_no_update;
CREATE TRIGGER trg_journal_entry_lines_no_update
BEFORE UPDATE ON journal_entry_lines
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'journal_entry_lines are immutable and cannot be updated';

DROP TRIGGER IF EXISTS trg_journal_entry_lines_no_delete;
CREATE TRIGGER trg_journal_entry_lines_no_delete
BEFORE DELETE ON journal_entry_lines
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'journal_entry_lines are immutable and cannot be deleted';
