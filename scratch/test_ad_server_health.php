<?php
declare(strict_types=1);
require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';
$port = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
if (!$port) { throw new InvalidArgumentException('Porta local de teste inválida.'); }
$service = new ActiveDirectoryAuthService(new PDO('sqlite::memory:'), ['network_timeout' => 2]);
$uri = 'ldap://127.0.0.1:' . $port;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo '[OK] ' . $message . PHP_EOL;
};
$network = $service->testServerConnection($uri);
$assert($network['success'] && $network['tcp_connected'] && $network['ldap_available'] && $network['authenticated'] === null && $network['status'] === 'available', 'Conexão disponível sem afirmar autenticação da conta técnica');
$healthy = $service->testServerConnection($uri, '', 'TEST\\reader', 'mock-only', 'DC=test,DC=invalid');
$assert($healthy['success'] && $healthy['authenticated'] === true && $healthy['directory_readable'] === true && $healthy['status'] === 'healthy', 'Conta técnica e Base DN válidas indicam estado operacional');
$wrong = $service->testServerConnection($uri, '', 'TEST\\reader', 'wrong', 'DC=test,DC=invalid');
$assert(!$wrong['success'] && $wrong['tcp_connected'] && $wrong['ldap_available'] && $wrong['authenticated'] === false && $wrong['status'] === 'authentication_error', 'Credencial rejeitada conserva o servidor como acessível');
$base = $service->testServerConnection($uri, '', 'TEST\\reader', 'mock-only', 'DC=missing,DC=invalid');
$assert(!$base['success'] && $base['ldap_available'] && $base['authenticated'] === true && $base['directory_readable'] === false && $base['status'] === 'directory_error', 'Base DN incorreta é distinguida de servidor indisponível');
$closed = $service->testServerConnection('ldap://127.0.0.1:1');
$assert(!$closed['success'] && !$closed['tcp_connected'] && !$closed['ldap_available'] && $closed['status'] === 'connection_error', 'Porta fechada indica falha de conexão');
$partial = $service->testServerConnection($uri, '', 'TEST\\reader');
$assert(!$partial['success'] && $partial['status'] === 'configuration_error', 'Conta incompleta não é convertida em autenticação anônima');
echo "PASS estados de saúde LDAP.\n";
