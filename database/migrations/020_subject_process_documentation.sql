-- Espaço de documentação de processos associado ao nível Assunto da árvore.
CREATE TABLE IF NOT EXISTS subject_workspaces (
    subject_id INTEGER PRIMARY KEY REFERENCES subjects(id) ON DELETE CASCADE,
    objective VARCHAR(600) NOT NULL DEFAULT '',
    owner_name VARCHAR(255) NOT NULL DEFAULT '',
    audience VARCHAR(600) NOT NULL DEFAULT '',
    documentation_status VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (documentation_status IN ('draft', 'review', 'approved', 'deprecated')),
    version_label VARCHAR(30) NOT NULL DEFAULT '1.0',
    next_review_on DATE NULL,
    description TEXT NOT NULL DEFAULT '',
    process_summary TEXT NOT NULL DEFAULT '',
    process_start TEXT NOT NULL DEFAULT '',
    process_validation TEXT NOT NULL DEFAULT '',
    expected_result TEXT NOT NULL DEFAULT '',
    video_title VARCHAR(255) NOT NULL DEFAULT '',
    video_url TEXT NOT NULL DEFAULT '',
    video_document_id INTEGER NULL REFERENCES documents(id) ON DELETE SET NULL,
    flow_steps JSONB NOT NULL DEFAULT '[]'::jsonb,
    procedure_steps JSONB NOT NULL DEFAULT '[]'::jsonb,
    evidences JSONB NOT NULL DEFAULT '[]'::jsonb,
    faq_items JSONB NOT NULL DEFAULT '[]'::jsonb,
    integrations JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE subject_workspaces
    ADD COLUMN IF NOT EXISTS video_document_id INTEGER NULL REFERENCES documents(id) ON DELETE SET NULL;

DROP TRIGGER IF EXISTS trg_subject_workspaces_updated_at ON subject_workspaces;
CREATE TRIGGER trg_subject_workspaces_updated_at
    BEFORE UPDATE ON subject_workspaces
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

CREATE TABLE IF NOT EXISTS subject_workspace_history (
    id BIGSERIAL PRIMARY KEY,
    subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    actor_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    section VARCHAR(30) NOT NULL CHECK (section IN (
        'overview', 'description', 'flow', 'steps', 'video', 'evidence', 'faq', 'integrations'
    )),
    action VARCHAR(30) NOT NULL DEFAULT 'updated',
    summary VARCHAR(500) NOT NULL DEFAULT '',
    snapshot JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_subject_workspace_history_subject_created
    ON subject_workspace_history(subject_id, created_at DESC, id DESC);
