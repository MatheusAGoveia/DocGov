<?php

$superAdminUsers = getenv('AD_SUPER_ADMIN_USERS') ?: 'matheus.damiao,marcuss';
$defaultDomain = strtoupper(trim((string)(getenv('AD_DEFAULT_DOMAIN') ?: 'BETIM')));
$domains = [
    'BETIM' => [
        'key' => 'BETIM',
        'name' => 'Prefeitura Municipal de Betim (Principal)',
        'uri' => getenv('AD_LDAP_URI') ?: 'ldaps://diana.betim.pmb:636',
        'base_dn' => getenv('AD_BASE_DN') ?: 'DC=betim,DC=pmb',
        'dns_domain' => getenv('AD_DNS_DOMAIN') ?: 'betim.pmb',
        'netbios_domain' => getenv('AD_NETBIOS_DOMAIN') ?: 'BETIM',
        'ca_certificate' => getenv('AD_CA_CERTIFICATE') ?: __DIR__ . '/certs/diana.betim.pmb.pem',
        'service_bind_dn' => trim((string)(getenv('AD_SERVICE_BIND_DN') ?: '')),
        'service_bind_password' => (string)(getenv('AD_SERVICE_BIND_PASSWORD') ?: ''),
        'aliases' => ['BETIM'],
        'enabled' => true,
        'is_primary' => true,
    ],
    'SAUDE' => [
        'key' => 'SAUDE',
        'name' => 'Secretaria de Saúde',
        'uri' => trim((string)(getenv('AD_SAUDE_LDAP_URI') ?: '')),
        'base_dn' => trim((string)(getenv('AD_SAUDE_BASE_DN') ?: '')),
        'dns_domain' => trim((string)(getenv('AD_SAUDE_DNS_DOMAIN') ?: '')),
        'netbios_domain' => trim((string)(getenv('AD_SAUDE_NETBIOS_DOMAIN') ?: 'SAUDE')),
        'ca_certificate' => trim((string)(getenv('AD_SAUDE_CA_CERTIFICATE') ?: '')),
        'service_bind_dn' => trim((string)(getenv('AD_SAUDE_SERVICE_BIND_DN') ?: '')),
        'service_bind_password' => (string)(getenv('AD_SAUDE_SERVICE_BIND_PASSWORD') ?: ''),
        'aliases' => ['SAUDE'],
        'enabled' => false,
        'is_primary' => false,
    ],
];

$enabled = filter_var(getenv('AD_AUTH_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN);
$integratedWindows = filter_var(getenv('AD_INTEGRATED_WINDOWS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$superAdminUsersList = array_values(array_filter(array_map(
    static fn(string $username): string => strtolower(trim($username)),
    preg_split('/[,;\s]+/', $superAdminUsers) ?: []
)));

// Se houver conexão com o banco e o SystemSettingsService estiver carregado, sobrepõe com as configurações salvas na interface
if (isset($pdo) && $pdo instanceof PDO && class_exists('SystemSettingsService')) {
    try {
        $settingsService = new SystemSettingsService($pdo);
        $dbSettings = $settingsService->all();
        if (isset($dbSettings['ad_auth_enabled'])) {
            $enabled = (bool)$dbSettings['ad_auth_enabled'];
        }
        if (!empty($dbSettings['ad_default_domain'])) {
            $defaultDomain = strtoupper(trim((string)$dbSettings['ad_default_domain']));
        }
        if (isset($dbSettings['ad_integrated_windows_enabled'])) {
            $integratedWindows = (bool)$dbSettings['ad_integrated_windows_enabled'];
        }
        if (!empty($dbSettings['ad_super_admin_users']) && is_array($dbSettings['ad_super_admin_users'])) {
            $superAdminUsersList = array_values(array_filter(array_map(
                static fn($u): string => strtolower(trim((string)$u)),
                $dbSettings['ad_super_admin_users']
            )));
        }
        if (!empty($dbSettings['ad_domains']) && is_array($dbSettings['ad_domains'])) {
            $domains = [];
            foreach ($dbSettings['ad_domains'] as $key => $dom) {
                $k = strtoupper(trim((string)($dom['key'] ?? $key)));
                if ($k === '') continue;
                $domains[$k] = [
                    'key' => $k,
                    'name' => (string)($dom['name'] ?? $k),
                    'uri' => (string)($dom['uri'] ?? ''),
                    'base_dn' => (string)($dom['base_dn'] ?? ''),
                    'dns_domain' => (string)($dom['dns_domain'] ?? ''),
                    'netbios_domain' => (string)($dom['netbios_domain'] ?? $k),
                    'ca_certificate' => (string)($dom['ca_certificate'] ?? ''),
                    'service_bind_dn' => (string)($dom['service_bind_dn'] ?? ''),
                    'service_bind_password' => (string)($dom['service_bind_password'] ?? ''),
                    'aliases' => array_unique(array_filter([$k, (string)($dom['netbios_domain'] ?? ''), (string)($dom['dns_domain'] ?? '')])),
                    'enabled' => !isset($dom['enabled']) || (bool)$dom['enabled'],
                    'is_primary' => !empty($dom['is_primary']),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('DocGov AD config load error: ' . $e->getMessage());
    }
}

// Localiza o servidor principal
$primaryKey = null;
foreach ($domains as $k => $d) {
    if (!empty($d['is_primary'])) {
        $primaryKey = $k;
        break;
    }
}
if (!$primaryKey) {
    $primaryKey = isset($domains[$defaultDomain]) ? $defaultDomain : array_key_first($domains);
}
if ($primaryKey && isset($domains[$primaryKey])) {
    $domains[$primaryKey]['is_primary'] = true;
    $defaultDomain = $primaryKey;
}

$primaryDomain = $domains[$defaultDomain] ?? reset($domains);

// Concatena URIs de servidores de réplica/failover ativos do mesmo NetBIOS domain para redundância nativa LDAP
$failoverUris = [];
if (!empty($primaryDomain['uri'])) {
    $failoverUris[] = $primaryDomain['uri'];
}
foreach ($domains as $k => $d) {
    if ($k !== $defaultDomain && !empty($d['enabled']) && !empty($d['uri']) && strtolower((string)$d['netbios_domain']) === strtolower((string)$primaryDomain['netbios_domain'])) {
        $failoverUris[] = $d['uri'];
    }
}
$combinedUri = implode(' ', array_unique($failoverUris));

return [
    'enabled' => $enabled,
    'uri' => $combinedUri ?: ($primaryDomain['uri'] ?? ''),
    'base_dn' => $primaryDomain['base_dn'] ?? '',
    'dns_domain' => $primaryDomain['dns_domain'] ?? '',
    'netbios_domain' => $primaryDomain['netbios_domain'] ?? $defaultDomain,
    'ca_certificate' => $primaryDomain['ca_certificate'] ?? '',
    'network_timeout' => max(2, (int)(getenv('AD_NETWORK_TIMEOUT') ?: 5)),
    'service_bind_dn' => $primaryDomain['service_bind_dn'] ?? '',
    'service_bind_password' => $primaryDomain['service_bind_password'] ?? '',
    'default_domain' => $defaultDomain,
    'domains' => $domains,
    'integrated_windows_enabled' => $integratedWindows,
    'super_admin_users' => $superAdminUsersList,
];
