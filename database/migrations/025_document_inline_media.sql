-- Imagens inseridas dentro de documentos formatados. O vínculo só é criado
-- quando o documento é salvo; uploads abandonados podem ser expurgados.
CREATE TABLE IF NOT EXISTS document_inline_media (
    id BIGSERIAL PRIMARY KEY,
    document_id INTEGER NULL REFERENCES documents(id) ON DELETE CASCADE,
    uploaded_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    stored_filename VARCHAR(100) NOT NULL UNIQUE,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(30) NOT NULL CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/gif', 'image/webp')),
    file_size INTEGER NOT NULL CHECK (file_size > 0 AND file_size <= 10485760),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_document_inline_media_document
    ON document_inline_media(document_id);
CREATE INDEX IF NOT EXISTS idx_document_inline_media_unbound
    ON document_inline_media(created_at) WHERE document_id IS NULL;
