-- Consolida o modelo de acesso e reforça integridade antes do ambiente real.

DO $$
BEGIN
    IF to_regclass('public.resource_permissions') IS NOT NULL THEN
        -- Em conflitos, o maior nível prevalece também durante a migração.
        UPDATE permissions p
        SET permission_level = CASE
                WHEN rp.permission_level = 'admin' OR p.permission_level = 'admin' THEN 'admin'
                WHEN rp.permission_level = 'edit' OR p.permission_level = 'edit' THEN 'edit'
                ELSE 'view'
            END,
            updated_at = CURRENT_TIMESTAMP
        FROM resource_permissions rp
        WHERE p.user_id IS NOT DISTINCT FROM rp.user_id
          AND p.group_id IS NOT DISTINCT FROM rp.group_id
          AND p.category_id IS NOT DISTINCT FROM rp.category_id
          AND p.subcategory_id IS NOT DISTINCT FROM rp.subcategory_id
          AND p.subject_id IS NOT DISTINCT FROM rp.subject_id;

        INSERT INTO permissions (
            user_id, group_id, category_id, subcategory_id, subject_id,
            permission_level, created_at, updated_at
        )
        SELECT
            rp.user_id, rp.group_id, rp.category_id, rp.subcategory_id, rp.subject_id,
            rp.permission_level, rp.created_at, rp.updated_at
        FROM resource_permissions rp
        WHERE num_nonnulls(rp.user_id, rp.group_id) = 1
          AND num_nonnulls(rp.category_id, rp.subcategory_id, rp.subject_id) = 1
          AND rp.permission_level IN ('view', 'edit', 'admin')
          AND NOT EXISTS (
              SELECT 1
              FROM permissions p
              WHERE p.user_id IS NOT DISTINCT FROM rp.user_id
                AND p.group_id IS NOT DISTINCT FROM rp.group_id
                AND p.category_id IS NOT DISTINCT FROM rp.category_id
                AND p.subcategory_id IS NOT DISTINCT FROM rp.subcategory_id
                AND p.subject_id IS NOT DISTINCT FROM rp.subject_id
          );

        IF to_regclass('public._legacy_resource_permissions_backup') IS NULL THEN
            ALTER TABLE resource_permissions RENAME TO _legacy_resource_permissions_backup;
        END IF;
    END IF;

    IF to_regclass('public.group_access') IS NOT NULL THEN
        INSERT INTO permissions (group_id, category_id, subcategory_id, subject_id, permission_level, created_at)
        SELECT ga.group_id, ga.category_id, ga.subcategory_id, ga.subject_id, 'view', ga.created_at
        FROM group_access ga
        WHERE num_nonnulls(ga.category_id, ga.subcategory_id, ga.subject_id) = 1
          AND NOT EXISTS (
              SELECT 1
              FROM permissions p
              WHERE p.group_id = ga.group_id
                AND p.user_id IS NULL
                AND p.category_id IS NOT DISTINCT FROM ga.category_id
                AND p.subcategory_id IS NOT DISTINCT FROM ga.subcategory_id
                AND p.subject_id IS NOT DISTINCT FROM ga.subject_id
          );

        IF to_regclass('public._legacy_group_access_backup') IS NULL THEN
            ALTER TABLE group_access RENAME TO _legacy_group_access_backup;
        END IF;
    END IF;
END $$;

-- Nomes visualmente iguais não podem representar equipes diferentes.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM groups GROUP BY LOWER(BTRIM(name)) HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Existem equipes com nomes duplicados ignorando maiúsculas/minúsculas.';
    END IF;

    IF EXISTS (SELECT 1 FROM groups WHERE BTRIM(name) = '') THEN
        RAISE EXCEPTION 'Existe equipe com nome vazio.';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_groups_name_not_blank') THEN
        ALTER TABLE groups ADD CONSTRAINT chk_groups_name_not_blank CHECK (BTRIM(name) <> '');
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS uk_groups_name_ci ON groups (LOWER(BTRIM(name)));

-- A trilha de auditoria deve sobreviver à exclusão futura do executor.
ALTER TABLE permission_audit ALTER COLUMN user_id DROP NOT NULL;
ALTER TABLE permission_audit DROP CONSTRAINT IF EXISTS permission_audit_user_id_fkey;
ALTER TABLE permission_audit
    ADD CONSTRAINT permission_audit_user_id_fkey
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Contas locais de desenvolvimento não podem manter bypass global no modelo AD.
UPDATE users
SET role = 'reader', updated_at = CURRENT_TIMESTAMP
WHERE role = 'admin' AND auth_source IS DISTINCT FROM 'ad';
