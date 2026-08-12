-- Identifica a origem de contas AD em ambientes com mais de um domínio.
ALTER TABLE users ADD COLUMN IF NOT EXISTS ad_domain VARCHAR(50) NULL;

UPDATE users SET ad_domain = 'BETIM'
WHERE auth_source = 'ad' AND ad_domain IS NULL;

ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_ad_domain_format;
ALTER TABLE users ADD CONSTRAINT chk_users_ad_domain_format
    CHECK (ad_domain IS NULL OR ad_domain ~ '^[A-Z0-9._-]{1,50}$');

CREATE INDEX IF NOT EXISTS idx_users_ad_domain_username
    ON users(ad_domain, LOWER(username)) WHERE auth_source = 'ad';
