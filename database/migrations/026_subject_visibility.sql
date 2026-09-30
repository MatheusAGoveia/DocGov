-- Conteúdos públicos são de leitura para usuários autenticados ativos.
-- Os assuntos existentes continuam privados e mantêm suas permissões.
ALTER TABLE subjects
    ADD COLUMN IF NOT EXISTS visibility VARCHAR(10) NOT NULL DEFAULT 'private';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'subjects'::regclass
          AND conname = 'subjects_visibility_check'
    ) THEN
        ALTER TABLE subjects ADD CONSTRAINT subjects_visibility_check
            CHECK (visibility IN ('private', 'public'));
    END IF;
END $$;
