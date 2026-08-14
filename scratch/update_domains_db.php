<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';

$userId = (int)$pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
$s = new SystemSettingsService($pdo);
$current = $s->all();
$domains = $current['ad_domains'] ?? [];

$domains['BETIM'] = [
    'key' => 'BETIM',
    'name' => 'Prefeitura Municipal de Betim',
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
    'uri' => '',
    'base_dn' => '',
    'dns_domain' => 'saude.betim.pmb',
    'netbios_domain' => 'SAUDE',
    'ca_certificate' => '',
    'service_bind_dn' => '',
    'service_bind_password' => '',
    'enabled' => true,
    'replication_enabled' => true,
    'is_primary' => false,
];

$s->saveMany(['ad_domains' => $domains], $userId);
echo "Updated ad_domains in DB!\n";
