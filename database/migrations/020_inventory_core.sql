CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(80) NOT NULL,
    name VARCHAR(190) NOT NULL,
    type ENUM('inventory', 'service') NOT NULL DEFAULT 'inventory',
    costing_method ENUM('weighted_avg') NOT NULL DEFAULT 'weighted_avg',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_org_sku (organization_id, sku),
    KEY idx_products_org_type_active (organization_id, type, is_active),
    CONSTRAINT fk_products_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE inventory_locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    type ENUM('store', 'warehouse', 'service', 'damaged', 'transit') NOT NULL DEFAULT 'store',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inventory_locations_org_branch_name (organization_id, branch_id, name),
    KEY idx_inventory_locations_org_location (organization_id, id),
    KEY idx_inventory_locations_org_branch (organization_id, branch_id),
    CONSTRAINT fk_inventory_locations_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_inventory_locations_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE stock_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    movement_type ENUM('purchase', 'sale', 'adjustment', 'transfer_in', 'transfer_out') NOT NULL,
    quantity DECIMAL(18,4) NOT NULL,
    unit_cost DECIMAL(18,4) NOT NULL,
    reference_type VARCHAR(80) NOT NULL,
    reference_id VARCHAR(120) NULL,
    correlation_id VARCHAR(64) NOT NULL,
    entry_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_stock_ledger_org_product_entry_date (organization_id, product_id, entry_date),
    KEY idx_stock_ledger_org_location (organization_id, location_id),
    KEY idx_stock_ledger_org_branch (organization_id, branch_id),
    KEY idx_stock_ledger_correlation (correlation_id),
    CONSTRAINT chk_stock_ledger_positive_quantity CHECK (quantity > 0),
    CONSTRAINT chk_stock_ledger_non_negative_cost CHECK (unit_cost >= 0),
    CONSTRAINT fk_stock_ledger_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_stock_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_stock_ledger_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_stock_ledger_location FOREIGN KEY (location_id) REFERENCES inventory_locations(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;
