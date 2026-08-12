<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/SystemSettingsService.php';

function adminSettingsAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function callSettingsAction(int $adminId, array $params): array {
    $params['_tab'] = 'configuracoes';
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/request_admin_action.php')
        . ' ' . escapeshellarg((string)$adminId)
        . ' admin '
        . escapeshellarg(base64_encode(json_encode($params)));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Falha ao executar configurações em subprocesso.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    preg_match('/HTTP_STATUS:(\d+)/', $stderr, $match);
    return ['status' => (int)($match[1] ?? 0), 'stdout' => $stdout, 'stderr' => $stderr];
}

$suffix = bin2hex(random_bytes(5));
$adminId = 0;
$service = new SystemSettingsService($pdo);
$original = $service->all(true);

try {
    $stmt = $pdo->prepare("INSERT INTO users (name, username, email, role, active) VALUES (?, ?, ?, 'admin', TRUE) RETURNING id");
    $stmt->execute(['Admin Configurações', "settings.admin.{$suffix}", "settings.admin.{$suffix}@example.invalid"]);
    $adminId = (int)$stmt->fetchColumn();

    $validPayload = [
        'save_system_settings' => '1',
        'portal_name' => "DocSec {$suffix}",
        'organization_name' => 'Prefeitura Teste',
        'portal_description' => 'Portal institucional de teste',
        'portal_theme' => 'ocean',
        'support_email' => 'suporte@example.invalid',
        'timezone' => 'America/Sao_Paulo',
        'session_timeout_minutes' => '90',
        'cors_enabled' => '1',
        'cors_allowed_origins' => "https://sistema.example.invalid\nhttp://localhost:3000",
        'cors_allowed_methods' => ['GET', 'POST', 'OPTIONS'],
        'cors_allow_credentials' => '1',
        'maintenance_title' => 'Manutenção programada',
        'maintenance_message' => 'Sistema temporariamente indisponível para melhorias.',
        'maintenance_mode' => 'full',
        'maintenance_scope' => ['portal', 'admin', 'api', 'files'],
        'maintenance_reason' => 'Atualização planejada de teste',
        'maintenance_reference' => 'CHG-TESTE',
        'maintenance_responsible' => 'Equipe de TI',
        'maintenance_progress' => '20',
        'maintenance_announce_minutes' => '60',
        'maintenance_auto_refresh_seconds' => '30',
        'maintenance_start_at' => '',
        'maintenance_end_at' => '',
    ];

    $withoutCsrf = callSettingsAction($adminId, $validPayload);
    adminSettingsAssert($withoutCsrf['status'] === 419, 'Configurações foram aceitas sem CSRF.');
    adminSettingsAssert((new SystemSettingsService($pdo))->get('portal_name') === $original['portal_name'], 'Valor mudou sem CSRF.');

    $invalidCors = $validPayload + ['csrf_token' => str_repeat('a', 64)];
    $invalidCors['cors_allowed_origins'] = '*';
    $invalidResult = callSettingsAction($adminId, $invalidCors);
    adminSettingsAssert($invalidResult['status'] === 200, 'CORS inseguro não retornou ao formulário.');
    adminSettingsAssert((new SystemSettingsService($pdo))->get('portal_name') === $original['portal_name'], 'CORS inseguro persistiu alterações.');

    $validPayload['csrf_token'] = str_repeat('a', 64);
    $saved = callSettingsAction($adminId, $validPayload);
    adminSettingsAssert($saved['status'] === 302, 'Configuração válida não redirecionou após salvar: ' . $saved['stderr']);
    $savedSettings = (new SystemSettingsService($pdo))->all(true);
    adminSettingsAssert($savedSettings['portal_name'] === "DocSec {$suffix}", 'Nome do sistema não foi salvo.');
    adminSettingsAssert($savedSettings['portal_theme'] === 'ocean', 'Tema padrão não foi salvo.');
    adminSettingsAssert($savedSettings['cors_allowed_origins'] === ['https://sistema.example.invalid', 'http://localhost:3000'], 'Origens CORS não foram normalizadas.');
    $auditStmt = $pdo->prepare("SELECT COUNT(*) FROM usage_audit_events WHERE user_id = ? AND event_type = 'admin_action' AND metadata->>'action' = 'system_settings_updated'");
    $auditStmt->execute([$adminId]);
    adminSettingsAssert((int)$auditStmt->fetchColumn() === 1, 'Alteração de configurações não foi auditada.');

    echo "[OK] Configurações: CSRF, validação CORS, persistência e auditoria validados.\n";
} finally {
    if ($adminId > 0) {
        $service->saveMany($original, $adminId);
        $pdo->prepare('DELETE FROM usage_audit_events WHERE user_id = ?')->execute([$adminId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$adminId]);
    }
}
