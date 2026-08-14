<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';

$userId = (int)$pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
$s = new SystemSettingsService($pdo);
$s->saveMany(['ad_auth_enabled' => true], $userId);
echo "ad_auth_enabled updated to true in DB using userId {$userId}!\n";
