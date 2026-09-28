-- Segunda camada de autorização: capacidades administrativas globais por equipe.
-- Essas capacidades não concedem acesso a categorias, documentos ou à própria
-- gestão de equipes; essa separação evita escalada de privilégio.

CREATE TABLE IF NOT EXISTS group_system_capabilities (
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    capability VARCHAR(80) NOT NULL CHECK (capability IN (
        'system.settings.manage',
        'system.authentication.manage',
        'system.directory.manage',
        'system.audit.view',
        'system.tags.manage'
    )),
    granted_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, capability)
);

CREATE INDEX IF NOT EXISTS idx_group_system_capabilities_capability
    ON group_system_capabilities(capability, group_id);

CREATE TABLE IF NOT EXISTS system_capability_audit (
    id BIGSERIAL PRIMARY KEY,
    actor_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    group_id INTEGER NULL,
    group_name VARCHAR(255) NOT NULL,
    capability VARCHAR(80) NOT NULL CHECK (capability IN (
        'system.settings.manage',
        'system.authentication.manage',
        'system.directory.manage',
        'system.audit.view',
        'system.tags.manage'
    )),
    action VARCHAR(10) NOT NULL CHECK (action IN ('GRANTED', 'REVOKED')),
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_system_capability_audit_created_at
    ON system_capability_audit(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_system_capability_audit_group
    ON system_capability_audit(group_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_system_capability_audit_actor
    ON system_capability_audit(actor_id, created_at DESC) WHERE actor_id IS NOT NULL;
