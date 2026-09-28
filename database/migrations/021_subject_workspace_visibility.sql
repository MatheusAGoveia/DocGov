-- Permite ao gestor escolher, por assunto, quais seções aparecem no portal.
ALTER TABLE subject_workspaces
    ADD COLUMN IF NOT EXISTS enabled_sections JSONB NOT NULL DEFAULT
        '["overview","description","flow","steps","video","evidence","faq","permissions","integrations","history"]'::jsonb;

ALTER TABLE subject_workspaces
    DROP CONSTRAINT IF EXISTS subject_workspaces_enabled_sections_array_check;

ALTER TABLE subject_workspaces
    ADD CONSTRAINT subject_workspaces_enabled_sections_array_check
    CHECK (jsonb_typeof(enabled_sections) = 'array');

ALTER TABLE subject_workspace_history
    DROP CONSTRAINT IF EXISTS subject_workspace_history_section_check;

ALTER TABLE subject_workspace_history
    ADD CONSTRAINT subject_workspace_history_section_check
    CHECK (section IN (
        'visibility', 'overview', 'description', 'flow', 'steps',
        'video', 'evidence', 'faq', 'integrations'
    ));
