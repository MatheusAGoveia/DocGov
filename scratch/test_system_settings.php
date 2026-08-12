<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';

function settingsAssert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$actorId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
settingsAssert($actorId > 0, 'Nenhum Super Admin ativo para o teste.');

$pdo->beginTransaction();
try {
    $service = new SystemSettingsService($pdo);
    $service->saveMany([
        'portal_name' => 'DocSec Teste',
        'cors_enabled' => true,
        'cors_allowed_origins' => ['https://sistema.example.invalid'],
        'cors_allowed_methods' => ['GET', 'POST', 'OPTIONS'],
        'maintenance_enabled' => true,
        'maintenance_mode' => 'read_only',
        'maintenance_scope' => ['portal', 'api'],
        'maintenance_reason' => 'Atualização de infraestrutura',
        'maintenance_reference' => 'CHG-TESTE',
        'maintenance_responsible' => 'Equipe de TI',
        'maintenance_progress' => 35,
        'maintenance_announce_minutes' => 60,
        'maintenance_auto_refresh_seconds' => 30,
        'maintenance_start_at' => (new DateTimeImmutable('-5 minutes'))->format(DateTimeInterface::ATOM),
        'maintenance_end_at' => (new DateTimeImmutable('+5 minutes'))->format(DateTimeInterface::ATOM),
    ], $actorId);
    settingsAssert($service->get('portal_name') === 'DocSec Teste', 'Nome do portal não foi persistido.');
    settingsAssert($service->maintenanceStatus()['active'] === true, 'Janela atual não ativou a manutenção.');
    settingsAssert($service->get('maintenance_mode') === 'read_only', 'Modo somente leitura não foi persistido.');
    settingsAssert($service->get('maintenance_scope') === ['portal', 'api'], 'Escopo da manutenção não foi persistido.');

    $service->saveMany([
        'maintenance_start_at' => (new DateTimeImmutable('+1 hour'))->format(DateTimeInterface::ATOM),
        'maintenance_end_at' => (new DateTimeImmutable('+2 hours'))->format(DateTimeInterface::ATOM),
    ], $actorId);
    settingsAssert($service->maintenanceStatus()['scheduled'] === true, 'Janela futura não foi reconhecida como agendada.');

    $service->saveMany(['maintenance_enabled' => false], $actorId);
    settingsAssert($service->maintenanceStatus()['active'] === false, 'Comando de encerramento não desativou a manutenção.');
    echo "[OK] Identidade, CORS, agendamento, ativação e encerramento de manutenção validados.\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
