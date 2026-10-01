<?php
declare(strict_types=1);
require_once __DIR__ . '/_cli_only.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';
require_once __DIR__ . '/../services/CsrfService.php';
if ($appEnvironment !== 'development' || !in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('Somente banco local de desenvolvimento.');
function adAccountAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function adAccountToken(string $key): string {
    adAccountAssert(preg_match('/^ADTEST([A-F0-9]{10})$/', $key, $match) === 1, 'Chave de teste inválida.');
    return strtolower($match[1]);
}
function adAccountSnapshot(PDO $pdo, string $key): array {
    $settings = (new SystemSettingsService($pdo))->all(true);
    $domain = $settings['ad_domains'][$key];
    $others = $settings['ad_domains']; unset($others[$key]);
    return [
        'globals' => array_intersect_key($settings, array_flip(['ad_auth_enabled', 'ad_default_domain', 'ad_super_admin_users', 'ad_integrated_windows_enabled'])),
        'other_domains_hash' => hash('sha256', json_encode($others, JSON_THROW_ON_ERROR)),
        'account' => $domain['service_bind_dn'],
        'password_matches' => $domain['service_bind_password'] === '  Test*' . adAccountToken($key) . '  ',
    ];
}
$mode = $argv[1] ?? '--test';
if ($mode === '--inspect') { echo json_encode(adAccountSnapshot($pdo, (string)$argv[2]), JSON_THROW_ON_ERROR); exit; }
if ($mode === '--cleanup') {
    $key = (string)$argv[2]; $token = adAccountToken($key);
    $pdo->beginTransaction();
    try {
        $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ad_domains' FOR UPDATE")->fetchColumn();
        $settings = new SystemSettingsService($pdo); $domains = $settings->get('ad_domains'); unset($domains[$key]);
        $actor = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
        $settings->saveMany(['ad_domains' => $domains], $actor);
        $pdo->prepare('DELETE FROM categories WHERE slug = ?')->execute(['ad-account-test-' . $token]);
        $pdo->prepare('DELETE FROM users WHERE username = ?')->execute(['ad-account-blocked-' . $token]);
        $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
    echo "Fixture removida.\n"; exit;
}
if ($mode === '--prepare') {
    $token = bin2hex(random_bytes(5)); $key = 'ADTEST' . strtoupper($token);
    $pdo->beginTransaction();
    try {
        $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ad_domains' FOR UPDATE")->fetchColumn();
        $settings = new SystemSettingsService($pdo); $domains = $settings->get('ad_domains');
        $actor = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
        adAccountAssert($actor > 0, 'Administrador de teste ausente.');
        $domains[$key] = ['key' => $key, 'name' => 'Teste conta AD ' . $token, 'uri' => 'ldap://127.0.0.1:1', 'base_dn' => 'DC=test,DC=invalid', 'dns_domain' => 'example.invalid', 'netbios_domain' => $key, 'ca_certificate' => '', 'service_bind_dn' => '', 'service_bind_password' => '', 'enabled' => false, 'replication_enabled' => false, 'is_primary' => false];
        $settings->saveMany(['ad_domains' => $domains], $actor);
        $stmt = $pdo->prepare("INSERT INTO users (name, username, email, role, active) VALUES ('Teste conta AD', ?, ?, 'reader', TRUE) RETURNING id");
        $stmt->execute(['ad-account-blocked-' . $token, 'ad-account-blocked-' . $token . '@example.invalid']); $blocked = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?) RETURNING id');
        $stmt->execute(['Teste conta AD ' . $token, 'ad-account-test-' . $token]); $category = (int)$stmt->fetchColumn();
        $pdo->prepare("INSERT INTO permissions (user_id, category_id, permission_level, created_by) VALUES (?, ?, 'edit', ?)")->execute([$blocked, $category, $actor]);
        $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
    $sessions = [];
    foreach (['global' => $actor, 'blocked' => $blocked] as $role => $id) {
        $stmt = $pdo->prepare('SELECT id, name, username, email, role FROM users WHERE id = ?'); $stmt->execute([$id]); $user = $stmt->fetch();
        session_id(bin2hex(random_bytes(16))); session_start();
        $_SESSION = ['user' => ['id' => $id, 'nome' => $user['name'], 'login' => $user['username'], 'email' => $user['email'], 'role' => $user['role'], 'active' => true, 'inicial' => 'T'], 'admin_logged' => true];
        $sessions[$role] = ['id' => session_id(), 'csrf' => CsrfService::token()]; session_write_close();
    }
    echo json_encode(['key' => $key, 'token' => $token, 'sessions' => $sessions, 'snapshot' => adAccountSnapshot($pdo, $key)], JSON_THROW_ON_ERROR); exit;
}
adAccountAssert($mode === '--test', 'Modo de teste inválido.');
$saved = ['service_bind_dn' => 'TEST\\reader', 'service_bind_password' => '  Test*Password  '];
foreach ([[], ['service_bind_password' => ''], ['service_bind_dn' => 'TEST\\reader', 'service_bind_password' => '']] as $input) {
    adAccountAssert(SystemSettingsService::mergeAdServiceAccount($input, $saved) === $saved, 'Credencial salva não foi preservada.');
}
$changed = ['service_bind_dn' => 'TEST\\other', 'service_bind_password' => ' New*Password '];
adAccountAssert(SystemSettingsService::mergeAdServiceAccount($changed, $saved) === $changed, 'A senha foi modificada durante a troca da conta.');
foreach ([['service_bind_dn' => 'TEST\\other'], ['service_bind_dn' => '', 'service_bind_password' => 'Password'], ['service_bind_dn' => []], ['service_bind_password' => []]] as $input) {
    $rejected = false; try { SystemSettingsService::mergeAdServiceAccount($input, $saved); } catch (InvalidArgumentException) { $rejected = true; }
    adAccountAssert($rejected, 'Credencial incompleta ou malformada foi aceita.');
}
adAccountAssert(SystemSettingsService::mergeAdServiceAccount([], []) === ['service_bind_dn' => '', 'service_bind_password' => ''], 'Domínio não configurado deve continuar sem conta.');
$ad = new ActiveDirectoryAuthService($pdo, ['enabled' => true]);
foreach (['ldap://127.0.0.1:70000', 'ldap://127.0.0.1:0', 'https://127.0.0.1:443', 'ldap://usuario@127.0.0.1:389'] as $uri) {
    adAccountAssert(empty($ad->testServerConnection($uri)['success']), 'URI inválida aceita no teste.');
}
adAccountAssert(empty($ad->testServerConnection('ldap://127.0.0.1:1', '', 'TEST\\reader')['success']), 'Conta sem senha virou teste anônimo.');
echo "PASS conta AD: preservação e troca de senha, caracteres especiais, validação de credenciais e URI.\n";
