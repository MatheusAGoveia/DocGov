<?php
require_once __DIR__ . '/_cli_only.php';
// scratch/test_get.php
// Simula a requisição via URL GET feita pelo usuário
$_GET['tab'] = 'servidores_ad';
$_GET['domain'] = 'SAUDE';
$_GET['new'] = '1';
$_GET['name'] = 'Secretaria de Saude';
$_GET['uri'] = 'ldasps://diana.betim.pmb:636';

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';

$systemSettingsService = new SystemSettingsService($pdo);

$rawDomainKey = strtoupper(trim((string)$_GET['domain']));
$rawUri = trim((string)($_GET['uri'] ?? ''));
$rawUri = preg_replace('/^ldasps:\/\//i', 'ldaps://', $rawUri);
$rawUri = preg_replace('/^ldaps\/\//i', 'ldaps://', $rawUri);

$currentSettings = $systemSettingsService->all();
$domains = (array)($currentSettings['ad_domains'] ?? []);

$domains[$rawDomainKey] = [
    'key' => $rawDomainKey,
    'name' => trim((string)($_GET['name'] ?? $rawDomainKey)),
    'uri' => $rawUri,
    'base_dn' => 'DC=saude,DC=betim,DC=pmb',
    'dns_domain' => 'saude.betim.pmb',
    'netbios_domain' => $rawDomainKey,
    'ca_certificate' => '',
    'service_bind_dn' => '',
    'service_bind_password' => '',
    'enabled' => true,
    'replication_enabled' => true,
    'is_primary' => false,
];

$systemSettingsService->saveMany(['ad_domains' => $domains], 148);

echo "SUCCESS! Domínio SAUDE cadastrado via GET com URI limpa: " . $domains['SAUDE']['uri'] . "\n";
echo "Configuração completa do domínio SAUDE:\n";
print_r($domains['SAUDE']);
