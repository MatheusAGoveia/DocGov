-- ==============================================================================
-- MIGRATION: 003_permissions_model.sql
-- DESCRIÇÃO: Estruturação da gestão de permissões (Tabela 'permissions')
--            e migração segura dos dados legados de 'group_access'.
-- ==============================================================================

-- 1. ESTRUTURA DA TABELA: groups
CREATE TABLE IF NOT EXISTS groups (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT DEFAULT '',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Trigger de updated_at para groups
DROP TRIGGER IF EXISTS trg_groups_updated_at ON groups;
CREATE TRIGGER trg_groups_updated_at
    BEFORE UPDATE ON groups
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- 2. ESTRUTURA DA TABELA: user_groups (Relacionamento N:N entre Users e Groups)
CREATE TABLE IF NOT EXISTS user_groups (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    group_id INT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, group_id)
);

CREATE INDEX IF NOT EXISTS idx_user_groups_user_id ON user_groups(user_id);
CREATE INDEX IF NOT EXISTS idx_user_groups_group_id ON user_groups(group_id);

-- 3. ESTRUTURA DA TABELA: permissions
CREATE TABLE IF NOT EXISTS permissions (
    id SERIAL PRIMARY KEY,
    
    -- Principal: Exatamente UM deve ser NOT NULL
    user_id INT NULL REFERENCES users(id) ON DELETE CASCADE,
    group_id INT NULL REFERENCES groups(id) ON DELETE CASCADE,
    
    -- Recurso: Exatamente UM deve ser NOT NULL
    category_id INT NULL REFERENCES categories(id) ON DELETE CASCADE,
    subcategory_id INT NULL REFERENCES subcategories(id) ON DELETE CASCADE,
    subject_id INT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    
    -- Nível de Permissão: view, edit, admin
    permission_level VARCHAR(10) NOT NULL CHECK (permission_level IN ('view', 'edit', 'admin')),
    
    -- Auditoria
    created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Constraint: Exatamente 1 Principal (User ou Group)
    CONSTRAINT chk_permissions_principal CHECK (
        num_nonnulls(user_id, group_id) = 1
    ),
    
    -- Constraint: Exatamente 1 Recurso (Category, Subcategory ou Subject)
    CONSTRAINT chk_permissions_resource CHECK (
        num_nonnulls(category_id, subcategory_id, subject_id) = 1
    )
);

-- Trigger de updated_at para permissions
DROP TRIGGER IF EXISTS trg_permissions_updated_at ON permissions;
CREATE TRIGGER trg_permissions_updated_at
    BEFORE UPDATE ON permissions
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- 4. ÍNDICES DE UNICIDADE PARCIAL (Impede registros duplicados para a mesma combinação Principal + Recurso)
CREATE UNIQUE INDEX IF NOT EXISTS uk_permissions_user_category 
    ON permissions(user_id, category_id) WHERE user_id IS NOT NULL AND category_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_permissions_user_subcategory 
    ON permissions(user_id, subcategory_id) WHERE user_id IS NOT NULL AND subcategory_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_permissions_user_subject 
    ON permissions(user_id, subject_id) WHERE user_id IS NOT NULL AND subject_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_permissions_group_category 
    ON permissions(group_id, category_id) WHERE group_id IS NOT NULL AND category_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_permissions_group_subcategory 
    ON permissions(group_id, subcategory_id) WHERE group_id IS NOT NULL AND subcategory_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_permissions_group_subject 
    ON permissions(group_id, subject_id) WHERE group_id IS NOT NULL AND subject_id IS NOT NULL;

-- 5. ÍNDICES DE ALTA PERFORMANCE PARA CONSULTAS E JOINS
CREATE INDEX IF NOT EXISTS idx_permissions_user_id ON permissions(user_id) WHERE user_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_permissions_group_id ON permissions(group_id) WHERE group_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_permissions_category_id ON permissions(category_id) WHERE category_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_permissions_subcategory_id ON permissions(subcategory_id) WHERE subcategory_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_permissions_subject_id ON permissions(subject_id) WHERE subject_id IS NOT NULL;

-- 6. MIGRAÇÃO SEGURA DE DADOS LEGADOS (group_access -> permissions)
DO $$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'group_access') THEN
        INSERT INTO permissions (group_id, category_id, subcategory_id, subject_id, permission_level, created_at)
        SELECT group_id, category_id, subcategory_id, subject_id, 'view', created_at
        FROM group_access
        ON CONFLICT DO NOTHING;
        
        -- Renomeia tabela antiga como backup de segurança em vez de apagar
        ALTER TABLE group_access RENAME TO _legacy_group_access_backup;
    END IF;
END $$;
