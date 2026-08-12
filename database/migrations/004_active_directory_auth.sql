-- Metadados de identidade corporativa. Senhas do AD nunca são persistidas.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS auth_source VARCHAR(20) NOT NULL DEFAULT 'local',
    ADD COLUMN IF NOT EXISTS ad_object_guid VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMPTZ NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_users_ad_object_guid
    ON users(ad_object_guid)
    WHERE ad_object_guid IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_users_auth_source ON users(auth_source);
