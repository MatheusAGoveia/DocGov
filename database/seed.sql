-- ==============================================================================
-- SEED DE DESENVOLVIMENTO: SISTEMA DE GESTÃO DOCUMENTAL (DocGov)
-- Carga inicial enxuta para testes de ambiente local
-- ==============================================================================

-- 1. USUÁRIOS DE TESTE (Senhas hashed com bcrypt: 'admin123' / 'user123')
INSERT INTO users (name, username, email, password_hash, role, active) VALUES
('Samuel Oliveira', 'samuel', 'samuel@prefeitura.gov.br', '$2y$10$eE.lC/xI7W0/b6C4Zt4F.O6Y9T8d9O8K.rKq4r.W/1p9/G9q9u.2m', 'reader', TRUE),
('Maria Santos', 'maria', 'maria@prefeitura.gov.br', '$2y$10$eE.lC/xI7W0/b6C4Zt4F.O6Y9T8d9O8K.rKq4r.W/1p9/G9q9u.2m', 'reader', TRUE),
('João Silva', 'joao', 'joao@prefeitura.gov.br', '$2y$10$eE.lC/xI7W0/b6C4Zt4F.O6Y9T8d9O8K.rKq4r.W/1p9/G9q9u.2m', 'reader', TRUE)
ON CONFLICT (username) DO NOTHING;

-- 2. CATEGORIAS
INSERT INTO categories (id, name, slug, description, active) VALUES
(1, 'Recursos Humanos', 'recursos-humanos', 'Políticas internas, licenças e gestão de servidores públicos.', TRUE),
(2, 'Saúde & Vigilância', 'saude-vigilancia', 'Atendimentos SUS, vacinação e decretos de saúde.', TRUE)
ON CONFLICT (slug) DO NOTHING;
SELECT setval('categories_id_seq', (SELECT MAX(id) FROM categories));

-- 3. SUBCATEGORIAS
INSERT INTO subcategories (id, category_id, name, slug, description, active) VALUES
(1, 1, 'Férias e Licenças', 'ferias-e-licencas', 'Solicitações e agendamentos de férias.', TRUE),
(2, 2, 'Atendimento SUS', 'atendimento-sus', 'Horários de UBS e agendamentos de consultas.', TRUE)
ON CONFLICT (category_id, slug) DO NOTHING;
SELECT setval('subcategories_id_seq', (SELECT MAX(id) FROM subcategories));

-- 4. ASSUNTOS
INSERT INTO subjects (id, subcategory_id, name, slug, description, active) VALUES
(1, 1, 'Solicitação de Férias', 'solicitacao-de-ferias', 'Formulários e diretrizes para férias regulamentares.', TRUE),
(2, 2, 'Agendamento UBS', 'agendamento-ubs', 'Procedimentos para agendamento nas unidades básicas.', TRUE)
ON CONFLICT (subcategory_id, slug) DO NOTHING;
SELECT setval('subjects_id_seq', (SELECT MAX(id) FROM subjects));

-- 5. DOCUMENTOS DE TESTE
INSERT INTO documents (
    id, subject_id, created_by, title, slug, description,
    content_type, status, published_at, text_content, external_url
) VALUES
(
    1, 1, 1,
    'Requerimento Padrão de Férias', 'requerimento-padrao-de-ferias',
    'Formulário oficial para solicitação de férias dos servidores.',
    'text', 'published', CURRENT_TIMESTAMP,
    '<h3>Instruções para Solicitação de Férias</h3><p>O requerimento deve ser protocolado com no mínimo 30 dias de antecedência junto ao setor de Recursos Humanos.</p>',
    NULL
),
(
    2, 2, 2,
    'Portal de Agendamento Online SUS', 'portal-de-agendamento-online-sus',
    'Link externo para agendamento de consultas nas Unidades Básicas de Saúde.',
    'link', 'published', CURRENT_TIMESTAMP,
    NULL,
    'https://saude.prefeitura.gov.br/agendamento'
)
ON CONFLICT (subject_id, slug) DO NOTHING;
SELECT setval('documents_id_seq', (SELECT MAX(id) FROM documents));

-- 6. FAVORITOS DE TESTE
INSERT INTO favorites (user_id, document_id) VALUES
(3, 1)
ON CONFLICT (user_id, document_id) DO NOTHING;
