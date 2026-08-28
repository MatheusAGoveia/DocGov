<?php
// scratch/test_tls_fix.php
putenv('LDAPTLS_REQCERT=never');
if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
    @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

$adService = new ActiveDirectoryAuthService($pdo);
$res636 = $adService->testServerConnection('ldaps://diana.betim.pmb:636');
echo "Resultado LDAPS (636) com LDAPTLS_REQCERT=never:\n";
print_r($res636);
