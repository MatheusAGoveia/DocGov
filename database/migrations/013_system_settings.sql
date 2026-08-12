-- Configurações administrativas centralizadas e auditáveis.
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value JSONB NOT NULL,
    updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_system_settings_updated_at
    ON system_settings(updated_at DESC);

INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('portal_name', '"DocGov"'::jsonb),
    ('organization_name', '"Prefeitura Municipal"'::jsonb),
    ('portal_description', '"Sistema de Gestão Documental"'::jsonb),
    ('support_email', '""'::jsonb),
    ('timezone', '"America/Sao_Paulo"'::jsonb),
    ('session_timeout_minutes', '120'::jsonb),
    ('cors_enabled', 'false'::jsonb),
    ('cors_allowed_origins', '[]'::jsonb),
    ('cors_allowed_methods', '["GET", "POST", "DELETE", "OPTIONS"]'::jsonb),
    ('cors_allow_credentials', 'false'::jsonb),
    ('maintenance_enabled', 'false'::jsonb),
    ('maintenance_start_at', 'null'::jsonb),
    ('maintenance_end_at', 'null'::jsonb),
    ('maintenance_title', '"Sistema em manutenção"'::jsonb),
    ('maintenance_message', '"Estamos realizando melhorias. O acesso será restabelecido em breve."'::jsonb)
ON CONFLICT (setting_key) DO NOTHING;
