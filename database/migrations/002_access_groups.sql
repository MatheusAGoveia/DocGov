-- ==============================================================================
-- MIGRATION: 002_access_groups.sql
-- DESCRIÇÃO: Estrutura de Banco de Dados para Grupos de Acesso (DocGov)
-- ==============================================================================

-- 1. TABELA: groups (Grupos de Acesso)
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

-- 2. TABELA: user_groups (Relacionamento N:N entre Usuários e Grupos)
CREATE TABLE IF NOT EXISTS user_groups (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    group_id INT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, group_id)
);

-- ÍNDICES DE PERFORMANCE PARA user_groups
CREATE INDEX IF NOT EXISTS idx_user_groups_user_id ON user_groups(user_id);
CREATE INDEX IF NOT EXISTS idx_user_groups_group_id ON user_groups(group_id);

-- 3. TABELA: group_access (Concessão de Acessos por Nível para os Grupos)
CREATE TABLE IF NOT EXISTS group_access (
    id SERIAL PRIMARY KEY,
    group_id INT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    category_id INT NULL REFERENCES categories(id) ON DELETE CASCADE,
    subcategory_id INT NULL REFERENCES subcategories(id) ON DELETE CASCADE,
    subject_id INT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- CHECK OBRIGATÓRIO: Exatamente um campo deve ser preenchido (num_nonnulls = 1)
    CONSTRAINT chk_group_access_single_target CHECK (
        num_nonnulls(category_id, subcategory_id, subject_id) = 1
    )
);

-- ÍNDICES DE UNICIDADE PARCIAL PARA EVITAR DUPLICIDADE DE ACESSO NO MESMO GRUPO
CREATE UNIQUE INDEX IF NOT EXISTS uk_group_access_group_category 
    ON group_access(group_id, category_id) 
    WHERE category_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_group_access_group_subcategory 
    ON group_access(group_id, subcategory_id) 
    WHERE subcategory_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_group_access_group_subject 
    ON group_access(group_id, subject_id) 
    WHERE subject_id IS NOT NULL;

-- ÍNDICES DE PERFORMANCE PARA JUNÇÕES E CONSULTAS DE PERMISSÃO
CREATE INDEX IF NOT EXISTS idx_group_access_group_id ON group_access(group_id);
CREATE INDEX IF NOT EXISTS idx_group_access_category_id ON group_access(category_id) WHERE category_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_group_access_subcategory_id ON group_access(subcategory_id) WHERE subcategory_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_group_access_subject_id ON group_access(subject_id) WHERE subject_id IS NOT NULL;
