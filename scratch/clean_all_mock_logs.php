<?php
require_once __DIR__ . '/../config/db.php';
$affected = $pdo->exec("DELETE FROM ad_auth_logs WHERE LOWER(username) IN ('maria.silva', 'joao.silva', 'usuario.teste', 'teste.ad')");
echo "Registros mock de teste removidos da auditoria: {$affected}\n";
