<?php

require_once __DIR__ . '/DirectoryImportGateway.php';

final class ActiveDirectoryAuthService implements DirectoryImportGateway {
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
            $bindIdentities = [];
            $netbiosDomain = !empty($domain['netbios_domain']) ? $domain['netbios_domain'] : $domainKey;
            if (!empty($netbiosDomain)) {
                $bindIdentities[] = $netbiosDomain . '\\' . $username;
            }
            $dnsDomain = !empty($domain['dns_domain']) ? $domain['dns_domain'] : '';
            if (!empty($dnsDomain)) {
                $bindIdentities[] = $username . '@' . $dnsDomain;
            }
            $typedHint = trim((string)($identity['typed_domain_hint'] ?? ''));
            if ($typedHint !== '' && str_contains($typedHint, '.')) {
                $bindIdentities[] = $username . '@' . $typedHint;
            }
            $bindIdentities[] = $username;
            $bindIdentities = array_values(array_unique($bindIdentities));

            $bound = false;
            $lastDiagnosticMessage = '';

            foreach ($bindIdentities as $bindIdentity) {
                if (@ldap_bind($ldap, $bindIdentity, $password)) {
                    $bound = true;
                    break;
                }
                $diag = '';
                if (defined('LDAP_OPT_DIAGNOSTIC_MESSAGE')) {
                    @ldap_get_option($ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag);
                }
                if ($diag !== '') {
                    $lastDiagnosticMessage = $diag;
                }
            }

            if (!$bound) {
                // Tentar Fallback de Emergência Break-Glass se a conta possuir senha local cadastrada no sistema
                $breakGlassUser = $this->tryBreakGlassEmergencyLogin($login, $password);
                if ($breakGlassUser !== null) {
                    return [
                        'success' => true,
                        'code' => 'authenticated_breakglass',
                        'message' => 'Login efetuado via Conta de Emergência Local (Break-Glass).',
                        'user' => $breakGlassUser,
                    ];
                }

                $latencyMs = (int)round((microtime(true) - $startTime) * 1000);

                // Identifica códigos de erro estendidos do Active Directory (ex.: data 775 = bloqueio, data 532/773 = senha expirada, data 533 = conta desativada)
                $adErrorCode = null;
                if (preg_match('/data\s+([0-9a-fA-F]+)/i', $lastDiagnosticMessage, $matches)) {
                    $adErrorCode = strtolower($matches[1]);
                }

                if ($adErrorCode === '775') {
                    $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'account_locked', 'Conta bloqueada por excesso de tentativas no AD.', $latencyMs, $userIp);
                    return $this->failure('account_locked', 'Sua conta do Active Directory está temporariamente bloqueada por excesso de tentativas de senha incorreta. Aguarde alguns minutos ou solicite o desbloqueio ao suporte de TI.');
                } elseif ($adErrorCode === '532' || $adErrorCode === '773') {
                    $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'password_expired', 'Senha do AD expirada ou troca obrigatória no próximo logon.', $latencyMs, $userIp);
                    return $this->failure('password_expired', 'Sua senha do Active Directory expirou ou requer alteração no próximo logon. Atualize sua senha em um computador da rede corporativa antes de acessar o DocGov.');
                } elseif ($adErrorCode === '533') {
                    $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'account_disabled', 'Conta desativada no Active Directory.', $latencyMs, $userIp);
                    return $this->failure('directory_account_disabled', 'Sua conta está desativada no Active Directory.');
                } elseif ($adErrorCode === '701') {
                    $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'account_expired', 'Conta expirada no Active Directory.', $latencyMs, $userIp);
                    return $this->failure('account_expired', 'Sua conta corporativa expirou no Active Directory. Entre em contato com a TI.');
                } elseif (in_array($adErrorCode, ['52f', '530', '531'], true)) {
                    $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'logon_restriction', 'Restrição de horário/estação no AD.', $latencyMs, $userIp);
                    return $this->failure('logon_restriction', 'Sua conta do Active Directory possui restrições de horário ou computador para logon.');
                }

                $logDetails = 'Senha do AD incorreta ou usuário/domínio não reconhecido pelo servidor.' . ($lastDiagnosticMessage !== '' ? " (Diagnóstico AD: {$lastDiagnosticMessage})" : '');
                $this->logAdAuthAttempt($domainKey, $username, $serverUri, $serverName, 'invalid_credentials', $logDetails, $latencyMs, $userIp);
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

    /** Consulta exata por nome completo ou login, reutilizando uma conexão por lote. */
    public function lookupDirectoryUsers(array $identifiers, string $domainKey): array {
        if (count($identifiers) > 20) {
            throw new InvalidArgumentException('Envie até 20 entradas por etapa.');
        }
        $domainKey = strtoupper(trim($domainKey));
        $domain = $this->config['domains'][$domainKey] ?? null;
        $failAll = fn(string $code, string $message): array => array_fill(0, count($identifiers), $this->failure($code, $message));
        if (!$domain || (isset($domain['enabled']) && !$domain['enabled'])) {
            return $failAll('unavailable', 'O domínio selecionado está indisponível.');
        }
        if (empty($this->config['enabled']) || !extension_loaded('ldap')) {
            return $failAll('unavailable', 'A consulta ao AD está indisponível. Tente novamente ou contate o suporte.');
        }
        if (trim((string)($domain['base_dn'] ?? '')) === '' || trim((string)($domain['service_bind_dn'] ?? '')) === '' || (string)($domain['service_bind_password'] ?? '') === '') {
            return $failAll('unavailable', 'Configure a conta técnica de leitura e a base de consulta deste domínio.');
        }
        $ldap = $this->connect($domain);
        if (!$ldap) {
            return $failAll('unavailable', 'Não foi possível conectar ao AD. Os nomes ainda não foram verificados.');
        }
        try {
            if (!@ldap_bind($ldap, $domain['service_bind_dn'], $domain['service_bind_password'])) {
                return $failAll('unavailable', 'Não foi possível consultar o AD com a conta técnica. Os nomes ainda não foram verificados.');
            }
            $results = [];
            $deadline = microtime(true) + 15;
            if (defined('LDAP_OPT_TIMEOUT')) {
                @ldap_set_option($ldap, LDAP_OPT_TIMEOUT, 2);
            }
            foreach ($identifiers as $identifier) {
                if (microtime(true) >= $deadline) {
                    $results[] = $this->failure('unavailable', 'O AD demorou para responder. Reprocesse esta entrada; ela ainda não foi verificada.');
                    continue;
                }
                $value = trim((string)$identifier);
                if ($value === '' || mb_strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    $results[] = $this->failure('invalid', 'Informe um nome completo ou login válido.');
                    continue;
                }
                if (str_contains($value, '\\') || str_contains($value, '@')) {
                    $identity = $this->resolveIdentity($value);
                    if (!$identity || $identity['domain']['key'] !== $domainKey) {
                        $results[] = $this->failure('invalid', 'O login deve pertencer ao domínio selecionado.');
                        continue;
                    }
                    $escaped = ldap_escape($identity['username'], '', LDAP_ESCAPE_FILTER);
                    $condition = "(sAMAccountName={$escaped})";
                } else {
                    $escaped = ldap_escape($value, '', LDAP_ESCAPE_FILTER);
                    $condition = "(|(sAMAccountName={$escaped})(displayName={$escaped})(cn={$escaped}))";
                }
                $search = @ldap_search($ldap, $domain['base_dn'], "(&(objectCategory=person)(objectClass=user){$condition})", [
                    'displayName', 'mail', 'userPrincipalName', 'sAMAccountName', 'objectGUID', 'userAccountControl', 'department', 'title', 'telephoneNumber',
                ], 0, 3, 2);
                $errorCode = 0;
                if (!$search || !ldap_parse_result($ldap, $search, $errorCode) || !in_array($errorCode, [0, 4], true)) {
                    $results[] = $this->failure('unavailable', 'A consulta ao AD falhou. Tente novamente; esta entrada ainda não foi verificada.');
                    continue;
                }
                $entries = ldap_get_entries($ldap, $search);
                if (!is_array($entries)) {
                    $results[] = $this->failure('unavailable', 'Não foi possível ler a resposta do AD. Esta entrada ainda não foi verificada.');
                    continue;
                }
                $count = (int)($entries['count'] ?? 0);
                if ($count > 1 || $errorCode === 4) {
                    $candidates = [];
                    for ($i = 0; $i < min($count, 3); $i++) {
                        $candidates[] = $domainKey . '\\' . (string)($entries[$i]['samaccountname'][0] ?? '');
                    }
                    $results[] = $this->failure('ambiguous', 'Mais de uma pessoa corresponde a este nome. Use o login corporativo.') + ['candidates' => $candidates];
                } elseif ($count === 0) {
                    $results[] = $this->failure('not_found', 'Não encontrado no AD deste domínio. Confira o nome completo ou informe o login.');
                } elseif ($this->isDirectoryAccountDisabled($entries[0])) {
                    $results[] = $this->failure('inactive', 'A conta está desativada no AD e não foi importada.');
                } else {
                    $results[] = ['success' => true, 'entry' => $entries[0]];
                }
            }
            return $results;
        } finally {
            @ldap_unbind($ldap);
        }
    }

    public function provisionDirectoryUser(array $entry, string $domainKey): array {
        $domainKey = strtoupper(trim($domainKey));
        $username = strtolower(trim((string)($entry['samaccountname'][0] ?? '')));
        $guid = isset($entry['objectguid'][0]) ? bin2hex($entry['objectguid'][0]) : '';
        if (!isset($this->config['domains'][$domainKey]) || !preg_match('/^[a-z0-9._-]{1,100}$/', $username) || strlen($guid) !== 32 || $this->isDirectoryAccountDisabled($entry)) {
            throw new InvalidArgumentException('A identidade retornada pelo AD não pode ser importada.');
        }
        if ($this->pdo->inTransaction()) {
            // Serializa importações concorrentes da mesma identidade no PostgreSQL.
            $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(81831, hashtext(?))');
            $lock->execute([$guid]);
            $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(81832, hashtext(?))');
            $lock->execute([$username]);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE ad_object_guid = ? OR LOWER(username) = LOWER(?) ORDER BY id');
        $stmt->execute([$guid, $username]);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($existing !== []) {
            if (count($existing) !== 1 || $existing[0]['auth_source'] !== 'ad' || strtoupper((string)$existing[0]['ad_domain']) !== $domainKey || (!empty($existing[0]['ad_object_guid']) && strtolower($existing[0]['ad_object_guid']) !== $guid)) {
                throw new DomainException('O login já pertence a outro cadastro. Solicite a revisão ao administrador.');
            }
            return ['user' => $existing[0], 'created' => false];
        }
        $email = strtolower(trim((string)($entry['mail'][0] ?? $entry['userprincipalname'][0] ?? ($username . '@' . $this->getDomainDnsName($domainKey)))));
        $emailStmt = $this->pdo->prepare('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $emailStmt->execute([$email]);
        if ($emailStmt->fetchColumn()) {
            throw new DomainException('O e-mail do AD já pertence a outro cadastro.');
        }
        // Importar não equivale a autenticar: não registra login nem promove papéis.
        return ['user' => $this->synchronizeUser($username, $entry, $domainKey, false, null, false, true), 'created' => true];
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
        } else {
            putenv('LDAPTLS_REQCERT=never');
            if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
                @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
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

        // Aceita somente os formatos sem domínio, NETBIOS\usuario ou usuario@dominio.
        // Identidades ambíguas/aninhadas são recusadas em vez de serem normalizadas.
        $hasNetbiosPrefix = str_contains($login, '\\');
        $hasUpnSuffix = str_contains($login, '@');
        if ($hasNetbiosPrefix && $hasUpnSuffix) {
            return null;
        }

        if ($hasNetbiosPrefix) {
            if (substr_count($login, '\\') !== 1) {
                return null;
            }
            [$domainHint, $login] = explode('\\', $login, 2);
        } elseif ($hasUpnSuffix) {
            if (substr_count($login, '@') !== 1) {
                return null;
            }
            [$login, $domainHint] = explode('@', $login, 2);
        }

        if (($hasNetbiosPrefix || $hasUpnSuffix) && ($domainHint === '' || $login === '')) {
            return null;
        }

        if (!preg_match('/^[a-z0-9._-]{1,100}$/', $login)) {
            return null;
        }

        $domains = $this->config['domains'] ?? [];
        $defaultKey = strtoupper((string)($this->config['default_domain'] ?? 'BETIM'));

        // Um domínio informado explicitamente precisa corresponder a um domínio
        // habilitado. Nunca converta uma identidade desconhecida para o padrão.
        foreach ($domains as $key => $domain) {
            $aliases = array_merge(
                [$key, $domain['key'] ?? '', $domain['netbios_domain'] ?? '', $domain['dns_domain'] ?? ''],
                $domain['aliases'] ?? []
            );
            if ($domainHint !== '' && in_array($domainHint, array_map('strtolower', array_filter($aliases)), true)) {
                if (isset($domain['enabled']) && !$domain['enabled']) {
                    return null;
                }
                $domain['key'] = strtoupper((string)($domain['key'] ?? $key));
                return ['username' => $login, 'domain' => $domain, 'typed_domain_hint' => $domainHint];
            }
        }

        if ($domainHint !== '') {
            return null;
        }

        // Login sem domínio pode usar o domínio padrão controlado pela configuração.
        // A comparação não depende de as chaves do array estarem em caixa alta.
        $fallbackDomain = null;
        $fallbackKey = '';
        foreach ($domains as $key => $domain) {
            $candidateKey = strtoupper((string)($domain['key'] ?? $key));
            if ($candidateKey === $defaultKey && (!isset($domain['enabled']) || $domain['enabled'])) {
                $fallbackDomain = $domain;
                $fallbackKey = $candidateKey;
                break;
            }
        }
        if ($fallbackDomain === null) {
            foreach ($domains as $key => $domain) {
                if (!isset($domain['enabled']) || $domain['enabled']) {
                    $fallbackDomain = $domain;
                    $fallbackKey = strtoupper((string)($domain['key'] ?? $key));
                    break;
                }
            }
        }
        if ($fallbackDomain !== null) {
            $fallbackDomain['key'] = $fallbackKey;
            return ['username' => $login, 'domain' => $fallbackDomain, 'typed_domain_hint' => ''];
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
        ?bool $activeOverride = null,
        bool $allowSuperAdmin = true,
        bool $createOnly = false
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

        if ($createOnly && $existing !== null) {
            // Um login normal pode ter criado a conta durante a consulta. Repetir é seguro.
            throw new RuntimeException('O cadastro foi criado por outro processo. Reprocesse esta entrada.');
        }
        $isSuperAdmin = $allowSuperAdmin && $this->isConfiguredSuperAdmin($domainKey, $username);
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
                    password_hash = password_hash,
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
                if ($role === 'admin' || $role === 'super_admin') {
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

    public function testServerConnection(string $uri, string $caCert = '', string $bindDn = '', #[\SensitiveParameter] string $bindPass = '', string $baseDn = ''): array {
        if (!extension_loaded('ldap')) {
            return ['success' => false, 'error' => 'A extensão PHP LDAP não está instalada ou ativada no servidor web.'];
        }

        $uri = trim($uri);
        if ($uri === '') {
            return ['success' => false, 'error' => 'URI do servidor LDAP/LDAPS não informada.'];
        }

        // 1. Extração rigorosa de Host e Porta TCP para teste direto de Socket
        $parsedUrl = parse_url($uri);
        $scheme = strtolower($parsedUrl['scheme'] ?? 'ldaps');
        $host = $parsedUrl['host'] ?? '';
        $port = (int)($parsedUrl['port'] ?? ($scheme === 'ldaps' ? 636 : 389));

        if ($parsedUrl === false || !in_array($scheme, ['ldap', 'ldaps'], true)
            || $host === '' || $port < 1 || $port > 65535
            || isset($parsedUrl['user']) || isset($parsedUrl['pass'])) {
            return ['success' => false, 'error' => 'Informe uma URI LDAP/LDAPS válida com porta entre 1 e 65535.'];
        }
        $bindDn = trim($bindDn);
        if (($bindDn === '') !== ($bindPass === '')) {
            return ['success' => false, 'error' => 'Informe o login e a senha da conta de leitura para testar a autenticação.'];
        }

        // 2. Teste físico de conectividade TCP via Socket antes do aperto de mão LDAP
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 2.5);
        if (!$socket) {
            $socketMsg = $errstr !== '' ? $errstr : "Não foi possível estabelecer conexão TCP com {$host}:{$port}";
            return [
                'success' => false,
                'error' => "Servidor ou porta inacessível [{$uri}]: {$socketMsg} (Erro {$errno}). Verifique o endereço IP/Host e a porta TCP {$port} no Firewall."
            ];
        }
        fclose($socket);

        // 3. Configurações de certificado TLS/SSL
        if (!empty($caCert) && file_exists($caCert)) {
            putenv("LDAPTLS_CACERT={$caCert}");
            if (defined('LDAP_OPT_X_TLS_CACERTFILE')) {
                @ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $caCert);
            }
        } else {
            putenv('LDAPTLS_REQCERT=never');
            if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
                @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
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

        // A senha é usada exatamente como informada, incluindo espaços e asteriscos.
        if ($bindDn !== '' && $bindPass !== '') {
            $bound = @ldap_bind($conn, $bindDn, $bindPass);
            if (!$bound) {
                $err = ldap_error($conn);
                @ldap_unbind($conn);
                return [
                    'success' => false,
                    'error' => "Conexão aberta com [{$uri}], mas a autenticação da Conta Técnica (Bind DN) falhou: {$err}"
                ];
            }
            if ($baseDn !== '') {
                $read = @ldap_read($conn, $baseDn, '(objectClass=*)', ['distinguishedName'], 0, 1, 3);
                if ($read === false || ldap_count_entries($conn, $read) < 1) {
                    @ldap_unbind($conn);
                    return ['success' => false, 'authenticated' => true, 'error' => 'A conta autenticou, mas não foi possível ler a Base DN configurada. Confira a Base DN e a permissão de leitura da conta.'];
                }
            }
            @ldap_unbind($conn);
            return [
                'success' => true,
                'authenticated' => true,
                'directory_readable' => $baseDn !== '',
                'message' => $baseDn !== '' ? 'Conta de leitura validada: autenticação e consulta ao diretório bem-sucedidas.' : "Conexão de rede e Autenticação BIND bem-sucedidas no servidor [{$uri}]!"
            ];
        } else {
            $bound = @ldap_bind($conn);
            if (!$bound) {
                $err = ldap_error($conn);
                @ldap_unbind($conn);
                return [
                    'success' => false,
                    'error' => "Falha no protocolo LDAP/TLS no servidor [{$uri}]: {$err}."
                ];
            }
            @ldap_unbind($conn);
            return [
                'success' => true,
                'authenticated' => false,
                'message' => "Conexão TCP e protocolo LDAP/TLS validados com sucesso no servidor [{$uri}]!"
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
