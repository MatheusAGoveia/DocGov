<?php

// Verificação isolada da auditoria de uso. Nenhum evento de teste é persistido.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/UsageAuditService.php';

$userId = (int)$pdo->query('SELECT id FROM users WHERE active = TRUE ORDER BY id LIMIT 1')->fetchColumn();
if ($userId <= 0) {
    echo "SKIP: não há usuário ativo para validar a auditoria.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $service = new UsageAuditService($pdo);
    $service->log('portal_view', $userId, 'PORTAL', null, ['test' => true]);

    $count = (int)$pdo->query("SELECT COUNT(*) FROM usage_audit_events WHERE event_type = 'portal_view'")->fetchColumn();
    if ($count < 1) {
        throw new RuntimeException('O evento de auditoria não foi registrado na transação de teste.');
    }

    $pdo->rollBack();
    echo "OK: evento de uso registrado e transação revertida.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERRO: ' . $exception->getMessage() . "\n");
    exit(1);
}
