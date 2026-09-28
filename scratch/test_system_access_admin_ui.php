<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/SystemAccessService.php';

function systemUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function renderSystemAdminPage(int $userId, array $params): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/request_admin_page.php')
        . ' ' . escapeshellarg((string)$userId)
        . ' reader '
        . escapeshellarg(base64_encode((string)json_encode($params)));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível renderizar o painel delegado.');
    }
    $html = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    preg_match('/HTTP_STATUS:(\d+)/', $stderr, $statusMatch);
    return [
        'status' => isset($statusMatch[1]) ? (int)$statusMatch[1] : 0,
        'html' => $html,
        'stderr' => $stderr,
    ];
}

$permissionService = new PermissionService($pdo);
$systemAccessService = new SystemAccessService($pdo, $permissionService);
$actorId = 0;
foreach ($pdo->query('SELECT id FROM users WHERE active = TRUE ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $candidateId) {
    if ($permissionService->isGlobalAdmin((int)$candidateId)) {
        $actorId = (int)$candidateId;
        break;
    }
}
systemUiAssert($actorId > 0, 'Nenhum Super Admin ativo foi encontrado.');

$suffix = bin2hex(random_bytes(5));
$userId = 0;
$groupId = 0;
try {
    $userStmt = $pdo->prepare("INSERT INTO users (name, username, email, role, auth_source, active) VALUES (?, ?, ?, 'reader', 'ad', TRUE) RETURNING id");
    $userStmt->execute(['Gestor delegado temporário', "ui.system.{$suffix}", "ui.system.{$suffix}@invalid.local"]);
    $userId = (int)$userStmt->fetchColumn();

    $groupStmt = $pdo->prepare("INSERT INTO groups (name, description, active) VALUES (?, 'Teste de interface', TRUE) RETURNING id");
    $groupStmt->execute(["Equipe UI {$suffix}"]);
    $groupId = (int)$groupStmt->fetchColumn();
    $pdo->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)')->execute([$userId, $groupId]);
    $systemAccessService->syncGroupCapabilities($groupId, [SystemAccessService::SETTINGS_MANAGE], $actorId);

    $settingsPage = renderSystemAdminPage($userId, ['tab' => 'configuracoes']);
    systemUiAssert($settingsPage['status'] === 200, 'O módulo delegado de configurações não abriu com HTTP 200.');
    systemUiAssert(str_contains($settingsPage['html'], 'Configurações do Sistema'), 'A tela de configurações não foi renderizada.');
    systemUiAssert(str_contains($settingsPage['html'], '>Configurações<'), 'O menu delegado de configurações não apareceu.');
    systemUiAssert(!str_contains($settingsPage['html'], '>Equipes<'), 'A gestão de equipes foi exposta ao usuário delegado.');
    systemUiAssert(!str_contains($settingsPage['html'], '>Autenticação<'), 'Um módulo não concedido apareceu no menu.');

    $groupsPage = renderSystemAdminPage($userId, ['tab' => 'grupos']);
    systemUiAssert($groupsPage['status'] === 403, 'A gestão de equipes não foi bloqueada para o usuário delegado.');
    systemUiAssert(!str_contains($groupsPage['html'], 'Criar nova equipe'), 'A interface de equipes foi renderizada sem autorização.');

    $authenticationPage = renderSystemAdminPage($userId, ['tab' => 'servidores_ad']);
    systemUiAssert($authenticationPage['status'] === 403, 'O módulo de autenticação não concedido deixou de retornar 403.');
    systemUiAssert(!str_contains($authenticationPage['html'], 'Autenticação & Domínios Corporativos'), 'A configuração do AD vazou para usuário sem capacidade.');

    $systemAccessService->syncGroupCapabilities($groupId, array_keys(SystemAccessService::catalog()), $actorId);
    $authenticationPage = renderSystemAdminPage($userId, ['tab' => 'servidores_ad']);
    systemUiAssert($authenticationPage['status'] === 200, 'O módulo de autenticação concedido não abriu.');
    systemUiAssert(str_contains($authenticationPage['html'], 'Autenticação & Domínios Corporativos'), 'A página do AD concedida não foi renderizada.');
    systemUiAssert((bool)preg_match('/name="ad_super_admin_users"[^>]*disabled/', $authenticationPage['html']), 'A lista de Super Admins ficou editável para um administrador delegado.');

    $directoryPage = renderSystemAdminPage($userId, ['tab' => 'usuarios']);
    systemUiAssert($directoryPage['status'] === 200, 'O diretório global concedido não abriu.');
    systemUiAssert(str_contains($directoryPage['html'], 'Usuários do Sistema'), 'O diretório delegado não exibiu a visão global de usuários.');
    systemUiAssert(str_contains($directoryPage['html'], 'Importar usuário do Active Directory'), 'A importação do AD não apareceu para o gestor do diretório.');

    $tagsPage = renderSystemAdminPage($userId, ['tab' => 'tags']);
    systemUiAssert($tagsPage['status'] === 200 && str_contains($tagsPage['html'], 'Tags e correlações'), 'A curadoria de tags concedida não abriu.');

    $auditPage = renderSystemAdminPage($userId, ['tab' => 'visao_geral']);
    systemUiAssert($auditPage['status'] === 200 && str_contains($auditPage['html'], 'Pessoas cadastradas'), 'A auditoria global concedida não exibiu o dashboard global.');

    $groupsStillForbidden = renderSystemAdminPage($userId, ['tab' => 'grupos']);
    systemUiAssert($groupsStillForbidden['status'] === 403, 'Conceder todos os módulos permitiu administrar as próprias equipes.');

    $pdo->prepare('UPDATE groups SET active = FALSE WHERE id = ?')->execute([$groupId]);
    $inactivePage = renderSystemAdminPage($userId, ['tab' => 'configuracoes']);
    systemUiAssert(in_array($inactivePage['status'], [302, 303], true), 'Desativar a equipe não retirou o acesso ao painel.');
    systemUiAssert(!str_contains($inactivePage['html'], 'Configurações do Sistema'), 'Equipe inativa ainda renderizou configurações.');

    echo "OK: rotas e menu respeitam os módulos administrativos delegados.\n";
} finally {
    if ($groupId > 0) {
        $pdo->prepare('DELETE FROM groups WHERE id = ?')->execute([$groupId]);
        $pdo->prepare('DELETE FROM system_capability_audit WHERE group_id = ?')->execute([$groupId]);
    }
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM usage_audit_events WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}
