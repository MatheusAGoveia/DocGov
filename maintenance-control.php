<?php
require_once __DIR__ . '/config/session.php';
session_name('DOCGOV_MAINTENANCE');
docgovStartSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/ActiveDirectoryAuthService.php';
require_once __DIR__ . '/services/PermissionService.php';
require_once __DIR__ . '/services/CsrfService.php';
require_once __DIR__ . '/services/UsageAuditService.php';

$controlConfig = require __DIR__ . '/config/maintenance.php';
$maintenance = $systemSettingsService->maintenanceStatus();
$justClosed = !empty($_SESSION['maintenance_just_closed']);
unset($_SESSION['maintenance_just_closed']);

// Fora de uma manutenção efetivamente ativa, a área de controle não oferece login nem comandos.
if (!$maintenance['active'] && !$justClosed) {
    http_response_code(404);
    ?>
    <!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Controle indisponível</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#171717;color:#e5e7eb;font:14px system-ui}.box{width:min(420px,calc(100% - 40px));text-align:center}.dot{width:10px;height:10px;border-radius:50%;background:#22c55e;margin:0 auto 18px}h1{font-size:20px;margin:0 0 8px}p{color:#9ca3af;line-height:1.6}</style></head><body><main class="box"><div class="dot"></div><h1>Controle indisponível</h1><p>Não existe uma manutenção ativa. Nenhum comando está habilitado nesta página.</p></main></body></html>
    <?php
    exit;
}

$authService = new ActiveDirectoryAuthService($pdo);
$permissionService = new PermissionService($pdo);
$usageAudit = new UsageAuditService($pdo);
$controllerIdentity = (string)$controlConfig['controller_identity'];
$error = '';
$authenticatedAt = (int)($_SESSION['maintenance_authenticated_at'] ?? 0);
$controllerUserId = (int)($_SESSION['maintenance_controller_user_id'] ?? 0);
$controlAuthenticated = $authenticatedAt > 0
    && time() - $authenticatedAt <= (int)$controlConfig['session_ttl_seconds']
    && $controllerUserId > 0
    && $permissionService->isGlobalAdmin($controllerUserId);
if (!$controlAuthenticated) {
    unset($_SESSION['maintenance_authenticated_at'], $_SESSION['maintenance_controller_user_id']);
}

$ipAddress = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? (string)$_SERVER['REMOTE_ADDR'] : null;
$recentFailures = 0;
if ($ipAddress) {
    $failureStmt = $pdo->prepare("SELECT COUNT(*) FROM usage_audit_events WHERE event_type = 'admin_action' AND metadata->>'action' = 'maintenance_control_login_failed' AND ip_address = CAST(:ip AS inet) AND created_at >= CURRENT_TIMESTAMP - INTERVAL '15 minutes'");
    $failureStmt->execute([':ip' => $ipAddress]);
    $recentFailures = (int)$failureStmt->fetchColumn();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'Sessão de segurança expirada. Atualize a página.';
    } elseif (isset($_POST['maintenance_login'])) {
        if ($recentFailures >= (int)$controlConfig['max_attempts_per_15_minutes']) {
            http_response_code(429);
            $error = 'Limite de tentativas atingido. Aguarde 15 minutos.';
        } else {
            $password = (string)($_POST['password'] ?? '');
            $result = $authService->authenticate($controllerIdentity, $password);
            $authenticatedIdentity = $result['success']
                ? strtolower((string)($result['user']['ad_domain'] ?? '') . '\\' . (string)($result['user']['username'] ?? ''))
                : '';
            if ($result['success'] && hash_equals($controllerIdentity, $authenticatedIdentity) && $permissionService->isGlobalAdmin((int)$result['user']['id'])) {
                session_regenerate_id(true);
                $_SESSION['maintenance_authenticated_at'] = time();
                $_SESSION['maintenance_controller_user_id'] = (int)$result['user']['id'];
                $usageAudit->log('admin_action', (int)$result['user']['id'], 'ADMIN', null, ['action' => 'maintenance_control_login']);
                header('Location: maintenance-control.php');
                exit;
            }
            $usageAudit->log('admin_action', null, 'ADMIN', null, ['action' => 'maintenance_control_login_failed']);
            usleep(500000);
            $error = 'Credencial inválida ou usuário não autorizado.';
        }
    } elseif (isset($_POST['stop_maintenance'])) {
        if (!$controlAuthenticated) {
            http_response_code(403);
            $error = 'Autenticação de emergência necessária.';
        } else {
            $systemSettingsService->saveMany(['maintenance_enabled' => false, 'maintenance_progress' => 100], $controllerUserId);
            $usageAudit->log('admin_action', $controllerUserId, 'ADMIN', null, ['action' => 'maintenance_stopped_external_control']);
            unset($_SESSION['maintenance_authenticated_at'], $_SESSION['maintenance_controller_user_id']);
            $_SESSION['maintenance_just_closed'] = true;
            header('Location: maintenance-control.php');
            exit;
        }
    }
}

if ($justClosed):
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Manutenção encerrada</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#171717;color:#f5f5f5;font:14px system-ui}.box{width:min(420px,calc(100% - 40px));text-align:center}.ok{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#14532d;color:#86efac;margin:0 auto 18px;font-size:22px}h1{font-size:22px;margin:0 0 8px}p{color:#a3a3a3;line-height:1.6}</style></head><body><main class="box"><div class="ok">✓</div><h1>Manutenção encerrada</h1><p>O bloqueio foi removido e o sistema voltou à operação normal. Este controle foi desativado automaticamente.</p></main></body></html>
<?php exit; endif; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Controle externo de manutenção</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#171717;color:#f5f5f5;font:14px system-ui}.panel{width:min(400px,calc(100% - 40px));padding:30px;border:1px solid #363636;border-radius:16px;background:#212121;box-shadow:0 24px 70px #0008}.mark{width:42px;height:42px;display:grid;place-items:center;border-radius:11px;background:#2f2f2f;margin-bottom:24px}h1{font-size:21px;margin:0 0 8px}p{color:#a3a3a3;line-height:1.55;margin:0}.identity{margin:22px 0 14px;padding:12px;border-radius:9px;background:#2a2a2a;color:#d4d4d4;font:12px ui-monospace,monospace}label{display:block;margin:18px 0 7px;color:#d4d4d4;font-size:12px;font-weight:650}input{width:100%;padding:12px;border:1px solid #444;border-radius:9px;background:#181818;color:#fff;outline:none}input:focus{border-color:#777}.error{margin-top:15px;padding:11px;border-radius:8px;background:#451a1a;color:#fca5a5;font-size:12px}button{width:100%;margin-top:18px;padding:13px;border:0;border-radius:9px;background:#f5f5f5;color:#171717;font-weight:750;cursor:pointer}.danger{background:#dc2626;color:#fff}.status{display:inline-flex;align-items:center;gap:7px;margin-bottom:22px;color:#fbbf24;font-size:12px}.status:before{content:'';width:8px;height:8px;border-radius:50%;background:#f59e0b;box-shadow:0 0 0 4px #f59e0b22}</style></head>
<body><main class="panel"><div class="mark">⌁</div><div class="status">Manutenção ativa</div><h1>Controle externo</h1>
<?php if (!$controlAuthenticated): ?><p>Área isolada. Apenas a identidade de emergência autorizada pode encerrar a manutenção.</p><div class="identity"><?= htmlspecialchars(strtoupper($controllerIdentity)) ?></div><?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?><form method="POST" autocomplete="off"><input type="hidden" name="maintenance_login" value="1"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CsrfService::token(), ENT_QUOTES, 'UTF-8') ?>"><label for="password">Senha do Active Directory</label><input id="password" name="password" type="password" required autofocus autocomplete="current-password"><button type="submit">Autenticar</button></form>
<?php else: ?><p>Autenticação confirmada. Esta é a única ação disponível nesta página.</p><?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?><form method="POST"><input type="hidden" name="stop_maintenance" value="1"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CsrfService::token(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="danger">Sair da manutenção</button></form><?php endif; ?>
</main></body></html>
