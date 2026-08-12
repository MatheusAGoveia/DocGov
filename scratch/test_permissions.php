<?php
// Testes transacionais do motor de permissões. Nenhuma fixture persiste no banco.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';

function failTest(string $message): never {
    throw new RuntimeException($message);
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        failTest($message . " (esperado: " . var_export($expected, true) . ", obtido: " . var_export($actual, true) . ")");
    }
}

function assertThrows(callable $callback, string $message): void {
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    failTest($message);
}

function insertReturningId(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function pass(string $label): void {
    echo "  [OK] {$label}\n";
}

$suffix = bin2hex(random_bytes(5));
$exitCode = 0;
$pdo->beginTransaction();

try {
    $actorId = insertReturningId(
        $pdo,
        "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'admin', TRUE) RETURNING id",
        ['Teste Admin', "test.admin.{$suffix}", "test.admin.{$suffix}@example.invalid"]
    );
    $samuelId = insertReturningId(
        $pdo,
        "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'reader', TRUE) RETURNING id",
        ['Samuel Teste', "samuel.{$suffix}", "samuel.{$suffix}@example.invalid"]
    );
    $leonidasId = insertReturningId(
        $pdo,
        "INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'reader', TRUE) RETURNING id",
        ['Leonidas Teste', "leonidas.{$suffix}", "leonidas.{$suffix}@example.invalid"]
    );

    $categoryId = insertReturningId(
        $pdo,
        "INSERT INTO categories (name, slug, description, active) VALUES (?, ?, '', TRUE) RETURNING id",
        ['Infraestrutura Teste', "infraestrutura-{$suffix}"]
    );
    $networksId = insertReturningId(
        $pdo,
        "INSERT INTO subcategories (category_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id",
        [$categoryId, 'Redes Teste', "redes-{$suffix}"]
    );
    $serversId = insertReturningId(
        $pdo,
        "INSERT INTO subcategories (category_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id",
        [$categoryId, 'Servidores Teste', "servidores-{$suffix}"]
    );
    $firewallId = insertReturningId(
        $pdo,
        "INSERT INTO subjects (subcategory_id, name, slug, description, active) VALUES (?, ?, ?, '', TRUE) RETURNING id",
        [$networksId, 'Firewall Teste', "firewall-{$suffix}"]
    );

    $teamAId = insertReturningId(
        $pdo,
        "INSERT INTO groups (name, description, active) VALUES (?, '', TRUE) RETURNING id",
        ["Equipe A {$suffix}"]
    );
    $teamBId = insertReturningId(
        $pdo,
        "INSERT INTO groups (name, description, active) VALUES (?, '', TRUE) RETURNING id",
        ["Equipe B {$suffix}"]
    );
    $teamTiId = insertReturningId(
        $pdo,
        "INSERT INTO groups (name, description, active) VALUES (?, '', TRUE) RETURNING id",
        ["Equipe TI {$suffix}"]
    );

    $service = new PermissionService($pdo);
    $addMember = $pdo->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
    $removeMember = $pdo->prepare('DELETE FROM user_groups WHERE user_id = ? AND group_id = ?');

    echo "DocGov - testes de equipes, herança e administração local\n";

    // 1. Equipe TI VIEW na categoria deve alcançar o assunto descendente.
    $addMember->execute([$samuelId, $teamTiId]);
    $service->saveResourcePermission('category', $categoryId, null, $teamTiId, 'view', $actorId);
    assertSameValue('view', $service->getEffectivePermission($samuelId, 'subject', $firewallId)['effective_level'], 'VIEW da equipe não foi herdado no assunto');
    $groupDiagnosis = $service->getUserEffectiveAccessDiagnosis($samuelId, 'groups');
    $groupDiagnosisIds = array_map('intval', array_column($groupDiagnosis['resources'], 'resource_id'));
    assertSameValue(true, in_array($firewallId, $groupDiagnosisIds, true), 'Filtro Via Equipes omitiu acesso herdado da equipe');
    pass('1. permissão de equipe herdada Categoria -> Assunto');

    // Limpeza das regras entre cenários, mantendo tudo dentro da transação.
    $pdo->prepare('DELETE FROM permissions WHERE category_id = ? OR subcategory_id IN (?, ?) OR subject_id = ?')->execute([$categoryId, $networksId, $serversId, $firewallId]);
    $pdo->prepare('DELETE FROM user_groups WHERE user_id = ?')->execute([$samuelId]);

    // 2. Maior permissão entre duas equipes vence.
    $addMember->execute([$samuelId, $teamAId]);
    $addMember->execute([$samuelId, $teamBId]);
    $service->saveResourcePermission('category', $categoryId, null, $teamAId, 'view', $actorId);
    $service->saveResourcePermission('category', $categoryId, null, $teamBId, 'edit', $actorId);
    assertSameValue('edit', $service->getEffectivePermission($samuelId, 'subject', $firewallId)['effective_level'], 'MAX entre equipes não retornou EDIT');
    pass('2. maior permissão entre múltiplas equipes');

    // 3. ADMIN de equipe prevalece sobre VIEW direto do usuário.
    $service->saveResourcePermission('category', $categoryId, null, $teamAId, 'admin', $actorId);
    $service->saveResourcePermission('subject', $firewallId, $samuelId, null, 'view', $actorId);
    assertSameValue('admin', $service->getEffectivePermission($samuelId, 'subject', $firewallId)['effective_level'], 'ADMIN da equipe não prevaleceu sobre VIEW direto');
    pass('3. ADMIN de equipe prevalece sobre VIEW direto');

    // 4. Equipe inativa deixa de participar do cálculo.
    $pdo->prepare('DELETE FROM user_groups WHERE user_id = ?')->execute([$samuelId]);
    $addMember->execute([$samuelId, $teamAId]);
    $pdo->prepare('DELETE FROM permissions WHERE category_id = ? OR subject_id = ?')->execute([$categoryId, $firewallId]);
    $service->saveResourcePermission('category', $categoryId, null, $teamAId, 'admin', $actorId);
    $pdo->prepare('UPDATE groups SET active = FALSE WHERE id = ?')->execute([$teamAId]);
    assertSameValue('none', $service->getEffectivePermission($samuelId, 'subject', $firewallId)['effective_level'], 'Equipe inativa ainda concedeu acesso');
    pass('4. equipe inativa não concede acesso');
    $pdo->prepare('UPDATE groups SET active = TRUE WHERE id = ?')->execute([$teamAId]);

    // 5. Remover o membro elimina a influência da equipe, sem excluir a regra.
    $service->saveResourcePermission('category', $categoryId, null, $teamAId, 'edit', $actorId);
    $removeMember->execute([$samuelId, $teamAId]);
    assertSameValue('none', $service->getEffectivePermission($samuelId, 'subject', $firewallId)['effective_level'], 'Permissão da equipe continuou após remover o membro');
    assertSameValue(1, (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE group_id = {$teamAId} AND category_id = {$categoryId}")->fetchColumn(), 'Remover membro apagou a regra da equipe');
    pass('5. remoção de membro preserva a regra e retira o acesso');

    // 6. ADMIN direto no assunto prevalece sobre EDIT herdado da equipe.
    $addMember->execute([$samuelId, $teamTiId]);
    $pdo->prepare('DELETE FROM permissions WHERE category_id = ? OR subject_id = ?')->execute([$categoryId, $firewallId]);
    $service->saveResourcePermission('category', $categoryId, null, $teamTiId, 'edit', $actorId);
    $service->saveResourcePermission('subject', $firewallId, $samuelId, null, 'admin', $actorId);
    assertSameValue('admin', $service->getEffectivePermission($samuelId, 'subject', $firewallId)['effective_level'], 'ADMIN direto não prevaleceu sobre EDIT herdado');
    pass('6. ADMIN direto prevalece sobre EDIT herdado');

    // 7. Herança é dinâmica e não materializa regra no descendente.
    $stmtDescendantRules = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE subject_id = ? AND group_id = ?');
    $stmtDescendantRules->execute([$firewallId, $teamTiId]);
    assertSameValue(0, (int)$stmtDescendantRules->fetchColumn(), 'Herança criou uma linha duplicada no descendente');
    pass('7. herança não materializa permissões descendentes');

    // 8. A visão expandida da equipe não duplica descendentes e mantém o maior nível herdado.
    $service->saveResourcePermission('subcategory', $networksId, null, $teamTiId, 'admin', $actorId);
    $expandedTeamPermissions = $service->getGroupPermissions($teamTiId, true);
    $expandedFirewall = array_values(array_filter(
        $expandedTeamPermissions,
        static fn(array $permission): bool => $permission['resource_type'] === 'subject'
            && (int)$permission['resource_id'] === $firewallId
    ));
    assertSameValue(1, count($expandedFirewall), 'Visão expandida duplicou o mesmo assunto por múltiplos ancestrais');
    assertSameValue('admin', $expandedFirewall[0]['permission_level'], 'Visão expandida não manteve o maior nível herdado');
    pass('8. diagnóstico expandido deduplica e preserva o maior nível');

    // Editor de Categoria continua com cadastro comum e recebe o painel pelo EDIT efetivo.
    assertSameValue('reader', (string)$pdo->query("SELECT role FROM users WHERE id = {$samuelId}")->fetchColumn(), 'Editor local deixou de ser usuário comum');
    assertSameValue(true, $service->canAccessAdminPanel($samuelId), 'Editor de Categoria não recebeu acesso ao painel');
    assertSameValue(true, $service->canCreateSubcategory($samuelId, $categoryId), 'Editor de Categoria não conseguiu criar subcategoria');
    assertSameValue(true, $service->canCreateSubject($samuelId, $networksId), 'Editor de Categoria não conseguiu criar assunto');
    $pdo->prepare('UPDATE categories SET active = FALSE WHERE id = ?')->execute([$categoryId]);
    assertSameValue(true, $service->canAccessAdminPanel($samuelId), 'Editor perdeu o painel após desativar o próprio ramo');
    assertSameValue(true, in_array($categoryId, $service->getAdministrativeScope($samuelId)['category_ids'], true), 'Recurso inativo sumiu do escopo administrativo');
    $pdo->prepare('UPDATE categories SET active = TRUE WHERE id = ?')->execute([$categoryId]);
    pass('Editor de Categoria recebe painel e cria estrutura mantendo role reader');

    // Admin Local: ADMIN em Redes permite Redes/Firewall, mas não Servidores.
    $service->saveResourcePermission('subcategory', $networksId, $leonidasId, null, 'admin', $actorId);
    assertSameValue(false, $service->isGlobalAdmin($leonidasId), 'Admin Local foi convertido em Admin Global');
    assertSameValue(true, $service->canAdmin($leonidasId, 'subcategory', $networksId), 'Admin Local não administra o recurso de origem');
    assertSameValue(true, $service->canAdmin($leonidasId, 'subject', $firewallId), 'Admin Local não administra descendente');
    assertSameValue(false, $service->canAdmin($leonidasId, 'subcategory', $serversId), 'Admin Local administrou ramo irmão indevidamente');
    assertSameValue(true, $service->canAccessAdminPanel($leonidasId), 'Usuário comum com ADMIN local não recebeu acesso ao painel');
    assertSameValue(true, $service->canCreateSubject($leonidasId, $networksId), 'Admin Local não conseguiu criar assunto no próprio ramo');
    $visibleUserIds = array_map('intval', array_column($service->getUsersForAdministrativeScope($leonidasId), 'id'));
    assertSameValue(true, in_array($samuelId, $visibleUserIds, true), 'Usuário do ramo autorizado não apareceu na listagem local');
    assertSameValue(false, in_array($actorId, $visibleUserIds, true), 'Usuário sem vínculo ao ramo apareceu na listagem local');
    pass('Admin Local respeita o ramo autorizado');

    // Auditoria: CREATE, CHANGE e REMOVE devem existir no fluxo do serviço.
    $service->saveResourcePermission('subject', $firewallId, $leonidasId, null, 'view', $actorId);
    $service->saveResourcePermission('subject', $firewallId, $leonidasId, null, 'edit', $actorId);
    $permissionId = (int)$pdo->query("SELECT id FROM permissions WHERE user_id = {$leonidasId} AND subject_id = {$firewallId}")->fetchColumn();
    $service->deletePermission($permissionId, $actorId);
    $auditStmt = $pdo->prepare("SELECT action, COUNT(*) FROM permission_audit WHERE user_id = ? AND principal_id = ? AND resource_type = 'SUBJECT' AND resource_id = ? GROUP BY action");
    $auditStmt->execute([$actorId, $leonidasId, $firewallId]);
    $auditCounts = $auditStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['PERMISSION_CREATED', 'PERMISSION_CHANGED', 'PERMISSION_REMOVED'] as $action) {
        if (empty($auditCounts[$action])) {
            failTest("Auditoria ausente para {$action}");
        }
    }
    pass('auditoria de criação, alteração e remoção');

    assertSameValue('none', $service->getEffectivePermission($actorId, 'category', 2000000000)['effective_level'], 'Super Admin recebeu acesso a recurso inexistente');
    assertThrows(
        fn() => $service->saveResourcePermission('category', $categoryId, $actorId, null, 'view', $actorId),
        'Foi criada regra redundante para Admin Global'
    );
    $pdo->prepare('UPDATE users SET active = FALSE WHERE id = ?')->execute([$leonidasId]);
    assertSameValue(false, $service->canAccessAdminPanel($leonidasId), 'Usuário inativo manteve acesso ao painel administrativo');
    assertThrows(
        fn() => $service->saveResourcePermission('subject', $firewallId, $leonidasId, null, 'view', $actorId),
        'Foi criada permissão nova para usuário inativo'
    );
    $inactiveDiagnosis = $service->getUserEffectiveAccessDiagnosis($leonidasId);
    assertSameValue([], $inactiveDiagnosis['resources'], 'Diagnóstico apresentou acesso efetivo para usuário inativo');
    $pdo->prepare('UPDATE users SET active = TRUE WHERE id = ?')->execute([$leonidasId]);
    pass('validações estritas de recurso, Admin Global e principal inativo');

    echo "Todos os testes passaram. Rollback das fixtures concluído.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[FALHA] {$e->getMessage()}\n");
    $exitCode = 1;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

exit($exitCode);
