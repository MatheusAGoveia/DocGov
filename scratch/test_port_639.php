<?php
// scratch/test_port_639.php
$uri = 'ldaps://diana.betim.pmb:639';
echo "Testing $uri ...\n";

putenv('LDAPTLS_REQCERT=never');
@ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
@ldap_set_option(null, LDAP_OPT_DEBUG_LEVEL, 0);

$conn = @ldap_connect($uri);
@ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
@ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
    @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 3);
}

$start = microtime(true);
$bound = @ldap_bind($conn);
$err = ldap_error($conn);
$errno = ldap_errno($conn);
$elapsed = round((microtime(true) - $start) * 1000);

echo "Bound result: " . ($bound ? 'TRUE' : 'FALSE') . "\n";
echo "Error code: $errno ($err)\n";
echo "Elapsed: {$elapsed}ms\n";
