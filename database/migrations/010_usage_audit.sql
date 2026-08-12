-- Auditoria de uso: acessos, consultas, downloads e ações no painel.
CREATE TABLE IF NOT EXISTS usage_audit_events (
    id BIGSERIAL PRIMARY KEY,
    user_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    event_type VARCHAR(40) NOT NULL,
    resource_type VARCHAR(20) NULL,
    resource_id INT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    ip_address INET NULL,
    user_agent VARCHAR(512) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_usage_audit_event_type CHECK (event_type IN (
        'login', 'portal_view', 'search', 'category_view', 'subcategory_view', 'subject_view',
        'document_view', 'document_file_view', 'document_download', 'external_open',
        'admin_page_view', 'admin_action'
    )),
    CONSTRAINT chk_usage_audit_resource_type CHECK (resource_type IS NULL OR resource_type IN (
        'PORTAL', 'CATEGORY', 'SUBCATEGORY', 'SUBJECT', 'DOCUMENT', 'ADMIN'
    ))
);

CREATE INDEX IF NOT EXISTS idx_usage_audit_created_at
    ON usage_audit_events(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_usage_audit_user_created
    ON usage_audit_events(user_id, created_at DESC)
    WHERE user_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_usage_audit_document_created
    ON usage_audit_events(resource_id, created_at DESC)
    WHERE resource_type = 'DOCUMENT';
CREATE INDEX IF NOT EXISTS idx_usage_audit_type_created
    ON usage_audit_events(event_type, created_at DESC);
