-- Amplia o modo de manutenção com controle operacional e escopo por área.
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('maintenance_mode', '"full"'::jsonb),
    ('maintenance_scope', '["portal", "admin", "api", "files"]'::jsonb),
    ('maintenance_reason', '"Atualização planejada"'::jsonb),
    ('maintenance_reference', '""'::jsonb),
    ('maintenance_responsible', '""'::jsonb),
    ('maintenance_progress', '0'::jsonb),
    ('maintenance_announce_minutes', '60'::jsonb),
    ('maintenance_auto_refresh_seconds', '30'::jsonb)
ON CONFLICT (setting_key) DO NOTHING;
