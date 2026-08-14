<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';

$s = new SystemSettingsService($pdo);
$all = $s->all();
echo "ad_auth_enabled in DB: " . var_export($all['ad_auth_enabled'] ?? null, true) . "\n";
echo "ad_auth_enabled type in DB: " . gettype($all['ad_auth_enabled'] ?? null) . "\n";

$config = require __DIR__ . '/../config/active_directory.php';
echo "ad_auth_enabled in config array: " . var_export($config['enabled'] ?? null, true) . "\n";
