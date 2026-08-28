<?php
// scratch/test_independent_domains.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';

$systemSettingsService = new SystemSettingsService($pdo);

// 1. Garantir que BETIM e SAUDE existam de forma independente
$domains = (array)($systemSettingsService->get('ad_domains') ?? []);

$domains['BETIM'] = [
    'key' => 'BETIM',
    'name' => 'Prefeitura Municipal de Betim (Principal)',
    'uri' => 'ldaps://diana.betim.pmb:636',
    'base_dn' => 'DC=betim,DC=pmb',
    'dns_domain' => 'betim.pmb',
    'netbios_domain' => 'BETIM',
    'ca_certificate' => __DIR__ . '/../config/certs/diana.betim.pmb.pem',
    'service_bind_dn' => '',
    'service_bind_password' => '',
    'enabled' => true,
    'replication_enabled' => true,
    'is_primary' => true,
];

$domains['SAUDE'] = [
    'key' => 'SAUDE',
    'name' => 'Secretaria de Saúde',
    'uri' => 'ldaps://diana.betim.pmb:636',
    'base_dn' => 'DC=saude,DC=betim,DC=pmb',
    'dns_domain' => 'saude.betim.pmb',
    'netbios_domain' => 'SAUDE',
    'ca_certificate' => '',
    'service_bind_dn' => '',
    'service_bind_password' => '',
    'enabled' => true,
    'replication_enabled' => true,
    'is_primary' => false,
];

$domains['EDUCACAO'] = [
    'key' => 'EDUCACAO',
    'name' => 'Secretaria de Educação',
    'uri' => 'ldaps://diana.betim.pmb:636',
    'base_dn' => 'DC=educacao,DC=betim,DC=pmb',
    'dns_domain' => 'educacao.betim.pmb',
    'netbios_domain' => 'EDUCACAO',
    'ca_certificate' => '',
    'service_bind_dn' => '',
    'service_bind_password' => '',
    'enabled' => true,
    'replication_enabled' => true,
    'is_primary' => false,
];

$systemSettingsService->saveMany(['ad_domains' => $domains], 148);

// Verificar recuperação
$savedDomains = (array)($systemSettingsService->get('ad_domains') ?? []);

echo "=== DOMÍNIOS CORPORATIVOS INDEPENDENTES SALVOS (" . count($savedDomains) . ") ===\n\n";
foreach ($savedDomains as $k => $d) {
    echo "Domínio [{$k}]:\n";
    echo "  - Nome: {$d['name']}\n";
    echo "  - URI: {$d['uri']}\n";
    echo "  - Principal: " . (!empty($d['is_primary']) ? 'SIM' : 'NÃO') . "\n";
    echo "  - Base DN: {$d['base_dn']}\n";
    echo "  - DNS Domain: {$d['dns_domain']}\n\n";
}
