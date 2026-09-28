-- Fluxo editorial da documentação estruturada no nível Assunto.
ALTER TABLE subject_workspaces
    ADD COLUMN IF NOT EXISTS submitted_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS reviewed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS approved_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS returned_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS returned_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS return_reason TEXT NULL,
    ADD COLUMN IF NOT EXISTS deprecated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS deprecated_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS workflow_note TEXT NULL,
    ADD COLUMN IF NOT EXISTS review_reminder_sent_for DATE NULL;

ALTER TABLE subject_workspace_history
    DROP CONSTRAINT IF EXISTS subject_workspace_history_section_check;

ALTER TABLE subject_workspace_history
    ADD CONSTRAINT subject_workspace_history_section_check
    CHECK (section IN (
        'visibility', 'overview', 'description', 'flow', 'steps',
        'video', 'evidence', 'faq', 'integrations', 'workflow'
    ));

CREATE INDEX IF NOT EXISTS idx_subject_workspaces_review_due
    ON subject_workspaces(next_review_on)
    WHERE documentation_status = 'approved' AND next_review_on IS NOT NULL;

-- Preserva como versão publicada os assuntos que já estavam homologados
-- antes da introdução do fluxo controlado.
UPDATE subject_workspaces
SET approved_by = COALESCE(approved_by, updated_by),
    approved_at = COALESCE(approved_at, updated_at),
    reviewed_by = COALESCE(reviewed_by, updated_by),
    reviewed_at = COALESCE(reviewed_at, updated_at)
WHERE documentation_status = 'approved';

INSERT INTO subject_workspace_history (subject_id, actor_id, section, action, summary, snapshot, created_at)
SELECT sw.subject_id,
       sw.approved_by,
       'workflow',
       'approve',
       'Versão homologada antes da ativação do fluxo editorial.',
       to_jsonb(sw),
       COALESCE(sw.approved_at, sw.updated_at, CURRENT_TIMESTAMP)
FROM subject_workspaces sw
WHERE sw.documentation_status = 'approved'
  AND NOT EXISTS (
      SELECT 1
      FROM subject_workspace_history h
      WHERE h.subject_id = sw.subject_id
        AND h.section = 'workflow'
        AND h.action = 'approve'
  );
