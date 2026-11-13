<?php
declare(strict_types=1);
require_once __DIR__ . '/_cli_only.php';
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';

if ($appEnvironment === 'production' || !preg_match('/^docgov_ad_identity_test_[a-f0-9]{12}$/', $dbName)
    || !in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Este adaptador exige o banco temporário de identidades AD.');
}
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    $port = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
    if (!$port) { throw new InvalidArgumentException('Porta LDAP inválida.'); }
    $domains = [];
    foreach (['BETIM', 'SAUDE'] as $domain) {
        $domains[$domain] = ['key' => $domain, 'name' => $domain, 'enabled' => true, 'is_primary' => $domain === 'BETIM',
            'uri' => 'ldap://127.0.0.1:' . $port, 'base_dn' => 'DC=' . strtolower($domain) . ',DC=test',
            'service_bind_dn' => 'TEST\\reader', 'service_bind_password' => $domain === 'BETIM' ? 'mock-only' : 'wrong',
            'netbios_domain' => $domain, 'dns_domain' => strtolower($domain) . '.example.invalid'];
    }
    $actor = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    (new SystemSettingsService($pdo))->saveMany(['ad_domains' => $domains, 'ad_default_domain' => 'BETIM', 'ad_auth_enabled' => true, 'maintenance_enabled' => false], $actor);
    echo "prepared\n";
} elseif ($mode === 'session') {
    session_start();
    $state = $argv[2] ?? '';
    $_SESSION = $state === 'stale' ? ['last_activity_at' => time() - 86400, 'session_expired' => true] : [];
    if ($state === 'admin') {
        $admin = $pdo->query("SELECT * FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetch();
        if (!$admin) { throw new RuntimeException('Administrador da base temporária ausente.'); }
        $_SESSION['user'] = ['id' => (int)$admin['id'], 'login' => $admin['username'], 'nome' => $admin['name'], 'email' => $admin['email'], 'role' => 'admin', 'active' => true, 'ad_domain' => $admin['ad_domain']];
        $_SESSION['last_activity_at'] = time();
    }
    echo session_id();
    session_write_close();
} elseif ($mode === 'inspect') {
    $id = (string)($argv[2] ?? '');
    if (!preg_match('/^[a-zA-Z0-9,-]{1,128}$/', $id)) { throw new InvalidArgumentException('Sessão inválida.'); }
    session_id($id); session_start();
    echo json_encode(['domain' => $_SESSION['user']['ad_domain'] ?? null, 'login' => $_SESSION['user']['login'] ?? null,
        'last_activity_recent' => isset($_SESSION['last_activity_at']) && abs(time() - (int)$_SESSION['last_activity_at']) < 60,
        'expired' => !empty($_SESSION['session_expired'])], JSON_THROW_ON_ERROR);
    session_write_close();
} else { throw new InvalidArgumentException('Modo inválido.'); }
