INSERT INTO organizations (id, code, name, status) VALUES (1, 'DEMO', 'Demo Holdings', 'active');
INSERT INTO branches (id, organization_id, code, name, status) VALUES (1, 1, 'HQ', 'Headquarters', 'active');

INSERT INTO permissions (code, name) VALUES
('dashboard.view', 'View dashboard'),
('users.manage', 'Manage users'),
('branches.manage', 'Manage branches'),
('settings.manage', 'Manage settings'),
('audit.view', 'View audit logs');

INSERT INTO roles (id, organization_id, code, name) VALUES
(1, 1, 'super_admin', 'Super Admin'),
(2, 1, 'org_admin', 'Organization Admin'),
(3, 1, 'auditor', 'Auditor');

INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;

INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE code IN ('dashboard.view', 'users.manage', 'branches.manage', 'settings.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE code IN ('dashboard.view', 'audit.view');

INSERT INTO users (id, organization_id, branch_id, full_name, username, email, password_hash, status)
VALUES (1, 1, 1, 'Platform Admin', 'admin', 'admin@demo.local', '$argon2id$v=19$m=65536,t=4,p=1$TnEySm1wVWkvN1Blc2hpQQ$1+s4UB0aNuyFuYPb4ToWG+vscaj06Q86MZIz5n9rRrk', 'active');

INSERT INTO user_roles (user_id, role_id, organization_id) VALUES (1, 1, 1);

INSERT INTO feature_flags (organization_id, code, is_enabled, rollout_percent) VALUES
(1, 'dashboard.v2', 1, 100),
(1, 'audit.log.streaming', 0, 0);

INSERT INTO system_configs (organization_id, config_group, config_key, config_value) VALUES
(1, 'app', 'timezone', 'UTC'),
(1, 'security', 'session_timeout_minutes', '180');
