<?php

$superAdminUsers = getenv('AD_SUPER_ADMIN_USERS') ?: 'matheus.damiao';
$defaultDomain = strtoupper(trim((string)(getenv('AD_DEFAULT_DOMAIN') ?: 'BETIM')));
$domains = [
    'BETIM' => [
        'key' => 'BETIM',
        'uri' => getenv('AD_LDAP_URI') ?: 'ldaps://diana.betim.pmb:636',
        'base_dn' => getenv('AD_BASE_DN') ?: 'DC=betim,DC=pmb',
        'dns_domain' => getenv('AD_DNS_DOMAIN') ?: 'betim.pmb',
        'netbios_domain' => getenv('AD_NETBIOS_DOMAIN') ?: 'BETIM',
        'ca_certificate' => getenv('AD_CA_CERTIFICATE') ?: __DIR__ . '/certs/diana.betim.pmb.pem',
        'service_bind_dn' => trim((string)(getenv('AD_SERVICE_BIND_DN') ?: '')),
        'service_bind_password' => (string)(getenv('AD_SERVICE_BIND_PASSWORD') ?: ''),
        'aliases' => ['BETIM'],
    ],
    // Preencha AD_SAUDE_* caso Saúde use um AD/forest diferente.
    'SAUDE' => [
        'key' => 'SAUDE',
        'uri' => trim((string)(getenv('AD_SAUDE_LDAP_URI') ?: '')),
        'base_dn' => trim((string)(getenv('AD_SAUDE_BASE_DN') ?: '')),
        'dns_domain' => trim((string)(getenv('AD_SAUDE_DNS_DOMAIN') ?: '')),
        'netbios_domain' => trim((string)(getenv('AD_SAUDE_NETBIOS_DOMAIN') ?: 'SAUDE')),
        'ca_certificate' => trim((string)(getenv('AD_SAUDE_CA_CERTIFICATE') ?: '')),
        'service_bind_dn' => trim((string)(getenv('AD_SAUDE_SERVICE_BIND_DN') ?: '')),
        'service_bind_password' => (string)(getenv('AD_SAUDE_SERVICE_BIND_PASSWORD') ?: ''),
        'aliases' => ['SAUDE'],
    ],
];

return [
    'enabled' => filter_var(getenv('AD_AUTH_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    // Compatibilidade com configurações existentes de um único domínio.
    'uri' => $domains['BETIM']['uri'],
    'base_dn' => $domains['BETIM']['base_dn'],
    'dns_domain' => $domains['BETIM']['dns_domain'],
    'netbios_domain' => $domains['BETIM']['netbios_domain'],
    'ca_certificate' => $domains['BETIM']['ca_certificate'],
    'network_timeout' => max(2, (int)(getenv('AD_NETWORK_TIMEOUT') ?: 5)),
    'service_bind_dn' => $domains['BETIM']['service_bind_dn'],
    'service_bind_password' => $domains['BETIM']['service_bind_password'],
    'default_domain' => $defaultDomain,
    'domains' => $domains,
    // Só habilite depois de configurar Windows Authentication no IIS/Apache.
    'integrated_windows_enabled' => filter_var(getenv('AD_INTEGRATED_WINDOWS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'super_admin_users' => array_values(array_filter(array_map(
        static fn(string $username): string => strtolower(trim($username)),
        preg_split('/[,;\s]+/', $superAdminUsers) ?: []
    ))),
];
