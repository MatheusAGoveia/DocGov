-- ==============================================================================
-- MIGRATION: 004_permission_audit.sql
-- DESCRIÇÃO: Tabela de auditoria para rastreamento de alterações de permissões
-- ==============================================================================

CREATE TABLE IF NOT EXISTS permission_audit (
    id SERIAL PRIMARY KEY,
    user_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(50) NOT NULL CHECK (action IN ('PERMISSION_CREATED', 'PERMISSION_CHANGED', 'PERMISSION_REMOVED')),
    principal_type VARCHAR(10) NOT NULL CHECK (principal_type IN ('USER', 'TEAM')),
    principal_id INT NOT NULL,
    resource_type VARCHAR(20) NOT NULL CHECK (resource_type IN ('CATEGORY', 'SUBCATEGORY', 'SUBJECT')),
    resource_id INT NOT NULL,
    old_permission VARCHAR(10) NULL,
    new_permission VARCHAR(10) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_perm_audit_user ON permission_audit(user_id);
CREATE INDEX IF NOT EXISTS idx_perm_audit_resource ON permission_audit(resource_type, resource_id);
CREATE INDEX IF NOT EXISTS idx_perm_audit_created_at ON permission_audit(created_at DESC);
