<?php
require_once __DIR__ . '/../config/db.php';

// Limpar logs duplicados mantendo apenas a tentativa mais recente de cada usuário
$pdo->exec("
    DELETE FROM ad_auth_logs a
    USING ad_auth_logs b
    WHERE a.id < b.id 
      AND LOWER(a.domain_key) = LOWER(b.domain_key)
      AND LOWER(a.username) = LOWER(b.username)
      AND a.status = b.status
");

echo "Logs duplicados limpos com sucesso no banco de dados!\n";
