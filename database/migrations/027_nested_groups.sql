-- Expansão aditiva: não altera usuários, grupos ou permissões existentes.
CREATE TABLE IF NOT EXISTS group_memberships (
    parent_group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    child_group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (parent_group_id, child_group_id),
    CONSTRAINT chk_group_memberships_not_self CHECK (parent_group_id <> child_group_id)
);

CREATE INDEX IF NOT EXISTS idx_group_memberships_child ON group_memberships(child_group_id);

-- A mesma trava é adquirida pelo serviço antes de ler/alterar a hierarquia.
-- UNION elimina repetições e também termina em bases importadas com ciclos.
CREATE OR REPLACE FUNCTION prevent_group_membership_cycle() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF current_setting('transaction_isolation') NOT IN ('read committed', 'read uncommitted') THEN
        RAISE EXCEPTION 'Alterações de subgrupos exigem isolamento READ COMMITTED.' USING ERRCODE = '25000';
    END IF;
    PERFORM pg_advisory_xact_lock(20261001, 27);
    IF NEW.parent_group_id = NEW.child_group_id OR EXISTS (
        WITH RECURSIVE ancestors(id) AS (
            SELECT NEW.parent_group_id
            UNION
            SELECT gm.parent_group_id
            FROM group_memberships gm JOIN ancestors a ON gm.child_group_id = a.id
            WHERE TG_OP <> 'UPDATE'
               OR (gm.parent_group_id, gm.child_group_id) <> (OLD.parent_group_id, OLD.child_group_id)
        )
        SELECT 1 FROM ancestors WHERE id = NEW.child_group_id
    ) THEN
        RAISE EXCEPTION 'O vínculo entre equipes formaria um ciclo.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_group_memberships_cycle ON group_memberships;
CREATE TRIGGER trg_group_memberships_cycle
    BEFORE INSERT OR UPDATE ON group_memberships
    FOR EACH ROW EXECUTE FUNCTION prevent_group_membership_cycle();
