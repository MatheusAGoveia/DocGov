-- Seções dinâmicas baseadas nos documentos realmente cadastrados.
CREATE TABLE IF NOT EXISTS document_sections (
    section_key VARCHAR(80) PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    editor_kind VARCHAR(20) NOT NULL CHECK (editor_kind IN (
        'richtext', 'file', 'code', 'video', 'link', 'flow', 'orgchart'
    )),
    sort_order INTEGER NOT NULL DEFAULT 100,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT document_sections_key_format CHECK (section_key ~ '^[a-z0-9]+(?:-[a-z0-9]+)*$')
);

DROP TRIGGER IF EXISTS trg_document_sections_updated_at ON document_sections;
CREATE TRIGGER trg_document_sections_updated_at
    BEFORE UPDATE ON document_sections
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

INSERT INTO document_sections (section_key, label, description, editor_kind, sort_order) VALUES
    ('documents', 'Documentos', 'Conteúdos textuais e orientações do assunto.', 'richtext', 10),
    ('process-flow', 'Fluxo do Processo', 'Fluxos operacionais representados graficamente.', 'flow', 20),
    ('organization-chart', 'Organograma', 'Estruturas hierárquicas e organizacionais.', 'orgchart', 30),
    ('attachments', 'Arquivos', 'Arquivos e anexos para consulta ou download.', 'file', 40),
    ('videos', 'Vídeos', 'Vídeos locais ou hospedados externamente.', 'video', 50),
    ('source-code', 'Códigos', 'Trechos de código com realce e cópia.', 'code', 60),
    ('links', 'Links', 'Referências e sistemas externos.', 'link', 70),
    ('faq', 'Perguntas Frequentes', 'Perguntas e respostas relacionadas ao assunto.', 'richtext', 80),
    ('legislation', 'Legislação', 'Leis, decretos, portarias e normas aplicáveis.', 'richtext', 90),
    ('tutorials', 'Tutoriais', 'Orientações práticas e tutoriais.', 'richtext', 100),
    ('manuals', 'Manuais', 'Manuais e procedimentos de referência.', 'richtext', 110),
    ('templates', 'Modelos', 'Modelos e padrões reutilizáveis.', 'richtext', 120),
    ('indicators', 'Indicadores', 'Indicadores e informações de acompanhamento.', 'richtext', 130)
ON CONFLICT (section_key) DO UPDATE SET
    label = EXCLUDED.label,
    description = EXCLUDED.description,
    editor_kind = EXCLUDED.editor_kind,
    sort_order = EXCLUDED.sort_order;

ALTER TABLE documents
    DROP CONSTRAINT IF EXISTS documents_content_type_check;

ALTER TABLE documents
    ADD CONSTRAINT documents_content_type_check
    CHECK (content_type IN ('file', 'text', 'link', 'code', 'video', 'flow', 'orgchart'));

ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS section_key VARCHAR(80),
    ADD COLUMN IF NOT EXISTS structured_content JSONB NULL;

UPDATE documents
SET section_key = CASE content_type
    WHEN 'text' THEN 'documents'
    WHEN 'file' THEN 'attachments'
    WHEN 'video' THEN 'videos'
    WHEN 'code' THEN 'source-code'
    WHEN 'link' THEN 'links'
    WHEN 'flow' THEN 'process-flow'
    WHEN 'orgchart' THEN 'organization-chart'
    ELSE 'documents'
END
WHERE section_key IS NULL OR BTRIM(section_key) = '';

ALTER TABLE documents
    ALTER COLUMN section_key SET DEFAULT 'documents',
    ALTER COLUMN section_key SET NOT NULL;

ALTER TABLE documents
    DROP CONSTRAINT IF EXISTS documents_section_key_fkey;

ALTER TABLE documents
    ADD CONSTRAINT documents_section_key_fkey
    FOREIGN KEY (section_key) REFERENCES document_sections(section_key)
    ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE documents
    DROP CONSTRAINT IF EXISTS documents_structured_content_check;

ALTER TABLE documents
    ADD CONSTRAINT documents_structured_content_check
    CHECK (structured_content IS NULL OR jsonb_typeof(structured_content) = 'object');

CREATE INDEX IF NOT EXISTS idx_documents_subject_section_published
    ON documents(subject_id, section_key, published_at DESC, id DESC)
    WHERE status = 'published';
