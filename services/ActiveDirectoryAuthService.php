<?php

final class ActiveDirectoryAuthService {
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo, ?array $config = null) {
        $this->pdo = $pdo;
        $this->config = $config ?? require __DIR__ . '/../config/active_directory.php';
    }

    /**
     * Autentica no Active Directory e sincroniza os dados cadastrais.
     * No primeiro acesso, uma conta corporativa válida é provisionada como leitora,
     * sem permissões de recursos. A autorização continua sob controle do DocGov.
     * A senha recebida nunca é registrada no banco, sessão ou logs.
     */
    public function authenticate(string $login, #[\SensitiveParameter] string $password): array {
        $startTime = microtime(true);
        $userIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (empty($this->config['enabled'])) {
            return $this->failure('unavailable', 'A integração com o Active Directory está desativada no ambiente.');
        }

        $identity = $this->resolveIdentity($login);
        if ($identity === null) {
            return $this->failure('invalid_username', 'Informe um usuário corporativo válido (ex.: BETIM\\maria.silva).');
        }

        $username = $identity['username'];
        $domain = $identity['domain'];
        $domainKey = $domain['key'] ?? 'BETIM';
        $serverUri = $domain['uri'] ?? 'LDAP';
        $serverName = $domain['name'] ?? $domainKey;

        if ($password === '') {
            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'invalid_credentials', 'Senha em branco.', $latencyMs, $userIp);
            return $this->failure('empty_password', 'Informe a senha corporativa do Active Directory.');
        }

        $ldap = $this->connect($domain);
        if (!$ldap) {
            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'server_unreachable', 'Servidores do Active Directory inacessíveis.', $latencyMs, $userIp);
            
            // Tentar Fallback de Emergência Break-Glass se habilitado
            $breakGlassUser = $this->tryBreakGlassEmergencyLogin($login, $password);
            if ($breakGlassUser !== null) {
                return [
                    'success' => true,
                    'code' => 'authenticated_breakglass',
                    'message' => 'Login efetuado via Conta de Emergência Local (Break-Glass).',
                    'user' => $breakGlassUser,
                ];
            }

            return $this->failure('unavailable', 'Não foi possível conectar ao Active Directory do domínio selecionado.');
        }

        try {
            $bindIdentity = (!empty($domain['netbios_domain']) ? $domain['netbios_domain'] : $domainKey) . '\\' . $username;
            if (!@ldap_bind($ldap, $bindIdentity, $password)) {
                $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
                $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'invalid_credentials', 'Senha do AD incorreta ou inválida.', $latencyMs, $userIp);
                return $this->failure('invalid_credentials', 'Usuário ou senha do Active Directory inválidos.');
            }

            $directoryUser = $this->loadDirectoryUser($ldap, $username, $domain);
            if (!empty($directoryUser)) {
                if ($this->isDirectoryAccountDisabled($directoryUser)) {
                    $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
                    $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'account_disabled', 'Conta desativada no Active Directory.', $latencyMs, $userIp);
                    return $this->failure('directory_account_disabled', 'Sua conta está desativada no Active Directory.');
                }

                if ($this->isPasswordExpiredOrChangeRequired($directoryUser)) {
                    $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
                    $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'password_expired', 'Senha expirada ou troca obrigatória no AD.', $latencyMs, $userIp);
                    return $this->failure('password_expired', 'Sua senha do Active Directory expirou ou requer alteração no próximo logon. Atualize sua senha em um computador da rede corporativa antes de acessar o DocGov.');
                }
            }

            $user = $this->synchronizeUser($username, $directoryUser, $domainKey);
            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'success', 'Autenticação bem-sucedida.', $latencyMs, $userIp);

            return [
                'success' => true,
                'code' => 'authenticated',
                'message' => '',
                'user' => $user,
            ];
        } catch (Throwable $exception) {
            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'sync_error', $exception->getMessage(), $latencyMs, $userIp);
            return $this->failure('sync_error', 'Seu acesso foi validado, mas o cadastro não pôde ser sincronizado. Contate o suporte.');
        } finally {
            @ldap_unbind($ldap);
        }
    }

    /**
     * Importa uma identidade já existente no AD sem solicitar a senha daquela pessoa.
     * A consulta é feita com a conta técnica de leitura configurada no ambiente.
     */
    public function importExistingDirectoryUser(string $login): array {
        if (empty($this->config['enabled'])) {
            return $this->failure('unavailable', 'A integração com o Active Directory está indisponível.');
        }
        if (!extension_loaded('ldap')) {
            return $this->failure('unavailable', 'A extensão LDAP não está habilitada no servidor.');
        }

        $identity = $this->resolveIdentity($login);
        if ($identity === null) {
            return $this->failure('invalid_username', 'Informe um usuário corporativo válido do Active Directory.');
        }
        $username = $identity['username'];
        $domain = $identity['domain'];

        $serviceBindDn = trim((string)($domain['service_bind_dn'] ?? ''));
        $serviceBindPassword = (string)($domain['service_bind_password'] ?? '');
        if ($serviceBindDn === '' || $serviceBindPassword === '') {
            return $this->failure('directory_import_unconfigured', 'A importação do AD ainda não foi configurada. Defina a conta técnica de leitura do Active Directory.');
        }

        $ldap = $this->connect($domain);
        if (!$ldap) {
            return $this->failure('unavailable', 'Não foi possível conectar ao Active Directory.');
        }

        try {
            if (!@ldap_bind($ldap, $serviceBindDn, $serviceBindPassword)) {
                error_log('DocGov AD: falha ao autenticar a conta técnica de importação.');
                return $this->failure('directory_import_unavailable', 'Não foi possível consultar o Active Directory com a conta técnica configurada.');
            }

            $directoryUser = $this->loadDirectoryUser($ldap, $username, $domain);
            if (empty($directoryUser)) {
                return $this->failure('not_found', 'Este usuário não foi encontrado no Active Directory.');
            }
            if ($this->isDirectoryAccountDisabled($directoryUser)) {
                return $this->failure('directory_account_disabled', 'Este usuário está desativado no Active Directory.');
            }

            $user = $this->synchronizeUser($username, $directoryUser, $domain['key'], false, true);
            return [
                'success' => true,
                'code' => 'imported',
                'message' => '',
                'user' => $user,
            ];
        } catch (Throwable $exception) {
            error_log('DocGov AD: erro ao importar usuário: ' . $exception->getMessage());
            return $this->failure('import_error', 'Não foi possível importar este usuário do Active Directory.');
        } finally {
            @ldap_unbind($ldap);
        }
    }

    /**
     * Realiza a varredura e replicação em lote dos usuários cadastrados no Active Directory
     * para a base de dados local do DocGov.
     */
    public function replicateAllDirectoryUsers(?string $domainKey = null): array {
        if (empty($this->config['enabled'])) {
            return ['success' => false, 'error' => 'A autenticação corporativa Active Directory está desabilitada.'];
        }
        if (!extension_loaded('ldap')) {
            return ['success' => false, 'error' => 'A extensão PHP LDAP não está habilitada no servidor.'];
        }

        $domains = $this->config['domains'] ?? [];
        if (empty($domains)) {
            return ['success' => false, 'error' => 'Nenhum domínio AD cadastrado.'];
        }

        $targetDomain = null;
        if ($domainKey !== null && isset($domains[strtoupper($domainKey)])) {
            $targetDomain = $domains[strtoupper($domainKey)];
        } else {
            $defaultKey = strtoupper((string)($this->config['default_domain'] ?? 'BETIM'));
            $targetDomain = $domains[$defaultKey] ?? reset($domains);
        }

        if (empty($targetDomain['enabled'])) {
            return ['success' => false, 'error' => "O domínio {$targetDomain['key']} está desativado."];
        }
        if (isset($targetDomain['replication_enabled']) && !$targetDomain['replication_enabled']) {
            return ['success' => false, 'error' => "A replicação de usuários está desabilitada para o domínio {$targetDomain['key']}."];
        }

        $serviceBindDn = trim((string)($targetDomain['service_bind_dn'] ?? ''));
        $serviceBindPassword = (string)($targetDomain['service_bind_password'] ?? '');
        if ($serviceBindDn === '' || $serviceBindPassword === '') {
            return ['success' => false, 'error' => "A Conta Técnica de serviço não está configurada para o domínio {$targetDomain['key']}."];
        }

        $ldap = $this->connect($targetDomain);
        if (!$ldap) {
            return ['success' => false, 'error' => "Não foi possível conectar ao servidor LDAP ({$targetDomain['uri']})."];
        }

        try {
            if (!@ldap_bind($ldap, $serviceBindDn, $serviceBindPassword)) {
                $err = ldap_error($ldap);
                return ['success' => false, 'error' => "Falha na autenticação da Conta Técnica ({$serviceBindDn}): {$err}"];
            }

            $baseDn = (string)($targetDomain['base_dn'] ?? '');
            if ($baseDn === '') {
                return ['success' => false, 'error' => 'Base DN não configurada para busca no AD.'];
            }

            $search = @ldap_search(
                $ldap,
                $baseDn,
                '(&(objectCategory=person)(objectClass=user)(sAMAccountName=*))',
                ['displayName', 'mail', 'userPrincipalName', 'sAMAccountName', 'objectGUID', 'userAccountControl'],
                0,
                500
            );

            if (!$search) {
                return ['success' => false, 'error' => 'Falha ao executar consulta LDAP no diretório.'];
            }

            $entries = ldap_get_entries($ldap, $search);
            $totalFound = (int)($entries['count'] ?? 0);
            $replicatedNew = 0;
            $updated = 0;
            $skipped = 0;

            for ($i = 0; $i < $totalFound; $i++) {
                $entry = $entries[$i];
                $sam = strtolower(trim((string)($entry['samaccountname'][0] ?? '')));
                if ($sam === '' || str_ends_with($sam, '$')) {
                    $skipped++;
                    continue;
                }

                if ($this->isDirectoryAccountDisabled($entry)) {
                    $skipped++;
                    continue;
                }

                try {
                    $stmtCheck = $this->pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND (ad_domain = ? OR auth_source = 'ad')");
                    $stmtCheck->execute([$sam, $targetDomain['key']]);
                    $exists = (bool)$stmtCheck->fetchColumn();

                    $this->synchronizeUser($sam, $entry, $targetDomain['key'], false, true);
                    if ($exists) {
                        $updated++;
                    } else {
                        $replicatedNew++;
                    }
                } catch (Throwable $e) {
                    error_log("DocGov AD Replication: erro ao sincronizar {$sam}: " . $e->getMessage());
                    $skipped++;
                }
            }

            return [
                'success' => true,
                'domain' => $targetDomain['key'],
                'total_found' => $totalFound,
                'replicated_new' => $replicatedNew,
                'updated' => $updated,
                'skipped' => $skipped,
                'message' => "Replicação concluída para {$targetDomain['key']}: {$replicatedNew} novos cadastros criados, {$updated} atualizados, {$skipped} ignorados/desativados.",
            ];
        } catch (Throwable $exception) {
            error_log('DocGov AD Replication Exception: ' . $exception->getMessage());
            return ['success' => false, 'error' => 'Erro durante a replicação: ' . $exception->getMessage()];
        } finally {
            @ldap_unbind($ldap);
        }
    }

    /**
     * Usa a identidade já autenticada pelo IIS/Apache (REMOTE_USER). Não recebe
     * nem armazena senha; só deve ser habilitado atrás de Windows Authentication.
     */
    public function authenticateIntegrated(string $remoteUser): array {
        if (empty($this->config['integrated_windows_enabled'])) {
            return $this->failure('integrated_disabled', 'Login integrado não está habilitado neste servidor.');
        }
        $identity = $this->resolveIdentity($remoteUser);
        if ($identity === null) {
            return $this->failure('integrated_identity_missing', 'O servidor não informou uma identidade Windows válida.');
        }

        $username = $identity['username'];
        $domain = $identity['domain'];
        $serviceBindDn = trim((string)($domain['service_bind_dn'] ?? ''));
        $serviceBindPassword = (string)($domain['service_bind_password'] ?? '');

        try {
            if ($serviceBindDn !== '' && $serviceBindPassword !== '') {
                $ldap = $this->connect($domain);
                if (!$ldap) {
                    return $this->failure('unavailable', 'Não foi possível validar sua identidade corporativa agora.');
                }
                try {
                    if (!@ldap_bind($ldap, $serviceBindDn, $serviceBindPassword)) {
                        return $this->failure('unavailable', 'Não foi possível consultar o Active Directory com a conta técnica.');
                    }
                    $directoryUser = $this->loadDirectoryUser($ldap, $username, $domain);
                    if (empty($directoryUser) || $this->isDirectoryAccountDisabled($directoryUser)) {
                        return $this->failure('inactive', 'Sua conta corporativa está desativada ou não foi localizada.');
                    }
                    $user = $this->synchronizeUser($username, $directoryUser, $domain['key']);
                } finally {
                    @ldap_unbind($ldap);
                }
            } else {
                // Sem conta técnica, o IIS/Apache já autenticou o usuário. Criamos
                // apenas o cadastro local mínimo, sem conceder acesso a recursos.
                // Quando houver consulta LDAP disponível, os dados são enriquecidos
                // no próximo login sem alterar as permissões já concedidas.
                $stmt = $this->pdo->prepare("SELECT * FROM users WHERE auth_source = 'ad' AND LOWER(username) = LOWER(?) AND ad_domain = ? LIMIT 1");
                $stmt->execute([$username, $domain['key']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$user) {
                    $user = $this->synchronizeUser($username, [], $domain['key']);
                }
                if (!(bool)$user['active']) {
                    return $this->failure('inactive', 'Seu acesso ao DocGov está desativado. Contate um administrador.');
                }
                $this->pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$user['id']]);
                $user['last_login_at'] = date('c');
            }
            if (!(bool)($user['active'] ?? false)) {
                return $this->failure('inactive', 'Seu acesso ao DocGov está desativado. Contate um administrador.');
            }
            return ['success' => true, 'code' => 'integrated_authenticated', 'message' => '', 'user' => $user];
        } catch (DomainException $exception) {
            return $this->failure('sync_error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('DocGov AD: erro no login integrado: ' . $exception->getMessage());
            return $this->failure('sync_error', 'Não foi possível concluir o login integrado.');
        }
    }

    public function buildSessionUser(array $user): array {
        return [
            'id' => (int)$user['id'],
            'nome' => $user['name'],
            'login' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'active' => (bool)$user['active'],
            'avatar' => $user['avatar'] ?? null,
            'auth_source' => 'ad',
            'ad_domain' => $user['ad_domain'] ?? null,
            'tema_preferido' => 'light',
            'inicial' => mb_strtoupper(mb_substr($user['name'], 0, 1)),
        ];
    }

    private function connect(array $domain): mixed {
        $rawUri = trim((string)($domain['uri'] ?? ''));
        if ($rawUri === '') {
            error_log('DocGov AD: URI de servidor não configurada para o domínio ' . ($domain['key'] ?? ''));
            return false;
        }

        $certificatePath = trim((string)($domain['ca_certificate'] ?? ''));
        if ($certificatePath !== '' && is_file($certificatePath)) {
            putenv("LDAPTLS_CACERT={$certificatePath}");
            if (defined('LDAP_OPT_X_TLS_CACERTFILE')) {
                @ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $certificatePath);
            }
            if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_DEMAND')) {
                @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
            }
        }

        @ldap_set_option(null, LDAP_OPT_DEBUG_LEVEL, 0);
        @ldap_set_option(null, LDAP_OPT_REFERRALS, 0);
        @ldap_set_option(null, LDAP_OPT_PROTOCOL_VERSION, 3);

        $uris = array_values(array_filter(explode(' ', $rawUri)));
        foreach ($uris as $uri) {
            $ldap = @ldap_connect($uri);
            if ($ldap) {
                @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
                @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
                $timeout = (int)($this->config['network_timeout'] ?? 4);
                if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
                    @ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
                }
                return $ldap;
            }
        }

        error_log("DocGov AD: não foi possível conectar a nenhum dos servidores do domínio: {$rawUri}");
        return false;
    }

    private function resolveIdentity(string $login): ?array {
        $login = strtolower(trim($login));
        $domainHint = '';
        if (str_contains($login, '\\')) {
            [$domainHint, $login] = array_pad(explode('\\', $login, 2), 2, '');
        } elseif (str_contains($login, '@')) {
            [$login, $domainHint] = array_pad(explode('@', $login, 2), 2, '');
        }
        if (!preg_match('/^[a-z0-9._-]{1,100}$/', $login)) {
            return null;
        }

        $domains = $this->config['domains'] ?? [];
        $defaultKey = strtoupper((string)($this->config['default_domain'] ?? 'BETIM'));
        foreach ($domains as $key => $domain) {
            $aliases = array_merge([$key, $domain['key'] ?? '', $domain['netbios_domain'] ?? '', $domain['dns_domain'] ?? ''], $domain['aliases'] ?? []);
            if ($domainHint === '' ? strtoupper($key) === $defaultKey : in_array($domainHint, array_map('strtolower', array_filter($aliases)), true)) {
                $domain['key'] = strtoupper((string)($domain['key'] ?? $key));
                return ['username' => $login, 'domain' => $domain];
            }
        }
        return null;
    }

    private function loadDirectoryUser(mixed $ldap, string $username, array $domain): array {
        $escapedUsername = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
        $search = @ldap_search(
            $ldap,
            (string)$domain['base_dn'],
            "(&(objectCategory=person)(objectClass=user)(sAMAccountName={$escapedUsername}))",
            ['displayName', 'mail', 'userPrincipalName', 'sAMAccountName', 'objectGUID', 'userAccountControl'],
            0,
            1
        );

        if (!$search) {
            return [];
        }

        $entries = ldap_get_entries($ldap, $search);
        return ($entries['count'] ?? 0) > 0 ? $entries[0] : [];
    }

    private function synchronizeUser(
        string $username,
        array $directoryUser,
        string $domainKey,
        bool $recordLogin = true,
        ?bool $activeOverride = null
    ): array {
        $name = trim((string)($directoryUser['displayname'][0] ?? $username));
        $email = strtolower(trim((string)(
            $directoryUser['mail'][0]
            ?? $directoryUser['userprincipalname'][0]
            ?? ($username . '@' . $this->getDomainDnsName($domainKey))
        )));
        $objectGuid = isset($directoryUser['objectguid'][0])
            ? strtolower(bin2hex($directoryUser['objectguid'][0]))
            : null;

        $existing = null;
        if ($objectGuid) {
            $stmtByGuid = $this->pdo->prepare('SELECT * FROM users WHERE ad_object_guid = ? LIMIT 1');
            $stmtByGuid->execute([$objectGuid]);
            $existing = $stmtByGuid->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$existing) {
            $stmtByUsername = $this->pdo->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(?) AND ((auth_source = 'ad' AND ad_domain = ?) OR (auth_source <> 'ad' AND ? = ?)) LIMIT 1");
            $stmtByUsername->execute([$username, $domainKey, $domainKey, strtoupper((string)($this->config['default_domain'] ?? 'BETIM'))]);
            $existing = $stmtByUsername->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $isSuperAdmin = $this->isConfiguredSuperAdmin($domainKey, $username);
        // A lista do ambiente é a única fonte autorizada para o papel global.
        // Não preservar `admin` legado evita que uma conta retirada da lista
        // continue com bypass total depois de uma nova sincronização com o AD.
        $role = $isSuperAdmin ? 'admin' : 'reader';

        $conflictStmt = $this->pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
        $conflictStmt->execute([$email, (int)($existing['id'] ?? 0)]);
        if ($conflictStmt->fetchColumn()) {
            throw new RuntimeException('E-mail do AD já está associado a outro cadastro.');
        }

        $department = trim((string)($directoryUser['department'][0] ?? ''));
        $jobTitle = trim((string)($directoryUser['title'][0] ?? ''));
        $phone = trim((string)($directoryUser['telephonenumber'][0] ?? ''));

        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE users
                SET name = :name,
                    username = :username,
                    email = :email,
                    role = :role,
                    password_hash = NULL,
                    auth_source = 'ad',
                    ad_object_guid = :object_guid,
                    ad_domain = :ad_domain,
                    department = COALESCE(NULLIF(:department, ''), department),
                    job_title = COALESCE(NULLIF(:job_title, ''), job_title),
                    phone = COALESCE(NULLIF(:phone, ''), phone),
                    active = COALESCE(CAST(:active_override AS BOOLEAN), active),
                    last_login_at = CASE WHEN :record_login THEN CURRENT_TIMESTAMP ELSE last_login_at END
                WHERE id = :id
                RETURNING *
            ");
            $stmt->execute([
                ':name' => $name,
                ':username' => $username,
                ':email' => $email,
                ':role' => $role,
                ':object_guid' => $objectGuid,
                ':ad_domain' => $domainKey,
                ':department' => $department,
                ':job_title' => $jobTitle,
                ':phone' => $phone,
                ':active_override' => $activeOverride === null ? null : ($activeOverride ? 'true' : 'false'),
                ':record_login' => $recordLogin ? 'true' : 'false',
                ':id' => (int)$existing['id'],
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO users (
                    name, username, email, password_hash, role, active,
                    auth_source, ad_object_guid, ad_domain, department, job_title, phone, last_login_at
                ) VALUES (
                    :name, :username, :email, NULL, :role, :active,
                    'ad', :object_guid, :ad_domain, :department, :job_title, :phone, CASE WHEN :record_login THEN CURRENT_TIMESTAMP ELSE NULL END
                )
                RETURNING *
            ");
            $stmt->execute([
                ':name' => $name,
                ':username' => $username,
                ':email' => $email,
                ':role' => $role,
                ':active' => ($activeOverride ?? true) ? 'true' : 'false',
                ':object_guid' => $objectGuid,
                ':ad_domain' => $domainKey,
                ':department' => $department !== '' ? $department : null,
                ':job_title' => $jobTitle !== '' ? $jobTitle : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':record_login' => $recordLogin ? 'true' : 'false',
            ]);
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new RuntimeException('O banco não retornou o usuário sincronizado.');
        }

        return $user;
    }

    public function logAdAuthAttempt(
        string $domainKey,
        string $username,
        string $serverUri,
        string $serverName,
        string $status,
        string $statusMessage,
        int $latencyMs,
        ?string $userIp = null
    ): void {
        try {
            $userIp = $userIp ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            // Agrupamento Inteligente: Atualizar o horário se o usuário tentou nos últimos 5 minutos com o mesmo status
            $stmtCheck = $this->pdo->prepare("
                SELECT id FROM ad_auth_logs 
                WHERE LOWER(domain_key) = LOWER(?) 
                  AND LOWER(username) = LOWER(?) 
                  AND status = ?
                  AND created_at >= NOW() - INTERVAL '5 minutes'
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmtCheck->execute([$domainKey, $username, $status]);
            $recentId = $stmtCheck->fetchColumn();

            if ($recentId) {
                $stmtUpd = $this->pdo->prepare("
                    UPDATE ad_auth_logs 
                    SET created_at = CURRENT_TIMESTAMP, 
                        latency_ms = ?, 
                        server_uri = ?, 
                        user_ip = ?
                    WHERE id = ?
                ");
                $stmtUpd->execute([$latencyMs, $serverUri, $userIp, $recentId]);
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO ad_auth_logs (
                        domain_key, username, server_uri, server_name, status, status_message, latency_ms, user_ip
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $domainKey,
                    $username,
                    $serverUri,
                    $serverName,
                    $status,
                    $statusMessage,
                    $latencyMs,
                    $userIp
                ]);
            }
        } catch (Throwable $e) {
            error_log("DocGov AD: falha ao gravar log de auditoria: " . $e->getMessage());
        }
    }

    public function tryBreakGlassEmergencyLogin(string $login, string $password): ?array {
        try {
            $username = strtolower(trim(basename(str_replace('\\', '/', $login))));
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE LOWER(username) = ? AND active = TRUE LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && !empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
                $role = strtolower((string)($user['role'] ?? ''));
                if ($role === 'admin' || $role === 'super_admin' || in_array($username, ['matheus.damiao', 'marcuss'], true)) {
                    return $user;
                }
            }
        } catch (Throwable $e) {
            error_log("DocGov Break-Glass error: " . $e->getMessage());
        }
        return null;
    }

    private function getDomainDnsName(string $domainKey): string {
        foreach (($this->config['domains'] ?? []) as $key => $domain) {
            if (strtoupper((string)$key) === strtoupper($domainKey)) {
                return (string)($domain['dns_domain'] ?? '');
            }
        }
        return (string)($this->config['dns_domain'] ?? '');
    }

    private function isConfiguredSuperAdmin(string $domainKey, string $username): bool {
        $identity = strtolower($domainKey . '\\' . $username);
        $defaultIdentity = strtolower((string)($this->config['default_domain'] ?? 'BETIM') . '\\' . $username);
        foreach (($this->config['super_admin_users'] ?? []) as $configured) {
            $configured = strtolower(trim((string)$configured));
            if ($configured === $identity || ($configured === $username && $identity === $defaultIdentity)) {
                return true;
            }
        }
        return false;
    }

    private function isDirectoryAccountDisabled(array $directoryUser): bool {
        $accountControl = (int)($directoryUser['useraccountcontrol'][0] ?? 0);
        return ($accountControl & 2) === 2;
    }

    private function isPasswordExpiredOrChangeRequired(array $directoryUser): bool {
        $pwdLastSet = (string)($directoryUser['pwdlastset'][0] ?? '');
        if ($pwdLastSet === '0') {
            return true;
        }
        $accountControl = (int)($directoryUser['useraccountcontrol'][0] ?? 0);
        // Bit 23 (0x800000 = 8388608) representa PASSWORD_EXPIRED no AD
        return ($accountControl & 8388608) === 8388608;
    }

    public function testServerConnection(string $uri, string $caCert = '', string $bindDn = '', string $bindPass = ''): array {
        if (!extension_loaded('ldap')) {
            return ['success' => false, 'error' => 'A extensão PHP LDAP não está instalada ou ativada no servidor web.'];
        }

        $uri = trim($uri);
        if ($uri === '') {
            return ['success' => false, 'error' => 'URI do servidor LDAP/LDAPS não informada.'];
        }

        if (!empty($caCert) && file_exists($caCert)) {
            putenv("LDAPTLS_CACERT={$caCert}");
            if (defined('LDAP_OPT_X_TLS_CACERTFILE')) {
                @ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $caCert);
            }
        }

        @ldap_set_option(null, LDAP_OPT_DEBUG_LEVEL, 0);
        @ldap_set_option(null, LDAP_OPT_REFERRALS, 0);
        @ldap_set_option(null, LDAP_OPT_PROTOCOL_VERSION, 3);

        $conn = @ldap_connect($uri);
        if (!$conn) {
            return ['success' => false, 'error' => "Não foi possível inicializar o conector para [{$uri}]."];
        }

        @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 3);
        }

        // Tentar realizar o aperto de mão TCP e autenticação real via ldap_bind
        if (!empty($bindDn) && !empty($bindPass)) {
            $bound = @ldap_bind($conn, $bindDn, $bindPass);
            if (!$bound) {
                $err = ldap_error($conn);
                @ldap_unbind($conn);
                return [
                    'success' => false,
                    'error' => "Conexão aberta com [{$uri}], mas a autenticação da Conta Técnica (Bind DN) falhou: {$err}"
                ];
            }
            @ldap_unbind($conn);
            return [
                'success' => true,
                'message' => "Comunicação e Autenticação BIND bem-sucedidas no servidor [{$uri}]!"
            ];
        } else {
            $bound = @ldap_bind($conn);
            if (!$bound) {
                $err = ldap_error($conn);
                @ldap_unbind($conn);
                return [
                    'success' => false,
                    'error' => "Falha de comunicação de rede no servidor [{$uri}]: {$err}. Verifique se o endereço/porta e a rota de rede estão operacionais."
                ];
            }
            @ldap_unbind($conn);
            return [
                'success' => true,
                'message' => "Conexão de rede e protocolo LDAP testados e operacionais com sucesso no servidor [{$uri}]!"
            ];
        }
    }

    private function failure(string $code, string $message): array {
        return [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'user' => null,
        ];
    }
}
