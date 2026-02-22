ALTER TABLE system_configs
    ADD COLUMN config_encrypted TINYINT(1) NOT NULL DEFAULT 0 AFTER config_value;

ALTER TABLE audit_logs
    ADD COLUMN before_state JSON NULL AFTER metadata_json,
    ADD COLUMN after_state JSON NULL AFTER before_state,
    ADD COLUMN request_id VARCHAR(64) NULL AFTER after_state,
    ADD COLUMN correlation_id VARCHAR(64) NULL AFTER request_id,
    ADD COLUMN ip VARCHAR(45) NULL AFTER correlation_id;

ALTER TABLE audit_logs
    ADD KEY idx_audit_request_id (request_id),
    ADD KEY idx_audit_correlation_id (correlation_id);

UPDATE audit_logs
SET ip = ip_address
WHERE ip IS NULL AND ip_address IS NOT NULL;

CREATE TABLE user_branch_access (
    user_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, organization_id, branch_id),
    KEY idx_uba_org_branch (organization_id, branch_id),
    CONSTRAINT fk_uba_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_uba_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT fk_uba_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE user_branch_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id, organization_id, branch_id),
    KEY idx_ubr_org_branch (organization_id, branch_id),
    CONSTRAINT fk_ubr_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_ubr_role FOREIGN KEY (role_id) REFERENCES roles(id),
    CONSTRAINT fk_ubr_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT fk_ubr_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;
