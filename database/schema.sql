-- ==============================================================================
-- DATABASE SCHEMA: SISTEMA DE GESTÃO DOCUMENTAL (DocGov)
-- SGDB: PostgreSQL 14+
-- Fonte Única de Verdade
-- ==============================================================================

-- 0. EXTENSÕES & TRIGGER REUTILIZÁVEL DE UPDATED_AT
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- LIMPEZA DE SEGUNDA CAMADA (Para reinicialização completa se necessário)
DROP TABLE IF EXISTS permissions CASCADE;
DROP TABLE IF EXISTS group_access CASCADE;
DROP TABLE IF EXISTS user_groups CASCADE;
DROP TABLE IF EXISTS groups CASCADE;
DROP TABLE IF EXISTS favorites CASCADE;
DROP TABLE IF EXISTS documents CASCADE;
DROP TABLE IF EXISTS subjects CASCADE;
DROP TABLE IF EXISTS subcategories CASCADE;
DROP TABLE IF EXISTS categories CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- ------------------------------------------------------------------------------
-- 1. TABELA: users (Usuários do Sistema)
-- ------------------------------------------------------------------------------
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'reader' CHECK (role IN ('admin', 'editor', 'reader')),
    avatar TEXT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ------------------------------------------------------------------------------
-- 2. TABELA: categories (Categorias Principais)
-- ------------------------------------------------------------------------------
CREATE TABLE categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT DEFAULT '',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER trg_categories_updated_at
    BEFORE UPDATE ON categories
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ------------------------------------------------------------------------------
-- 3. TABELA: subcategories (Subcategorias vinculadas a uma Categoria)
-- ------------------------------------------------------------------------------
CREATE TABLE subcategories (
    id SERIAL PRIMARY KEY,
    category_id INT NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT DEFAULT '',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_subcategories_category_slug UNIQUE (category_id, slug)
);

CREATE TRIGGER trg_subcategories_updated_at
    BEFORE UPDATE ON subcategories
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ------------------------------------------------------------------------------
-- 4. TABELA: subjects (Assuntos vinculados a uma Subcategoria)
-- ------------------------------------------------------------------------------
CREATE TABLE subjects (
    id SERIAL PRIMARY KEY,
    subcategory_id INT NOT NULL REFERENCES subcategories(id) ON DELETE RESTRICT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT DEFAULT '',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_subjects_subcategory_slug UNIQUE (subcategory_id, slug)
);

CREATE TRIGGER trg_subjects_updated_at
    BEFORE UPDATE ON subjects
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ------------------------------------------------------------------------------
-- 5. TABELA: documents (Documentos / Conteúdos do Sistema)
-- ------------------------------------------------------------------------------
CREATE TABLE documents (
    id SERIAL PRIMARY KEY,
    subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE RESTRICT,
    created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT DEFAULT '',
    content_type VARCHAR(20) NOT NULL CHECK (content_type IN ('file', 'text', 'link')),
    status VARCHAR(20) NOT NULL DEFAULT 'published' CHECK (status IN ('draft', 'published', 'inactive')),
    published_at TIMESTAMPTZ NULL,

    -- Para tipo 'file'
    original_filename VARCHAR(255) NULL,
    stored_filename VARCHAR(255) NULL,
    file_path TEXT NULL,
    mime_type VARCHAR(100) NULL,
    file_extension VARCHAR(20) NULL,
    file_size BIGINT NULL,

    -- Para tipo 'text'
    text_content TEXT NULL,

    -- Para tipo 'link'
    external_url TEXT NULL,

    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_documents_subject_slug UNIQUE (subject_id, slug)
);

CREATE TRIGGER trg_documents_updated_at
    BEFORE UPDATE ON documents
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ------------------------------------------------------------------------------
-- 6. TABELA: favorites (Documentos Favoritados por Usuários)
-- ------------------------------------------------------------------------------
CREATE TABLE favorites (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    document_id INT NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_favorites_user_document UNIQUE (user_id, document_id)
);

-- ÍNDICES DE PERFORMANCE E INTEGRIDADE
CREATE INDEX idx_subcategories_category_id ON subcategories(category_id);
CREATE INDEX idx_subjects_subcategory_id ON subjects(subcategory_id);
CREATE INDEX idx_documents_subject_id ON documents(subject_id);
CREATE INDEX idx_documents_created_by ON documents(created_by);
CREATE INDEX idx_favorites_user_id ON favorites(user_id);
CREATE INDEX idx_favorites_document_id ON favorites(document_id);

CREATE INDEX idx_categories_active ON categories(active) WHERE active = TRUE;
CREATE INDEX idx_subcategories_active ON subcategories(category_id, active) WHERE active = TRUE;
CREATE INDEX idx_subjects_active ON subjects(subcategory_id, active) WHERE active = TRUE;
CREATE INDEX idx_documents_status_published ON documents(subject_id, status, published_at DESC) WHERE status = 'published';
CREATE INDEX idx_documents_title ON documents(title);

-- ------------------------------------------------------------------------------
-- 7. ESTRUTURA DE PERMISSÕES E GRUPOS DE ACESSO (groups, user_groups, permissions)
-- ------------------------------------------------------------------------------

CREATE TABLE groups (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT DEFAULT '',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER trg_groups_updated_at
    BEFORE UPDATE ON groups
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

CREATE TABLE user_groups (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    group_id INT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, group_id)
);

CREATE INDEX idx_user_groups_user_id ON user_groups(user_id);
CREATE INDEX idx_user_groups_group_id ON user_groups(group_id);

CREATE TABLE permissions (
    id SERIAL PRIMARY KEY,
    
    -- Principal: Exatamente UM deve ser NOT NULL (user_id ou group_id)
    user_id INT NULL REFERENCES users(id) ON DELETE CASCADE,
    group_id INT NULL REFERENCES groups(id) ON DELETE CASCADE,
    
    -- Recurso Alvo: Exatamente UM deve ser NOT NULL (category_id, subcategory_id ou subject_id)
    category_id INT NULL REFERENCES categories(id) ON DELETE CASCADE,
    subcategory_id INT NULL REFERENCES subcategories(id) ON DELETE CASCADE,
    subject_id INT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    
    -- Nível de Permissão: 'view', 'edit', 'admin'
    permission_level VARCHAR(10) NOT NULL CHECK (permission_level IN ('view', 'edit', 'admin')),
    
    -- Auditoria
    created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Restrições de Integridade Específicas
    CONSTRAINT chk_permissions_principal CHECK (
        num_nonnulls(user_id, group_id) = 1
    ),
    CONSTRAINT chk_permissions_resource CHECK (
        num_nonnulls(category_id, subcategory_id, subject_id) = 1
    )
);

CREATE TRIGGER trg_permissions_updated_at
    BEFORE UPDATE ON permissions
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ÍNDICES DE UNICIDADE PARCIAL (Evita duplicidade do mesmo Principal + Recurso)
CREATE UNIQUE INDEX uk_permissions_user_category 
    ON permissions(user_id, category_id) WHERE user_id IS NOT NULL AND category_id IS NOT NULL;

CREATE UNIQUE INDEX uk_permissions_user_subcategory 
    ON permissions(user_id, subcategory_id) WHERE user_id IS NOT NULL AND subcategory_id IS NOT NULL;

CREATE UNIQUE INDEX uk_permissions_user_subject 
    ON permissions(user_id, subject_id) WHERE user_id IS NOT NULL AND subject_id IS NOT NULL;

CREATE UNIQUE INDEX uk_permissions_group_category 
    ON permissions(group_id, category_id) WHERE group_id IS NOT NULL AND category_id IS NOT NULL;

CREATE UNIQUE INDEX uk_permissions_group_subcategory 
    ON permissions(group_id, subcategory_id) WHERE group_id IS NOT NULL AND subcategory_id IS NOT NULL;

CREATE UNIQUE INDEX uk_permissions_group_subject 
    ON permissions(group_id, subject_id) WHERE group_id IS NOT NULL AND subject_id IS NOT NULL;

-- ÍNDICES DE ALTA PERFORMANCE PARA CONSULTAS E JOINS
CREATE INDEX idx_permissions_user_id ON permissions(user_id) WHERE user_id IS NOT NULL;
CREATE INDEX idx_permissions_group_id ON permissions(group_id) WHERE group_id IS NOT NULL;
CREATE INDEX idx_permissions_category_id ON permissions(category_id) WHERE category_id IS NOT NULL;
CREATE INDEX idx_permissions_subcategory_id ON permissions(subcategory_id) WHERE subcategory_id IS NOT NULL;
CREATE INDEX idx_permissions_subject_id ON permissions(subject_id) WHERE subject_id IS NOT NULL;

