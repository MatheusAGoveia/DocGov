-- Depende da 029. Amplia somente os escopos aceitos; chaves existentes mantêm
-- suas permissões de leitura. Execute em transação, com lock_timeout limitado.
-- Retorno ao contrato anterior: revogue as chaves user_write e converta seu
-- escopo para user antes de restaurar a constraint da 029 em uma nova migração.
ALTER TABLE api_clients DROP CONSTRAINT IF EXISTS chk_api_clients_scope;
ALTER TABLE api_clients ADD CONSTRAINT chk_api_clients_scope CHECK (
    (access_scope = 'public' AND user_id IS NULL)
    OR (access_scope IN ('user', 'user_write') AND user_id IS NOT NULL)
);
