-- ==============================================================================
-- MIGRATION: 003_resource_permissions.sql
-- DESCRIÇÃO: Reformulação da Gestão de Acessos (Modelo Grafana Folder Permissions)
-- ==============================================================================

-- 1. CRIAR A NOVA TABELA UNIFICADA DE PERMISSÕES POR RECURSO
CREATE TABLE IF NOT EXISTS resource_permissions (
    id SERIAL PRIMARY KEY,
    
    -- Principal: Exatamente um deve ser NOT NULL (Usuário OU Grupo)
    user_id INT NULL REFERENCES users(id) ON DELETE CASCADE,
    group_id INT NULL REFERENCES groups(id) ON DELETE CASCADE,
    
    -- Recurso: Exatamente um deve ser NOT NULL (Categoria, Subcategoria OU Assunto)
    category_id INT NULL REFERENCES categories(id) ON DELETE CASCADE,
    subcategory_id INT NULL REFERENCES subcategories(id) ON DELETE CASCADE,
    subject_id INT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    
    -- Nível de Permissão: view = 1, edit = 2, admin = 3
    permission_level VARCHAR(10) NOT NULL CHECK (permission_level IN ('view', 'edit', 'admin')),
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- CONSTRAINT: Exatamente 1 Principal (User ou Group)
    CONSTRAINT chk_resource_permissions_principal CHECK (
        num_nonnulls(user_id, group_id) = 1
    ),
    
    -- CONSTRAINT: Exatamente 1 Recurso (Cat, Subcat ou Subject)
    CONSTRAINT chk_resource_permissions_resource CHECK (
        num_nonnulls(category_id, subcategory_id, subject_id) = 1
    )
);

-- 2. TRIGGER DE UPDATED_AT
DROP TRIGGER IF EXISTS trg_resource_permissions_updated_at ON resource_permissions;
CREATE TRIGGER trg_resource_permissions_updated_at
    BEFORE UPDATE ON resource_permissions
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- 3. MIGRAÇÃO DE DADOS DA TABELA DE RASCUNHO LEGADA (group_access -> resource_permissions)
-- Mapeia os registros de group_access atribuindo o nível inicial 'view'
INSERT INTO resource_permissions (group_id, category_id, subcategory_id, subject_id, permission_level)
SELECT group_id, category_id, subcategory_id, subject_id, 'view'
FROM group_access
ON CONFLICT DO NOTHING;

-- 4. REMOVER A TABELA ANTERIOR LEGADA (group_access)
DROP TABLE IF EXISTS group_access CASCADE;

-- 5. ÍNDICES DE UNICIDADE PARCIAL (Evita duplicidade do mesmo Principal + Recurso)
CREATE UNIQUE INDEX IF NOT EXISTS uk_perm_user_category 
    ON resource_permissions(user_id, category_id) WHERE user_id IS NOT NULL AND category_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_perm_user_subcategory 
    ON resource_permissions(user_id, subcategory_id) WHERE user_id IS NOT NULL AND subcategory_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_perm_user_subject 
    ON resource_permissions(user_id, subject_id) WHERE user_id IS NOT NULL AND subject_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_perm_group_category 
    ON resource_permissions(group_id, category_id) WHERE group_id IS NOT NULL AND category_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_perm_group_subcategory 
    ON resource_permissions(group_id, subcategory_id) WHERE group_id IS NOT NULL AND subcategory_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_perm_group_subject 
    ON resource_permissions(group_id, subject_id) WHERE group_id IS NOT NULL AND subject_id IS NOT NULL;

-- 6. ÍNDICES DE DESEMPENHO PARA CONSULTAS DE HERANÇA E PERMISSÃO EFETIVA
CREATE INDEX IF NOT EXISTS idx_perm_user_id ON resource_permissions(user_id) WHERE user_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_perm_group_id ON resource_permissions(group_id) WHERE group_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_perm_category_id ON resource_permissions(category_id) WHERE category_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_perm_subcategory_id ON resource_permissions(subcategory_id) WHERE subcategory_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_perm_subject_id ON resource_permissions(subject_id) WHERE subject_id IS NOT NULL;
