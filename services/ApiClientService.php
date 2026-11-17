<?php

/**
 * Chaves da API de integração (api/v1).
 *
 * Formato do token: dgv_<prefixo 8 hex>_<segredo 48 hex> (192 bits de segredo).
 * O banco guarda somente o prefixo e o SHA-256 do token completo; o valor em
 * claro é devolvido uma única vez, na criação.
 */
final class ApiClientService
{
    public const SCOPE_PUBLIC = 'public';
    public const SCOPE_USER = 'user';
    public const SCOPE_USER_WRITE = 'user_write';

    public static function isUserScope(string $scope): bool
    {
        return in_array($scope, [self::SCOPE_USER, self::SCOPE_USER_WRITE], true);
    }

    public const DEFAULT_RATE_LIMIT = 120;
    public const MIN_RATE_LIMIT = 10;
    public const MAX_RATE_LIMIT = 1000;

    private const TOKEN_PATTERN = '/^dgv_([a-f0-9]{8})_[a-f0-9]{48}$/';
    private const PREFIX_BYTES = 4;
    private const SECRET_BYTES = 24;
    private const MAX_CREATE_ATTEMPTS = 3;
    private const TOUCH_INTERVAL_SECONDS = 60;
    private const RATE_WINDOW_RETENTION = '1 hour';
    private const NAME_MIN = 3;
    private const NAME_MAX = 120;
    private const DESCRIPTION_MAX = 500;
    /** Papéis de administrador global (ver PermissionService::isGlobalAdmin). */
    private const GLOBAL_ADMIN_ROLES = ['admin', 'super_admin'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Cria uma chave e devolve ['client' => array, 'token' => string].
     * @param array{name?:mixed, description?:mixed, access_scope?:mixed, username?:mixed, expires_on?:mixed, rate_limit_per_minute?:mixed} $input
     */
    public function create(array $input, int $actorId): array
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Executor inválido para criar a chave.');
        }
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $scope = (string)($input['access_scope'] ?? self::SCOPE_PUBLIC);
        $rateLimit = (int)($input['rate_limit_per_minute'] ?? self::DEFAULT_RATE_LIMIT);

        if (mb_strlen($name) < self::NAME_MIN || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidArgumentException('O nome da integração deve ter entre 3 e 120 caracteres.');
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new InvalidArgumentException('A descrição deve ter até 500 caracteres.');
        }
        if ($scope !== self::SCOPE_PUBLIC && !self::isUserScope($scope)) {
            throw new InvalidArgumentException('Escopo de acesso inválido.');
        }
        if ($rateLimit < self::MIN_RATE_LIMIT || $rateLimit > self::MAX_RATE_LIMIT) {
            throw new InvalidArgumentException('O limite deve ficar entre 10 e 1000 requisições por minuto.');
        }
        $userId = self::isUserScope($scope) ? $this->resolveActiveUserId((string)($input['username'] ?? '')) : null;
        $expiresAt = $this->parseExpiry($input['expires_on'] ?? null);

        $insert = $this->pdo->prepare('
            INSERT INTO api_clients (name, description, token_prefix, token_hash, access_scope, user_id, rate_limit_per_minute, expires_at, created_by)
            VALUES (:name, :description, :prefix, :hash, :scope, :user_id, :rate_limit, :expires_at, :actor)
            RETURNING id
        ');
        for ($attempt = 1; $attempt <= self::MAX_CREATE_ATTEMPTS; $attempt++) {
            $prefix = bin2hex(random_bytes(self::PREFIX_BYTES));
            $token = 'dgv_' . $prefix . '_' . bin2hex(random_bytes(self::SECRET_BYTES));
            try {
                $insert->execute([
                    ':name' => $name,
                    ':description' => $description,
                    ':prefix' => $prefix,
                    ':hash' => self::hashToken($token),
                    ':scope' => $scope,
                    ':user_id' => $userId,
                    ':rate_limit' => $rateLimit,
                    ':expires_at' => $expiresAt,
                    ':actor' => $actorId,
                ]);
                return ['client' => $this->find((int)$insert->fetchColumn()), 'token' => $token];
            } catch (PDOException $exception) {
                // Colisão de prefixo é improvável; outra falha é propagada.
                if ($exception->getCode() !== '23505' || $attempt === self::MAX_CREATE_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
        throw new RuntimeException('Não foi possível gerar uma chave única.');
    }

    public function find(int $clientId): ?array
    {
        $stmt = $this->pdo->prepare(self::selectSql() . ' WHERE ac.id = ?');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalize($row) : null;
    }

    public function listAll(): array
    {
        $rows = $this->pdo->query(self::selectSql() . ' ORDER BY ac.revoked_at IS NOT NULL, ac.created_at DESC, ac.id DESC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'normalize'], $rows ?: []);
    }

    public function revoke(int $clientId, int $actorId): bool
    {
        if ($clientId <= 0 || $actorId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('UPDATE api_clients SET active = FALSE, revoked_at = CURRENT_TIMESTAMP, revoked_by = :actor WHERE id = :id AND revoked_at IS NULL RETURNING id');
        $stmt->execute([':actor' => $actorId, ':id' => $clientId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Valida o token e devolve a chave ativa. Qualquer falha (formato, chave
     * revogada, expirada ou conta vinculada inativa) resulta em null, para não
     * revelar o estado de uma chave a quem não a possui.
     */
    public function authenticate(?string $token): ?array
    {
        if ($token === null || !preg_match(self::TOKEN_PATTERN, $token, $match)) {
            return null;
        }
        $stmt = $this->pdo->prepare('
            SELECT ac.id, ac.name, ac.token_hash, ac.access_scope, ac.user_id, ac.rate_limit_per_minute, ac.last_used_at
            FROM api_clients ac
            LEFT JOIN users u ON u.id = ac.user_id
            WHERE ac.token_prefix = :prefix
              AND ac.active = TRUE
              AND ac.revoked_at IS NULL
              AND (ac.expires_at IS NULL OR ac.expires_at > CURRENT_TIMESTAMP)
              AND (
                  ac.access_scope = :public_scope
                  OR (u.active = TRUE AND LOWER(TRIM(COALESCE(u.role, :empty_role))) NOT IN (:admin_role, :super_admin_role))
              )
        ');
        // Conta promovida a administrador global depois da criação também perde a chave.
        $stmt->execute([
            ':prefix' => $match[1],
            ':public_scope' => self::SCOPE_PUBLIC,
            ':empty_role' => '',
            ':admin_role' => self::GLOBAL_ADMIN_ROLES[0],
            ':super_admin_role' => self::GLOBAL_ADMIN_ROLES[1],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals((string)$row['token_hash'], self::hashToken($token))) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'access_scope' => (string)$row['access_scope'],
            'user_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'rate_limit_per_minute' => (int)$row['rate_limit_per_minute'],
            'last_used_at' => $row['last_used_at'],
        ];
    }

    /**
     * Conta a requisição na janela do minuto atual.
     * @return array{allowed: bool, limit: int, remaining: int, reset_seconds: int}
     */
    public function consumeRateLimit(int $clientId, int $limit): array
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO api_client_rate_windows (client_id, window_start, request_count)
            VALUES (:client_id, date_trunc('minute', CURRENT_TIMESTAMP), 1)
            ON CONFLICT (client_id, window_start)
            DO UPDATE SET request_count = api_client_rate_windows.request_count + 1
            RETURNING request_count, CAST(CEIL(EXTRACT(EPOCH FROM (window_start + INTERVAL '1 minute' - CURRENT_TIMESTAMP))) AS INT) AS reset_seconds
        ");
        $stmt->execute([':client_id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['request_count' => 1, 'reset_seconds' => 60];
        $count = (int)$row['request_count'];
        if ($count === 1) {
            // Primeira requisição do minuto: descarta janelas antigas desta chave.
            $this->pdo->prepare("DELETE FROM api_client_rate_windows WHERE client_id = ? AND window_start < CURRENT_TIMESTAMP - INTERVAL '" . self::RATE_WINDOW_RETENTION . "'")->execute([$clientId]);
        }
        return [
            'allowed' => $count <= $limit,
            'limit' => $limit,
            'remaining' => max(0, $limit - $count),
            'reset_seconds' => max(1, (int)$row['reset_seconds']),
        ];
    }

    /** Atualiza o último uso no máximo uma vez por minuto, evitando escrita a cada chamada. */
    public function touch(array $client, ?string $ipAddress): void
    {
        $lastUsed = $client['last_used_at'] ?? null;
        if (is_string($lastUsed) && strtotime($lastUsed) > time() - self::TOUCH_INTERVAL_SECONDS) {
            return;
        }
        $ip = $ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null;
        $this->pdo->prepare('UPDATE api_clients SET last_used_at = CURRENT_TIMESTAMP, last_used_ip = :ip WHERE id = :id')
            ->execute([':ip' => $ip, ':id' => (int)$client['id']]);
    }

    /** Lê o token de "Authorization: Bearer" ou "X-API-Key". Nunca da URL. */
    public static function extractToken(array $server): ?string
    {
        $authorization = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($authorization === '' && function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $header => $value) {
                if (strcasecmp((string)$header, 'Authorization') === 0) {
                    $authorization = (string)$value;
                    break;
                }
            }
        }
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $match)) {
            return $match[1];
        }
        $apiKey = trim((string)($server['HTTP_X_API_KEY'] ?? ''));
        return $apiKey !== '' ? $apiKey : null;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function resolveActiveUserId(string $username): int
    {
        $username = trim($username);
        if ($username === '') {
            throw new InvalidArgumentException('Informe o login do usuário cujo acesso de leitura a chave usará.');
        }
        $stmt = $this->pdo->prepare('SELECT id, role FROM users WHERE LOWER(username) = LOWER(?) AND active = TRUE ORDER BY id');
        $stmt->execute([$username]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new InvalidArgumentException(count($rows) === 0
                ? 'Usuário ativo não encontrado para o login informado.'
                : 'O login corresponde a mais de uma conta ativa; vincule a chave a uma conta única.');
        }
        // Administrador global lê todo o acervo: uma chave vazada teria esse alcance.
        if (in_array(strtolower(trim((string)$rows[0]['role'])), self::GLOBAL_ADMIN_ROLES, true)) {
            throw new InvalidArgumentException('Não vincule chaves a administradores globais. Use uma conta de serviço com permissões de leitura mínimas.');
        }
        return (int)$rows[0]['id'];
    }

    private function parseExpiry(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Data de expiração inválida.');
        }
        $endOfDay = $date->setTime(23, 59, 59);
        if ($endOfDay <= new DateTimeImmutable('now')) {
            throw new InvalidArgumentException('A expiração precisa estar no futuro.');
        }
        return $endOfDay->format(DateTimeInterface::ATOM);
    }

    private static function selectSql(): string
    {
        return '
            SELECT ac.id, ac.name, ac.description, ac.token_prefix, ac.access_scope, ac.user_id,
                   ac.rate_limit_per_minute, ac.active, ac.expires_at, ac.last_used_at, ac.last_used_ip,
                   ac.created_at, ac.revoked_at,
                   u.name AS user_name, u.username AS user_username, u.active AS user_active, u.role AS user_role,
                   creator.name AS created_by_name
            FROM api_clients ac
            LEFT JOIN users u ON u.id = ac.user_id
            LEFT JOIN users creator ON creator.id = ac.created_by
        ';
    }

    private static function normalize(array $row): array
    {
        $expired = !empty($row['expires_at']) && strtotime((string)$row['expires_at']) <= time();
        $revoked = !empty($row['revoked_at']);
        $userScoped = self::isUserScope((string)$row['access_scope']);
        $userInactive = $userScoped && !filter_var($row['user_active'], FILTER_VALIDATE_BOOLEAN);
        $userIsGlobalAdmin = $userScoped && in_array(strtolower(trim((string)($row['user_role'] ?? ''))), self::GLOBAL_ADMIN_ROLES, true);
        $status = match (true) {
            $revoked => 'revoked',
            $expired => 'expired',
            $userInactive => 'user_inactive',
            $userIsGlobalAdmin => 'user_admin',
            !filter_var($row['active'], FILTER_VALIDATE_BOOLEAN) => 'inactive',
            default => 'active',
        };
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'description' => (string)$row['description'],
            'token_hint' => 'dgv_' . $row['token_prefix'] . '_…',
            'access_scope' => (string)$row['access_scope'],
            'user_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'user_name' => $row['user_name'],
            'user_username' => $row['user_username'],
            'rate_limit_per_minute' => (int)$row['rate_limit_per_minute'],
            'status' => $status,
            'expires_at' => $row['expires_at'],
            'last_used_at' => $row['last_used_at'],
            'last_used_ip' => $row['last_used_ip'],
            'created_at' => $row['created_at'],
            'created_by_name' => $row['created_by_name'],
            'revoked_at' => $row['revoked_at'],
        ];
    }
}
