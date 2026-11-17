-- Chaves da API de integração (api/v1), somente leitura.
-- O token nunca é gravado: o banco guarda apenas o prefixo de identificação
-- e o hash SHA-256 do token completo. Idempotente.
CREATE TABLE IF NOT EXISTS api_clients (
    id SERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    token_prefix VARCHAR(16) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    access_scope VARCHAR(10) NOT NULL DEFAULT 'public',
    user_id INT NULL REFERENCES users(id) ON DELETE CASCADE,
    rate_limit_per_minute INT NOT NULL DEFAULT 120,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    expires_at TIMESTAMPTZ NULL,
    last_used_at TIMESTAMPTZ NULL,
    last_used_ip INET NULL,
    created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMPTZ NULL,
    revoked_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT uk_api_clients_token_prefix UNIQUE (token_prefix),
    CONSTRAINT uk_api_clients_token_hash UNIQUE (token_hash),
    CONSTRAINT chk_api_clients_scope CHECK (
        (access_scope = 'public' AND user_id IS NULL)
        OR (access_scope = 'user' AND user_id IS NOT NULL)
    ),
    CONSTRAINT chk_api_clients_rate_limit CHECK (rate_limit_per_minute BETWEEN 1 AND 10000)
);

CREATE INDEX IF NOT EXISTS idx_api_clients_active ON api_clients(active, revoked_at);

-- Janela fixa de um minuto por chave para limitar requisições.
CREATE TABLE IF NOT EXISTS api_client_rate_windows (
    client_id INT NOT NULL REFERENCES api_clients(id) ON DELETE CASCADE,
    window_start TIMESTAMPTZ NOT NULL,
    request_count INT NOT NULL DEFAULT 0,
    PRIMARY KEY (client_id, window_start)
);
