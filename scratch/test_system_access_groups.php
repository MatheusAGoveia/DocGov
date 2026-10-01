<?php

require_once __DIR__ . '/_cli_only.php';
define('DOCGOV_SKIP_APP_RUNTIME', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/SystemAccessService.php';

$permissionService = new PermissionService($pdo);
$systemAccessService = new SystemAccessService($pdo, $permissionService);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$actorId = 0;
$userRows = $pdo->query('SELECT id FROM users WHERE active = TRUE ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
foreach ($userRows as $candidateId) {
    if ($permissionService->isGlobalAdmin((int)$candidateId)) {
        $actorId = (int)$candidateId;
        break;
    }
}
if ($actorId <= 0) {
    throw new RuntimeException('Nenhum Super Admin ativo foi encontrado para executar o teste.');
}

$pdo->beginTransaction();
try {
    $token = bin2hex(random_bytes(6));
    $userStmt = $pdo->prepare('
        INSERT INTO users (name, username, email, role, auth_source, active)
        VALUES (:name, :username, :email, :role, :auth_source, TRUE)
        RETURNING id
    ');
    $userStmt->execute([
        ':name' => 'Teste temporário de capacidade',
        ':username' => 'tmp_cap_' . $token,
        ':email' => 'tmp_cap_' . $token . '@invalid.local',
        ':role' => 'reader',
        ':auth_source' => 'ad',
    ]);
    $userId = (int)$userStmt->fetchColumn();

    $groupStmt = $pdo->prepare('
        INSERT INTO groups (name, description, active)
        VALUES (:name, :description, TRUE)
        RETURNING id
    ');
    $groupStmt->execute([
        ':name' => 'Equipe temporária ' . $token,
        ':description' => 'Criada apenas pelo teste automatizado.',
    ]);
    $groupId = (int)$groupStmt->fetchColumn();
    $pdo->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)')
        ->execute([':user_id' => $userId, ':group_id' => $groupId]);

    $assert(!$systemAccessService->hasAnyCapability($userId), 'Uma equipe sem capacidades concedeu acesso administrativo.');

    $changes = $systemAccessService->syncGroupCapabilities($groupId, [
        SystemAccessService::SETTINGS_MANAGE,
        SystemAccessService::DIRECTORY_MANAGE,
    ], $actorId);
    $assert(count($changes['granted']) === 2, 'A concessão não registrou as duas capacidades esperadas.');
    $assert($systemAccessService->hasCapability($userId, SystemAccessService::SETTINGS_MANAGE), 'Configurações gerais não foram concedidas.');
    $assert($systemAccessService->hasCapability($userId, SystemAccessService::DIRECTORY_MANAGE), 'Diretório não foi concedido.');
    $assert(!$systemAccessService->hasCapability($userId, SystemAccessService::AUTHENTICATION_MANAGE), 'Uma capacidade não selecionada foi concedida.');

    $pdo->prepare('UPDATE groups SET active = FALSE WHERE id = :id')->execute([':id' => $groupId]);
    $assert(!$systemAccessService->hasAnyCapability($userId), 'Equipe inativa continuou concedendo acesso.');
    $pdo->prepare('UPDATE groups SET active = TRUE WHERE id = :id')->execute([':id' => $groupId]);
    $assert($systemAccessService->hasAnyCapability($userId), 'Reativação da equipe não restaurou os acessos salvos.');

    $invalidRejected = false;
    try {
        $systemAccessService->syncGroupCapabilities($groupId, ['system.invalid.manage'], $actorId);
    } catch (InvalidArgumentException) {
        $invalidRejected = true;
    }
    $assert($invalidRejected, 'Uma capacidade desconhecida foi aceita.');

    $escalationRejected = false;
    try {
        $systemAccessService->syncGroupCapabilities($groupId, [SystemAccessService::AUDIT_VIEW], $userId);
    } catch (RuntimeException) {
        $escalationRejected = true;
    }
    $assert($escalationRejected, 'Um usuário delegado conseguiu alterar capacidades da própria equipe.');

    $changes = $systemAccessService->syncGroupCapabilities($groupId, [], $actorId);
    $assert(count($changes['revoked']) === 2, 'A revogação não removeu as duas capacidades.');
    $assert(!$systemAccessService->hasAnyCapability($userId), 'O usuário manteve acesso após a revogação.');

    $auditStmt = $pdo->prepare('SELECT COUNT(*) FROM system_capability_audit WHERE group_id = :group_id');
    $auditStmt->execute([':group_id' => $groupId]);
    $assert((int)$auditStmt->fetchColumn() === 4, 'A trilha de auditoria não registrou concessões e revogações.');
    $assert($systemAccessService->hasCapability($actorId, SystemAccessService::AUTHENTICATION_MANAGE), 'O Super Admin não recebeu o bypass esperado.');

    $pdo->rollBack();
    echo "OK: capacidades administrativas por equipe validadas sem deixar dados de teste.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FALHA: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
