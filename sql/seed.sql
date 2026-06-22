USE brs_system;
-- Password hash for 'Admin@1234' โ€” CHANGE IMMEDIATELY after install
-- Generate with: php -r "echo password_hash('Admin@1234', PASSWORD_ARGON2ID);"
INSERT INTO users (username, password_hash, full_name, role)
VALUES ('admin', '$argon2id$v=19$m=65536,t=4,p=1$bUhxVUVsbDhpUUJ5Zi5OUQ$4tPGhYK7YxKsb7+javGe717/dGt0B3xIw+8wR+khaxw', 'System Administrator', 'admin');

INSERT INTO storage_targets (target_name, provider_type, config_json, is_active)
VALUES ('Local Default', 'local', '{"base_path":"D:\\\\xampp\\\\htdocs\\\\brs\\\\storage"}', 1);

