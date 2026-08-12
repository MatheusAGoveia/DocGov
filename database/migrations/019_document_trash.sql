-- Lixeira explícita: separa documentos arquivados manualmente de conteúdos
-- inativos por expiração ou por outros estados administrativos.
ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS trashed_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS trashed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS trashed_from_status VARCHAR(20) NULL
        CHECK (trashed_from_status IN ('draft', 'review', 'published'));

-- Recupera os documentos que já haviam sido enviados à lixeira pelo fluxo antigo.
-- Apenas o último evento editorial de cada documento é considerado, assim itens
-- expirados ou posteriormente alterados não são classificados como lixeira.
WITH latest_history AS (
    SELECT DISTINCT ON (document_id)
           document_id, actor_id, previous_status, created_at, action
    FROM document_workflow_history
    ORDER BY document_id, created_at DESC, id DESC
)
UPDATE documents d
SET trashed_at = h.created_at,
    trashed_by = h.actor_id,
    trashed_from_status = CASE
        WHEN h.previous_status IN ('draft', 'review', 'published') THEN h.previous_status
        ELSE 'draft'
    END
FROM latest_history h
WHERE d.id = h.document_id
  AND d.status = 'inactive'
  AND h.action = 'archived'
  AND d.trashed_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_documents_trash
    ON documents (trashed_at DESC)
    WHERE trashed_at IS NOT NULL;
