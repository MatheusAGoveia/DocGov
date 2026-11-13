<?php
declare(strict_types=1);
require_once __DIR__ . '/_cli_only.php';
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';
require_once __DIR__ . '/../services/PermissionService.php';

if ($appEnvironment === 'production' || !in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Este teste exige PostgreSQL local de desenvolvimento.');
}

$serverPdo = $pdo;
$oldDatabase = getenv('DB_NAME');
$testDatabase = 'docgov_ad_identity_test_' . bin2hex(random_bytes(6));
$serverPdo->exec('CREATE DATABASE ' . $testDatabase);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
    echo '[OK] ' . $message . PHP_EOL;
};
$entry = static function (string $username, string $name, string $domain, string $guidSeed): array {
    return [
        'samaccountname' => [$username], 'displayname' => [$name],
        'mail' => [$username . '@' . strtolower($domain) . '.example.invalid'],
        'objectguid' => [substr(hash('sha256', $guidSeed, true), 0, 16)],
        'useraccountcontrol' => ['512'],
    ];
};
$config = [
    'enabled' => true, 'integrated_windows_enabled' => true,
    'default_domain' => 'BETIM', 'super_admin_users' => ['shared.admin'],
    'domains' => [
        'BETIM' => ['key' => 'BETIM', 'enabled' => true, 'netbios_domain' => 'BETIM', 'dns_domain' => 'betim.example.invalid'],
        'SAUDE' => ['key' => 'SAUDE', 'enabled' => true, 'netbios_domain' => 'SAUDE', 'dns_domain' => 'saude.example.invalid'],
    ],
];

try {
    $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $testDatabase), $dbUser, (string)$dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $pdo->exec('ALTER TABLE users ADD COLUMN department VARCHAR(150), ADD COLUMN job_title VARCHAR(150), ADD COLUMN phone VARCHAR(50)');
    $pdo->exec('CREATE TABLE ad_auth_logs (id SERIAL PRIMARY KEY, domain_key VARCHAR(50), username VARCHAR(100), server_uri VARCHAR(255), server_name VARCHAR(100), status VARCHAR(50), status_message TEXT, latency_ms INT, user_ip VARCHAR(50), created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP)');
    $seed = file_get_contents(__DIR__ . '/../database/seed.sql');
    $pdo->exec($seed);
    $seedUserCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $pdo->exec($seed);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $seedUserCount, 'Seed permanece compatível e idempotente com os novos índices');
    $service = new ActiveDirectoryAuthService($pdo, $config);
    $sync = new ReflectionMethod(ActiveDirectoryAuthService::class, 'synchronizeUser');
    $betimEntry = $entry('shared.login', 'Marcos Antônio da Silva', 'BETIM', 'betim-person');
    $saudeEntry = $entry('shared.login', 'Marcos Roberto de Souza', 'SAUDE', 'saude-person');
    $betim = $sync->invoke($service, 'shared.login', $betimEntry, 'BETIM');
    $saude = $sync->invoke($service, 'shared.login', $saudeEntry, 'SAUDE');
    $assert($betim['id'] !== $saude['id'] && $betim['name'] !== $saude['name'], 'Mesmo login e nomes completos diferentes criam identidades distintas por domínio');
    $assert($betim['ad_domain'] === 'BETIM' && $saude['ad_domain'] === 'SAUDE', 'Domínio correto é preservado em cada cadastro');

    $categoryId = (int)$pdo->query("INSERT INTO categories (name, slug) VALUES ('Privado BETIM', 'privado-betim') RETURNING id")->fetchColumn();
    $pdo->prepare("INSERT INTO permissions (user_id, category_id, permission_level) VALUES (?, ?, 'view')")->execute([$betim['id'], $categoryId]);
    $permissions = new PermissionService($pdo);
    $assert($permissions->canView((int)$betim['id'], 'category', $categoryId) && !$permissions->canView((int)$saude['id'], 'category', $categoryId), 'Novo cadastro SAUDE não herda as permissões de BETIM');

    $betimAgain = $sync->invoke($service, 'shared.login', $betimEntry, 'BETIM');
    $saudeAgain = $sync->invoke($service, 'shared.login', $saudeEntry, 'SAUDE');
    $assert($betimAgain['id'] === $betim['id'] && $saudeAgain['id'] === $saude['id'], 'Próximos acessos reutilizam o cadastro do domínio correspondente');
    $sameName = $entry('shared.login', $betim['name'], 'SAUDE', 'saude-person');
    $sameNameUser = $sync->invoke($service, 'shared.login', $sameName, 'SAUDE');
    $assert($sameNameUser['id'] === $saude['id'] && $sameNameUser['id'] !== $betim['id'], 'Nomes completos iguais também não fundem pessoas de domínios diferentes');

    $renamed = $betimEntry;
    $renamed['displayname'] = ['Marcos Antônio da Silva Santos'];
    $updated = $sync->invoke($service, 'shared.login', $renamed, 'BETIM');
    $assert($updated['id'] === $betim['id'] && $updated['name'] === $renamed['displayname'][0], 'Alteração do nome completo no mesmo GUID mantém ID e cadastro');
    $missing = $sync->invoke($service, 'shared.login', [], 'BETIM');
    $assert($missing['name'] === $updated['name'] && $missing['ad_object_guid'] === $updated['ad_object_guid'], 'Consulta sem atributos preserva nome completo e GUID existentes');

    $otherGuid = $entry('shared.login', 'Outra Pessoa', 'BETIM', 'replacement-person');
    $rejected = false;
    try { $sync->invoke($service, 'shared.login', $otherGuid, 'BETIM'); }
    catch (DomainException $error) { $rejected = true; }
    $assert($rejected, 'Mesmo login no mesmo domínio com outro GUID não assume o cadastro existente');
    $crossGuid = $entry('another.login', 'Outra Pessoa', 'SAUDE', 'betim-person');
    $rejected = false;
    try { $sync->invoke($service, 'another.login', $crossGuid, 'SAUDE'); }
    catch (DomainException $error) { $rejected = true; }
    $assert($rejected, 'GUID de outro domínio não transfere cadastro nem permissões');

    $importBetim = $service->provisionDirectoryUser($entry('import.shared', 'Ana Paula de Lima', 'BETIM', 'import-betim'), 'BETIM');
    $importSaude = $service->provisionDirectoryUser($entry('import.shared', 'Ana Cláudia de Lima', 'SAUDE', 'import-saude'), 'SAUDE');
    $assert($importBetim['created'] && $importSaude['created'] && $importBetim['user']['id'] !== $importSaude['user']['id'], 'Importação permite mesmo login e nomes completos diferentes entre domínios');
    $repeat = $service->provisionDirectoryUser($entry('import.shared', 'Ana Cláudia de Lima', 'SAUDE', 'import-saude'), 'SAUDE');
    $assert(!$repeat['created'] && $repeat['user']['id'] === $importSaude['user']['id'], 'Reimportar mantém a identidade correta sem duplicar');

    $integratedBetim = $service->authenticateIntegrated('BETIM\\shared.login');
    $integratedSaude = $service->authenticateIntegrated('SAUDE\\shared.login');
    $assert($integratedBetim['success'] && $integratedSaude['success'] && $integratedBetim['user']['id'] === $betim['id'] && $integratedSaude['user']['id'] === $saude['id'], 'Login integrado resolve contas com o mesmo login no domínio correto');

    $adminBetim = $sync->invoke($service, 'shared.admin', $entry('shared.admin', 'Administrador BETIM', 'BETIM', 'admin-betim'), 'BETIM');
    $adminSaude = $sync->invoke($service, 'shared.admin', $entry('shared.admin', 'Leitor SAUDE', 'SAUDE', 'admin-saude'), 'SAUDE');
    $assert($adminBetim['role'] === 'admin' && $adminSaude['role'] === 'reader', 'Mesmo login em SAUDE não recebe o papel de administrador de BETIM');
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash('test-only-secret', PASSWORD_DEFAULT), $adminBetim['id']]);
    $assert($service->tryBreakGlassEmergencyLogin('BETIM\\shared.admin', 'test-only-secret')['id'] === $adminBetim['id'], 'Login de emergência permanece disponível no domínio correto');
    $assert($service->tryBreakGlassEmergencyLogin('SAUDE\\shared.admin', 'test-only-secret') === null, 'Login de emergência de SAUDE não reutiliza a conta administrativa de BETIM');
    $assert($service->tryBreakGlassEmergencyLogin('OUTRO\\shared.admin', 'test-only-secret') === null, 'Domínio desconhecido não seleciona conta de emergência por login');

    $cnEntry = $entry('cn.fallback', '', 'SAUDE', 'cn-person');
    $cnEntry['cn'] = ['Ana Cláudia de Oliveira'];
    $cnUser = $sync->invoke($service, 'cn.fallback', $cnEntry, 'SAUDE');
    $assert($cnUser['name'] === 'Ana Cláudia de Oliveira', 'Nome completo usa cn quando displayName está vazio');
    $conflictingEmail = $entry('email.conflict', 'Pessoa Diferente', 'SAUDE', 'email-person');
    $conflictingEmail['mail'] = [$betim['email']];
    $rejected = false;
    try { $sync->invoke($service, 'email.conflict', $conflictingEmail, 'SAUDE'); }
    catch (RuntimeException $error) { $rejected = true; }
    $assert($rejected, 'Conflito de e-mail não associa silenciosamente pessoas diferentes');

    $migration = file_get_contents(__DIR__ . '/../database/migrations/028_ad_domain_identity.sql');
    $pdo->exec('CREATE SCHEMA legacy_identity');
    $pdo->exec('SET search_path TO legacy_identity');
    $pdo->exec("CREATE TABLE users (id SERIAL PRIMARY KEY, name TEXT NOT NULL, username VARCHAR(100) NOT NULL UNIQUE, email TEXT NOT NULL UNIQUE, auth_source TEXT NOT NULL DEFAULT 'local', ad_domain TEXT)");
    $pdo->exec("INSERT INTO users (name, username, email, auth_source, ad_domain) VALUES ('Pessoa BETIM', 'legacy.person', 'legacy@betim.invalid', 'ad', 'BETIM')");
    $legacyBefore = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
    $pdo->beginTransaction();
    $pdo->exec($migration);
    $pdo->commit();
    $assert($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll() === $legacyBefore, 'Migração preserva todos os valores e IDs do cadastro legado');
    $pdo->exec("INSERT INTO users (name, username, email, auth_source, ad_domain) VALUES ('Pessoa SAUDE', 'legacy.person', 'legacy@saude.invalid', 'ad', 'SAUDE')");
    $assert((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username = 'legacy.person'")->fetchColumn() === 2, 'Migração remove o bloqueio global e permite o mesmo login em outro domínio');
    $pdo->exec($migration);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 2, 'Reaplicar migração é idempotente e preserva contas de ambos os domínios');
    $rejected = false;
    try { $pdo->exec("INSERT INTO users (name, username, email, auth_source, ad_domain) VALUES ('Outra Pessoa', 'LEGACY.PERSON', 'duplicate@invalid', 'ad', 'saude')"); }
    catch (PDOException $error) { $rejected = $error->getCode() === '23505'; }
    $assert($rejected, 'Banco rejeita duplicata no mesmo domínio independentemente de maiúsculas');
    $pdo->exec("INSERT INTO users (name, username, email) VALUES ('Pessoa Local', 'local.person', 'local@invalid')");
    $rejected = false;
    try { $pdo->exec("INSERT INTO users (name, username, email) VALUES ('Outra Local', 'LOCAL.PERSON', 'local2@invalid')"); }
    catch (PDOException $error) { $rejected = $error->getCode() === '23505'; }
    $assert($rejected, 'Banco mantém login único entre contas locais');
    $pdo->exec('SET search_path TO public');

    $ldapPort = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
    if ($ldapPort !== false) {
        foreach (['BETIM', 'SAUDE'] as $domain) {
            $config['domains'][$domain]['uri'] = 'ldap://127.0.0.1:' . $ldapPort;
            $config['domains'][$domain]['base_dn'] = 'DC=' . strtolower($domain) . ',DC=test';
        }
        $formService = new ActiveDirectoryAuthService($pdo, $config);
        $formBetim = $formService->authenticate('BETIM\\form.same', 'mock-only');
        $formSaude = $formService->authenticate('SAUDE\\form.same', 'mock-only');
        $assert($formBetim['success'] && $formSaude['success'] && $formBetim['user']['id'] !== $formSaude['user']['id'] && $formBetim['user']['name'] !== $formSaude['user']['name'], 'Login com senha valida LDAP, nomes diferentes e cadastros separados entre domínios');
        $repeatBetim = $formService->authenticate('BETIM\\form.same', 'mock-only');
        $repeatSaude = $formService->authenticate('SAUDE\\form.same', 'mock-only');
        $assert($repeatBetim['success'] && $repeatSaude['success'] && $repeatBetim['user']['id'] === $formBetim['user']['id'] && $repeatSaude['user']['id'] === $formSaude['user']['id'], 'Repetir o login com senha seleciona a pessoa correta em cada domínio');
        $homonymBetim = $formService->authenticate('BETIM\\form.homonym', 'mock-only');
        $homonymSaude = $formService->authenticate('SAUDE\\form.homonym', 'mock-only');
        $assert($homonymBetim['success'] && $homonymSaude['success'] && $homonymBetim['user']['name'] === $homonymSaude['user']['name'] && $homonymBetim['user']['id'] !== $homonymSaude['user']['id'], 'Login com senha permite homônimos em domínios diferentes sem unir cadastros');
        $logs = $pdo->query("SELECT domain_key, COUNT(*) AS attempts FROM ad_auth_logs WHERE username = 'form.same' AND status = 'success' GROUP BY domain_key ORDER BY domain_key")->fetchAll();
        $assert(count($logs) === 2 && array_column($logs, 'domain_key') === ['BETIM', 'SAUDE'], 'Auditoria mantém os acessos separados pelo domínio');
    }

    putenv('DB_NAME=' . $testDatabase);
    if ($ldapPort !== false) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/test_ad_server_health.php', (string)$ldapPort], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Não foi possível iniciar o teste de saúde LDAP.'); }
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process); echo $stdout;
        $assert($exit === 0, 'Estados de conexão, autenticação e leitura LDAP' . ($exit === 0 ? '' : ': ' . $stderr));
        $process = proc_open(['node', __DIR__ . '/test_login_domain_flow.js', (string)$ldapPort], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Não foi possível iniciar o teste HTTP de login.'); }
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        echo $stdout;
        $assert($exit === 0, 'Login HTTP por domínio e renovação de sessão' . ($exit === 0 ? '' : ': ' . $stderr));
    }
    foreach (['test_integrated_windows_auth.php', 'test_batch_user_import.php', 'test_ad_service_account.php'] as $regression) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/' . $regression], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Não foi possível iniciar ' . $regression); }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $assert($exit === 0, 'Regressão ' . $regression . ($exit === 0 ? '' : ': ' . $stdout . $stderr));
    }
    echo "PASS: {$checks} verificações de identidades AD em banco temporário.\n";
} finally {
    putenv($oldDatabase === false ? 'DB_NAME' : 'DB_NAME=' . $oldDatabase);
    $service = null;
    $formService = null;
    $permissions = null;
    $sync = null;
    $pdo = null;
    $serverPdo->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()')->execute([$testDatabase]);
    $serverPdo->exec('DROP DATABASE ' . $testDatabase);
}
