<?php
require_once __DIR__ . '/_cli_only.php';
// CLI: cria uma base local exclusiva, executa testes e remove a base ao terminar.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('DOCGOV_SKIP_APP_RUNTIME', true);
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/SystemAccessService.php';
if ($appEnvironment === 'production' || !in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Este teste exige PostgreSQL local de desenvolvimento.');
}
$serverPdo = $pdo;
$testDatabase = 'docgov_nesting_test_' . bin2hex(random_bytes(6));
$oldDatabase = getenv('DB_NAME');
$serverPdo->exec('CREATE DATABASE ' . $testDatabase);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo '[OK] ' . $message . PHP_EOL;
};
$insert = static function (PDO $db, string $sql, array $params): int {
    $stmt = $db->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn();
};
$call = static function (int $userId, array $params): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/request_admin_action.php', (string)$userId, 'admin', base64_encode(json_encode($params))],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Falha ao iniciar o adaptador.');
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    preg_match('/HTTP_STATUS:(\d+)/', $err, $status);
    return [(int)($status[1] ?? 0), $out, $err];
};
$rejected = static function (callable $change): bool {
    try { $change(); return false; } catch (InvalidArgumentException|RuntimeException $e) { return true; }
};
try {
    putenv('DB_NAME=' . $testDatabase);
    $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $testDatabase), $dbUser, (string)$dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    $schema = preg_replace('/-- BEGIN NESTED GROUPS.*?-- END NESTED GROUPS\s*/s', '', $schema);
    $pdo->exec($schema);
    $admin = $insert($pdo, "INSERT INTO users (name, username, email, role) VALUES (?, ?, ?, 'admin') RETURNING id", ['Admin Teste', 'nest.admin', 'nest.admin@example.invalid']);
    $pdo->prepare("UPDATE users SET auth_source = 'ad' WHERE id = ?")->execute([$admin]);
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('ad_super_admin_users', '[\"nest.admin\"]'::jsonb)");
    $leafUser = $insert($pdo, "INSERT INTO users (name, username, email) VALUES (?, ?, ?) RETURNING id", ['Membro Folha', 'nest.leaf', 'nest.leaf@example.invalid']);
    $parentUser = $insert($pdo, "INSERT INTO users (name, username, email) VALUES (?, ?, ?) RETURNING id", ['Membro Superior', 'nest.parent', 'nest.parent@example.invalid']);
    $manager = $insert($pdo, "INSERT INTO users (name, username, email) VALUES (?, ?, ?) RETURNING id", ['Gestor Local', 'nest.manager', 'nest.manager@example.invalid']);
    $inactiveUser = $insert($pdo, "INSERT INTO users (name, username, email, active) VALUES (?, ?, ?, FALSE) RETURNING id", ['Inativo', 'nest.inactive', 'nest.inactive@example.invalid']);
    $groupIds = [];
    foreach (['Financeiro', 'Pagamentos', 'Equipe Folha', 'Outra Diretoria', 'Equipe Livre'] as $name) {
        $groupIds[] = $insert($pdo, 'INSERT INTO groups (name) VALUES (?) RETURNING id', [$name]);
    }
    [$parent, $child, $leaf, $other, $free] = $groupIds;
    $pdo->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (?, ?), (?, ?), (?, ?)')
        ->execute([$leafUser, $leaf, $parentUser, $parent, $inactiveUser, $leaf]);
    $category = $insert($pdo, 'INSERT INTO categories (name, slug) VALUES (?, ?) RETURNING id', ['Financeiro', 'financeiro']);
    $subcategory = $insert($pdo, 'INSERT INTO subcategories (category_id, name, slug) VALUES (?, ?, ?) RETURNING id', [$category, 'Rotinas', 'rotinas']);
    $subject = $insert($pdo, 'INSERT INTO subjects (subcategory_id, name, slug) VALUES (?, ?, ?) RETURNING id', [$subcategory, 'Pagamentos', 'pagamentos']);
    $document = $insert($pdo, "INSERT INTO documents (subject_id, title, slug, content_type, status) VALUES (?, ?, ?, 'text', 'published') RETURNING id", [$subject, 'Documento Privado', 'documento-privado']);
    $pdo->prepare("INSERT INTO permissions (group_id, category_id, permission_level, created_by) VALUES (?, ?, 'view', ?)")->execute([$parent, $category, $admin]);
    $before = new PermissionService($pdo);
    $assert(!$before->canView($leafUser, 'category', $category) && $before->canView($parentUser, 'category', $category), 'Comportamento direto preservado antes da migração');
    $assert(!(new GroupMembershipService($pdo))->isAvailable(), 'Opção indisponível sem migração, sem quebrar permissões');
    [$status, $html, $err] = $call($admin, ['group_action' => 'add_subgroup', 'group_id' => $parent, 'child_group_id' => $child, 'csrf_token' => str_repeat('a', 64)]);
    $assert($status === 503 && !str_contains($html, 'Fatal error'), 'POST antes da migração retorna erro controlado');
    $migration = file_get_contents(__DIR__ . '/../database/migrations/027_nested_groups.sql');
    $pdo->beginTransaction(); $pdo->exec($migration); $pdo->commit();
    $groups = new GroupMembershipService($pdo); $permissions = new PermissionService($pdo);
    $assert($groups->isAvailable(), 'Migração habilita subgrupos');
    $assert($groups->changeChild($parent, $child, $admin, true), 'Inclusão de subgrupo');
    $assert($groups->changeChild($child, $leaf, $admin, true), 'Inclusão em múltiplos níveis');
    $assert($permissions->canView($leafUser, 'document', $document), 'Herança alcança documentos privados em múltiplos níveis');
    $source = $permissions->getEffectivePermission($leafUser, 'document', $document)['sources'][0];
    $assert($source['is_group_inherited'] && array_column($source['membership_path'], 'id') === [$leaf, $child, $parent], 'Diagnóstico mostra o caminho da herança');
    $pdo->prepare("INSERT INTO permissions (group_id, subject_id, permission_level) VALUES (?, ?, 'edit')")->execute([$leaf, $subject]);
    $assert(!$permissions->canEdit($parentUser, 'subject', $subject), 'Equipe superior não recebe acessos exclusivos do subgrupo');
    $pdo->prepare("UPDATE permissions SET permission_level = 'admin' WHERE group_id = ? AND category_id = ?")->execute([$parent, $category]);
    $assert($permissions->canAdmin($leafUser, 'subject', $subject), 'Maior permissão prevalece sobre regra menor no subgrupo');
    $pdo->prepare('UPDATE groups SET active = FALSE WHERE id = ?')->execute([$child]);
    $assert(!in_array($parent, $groups->getActiveGroupIds($leafUser), true) && $permissions->canEdit($leafUser, 'subject', $subject), 'Equipe intermediária inativa interrompe somente seu caminho');
    $pdo->prepare('UPDATE groups SET active = TRUE WHERE id = ?')->execute([$child]);
    $assert($permissions->canAdmin($leafUser, 'document', $document), 'Reativação restaura acesso sem recriar vínculos');
    $assert($groups->changeChild($other, $leaf, $admin, true) && in_array($other, $groups->getActiveGroupIds($leafUser), true), 'Equipe pode fazer parte de mais de um grupo');
    $groups->changeChild($parent, $leaf, $admin, true);
    $assert(count(array_filter($groups->getEffectiveMembers($parent), static fn(array $u): bool => (int)$u['id'] === $leafUser)) === 1, 'Caminhos repetidos não duplicam membros');
    $assert(count($groups->getEffectiveMembers($parent)) === 2, 'Membros inativos não entram na contagem efetiva');
    $assert(!$permissions->canView($inactiveUser, 'document', $document), 'Usuário inativo não recebe acesso indireto');
    $assert($rejected(fn() => $groups->changeChild($leaf, $parent, $admin, true)), 'Ciclo indireto rejeitado');
    $assert($rejected(fn() => $groups->changeChild($parent, $parent, $admin, true)), 'Autoinclusão rejeitada');
    $assert($rejected(fn() => $groups->changeChild($parent, $free, $leafUser, true)), 'Usuário comum não altera hierarquia');
    $pdo->beginTransaction();
    $assert($rejected(fn() => $groups->changeChild($leaf, $parent, $admin, true)), 'Validação mantém transação externa utilizável');
    $pdo->query('SELECT 1'); $pdo->rollBack();
    $pdo->beginTransaction(); $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $assert($rejected(fn() => $groups->changeChild($free, $parent, $admin, true)), 'Banco impede escritas com snapshot antigo');
    $pdo->query('SELECT 1'); $pdo->rollBack();
    $pdo->beginTransaction(); $pdo->exec('ALTER TABLE usage_audit_events RENAME TO unavailable_audit_events');
    $assert($rejected(fn() => $groups->changeChild($free, $parent, $admin, true))
        && $groups->getRelations($free) === [], 'Falha de auditoria reverte o vínculo junto com a gravação');
    $pdo->rollBack();
    $assert(!in_array($parent, array_map('intval', array_column($groups->getAvailableChildren($leaf), 'id')), true), 'Seletor exclui candidatos que criariam ciclo');
    $pdo->prepare("INSERT INTO permissions (user_id, category_id, permission_level) VALUES (?, ?, 'admin')")->execute([$manager, $category]);
    $assert($permissions->canViewUserInAdministrativeScope($manager, $leafUser), 'Gestor local visualiza membros indiretos no seu escopo');
    $teams = $permissions->getUserTeamsForAdministrativeScope($manager, $leafUser);
    $assert(count($teams) === 2 && in_array($parent, array_column($teams, 'id'), true), 'Equipes indiretas aparecem no escopo administrativo');
    $diagnosis = $permissions->getUserEffectiveAccessDiagnosis($leafUser);
    $assert(in_array($parent, array_column($diagnosis['active_groups'], 'id'), true), 'Diagnóstico completo inclui equipes indiretas');
    $system = new SystemAccessService($pdo, $permissions);
    $system->syncGroupCapabilities($parent, [SystemAccessService::SETTINGS_MANAGE], $admin);
    $assert(!$system->hasAnyCapability($leafUser) && $system->hasCapability($parentUser, SystemAccessService::SETTINGS_MANAGE), 'Capacidades globais continuam exclusivas dos membros diretos');
    $token = ['csrf_token' => str_repeat('a', 64)];
    $args = ['group_action' => 'add_subgroup', 'group_id' => $other, 'child_group_id' => $free, '_tab' => 'editar_grupo', '_group_tab' => 'groups'];
    $assert($call($admin, $args)[0] === 419, 'Inclusão pelo painel exige CSRF');
    $assert($call($manager, $args + $token)[0] === 403, 'Gestor de conteúdo não pode incluir subgrupos');
    $assert($call($admin, $args + $token)[0] === 302, 'Painel persiste inclusão de subgrupo');
    $assert($call($admin, $args + $token)[0] === 422, 'Painel rejeita vínculo duplicado');
    $assert($call($admin, array_replace($args, ['group_action' => 'remove_subgroup']) + $token)[0] === 302, 'Painel remove vínculo sem excluir equipe');
    $assert($call($admin, array_replace($args, ['group_id' => $leaf, 'child_group_id' => $parent]) + $token)[0] === 422, 'Painel rejeita ciclo com mensagem de validação');
    $groups->changeChild($parent, $leaf, $admin, false);
    $groups->changeChild($parent, $child, $admin, false);
    $assert(!$permissions->canAdmin($leafUser, 'subject', $subject) && $permissions->canEdit($leafUser, 'subject', $subject), 'Remoção revoga a herança e preserva acessos de outras origens');
    $pdo->beginTransaction();
    try { $pdo->prepare('INSERT INTO group_memberships (parent_group_id, child_group_id) VALUES (?, ?)')->execute([$leaf, $other]); throw new RuntimeException('Ciclo aceito pelo banco'); }
    catch (PDOException $e) { $assert($e->getCode() === '23514', 'Banco também rejeita ciclos fora do serviço'); }
    finally { $pdo->rollBack(); }
    $pdo->beginTransaction(); $pdo->exec($migration); $pdo->commit();
    $assert(count($groups->getRelations($other)) === 1, 'Reaplicar migração preserva vínculos existentes');
    $pdo->prepare('DELETE FROM groups WHERE id = ?')->execute([$other]);
    $assert($pdo->query('SELECT COUNT(*) FROM group_memberships WHERE parent_group_id = ' . $other . ' OR child_group_id = ' . $other)->fetchColumn() == 0
        && $pdo->query('SELECT COUNT(*) FROM groups WHERE id = ' . $leaf)->fetchColumn() == 1, 'Excluir equipe remove vínculos e preserva subgrupos');
    $assert($pdo->query("SELECT COUNT(*) FROM usage_audit_events WHERE metadata->>'action' IN ('team_subgroup_added', 'team_subgroup_removed')")->fetchColumn() >= 8, 'Inclusões e remoções registradas na auditoria');
    foreach (['service', 'raw'] as $mode) {
        $one = $insert($pdo, 'INSERT INTO groups (name) VALUES (?) RETURNING id', ['Concorrência A ' . $mode]);
        $two = $insert($pdo, 'INSERT INTO groups (name) VALUES (?) RETURNING id', ['Concorrência B ' . $mode]);
        $worker = [PHP_BINARY, __DIR__ . '/request_nested_group_change.php'];
        $first = proc_open([...$worker, (string)$one, (string)$two, (string)$admin, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $firstPipes);
        $assert(trim(fgets($firstPipes[1])) === 'INSERTED', 'Primeiro vínculo concorrente inserido: ' . $mode);
        $second = proc_open([...$worker, (string)$two, (string)$one, (string)$admin, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $secondPipes);
        $firstOut = stream_get_contents($firstPipes[1]); $firstErr = stream_get_contents($firstPipes[2]);
        fclose($firstPipes[1]); fclose($firstPipes[2]); $firstCode = proc_close($first);
        $secondOut = stream_get_contents($secondPipes[1]); $secondErr = stream_get_contents($secondPipes[2]);
        fclose($secondPipes[1]); fclose($secondPipes[2]); $secondCode = proc_close($second);
        $assert($firstCode === 0 && $secondCode === 1 && str_contains($secondOut, 'REJECTED'), 'Concorrência não cria ciclo: ' . $mode . ' ' . $firstErr . $secondErr);
    }
    if (in_array('--browser', $argv, true)) {
        $process = proc_open(['node', __DIR__ . '/test_nested_groups_browser.js'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        echo $out;
        if ($exit !== 0) throw new RuntimeException('Navegador: ' . $err);
    }
    foreach (['test_permissions.php', 'test_group_access_admin.php', 'test_system_access_groups.php', 'test_permission_api.php', 'test_hierarchy_resolution.php', 'test_access_integrity.php'] as $test) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/' . $test], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        if ($exit !== 0) throw new RuntimeException($test . ': ' . $out . $err);
        echo '[OK] Regressão ' . $test . PHP_EOL;
    }
    echo "OK: grupos aninhados e regressões validados em base exclusiva.\n";
} finally {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $pdo = null; $groups = null; $permissions = null; $system = null; $before = null;
    $serverPdo->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()')->execute([$testDatabase]);
    $serverPdo->exec('DROP DATABASE ' . $testDatabase);
    putenv($oldDatabase === false ? 'DB_NAME' : 'DB_NAME=' . $oldDatabase);
}
