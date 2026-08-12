-- Tema institucional padrão do portal. A preferência claro/escuro permanece individual.
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('portal_theme', '"emerald"'::jsonb)
ON CONFLICT (setting_key) DO NOTHING;
