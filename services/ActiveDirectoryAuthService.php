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
        if (empty($this->config['enabled'])) {
            return $this->failure('unavailable', 'A autenticação corporativa está temporariamente indisponível.');
        }

        if (!extension_loaded('ldap')) {
            error_log('DocGov AD: extensão LDAP não está habilitada.');
            return $this->failure('unavailable', 'A autenticação corporativa está temporariamente indisponível.');
        }

        $identity = $this->resolveIdentity($login);
        if ($identity === null || $password === '') {
            return $this->failure('invalid_credentials', 'Informe seu usuário e senha do Active Directory.');
        }
        $username = $identity['username'];
        $domain = $identity['domain'];

        $ldap = $this->connect($domain);
        if (!$ldap) {
            return $this->failure('unavailable', 'Não foi possível conectar ao Active Directory. Tente novamente.');
        }

        try {
            $bindIdentity = $domain['netbios_domain'] . '\\' . $username;
            if (!@ldap_bind($ldap, $bindIdentity, $password)) {
                $errorNumber = ldap_errno($ldap);
                if ($errorNumber !== 49) {
                    error_log("DocGov AD: falha LDAP {$errorNumber} durante o bind.");
                }
                return $this->failure('invalid_credentials', 'Usuário ou senha do Active Directory inválidos.');
            }

            $directoryUser = $this->loadDirectoryUser($ldap, $username, $domain);
            $user = $this->synchronizeUser($username, $directoryUser, $domain['key']);
            if (!(bool)($user['active'] ?? false)) {
                return $this->failure('inactive', 'Seu acesso ao DocGov está desativado. Contate um administrador.');
            }

            return [
                'success' => true,
                'code' => 'authenticated',
                'message' => '',
                'user' => $user,
            ];
        } catch (DomainException $exception) {
            return $this->failure('not_registered', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('DocGov AD: erro ao sincronizar usuário autenticado: ' . $exception->getMessage());
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
        $ldapUri = strtolower(trim((string)($domain['uri'] ?? '')));
        if (!str_starts_with($ldapUri, 'ldaps://')) {
            error_log('DocGov AD: conexão recusada porque AD_LDAP_URI não usa LDAPS.');
            return false;
        }

        $certificatePath = (string)($domain['ca_certificate'] ?? '');
        if ($certificatePath === '' || !is_file($certificatePath)) {
            error_log('DocGov AD: certificado de confiança LDAP não encontrado.');
            return false;
        }

        if (defined('LDAP_OPT_X_TLS_CACERTFILE')) {
            ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $certificatePath);
        }
        if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_DEMAND')) {
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
        }

        $ldap = @ldap_connect((string)$domain['uri']);
        if (!$ldap) {
            error_log('DocGov AD: ldap_connect não criou a conexão.');
            return false;
        }

        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, (int)$this->config['network_timeout']);
        }

        return $ldap;
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
                ':active_override' => $activeOverride === null ? null : ($activeOverride ? 'true' : 'false'),
                ':record_login' => $recordLogin ? 'true' : 'false',
                ':id' => (int)$existing['id'],
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO users (
                    name, username, email, password_hash, role, active,
                    auth_source, ad_object_guid, ad_domain, last_login_at
                ) VALUES (
                    :name, :username, :email, NULL, :role, :active,
                    'ad', :object_guid, :ad_domain, CASE WHEN :record_login THEN CURRENT_TIMESTAMP ELSE NULL END
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
                ':record_login' => $recordLogin ? 'true' : 'false',
            ]);
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new RuntimeException('O banco não retornou o usuário sincronizado.');
        }

        return $user;
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

    private function failure(string $code, string $message): array {
        return [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'user' => null,
        ];
    }
}
