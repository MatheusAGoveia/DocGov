<?php
declare(strict_types=1);
require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/_credential_input.php';
try {
    $input = docgovCredentialInput();
    define('DOCGOV_SKIP_APP_RUNTIME', true);
    require __DIR__ . '/../config/db.php';
    $pdo->beginTransaction();
    $where = $input['user_id'] > 0 ? 'id = :target' : 'LOWER(username) = LOWER(:target)';
    $stmt = $pdo->prepare("SELECT id, role, active FROM users WHERE {$where} FOR UPDATE");
    $stmt->execute([':target' => $input['user_id'] > 0 ? $input['user_id'] : $input['username']]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) !== 1 || $users[0]['role'] !== 'admin' || !$users[0]['active']) {
        throw new RuntimeException('O alvo deve identificar exatamente uma conta administrativa ativa.');
    }
    $userId = (int)$users[0]['id'];
    $hash = password_hash($input['password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id RETURNING password_hash');
    $stmt->execute([':hash' => $hash, ':id' => $userId]);
    if (!password_verify($input['password'], (string)$stmt->fetchColumn())) {
        throw new RuntimeException('Não foi possível confirmar a gravação da senha.');
    }
    $pdo->commit();
    unset($input['password'], $hash);
    require_once __DIR__ . '/../services/UsageAuditService.php';
    (new UsageAuditService($pdo))->log('admin_action', null, 'USER', $userId, ['action' => 'emergency_password_rotated', 'source' => 'cli']);
    echo "Senha de emergência atualizada para a conta ID {$userId}. Credencial não exibida.\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Operação não concluída: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
