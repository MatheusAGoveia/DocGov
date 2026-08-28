<?php
require_once __DIR__ . '/../config/db.php';
$affected = $pdo->exec("DELETE FROM ad_auth_logs WHERE LOWER(username) = 'joao.silva'");
echo "Registros mock de teste removidos para joao.silva: {$affected}\n";
