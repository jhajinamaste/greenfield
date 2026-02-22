INSERT INTO user_branch_access (user_id, organization_id, branch_id)
VALUES (1, 1, 1);

INSERT INTO user_branch_roles (user_id, role_id, organization_id, branch_id)
VALUES (1, 1, 1, 1);

UPDATE system_configs
SET config_encrypted = 0
WHERE organization_id = 1;
