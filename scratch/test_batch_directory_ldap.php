<?php
declare(strict_types=1);
require_once __DIR__ . '/_cli_only.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';
$port = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
if (!$port) throw new InvalidArgumentException('Porta de teste inválida.');
$config = ['enabled' => true, 'network_timeout' => 2, 'default_domain' => 'BETIM', 'domains' => ['BETIM' => [
    'key' => 'BETIM', 'enabled' => true, 'uri' => 'ldap://127.0.0.1:' . $port,
    'base_dn' => 'DC=test,DC=invalid', 'dns_domain' => 'test.invalid', 'netbios_domain' => 'BETIM',
    'service_bind_dn' => 'TEST\\reader', 'service_bind_password' => 'mock-only',
]]];
$pdo = new PDO('sqlite::memory:');
$inputs = ['Ana Silva', 'BETIM\\ana', 'Ausente', 'Nome Igual', 'Inativo', 'Erro Servidor', 'Parcial', '*)(sAMAccountName=*)', 'João Conceição', 'OUTRO\\ana'];
$results = (new ActiveDirectoryAuthService($pdo, $config))->lookupDirectoryUsers($inputs, 'BETIM');
$statuses = array_map(static fn(array $row): string => $row['success'] ? 'found' : $row['code'], $results);
$expected = ['found', 'found', 'not_found', 'ambiguous', 'inactive', 'unavailable', 'ambiguous', 'not_found', 'found', 'invalid'];
if ($statuses !== $expected) throw new RuntimeException('Resultados LDAP inesperados: ' . json_encode($statuses));
if (count($results[3]['candidates']) !== 2 || count($results[6]['candidates']) !== 3) throw new RuntimeException('Sugestões de homônimos ausentes.');
$config['domains']['BETIM']['service_bind_password'] = 'wrong';
$failedBind = (new ActiveDirectoryAuthService($pdo, $config))->lookupDirectoryUsers(['Ana Silva'], 'BETIM');
if ($failedBind[0]['code'] !== 'unavailable') throw new RuntimeException('Falha de bind foi classificada como nome ausente.');
$config['domains']['BETIM']['enabled'] = false;
$disabled = (new ActiveDirectoryAuthService($pdo, $config))->lookupDirectoryUsers(['Ana Silva'], 'BETIM');
if ($disabled[0]['code'] !== 'unavailable') throw new RuntimeException('Domínio desativado foi consultado.');
echo "PASS LDAP real com servidor simulado: nomes, logins, Unicode, ausentes, homônimos, desativados, resultados parciais, falha de consulta e de bind.\n";
