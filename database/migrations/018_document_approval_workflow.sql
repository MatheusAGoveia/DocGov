-- Aprovação editorial completa: revisão, aprovação, recusa e expiração de pendências.
ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS approval_expires_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS reviewed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS approved_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS rejected_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS rejected_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL;

-- Conteúdos ainda pendentes recebem 1 mês. Publicações não têm prazo editorial.
UPDATE documents
SET approval_expires_at = COALESCE(approval_expires_at, created_at + INTERVAL '1 month')
WHERE status IN ('draft', 'review');

ALTER TABLE documents
    ALTER COLUMN approval_expires_at SET DEFAULT (CURRENT_TIMESTAMP + INTERVAL '1 month');

CREATE INDEX IF NOT EXISTS idx_documents_pending_approval_expiry
    ON documents (approval_expires_at)
    WHERE status IN ('draft', 'review') AND approval_expires_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_documents_reviewed_by ON documents(reviewed_by) WHERE reviewed_by IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_documents_approved_by ON documents(approved_by) WHERE approved_by IS NOT NULL;
