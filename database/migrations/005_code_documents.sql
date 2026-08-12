-- Documentos de código: conteúdo textual com linguagem manual ou autodetectada.
ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS code_language VARCHAR(50) NOT NULL DEFAULT 'auto';

ALTER TABLE documents
    DROP CONSTRAINT IF EXISTS documents_content_type_check;

ALTER TABLE documents
    ADD CONSTRAINT documents_content_type_check
    CHECK (content_type IN ('file', 'text', 'link', 'code'));

