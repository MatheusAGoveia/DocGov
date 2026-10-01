<?php
require_once __DIR__ . '/_cli_only.php';
// scratch/test_connection_service.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

$adAuthService = new ActiveDirectoryAuthService($pdo);

echo "1. Testing invalid port 639...\n";
$res1 = $adAuthService->testServerConnection('ldaps://diana.betim.pmb:639');
print_r($res1);

echo "\n2. Testing valid port 636...\n";
$res2 = $adAuthService->testServerConnection('ldaps://diana.betim.pmb:636');
print_r($res2);
