<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';
$s = new SystemSettingsService($pdo);
print_r($s->all());
