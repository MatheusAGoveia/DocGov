-- O mesmo login pode identificar pessoas diferentes em domínios independentes.
-- Criar os novos índices antes de remover a restrição antiga valida os dados
-- existentes sem renomear usuários, alterar IDs ou transferir permissões.
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_ad_domain_username
    ON users (UPPER(COALESCE(ad_domain, '')), LOWER(username))
    WHERE auth_source = 'ad';

CREATE UNIQUE INDEX IF NOT EXISTS uq_users_local_username
    ON users (LOWER(username))
    WHERE auth_source <> 'ad';

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_username_key;
