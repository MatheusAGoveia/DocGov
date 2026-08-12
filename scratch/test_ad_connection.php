<?php
// Smoke test seguro: valida LDAPS/certificado/RootDSE sem testar ou solicitar senha.

$config = require __DIR__ . '/../config/active_directory.php';

if (!extension_loaded('ldap')) {
    fwrite(STDERR, "[FALHA] Extensão LDAP não habilitada.\n");
    exit(1);
}

if (!is_file($config['ca_certificate'])) {
    fwrite(STDERR, "[FALHA] Certificado LDAP não encontrado.\n");
    exit(1);
}

ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $config['ca_certificate']);
ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);

$ldap = ldap_connect($config['uri']);
ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, $config['network_timeout']);

if (!@ldap_bind($ldap)) {
    fwrite(STDERR, "[FALHA] Não foi possível estabelecer sessão LDAPS validada.\n");
    exit(1);
}

$read = ldap_read($ldap, '', '(objectClass=*)', ['defaultNamingContext', 'dnsHostName']);
$entries = ldap_get_entries($ldap, $read);
$root = $entries[0] ?? [];

$actualBaseDn = strtolower((string)($root['defaultnamingcontext'][0] ?? ''));
if ($actualBaseDn !== strtolower($config['base_dn'])) {
    fwrite(STDERR, "[FALHA] Base DN retornada pelo AD não corresponde à configuração.\n");
    exit(1);
}

echo '[OK] LDAPS validado: ', ($root['dnshostname'][0] ?? $config['uri']), ' / ', $config['base_dn'], PHP_EOL;
ldap_unbind($ldap);
