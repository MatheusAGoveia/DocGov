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
DROP TABLE IF EXISTS system_settings CASCADE;
DROP TABLE IF EXISTS permissions CASCADE;
DROP TABLE IF EXISTS permission_audit CASCADE;
DROP TABLE IF EXISTS group_access CASCADE;
DROP TABLE IF EXISTS user_groups CASCADE;
DROP TABLE IF EXISTS groups CASCADE;
DROP TABLE IF EXISTS favorites CASCADE;
DROP TABLE IF EXISTS document_tags CASCADE;
DROP TABLE IF EXISTS tag_aliases CASCADE;
DROP TABLE IF EXISTS tags CASCADE;
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
    auth_source VARCHAR(20) NOT NULL DEFAULT 'local',
    ad_object_guid VARCHAR(64) NULL UNIQUE,
    ad_domain VARCHAR(50) NULL CHECK (ad_domain IS NULL OR ad_domain ~ '^[A-Z0-9._-]{1,50}$'),
    last_login_at TIMESTAMPTZ NULL,
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
-- 1.1 CONFIGURAÇÕES CENTRAIS DO SISTEMA
-- ------------------------------------------------------------------------------
CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value JSONB NOT NULL,
    updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_system_settings_updated_at ON system_settings(updated_at DESC);

-- ------------------------------------------------------------------------------
-- 2. TABELA: categories (Categorias Principais)
-- ------------------------------------------------------------------------------
CREATE TABLE categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT DEFAULT '',
    image_path TEXT NULL,
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
    image_path TEXT NULL,
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
    content_type VARCHAR(20) NOT NULL CHECK (content_type IN ('file', 'text', 'link', 'code', 'video')),
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'review', 'published', 'inactive')),
    published_at TIMESTAMPTZ NULL,
    approval_expires_at TIMESTAMPTZ NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL '1 month'),
    reviewed_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    reviewed_at TIMESTAMPTZ NULL,
    approved_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    approved_at TIMESTAMPTZ NULL,
    rejected_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    rejected_at TIMESTAMPTZ NULL,
    rejection_reason TEXT NULL,

    -- Para tipo 'file'
    original_filename VARCHAR(255) NULL,
    stored_filename VARCHAR(255) NULL,
    file_path TEXT NULL,
    mime_type VARCHAR(100) NULL,
    file_extension VARCHAR(20) NULL,
    file_size BIGINT NULL,

    -- Para tipo 'text'
    text_content TEXT NULL,
    code_language VARCHAR(50) NOT NULL DEFAULT 'auto',

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

-- Tags transversais. Elas não concedem acesso: o acesso continua definido pela hierarquia.
CREATE TABLE tags (
    id SERIAL PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    normalized_name VARCHAR(100) NOT NULL UNIQUE,
    tag_type VARCHAR(20) NOT NULL DEFAULT 'topic' CHECK (tag_type IN ('topic', 'technology', 'asset', 'process')),
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TRIGGER trg_tags_updated_at BEFORE UPDATE ON tags FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TABLE tag_aliases (
    id SERIAL PRIMARY KEY,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    alias VARCHAR(80) NOT NULL,
    normalized_alias VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE document_tags (
    document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE RESTRICT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id, tag_id)
);

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
CREATE INDEX idx_documents_pending_approval_expiry ON documents(approval_expires_at) WHERE status IN ('draft', 'review') AND approval_expires_at IS NOT NULL;
CREATE INDEX idx_documents_title ON documents(title);
CREATE INDEX idx_tags_active_name ON tags(active, name);
CREATE INDEX idx_tag_aliases_tag_id ON tag_aliases(tag_id);
CREATE INDEX idx_document_tags_tag_id ON document_tags(tag_id, document_id);

-- Fluxo editorial e avisos internos
CREATE TABLE document_workflow_history (
    id BIGSERIAL PRIMARY KEY,
    document_id INT NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    actor_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(40) NOT NULL,
    previous_status VARCHAR(20) NULL,
    new_status VARCHAR(20) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_document_workflow_history_document_created ON document_workflow_history(document_id, created_at DESC);

CREATE TABLE notifications (
    id BIGSERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    document_id INT NULL REFERENCES documents(id) ON DELETE CASCADE,
    read_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_notifications_user_unread ON notifications(user_id, created_at DESC) WHERE read_at IS NULL;

-- ------------------------------------------------------------------------------
-- 7. AUDITORIA DE USO (Acessos, consultas, downloads e ações administrativas)
-- ------------------------------------------------------------------------------
CREATE TABLE usage_audit_events (
    id BIGSERIAL PRIMARY KEY,
    user_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    event_type VARCHAR(40) NOT NULL,
    resource_type VARCHAR(20) NULL,
    resource_id INT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    ip_address INET NULL,
    user_agent VARCHAR(512) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_usage_audit_event_type CHECK (event_type IN (
        'login', 'portal_view', 'search', 'category_view', 'subcategory_view', 'subject_view',
        'document_view', 'document_file_view', 'document_download', 'external_open',
        'admin_page_view', 'admin_action'
    )),
    CONSTRAINT chk_usage_audit_resource_type CHECK (resource_type IS NULL OR resource_type IN (
        'PORTAL', 'CATEGORY', 'SUBCATEGORY', 'SUBJECT', 'DOCUMENT', 'ADMIN'
    ))
);

CREATE INDEX idx_usage_audit_created_at ON usage_audit_events(created_at DESC);
CREATE INDEX idx_usage_audit_user_created ON usage_audit_events(user_id, created_at DESC) WHERE user_id IS NOT NULL;
CREATE INDEX idx_usage_audit_document_created ON usage_audit_events(resource_id, created_at DESC) WHERE resource_type = 'DOCUMENT';
CREATE INDEX idx_usage_audit_type_created ON usage_audit_events(event_type, created_at DESC);
CREATE INDEX idx_notifications_document_id ON notifications(document_id) WHERE document_id IS NOT NULL;

-- ------------------------------------------------------------------------------
-- 7. ESTRUTURA DE PERMISSÕES E GRUPOS DE ACESSO (groups, user_groups, permissions)
-- ------------------------------------------------------------------------------

CREATE TABLE groups (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE CHECK (BTRIM(name) <> ''),
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

CREATE UNIQUE INDEX uk_groups_name_ci ON groups (LOWER(BTRIM(name)));

-- Auditoria imutável de criação, alteração e remoção de permissões.
CREATE TABLE permission_audit (
    id SERIAL PRIMARY KEY,
    user_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(50) NOT NULL CHECK (action IN ('PERMISSION_CREATED', 'PERMISSION_CHANGED', 'PERMISSION_REMOVED')),
    principal_type VARCHAR(10) NOT NULL CHECK (principal_type IN ('USER', 'TEAM')),
    principal_id INT NOT NULL,
    resource_type VARCHAR(20) NOT NULL CHECK (resource_type IN ('CATEGORY', 'SUBCATEGORY', 'SUBJECT')),
    resource_id INT NOT NULL,
    old_permission VARCHAR(10) NULL,
    new_permission VARCHAR(10) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_perm_audit_user ON permission_audit(user_id);
CREATE INDEX idx_perm_audit_resource ON permission_audit(resource_type, resource_id);
CREATE INDEX idx_perm_audit_created_at ON permission_audit(created_at DESC);
