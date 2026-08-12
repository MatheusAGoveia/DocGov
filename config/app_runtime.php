<?php

require_once __DIR__ . '/../services/SystemSettingsService.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/DocumentWorkflowService.php';

$systemSettingsService = new SystemSettingsService($pdo);
$appSettings = $systemSettingsService->all();
$appName = (string)$appSettings['portal_name'];
$organizationName = (string)$appSettings['organization_name'];
$appDescription = (string)$appSettings['portal_description'];
$portalTheme = SystemSettingsService::normalizePortalTheme($_SESSION['user']['portal_theme'] ?? ($appSettings['portal_theme'] ?? 'emerald'));
$appLogoPath = trim((string)($appSettings['system_logo_path'] ?? ''));
$appLogoUrl = $appLogoPath !== '' ? 'app_logo.php?v=' . rawurlencode($appLogoPath) : null;

// Pendências editoriais não podem permanecer indefinidamente disponíveis no painel.
// A execução é idempotente e o índice de expiração mantém a consulta leve.
if (PHP_SAPI !== 'cli') {
    try {
        (new DocumentWorkflowService($pdo, new PermissionService($pdo)))->expireUnapprovedDocuments();
    } catch (Throwable $exception) {
        // Uma migração ainda não aplicada não deve indisponibilizar o portal.
        error_log('DocGov workflow expiration: ' . $exception->getMessage());
    }
}

$configuredTimezone = (string)($appSettings['timezone'] ?? 'America/Sao_Paulo');
if (in_array($configuredTimezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($configuredTimezone);
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $corsEnabled = (bool)($appSettings['cors_enabled'] ?? false);
    $allowedOrigins = is_array($appSettings['cors_allowed_origins'] ?? null) ? $appSettings['cors_allowed_origins'] : [];
    $allowCredentials = (bool)($appSettings['cors_allow_credentials'] ?? false);
    if ($corsEnabled && $origin !== '') {
        $originAllowed = in_array($origin, $allowedOrigins, true) || (!$allowCredentials && in_array('*', $allowedOrigins, true));
        if ($originAllowed) {
            header('Access-Control-Allow-Origin: ' . (in_array('*', $allowedOrigins, true) ? '*' : $origin));
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: ' . implode(', ', $appSettings['cors_allowed_methods'] ?? ['GET', 'POST', 'OPTIONS']));
            header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
            if ($allowCredentials) {
                header('Access-Control-Allow-Credentials: true');
            }
        }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS' && $corsEnabled) {
        http_response_code(204);
        exit;
    }
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user'])) {
    $timeoutSeconds = max(900, min(28800, (int)($appSettings['session_timeout_minutes'] ?? 120) * 60));
    $lastActivity = (int)($_SESSION['last_activity_at'] ?? time());
    if (time() - $lastActivity > $timeoutSeconds) {
        unset($_SESSION['user'], $_SESSION['admin_logged']);
        $_SESSION['session_expired'] = true;
    }
    $_SESSION['last_activity_at'] = time();
}

if (PHP_SAPI !== 'cli') {
    $runtimeMaintenanceStatus = $systemSettingsService->maintenanceStatus();
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptPath = str_replace('\\', '/', strtolower((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $allowedScripts = ['maintenance.php', 'maintenance-control.php', 'login.php', 'app_logo.php'];
    $maintenanceArea = 'portal';
    if (str_contains($scriptPath, '/admin/')) {
        $maintenanceArea = 'admin';
    } elseif (str_contains($scriptPath, '/api/') || str_starts_with($script, 'api_')) {
        $maintenanceArea = 'api';
    } elseif (in_array($script, ['download.php', 'document-file.php', 'category_image.php', 'subcategory_image.php'], true)) {
        $maintenanceArea = 'files';
    }
    $maintenanceScope = is_array($appSettings['maintenance_scope'] ?? null) ? $appSettings['maintenance_scope'] : ['portal', 'admin', 'api', 'files'];
    $maintenanceAffectsRequest = in_array($maintenanceArea, $maintenanceScope, true);
    $maintenanceMode = in_array(($appSettings['maintenance_mode'] ?? 'full'), ['full', 'read_only'], true) ? $appSettings['maintenance_mode'] : 'full';
    $safeRequest = in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD', 'OPTIONS'], true);
    // O Super Admin permanece operacional para acompanhar a intervenção e
    // ajustar a comunicação; os demais seguem a política escolhida.
    $isMaintenanceBypass = false;
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user']['id'])) {
        $stmtRuntimeAdmin = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND active = TRUE AND role = 'admin'");
        $stmtRuntimeAdmin->execute([(int)$_SESSION['user']['id']]);
        $isMaintenanceBypass = (bool)$stmtRuntimeAdmin->fetchColumn();
    }
    $mustBlockForMaintenance = $runtimeMaintenanceStatus['active']
        && $maintenanceAffectsRequest
        && !$isMaintenanceBypass
        && !in_array($script, $allowedScripts, true)
        && ($maintenanceMode === 'full' || !$safeRequest);
    if ($mustBlockForMaintenance) {
        $isJson = $maintenanceArea === 'api'
            || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
        http_response_code(503);
        if (!headers_sent()) {
            header('X-DocGov-Maintenance-Mode: ' . $maintenanceMode);
        }
        if ($runtimeMaintenanceStatus['end'] instanceof DateTimeImmutable && !headers_sent()) {
            header('Retry-After: ' . max(60, $runtimeMaintenanceStatus['end']->getTimestamp() - time()));
        }
        if ($isJson) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false, 'error' => 'maintenance', 'message' => $appSettings['maintenance_message']], JSON_UNESCAPED_UNICODE);
        } else {
            if (!headers_sent()) {
                $scriptDirectory = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
                if (in_array(basename($scriptDirectory), ['admin', 'api'], true)) {
                    $scriptDirectory = str_replace('\\', '/', dirname($scriptDirectory));
                }
                $maintenanceUrl = rtrim($scriptDirectory, '/') . '/maintenance.php';
                header('Location: ' . ($maintenanceUrl ?: '/maintenance.php'));
            }
        }
        exit;
    }
}
