ALTER TABLE users
    ADD COLUMN username VARCHAR(120) NULL AFTER full_name;

UPDATE users
SET username = CONCAT('user', id)
WHERE username IS NULL OR username = '';

UPDATE users
SET username = 'admin'
WHERE id = 1 AND organization_id = 1;

ALTER TABLE users
    MODIFY username VARCHAR(120) NOT NULL;

ALTER TABLE users
    ADD UNIQUE KEY uq_users_org_username (organization_id, username);
