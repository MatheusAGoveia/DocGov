<?php
// Verificação somente leitura da integridade do modelo de acessos no banco atual.
require_once __DIR__ . '/../config/db.php';

$checks = [
    'Formato inválido de regra' => 'SELECT COUNT(*) FROM permissions WHERE num_nonnulls(user_id, group_id) <> 1 OR num_nonnulls(category_id, subcategory_id, subject_id) <> 1',
    'Principal inativo com regra' => 'SELECT COUNT(*) FROM permissions p LEFT JOIN users u ON u.id = p.user_id LEFT JOIN groups g ON g.id = p.group_id WHERE (p.user_id IS NOT NULL AND COALESCE(u.active, FALSE) = FALSE) OR (p.group_id IS NOT NULL AND COALESCE(g.active, FALSE) = FALSE)',
    'Associação de equipe duplicada' => 'SELECT COUNT(*) FROM (SELECT user_id, group_id FROM user_groups GROUP BY user_id, group_id HAVING COUNT(*) > 1) duplicated',
    'Nome de equipe duplicado sem diferenciar maiúsculas' => 'SELECT COUNT(*) FROM (SELECT lower(btrim(name)) FROM groups GROUP BY lower(btrim(name)) HAVING COUNT(*) > 1) duplicated',
    'Regra de permissão duplicada' => 'SELECT COUNT(*) FROM (SELECT COALESCE(user_id, 0), COALESCE(group_id, 0), COALESCE(category_id, 0), COALESCE(subcategory_id, 0), COALESCE(subject_id, 0) FROM permissions GROUP BY 1, 2, 3, 4, 5 HAVING COUNT(*) > 1) duplicated',
    'Tabela legada ativa' => "SELECT COUNT(*) FROM pg_class WHERE relname IN ('resource_permissions', 'group_access') AND relkind = 'r'",
];

$failed = false;
foreach ($checks as $label => $sql) {
    $count = (int)$pdo->query($sql)->fetchColumn();
    echo $count === 0 ? "[OK] {$label}\n" : "[FALHA] {$label}: {$count}\n";
    $failed = $failed || $count !== 0;
}

$admins = $pdo->query("SELECT username, auth_source FROM users WHERE role = 'admin' AND active = TRUE ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
if ($admins !== [['username' => 'matheus.damiao', 'auth_source' => 'ad']]) {
    fwrite(STDERR, '[FALHA] Administradores globais ativos inesperados: ' . json_encode($admins, JSON_UNESCAPED_UNICODE) . "\n");
    $failed = true;
} else {
    echo "[OK] Único Super Admin ativo: matheus.damiao [AD]\n";
}

exit($failed ? 1 : 0);
