-- Logo institucional configurável pelo Super Admin.
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('system_logo_path', '""'::jsonb)
ON CONFLICT (setting_key) DO NOTHING;
