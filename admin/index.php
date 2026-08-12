<?php
// admin/index.php - Painel Administrativo de Gestão de Documentos (Ícones 100% SVG)
require_once __DIR__ . '/../config/session.php';
docgovStartSession();
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';
require_once __DIR__ . '/../services/VideoEmbedService.php';
require_once __DIR__ . '/../services/CategoryImageService.php';
require_once __DIR__ . '/../services/BatchDocumentUploadService.php';
require_once __DIR__ . '/../services/DocumentWorkflowService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/UsageAuditService.php';
require_once __DIR__ . '/../services/CsrfService.php';
require_once __DIR__ . '/../services/HierarchyService.php';
$tagService = new TagService($pdo);
$hierarchyService = new HierarchyService($pdo);
$permService = new PermissionService($pdo);
$adAuthService = new ActiveDirectoryAuthService($pdo);
$categoryImageService = new CategoryImageService(dirname(__DIR__));
$notificationService = new NotificationService($pdo);
$workflowService = new DocumentWorkflowService($pdo, $permService);
$usageAuditService = new UsageAuditService($pdo);
$batchDocumentUploadService = new BatchDocumentUploadService($pdo, $permService, $workflowService, $usageAuditService, $tagService, dirname(__DIR__));
$csrfToken = CsrfService::token();
$adConfig = require __DIR__ . '/../config/active_directory.php';
$availableAdDomains = array_keys($adConfig['domains'] ?? []);
$selectedAdDomain = strtoupper((string)($adConfig['default_domain'] ?? 'BETIM'));

if (!function_exists('docgovDatabaseBoolean')) {
    function docgovDatabaseBoolean(mixed $value): bool {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}

// A autenticação acontece exclusivamente na entrada do portal. Sem sessão,
// não exibimos uma segunda tela de login dentro do painel administrativo.
if (empty($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

// Verifica sessão do usuário logado
$loggedUser = $_SESSION['user'] ?? null;
$accessDenied = false;
$accessErrorReason = '';

if (!$loggedUser || !$permService->canAccessAdminPanel((int)($loggedUser['id'] ?? 0))) {
    unset($_SESSION['admin_logged']);
    header('Location: ../index.php');
    exit;
} else {
    $_SESSION['admin_logged'] = true;
}

$isLogged = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true && !$accessDenied;
$adminAccessLabel = $loggedUser
    ? $permService->getAdminPanelAccessLabel((int)($loggedUser['id'] ?? 0))
    : 'Usuário';

$activeTab = trim($_GET['tab'] ?? 'visao_geral');
// Alias: novo_conteudo é o novo nome do tab de criação
if ($activeTab === 'novo_conteudo') $activeTab = 'novo_documento';
$dashboardPeriodOptions = [7, 14, 30];
$requestedDashboardPeriod = (int)($_GET['dash_period'] ?? 14);
$dashboardPeriodDays = in_array($requestedDashboardPeriod, $dashboardPeriodOptions, true)
    ? $requestedDashboardPeriod
    : 14;
$dashboardPeriodStartOffset = max(0, $dashboardPeriodDays - 1);
$message = '';
$errorMessage = '';
$editDoc = null;
$docDetails = null;
$workflowHistory = [];
$editCat = null;
$editSub = null;
$editAss = null;
$currentAdminUserId = (int)($loggedUser['id'] ?? 0);
$unreadNotificationCount = $isLogged ? $notificationService->unreadCount($currentAdminUserId) : 0;
$isGlobalAdminCurrent = $isLogged && $permService->isGlobalAdmin($currentAdminUserId);
if ($isLogged && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $usageAuditService->log('admin_page_view', $currentAdminUserId, 'ADMIN', null, ['tab' => $activeTab]);
}
$administrativeScope = $isLogged
    ? $permService->getAdministrativeScope($currentAdminUserId)
    : [
        'category_ids' => [],
        'subcategory_ids' => [],
        'subject_ids' => [],
        'coverage_category_ids' => [],
        'coverage_subcategory_ids' => [],
    ];
$administrativeSubjectIds = array_values(array_unique(array_map('intval', $administrativeScope['subject_ids'])));
$administrativeCategoryIds = array_values(array_unique(array_map('intval', $administrativeScope['coverage_category_ids'])));
$administrativeSubcategoryIds = array_values(array_unique(array_map('intval', $administrativeScope['coverage_subcategory_ids'])));
$administrativeDocumentScopeSql = $isGlobalAdminCurrent
    ? 'TRUE'
    : (!empty($administrativeSubjectIds)
        ? 'd.subject_id IN (' . implode(',', $administrativeSubjectIds) . ')'
        : 'FALSE');

$globalOnlyTabs = ['grupos', 'editar_grupo', 'configuracoes', 'tags'];
if ($isLogged && !$isGlobalAdminCurrent && in_array($activeTab, $globalOnlyTabs, true)) {
    http_response_code(403);
    $activeTab = 'visao_geral';
    $errorMessage = 'Esta área contém dados globais e está disponível somente para o Super Admin.';
}

// =============================================================================
// PROCESSAMENTO COMPLETO DE AÇÕES ADMINISTRATIVAS (POSTGRESQL)
// =============================================================================
if ($isLogged) {

    // CONFIGURAÇÕES GLOBAIS (SOMENTE SUPER ADMIN)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_system_settings'])) {
        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
        } elseif (!$isGlobalAdminCurrent) {
            http_response_code(403);
            $errorMessage = 'Somente o Super Admin pode alterar configurações globais.';
        } else {
            try {
                $portalName = trim((string)($_POST['portal_name'] ?? ''));
                $organizationNameInput = trim((string)($_POST['organization_name'] ?? ''));
                $portalDescription = trim((string)($_POST['portal_description'] ?? ''));
                $selectedPortalTheme = SystemSettingsService::normalizePortalTheme($_POST['portal_theme'] ?? 'emerald');
                $systemLogoFile = isset($_FILES['system_logo']) && is_array($_FILES['system_logo'])
                    ? $_FILES['system_logo']
                    : null;
                $removeSystemLogo = isset($_POST['remove_system_logo']);
                $supportEmail = strtolower(trim((string)($_POST['support_email'] ?? '')));
                $timezoneInput = trim((string)($_POST['timezone'] ?? 'America/Sao_Paulo'));
                $sessionTimeout = (int)($_POST['session_timeout_minutes'] ?? 120);
                $corsEnabled = isset($_POST['cors_enabled']);
                $corsCredentials = isset($_POST['cors_allow_credentials']);
                $origins = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R+/', (string)($_POST['cors_allowed_origins'] ?? '')) ?: []))));
                $allowedMethodOptions = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
                $corsMethods = array_values(array_intersect($allowedMethodOptions, array_map('strtoupper', (array)($_POST['cors_allowed_methods'] ?? []))));
                $maintenanceEnabled = isset($_POST['maintenance_enabled']);
                $maintenanceMode = strtolower(trim((string)($_POST['maintenance_mode'] ?? 'full')));
                $maintenanceScope = array_values(array_intersect(['portal', 'admin', 'api', 'files'], (array)($_POST['maintenance_scope'] ?? [])));
                $maintenanceReason = trim((string)($_POST['maintenance_reason'] ?? ''));
                $maintenanceReference = trim((string)($_POST['maintenance_reference'] ?? ''));
                $maintenanceResponsible = trim((string)($_POST['maintenance_responsible'] ?? ''));
                $maintenanceProgress = (int)($_POST['maintenance_progress'] ?? 0);
                $maintenanceAnnounceMinutes = (int)($_POST['maintenance_announce_minutes'] ?? 60);
                $maintenanceRefreshSeconds = (int)($_POST['maintenance_auto_refresh_seconds'] ?? 30);
                $maintenanceTitle = trim((string)($_POST['maintenance_title'] ?? ''));
                $maintenanceMessage = trim((string)($_POST['maintenance_message'] ?? ''));

                if (mb_strlen($portalName) < 2 || mb_strlen($portalName) > 60) {
                    throw new InvalidArgumentException('O nome do portal deve ter entre 2 e 60 caracteres.');
                }
                if (mb_strlen($organizationNameInput) < 2 || mb_strlen($organizationNameInput) > 100) {
                    throw new InvalidArgumentException('O nome da organização deve ter entre 2 e 100 caracteres.');
                }
                if (mb_strlen($portalDescription) < 2 || mb_strlen($portalDescription) > 160) {
                    throw new InvalidArgumentException('A descrição deve ter entre 2 e 160 caracteres.');
                }
                if ($selectedPortalTheme !== strtolower(trim((string)($_POST['portal_theme'] ?? 'emerald')))) {
                    throw new InvalidArgumentException('Tema padrão inválido.');
                }
                if (($systemLogoError = $categoryImageService->validate($systemLogoFile, 'logo do sistema')) !== null) {
                    throw new InvalidArgumentException($systemLogoError);
                }
                if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Informe um e-mail de suporte válido.');
                }
                if (!in_array($timezoneInput, ['America/Sao_Paulo', 'UTC'], true)) {
                    throw new InvalidArgumentException('Fuso horário inválido.');
                }
                if ($sessionTimeout < 15 || $sessionTimeout > 480) {
                    throw new InvalidArgumentException('A expiração da sessão deve ficar entre 15 e 480 minutos.');
                }
                if ($corsEnabled && empty($origins)) {
                    throw new InvalidArgumentException('Informe ao menos uma origem quando o CORS estiver habilitado.');
                }
                foreach ($origins as $origin) {
                    if ($origin !== '*' && !preg_match('#^https?://[a-z0-9.-]+(?::\d{1,5})?$#i', $origin)) {
                        throw new InvalidArgumentException("Origem CORS inválida: {$origin}");
                    }
                }
                if ($corsCredentials && in_array('*', $origins, true)) {
                    throw new InvalidArgumentException('CORS com credenciais não pode usar origem curinga (*).');
                }
                if ($corsEnabled && empty($corsMethods)) {
                    throw new InvalidArgumentException('Selecione ao menos um método permitido pelo CORS.');
                }
                if (mb_strlen($maintenanceTitle) < 3 || mb_strlen($maintenanceTitle) > 100 || mb_strlen($maintenanceMessage) < 5 || mb_strlen($maintenanceMessage) > 500) {
                    throw new InvalidArgumentException('Revise o título e a mensagem de manutenção.');
                }
                if (!in_array($maintenanceMode, ['full', 'read_only'], true)) {
                    throw new InvalidArgumentException('Modo de manutenção inválido.');
                }
                if ($maintenanceEnabled && empty($maintenanceScope)) {
                    throw new InvalidArgumentException('Selecione ao menos uma área afetada pela manutenção.');
                }
                if (mb_strlen($maintenanceReason) < 3 || mb_strlen($maintenanceReason) > 160) {
                    throw new InvalidArgumentException('O motivo deve ter entre 3 e 160 caracteres.');
                }
                if (mb_strlen($maintenanceReference) > 80 || mb_strlen($maintenanceResponsible) > 100) {
                    throw new InvalidArgumentException('Referência ou responsável excedeu o tamanho permitido.');
                }
                if ($maintenanceProgress < 0 || $maintenanceProgress > 100) {
                    throw new InvalidArgumentException('O progresso deve ficar entre 0% e 100%.');
                }
                if (!in_array($maintenanceAnnounceMinutes, [0, 15, 30, 60, 120, 240, 1440], true)) {
                    throw new InvalidArgumentException('Antecedência do aviso inválida.');
                }
                if (!in_array($maintenanceRefreshSeconds, [0, 15, 30, 60, 120], true)) {
                    throw new InvalidArgumentException('Intervalo de atualização inválido.');
                }

                $inputTimezone = new DateTimeZone($timezoneInput);
                $toUtc = static function (string $value) use ($inputTimezone): ?string {
                    $value = trim($value);
                    if ($value === '') {
                        return null;
                    }
                    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $inputTimezone);
                    if (!$date || DateTimeImmutable::getLastErrors() && DateTimeImmutable::getLastErrors()['warning_count'] > 0) {
                        throw new InvalidArgumentException('Data ou hora de manutenção inválida.');
                    }
                    return $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
                };
                $maintenanceStart = $toUtc((string)($_POST['maintenance_start_at'] ?? ''));
                $maintenanceEnd = $toUtc((string)($_POST['maintenance_end_at'] ?? ''));
                if ($maintenanceEnabled && (!$maintenanceStart || !$maintenanceEnd)) {
                    throw new InvalidArgumentException('Defina o início e o fim da janela de manutenção.');
                }
                if ($maintenanceStart && $maintenanceEnd && new DateTimeImmutable($maintenanceEnd) <= new DateTimeImmutable($maintenanceStart)) {
                    throw new InvalidArgumentException('O fim da manutenção deve ser posterior ao início.');
                }
                if ($maintenanceEnabled && $maintenanceEnd && new DateTimeImmutable($maintenanceEnd) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                    throw new InvalidArgumentException('O término da manutenção precisa estar no futuro.');
                }

                $currentLogoPath = trim((string)$systemSettingsService->get('system_logo_path', ''));
                $newLogoPath = null;
                $hasNewLogo = $systemLogoFile !== null
                    && (int)($systemLogoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                try {
                    if ($hasNewLogo) {
                        $newLogoPath = $categoryImageService->storeFor($systemLogoFile, 1, 'system_logo');
                    }

                    $systemSettingsService->saveMany([
                    'portal_name' => $portalName,
                    'organization_name' => $organizationNameInput,
                    'portal_description' => $portalDescription,
                    'portal_theme' => $selectedPortalTheme,
                    'system_logo_path' => $newLogoPath ?? ($removeSystemLogo ? '' : $currentLogoPath),
                    'support_email' => $supportEmail,
                    'timezone' => $timezoneInput,
                    'session_timeout_minutes' => $sessionTimeout,
                    'cors_enabled' => $corsEnabled,
                    'cors_allowed_origins' => $origins,
                    'cors_allowed_methods' => $corsMethods ?: ['GET', 'POST', 'OPTIONS'],
                    'cors_allow_credentials' => $corsCredentials,
                    'maintenance_enabled' => $maintenanceEnabled,
                    'maintenance_mode' => $maintenanceMode,
                    'maintenance_scope' => $maintenanceScope,
                    'maintenance_start_at' => $maintenanceStart,
                    'maintenance_end_at' => $maintenanceEnd,
                    'maintenance_reason' => $maintenanceReason,
                    'maintenance_reference' => $maintenanceReference,
                    'maintenance_responsible' => $maintenanceResponsible,
                    'maintenance_progress' => $maintenanceProgress,
                    'maintenance_announce_minutes' => $maintenanceAnnounceMinutes,
                    'maintenance_auto_refresh_seconds' => $maintenanceRefreshSeconds,
                    'maintenance_title' => $maintenanceTitle,
                    'maintenance_message' => $maintenanceMessage,
                    ], $currentAdminUserId);
                } catch (Throwable $exception) {
                    if ($newLogoPath !== null) {
                        $categoryImageService->remove($newLogoPath);
                    }
                    throw $exception;
                }
                if (($newLogoPath !== null || $removeSystemLogo) && $currentLogoPath !== '') {
                    $categoryImageService->remove($currentLogoPath);
                }
                $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, [
                    'action' => 'system_settings_updated',
                    'maintenance_enabled' => $maintenanceEnabled,
                    'cors_enabled' => $corsEnabled,
                    'system_logo_updated' => $newLogoPath !== null || $removeSystemLogo,
                    'portal_theme' => $selectedPortalTheme,
                ]);
                header('Location: index.php?tab=configuracoes&msg=settings_saved');
                exit;
            } catch (Throwable $exception) {
                $errorMessage = $exception->getMessage();
            }
        }
    }

    // 0.0 CURADORIA DO CATÁLOGO DE TAGS (SOMENTE SUPER ADMIN)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['tag_admin_action'])) {
        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
        } elseif (!$isGlobalAdminCurrent) {
            http_response_code(403);
            $errorMessage = 'Somente o Super Admin pode organizar o catálogo de tags.';
        } else {
            try {
                $tagAction = (string)$_POST['tag_admin_action'];
                $tagId = (int)($_POST['tag_id'] ?? 0);
                if ($tagAction === 'create') {
                    $tagService->create((string)($_POST['tag_name'] ?? ''), (string)($_POST['tag_type'] ?? 'topic'), $currentAdminUserId);
                } elseif ($tagAction === 'update') {
                    $tagService->update($tagId, (string)($_POST['tag_name'] ?? ''), (string)($_POST['tag_type'] ?? 'topic'));
                } elseif ($tagAction === 'toggle') {
                    $tagService->setActive($tagId, (string)($_POST['active'] ?? '') === '1');
                } elseif ($tagAction === 'add_alias') {
                    $tagService->addAlias($tagId, (string)($_POST['tag_alias'] ?? ''));
                } else {
                    throw new InvalidArgumentException('Ação de tag inválida.');
                }
                $usageAuditService->logAdminAction($currentAdminUserId, 'tag_catalog_' . $tagAction, 'TAG', $tagId ?: null);
                header('Location: index.php?tab=tags&msg=tag_catalog_saved');
                exit;
            } catch (Throwable $exception) {
                $errorMessage = $exception->getMessage();
            }
        }
    }

    // 0. CADASTRO DE USUÁRIOS (SOMENTE SUPER ADMIN)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['create_user'])) {
        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
        } elseif (!$isGlobalAdminCurrent) {
            http_response_code(403);
            $errorMessage = 'Somente o Super Admin pode cadastrar usuários.';
        } else {
            $adUserLogin = trim($_POST['ad_username'] ?? '');
            $importAdDomain = strtoupper(trim((string)($_POST['ad_domain'] ?? $selectedAdDomain)));

            if (!in_array($importAdDomain, $availableAdDomains, true)) {
                $errorMessage = 'Domínio corporativo inválido.';
            } elseif ($adUserLogin === '') {
                $errorMessage = 'Informe o usuário corporativo que já existe no Active Directory.';
            } else {
                $importResult = $adAuthService->importExistingDirectoryUser($importAdDomain . '\\' . $adUserLogin);
                if ($importResult['success']) {
                    $importedUser = $importResult['user'];
                    $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, [
                        'action' => 'user_imported_from_ad',
                        'target_name' => (string)$importedUser['name'],
                        'target_username' => (string)$importedUser['username'],
                    ]);
                    header('Location: index.php?tab=usuarios&msg=user_imported');
                    exit;
                } else {
                    $errorMessage = (string)$importResult['message'];
                }
            }
        }
    }

    // 0.1. ACESSO INDIVIDUAL DO USUÁRIO (SOMENTE SUPER ADMIN)
    // A regra é gravada diretamente para a pessoa escolhida, sem depender de equipe.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_direct_user_permission'])) {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $resourceSelection = trim((string)($_POST['direct_resource'] ?? ''));
        $permissionLevel = strtolower(trim((string)($_POST['permission_level'] ?? '')));
        [$resourceType, $resourceIdRaw] = array_pad(explode(':', $resourceSelection, 2), 2, '');
        $resourceType = strtolower(trim($resourceType));
        $resourceId = (int)$resourceIdRaw;

        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
        } elseif (!$isGlobalAdminCurrent) {
            http_response_code(403);
            $errorMessage = 'Somente o Super Admin pode conceder acesso individual pela tela de usuários.';
        } elseif ($targetUserId <= 0 || !in_array($resourceType, ['category', 'subcategory', 'subject'], true) || $resourceId <= 0) {
            $errorMessage = 'Selecione um usuário e uma área válida para liberar o acesso.';
        } elseif (!in_array($permissionLevel, ['view', 'edit', 'admin'], true)) {
            $errorMessage = 'Selecione um nível de acesso válido.';
        } else {
            $targetUserStmt = $pdo->prepare('SELECT id, active, role FROM users WHERE id = :id');
            $targetUserStmt->execute([':id' => $targetUserId]);
            $targetUser = $targetUserStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $errorMessage = 'Usuário não encontrado.';
            } elseif (!filter_var($targetUser['active'], FILTER_VALIDATE_BOOLEAN)) {
                $errorMessage = 'Não é possível liberar acesso para um usuário inativo.';
            } elseif (strtolower((string)$targetUser['role']) === 'admin') {
                $errorMessage = 'Este usuário já é Administrador Global e possui acesso completo.';
            } else {
                try {
                    $permService->saveResourcePermission(
                        $resourceType,
                        $resourceId,
                        $targetUserId,
                        null,
                        $permissionLevel,
                        $currentAdminUserId
                    );
                    $usageAuditService->logAdminAction(
                        $currentAdminUserId,
                        'direct_user_access_granted',
                        strtoupper($resourceType),
                        $resourceId
                    );
                    header('Location: index.php?tab=editar_usuario&id=' . $targetUserId . '&user_tab=access&msg=direct_user_access_saved');
                    exit;
                } catch (Throwable $exception) {
                    error_log('DocGov: falha ao conceder acesso individual: ' . $exception->getMessage());
                    $errorMessage = 'Não foi possível salvar o acesso individual. Verifique a área selecionada e tente novamente.';
                }
            }
        }
    }

    // 0.2. REMOÇÃO DE UMA REGRA INDIVIDUAL DE USUÁRIO (SOMENTE SUPER ADMIN)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['remove_direct_user_permission'])) {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $permissionId = (int)($_POST['permission_id'] ?? 0);

        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
        } elseif (!$isGlobalAdminCurrent) {
            http_response_code(403);
            $errorMessage = 'Somente o Super Admin pode remover acessos individuais pela tela de usuários.';
        } elseif ($targetUserId <= 0 || $permissionId <= 0) {
            $errorMessage = 'Regra de acesso inválida.';
        } else {
            $ruleStmt = $pdo->prepare('SELECT id FROM permissions WHERE id = :id AND user_id = :user_id');
            $ruleStmt->execute([':id' => $permissionId, ':user_id' => $targetUserId]);

            if (!$ruleStmt->fetchColumn()) {
                $errorMessage = 'A regra individual não foi encontrada para este usuário.';
            } else {
                try {
                    if (!$permService->deletePermission($permissionId, $currentAdminUserId)) {
                        throw new RuntimeException('Regra não encontrada.');
                    }
                    $usageAuditService->logAdminAction($currentAdminUserId, 'direct_user_access_removed', 'ADMIN', null);
                    header('Location: index.php?tab=editar_usuario&id=' . $targetUserId . '&user_tab=access&msg=direct_user_access_removed');
                    exit;
                } catch (Throwable $exception) {
                    error_log('DocGov: falha ao remover acesso individual: ' . $exception->getMessage());
                    $errorMessage = 'Não foi possível remover o acesso individual.';
                }
            }
        }
    }

    // 0. PROCESSAMENTO DE AÇÕES DE GRUPOS DE ACESSO (APENAS ADMIN)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['group_action'])) {
        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
        } elseif (!$isGlobalAdminCurrent) {
            http_response_code(403);
            $errorMessage = "Apenas administradores podem gerenciar equipes.";
        } else {
            $grpAction = strtolower(trim((string)$_POST['group_action']));
            $allowedGroupActions = ['create_group', 'edit_group', 'toggle_status', 'delete_group', 'add_user', 'remove_user'];

            if (!in_array($grpAction, $allowedGroupActions, true)) {
                http_response_code(400);
                $errorMessage = 'Ação de equipe inválida.';
            } elseif ($grpAction === 'create_group') {
                $gName = trim((string)($_POST['name'] ?? ''));
                $gDesc = trim((string)($_POST['description'] ?? ''));
                $gActive = isset($_POST['active']) && in_array((string)$_POST['active'], ['1', 'true', 'on'], true);

                if ($gName === '' || mb_strlen($gName) > 255) {
                    $errorMessage = 'Informe um nome de equipe com até 255 caracteres.';
                } else {
                    try {
                        $stmtInsG = $pdo->prepare('INSERT INTO groups (name, description, active) VALUES (:name, :description, :active) RETURNING id');
                        $stmtInsG->execute([':name' => $gName, ':description' => $gDesc, ':active' => $gActive ? 'true' : 'false']);
                        $gId = (int)$stmtInsG->fetchColumn();
                        $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, ['action' => 'team_created', 'team_id' => $gId, 'team_name' => $gName]);
                        header('Location: index.php?tab=grupos&msg=team_created');
                        exit;
                    } catch (PDOException $exception) {
                        $errorMessage = $exception->getCode() === '23505'
                            ? 'Já existe uma equipe com esse nome.'
                            : 'Não foi possível criar a equipe.';
                    }
                }
            } elseif ($grpAction === 'edit_group') {
                $gId = (int)($_POST['group_id'] ?? 0);
                $gName = trim((string)($_POST['name'] ?? ''));
                $gDesc = trim((string)($_POST['description'] ?? ''));
                $gActive = isset($_POST['active']) && in_array((string)$_POST['active'], ['1', 'true', 'on'], true);

                if ($gId <= 0 || $gName === '' || mb_strlen($gName) > 255) {
                    $errorMessage = 'Equipe ou nome inválido.';
                } else {
                    try {
                        $stmtUpdG = $pdo->prepare('UPDATE groups SET name = :name, description = :description, active = :active WHERE id = :id RETURNING id');
                        $stmtUpdG->execute([':name' => $gName, ':description' => $gDesc, ':active' => $gActive ? 'true' : 'false', ':id' => $gId]);
                        if (!$stmtUpdG->fetchColumn()) {
                            throw new RuntimeException('Equipe não encontrada.');
                        }
                        $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, ['action' => 'team_updated', 'team_id' => $gId, 'team_name' => $gName, 'active' => $gActive]);
                        header('Location: index.php?tab=editar_grupo&id=' . $gId . '&group_tab=info&msg=team_updated');
                        exit;
                    } catch (PDOException $exception) {
                        $errorMessage = $exception->getCode() === '23505'
                            ? 'Já existe outra equipe com esse nome.'
                            : 'Não foi possível atualizar a equipe.';
                    } catch (Throwable $exception) {
                        $errorMessage = $exception->getMessage();
                    }
                }
            } elseif ($grpAction === 'toggle_status') {
                $gId = (int)($_POST['group_id'] ?? 0);
                $stmtTgl = $pdo->prepare('UPDATE groups SET active = NOT active WHERE id = :id RETURNING name, active');
                $stmtTgl->execute([':id' => $gId]);
                $updatedGroup = $stmtTgl->fetch(PDO::FETCH_ASSOC);
                if (!$updatedGroup) {
                    $errorMessage = 'Equipe não encontrada.';
                } else {
                    $groupActive = filter_var($updatedGroup['active'], FILTER_VALIDATE_BOOLEAN);
                    $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, ['action' => $groupActive ? 'team_activated' : 'team_deactivated', 'team_id' => $gId, 'team_name' => $updatedGroup['name']]);
                    header('Location: index.php?tab=grupos&msg=team_status_updated');
                    exit;
                }
            } elseif ($grpAction === 'delete_group') {
                $gId = (int)($_POST['group_id'] ?? 0);
                try {
                    $pdo->beginTransaction();
                    $groupStmt = $pdo->prepare('SELECT name FROM groups WHERE id = :id FOR UPDATE');
                    $groupStmt->execute([':id' => $gId]);
                    $groupName = $groupStmt->fetchColumn();
                    if ($groupName === false) {
                        throw new RuntimeException('Equipe não encontrada.');
                    }

                    $permissionStmt = $pdo->prepare('SELECT id FROM permissions WHERE group_id = :group_id ORDER BY id');
                    $permissionStmt->execute([':group_id' => $gId]);
                    foreach (array_map('intval', $permissionStmt->fetchAll(PDO::FETCH_COLUMN)) as $groupPermissionId) {
                        if (!$permService->deletePermission($groupPermissionId, $currentAdminUserId)) {
                            throw new RuntimeException('Falha ao auditar a remoção de uma permissão da equipe.');
                        }
                    }

                    $deleteGroupStmt = $pdo->prepare('DELETE FROM groups WHERE id = :id');
                    $deleteGroupStmt->execute([':id' => $gId]);
                    if ($deleteGroupStmt->rowCount() !== 1) {
                        throw new RuntimeException('Equipe não encontrada.');
                    }
                    $pdo->commit();
                    $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, ['action' => 'team_deleted', 'team_id' => $gId, 'team_name' => (string)$groupName]);
                    header('Location: index.php?tab=grupos&msg=team_deleted');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errorMessage = $exception->getMessage();
                }
            } elseif ($grpAction === 'add_user') {
                $gId = (int)($_POST['group_id'] ?? 0);
                $uId = (int)($_POST['user_id'] ?? 0);
                $groupStmt = $pdo->prepare('SELECT name, active FROM groups WHERE id = :id');
                $groupStmt->execute([':id' => $gId]);
                $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
                $userStmt = $pdo->prepare('SELECT name FROM users WHERE id = :id AND active = TRUE');
                $userStmt->execute([':id' => $uId]);
                $userName = $userStmt->fetchColumn();

                if (!$group || $userName === false) {
                    $errorMessage = 'Selecione uma equipe existente e um usuário ativo.';
                } else {
                    $stmtAddU = $pdo->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id) ON CONFLICT DO NOTHING');
                    $stmtAddU->execute([':user_id' => $uId, ':group_id' => $gId]);
                    if ($stmtAddU->rowCount() !== 1) {
                        $errorMessage = 'Este usuário já pertence a esta equipe.';
                    } else {
                        try {
                            $teamIsActive = filter_var($group['active'], FILTER_VALIDATE_BOOLEAN);
                            $notificationService->create(
                                $uId,
                                'team_membership_added',
                                'Você foi adicionado a uma equipe',
                                'Você agora faz parte da equipe “' . $group['name'] . '”. ' . ($teamIsActive ? 'Os acessos associados já estão disponíveis.' : 'A equipe está inativa e ainda não concede acesso.')
                            );
                        } catch (Throwable $exception) {
                            error_log('DocGov: falha ao notificar novo membro de equipe: ' . $exception->getMessage());
                        }
                        $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, ['action' => 'team_member_added', 'team_id' => $gId, 'target_user_id' => $uId]);
                        header('Location: index.php?tab=editar_grupo&id=' . $gId . '&group_tab=users&msg=team_member_added');
                        exit;
                    }
                }
            } elseif ($grpAction === 'remove_user') {
                $gId = (int)($_POST['group_id'] ?? 0);
                $uId = (int)($_POST['user_id'] ?? 0);
                $groupStmt = $pdo->prepare('SELECT name FROM groups WHERE id = :id');
                $groupStmt->execute([':id' => $gId]);
                $groupName = $groupStmt->fetchColumn();
                $stmtRemU = $pdo->prepare('DELETE FROM user_groups WHERE user_id = :user_id AND group_id = :group_id');
                $stmtRemU->execute([':user_id' => $uId, ':group_id' => $gId]);

                if ($groupName === false || $stmtRemU->rowCount() !== 1) {
                    $errorMessage = 'O vínculo informado não existe.';
                } else {
                    try {
                        $notificationService->create($uId, 'team_membership_removed', 'Acesso por equipe removido', 'Você não faz mais parte da equipe “' . $groupName . '”.');
                    } catch (Throwable $exception) {
                        error_log('DocGov: falha ao notificar remoção de membro: ' . $exception->getMessage());
                    }
                    $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, ['action' => 'team_member_removed', 'team_id' => $gId, 'target_user_id' => $uId]);
                    header('Location: index.php?tab=editar_grupo&id=' . $gId . '&group_tab=users&msg=team_member_removed');
                    exit;
                }
            }
        }
    }

    // 1. ENVIO EM LOTE DE ARQUIVOS (assíncrono, com uma entrada por arquivo)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_doc']) && ($_POST['batch_upload'] ?? '') === '1') {
        $batchResponse = static function (int $status, array $payload): never {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        };

        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            $batchResponse(419, ['success' => false, 'error' => 'A sessão de segurança expirou. Atualize a página e tente novamente.']);
        }
        if (!empty($_POST['id'])) {
            $batchResponse(422, ['success' => false, 'error' => 'O envio em lote está disponível apenas para novos documentos.']);
        }

        $assuntoInput = trim((string)($_POST['assunto'] ?? ''));
        $subcategoriaInput = trim((string)($_POST['subcategoria'] ?? ''));
        $categoriaInput = trim((string)($_POST['categoria'] ?? ''));
        $workflowAction = trim((string)($_POST['workflow_action'] ?? $_POST['publication_action'] ?? 'save_draft'));
        if ($workflowAction === '') {
            $workflowAction = 'save_draft';
        }

        try {
            $subjectContext = $hierarchyService->resolveActiveSubject($assuntoInput, $subcategoriaInput, $categoriaInput);
            $subjectId = (int)($subjectContext['id'] ?? 0);
            if ($subjectId <= 0) {
                throw new InvalidArgumentException('Selecione uma categoria, uma subcategoria e um assunto ativos.');
            }
            $createdDocuments = $batchDocumentUploadService->create(
                (int)($loggedUser['id'] ?? 0),
                $subjectId,
                trim((string)($_POST['descricao'] ?? '')),
                $workflowAction,
                trim((string)($_POST['workflow_note'] ?? '')),
                is_array($_FILES['arquivo_file'] ?? null) ? $_FILES['arquivo_file'] : [],
                array_values(array_map('strval', (array)($_POST['batch_titles'] ?? []))),
                array_values(array_map('intval', (array)($_POST['tag_ids'] ?? []))),
                array_values(array_map('strval', (array)($_POST['new_tags'] ?? []))),
            );
            $batchResponse(201, [
                'success' => true,
                'created_count' => count($createdDocuments),
                'documents' => $createdDocuments,
                'redirect' => 'index.php?tab=documentos&msg=batch_docs_created&count=' . count($createdDocuments),
            ]);
        } catch (Throwable $exception) {
            $batchResponse(422, ['success' => false, 'error' => $exception->getMessage()]);
        }
    }

    // 1.1 SALVAR / EDITAR DOCUMENTO E LAYOUT VISUAL
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_doc'])) {
        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
        }
        $titulo = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $catInput = trim($_POST['categoria'] ?? '');
        $subInput = trim($_POST['subcategoria'] ?? '');
        $assuntoInput = trim($_POST['assunto'] ?? '');
        $publicationAction = trim($_POST['publication_action'] ?? '');
        $workflowAction = trim($_POST['workflow_action'] ?? '');
        if ($workflowAction === '') {
            $workflowAction = $publicationAction === 'publish' ? 'submit_review' : 'save_draft';
        }
        $workflowNote = trim($_POST['workflow_note'] ?? '');
        $tipoConteudo = trim($_POST['tipo_conteudo'] ?? 'file');
        $conteudoHtmlRaw = trim($_POST['conteudo_html'] ?? '');
        $codigoFonteRaw = str_replace(["\r\n", "\r"], "\n", (string)($_POST['codigo_fonte'] ?? ''));
        $linguagemCodigo = strtolower(trim($_POST['linguagem_codigo'] ?? 'auto'));
        $linkExterno = trim($_POST['link_externo'] ?? '');
        $videoSource = trim($_POST['video_source'] ?? 'upload');
        $videoUrl = trim($_POST['video_url'] ?? '');
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        $requestedTagIds = array_values(array_map('intval', (array)($_POST['tag_ids'] ?? [])));
        $requestedNewTagNames = array_values(array_map('strval', (array)($_POST['new_tags'] ?? [])));
        $previousDocumentStatus = null;

        if ($id && $id > 0) {
            $stmtCurrentStatus = $pdo->prepare('SELECT status FROM documents WHERE id = :id');
            $stmtCurrentStatus->execute([':id' => $id]);
            $previousDocumentStatus = $stmtCurrentStatus->fetchColumn();
            if ($previousDocumentStatus === false) {
                $errorMessage = 'Documento não encontrado.';
            }
        }

        if (!in_array($tipoConteudo, ['file', 'text', 'link', 'code', 'video'], true)) {
            $tipoConteudo = 'file';
        }
        if (!in_array($videoSource, ['upload', 'url'], true)) {
            $videoSource = 'upload';
        }
        if ($tipoConteudo === 'video' && $videoSource === 'url') {
            $linkExterno = $videoUrl;
        }

        $linguagensPermitidas = [
            'auto', 'plaintext', 'javascript', 'typescript', 'xml', 'css', 'php', 'python',
            'sql', 'bash', 'json', 'java', 'csharp', 'cpp', 'go', 'yaml', 'markdown'
        ];
        if (!in_array($linguagemCodigo, $linguagensPermitidas, true)) {
            $linguagemCodigo = 'auto';
        }

        $conteudoHtml = strip_tags($conteudoHtmlRaw, '<h3><h4><p><b><i><strong><em><ul><ol><li><a><br>');
        $conteudoArmazenado = $tipoConteudo === 'code' ? $codigoFonteRaw : $conteudoHtml;

        // Resolver o ramo completo. IDs são usados pelo formulário; nomes/slugs antigos
        // continuam aceitos somente quando identificam um único ramo ativo.
        $subjectId = 0;
        try {
            $subjectContext = $hierarchyService->resolveActiveSubject($assuntoInput, $subInput, $catInput);
            $subjectId = (int)($subjectContext['id'] ?? 0);
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }

        // VALIDAÇÃO DE AUTORIZAÇÃO NO BACKEND (canCreateDocument / canEditDocument)
        $editorUserId = (int)($loggedUser['id'] ?? 0);

        if (empty($errorMessage) && (!$id || $id <= 0)) {
            if ($subjectId <= 0 || !$permService->canCreateDocument($editorUserId, $subjectId)) {
                if ($subjectId <= 0) {
                    $errorMessage = 'Selecione uma categoria, uma subcategoria e um assunto ativos.';
                } else {
                    http_response_code(403);
                    $errorMessage = "Acesso Negado: Você não possui permissão para criar documentos neste Assunto.";
                }
            }
        } elseif (empty($errorMessage) && $id && $id > 0) {
            if (!$permService->canEditDocument($editorUserId, $id)) {
                http_response_code(403);
                $errorMessage = "Acesso Negado: Você não possui permissão para editar este documento.";
            } elseif ($subjectId > 0 && !$permService->canCreateDocument($editorUserId, $subjectId)) {
                http_response_code(403);
                $errorMessage = "Acesso Negado: Você não possui permissão no Assunto de destino especificado.";
            }
        }

        $workflowTransition = null;
        if (empty($errorMessage)) {
            try {
                $workflowTransition = $workflowService->prepareAction(
                    $workflowAction,
                    $previousDocumentStatus,
                    $editorUserId,
                    $id,
                    $workflowNote
                );
                $status = $workflowTransition['status'];
            } catch (Throwable $exception) {
                $errorMessage = $exception->getMessage();
            }
        }

        if (!empty($errorMessage)) {
            // Mantém $errorMessage e ignora o salvamento
        } elseif (empty($titulo) || $subjectId <= 0) {
            $errorMessage = "Por favor, preencha o Título e selecione um Assunto válido.";
        } elseif ($tipoConteudo === 'link' && (empty($linkExterno) || !filter_var($linkExterno, FILTER_VALIDATE_URL))) {
            $errorMessage = "Por favor, informe uma URL válida para o link externo.";
        } elseif ($tipoConteudo === 'video' && $videoSource === 'url' && VideoEmbedService::normalizeExternalUrl($linkExterno) === null) {
            $errorMessage = "Por favor, informe uma URL HTTP ou HTTPS válida para o vídeo.";
        } elseif ($tipoConteudo === 'video' && $videoSource === 'upload' && (!$id || $id <= 0) && (!isset($_FILES['video_file']) || ($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
            $errorMessage = "Selecione um arquivo de vídeo para publicar.";
        } elseif ($tipoConteudo === 'code' && trim($codigoFonteRaw) === '') {
            $errorMessage = "Por favor, informe o trecho de código.";
        } elseif ($tipoConteudo === 'code' && strlen($codigoFonteRaw) > 1048576) {
            $errorMessage = "O trecho de código excede o limite de 1 MB.";
        } else {
            try {
                // Tags existentes são validadas antes de qualquer upload. As novas são criadas apenas no salvamento.
                $requestedTagIds = $tagService->assertActiveIds($requestedTagIds);
                $requestedNewTagNames = $tagService->assertNewNames($requestedNewTagNames, count($requestedTagIds));
            } catch (Throwable $exception) {
                $errorMessage = $exception->getMessage();
            }
        }

        if (empty($errorMessage) && !empty($titulo) && $subjectId > 0) {
            $storedFilename = null;
            $originalFilename = null;
            $tipoMime = null;
            $tamanhoBytes = 0;
            $fileExt = null;
            $filePath = null;

            $uploadField = $tipoConteudo === 'video' ? 'video_file' : 'arquivo_file';
            if (in_array($tipoConteudo, ['file', 'video'], true) && isset($_FILES[$uploadField]) && $_FILES[$uploadField]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES[$uploadField];
                $originalFilename = basename($file['name']);
                $tamanhoBytes = (int)$file['size'];
                $fileExt = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

                $extsPermitidas = [
                    'pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'avif',
                    'txt', 'log', 'csv', 'md', 'json', 'xml', 'doc', 'docx',
                    'mp3', 'wav', 'ogg', 'mp4', 'webm', 'ogv', 'm4v', 'mov'
                ];
                if (!in_array($fileExt, $extsPermitidas)) {
                    $errorMessage = "Formato não suportado. Utilize PDF, imagens, textos, DOC/DOCX, áudio ou vídeo.";
                } elseif ($tamanhoBytes > ($tipoConteudo === 'video' ? 250 : 25) * 1024 * 1024) {
                    $errorMessage = $tipoConteudo === 'video'
                        ? "O vídeo excede o limite máximo permitido de 250MB."
                        : "O arquivo excede o limite máximo permitido de 25MB.";
                } else {
                    $storedFilename = sprintf('%s_%s.%s', uniqid('doc_'), md5($originalFilename . microtime()), $fileExt);
                    $targetDir = __DIR__ . '/../storage/documents';
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }
                    $targetPath = $targetDir . '/' . $storedFilename;

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $filePath = 'storage/documents/' . $storedFilename;
                        $tipoMime = mime_content_type($targetPath) ?: 'application/octet-stream';
                    } else {
                        $errorMessage = "Falha ao salvar o arquivo no servidor.";
                    }
                }
            }

            if (empty($errorMessage)) {
                $slug = slugify($titulo);
                $publishedAt = $status === 'published' ? date(DATE_ATOM) : null;
                if ($id) {
                    if ($storedFilename) {
                        $stmt = $pdo->prepare("
                            UPDATE documents SET 
                                subject_id = :sub_id, title = :title, slug = :slug, description = :desc, 
                                content_type = :type, status = :status,
                                published_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN COALESCE(:published_at, published_at, CURRENT_TIMESTAMP) ELSE NULL END,
                                approval_expires_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN NULL ELSE COALESCE(approval_expires_at, CURRENT_TIMESTAMP + INTERVAL '1 month') END,
                                text_content = :text_content,
                                code_language = :code_language, external_url = :url,
                                stored_filename = :stored_name, original_filename = :orig_name, mime_type = :mime, 
                                file_extension = :ext, file_size = :size, file_path = :path
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':sub_id' => $subjectId, ':title' => $titulo, ':slug' => $slug, ':desc' => $descricao,
                            ':type' => $tipoConteudo, ':status' => $status, ':published_at' => $publishedAt, ':is_published' => $status === 'published' ? 1 : 0,
                            ':text_content' => $conteudoArmazenado,
                            ':code_language' => $linguagemCodigo, ':url' => $linkExterno,
                            ':stored_name' => $storedFilename, ':orig_name' => $originalFilename, ':mime' => $tipoMime,
                            ':ext' => $fileExt, ':size' => $tamanhoBytes, ':path' => $filePath, ':id' => $id
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE documents SET 
                                subject_id = :sub_id, title = :title, slug = :slug, description = :desc, 
                                content_type = :type, status = :status,
                                published_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN COALESCE(:published_at, published_at, CURRENT_TIMESTAMP) ELSE NULL END,
                                approval_expires_at = CASE WHEN CAST(:is_published AS BOOLEAN) THEN NULL ELSE COALESCE(approval_expires_at, CURRENT_TIMESTAMP + INTERVAL '1 month') END,
                                text_content = :text_content,
                                code_language = :code_language, external_url = :url
                                " . ($tipoConteudo === 'video' && $videoSource === 'url'
                                    ? ', stored_filename = NULL, original_filename = NULL, mime_type = NULL, file_extension = NULL, file_size = NULL, file_path = NULL'
                                    : '') . "
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':sub_id' => $subjectId, ':title' => $titulo, ':slug' => $slug, ':desc' => $descricao,
                            ':type' => $tipoConteudo, ':status' => $status, ':published_at' => $publishedAt, ':is_published' => $status === 'published' ? 1 : 0,
                            ':text_content' => $conteudoArmazenado,
                            ':code_language' => $linguagemCodigo, ':url' => $linkExterno,
                            ':id' => $id
                        ]);
                    }
                    $resolvedTagIds = $tagService->resolveForDocument($requestedTagIds, $requestedNewTagNames, $editorUserId);
                    $tagService->syncDocumentTags($id, $resolvedTagIds);
                    try {
                        $workflowService->applyTransitionMetadata($id, $editorUserId, $workflowTransition['action'], $workflowTransition['note']);
                        $workflowService->record($id, $editorUserId, $workflowTransition['action'], $previousDocumentStatus, $status, $workflowTransition['note']);
                        $workflowService->notifyForTransition($id, $editorUserId, $workflowTransition['action']);
                    } catch (Throwable $exception) {
                        error_log('DocGov workflow: falha ao registrar transição: ' . $exception->getMessage());
                    }
                    $usageAuditService->logAdminAction($editorUserId, 'document_updated', 'DOCUMENT', $id);
                    header('Location: index.php?tab=detalhes_documento&id=' . $id . '&msg=doc_updated');
                    exit;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO documents (
                            subject_id, created_by, title, slug, description, content_type, status, published_at,
                            original_filename, stored_filename, file_path, mime_type, file_extension, file_size,
                            text_content, code_language, external_url
                        ) VALUES (
                            :sub_id, :created_by, :title, :slug, :desc, :type, :status, :published_at,
                            :orig_name, :stored_name, :path, :mime, :ext, :size,
                            :text_content, :code_language, :url
                        ) RETURNING id
                    ");
                    $stmt->execute([
                        ':sub_id' => $subjectId, ':created_by' => (int)$loggedUser['id'], ':title' => $titulo, ':slug' => $slug,
                        ':desc' => $descricao, ':type' => $tipoConteudo, ':status' => $status, ':published_at' => $publishedAt,
                        ':orig_name' => $originalFilename, ':stored_name' => $storedFilename, ':path' => $filePath,
                        ':mime' => $tipoMime, ':ext' => $fileExt, ':size' => $tamanhoBytes,
                        ':text_content' => $conteudoArmazenado, ':code_language' => $linguagemCodigo, ':url' => $linkExterno
                    ]);
                    $newId = (int)$stmt->fetchColumn();
                    $resolvedTagIds = $tagService->resolveForDocument($requestedTagIds, $requestedNewTagNames, $editorUserId);
                    $tagService->syncDocumentTags($newId, $resolvedTagIds);
                    try {
                        $workflowService->applyTransitionMetadata($newId, $editorUserId, $workflowTransition['action'], $workflowTransition['note']);
                        $workflowService->record($newId, $editorUserId, $workflowTransition['action'], 'draft', $status, $workflowTransition['note']);
                        $workflowService->notifyForTransition($newId, $editorUserId, $workflowTransition['action']);
                    } catch (Throwable $exception) {
                        error_log('DocGov workflow: falha ao registrar criação: ' . $exception->getMessage());
                    }
                    $usageAuditService->logAdminAction($editorUserId, 'document_created', 'DOCUMENT', $newId);
                    header('Location: index.php?tab=detalhes_documento&id=' . $newId . '&msg=doc_created');
                    exit;
                }
            }
        }
    }

    // 1.1 TRANSIÇÃO EDITORIAL RÁPIDA (aprovar ou devolver para ajustes sem abrir o editor)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['workflow_quick_action'])) {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $workflowAction = trim($_POST['workflow_quick_action'] ?? '');
        $workflowNote = trim($_POST['workflow_note'] ?? '');

        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
        } elseif ($documentId <= 0 || !$permService->canEditDocument($currentAdminUserId, $documentId)) {
            http_response_code(403);
            $errorMessage = 'Você não possui acesso a este documento.';
        } else {
            $stmtCurrent = $pdo->prepare('SELECT status FROM documents WHERE id = :id');
            $stmtCurrent->execute([':id' => $documentId]);
            $previousStatus = (string)$stmtCurrent->fetchColumn();
            try {
                $transition = $workflowService->prepareAction($workflowAction, $previousStatus, $currentAdminUserId, $documentId, $workflowNote);
                $workflowService->applyStatus($documentId, $transition['status']);
                $workflowService->applyTransitionMetadata($documentId, $currentAdminUserId, $transition['action'], $transition['note']);
                $workflowService->record($documentId, $currentAdminUserId, $transition['action'], $previousStatus, $transition['status'], $transition['note']);
                $workflowService->notifyForTransition($documentId, $currentAdminUserId, $transition['action']);
                $usageAuditService->logAdminAction($currentAdminUserId, 'workflow_' . $transition['action'], 'DOCUMENT', $documentId);
                header('Location: index.php?tab=detalhes_documento&id=' . $documentId . '&msg=workflow_updated');
                exit;
            } catch (Throwable $exception) {
                $errorMessage = $exception->getMessage();
            }
        }
    }

    // 1.15 LIXEIRA: ações individuais protegidas por CSRF. A restauração usa o
    // estado preservado no momento do arquivamento e nunca reativa expirações.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['document_trash_action'])) {
        $trashAction = trim((string)$_POST['document_trash_action']);
        $documentId = (int)($_POST['document_id'] ?? 0);

        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
        } elseif (!in_array($trashAction, ['trash', 'restore', 'permanent_delete'], true) || $documentId <= 0) {
            $errorMessage = 'Ação de lixeira inválida.';
        } elseif (!$permService->canEditDocument($currentAdminUserId, $documentId)) {
            http_response_code(403);
            $errorMessage = 'Você não possui acesso a este documento.';
        } else {
            try {
                $pdo->beginTransaction();
                $currentDocumentStmt = $pdo->prepare('SELECT id, status, file_path, trashed_at FROM documents WHERE id = :id FOR UPDATE');
                $currentDocumentStmt->execute([':id' => $documentId]);
                $currentDocument = $currentDocumentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$currentDocument) {
                    throw new RuntimeException('Documento não encontrado.');
                }

                if ($trashAction === 'trash') {
                    $workflowService->moveToTrash($documentId, $currentAdminUserId, (string)$currentDocument['status'], 'Movido para a lixeira.');
                    $pdo->commit();
                    $usageAuditService->logAdminAction($currentAdminUserId, 'document_trashed', 'DOCUMENT', $documentId);
                    header('Location: index.php?tab=documentos&msg=moved_to_trash');
                    exit;
                }

                if ($trashAction === 'restore') {
                    $restoreStatus = $workflowService->restoreFromTrash($documentId, $currentAdminUserId);
                    $pdo->commit();
                    $usageAuditService->logAdminAction($currentAdminUserId, 'document_restored_from_trash', 'DOCUMENT', $documentId);
                    header('Location: index.php?tab=lixeira&msg=restored&status=' . urlencode($restoreStatus));
                    exit;
                }

                if (!$permService->canAdminDocument($currentAdminUserId, $documentId)) {
                    throw new RuntimeException('Somente o Administrador da categoria pode excluir um documento definitivamente.');
                }
                if ((string)$currentDocument['status'] !== 'inactive' || empty($currentDocument['trashed_at'])) {
                    throw new InvalidArgumentException('Somente documentos enviados à lixeira podem ser excluídos definitivamente.');
                }
                $filePath = (string)($currentDocument['file_path'] ?? '');
                $deleteDocumentStmt = $pdo->prepare('DELETE FROM documents WHERE id = :id');
                $deleteDocumentStmt->execute([':id' => $documentId]);
                $pdo->commit();

                // Arquivos só são removidos depois da confirmação no banco e se
                // pertencem inequivocamente ao diretório protegido de documentos.
                if ($filePath !== '') {
                    $storageRoot = realpath(__DIR__ . '/../storage/documents');
                    $candidatePath = realpath(__DIR__ . '/../' . ltrim(str_replace('\\', '/', $filePath), '/'));
                    if ($storageRoot !== false && $candidatePath !== false
                        && str_starts_with($candidatePath, $storageRoot . DIRECTORY_SEPARATOR)
                        && is_file($candidatePath)) {
                        @unlink($candidatePath);
                    }
                }
                $usageAuditService->logAdminAction($currentAdminUserId, 'document_permanently_deleted', 'DOCUMENT', $documentId);
                header('Location: index.php?tab=lixeira&msg=perm_deleted');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMessage = $exception->getMessage();
            }
        }
    }

    // 1.2 AÇÕES EM LOTE: respeitam as mesmas regras e registram cada transição.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['batch_action'])) {
        $batchAction = trim($_POST['batch_action'] ?? '');
        $selectedDocumentIds = array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['selected_docs'] ?? [])),
            static fn (int $documentId): bool => $documentId > 0
        )));
        $workflowActions = [
            'submit_review' => 'submit_review',
            'publish' => 'approve_publish',
            'draft' => 'save_draft',
            'trash' => 'archive',
        ];

        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
        } elseif (!isset($workflowActions[$batchAction])) {
            $errorMessage = 'Ação em lote inválida.';
        } elseif (empty($selectedDocumentIds)) {
            $errorMessage = 'Selecione pelo menos um documento.';
        } else {
            try {
                $pdo->beginTransaction();
                $processed = 0;
                $stmtCurrent = $pdo->prepare('SELECT status FROM documents WHERE id = :id FOR UPDATE');
                foreach ($selectedDocumentIds as $selectedDocumentId) {
                    if (!$permService->canEditDocument($currentAdminUserId, $selectedDocumentId)) {
                        throw new RuntimeException('Um ou mais documentos selecionados estão fora da sua área autorizada.');
                    }
                    $stmtCurrent->execute([':id' => $selectedDocumentId]);
                    $previousStatus = (string)$stmtCurrent->fetchColumn();
                    if ($previousStatus === '') {
                        throw new RuntimeException('Documento selecionado não encontrado.');
                    }
                    if ($batchAction === 'trash') {
                        $workflowService->moveToTrash($selectedDocumentId, $currentAdminUserId, $previousStatus, 'Movido para a lixeira em ação em lote.');
                    } else {
                        $transition = $workflowService->prepareAction($workflowActions[$batchAction], $previousStatus, $currentAdminUserId, $selectedDocumentId);
                        $workflowService->applyStatus($selectedDocumentId, $transition['status']);
                        $workflowService->applyTransitionMetadata($selectedDocumentId, $currentAdminUserId, $transition['action'], 'Ação em lote');
                        $workflowService->record($selectedDocumentId, $currentAdminUserId, $transition['action'], $previousStatus, $transition['status'], 'Ação em lote');
                    }
                    $processed++;
                }
                $pdo->commit();

                foreach ($selectedDocumentIds as $selectedDocumentId) {
                    $workflowService->notifyForTransition($selectedDocumentId, $currentAdminUserId, $workflowActions[$batchAction] === 'submit_review' ? 'submitted_for_review' : ($workflowActions[$batchAction] === 'approve_publish' ? 'approved_and_published' : ''));
                    $usageAuditService->logAdminAction($currentAdminUserId, 'batch_' . $workflowActions[$batchAction], 'DOCUMENT', $selectedDocumentId);
                }
                $batchMessage = $batchAction === 'trash' ? 'docs_trashed' : 'docs_workflow_updated';
                header('Location: index.php?tab=documentos&msg=' . $batchMessage . '&count=' . $processed);
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMessage = $exception->getMessage();
            }
        }
    }

    // 2. GESTÃO DE CATEGORIAS (CRIAR/EDITAR/EXCLUIR)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_category'])) {
        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $statusVal = trim($_POST['status'] ?? 'ativo') === 'ativo';
        $catId = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        $redirectTab = trim($_POST['redirect_tab'] ?? 'categorias');
        $categoryImageFile = isset($_FILES['category_image']) && is_array($_FILES['category_image'])
            ? $_FILES['category_image']
            : null;
        $removeCategoryImage = $catId !== null && isset($_POST['remove_category_image']);

        $userId = (int)($loggedUser['id'] ?? 0);
        $canSaveCategory = $catId
            ? ($permService->canAdminCategory($userId, $catId) || $permService->isGlobalAdmin($userId))
            : $permService->canCreateCategory($userId);

        if (!$canSaveCategory) {
            http_response_code(403);
            $errorMessage = $catId
                ? 'Acesso negado. É necessário privilégio Admin nesta Categoria (ou ser Administrador Global) para alterá-la.'
                : 'Acesso negado. Apenas o Administrador Global pode criar novas Categorias no nível raiz.';
        } elseif (empty($nome)) {
            $errorMessage = 'Informe o nome da categoria.';
        } elseif (($imageError = $categoryImageService->validate($categoryImageFile)) !== null) {
            $errorMessage = $imageError;
        } else {
            $oldImagePath = null;
            $newImagePath = null;
            $savedCategoryId = (int)($catId ?? 0);

            try {
                $pdo->beginTransaction();

                if ($catId) {
                    $oldImageStmt = $pdo->prepare('SELECT image_path FROM categories WHERE id = :id FOR UPDATE');
                    $oldImageStmt->execute([':id' => $catId]);
                    $oldImagePath = $oldImageStmt->fetchColumn();
                    if ($oldImagePath === false) {
                        throw new RuntimeException('Categoria não encontrada.');
                    }

                    $slug = slugify($nome);
                    $stmt = $pdo->prepare('UPDATE categories SET name = :name, slug = :slug, description = :desc, active = :active WHERE id = :id');
                    $stmt->execute([':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false', ':id' => $catId]);
                } else {
                    $slug = slugify($nome);
                    $stmt = $pdo->prepare('INSERT INTO categories (name, slug, description, active) VALUES (:name, :slug, :desc, :active) RETURNING id');
                    $stmt->execute([':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false']);
                    $savedCategoryId = (int)$stmt->fetchColumn();
                }

                $hasNewImage = $categoryImageFile !== null
                    && (int)($categoryImageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if ($hasNewImage) {
                    $newImagePath = $categoryImageService->store($categoryImageFile, $savedCategoryId);
                    $imageStmt = $pdo->prepare('UPDATE categories SET image_path = :image_path WHERE id = :id');
                    $imageStmt->execute([':image_path' => $newImagePath, ':id' => $savedCategoryId]);
                } elseif ($removeCategoryImage) {
                    $imageStmt = $pdo->prepare('UPDATE categories SET image_path = NULL WHERE id = :id');
                    $imageStmt->execute([':id' => $savedCategoryId]);
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($newImagePath !== null) {
                    $categoryImageService->remove($newImagePath);
                }
                $errorMessage = $exception->getMessage();
            }

            if ($errorMessage === '') {
                if (($newImagePath !== null || $removeCategoryImage) && is_string($oldImagePath)) {
                    $categoryImageService->remove($oldImagePath);
                }
                $usageAuditService->logAdminAction($userId, $catId ? 'category_updated' : 'category_created', 'CATEGORY', $savedCategoryId);
                $redirectParams = ['tab' => $redirectTab, 'msg' => 'category_saved'];
                if ($redirectTab === 'novo_documento' && !$catId && $statusVal) {
                    $redirectParams += ['setup' => 'subcategory', 'cat_id' => $savedCategoryId];
                } elseif ($redirectTab === 'novo_documento' && !$statusVal) {
                    $redirectParams['tab'] = 'categorias';
                }
                header('Location: index.php?' . http_build_query($redirectParams));
                exit;
            }
        }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'delete_category' && isset($_GET['id'])) {
        if (!$isGlobalAdminCurrent) {
            $errorMessage = "Usuários com perfil 'Editor' não possuem permissão para excluir Categorias.";
        } else {
            $catId = (int)$_GET['id'];
            $countSub = $pdo->prepare("SELECT COUNT(*) FROM subcategories WHERE category_id = :id");
            $countSub->execute([':id' => $catId]);
            if ((int)$countSub->fetchColumn() > 0) {
                $errorMessage = "Esta categoria possui subcategorias vinculadas. Desative-a em vez de excluir.";
            } else {
                $pdo->prepare("DELETE FROM categories WHERE id = :id")->execute([':id' => $catId]);
                $usageAuditService->logAdminAction($currentAdminUserId, 'category_deleted', 'CATEGORY', $catId);
                header('Location: index.php?tab=categorias&msg=category_deleted');
                exit;
            }
        }
    }

    // 3. GESTÃO DE SUBCATEGORIAS
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_subcategory'])) {
        $catId = (int)($_POST['category_id'] ?? $_POST['categoria_id'] ?? 0);
        $catNome = trim($_POST['categoria_nome'] ?? '');
        if ($catId <= 0 && !empty($catNome)) {
            $stmtFindCat = $pdo->prepare("SELECT id FROM categories WHERE name = :n OR slug = :n LIMIT 1");
            $stmtFindCat->execute([':n' => $catNome]);
            $catId = (int)$stmtFindCat->fetchColumn();
        }

        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $statusVal = trim($_POST['status'] ?? 'ativo') === 'ativo';
        $subId = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        $redirectTab = trim($_POST['redirect_tab'] ?? 'subcategorias');
        $subcategoryImageFile = isset($_FILES['subcategory_image']) && is_array($_FILES['subcategory_image'])
            ? $_FILES['subcategory_image']
            : null;
        $removeSubcategoryImage = $subId !== null && isset($_POST['remove_subcategory_image']);

        $originalCategoryId = 0;
        if ($subId) {
            $parentCategoryStmt = $pdo->prepare('SELECT category_id FROM subcategories WHERE id = :id');
            $parentCategoryStmt->execute([':id' => $subId]);
            $originalCategoryId = (int)$parentCategoryStmt->fetchColumn();
            if ($catId <= 0) {
                $catId = $originalCategoryId;
            }
        }

        $userId = (int)($loggedUser['id'] ?? 0);

        if (!$subId && ($catId <= 0 || !$permService->canCreateSubcategory($userId, $catId))) {
            http_response_code(403);
            $errorMessage = "Acesso negado. Você não possui permissão para criar subcategorias nesta categoria.";
        } elseif ($subId && $originalCategoryId <= 0) {
            $errorMessage = 'Subcategoria não encontrada.';
        } elseif ($subId && !$permService->canEditSubcategory($userId, $subId)) {
            http_response_code(403);
            $errorMessage = "Acesso negado. Você não possui permissão para editar esta subcategoria.";
        } elseif ($subId && $catId !== $originalCategoryId
            && (!$permService->canAdminSubcategory($userId, $subId) || !$permService->canAdminCategory($userId, $catId))) {
            http_response_code(403);
            $errorMessage = 'Para mover uma subcategoria é necessário possuir Admin tanto na origem quanto na categoria de destino.';
        } elseif (empty($nome) || $catId <= 0) {
            $errorMessage = 'Informe a categoria pai e o nome da subcategoria.';
        } elseif (($imageError = $categoryImageService->validate($subcategoryImageFile, 'subcategoria')) !== null) {
            $errorMessage = $imageError;
        } else {
            $oldImagePath = null;
            $newImagePath = null;
            $savedSubcategoryId = (int)($subId ?? 0);

            try {
                $pdo->beginTransaction();
                $slug = slugify($nome);

                if ($subId) {
                    $oldImageStmt = $pdo->prepare('SELECT image_path FROM subcategories WHERE id = :id FOR UPDATE');
                    $oldImageStmt->execute([':id' => $subId]);
                    $oldImagePath = $oldImageStmt->fetchColumn();
                    if ($oldImagePath === false) {
                        throw new RuntimeException('Subcategoria não encontrada.');
                    }

                    $stmt = $pdo->prepare('UPDATE subcategories SET category_id = :cat_id, name = :name, slug = :slug, description = :desc, active = :active WHERE id = :id');
                    $stmt->execute([':cat_id' => $catId, ':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false', ':id' => $subId]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO subcategories (category_id, name, slug, description, active) VALUES (:cat_id, :name, :slug, :desc, :active) RETURNING id');
                    $stmt->execute([':cat_id' => $catId, ':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false']);
                    $savedSubcategoryId = (int)$stmt->fetchColumn();
                }

                $hasNewImage = $subcategoryImageFile !== null
                    && (int)($subcategoryImageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if ($hasNewImage) {
                    $newImagePath = $categoryImageService->storeFor($subcategoryImageFile, $savedSubcategoryId, 'subcategory');
                    $imageStmt = $pdo->prepare('UPDATE subcategories SET image_path = :image_path WHERE id = :id');
                    $imageStmt->execute([':image_path' => $newImagePath, ':id' => $savedSubcategoryId]);
                } elseif ($removeSubcategoryImage) {
                    $imageStmt = $pdo->prepare('UPDATE subcategories SET image_path = NULL WHERE id = :id');
                    $imageStmt->execute([':id' => $savedSubcategoryId]);
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($newImagePath !== null) {
                    $categoryImageService->remove($newImagePath);
                }
                $errorMessage = $exception->getMessage();
            }

            if ($errorMessage === '') {
                if (($newImagePath !== null || $removeSubcategoryImage) && is_string($oldImagePath)) {
                    $categoryImageService->remove($oldImagePath);
                }
                $usageAuditService->logAdminAction($userId, $subId ? 'subcategory_updated' : 'subcategory_created', 'SUBCATEGORY', $savedSubcategoryId);
                $redirectParams = ['tab' => $redirectTab, 'msg' => 'subcategory_saved'];
                if ($redirectTab === 'novo_documento' && !$subId && $statusVal) {
                    $redirectParams += ['setup' => 'subject', 'cat_id' => $catId, 'subcat_id' => $savedSubcategoryId];
                } elseif ($redirectTab === 'novo_documento' && !$statusVal) {
                    $redirectParams['tab'] = 'subcategorias';
                }
                header('Location: index.php?' . http_build_query($redirectParams));
                exit;
            }
        }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'delete_subcategory' && isset($_GET['id'])) {
        if (!$isGlobalAdminCurrent) {
            $errorMessage = "Usuários com perfil 'Editor' não possuem permissão para excluir Subcategorias.";
        } else {
            $subId = (int)$_GET['id'];
            $countAss = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE subcategory_id = :id");
            $countAss->execute([':id' => $subId]);
            if ((int)$countAss->fetchColumn() > 0) {
                $errorMessage = "Esta subcategoria possui assuntos vinculados. Desative-a em vez de excluir.";
            } else {
                $pdo->prepare("DELETE FROM subcategories WHERE id = :id")->execute([':id' => $subId]);
                $usageAuditService->logAdminAction($currentAdminUserId, 'subcategory_deleted', 'SUBCATEGORY', $subId);
                header('Location: index.php?tab=subcategorias&msg=subcategory_deleted');
                exit;
            }
        }
    }

    // 4. GESTÃO DE ASSUNTOS
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_subject'])) {
        $subId = (int)($_POST['subcategory_id'] ?? $_POST['subcategoria_id'] ?? 0);
        $subNome = trim($_POST['subcategoria_nome'] ?? '');
        if ($subId <= 0 && !empty($subNome)) {
            $stmtFindSub = $pdo->prepare("SELECT id FROM subcategories WHERE name = :n OR slug = :n LIMIT 1");
            $stmtFindSub->execute([':n' => $subNome]);
            $subId = (int)$stmtFindSub->fetchColumn();
        }

        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $statusVal = trim($_POST['status'] ?? 'ativo') === 'ativo';
        $assId = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        $isNewSubject = !$assId;
        $redirectTab = trim($_POST['redirect_tab'] ?? 'assuntos');

        $originalSubcategoryId = 0;
        if ($assId) {
            $parentSubcategoryStmt = $pdo->prepare('SELECT subcategory_id FROM subjects WHERE id = :id');
            $parentSubcategoryStmt->execute([':id' => $assId]);
            $originalSubcategoryId = (int)$parentSubcategoryStmt->fetchColumn();
            if ($subId <= 0) {
                $subId = $originalSubcategoryId;
            }
        }

        $userId = (int)($loggedUser['id'] ?? 0);

        if (!$assId && ($subId <= 0 || !$permService->canCreateSubject($userId, $subId))) {
            http_response_code(403);
            $errorMessage = "Acesso negado. Você não possui permissão para criar assuntos nesta subcategoria.";
        } elseif ($assId && $originalSubcategoryId <= 0) {
            $errorMessage = 'Assunto não encontrado.';
        } elseif ($assId && !$permService->canEditSubject($userId, $assId)) {
            http_response_code(403);
            $errorMessage = "Acesso negado. Você não possui permissão para editar este assunto.";
        } elseif ($assId && $subId !== $originalSubcategoryId
            && (!$permService->canAdminSubject($userId, $assId) || !$permService->canAdminSubcategory($userId, $subId))) {
            http_response_code(403);
            $errorMessage = 'Para mover um assunto é necessário possuir Admin tanto na origem quanto na subcategoria de destino.';
        } elseif (!empty($nome) && $subId > 0) {
            $slug = slugify($nome);
            if ($assId) {
                $stmt = $pdo->prepare("UPDATE subjects SET subcategory_id = :sub_id, name = :name, slug = :slug, description = :desc, active = :active WHERE id = :id");
                $stmt->execute([':sub_id' => $subId, ':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false', ':id' => $assId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO subjects (subcategory_id, name, slug, description, active) VALUES (:sub_id, :name, :slug, :desc, :active) RETURNING id");
                $stmt->execute([':sub_id' => $subId, ':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false']);
                $assId = (int)$stmt->fetchColumn();
            }
            $usageAuditService->logAdminAction($userId, $isNewSubject ? 'subject_created' : 'subject_updated', 'SUBJECT', (int)$assId);
            $redirectParams = ['tab' => $redirectTab, 'msg' => 'subject_saved'];
            if ($redirectTab === 'novo_documento' && $isNewSubject && $statusVal) {
                $parentStmt = $pdo->prepare('SELECT sc.category_id FROM subcategories sc WHERE sc.id = :id');
                $parentStmt->execute([':id' => $subId]);
                $redirectParams += [
                    'setup' => 'document',
                    'cat_id' => (int)$parentStmt->fetchColumn(),
                    'subcat_id' => $subId,
                    'subject_id' => (int)$assId,
                ];
            } elseif ($redirectTab === 'novo_documento' && !$statusVal) {
                $redirectParams['tab'] = 'assuntos';
            }
            header('Location: index.php?' . http_build_query($redirectParams));
            exit;
        }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'delete_subject' && isset($_GET['id'])) {
        if (!$isGlobalAdminCurrent) {
            $errorMessage = "Usuários com perfil 'Editor' não possuem permissão para excluir Assuntos.";
        } else {
            $assId = (int)$_GET['id'];
            $countDoc = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE subject_id = :id");
            $countDoc->execute([':id' => $assId]);
            if ((int)$countDoc->fetchColumn() > 0) {
                $errorMessage = "Este assunto possui documentos vinculados. Desative-o em vez de excluir.";
            } else {
                $pdo->prepare("DELETE FROM subjects WHERE id = :id")->execute([':id' => $assId]);
                $usageAuditService->logAdminAction($currentAdminUserId, 'subject_deleted', 'SUBJECT', $assId);
                header('Location: index.php?tab=assuntos&msg=subject_deleted');
                exit;
            }
        }
    }

    // CARREGAR EDICÃO DE ENTIDADES DA HIERARQUIA
    if (isset($_GET['action']) && $_GET['action'] === 'edit_category' && isset($_GET['id'])) {
        $requestedCategoryId = (int)$_GET['id'];
        if (!$permService->canEdit($currentAdminUserId, 'category', $requestedCategoryId)) {
            http_response_code(403);
            $errorMessage = 'Acesso negado: categoria fora do seu escopo administrativo.';
        } else {
            $stmt = $pdo->prepare("SELECT id, name AS nome, description AS descricao, image_path, active FROM categories WHERE id = :id");
            $stmt->execute([':id' => $requestedCategoryId]);
            $editCat = $stmt->fetch();
            if ($editCat) {
                $editCat['status'] = $editCat['active'] ? 'ativo' : 'inativo';
            }
        }
    }
    if (isset($_GET['action']) && $_GET['action'] === 'edit_subcategory' && isset($_GET['id'])) {
        $requestedSubcategoryId = (int)$_GET['id'];
        if (!$permService->canEdit($currentAdminUserId, 'subcategory', $requestedSubcategoryId)) {
            http_response_code(403);
            $errorMessage = 'Acesso negado: subcategoria fora do seu escopo administrativo.';
        } else {
            $stmt = $pdo->prepare("SELECT sc.id, sc.category_id, sc.name AS nome, sc.description AS descricao, sc.image_path, sc.active, c.name AS categoria_nome FROM subcategories sc JOIN categories c ON sc.category_id = c.id WHERE sc.id = :id");
            $stmt->execute([':id' => $requestedSubcategoryId]);
            $editSub = $stmt->fetch();
            if ($editSub) {
                $editSub['status'] = $editSub['active'] ? 'ativo' : 'inativo';
            }
        }
    }
    if (isset($_GET['action']) && $_GET['action'] === 'edit_subject' && isset($_GET['id'])) {
        $requestedSubjectId = (int)$_GET['id'];
        if (!$permService->canEdit($currentAdminUserId, 'subject', $requestedSubjectId)) {
            http_response_code(403);
            $errorMessage = 'Acesso negado: assunto fora do seu escopo administrativo.';
        } else {
            $stmt = $pdo->prepare("SELECT s.id, s.subcategory_id, s.name AS nome, s.description AS descricao, s.active, sc.name AS subcategoria_nome FROM subjects s JOIN subcategories sc ON s.subcategory_id = sc.id WHERE s.id = :id");
            $stmt->execute([':id' => $requestedSubjectId]);
            $editAss = $stmt->fetch();
            if ($editAss) {
                $editAss['status'] = $editAss['active'] ? 'ativo' : 'inativo';
            }
        }
    }
    if (isset($_GET['action']) && $_GET['action'] === 'edit_doc' && isset($_GET['id'])) {
        $requestedDocumentId = (int)$_GET['id'];
        if (!$permService->canEditDocument($currentAdminUserId, $requestedDocumentId)) {
            http_response_code(403);
            $errorMessage = 'Acesso negado: documento fora do seu escopo administrativo.';
        } else {
            $stmt = $pdo->prepare("
                SELECT d.id, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo, d.status,
                       d.text_content AS conteudo_html, d.code_language AS linguagem_codigo, d.external_url AS link_externo,
                       s.id AS assunto_id, s.name AS assunto,
                       sc.id AS subcategoria_id, sc.name AS subcategoria,
                       c.id AS categoria_id, c.name AS categoria
                FROM documents d
                JOIN subjects s ON d.subject_id = s.id
                JOIN subcategories sc ON s.subcategory_id = sc.id
                JOIN categories c ON sc.category_id = c.id
                WHERE d.id = :id
            ");
            $stmt->execute([':id' => $requestedDocumentId]);
            $editDoc = $stmt->fetch();
        }
    }

    // CARREGAR DETALHES DE DOCUMENTO
    if ($activeTab === 'detalhes_documento' || $activeTab === 'substituir_arquivo') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$permService->canEditDocument($currentAdminUserId, $id)) {
            http_response_code(403);
            $errorMessage = 'Acesso negado: os detalhes deste documento pertencem a outra área.';
        } else {
            $stmt = $pdo->prepare("
                SELECT d.id, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo, d.status,
                       d.original_filename AS nome_original, d.file_path AS caminho_arquivo, d.file_size AS tamanho_bytes,
                       d.mime_type AS tipo_mime, d.published_at, d.created_at, d.approval_expires_at,
                       d.reviewed_at, d.approved_at, d.rejected_at, d.rejection_reason,
                       s.name AS assunto, sc.name AS subcategoria, c.name AS categoria,
                       u.name AS autor_nome, reviewer.name AS revisor_nome,
                       approver.name AS aprovador_nome, rejector.name AS recusador_nome
                FROM documents d
                JOIN subjects s ON d.subject_id = s.id
                JOIN subcategories sc ON s.subcategory_id = sc.id
                JOIN categories c ON sc.category_id = c.id
                LEFT JOIN users u ON d.created_by = u.id
                LEFT JOIN users reviewer ON d.reviewed_by = reviewer.id
                LEFT JOIN users approver ON d.approved_by = approver.id
                LEFT JOIN users rejector ON d.rejected_by = rejector.id
                WHERE d.id = :id
            ");
            $stmt->execute([':id' => $id]);
            $docDetails = $stmt->fetch();
            if ($docDetails) {
                try {
                    $workflowHistory = $workflowService->history($id);
                } catch (Throwable $exception) {
                    error_log('DocGov workflow: histórico indisponível: ' . $exception->getMessage());
                }
            }
        }
    }
}

// =============================================================================
// CONSULTAS PARA AS TABELAS DA GESTÃO E FILTROS (POSTGRESQL)
// =============================================================================
$searchQuery = trim($_GET['search'] ?? '');
$filterCat = trim($_GET['filter_cat'] ?? '');
$filterSubcat = trim($_GET['filter_subcat'] ?? '');
$filterAssunto = trim($_GET['filter_assunto'] ?? '');
$filterTipo = trim($_GET['filter_tipo'] ?? '');
$filterStatus = trim($_GET['filter_status'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClauses = ["d.status != 'inactive'", $administrativeDocumentScopeSql];
$params = [];

if (!empty($searchQuery)) {
    $whereClauses[] = "(LOWER(d.title) LIKE :sq OR LOWER(d.description) LIKE :sq)";
    $params[':sq'] = '%' . mb_strtolower($searchQuery) . '%';
}
if (!empty($filterCat)) {
    $whereClauses[] = "(c.name = :fcat OR c.slug = :fcat)";
    $params[':fcat'] = $filterCat;
}
if (!empty($filterSubcat)) {
    $whereClauses[] = "(sc.name = :fsub OR sc.slug = :fsub)";
    $params[':fsub'] = $filterSubcat;
}
if (!empty($filterAssunto)) {
    $whereClauses[] = "(s.name = :fass OR s.slug = :fass)";
    $params[':fass'] = $filterAssunto;
}
if (!empty($filterTipo)) {
    $whereClauses[] = "d.content_type = :ftipo";
    $params[':ftipo'] = $filterTipo;
}
if (!empty($filterStatus)) {
    $whereClauses[] = "d.status = :fstat";
    $params[':fstat'] = $filterStatus;
}

$whereSql = "WHERE " . implode(" AND ", $whereClauses);

// Paginação e Contagem da Tabela Principal
$countStmt = $pdo->prepare("
    SELECT COUNT(d.id) 
    FROM documents d 
    JOIN subjects s ON d.subject_id = s.id 
    JOIN subcategories sc ON s.subcategory_id = sc.id 
    JOIN categories c ON sc.category_id = c.id 
    {$whereSql}
");
$countStmt->execute($params);
$totalDocsFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalDocsFiltered / $perPage));

$sqlDocs = "
    SELECT d.id, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo, 
           d.status, d.created_at, d.published_at, d.approval_expires_at,
           s.name AS assunto, sc.name AS subcategoria, c.name AS categoria,
           u.name AS autor_nome
    FROM documents d
    JOIN subjects s ON d.subject_id = s.id
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    LEFT JOIN users u ON d.created_by = u.id
    {$whereSql}
    ORDER BY d.id DESC LIMIT {$perPage} OFFSET {$offset}
";
$stmtDocs = $pdo->prepare($sqlDocs);
$stmtDocs->execute($params);
$documentosPaginados = $stmtDocs->fetchAll();

// Métricas da Visão Geral sempre limitadas ao escopo administrativo atual.
$metricsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        COUNT(*) FILTER (WHERE d.status = 'published') AS published,
        COUNT(*) FILTER (WHERE d.status = 'draft') AS draft,
        COUNT(*) FILTER (WHERE d.status = 'review') AS review,
        COUNT(*) FILTER (WHERE d.status = 'inactive') AS inactive
    FROM documents d
    WHERE {$administrativeDocumentScopeSql}
");
$metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalDocs = (int)($metrics['total'] ?? 0);
$totalPublicados = (int)($metrics['published'] ?? 0);
$totalRascunhos = (int)($metrics['draft'] ?? 0);
$totalEmRevisao = (int)($metrics['review'] ?? 0);
$totalInativos = (int)($metrics['inactive'] ?? 0);
$totalLixeira = $totalInativos;

$ultimosDocumentos = $pdo->query("
    SELECT d.id, d.title AS titulo, d.status, d.created_at, d.updated_at AS atualizado_em,
           s.name AS assunto, sc.name AS subcategoria, c.name AS categoria,
           u.name AS autor_nome
    FROM documents d
    JOIN subjects s ON d.subject_id = s.id
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    LEFT JOIN users u ON d.created_by = u.id
    WHERE {$administrativeDocumentScopeSql}
    ORDER BY d.updated_at DESC, d.id DESC LIMIT 5
")->fetchAll();

// Indicadores e auditoria globais: consultados somente para o Super Admin.
// Gestores locais continuam recebendo apenas os dados do próprio escopo.
$globalDashboard = null;
if ($isGlobalAdminCurrent) {
    $userStats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE active = TRUE) AS active,
            COUNT(*) FILTER (WHERE active = FALSE) AS inactive,
            COUNT(*) FILTER (WHERE role = 'admin' AND active = TRUE) AS global_admins,
            COUNT(*) FILTER (WHERE auth_source = 'ad') AS active_directory,
            COUNT(*) FILTER (WHERE auth_source <> 'ad' OR auth_source IS NULL) AS local_auth,
            COUNT(*) FILTER (WHERE last_login_at >= CURRENT_TIMESTAMP - INTERVAL '30 days') AS logins_30d,
            COUNT(*) FILTER (WHERE last_login_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})) AS logins_in_period,
            COUNT(*) FILTER (WHERE last_login_at IS NULL) AS never_logged
        FROM users
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $structureStats = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM categories) AS categories_total,
            (SELECT COUNT(*) FROM categories WHERE active = TRUE) AS categories_active,
            (SELECT COUNT(*) FROM subcategories) AS subcategories_total,
            (SELECT COUNT(*) FROM subjects) AS subjects_total
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $accessStats = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM groups) AS teams_total,
            (SELECT COUNT(*) FROM groups WHERE active = TRUE) AS teams_active,
            (SELECT COUNT(*) FROM user_groups) AS memberships_total,
            (SELECT COUNT(*) FROM permissions) AS permission_rules_total,
            (SELECT COUNT(*) FROM permissions WHERE user_id IS NOT NULL) AS direct_user_rules,
            (SELECT COUNT(*) FROM permissions WHERE group_id IS NOT NULL) AS team_rules,
            (SELECT COUNT(*) FROM permissions WHERE permission_level = 'admin') AS admin_rules,
            (SELECT COUNT(*) FROM permissions WHERE permission_level = 'edit') AS edit_rules,
            (SELECT COUNT(*) FROM permissions WHERE permission_level = 'view') AS view_rules
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $contentStats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status = 'published') AS published,
            COUNT(*) FILTER (WHERE status = 'draft') AS draft,
            COUNT(*) FILTER (WHERE status = 'review') AS review,
            COUNT(*) FILTER (WHERE status = 'inactive') AS inactive,
            COUNT(*) FILTER (WHERE content_type = 'file') AS files,
            COUNT(*) FILTER (WHERE content_type = 'text') AS texts,
            COUNT(*) FILTER (WHERE content_type = 'link') AS links,
            COUNT(*) FILTER (WHERE content_type = 'code') AS codes,
            COUNT(*) FILTER (WHERE created_at >= CURRENT_TIMESTAMP - INTERVAL '30 days') AS created_30d,
            COUNT(*) FILTER (WHERE updated_at >= CURRENT_TIMESTAMP - INTERVAL '30 days') AS updated_30d,
            COUNT(*) FILTER (WHERE created_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})) AS created_in_period,
            COUNT(*) FILTER (WHERE updated_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})) AS updated_in_period
        FROM documents
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $documentsByCategory = $pdo->query("
        SELECT
            c.id,
            c.name,
            COUNT(d.id) AS documents_total,
            COUNT(d.id) FILTER (WHERE d.status = 'published') AS published_total,
            COUNT(d.id) FILTER (WHERE d.status = 'draft') AS draft_total,
            MAX(d.updated_at) AS last_activity_at
        FROM categories c
        LEFT JOIN subcategories sc ON sc.category_id = c.id
        LEFT JOIN subjects s ON s.subcategory_id = sc.id
        LEFT JOIN documents d ON d.subject_id = s.id
        GROUP BY c.id, c.name
        ORDER BY COUNT(d.id) DESC, c.name ASC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    $documentActivity = $pdo->query("
        SELECT
            d.id,
            d.title,
            d.status,
            d.created_at,
            d.updated_at,
            c.name AS category_name,
            sc.name AS subcategory_name,
            s.name AS subject_name,
            u.name AS author_name
        FROM documents d
        JOIN subjects s ON s.id = d.subject_id
        JOIN subcategories sc ON sc.id = s.subcategory_id
        JOIN categories c ON c.id = sc.category_id
        LEFT JOIN users u ON u.id = d.created_by
        ORDER BY d.updated_at DESC, d.id DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    $loginActivity = $pdo->query("
        SELECT id, name, username, last_login_at, auth_source
        FROM users
        WHERE last_login_at IS NOT NULL
        ORDER BY last_login_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    $activityByDay = $pdo->query("
        SELECT
            activity_day::date AS activity_day,
            COUNT(d.id) AS documents_created
        FROM generate_series(
            CURRENT_DATE - INTERVAL '{$dashboardPeriodStartOffset} days',
            CURRENT_DATE,
            INTERVAL '1 day'
        ) AS activity_day
        LEFT JOIN documents d
            ON d.created_at >= activity_day
           AND d.created_at < activity_day + INTERVAL '1 day'
        GROUP BY activity_day
        ORDER BY activity_day ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $permissionAuditAvailable = (bool)$pdo->query("SELECT to_regclass('public.permission_audit') IS NOT NULL")->fetchColumn();
    $permissionAudit = [];
    if ($permissionAuditAvailable) {
        $accessStats['permission_audit_total'] = (int)$pdo->query("SELECT COUNT(*) FROM permission_audit")->fetchColumn();
        $accessStats['permission_audit_in_period'] = (int)$pdo->query("SELECT COUNT(*) FROM permission_audit WHERE created_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})")->fetchColumn();
        $permissionAudit = $pdo->query("
            SELECT
                pa.id,
                pa.action,
                pa.principal_type,
                pa.principal_id,
                pa.resource_type,
                pa.resource_id,
                pa.old_permission,
                pa.new_permission,
                pa.ip_address,
                pa.created_at,
                actor.name AS actor_name,
                COALESCE(target_user.name, target_team.name, 'Principal removido') AS principal_name,
                COALESCE(category_resource.name, subcategory_resource.name, subject_resource.name, 'Recurso removido') AS resource_name
            FROM permission_audit pa
            LEFT JOIN users actor ON actor.id = pa.user_id
            LEFT JOIN users target_user ON pa.principal_type = 'USER' AND target_user.id = pa.principal_id
            LEFT JOIN groups target_team ON pa.principal_type = 'TEAM' AND target_team.id = pa.principal_id
            LEFT JOIN categories category_resource ON pa.resource_type = 'CATEGORY' AND category_resource.id = pa.resource_id
            LEFT JOIN subcategories subcategory_resource ON pa.resource_type = 'SUBCATEGORY' AND subcategory_resource.id = pa.resource_id
            LEFT JOIN subjects subject_resource ON pa.resource_type = 'SUBJECT' AND subject_resource.id = pa.resource_id
            ORDER BY pa.created_at DESC, pa.id DESC
            LIMIT 12
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    $usageAuditAvailable = (bool)$pdo->query("SELECT to_regclass('public.usage_audit_events') IS NOT NULL")->fetchColumn();
    $usageAuditStats = [];
    $usageByPerson = [];
    $usageByDocument = [];
    $usageByNavigation = [];
    $usageRecentEvents = [];
    $usageByDay = [];
    $publicationsByDay = [];
    $adminTimeline = [];

    if ($usageAuditAvailable) {
        $usageAuditStats = $pdo->query("
            SELECT
                COUNT(*) AS total_events,
                COUNT(DISTINCT user_id) FILTER (WHERE user_id IS NOT NULL) AS unique_users,
                COUNT(DISTINCT user_id) FILTER (WHERE user_id IS NOT NULL AND created_at >= CURRENT_TIMESTAMP - INTERVAL '15 minutes') AS online_now,
                COUNT(*) FILTER (WHERE event_type = 'login') AS logins,
                COUNT(*) FILTER (WHERE event_type = 'document_view') AS document_views,
                COUNT(*) FILTER (WHERE event_type = 'document_download') AS downloads,
                COUNT(*) FILTER (WHERE event_type = 'external_open') AS external_opens,
                COUNT(*) FILTER (WHERE event_type = 'search') AS searches,
                COUNT(*) FILTER (WHERE event_type IN ('admin_action', 'admin_page_view')) AS admin_events,
                COUNT(DISTINCT resource_id) FILTER (WHERE resource_type = 'DOCUMENT' AND event_type IN ('document_view', 'document_download', 'external_open')) AS documents_consulted
            FROM usage_audit_events
            WHERE created_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        $usageByPerson = $pdo->query("
            SELECT
                u.id, u.name, u.username, u.role,
                MAX(e.created_at) AS last_activity_at,
                COUNT(*) AS events_total,
                COUNT(*) FILTER (WHERE e.event_type = 'document_view') AS document_views,
                COUNT(*) FILTER (WHERE e.event_type = 'document_download') AS downloads,
                COUNT(DISTINCT e.resource_id) FILTER (WHERE e.resource_type = 'DOCUMENT' AND e.event_type IN ('document_view', 'document_download', 'external_open')) AS documents_used
            FROM usage_audit_events e
            JOIN users u ON u.id = e.user_id
            WHERE e.created_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})
            GROUP BY u.id, u.name, u.username, u.role
            ORDER BY COUNT(*) DESC, MAX(e.created_at) DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        $usageByDocument = $pdo->query("
            SELECT
                d.id, d.title, c.name AS category_name, sc.name AS subcategory_name,
                COUNT(*) FILTER (WHERE e.event_type = 'document_view') AS views,
                COUNT(*) FILTER (WHERE e.event_type = 'document_download') AS downloads,
                COUNT(*) FILTER (WHERE e.event_type = 'external_open') AS external_opens,
                COUNT(DISTINCT e.user_id) FILTER (WHERE e.user_id IS NOT NULL) AS users_total,
                MAX(e.created_at) AS last_access_at
            FROM usage_audit_events e
            JOIN documents d ON e.resource_type = 'DOCUMENT' AND e.resource_id = d.id
            JOIN subjects s ON s.id = d.subject_id
            JOIN subcategories sc ON sc.id = s.subcategory_id
            JOIN categories c ON c.id = sc.category_id
            WHERE e.created_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})
              AND e.event_type IN ('document_view', 'document_download', 'external_open')
            GROUP BY d.id, d.title, c.name, sc.name
            ORDER BY (COUNT(*) FILTER (WHERE e.event_type = 'document_view') + COUNT(*) FILTER (WHERE e.event_type = 'document_download') + COUNT(*) FILTER (WHERE e.event_type = 'external_open')) DESC, MAX(e.created_at) DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        $usageByNavigation = $pdo->query("
            SELECT
                e.resource_type, e.resource_id,
                COALESCE(c.name, sc.name, s.name, 'Recurso removido') AS resource_name,
                COUNT(*) AS accesses,
                COUNT(DISTINCT e.user_id) FILTER (WHERE e.user_id IS NOT NULL) AS users_total,
                MAX(e.created_at) AS last_access_at
            FROM usage_audit_events e
            LEFT JOIN categories c ON e.resource_type = 'CATEGORY' AND e.resource_id = c.id
            LEFT JOIN subcategories sc ON e.resource_type = 'SUBCATEGORY' AND e.resource_id = sc.id
            LEFT JOIN subjects s ON e.resource_type = 'SUBJECT' AND e.resource_id = s.id
            WHERE e.created_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})
              AND e.event_type IN ('category_view', 'subcategory_view', 'subject_view')
            GROUP BY e.resource_type, e.resource_id, c.name, sc.name, s.name
            ORDER BY COUNT(*) DESC, MAX(e.created_at) DESC
            LIMIT 6
        ")->fetchAll(PDO::FETCH_ASSOC);

        $usageRecentEvents = $pdo->query("
            SELECT
                e.id, e.event_type, e.resource_type, e.created_at,
                u.name AS user_name, u.username,
                COALESCE(d.title, c.name, sc.name, s.name, e.metadata->>'target_name', e.metadata->>'tab', 'Portal') AS resource_name
            FROM usage_audit_events e
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN documents d ON e.resource_type = 'DOCUMENT' AND e.resource_id = d.id
            LEFT JOIN categories c ON e.resource_type = 'CATEGORY' AND e.resource_id = c.id
            LEFT JOIN subcategories sc ON e.resource_type = 'SUBCATEGORY' AND e.resource_id = sc.id
            LEFT JOIN subjects s ON e.resource_type = 'SUBJECT' AND e.resource_id = s.id
            WHERE e.created_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})
            ORDER BY e.created_at DESC, e.id DESC
            LIMIT 14
        ")->fetchAll(PDO::FETCH_ASSOC);

        $usageByDay = $pdo->query("
            SELECT
                activity_day::date AS activity_day,
                COUNT(e.id) AS events_total,
                COUNT(e.id) FILTER (WHERE e.event_type = 'document_view') AS document_views,
                COUNT(e.id) FILTER (WHERE e.event_type = 'document_download') AS downloads,
                COUNT(e.id) FILTER (WHERE e.event_type IN ('admin_action', 'admin_page_view')) AS admin_events
            FROM generate_series(CURRENT_DATE - INTERVAL '{$dashboardPeriodStartOffset} days', CURRENT_DATE, INTERVAL '1 day') AS activity_day
            LEFT JOIN usage_audit_events e ON e.created_at >= activity_day AND e.created_at < activity_day + INTERVAL '1 day'
            GROUP BY activity_day
            ORDER BY activity_day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $publicationsByDay = $pdo->query("
            SELECT
                activity_day::date AS activity_day,
                COUNT(d.id) AS publications_total
            FROM generate_series(CURRENT_DATE - INTERVAL '{$dashboardPeriodStartOffset} days', CURRENT_DATE, INTERVAL '1 day') AS activity_day
            LEFT JOIN documents d ON d.published_at >= activity_day AND d.published_at < activity_day + INTERVAL '1 day' AND d.status = 'published'
            GROUP BY activity_day
            ORDER BY activity_day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $timelineParts = ["
            SELECT
                e.created_at, 'usage' AS source, COALESCE(e.metadata->>'action', e.event_type) AS action,
                u.name AS actor_name,
                COALESCE(d.title, c.name, sc.name, s.name, e.metadata->>'target_name', e.metadata->>'tab', 'Portal') AS resource_name
            FROM usage_audit_events e
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN documents d ON e.resource_type = 'DOCUMENT' AND e.resource_id = d.id
            LEFT JOIN categories c ON e.resource_type = 'CATEGORY' AND e.resource_id = c.id
            LEFT JOIN subcategories sc ON e.resource_type = 'SUBCATEGORY' AND e.resource_id = sc.id
            LEFT JOIN subjects s ON e.resource_type = 'SUBJECT' AND e.resource_id = s.id
            WHERE e.created_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays}) AND e.event_type = 'admin_action'
        "];
        if ($permissionAuditAvailable) {
            $timelineParts[] = "
                SELECT
                    pa.created_at, 'permission' AS source, pa.action AS action,
                    actor.name AS actor_name,
                    COALESCE(category_resource.name, subcategory_resource.name, subject_resource.name, 'Recurso removido') AS resource_name
                FROM permission_audit pa
                LEFT JOIN users actor ON actor.id = pa.user_id
                LEFT JOIN categories category_resource ON pa.resource_type = 'CATEGORY' AND category_resource.id = pa.resource_id
                LEFT JOIN subcategories subcategory_resource ON pa.resource_type = 'SUBCATEGORY' AND subcategory_resource.id = pa.resource_id
                LEFT JOIN subjects subject_resource ON pa.resource_type = 'SUBJECT' AND subject_resource.id = pa.resource_id
                WHERE pa.created_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})
            ";
        }
        $timelineParts[] = "
            SELECT
                h.created_at, 'workflow' AS source, h.action AS action,
                actor.name AS actor_name, d.title AS resource_name
            FROM document_workflow_history h
            JOIN documents d ON d.id = h.document_id
            LEFT JOIN users actor ON actor.id = h.actor_id
            WHERE h.created_at >= CURRENT_TIMESTAMP - make_interval(days => {$dashboardPeriodDays})
        ";
        $adminTimeline = $pdo->query('SELECT * FROM (' . implode(' UNION ALL ', $timelineParts) . ') AS timeline ORDER BY created_at DESC LIMIT 16')->fetchAll(PDO::FETCH_ASSOC);
    }

    $globalDashboard = [
        'users' => $userStats,
        'structure' => $structureStats,
        'access' => $accessStats,
        'content' => $contentStats,
        'categories' => $documentsByCategory,
        'documents' => $documentActivity,
        'logins' => $loginActivity,
        'activity_by_day' => $activityByDay,
        'period_days' => $dashboardPeriodDays,
        'permission_audit_available' => $permissionAuditAvailable,
        'permission_audit' => $permissionAudit,
        'usage_audit_available' => $usageAuditAvailable,
        'usage' => $usageAuditStats,
        'usage_by_person' => $usageByPerson,
        'usage_by_document' => $usageByDocument,
        'usage_by_navigation' => $usageByNavigation,
        'usage_recent_events' => $usageRecentEvents,
        'usage_by_day' => $usageByDay,
        'publications_by_day' => $publicationsByDay,
        'admin_timeline' => $adminTimeline,
    ];
}

$documentosLixeira = $pdo->query("
    SELECT d.id, d.title AS titulo, d.status, d.trashed_at AS removido_em,
           s.name AS assunto, sc.name AS subcategoria, c.name AS categoria,
           u.name AS removido_por_nome, d.trashed_from_status
    FROM documents d
    JOIN subjects s ON d.subject_id = s.id
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    LEFT JOIN users u ON d.trashed_by = u.id
    WHERE d.status = 'inactive' AND d.trashed_at IS NOT NULL AND {$administrativeDocumentScopeSql}
    ORDER BY d.trashed_at DESC
")->fetchAll();

// Entidades de Organização
$listCategorias = $pdo->query("
    SELECT c.id, c.name AS nome, c.slug, c.description AS descricao, c.image_path, c.active,
           CASE WHEN c.active THEN 'ativo' ELSE 'inativo' END AS status,
           COUNT(sc.id) AS total_subcat
    FROM categories c
    LEFT JOIN subcategories sc ON sc.category_id = c.id
    GROUP BY c.id, c.name, c.slug, c.description, c.image_path, c.active
    ORDER BY c.name ASC
")->fetchAll();

$listSubcategorias = $pdo->query("
    SELECT sc.id, sc.category_id, sc.name AS nome, sc.slug, sc.description AS descricao, sc.image_path, sc.active,
           CASE WHEN sc.active THEN 'ativo' ELSE 'inativo' END AS status,
           c.name AS categoria_nome, c.active AS categoria_active,
           COUNT(s.id) AS total_assuntos
    FROM subcategories sc
    JOIN categories c ON sc.category_id = c.id
    LEFT JOIN subjects s ON s.subcategory_id = sc.id
    GROUP BY sc.id, sc.category_id, sc.name, sc.slug, sc.description, sc.image_path, sc.active, c.name, c.active
    ORDER BY c.name ASC, sc.name ASC
")->fetchAll();

$listAssuntos = $pdo->query("
    SELECT s.id, s.subcategory_id, s.name AS nome, s.slug, s.description AS descricao, s.active,
           CASE WHEN s.active THEN 'ativo' ELSE 'inativo' END AS status,
           sc.category_id, sc.name AS subcategoria_nome, sc.active AS subcategoria_active,
           c.name AS categoria_nome, c.active AS categoria_active,
           COUNT(d.id) AS total_docs
    FROM subjects s
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    LEFT JOIN documents d ON d.subject_id = s.id
    GROUP BY s.id, s.subcategory_id, s.name, s.slug, s.description, s.active, sc.category_id, sc.name, sc.active, c.name, c.active
    ORDER BY c.name ASC, sc.name ASC, s.name ASC
")->fetchAll();

if ($loggedUser && !$isGlobalAdminCurrent) {
    $listCategorias = array_values(array_filter(
        $listCategorias,
        fn($category) => in_array((int)$category['id'], $administrativeCategoryIds, true)
    ));
    $listSubcategorias = array_values(array_filter(
        $listSubcategorias,
        fn($subcategory) => in_array((int)$subcategory['id'], $administrativeSubcategoryIds, true)
    ));
    $listAssuntos = array_values(array_filter(
        $listAssuntos,
        fn($subject) => in_array((int)$subject['id'], $administrativeSubjectIds, true)
    ));

    // Contadores locais não revelam a existência de irmãos fora do escopo.
    foreach ($listCategorias as &$scopedCategory) {
        $scopedCategory['total_subcat'] = count(array_filter(
            $listSubcategorias,
            fn($subcategory) => (int)$subcategory['category_id'] === (int)$scopedCategory['id']
        ));
    }
    unset($scopedCategory);
    foreach ($listSubcategorias as &$scopedSubcategory) {
        $scopedSubcategory['total_assuntos'] = count(array_filter(
            $listAssuntos,
            fn($subject) => (int)$subject['subcategory_id'] === (int)$scopedSubcategory['id']
        ));
    }
    unset($scopedSubcategory);
}

$rawCategorias = array_column($listCategorias, 'nome');
$categoriasAutorizadas = $rawCategorias;

// ============================================================
// CAPACIDADES SEMÂNTICAS DO USUÁRIO ATUAL
// Calculadas aqui para serem usadas nos formulários do frontend.
// O backend já protege os endpoints - isso é apenas UX.
// ============================================================
$_currentUserId = (int)($loggedUser['id'] ?? 0);
$_canCreateCat    = $permService->canCreateCategory($_currentUserId);
$_canCreateAnySub = false;
$_canCreateAnyAss = false;
$_canCreateAnyDoc = false;

// Listas filtradas para cada formulário de criação (somente onde o usuário tem capacidade)
// Para Subcategoria: categorias onde canCreateSubcategory = true
$catsParaSubcategoria = array_values(array_filter($listCategorias, function($c) use ($permService, $_currentUserId) {
    return docgovDatabaseBoolean($c['active'])
        && $permService->canCreateSubcategory($_currentUserId, (int)$c['id']);
}));
$_canCreateAnySub = !empty($catsParaSubcategoria);

// Para Assunto: subcategorias onde canCreateSubject = true
$subcatsParaAssunto = array_values(array_filter($listSubcategorias, function($sc) use ($permService, $_currentUserId) {
    return docgovDatabaseBoolean($sc['active'])
        && docgovDatabaseBoolean($sc['categoria_active'])
        && $permService->canCreateSubject($_currentUserId, (int)$sc['id']);
}));
$_canCreateAnyAss = !empty($subcatsParaAssunto);

// Para Documento: assuntos onde canCreateDocument = true
$assuntosParaDocumento = array_values(array_filter($listAssuntos, function($s) use ($permService, $_currentUserId) {
    return docgovDatabaseBoolean($s['active'])
        && docgovDatabaseBoolean($s['subcategoria_active'])
        && docgovDatabaseBoolean($s['categoria_active'])
        && $permService->canCreateDocument($_currentUserId, (int)$s['id']);
}));
$_canCreateAnyDoc = !empty($assuntosParaDocumento);

// O formulário mostra também ramos ativos ainda vazios. Assim uma Categoria recém-criada
// não desaparece; a própria tela orienta a completar Subcategoria e Assunto.
$subcatIdsParaDoc = array_unique(array_column($assuntosParaDocumento, 'subcategory_id'));
$subcatsParaDocumento = array_values(array_filter($listSubcategorias, function($sc) use ($subcatIdsParaDoc, $permService, $_currentUserId) {
    $active = docgovDatabaseBoolean($sc['active'])
        && docgovDatabaseBoolean($sc['categoria_active']);
    return $active && (
        in_array((int)$sc['id'], $subcatIdsParaDoc, true)
        || $permService->canCreateSubject($_currentUserId, (int)$sc['id'])
    );
}));
// Categorias acessíveis para Documento, inclusive as ainda sem descendentes.
$catIdsParaDoc = array_unique(array_column($subcatsParaDocumento, 'category_id'));
$catsParaDocumento = array_values(array_filter($listCategorias, function($c) use ($catIdsParaDoc, $permService, $_currentUserId) {
    return docgovDatabaseBoolean($c['active']) && (
        in_array((int)$c['id'], $catIdsParaDoc, true)
        || $permService->canCreateSubcategory($_currentUserId, (int)$c['id'])
    );
}));

// Categorias visíveis para Assunto (derivado das subcategorias autorizadas)
$catIdsParaAss = array_unique(array_column($subcatsParaAssunto, 'category_id'));
$catsParaAssunto = array_values(array_filter($listCategorias, function($c) use ($catIdsParaAss) {
    return in_array((int)$c['id'], $catIdsParaAss);
}));

// Usuário tem alguma capacidade de escrita?
$_hasAnyWriteCapability = $_canCreateCat || $_canCreateAnySub || $_canCreateAnyAss || $_canCreateAnyDoc;

// Mapas para uso no JavaScript (JSON embutido na página)
$mapSubcatsParaAssunto   = []; // [cat_id => [subcat_id => subcat_nome]]
foreach ($subcatsParaAssunto as $sc) {
    $mapSubcatsParaAssunto[(int)$sc['category_id']][] = ['id' => (int)$sc['id'], 'nome' => $sc['nome']];
}
$mapAssuntosParaDocumento = []; // [subcat_id => [subj_id => subj_nome]]
foreach ($assuntosParaDocumento as $s) {
    $mapAssuntosParaDocumento[(int)$s['subcategory_id']][] = ['id' => (int)$s['id'], 'nome' => $s['nome']];
}
$mapSubcatsParaDocumento = []; // [cat_id => [subcat_id => subcat_nome]]
foreach ($subcatsParaDocumento as $sc) {
    $mapSubcatsParaDocumento[(int)$sc['category_id']][] = ['id' => (int)$sc['id'], 'nome' => $sc['nome']];
}

// Mapeamento Completo da Árvore Hierárquica
$treeStructure = [];
foreach ($listCategorias as $catItem) {
    $categoryId = (int)$catItem['id'];
    $treeStructure[$categoryId] = [
        'info' => $catItem,
        'subcategorias' => []
    ];
}

foreach ($listSubcategorias as $subItem) {
    $categoryId = (int)$subItem['category_id'];
    $subcategoryId = (int)$subItem['id'];
    if (isset($treeStructure[$categoryId])) {
        $treeStructure[$categoryId]['subcategorias'][$subcategoryId] = [
            'info' => $subItem,
            'assuntos' => []
        ];
    }
}

foreach ($listAssuntos as $assItem) {
    $categoryId = (int)$assItem['category_id'];
    $subcategoryId = (int)$assItem['subcategory_id'];
    $subjectId = (int)$assItem['id'];
    if (isset($treeStructure[$categoryId]['subcategorias'][$subcategoryId])) {
        $treeStructure[$categoryId]['subcategorias'][$subcategoryId]['assuntos'][$subjectId] = [
            'info' => $assItem,
            'documentos' => []
        ];
    }
}

$allDocsTree = $pdo->query("
    SELECT d.id, d.subject_id, s.subcategory_id, sc.category_id,
           d.title AS titulo, d.status, d.content_type AS tipo_conteudo,
           s.name AS assunto, sc.name AS subcategoria, c.name AS categoria
    FROM documents d
    JOIN subjects s ON d.subject_id = s.id
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    WHERE {$administrativeDocumentScopeSql}
    ORDER BY d.id DESC, d.title ASC
")->fetchAll();

foreach ($allDocsTree as $dItem) {
    $categoryId = (int)$dItem['category_id'];
    $subcategoryId = (int)$dItem['subcategory_id'];
    $subjectId = (int)$dItem['subject_id'];
    if (isset($treeStructure[$categoryId]['subcategorias'][$subcategoryId]['assuntos'][$subjectId])) {
        $treeStructure[$categoryId]['subcategorias'][$subcategoryId]['assuntos'][$subjectId]['documentos'][] = $dItem;
    }
}

$hierarchyMap = [];
foreach ($treeStructure as $cData) {
    $cName = $cData['info']['nome'];
    $hierarchyMap[$cName] = [];
    foreach ($cData['subcategorias'] as $sData) {
        $sName = $sData['info']['nome'];
        $hierarchyMap[$cName][$sName] = array_values(array_map(
            static fn(array $subjectData): string => (string)$subjectData['info']['nome'],
            $sData['assuntos']
        ));
    }
}

// Tags podem ser criadas por quem publica conteúdo; o Super Admin apenas faz a curadoria.
$tagCatalog = $tagService->allActive();
$editDocumentTagIds = $editDoc ? $tagService->getDocumentTagIds((int)$editDoc['id']) : [];
$tagCatalogDetails = $isGlobalAdminCurrent ? $tagService->allWithDetails() : [];

// Define o status HTTP antes de iniciar a saída HTML. As telas abaixo ainda
// exibem a mensagem contextual, mas não podem chamar http_response_code depois
// que o cabeçalho da página já foi enviado.
if ($activeTab === 'editar_estrutura') {
    $preflightTypeInput = strtolower(trim($_GET['type'] ?? 'categoria'));
    $preflightResourceType = match ($preflightTypeInput) {
        'subcategoria', 'subcategory' => 'subcategory',
        'assunto', 'subject' => 'subject',
        default => 'category',
    };
    $preflightResourceId = (int)($_GET['id'] ?? 0);
    // Sem ID, a rota representa a página inicial do Editor da Árvore.
    // Somente uma tentativa de abrir um recurso específico fora do escopo é 403.
    if ($preflightResourceId > 0 && !$permService->canEdit($currentAdminUserId, $preflightResourceType, $preflightResourceId)) {
        http_response_code(403);
    }
}

if ($activeTab === 'detalhes_usuario') {
    $preflightTargetUserId = (int)($_GET['id'] ?? 0);
    if (!$permService->canViewUserInAdministrativeScope($currentAdminUserId, $preflightTargetUserId)) {
        http_response_code(403);
    }
}

$userTheme = $loggedUser['tema_preferido'] ?? ($loggedUser['theme_preference'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';
?>
<?php
$currentSystemSettings = $systemSettingsService->all(true);
$currentMaintenanceStatus = $systemSettingsService->maintenanceStatus();
$settingsTimezone = new DateTimeZone((string)($currentSystemSettings['timezone'] ?? 'America/Sao_Paulo'));
$formatSettingDate = static function (mixed $value) use ($settingsTimezone): string {
    if (!is_string($value) || trim($value) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone($settingsTimezone)->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
};
$settingsMaintenanceStartLocal = $formatSettingDate($currentSystemSettings['maintenance_start_at'] ?? null);
$settingsMaintenanceEndLocal = $formatSettingDate($currentSystemSettings['maintenance_end_at'] ?? null);
$settingsDbVersion = (string)$pdo->query('SHOW server_version')->fetchColumn();
$settingsStorageWritable = is_dir(dirname(__DIR__) . '/storage') && is_writable(dirname(__DIR__) . '/storage');
$settingsLastUpdate = $pdo->query('SELECT MAX(updated_at) FROM system_settings')->fetchColumn() ?: null;
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $userThemeClass ?>" data-portal-theme="<?= htmlspecialchars($portalTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Documentos - <?= htmlspecialchars($appName) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        darkMode: 'class',
        theme: {
          extend: {
            colors: {
              graphite: {
                950: '#171717',
                900: '#212121',
                800: '#2f2f2f',
                700: '#383838',
                600: '#424242'
              }
            }
          }
        }
      }
    </script>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('dark');
                document.documentElement.classList.remove('light');
            } else if (savedTheme === 'light') {
                document.documentElement.classList.remove('dark');
                document.documentElement.classList.add('light');
            }
        })();
    </script>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/permissions.css">
    <link rel="stylesheet" href="../assets/code-snippets.css">
    <script defer src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.11.1/build/highlight.min.js"></script>
    <script defer src="../assets/code-snippets.js"></script>
    <!-- GridStack.js (Base para o Editor Visual) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gridstack@7.3.0/dist/gridstack.min.css">
    <script src="https://cdn.jsdelivr.net/npm/gridstack@7.3.0/dist/gridstack-all.js"></script>
    <style>
        /* Estilos e Transições de Layout para Sidebars */
        #sidebar-menu {
            transition: width 200ms cubic-bezier(0.4, 0, 0.2, 1), transform 200ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* MODO COMPACTO DO MENU PRINCIPAL (4rem / w-16) */
        #sidebar-menu.sidebar-compact {
            width: 4rem !important;
        }
        #sidebar-menu.sidebar-compact .menu-label,
        #sidebar-menu.sidebar-compact .menu-section-title,
        #sidebar-menu.sidebar-compact .menu-badge,
        #sidebar-menu.sidebar-compact .user-info-text,
        #sidebar-menu.sidebar-compact .submenu-arrow,
        #sidebar-menu.sidebar-compact .brand-text {
            display: none !important;
        }
        #sidebar-menu.sidebar-compact .menu-item-content {
            justify-content: center !important;
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }
        #sidebar-menu.sidebar-compact .submenu-container {
            display: none !important;
        }

        /* ============================================================
           NOVO CONTEÚDO — SELETOR SEGMENTADO DE TIPO
           ============================================================ */
        .nc-type-btn {
            color: #64748b; /* slate-500 */
            background: transparent;
        }
        .dark .nc-type-btn {
            color: #b4b4b4;
        }
        .nc-type-btn:hover {
            color: #1e293b;
            background: rgba(255,255,255,0.7);
        }
        .dark .nc-type-btn:hover {
            color: #ececec;
            background: rgba(255, 255, 255, 0.08);
        }
        .nc-type-btn.nc-type-active {
            color: #0f172a;
            background: #ffffff;
            box-shadow: 0 1px 3px 0 rgba(0,0,0,0.12), 0 1px 2px -1px rgba(0,0,0,0.10);
        }
        .dark .nc-type-btn.nc-type-active {
            color: #ececec;
            background: #3a3a3a;
            box-shadow: 0 4px 12px 0 rgba(0,0,0,0.28);
        }

        /* Painel de formulário — transição suave */
        .nc-panel {
            animation: ncFadeIn 0.15s ease-out;
        }
        @keyframes ncFadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Radio labels para tipo de conteúdo do documento (Arquivo/Texto/Link) */
        .doc-type-btn {
            color: #64748b;
            background: transparent;
        }
        .dark .doc-type-btn {
            color: #b4b4b4;
        }
        .doc-type-btn:has(input:checked) {
            color: #0f172a;
            background: #ffffff;
            box-shadow: 0 1px 3px 0 rgba(0,0,0,0.12);
        }
        .dark .doc-type-btn:has(input:checked) {
            color: #ececec;
            background: #3a3a3a;
        }
        .doc-type-btn:hover {
            color: #1e293b;
        }

        /* Visão Geral — cartões com hierarquia visual e foco acessível */
        .dashboard-panel {
            border-radius: 0.875rem;
        }
        .dashboard-link-card {
            position: relative;
            overflow: hidden;
        }
        .dashboard-link-card::after {
            content: '';
            position: absolute;
            inset: auto 1rem 0;
            height: 2px;
            background: currentColor;
            opacity: 0;
            transition: opacity 150ms ease;
        }
        .dashboard-link-card:hover::after,
        .dashboard-link-card:focus-visible::after {
            opacity: .72;
        }
        .dashboard-link-card:focus-visible,
        .dashboard-period-link:focus-visible {
            outline: 2px solid #10a37f;
            outline-offset: 2px;
        }
        .dark .dashboard-stat-card {
            background: var(--dg-surface-raised) !important;
            border-color: var(--dg-border) !important;
        }
        .dark .dashboard-stat-card:not(.dashboard-stat-card--primary) .dashboard-stat-label,
        .dark .dashboard-stat-card:not(.dashboard-stat-card--primary) .dashboard-stat-icon {
            color: #b4b4b4 !important;
        }
        .dark .dashboard-stat-card:not(.dashboard-stat-card--primary) .dashboard-stat-icon {
            background: #424242 !important;
        }
        .dark .dashboard-stat-card:not(.dashboard-stat-card--primary) .dashboard-stat-progress {
            background: #424242 !important;
        }
        .dark .dashboard-stat-card:not(.dashboard-stat-card--primary) .dashboard-stat-progress__fill {
            background: #b4b4b4 !important;
        }
        .dark .dashboard-stat-card--primary {
            background: rgba(16, 163, 127, 0.12) !important;
            border-color: rgba(16, 163, 127, 0.32) !important;
        }
    </style>
</head>
<body class="bg-[#f8f9fa] dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 min-h-screen flex flex-col selection:bg-slate-800 selection:text-white dark:selection:bg-slate-200 dark:selection:text-slate-900">
    <?php require __DIR__ . '/../partials/maintenance-banner.php'; ?>

    <?php if (!$isLogged): ?>
        <!-- CARD DE LOGIN OU BLOQUEIO 403 -->
        <div class="min-h-screen flex items-center justify-center p-4">
            <div class="max-w-sm w-full bg-white dark:bg-[#353842] p-6 rounded-md border border-slate-200 dark:border-[#454956] shadow-sm text-center">
                <div class="w-12 h-12 rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold text-xl mx-auto mb-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <h2 class="text-base font-bold text-slate-900 dark:text-slate-100 mb-1">Acesso Administrativo Restrito</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4"><?= htmlspecialchars($accessErrorReason) ?></p>

                <?php if (!empty($login_error)): ?>
                    <div class="bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs p-2.5 rounded-md mb-4">
                        <?= htmlspecialchars($login_error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="index.php" autocomplete="off">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3 text-left">
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Domínio</label>
                        <select name="ad_domain" class="input-minimal w-full text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                            <?php foreach ($availableAdDomains as $domain): ?>
                                <option value="<?= htmlspecialchars($domain) ?>" <?= $selectedAdDomain === $domain ? 'selected' : '' ?>><?= htmlspecialchars($domain === 'SAUDE' ? 'SAÚDE' : $domain) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3 text-left">
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Usuário do AD</label>
                        <input type="text" name="username" required autocomplete="off" autocapitalize="none" spellcheck="false" class="input-minimal w-full text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" placeholder="Ex.: maria.silva">
                    </div>
                    <div class="mb-4 text-left">
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Senha do Active Directory</label>
                        <input type="password" name="password" required autocomplete="new-password" class="input-minimal w-full text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" placeholder="Digite sua senha...">
                    </div>
                    <button type="submit" class="w-full bg-slate-900 dark:bg-white hover:bg-slate-800 dark:hover:bg-slate-100 text-white dark:text-slate-900 font-semibold py-2 rounded-md text-xs transition mb-3">
                        Entrar no Painel &rarr;
                    </button>
                </form>
                <a href="../index.php" class="text-xs text-slate-500 hover:underline">&larr; Voltar para a Área de Consulta Pública</a>
            </div>
        </div>
    <?php else: ?>

        <!-- LAYOUT ADMINISTRATIVO COM SIDEBAR VERTICAL -->
        <div class="flex flex-1 min-h-screen">
            
            <!-- OVERLAY MOBILE -->
            <div id="sidebar-overlay" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-slate-950/40 z-30 hidden md:hidden"></div>

            <!-- SIDEBAR VERTICAL CORPORATIVA -->
            <aside id="sidebar-menu" class="w-64 bg-white dark:bg-[#32353e] border-r border-slate-200 dark:border-[#4a4e5c] flex flex-col justify-between shrink-0 fixed md:sticky top-0 h-screen overflow-y-auto z-40 transition-all duration-200 ease-in-out -translate-x-full md:translate-x-0">
                <div class="sidebar-content flex flex-col justify-between flex-1 overflow-y-auto">
                    <div>
                        <!-- MARCA / CABEÇALHO DA SIDEBAR -->
                        <div class="p-4 border-b border-slate-100 dark:border-[#454956] flex items-center justify-between">
                            <a href="../index.php" class="flex items-center gap-3 text-decoration-none min-w-0" title="Ir ao Portal Público">
                                <?php if ($appLogoUrl): ?>
                                    <img src="../<?= htmlspecialchars($appLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="h-8 w-8 shrink-0 rounded-md border border-slate-200 bg-white object-contain p-0.5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                                <?php else: ?>
                                    <div class="w-8 h-8 rounded-md bg-slate-900 dark:bg-white text-white dark:text-slate-900 flex items-center justify-center font-bold text-xs shadow-xs shrink-0">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                                    </div>
                                <?php endif; ?>
                                <div class="flex flex-col brand-text truncate">
                                    <span class="font-bold text-sm text-slate-900 dark:text-slate-100 leading-tight truncate"><?= htmlspecialchars($appName) ?></span>
                                    <span class="text-[10px] text-slate-400 dark:text-slate-500 font-medium truncate"><?= htmlspecialchars($organizationName) ?></span>
                                </div>
                            </a>
                            <?php $notificationLink = '../notificacoes.php'; require __DIR__ . '/../partials/notification_link.php'; ?>
                            <button type="button" onclick="toggleMobileSidebar()" class="md:hidden text-slate-400 hover:text-slate-600 text-base">✕</button>
                        </div>

                        <!-- MENU DE NAVEGAÇÃO -->
                        <nav class="p-3 space-y-4 text-xs font-medium">
                            <!-- SEÇÃO GESTÃO -->
                            <div>
                                <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 menu-section-title">
                                    GESTÃO
                                </div>

                                <div class="mt-1 space-y-1">
                                    <!-- VISÃO GERAL -->
                                    <a href="index.php?tab=visao_geral" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= $activeTab === 'visao_geral' ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Visão Geral">
                                        <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                        <span class="menu-label">Visão Geral</span>
                                    </a>

                                    <!-- NOVO CONTEÚDO -->
                                    <a href="index.php?tab=novo_documento" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= $activeTab === 'novo_documento' ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Novo Conteúdo">
                                        <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                        <span class="menu-label">Novo Conteúdo</span>
                                    </a>

                                    <!-- DOCUMENTOS -->
                                    <a href="index.php?tab=documentos" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= $activeTab === 'documentos' ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Documentos">
                                        <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                        <span class="menu-label truncate">Documentos</span>
                                        <span class="menu-badge ml-auto text-[10px] px-1.5 py-0.2 rounded-full bg-slate-200 dark:bg-[#454956] text-slate-600 dark:text-slate-300 font-mono">
                                            <?= $totalDocs ?>
                                        </span>
                                    </a>

                                    <!-- EXPANSÍVEL ORGANIZAÇÃO -->
                                    <div>
                                        <button type="button" onclick="toggleSubmenu('submenu-org')" class="menu-item-content w-full flex items-center justify-between px-3 py-2 rounded-md transition text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-[#3e424e]" title="Organização">
                                            <div class="flex items-center gap-2.5">
                                                <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                                <span class="menu-label font-bold">Organização</span>
                                            </div>
                                            <span id="submenu-org-arrow" class="submenu-arrow text-[10px] text-slate-400 transition-transform duration-200 <?= in_array($activeTab, ['editar_estrutura', 'categorias', 'subcategorias', 'assuntos', 'organizacao']) ? 'rotate-90' : '' ?>">▼</span>
                                        </button>

                                        <div id="submenu-org" class="submenu-container pl-4 space-y-1 mt-1 <?= in_array($activeTab, ['editar_estrutura', 'categorias', 'subcategorias', 'assuntos', 'organizacao']) ? '' : 'hidden' ?>">
                                            <a href="index.php?tab=editar_estrutura" class="flex items-center gap-2 px-3 py-1.5 rounded-md transition text-decoration-none <?= $activeTab === 'editar_estrutura' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold shadow-xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#3e424e]' ?>" title="Editor da Árvore">
                                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                                                <span>Editor da Árvore</span>
                                            </a>
                                            <a href="index.php?tab=categorias" class="flex items-center gap-2 px-3 py-1.5 rounded-md transition text-decoration-none <?= $activeTab === 'categorias' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold shadow-xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#3e424e]' ?>" title="Categorias">
                                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                                <span>Categorias</span>
                                            </a>
                                            <a href="index.php?tab=subcategorias" class="flex items-center gap-2 px-3 py-1.5 rounded-md transition text-decoration-none <?= $activeTab === 'subcategorias' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold shadow-xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#3e424e]' ?>" title="Subcategorias">
                                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                                                <span>Subcategorias</span>
                                            </a>
                                            <a href="index.php?tab=assuntos" class="flex items-center gap-2 px-3 py-1.5 rounded-md transition text-decoration-none <?= $activeTab === 'assuntos' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold shadow-xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#3e424e]' ?>" title="Assuntos">
                                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                                <span>Assuntos</span>
                                            </a>
                                        </div>
                                    </div>

                                    <!-- LIXEIRA -->
                                    <a href="index.php?tab=lixeira" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= $activeTab === 'lixeira' ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Lixeira">
                                        <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        <span class="menu-label">Lixeira</span>
                                        <?php if ($totalLixeira > 0): ?>
                                            <span class="menu-badge ml-auto text-[10px] px-1.5 py-0.2 rounded-full bg-red-500/20 text-red-600 dark:text-red-400 font-bold font-mono">
                                                <?= $totalLixeira ?>
                                            </span>
                                        <?php endif; ?>
                                    </a>
                                </div>
                            </div>

                            <!-- SEÇÃO GESTÃO DE ACESSO -->
                            <?php if ($isGlobalAdminCurrent): ?>
                                <div class="pt-3 border-t border-slate-100 dark:border-[#454956]">
                                    <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 menu-section-title">
                                        GESTÃO DE ACESSO
                                    </div>
                                    <div class="mt-1 space-y-1">
                                        <a href="index.php?tab=grupos" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= in_array($activeTab, ['grupos', 'editar_grupo']) ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Equipes">
                                            <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                            <span class="menu-label font-bold">Equipes</span>
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- SEÇÃO SISTEMA -->
                            <div class="pt-3 border-t border-slate-100 dark:border-[#454956]">
                                <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 menu-section-title">
                                    SISTEMA
                                </div>
                                <div class="mt-1 space-y-1">
                                    <a href="index.php?tab=usuarios" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= $activeTab === 'usuarios' ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Gestão de Usuários">
                                        <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                        <span class="menu-label font-bold">Usuários</span>
                                    </a>

                                    <?php if ($isGlobalAdminCurrent): ?>
                                    <a href="index.php?tab=tags" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= $activeTab === 'tags' ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Tags">
                                        <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                        <span class="menu-label font-bold">Tags</span>
                                    </a>
                                    <a href="index.php?tab=configuracoes" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= $activeTab === 'configuracoes' ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Configurações">
                                        <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        <span class="menu-label font-bold">Configurações</span>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </nav>
                    </div>

                    <!-- CARD DE USUÁRIO LOGADO E BOTÃO DE RECOLHER -->
                    <div class="p-3 border-t border-slate-100 dark:border-[#454956] space-y-2">
                        <div class="p-2 rounded-md bg-slate-50 dark:bg-[#2c2e33] flex items-center justify-between border border-slate-200/60 dark:border-[#454956]">
                            <div class="flex items-center gap-2 overflow-hidden">
                                <div class="w-6 h-6 rounded-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold text-[11px] flex items-center justify-center shrink-0">
                                    <?= mb_strtoupper(mb_substr($loggedUser['nome'] ?? 'A', 0, 1)) ?>
                                </div>
                                <div class="truncate text-xs user-info-text">
                                    <p class="font-semibold text-slate-900 dark:text-slate-100 truncate leading-tight"><?= htmlspecialchars($loggedUser['nome'] ?? 'Administrador') ?></p>
                                    <p class="text-[10px] text-slate-400 leading-tight"><?= htmlspecialchars($adminAccessLabel) ?></p>
                                </div>
                            </div>
                            <a href="index.php?action=logout" class="text-slate-400 hover:text-red-500 text-xs font-bold px-1" title="Sair do painel">✕</a>
                        </div>

                        <!-- BOTÃO DISCRETO PARA ALTERNAR RECOLHIMENTO DA SIDEBAR -->
                        <button type="button" onclick="toggleMainSidebar()" class="menu-item-content w-full flex items-center justify-center gap-2 p-2 rounded-md bg-slate-100 dark:bg-[#353842] text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-[#3e424e] transition border border-slate-200 dark:border-[#454956]" title="Recolher / Expandir Menu">
                            <svg class="w-4 h-4 transform transition-transform duration-200 shrink-0" id="main-dock-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
                            <span class="menu-label text-xs font-semibold">Recolher menu</span>
                        </button>
            </aside>

            <!-- ÁREA PRINCIPAL DA GESTÃO -->
            <main class="flex-1 min-w-0 p-4 sm:p-8 overflow-y-auto">

                <div class="md:hidden flex items-center justify-between mb-4 pb-3 border-b border-slate-200 dark:border-[#454956]">
                    <button type="button" onclick="toggleMobileSidebar()" class="flex items-center gap-2 px-3 py-1.5 rounded-md bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] text-xs font-semibold text-slate-700 dark:text-slate-200 shadow-xs">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        <span>Menu Administração</span>
                    </button>
                </div>

                <!-- ALERTAS E MENSAGENS FEEDBACK -->
                <?php if (isset($_GET['msg'])): ?>
                    <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-400 text-xs p-3 rounded-md mb-6 shadow-xs">
                        <?php
                            if ($_GET['msg'] === 'doc_created') echo "✓ Documento criado com sucesso!";
                            if ($_GET['msg'] === 'doc_updated') echo "✓ Conteúdo e metadados atualizados com sucesso!";
                            if ($_GET['msg'] === 'batch_docs_created') echo "✓ " . (int)($_GET['count'] ?? 0) . " documento(s) criado(s) com sucesso!";
                            if ($_GET['msg'] === 'docs_published') echo "✓ " . (int)($_GET['count'] ?? 0) . " documento(s) publicado(s) com sucesso!";
                            if ($_GET['msg'] === 'docs_drafted') echo "✓ " . (int)($_GET['count'] ?? 0) . " documento(s) movido(s) para rascunho.";
                            if ($_GET['msg'] === 'docs_trashed') echo "✓ " . (int)($_GET['count'] ?? 0) . " documento(s) movido(s) para a lixeira.";
                            if ($_GET['msg'] === 'docs_workflow_updated') echo "✓ " . (int)($_GET['count'] ?? 0) . " documento(s) atualizado(s) no fluxo editorial.";
                            if ($_GET['msg'] === 'workflow_updated') echo "✓ Fluxo editorial atualizado com sucesso.";
                            if ($_GET['msg'] === 'layout_saved') echo "✓ Disposição visual e ordem dos conteúdos salvas com sucesso!";
                            if ($_GET['msg'] === 'file_replaced') echo "✓ Arquivo substituído com sucesso!";
                            if ($_GET['msg'] === 'moved_to_trash') echo "Documento movido para a lixeira.";
                            if ($_GET['msg'] === 'restored') echo "✓ Documento restaurado da lixeira com sucesso!";
                            if ($_GET['msg'] === 'perm_deleted') echo "Documento excluído permanentemente.";
                            if ($_GET['msg'] === 'category_saved') echo "✓ Categoria criada com sucesso!";
                            if ($_GET['msg'] === 'category_deleted') echo "Categoria removida com sucesso!";
                            if ($_GET['msg'] === 'subcategory_saved') echo "✓ Subcategoria criada com sucesso!";
                            if ($_GET['msg'] === 'subcategory_deleted') echo "Subcategoria removida com sucesso!";
                            if ($_GET['msg'] === 'subject_saved') echo "✓ Assunto criado com sucesso!";
                            if ($_GET['msg'] === 'subject_deleted') echo "Assunto removido com sucesso!";
                            if ($_GET['msg'] === 'user_imported') echo "✓ Usuário localizado no Active Directory e importado para " . htmlspecialchars($appName) . ".";
                            if ($_GET['msg'] === 'settings_saved') echo "✓ Configurações do sistema atualizadas.";
                            if ($_GET['msg'] === 'tag_catalog_saved') echo "✓ Catálogo de tags atualizado.";
                            if ($_GET['msg'] === 'direct_user_access_saved') echo "✓ Acesso individual salvo e o usuário foi notificado.";
                            if ($_GET['msg'] === 'direct_user_access_removed') echo "✓ Acesso individual removido.";
                            if ($_GET['msg'] === 'team_created') echo "✓ Equipe criada com sucesso.";
                            if ($_GET['msg'] === 'team_updated') echo "✓ Equipe atualizada com sucesso.";
                            if ($_GET['msg'] === 'team_status_updated') echo "✓ Status da equipe atualizado.";
                            if ($_GET['msg'] === 'team_deleted') echo "✓ Equipe excluída e suas permissões foram auditadas.";
                            if ($_GET['msg'] === 'team_member_added') echo "✓ Usuário adicionado à equipe.";
                            if ($_GET['msg'] === 'team_member_removed') echo "✓ Usuário removido da equipe sem excluir seu cadastro.";
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errorMessage)): ?>
                    <div class="bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs p-3 rounded-md mb-6 shadow-xs font-medium">
                        Atenção: <?= htmlspecialchars($errorMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'visao_geral'): ?>
                    <?php if ($isGlobalAdminCurrent && $globalDashboard): ?>
                        <?php
                            $dashboardUsers = $globalDashboard['users'];
                            $dashboardStructure = $globalDashboard['structure'];
                            $dashboardAccess = $globalDashboard['access'];
                            $dashboardContent = $globalDashboard['content'];
                            $dashboardUsage = $globalDashboard['usage'];
                            $categoryMaxDocuments = max(1, ...array_map(
                                static fn(array $item): int => (int)$item['documents_total'],
                                $globalDashboard['categories'] ?: [['documents_total' => 0]]
                            ));
                            $dailyMaxDocuments = max(1, ...array_map(
                                static fn(array $item): int => (int)$item['documents_created'],
                                $globalDashboard['activity_by_day'] ?: [['documents_created' => 0]]
                            ));
                            $usageDailyMax = max(1, ...array_map(
                                static fn(array $item): int => (int)$item['events_total'],
                                $globalDashboard['usage_by_day'] ?: [['events_total' => 0]]
                            ));
                            $publicationDailyMax = max(1, ...array_map(
                                static fn(array $item): int => (int)$item['publications_total'],
                                $globalDashboard['publications_by_day'] ?: [['publications_total' => 0]]
                            ));
                        ?>
                        <?php
                            $dashboardPeriodLabel = (int)$globalDashboard['period_days'] . ' dias';
                            $dashboardActiveUserRate = (int)$dashboardUsers['total'] > 0
                                ? round(((int)$dashboardUsers['active'] / (int)$dashboardUsers['total']) * 100)
                                : 0;
                            $dashboardPublishedRate = (int)$dashboardContent['total'] > 0
                                ? round(((int)$dashboardContent['published'] / (int)$dashboardContent['total']) * 100)
                                : 0;
                            $dashboardAttentionItems = [];
                            if ((int)$dashboardUsers['never_logged'] > 0) {
                                $dashboardAttentionItems[] = [
                                    'value' => (int)$dashboardUsers['never_logged'],
                                    'title' => 'Primeiro acesso pendente',
                                    'description' => 'usuários nunca acessaram o sistema',
                                    'url' => 'index.php?tab=usuarios',
                                    'tone' => 'amber',
                                ];
                            }
                            if ((int)$dashboardContent['draft'] > 0) {
                                $dashboardAttentionItems[] = [
                                    'value' => (int)$dashboardContent['draft'],
                                    'title' => 'Rascunhos para revisar',
                                    'description' => 'documentos ainda não publicados',
                                    'url' => 'index.php?tab=documentos&filter_status=draft',
                                    'tone' => 'blue',
                                ];
                            }
                            if ((int)$dashboardContent['review'] > 0) {
                                $dashboardAttentionItems[] = [
                                    'value' => (int)$dashboardContent['review'],
                                    'title' => 'Aguardando aprovação',
                                    'description' => 'documentos enviados para revisão',
                                    'url' => 'index.php?tab=documentos&filter_status=review',
                                    'tone' => 'blue',
                                ];
                            }
                            if ((int)$dashboardContent['inactive'] > 0) {
                                $dashboardAttentionItems[] = [
                                    'value' => (int)$dashboardContent['inactive'],
                                    'title' => 'Itens na lixeira',
                                    'description' => 'documentos inativos aguardando decisão',
                                    'url' => 'index.php?tab=lixeira',
                                    'tone' => 'rose',
                                ];
                            }
                            $dashboardDailyTotal = array_sum(array_map(
                                static fn(array $item): int => (int)$item['documents_created'],
                                $globalDashboard['activity_by_day']
                            ));
                        ?>
                        <div class="space-y-5">
                            <header class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                                <div class="flex flex-col justify-between gap-5 xl:flex-row xl:items-center">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Super Admin</span>
                                            <span class="text-[11px] text-slate-400">Indicadores consolidados de <?= htmlspecialchars($appName) ?></span>
                                        </div>
                                        <h1 class="mt-3 text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Visão Geral da Gestão de Documentos</h1>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Acompanhe o que está saudável, o que precisa de atenção e as últimas movimentações.</p>
                                    </div>
                                    <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center xl:justify-end">
                                        <nav aria-label="Período dos indicadores" class="inline-flex rounded-lg bg-slate-100 p-1 dark:bg-[#2c2e33]">
                                            <?php foreach ($dashboardPeriodOptions as $periodOption): ?>
                                                <?php $isCurrentPeriod = $dashboardPeriodDays === $periodOption; ?>
                                                <a href="index.php?tab=visao_geral&amp;dash_period=<?= $periodOption ?>" class="dashboard-period-link rounded-md px-2.5 py-1.5 text-[11px] font-bold text-decoration-none transition <?= $isCurrentPeriod ? 'bg-white text-slate-900 shadow-xs dark:bg-[#454956] dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' ?>"<?= $isCurrentPeriod ? ' aria-current="page"' : '' ?>><?= $periodOption ?> dias</a>
                                            <?php endforeach; ?>
                                        </nav>
                                        <div class="flex flex-wrap gap-2">
                                            <a href="index.php?tab=novo_documento" class="rounded-md bg-slate-900 px-3.5 py-2 text-xs font-bold text-white text-decoration-none transition hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">Novo conteúdo</a>
                                            <a href="index.php?tab=usuarios" class="rounded-md border border-slate-200 px-3.5 py-2 text-xs font-bold text-slate-700 text-decoration-none transition hover:bg-slate-50 dark:border-[#454956] dark:text-slate-200 dark:hover:bg-[#454956]">Gerir usuários</a>
                                        </div>
                                    </div>
                                </div>
                            </header>

                            <section aria-labelledby="dashboard-attention-title" class="dashboard-panel border border-slate-200 bg-white p-4 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                                    <div class="shrink-0 lg:w-48">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Prioridades</p>
                                        <h2 id="dashboard-attention-title" class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Central de atenção</h2>
                                    </div>
                                    <?php if (empty($dashboardAttentionItems)): ?>
                                        <div class="flex min-h-14 flex-1 items-center gap-3 rounded-lg bg-emerald-500/8 px-3 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-sm font-bold text-white">✓</span><span><strong>Nenhuma pendência prioritária.</strong> Contas e documentos estão em situação regular.</span></div>
                                    <?php else: ?>
                                        <div class="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-<?= min(3, count($dashboardAttentionItems)) ?>">
                                            <?php foreach ($dashboardAttentionItems as $attentionItem): ?>
                                                <?php $attentionClasses = ['amber' => 'border-amber-500/20 bg-amber-500/7 text-amber-900 dark:text-amber-200', 'blue' => 'border-blue-500/20 bg-blue-500/7 text-blue-900 dark:text-blue-200', 'rose' => 'border-rose-500/20 bg-rose-500/7 text-rose-900 dark:text-rose-200'][$attentionItem['tone']] ?? 'border-slate-200 bg-slate-50 text-slate-800'; ?>
                                                <a href="<?= htmlspecialchars($attentionItem['url']) ?>" class="dashboard-link-card rounded-lg border px-3 py-2.5 text-decoration-none transition hover:-translate-y-px hover:shadow-sm <?= $attentionClasses ?>">
                                                    <span class="font-mono text-lg font-bold"><?= (int)$attentionItem['value'] ?></span>
                                                    <span class="ml-1.5 text-xs font-bold"><?= htmlspecialchars($attentionItem['title']) ?></span>
                                                    <span class="mt-0.5 block text-[10px] opacity-70"><?= htmlspecialchars($attentionItem['description']) ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </section>

                            <section aria-label="Indicadores principais" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <a href="index.php?tab=usuarios" class="dashboard-link-card dashboard-stat-card dashboard-stat-card--primary rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4 text-decoration-none shadow-xs transition hover:-translate-y-0.5 hover:shadow-sm dark:bg-emerald-500/10">
                                    <div class="flex items-start justify-between gap-3"><span class="dashboard-stat-label text-[11px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">Pessoas cadastradas</span><span class="dashboard-stat-icon flex h-7 w-7 items-center justify-center rounded-md bg-emerald-500/15 text-emerald-700 dark:text-emerald-300"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m18 0v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75M12 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg></span></div>
                                    <span class="mt-3 block font-mono text-3xl font-bold text-slate-900 dark:text-slate-100"><?= (int)$dashboardUsers['total'] ?></span>
                                    <div class="dashboard-stat-progress mt-3 h-1.5 overflow-hidden rounded-full bg-emerald-500/15"><div class="dashboard-stat-progress__fill h-full rounded-full bg-emerald-500" style="width: <?= $dashboardActiveUserRate ?>%"></div></div>
                                    <span class="mt-2 block text-[11px] text-slate-500"><?= (int)$dashboardUsers['active'] ?> ativas · <?= $dashboardActiveUserRate ?>% da base</span>
                                </a>
                                <a href="index.php?tab=documentos&amp;filter_status=published" class="dashboard-link-card dashboard-stat-card rounded-xl border border-blue-500/20 bg-blue-500/5 p-4 text-decoration-none shadow-xs transition hover:-translate-y-0.5 hover:shadow-sm dark:bg-blue-500/10">
                                    <div class="flex items-start justify-between gap-3"><span class="dashboard-stat-label text-[11px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300">Acervo publicado</span><span class="dashboard-stat-icon flex h-7 w-7 items-center justify-center rounded-md bg-blue-500/15 text-blue-700 dark:text-blue-300"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2m5-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span></div>
                                    <span class="mt-3 block font-mono text-3xl font-bold text-slate-900 dark:text-slate-100"><?= (int)$dashboardContent['published'] ?></span>
                                    <div class="dashboard-stat-progress mt-3 h-1.5 overflow-hidden rounded-full bg-blue-500/15"><div class="dashboard-stat-progress__fill h-full rounded-full bg-blue-500" style="width: <?= $dashboardPublishedRate ?>%"></div></div>
                                    <span class="mt-2 block text-[11px] text-slate-500"><?= $dashboardPublishedRate ?>% dos <?= (int)$dashboardContent['total'] ?> itens estão disponíveis</span>
                                </a>
                                <a href="index.php?tab=documentos" class="dashboard-link-card dashboard-stat-card rounded-xl border border-violet-500/20 bg-violet-500/5 p-4 text-decoration-none shadow-xs transition hover:-translate-y-0.5 hover:shadow-sm dark:bg-violet-500/10">
                                    <div class="flex items-start justify-between gap-3"><span class="dashboard-stat-label text-[11px] font-bold uppercase tracking-wider text-violet-700 dark:text-violet-300">Novos conteúdos</span><span class="dashboard-stat-icon flex h-7 w-7 items-center justify-center rounded-md bg-violet-500/15 text-violet-700 dark:text-violet-300"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></span></div>
                                    <span class="mt-3 block font-mono text-3xl font-bold text-slate-900 dark:text-slate-100"><?= (int)$dashboardContent['created_in_period'] ?></span>
                                    <span class="mt-6 block text-[11px] text-slate-500">Criados nos últimos <?= htmlspecialchars($dashboardPeriodLabel) ?></span>
                                </a>
                                <a href="index.php?tab=documentos" class="dashboard-link-card dashboard-stat-card rounded-xl border border-slate-300 bg-white p-4 text-decoration-none shadow-xs transition hover:-translate-y-0.5 hover:shadow-sm dark:border-[#454956] dark:bg-[#353842]">
                                    <div class="flex items-start justify-between gap-3"><span class="dashboard-stat-label text-[11px] font-bold uppercase tracking-wider text-slate-500">Atualizações</span><span class="dashboard-stat-icon flex h-7 w-7 items-center justify-center rounded-md bg-slate-100 text-slate-600 dark:bg-[#2c2e33] dark:text-slate-300"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span></div>
                                    <span class="mt-3 block font-mono text-3xl font-bold text-slate-900 dark:text-slate-100"><?= (int)$dashboardContent['updated_in_period'] ?></span>
                                    <span class="mt-6 block text-[11px] text-slate-500">Alterados nos últimos <?= htmlspecialchars($dashboardPeriodLabel) ?></span>
                                </a>
                            </section>

                            <div class="grid grid-cols-1 gap-5 xl:grid-cols-12">
                                <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-7">
                                    <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Atividade documental</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Novos conteúdos nos últimos <?= htmlspecialchars($dashboardPeriodLabel) ?></h2></div><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500 dark:bg-[#2c2e33]"><?= $dashboardDailyTotal ?> no período</span></div>
                                    <div class="mt-5 flex h-36 items-end gap-1 border-b border-slate-100 pb-1 dark:border-[#454956]">
                                        <?php foreach ($globalDashboard['activity_by_day'] as $dailyActivity): ?>
                                            <?php $dailyHeight = max(4, round(((int)$dailyActivity['documents_created'] / $dailyMaxDocuments) * 100)); ?>
                                            <div class="group relative flex h-full flex-1 items-end" title="<?= date('d/m/Y', strtotime($dailyActivity['activity_day'])) ?>: <?= (int)$dailyActivity['documents_created'] ?> documento(s)"><div class="w-full rounded-t-sm bg-slate-300 transition group-hover:bg-emerald-500 dark:bg-slate-600" style="height: <?= $dailyHeight ?>%"></div></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="mt-3 flex items-center justify-between text-[10px] text-slate-400"><span><?= date('d/m', strtotime($globalDashboard['activity_by_day'][0]['activity_day'] ?? 'today')) ?></span><span><?= (int)$dashboardContent['updated_in_period'] ?> atualizações no período</span><span>Hoje</span></div>
                                    <div class="mt-5 grid grid-cols-4 gap-2 border-t border-slate-100 pt-4 text-center text-[10px] dark:border-[#454956]"><div class="rounded-md bg-slate-50 p-2 dark:bg-[#2c2e33]"><span class="block text-slate-400">Arquivo</span><span class="font-mono font-bold text-slate-700 dark:text-slate-200"><?= (int)$dashboardContent['files'] ?></span></div><div class="rounded-md bg-slate-50 p-2 dark:bg-[#2c2e33]"><span class="block text-slate-400">Texto</span><span class="font-mono font-bold text-slate-700 dark:text-slate-200"><?= (int)$dashboardContent['texts'] ?></span></div><div class="rounded-md bg-slate-50 p-2 dark:bg-[#2c2e33]"><span class="block text-slate-400">Link</span><span class="font-mono font-bold text-slate-700 dark:text-slate-200"><?= (int)$dashboardContent['links'] ?></span></div><div class="rounded-md bg-slate-50 p-2 dark:bg-[#2c2e33]"><span class="block text-slate-400">Código</span><span class="font-mono font-bold text-slate-700 dark:text-slate-200"><?= (int)$dashboardContent['codes'] ?></span></div></div>
                                </section>

                                <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-5">
                                    <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Pessoas e identidade</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Base de usuários</h2></div><a href="index.php?tab=usuarios" class="text-xs font-bold text-slate-600 hover:underline dark:text-slate-300">Ver usuários &rarr;</a></div>
                                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4"><div class="rounded-md bg-slate-50 p-2.5 dark:bg-[#2c2e33]"><span class="block text-[10px] font-semibold text-slate-400">AD</span><span class="mt-1 block font-mono text-lg font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardUsers['active_directory'] ?></span></div><div class="rounded-md bg-slate-50 p-2.5 dark:bg-[#2c2e33]"><span class="block text-[10px] font-semibold text-slate-400">Local</span><span class="mt-1 block font-mono text-lg font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardUsers['local_auth'] ?></span></div><div class="rounded-md bg-slate-50 p-2.5 dark:bg-[#2c2e33]"><span class="block text-[10px] font-semibold text-slate-400">Login <?= (int)$globalDashboard['period_days'] ?>d</span><span class="mt-1 block font-mono text-lg font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardUsers['logins_in_period'] ?></span></div><div class="rounded-md bg-slate-50 p-2.5 dark:bg-[#2c2e33]"><span class="block text-[10px] font-semibold text-slate-400">Nunca acessaram</span><span class="mt-1 block font-mono text-lg font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardUsers['never_logged'] ?></span></div></div>
                                    <div class="mt-4 border-t border-slate-100 pt-3 dark:border-[#454956]"><div class="flex items-center justify-between"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Últimas autenticações</p><span class="text-[10px] text-slate-400"><?= (int)$dashboardUsers['logins_in_period'] ?> no período</span></div><div class="mt-1 divide-y divide-slate-100 dark:divide-[#454956]"><?php if (empty($globalDashboard['logins'])): ?><p class="py-4 text-center text-xs text-slate-400">Ainda não há acessos registrados.</p><?php endif; ?><?php foreach (array_slice($globalDashboard['logins'], 0, 4) as $loginEvent): ?><div class="flex items-center justify-between gap-3 py-2"><div class="min-w-0"><span class="block truncate text-xs font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($loginEvent['name']) ?></span><span class="block truncate text-[10px] text-slate-400">@<?= htmlspecialchars($loginEvent['username']) ?> · <?= $loginEvent['auth_source'] === 'ad' ? 'AD' : 'Local' ?></span></div><time class="shrink-0 text-[10px] text-slate-400"><?= date('d/m H:i', strtotime($loginEvent['last_login_at'])) ?></time></div><?php endforeach; ?></div></div>
                                </section>
                            </div>

                            <div class="grid grid-cols-1 gap-5 xl:grid-cols-12">
                                <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-7">
                                    <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Acervo e estrutura</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Distribuição por categoria</h2></div><a href="index.php?tab=editar_estrutura" class="text-xs font-bold text-slate-600 hover:underline dark:text-slate-300">Abrir árvore &rarr;</a></div>
                                    <?php if (empty($globalDashboard['categories'])): ?>
                                        <p class="py-10 text-center text-xs text-slate-400">Ainda não há categorias cadastradas.</p>
                                    <?php else: ?>
                                        <div class="mt-4 space-y-3">
                                            <?php foreach (array_slice($globalDashboard['categories'], 0, 6) as $categoryReport): ?>
                                                <?php $categoryWidth = max(3, round(((int)$categoryReport['documents_total'] / $categoryMaxDocuments) * 100)); ?>
                                                <a href="index.php?tab=documentos&amp;filter_cat=<?= urlencode($categoryReport['name']) ?>" class="group block text-decoration-none"><div class="mb-1.5 flex items-center justify-between gap-4 text-xs"><span class="truncate font-semibold text-slate-700 group-hover:underline dark:text-slate-200"><?= htmlspecialchars($categoryReport['name']) ?></span><span class="shrink-0 font-mono text-slate-500"><?= (int)$categoryReport['documents_total'] ?> doc<?= (int)$categoryReport['documents_total'] === 1 ? '' : 's' ?></span></div><div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-[#2c2e33]"><div class="h-full rounded-full bg-slate-700 transition group-hover:bg-emerald-500 dark:bg-slate-300" style="width: <?= $categoryWidth ?>%"></div></div></a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="mt-5 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-xs dark:border-[#454956] sm:grid-cols-4"><div><span class="block text-[10px] text-slate-400">Categorias ativas</span><span class="font-mono font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardStructure['categories_active'] ?>/<?= (int)$dashboardStructure['categories_total'] ?></span></div><div><span class="block text-[10px] text-slate-400">Subcategorias</span><span class="font-mono font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardStructure['subcategories_total'] ?></span></div><div><span class="block text-[10px] text-slate-400">Assuntos</span><span class="font-mono font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardStructure['subjects_total'] ?></span></div><div><span class="block text-[10px] text-slate-400">Criados no período</span><span class="font-mono font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardContent['created_in_period'] ?></span></div></div>
                                </section>

                                <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-5">
                                    <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Governança de acesso</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Equipes e permissões</h2></div><a href="index.php?tab=grupos" class="text-xs font-bold text-slate-600 hover:underline dark:text-slate-300">Gerir equipes &rarr;</a></div>
                                    <div class="mt-4 grid grid-cols-2 gap-2"><div class="rounded-md border border-slate-100 p-3 dark:border-[#454956]"><span class="block text-[10px] text-slate-400">Equipes ativas</span><span class="mt-1 block font-mono text-xl font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardAccess['teams_active'] ?>/<?= (int)$dashboardAccess['teams_total'] ?></span></div><div class="rounded-md border border-slate-100 p-3 dark:border-[#454956]"><span class="block text-[10px] text-slate-400">Vínculos</span><span class="mt-1 block font-mono text-xl font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardAccess['memberships_total'] ?></span></div><div class="rounded-md border border-slate-100 p-3 dark:border-[#454956]"><span class="block text-[10px] text-slate-400">Regras configuradas</span><span class="mt-1 block font-mono text-xl font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardAccess['permission_rules_total'] ?></span></div><div class="rounded-md border border-slate-100 p-3 dark:border-[#454956]"><span class="block text-[10px] text-slate-400">Auditorias <?= (int)$globalDashboard['period_days'] ?>d</span><span class="mt-1 block font-mono text-xl font-bold text-slate-800 dark:text-slate-100"><?= (int)($dashboardAccess['permission_audit_in_period'] ?? 0) ?></span></div></div>
                                    <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4 text-[10px] font-semibold dark:border-[#454956]"><span class="rounded bg-violet-500/10 px-2 py-1 text-violet-700 dark:text-violet-300"><?= (int)$dashboardAccess['admin_rules'] ?> ADMIN</span><span class="rounded bg-amber-500/10 px-2 py-1 text-amber-700 dark:text-amber-300"><?= (int)$dashboardAccess['edit_rules'] ?> EDIT</span><span class="rounded bg-blue-500/10 px-2 py-1 text-blue-700 dark:text-blue-300"><?= (int)$dashboardAccess['view_rules'] ?> VIEW</span><span class="rounded bg-slate-100 px-2 py-1 text-slate-600 dark:bg-[#2c2e33] dark:text-slate-300"><?= (int)($dashboardAccess['permission_audit_total'] ?? 0) ?> eventos registrados</span></div>
                                </section>
                            </div>

                            <div class="grid grid-cols-1 gap-5 xl:grid-cols-12">
                                <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-7">
                                    <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Auditoria de segurança</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Alterações de permissões</h2></div><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500 dark:bg-[#2c2e33]"><?= (int)($dashboardAccess['permission_audit_total'] ?? 0) ?> evento<?= (int)($dashboardAccess['permission_audit_total'] ?? 0) === 1 ? '' : 's' ?></span></div>
                                    <?php if (!$globalDashboard['permission_audit_available']): ?>
                                        <div class="mt-4 rounded-lg border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">A migração de auditoria de permissões ainda não foi aplicada.</div>
                                    <?php elseif (empty($globalDashboard['permission_audit'])): ?>
                                        <p class="mt-6 text-center text-xs text-slate-400">Nenhuma alteração de permissão foi registrada ainda.</p>
                                    <?php else: ?>
                                        <div class="mt-4 divide-y divide-slate-100 dark:divide-[#454956]">
                                            <?php foreach ($globalDashboard['permission_audit'] as $auditEvent): ?>
                                                <?php $auditActionLabel = ['PERMISSION_CREATED' => 'Permissão concedida', 'PERMISSION_CHANGED' => 'Permissão alterada', 'PERMISSION_REMOVED' => 'Permissão removida'][$auditEvent['action']] ?? 'Permissão atualizada'; $auditPermission = $auditEvent['new_permission'] ?: $auditEvent['old_permission'] ?: '—'; $auditDotClass = $auditEvent['action'] === 'PERMISSION_REMOVED' ? 'bg-rose-500' : ($auditEvent['action'] === 'PERMISSION_CHANGED' ? 'bg-amber-500' : 'bg-emerald-500'); ?>
                                                <div class="flex items-start justify-between gap-4 py-3"><div class="flex min-w-0 gap-2.5"><span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full <?= $auditDotClass ?>"></span><div class="min-w-0"><span class="block text-xs font-semibold text-slate-700 dark:text-slate-200"><?= $auditActionLabel ?> <span class="font-mono text-[10px] text-slate-400"><?= strtoupper(htmlspecialchars($auditPermission)) ?></span></span><span class="mt-0.5 block text-[10px] text-slate-400">Por <?= htmlspecialchars($auditEvent['actor_name'] ?? 'Sistema') ?> · <?= htmlspecialchars($auditEvent['principal_name']) ?> em <?= htmlspecialchars($auditEvent['resource_name']) ?></span></div></div><time class="shrink-0 text-[10px] text-slate-400"><?= date('d/m H:i', strtotime($auditEvent['created_at'])) ?></time></div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </section>

                                <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-5">
                                    <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Atividade do acervo</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Documentos alterados</h2></div><a href="index.php?tab=documentos" class="text-xs font-bold text-slate-600 hover:underline dark:text-slate-300">Ver todos &rarr;</a></div>
                                    <div class="mt-4 divide-y divide-slate-100 dark:divide-[#454956]"><?php if (empty($globalDashboard['documents'])): ?><p class="py-6 text-center text-xs text-slate-400">Ainda não há documentos para exibir.</p><?php endif; ?><?php foreach ($globalDashboard['documents'] as $documentEvent): ?><a href="index.php?tab=detalhes_documento&amp;id=<?= (int)$documentEvent['id'] ?>" class="block rounded-md px-2 py-3 text-decoration-none transition hover:bg-slate-50 dark:hover:bg-[#2c2e33]"><div class="flex items-start justify-between gap-4"><div class="min-w-0"><span class="block truncate text-xs font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($documentEvent['title']) ?></span><span class="mt-0.5 block truncate text-[10px] text-slate-400"><?= htmlspecialchars($documentEvent['category_name']) ?> &rsaquo; <?= htmlspecialchars($documentEvent['subject_name']) ?> · <?= htmlspecialchars($documentEvent['author_name'] ?? 'Sem autor') ?></span></div><time class="shrink-0 text-[10px] text-slate-400"><?= date('d/m H:i', strtotime($documentEvent['updated_at'])) ?></time></div></a><?php endforeach; ?></div>
                                </section>
                            </div>

                            <section aria-labelledby="usage-audit-title" class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Auditoria de utilização</p>
                                        <h2 id="usage-audit-title" class="mt-1 text-base font-bold text-slate-900 dark:text-slate-100">Como <?= htmlspecialchars($appName) ?> está sendo usado</h2>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Dados reais de acesso, consulta e download nos últimos <?= htmlspecialchars($dashboardPeriodLabel) ?>.</p>
                                    </div>
                                    <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-1 text-[10px] font-bold text-emerald-700 dark:text-emerald-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Atualizado por eventos reais</span>
                                </div>

                                <?php if (!$globalDashboard['usage_audit_available']): ?>
                                    <div class="mt-4 rounded-lg border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">A migração de auditoria de uso ainda não foi aplicada.</div>
                                <?php else: ?>
                                    <div class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
                                        <div class="rounded-lg border border-emerald-500/15 bg-emerald-500/5 p-3"><span class="block text-[10px] font-semibold text-emerald-700 dark:text-emerald-300">Pessoas usando</span><strong class="mt-1 block font-mono text-2xl text-slate-900 dark:text-slate-100"><?= (int)($dashboardUsage['unique_users'] ?? 0) ?></strong><span class="mt-1 block text-[10px] text-slate-500">no período</span></div>
                                        <div class="rounded-lg border border-sky-500/15 bg-sky-500/5 p-3"><span class="block text-[10px] font-semibold text-sky-700 dark:text-sky-300">Ativos agora</span><strong class="mt-1 block font-mono text-2xl text-slate-900 dark:text-slate-100"><?= (int)($dashboardUsage['online_now'] ?? 0) ?></strong><span class="mt-1 block text-[10px] text-slate-500">últimos 15 min</span></div>
                                        <div class="rounded-lg border border-violet-500/15 bg-violet-500/5 p-3"><span class="block text-[10px] font-semibold text-violet-700 dark:text-violet-300">Consultas</span><strong class="mt-1 block font-mono text-2xl text-slate-900 dark:text-slate-100"><?= (int)($dashboardUsage['document_views'] ?? 0) ?></strong><span class="mt-1 block text-[10px] text-slate-500"><?= (int)($dashboardUsage['documents_consulted'] ?? 0) ?> documentos</span></div>
                                        <div class="rounded-lg border border-blue-500/15 bg-blue-500/5 p-3"><span class="block text-[10px] font-semibold text-blue-700 dark:text-blue-300">Downloads</span><strong class="mt-1 block font-mono text-2xl text-slate-900 dark:text-slate-100"><?= (int)($dashboardUsage['downloads'] ?? 0) ?></strong><span class="mt-1 block text-[10px] text-slate-500">arquivos baixados</span></div>
                                        <div class="rounded-lg border border-amber-500/15 bg-amber-500/5 p-3"><span class="block text-[10px] font-semibold text-amber-700 dark:text-amber-300">Buscas</span><strong class="mt-1 block font-mono text-2xl text-slate-900 dark:text-slate-100"><?= (int)($dashboardUsage['searches'] ?? 0) ?></strong><span class="mt-1 block text-[10px] text-slate-500">sem guardar termos</span></div>
                                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-[#454956] dark:bg-[#2c2e33]"><span class="block text-[10px] font-semibold text-slate-500">Eventos totais</span><strong class="mt-1 block font-mono text-2xl text-slate-900 dark:text-slate-100"><?= (int)($dashboardUsage['total_events'] ?? 0) ?></strong><span class="mt-1 block text-[10px] text-slate-500"><?= (int)($dashboardUsage['admin_events'] ?? 0) ?> administrativos</span></div>
                                    </div>
                                <?php endif; ?>
                            </section>

                            <?php if ($globalDashboard['usage_audit_available']): ?>
                                <div class="grid grid-cols-1 gap-5 xl:grid-cols-12">
                                    <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-7">
                                        <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Uso por período</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Acessos e interações diárias</h2></div><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500 dark:bg-[#2c2e33]"><?= (int)($dashboardUsage['total_events'] ?? 0) ?> eventos</span></div>
                                        <div class="mt-5 flex h-36 items-end gap-1 border-b border-slate-100 pb-1 dark:border-[#454956]">
                                            <?php foreach ($globalDashboard['usage_by_day'] as $dailyUsage): ?>
                                                <?php $usageHeight = max(3, round(((int)$dailyUsage['events_total'] / $usageDailyMax) * 100)); ?>
                                                <div class="group relative flex h-full flex-1 items-end" title="<?= date('d/m/Y', strtotime($dailyUsage['activity_day'])) ?>: <?= (int)$dailyUsage['events_total'] ?> eventos · <?= (int)$dailyUsage['document_views'] ?> consultas · <?= (int)$dailyUsage['downloads'] ?> downloads"><div class="w-full rounded-t-sm bg-slate-300 transition group-hover:bg-violet-500 dark:bg-slate-600" style="height: <?= $usageHeight ?>%"></div></div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="mt-3 flex items-center justify-between text-[10px] text-slate-400"><span><?= date('d/m', strtotime($globalDashboard['usage_by_day'][0]['activity_day'] ?? 'today')) ?></span><span>Consultas, downloads e ações administrativas</span><span>Hoje</span></div>
                                    </section>

                                    <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-5">
                                        <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Publicações</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Conteúdos publicados por dia</h2></div><span class="text-[10px] text-slate-400">status publicado</span></div>
                                        <div class="mt-5 flex h-36 items-end gap-1 border-b border-slate-100 pb-1 dark:border-[#454956]">
                                            <?php foreach ($globalDashboard['publications_by_day'] as $dailyPublication): ?>
                                                <?php $publicationHeight = max(3, round(((int)$dailyPublication['publications_total'] / $publicationDailyMax) * 100)); ?>
                                                <div class="group relative flex h-full flex-1 items-end" title="<?= date('d/m/Y', strtotime($dailyPublication['activity_day'])) ?>: <?= (int)$dailyPublication['publications_total'] ?> publicação(ões)"><div class="w-full rounded-t-sm bg-emerald-200 transition group-hover:bg-emerald-500 dark:bg-emerald-800" style="height: <?= $publicationHeight ?>%"></div></div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="mt-3 flex items-center justify-between text-[10px] text-slate-400"><span><?= date('d/m', strtotime($globalDashboard['publications_by_day'][0]['activity_day'] ?? 'today')) ?></span><span><?= (int)$dashboardContent['published'] ?> publicados no acervo</span><span>Hoje</span></div>
                                    </section>
                                </div>

                                <div class="grid grid-cols-1 gap-5 xl:grid-cols-12">
                                    <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-6">
                                        <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Pessoas mais ativas</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Quem está usando o sistema</h2></div><a href="index.php?tab=usuarios" class="text-xs font-bold text-slate-600 hover:underline dark:text-slate-300">Ver usuários &rarr;</a></div>
                                        <?php if (empty($globalDashboard['usage_by_person'])): ?>
                                            <p class="py-8 text-center text-xs text-slate-400">Os acessos aparecerão aqui a partir de agora.</p>
                                        <?php else: ?>
                                            <div class="mt-4 divide-y divide-slate-100 dark:divide-[#454956]">
                                                <?php foreach ($globalDashboard['usage_by_person'] as $personUsage): ?>
                                                    <div class="flex items-center justify-between gap-3 py-3"><div class="min-w-0"><span class="block truncate text-xs font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($personUsage['name']) ?></span><span class="block truncate text-[10px] text-slate-400">@<?= htmlspecialchars($personUsage['username']) ?> · <?= (int)$personUsage['documents_used'] ?> documento(s) usado(s)</span></div><div class="shrink-0 text-right"><strong class="block font-mono text-sm text-slate-800 dark:text-slate-100"><?= (int)$personUsage['events_total'] ?></strong><span class="block text-[10px] text-slate-400"><?= (int)$personUsage['document_views'] ?> consultas · <?= (int)$personUsage['downloads'] ?> downloads</span></div></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </section>

                                    <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-6">
                                        <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Documentos mais consultados</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">O que as pessoas usam</h2></div><a href="index.php?tab=documentos" class="text-xs font-bold text-slate-600 hover:underline dark:text-slate-300">Ver acervo &rarr;</a></div>
                                        <?php if (empty($globalDashboard['usage_by_document'])): ?>
                                            <p class="py-8 text-center text-xs text-slate-400">As consultas e downloads serão consolidados aqui.</p>
                                        <?php else: ?>
                                            <div class="mt-4 divide-y divide-slate-100 dark:divide-[#454956]">
                                                <?php foreach ($globalDashboard['usage_by_document'] as $documentUsage): ?>
                                                    <a href="index.php?tab=detalhes_documento&amp;id=<?= (int)$documentUsage['id'] ?>" class="flex items-center justify-between gap-3 rounded-md py-3 text-decoration-none transition hover:bg-slate-50 dark:hover:bg-[#2c2e33]"><div class="min-w-0"><span class="block truncate text-xs font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($documentUsage['title']) ?></span><span class="block truncate text-[10px] text-slate-400"><?= htmlspecialchars($documentUsage['category_name']) ?> &rsaquo; <?= htmlspecialchars($documentUsage['subcategory_name']) ?> · <?= (int)$documentUsage['users_total'] ?> pessoa(s)</span></div><div class="shrink-0 text-right text-[10px] text-slate-500"><strong class="block font-mono text-sm text-slate-800 dark:text-slate-100"><?= (int)$documentUsage['views'] ?></strong><span><?= (int)$documentUsage['downloads'] ?> downloads<?= (int)$documentUsage['external_opens'] ? ' · ' . (int)$documentUsage['external_opens'] . ' links' : '' ?></span></div></a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </section>
                                </div>

                                <div class="grid grid-cols-1 gap-5 xl:grid-cols-12">
                                    <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-4">
                                        <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Navegação</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Áreas mais acessadas</h2></div>
                                        <?php if (empty($globalDashboard['usage_by_navigation'])): ?>
                                            <p class="py-8 text-center text-xs text-slate-400">Ainda não há navegação registrada.</p>
                                        <?php else: ?>
                                            <div class="mt-4 space-y-3"><?php foreach ($globalDashboard['usage_by_navigation'] as $navigationUsage): ?><div><div class="flex items-center justify-between gap-3 text-xs"><span class="truncate font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($navigationUsage['resource_name']) ?></span><span class="shrink-0 font-mono text-slate-500"><?= (int)$navigationUsage['accesses'] ?></span></div><span class="mt-0.5 block text-[10px] text-slate-400"><?= strtolower((string)$navigationUsage['resource_type']) ?> · <?= (int)$navigationUsage['users_total'] ?> pessoa(s)</span></div><?php endforeach; ?></div>
                                        <?php endif; ?>
                                    </section>

                                    <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-8">
                                        <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Acessos recentes</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Quem acessou o quê</h2></div><span class="text-[10px] text-slate-400">últimos <?= (int)$globalDashboard['period_days'] ?> dias</span></div>
                                        <?php $usageEventLabels = ['login' => 'Entrou no sistema', 'portal_view' => 'Abriu o portal', 'search' => 'Pesquisou no acervo', 'category_view' => 'Abriu categoria', 'subcategory_view' => 'Abriu subcategoria', 'subject_view' => 'Abriu assunto', 'document_view' => 'Consultou documento', 'document_file_view' => 'Visualizou arquivo', 'document_download' => 'Baixou arquivo', 'external_open' => 'Abriu link externo', 'admin_page_view' => 'Acessou o painel', 'admin_action' => 'Executou ação']; ?>
                                        <?php if (empty($globalDashboard['usage_recent_events'])): ?>
                                            <p class="py-8 text-center text-xs text-slate-400">Os eventos recentes aparecerão aqui conforme o sistema for utilizado.</p>
                                        <?php else: ?>
                                            <div class="mt-4 grid grid-cols-1 gap-x-6 divide-y divide-slate-100 dark:divide-[#454956] lg:grid-cols-2 lg:divide-y-0">
                                                <?php foreach ($globalDashboard['usage_recent_events'] as $usageEvent): ?>
                                                    <div class="flex items-start justify-between gap-3 py-2.5"><div class="min-w-0"><span class="block truncate text-xs font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($usageEvent['user_name'] ?? 'Sistema') ?> <span class="font-normal text-slate-400">· <?= htmlspecialchars($usageEventLabels[$usageEvent['event_type']] ?? $usageEvent['event_type']) ?></span></span><span class="block truncate text-[10px] text-slate-400"><?= htmlspecialchars($usageEvent['resource_name']) ?></span></div><time class="shrink-0 text-[10px] text-slate-400"><?= date('d/m H:i', strtotime($usageEvent['created_at'])) ?></time></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </section>
                                </div>

                                <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                                    <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Trilha administrativa</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Ações de gestão, permissões e publicações</h2></div><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500 dark:bg-[#2c2e33]">auditoria consolidada</span></div>
                                    <?php if (empty($globalDashboard['admin_timeline'])): ?>
                                        <p class="py-8 text-center text-xs text-slate-400">Nenhuma ação administrativa foi registrada no período.</p>
                                    <?php else: ?>
                                        <div class="mt-4 grid grid-cols-1 gap-x-8 divide-y divide-slate-100 dark:divide-[#454956] lg:grid-cols-2 lg:divide-y-0">
                                            <?php foreach ($globalDashboard['admin_timeline'] as $timelineEvent): ?>
                                                <?php $timelineSource = ['usage' => 'Gestão', 'permission' => 'Permissão', 'workflow' => 'Publicação'][$timelineEvent['source']] ?? 'Sistema'; ?>
                                                <div class="flex items-start justify-between gap-3 py-3"><div class="min-w-0"><span class="block text-xs font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars(ucwords(str_replace('_', ' ', strtolower((string)$timelineEvent['action'])))) ?> <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[9px] uppercase tracking-wide text-slate-500 dark:bg-[#2c2e33] dark:text-slate-300"><?= htmlspecialchars($timelineSource) ?></span></span><span class="mt-0.5 block truncate text-[10px] text-slate-400">Por <?= htmlspecialchars($timelineEvent['actor_name'] ?? 'Sistema') ?> · <?= htmlspecialchars($timelineEvent['resource_name']) ?></span></div><time class="shrink-0 text-[10px] text-slate-400"><?= date('d/m H:i', strtotime($timelineEvent['created_at'])) ?></time></div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </section>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                    <div class="space-y-6">
                        <div>
                            <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Visão Geral da Gestão de Documentos</h1>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1"><?= $isGlobalAdminCurrent ? 'Resumo global e operações recentes de todo o acervo municipal.' : 'Resumo exclusivo das categorias em que você possui acesso de edição ou administração.' ?></p>
                        </div>

                        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                            <div class="p-4 rounded-md bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] shadow-xs">
                                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 block">Total de Documentos</span>
                                <span class="text-2xl font-bold text-slate-900 dark:text-slate-100 mt-1 block font-mono"><?= $totalDocs ?></span>
                            </div>
                            <div class="p-4 rounded-md bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] shadow-xs">
                                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 block">Publicados</span>
                                <span class="text-2xl font-bold text-slate-900 dark:text-slate-100 mt-1 block font-mono"><?= $totalPublicados ?></span>
                            </div>
                            <div class="p-4 rounded-md bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] shadow-xs">
                                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 block">Rascunhos</span>
                                <span class="text-2xl font-bold text-slate-900 dark:text-slate-100 mt-1 block font-mono"><?= $totalRascunhos ?></span>
                            </div>
                            <div class="p-4 rounded-md bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] shadow-xs">
                                <span class="text-xs font-semibold text-blue-600 dark:text-blue-300 block">Em revisão</span>
                                <span class="text-2xl font-bold text-slate-900 dark:text-slate-100 mt-1 block font-mono"><?= $totalEmRevisao ?></span>
                            </div>
                            <div class="p-4 rounded-md bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] shadow-xs">
                                <span class="text-xs font-semibold text-slate-400 block">Inativos</span>
                                <span class="text-2xl font-bold text-slate-900 dark:text-slate-100 mt-1 block font-mono"><?= $totalInativos ?></span>
                            </div>
                        </div>

                        <div class="p-5 rounded-md bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] shadow-xs">
                            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-3">Atalhos Administrativos</h2>
                            <div class="flex flex-wrap items-center gap-3">
                                <a href="index.php?tab=novo_documento" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90 transition shadow-xs text-decoration-none">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                    <span>Novo Conteúdo</span>
                                </a>
                                <a href="index.php?tab=editar_estrutura" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-700 dark:text-slate-300 text-xs font-semibold hover:bg-slate-100 dark:hover:bg-[#3e424e] transition text-decoration-none">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                                    <span>Árvore & Estrutura</span>
                                </a>
                                <a href="index.php?tab=documentos" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-700 dark:text-slate-300 text-xs font-semibold hover:bg-slate-100 dark:hover:bg-[#3e424e] transition text-decoration-none">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                    <span>Ver Documentos</span>
                                </a>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-3">Últimos Documentos Alterados</h2>
                            <div class="divide-y divide-slate-100 dark:divide-[#454956]">
                                <?php foreach ($ultimosDocumentos as $uDoc): ?>
                                    <div class="py-2.5 flex items-center justify-between">
                                        <div>
                                            <span class="font-bold text-xs text-slate-900 dark:text-slate-100 block"><?= htmlspecialchars($uDoc['titulo'] ?? 'Documento') ?></span>
                                            <span class="text-[11px] text-slate-400 block"><?= htmlspecialchars($uDoc['categoria'] ?? 'Geral') ?> &rsaquo; <?= htmlspecialchars($uDoc['subcategoria'] ?? 'Geral') ?> &rsaquo; <?= htmlspecialchars($uDoc['assunto'] ?? 'Geral') ?> • Atualizado em <?= isset($uDoc['atualizado_em']) ? date('d/m/Y H:i', strtotime($uDoc['atualizado_em'])) : 'Recente' ?></span>
                                        </div>
                                        <a href="index.php?tab=detalhes_documento&id=<?= $uDoc['id'] ?>" class="text-xs font-semibold text-slate-600 dark:text-slate-300 hover:underline">Ver Detalhes &rarr;</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- ABA 2: PÁGINA DOCUMENTOS (BUSCA, FILTROS, AÇÕES EM LOTE, TABELA E ⋯) -->
                <?php if ($activeTab === 'documentos'): ?>
                    <form method="POST" action="index.php?tab=documentos" id="batch-form" class="space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="document_id" id="single-document-id" value="">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                            <div>
                                <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Documentos</h1>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Gerencie todos os conteúdos cadastrados no sistema.</p>
                            </div>

                            <div class="flex items-center gap-2">
                                <a href="index.php?tab=novo_documento" class="px-4 py-2 rounded-md bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90 transition shadow-xs text-decoration-none flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                    <span>Novo Conteúdo</span>
                                </a>
                            </div>
                        </div>

                        <!-- BARRA DE PESQUISA E FILTROS -->
                        <div class="p-4 rounded-md bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] shadow-xs space-y-3">
                            <div class="flex items-center gap-3">
                                <div class="relative flex-1">
                                    <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" class="input-minimal w-full pl-9 pr-3 py-2 text-xs" placeholder="Pesquisar por título, resumo ou palavras-chave...">
                                    <span class="absolute left-3 top-2.5 text-slate-400 text-xs">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                    </span>
                                </div>
                                <button type="submit" class="px-4 py-2 rounded-md bg-slate-200 dark:bg-[#2c2e33] hover:bg-slate-300 dark:hover:bg-[#3e424e] text-slate-700 dark:text-slate-300 font-semibold text-xs transition">
                                    Filtrar
                                </button>
                                <?php if (!empty($searchQuery) || !empty($filterCat) || !empty($filterSubcat) || !empty($filterAssunto) || !empty($filterTipo) || !empty($filterStatus)): ?>
                                    <a href="index.php?tab=documentos" class="px-3 py-2 rounded-md bg-slate-100 dark:bg-[#2c2e33] text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 text-xs font-semibold">Limpar</a>
                                <?php endif; ?>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2.5 pt-2 border-t border-slate-100 dark:border-[#454956]">
                                <div>
                                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Categoria</label>
                                    <select id="filter-cat" name="filter_cat" onchange="onFilterCategoryChange()" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="">Todas</option>
                                        <?php foreach ($rawCategorias as $c): ?>
                                            <option value="<?= htmlspecialchars($c) ?>" <?= $filterCat === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Subcategoria</label>
                                    <select id="filter-subcat" name="filter_subcat" onchange="onFilterSubcategoryChange()" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="">Todas</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Assunto</label>
                                    <select id="filter-assunto" name="filter_assunto" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="">Todos</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Tipo</label>
                                    <select name="filter_tipo" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="">Todos</option>
                                        <option value="file" <?= $filterTipo === 'file' ? 'selected' : '' ?>>Arquivo</option>
                                        <option value="text" <?= $filterTipo === 'text' ? 'selected' : '' ?>>Texto</option>
                                        <option value="code" <?= $filterTipo === 'code' ? 'selected' : '' ?>>Código</option>
                                        <option value="link" <?= $filterTipo === 'link' ? 'selected' : '' ?>>Link</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Status</label>
                                    <select name="filter_status" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="">Todos</option>
                                        <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>Publicado</option>
                                        <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Rascunho</option>
                                        <option value="review" <?= $filterStatus === 'review' ? 'selected' : '' ?>>Em revisão</option>
                                        <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inativo</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- BARRA DE AÇÕES EM LOTE -->
                        <div class="flex items-center justify-between p-3 rounded-md bg-slate-100 dark:bg-[#2c2e33] border border-slate-200 dark:border-[#454956] text-xs">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-slate-700 dark:text-slate-300">Ações em Lote:</span>
                                <button type="submit" name="batch_action" value="submit_review" onclick="return confirm('Enviar os documentos selecionados para revisão?')" class="px-3 py-1 rounded bg-blue-600 text-white font-semibold hover:opacity-90">
                                    Enviar para revisão
                                </button>
                                <button type="submit" name="batch_action" value="publish" onclick="return confirm('Aprovar e publicar os documentos selecionados? Somente itens em revisão serão aceitos.')" class="px-3 py-1 rounded bg-emerald-600 text-white font-semibold hover:opacity-90">
                                    Aprovar e publicar
                                </button>
                                <button type="submit" name="batch_action" value="draft" onclick="return confirm('Mover selecionados para rascunho?')" class="px-3 py-1 rounded bg-amber-600 text-white font-semibold hover:opacity-90">
                                    Mover para Rascunho
                                </button>
                                <button type="submit" name="batch_action" value="trash" onclick="return confirm('Mover selecionados para a lixeira?')" class="px-3 py-1 rounded bg-red-600 text-white font-semibold hover:opacity-90 flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    <span>Mover para Lixeira</span>
                                </button>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-[#353842] rounded-md border border-slate-200 dark:border-[#454956] shadow-xs overflow-hidden">
                            <?php if (empty($documentosPaginados)): ?>
                                <div class="p-12 text-center">
                                    <div class="w-12 h-12 rounded-full bg-slate-100 dark:bg-[#2c2e33] text-slate-400 flex items-center justify-center font-bold text-xl mx-auto mb-3">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                    </div>
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-1">Nenhum documento cadastrado</h3>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Comece adicionando o primeiro conteúdo.</p>
                                    <a href="index.php?tab=novo_documento" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold shadow-xs">
                                        + Novo Conteúdo
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs text-slate-700 dark:text-slate-300">
                                        <thead class="bg-slate-50 dark:bg-[#2c2e33] text-slate-400 uppercase font-semibold text-[10px] border-b border-slate-200 dark:border-[#454956]">
                                            <tr>
                                                <th class="p-3 w-8">
                                                    <input type="checkbox" onclick="toggleSelectAll(this)">
                                                </th>
                                                <th class="p-3">Título</th>
                                                <th class="p-3">Localização</th>
                                                <th class="p-3">Tipo</th>
                                                <th class="p-3">Layout</th>
                                                <th class="p-3">Status</th>
                                                <th class="p-3 text-right">Ação</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-[#454956]/60">
                                            <?php foreach ($documentosPaginados as $doc): ?>
                                                <?php $st = strtolower($doc['status'] ?: 'published'); ?>
                                                <tr class="hover:bg-slate-50/70 dark:hover:bg-[#3e424e]/50 transition">
                                                    <td class="p-3">
                                                        <input type="checkbox" name="selected_docs[]" value="<?= $doc['id'] ?>" class="batch-checkbox">
                                                    </td>
                                                    <td class="p-3">
                                                        <a href="index.php?tab=detalhes_documento&id=<?= $doc['id'] ?>" class="font-bold text-slate-900 dark:text-slate-100 hover:underline block"><?= htmlspecialchars($doc['titulo']) ?></a>
                                                        <span class="text-[11px] text-slate-400 line-clamp-1 mt-0.5"><?= htmlspecialchars($doc['descricao']) ?></span>
                                                    </td>
                                                    <td class="p-3 text-[11px] text-slate-500 dark:text-slate-400 font-medium">
                                                        <?= htmlspecialchars($doc['categoria']) ?> / <?= htmlspecialchars($doc['subcategoria']) ?> / <?= htmlspecialchars($doc['assunto']) ?>
                                                    </td>
                                                    <td class="p-3">
                                                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-[#454956]">
                                                            <?= strtoupper($doc['tipo_conteudo']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="p-3 font-mono text-[10px] uppercase text-slate-400">
                                                        <?= htmlspecialchars($doc['layout_width'] ?? 'full') ?>
                                                    </td>
                                                    <td class="p-3">
                                                        <?php if ($st === 'published'): ?>
                                                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20">Publicado</span>
                                                        <?php elseif ($st === 'draft'): ?>
                                                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20">Rascunho</span>
                                                        <?php elseif ($st === 'review'): ?>
                                                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-blue-500/10 text-blue-700 dark:text-blue-300 border border-blue-500/20">Em revisão</span>
                                                        <?php else: ?>
                                                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-slate-500/10 text-slate-600 dark:text-slate-400 border border-slate-500/20">Inativo</span>
                                                        <?php endif; ?>
                                                        <?php if (in_array($st, ['draft', 'review'], true) && !empty($doc['approval_expires_at'])): ?>
                                                            <?php $docExpiryDays = (int)ceil((strtotime($doc['approval_expires_at']) - time()) / 86400); ?>
                                                            <span class="mt-1 block text-[10px] <?= $docExpiryDays <= 7 ? 'font-semibold text-amber-700 dark:text-amber-300' : 'text-slate-400' ?>"><?= $docExpiryDays <= 7 ? 'Expira em ' . max(0, $docExpiryDays) . ' dia(s)' : 'Expira em ' . date('d/m/Y', strtotime($doc['approval_expires_at'])) ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="p-3 text-right relative">
                                                        <button type="button" onclick="toggleActionMenu(<?= $doc['id'] ?>, event)" class="px-2 py-1 rounded text-slate-500 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-[#454956] font-bold text-sm">
                                                            ⋯
                                                        </button>

                                                        <div id="action-menu-<?= $doc['id'] ?>" class="hidden absolute right-3 top-10 w-48 bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] rounded-md shadow-md py-1 z-50 text-left text-xs font-medium">
                                                            <a href="index.php?tab=detalhes_documento&id=<?= $doc['id'] ?>" class="block px-3 py-1.5 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">Visualizar Detalhes</a>
                                                            <a href="index.php?tab=novo_documento&action=edit_doc&id=<?= $doc['id'] ?>" class="block px-3 py-1.5 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">Editar Metadados</a>
                                                            <?php if ($doc['tipo_conteudo'] === 'file'): ?>
                                                                <a href="index.php?tab=substituir_arquivo&id=<?= $doc['id'] ?>" class="block px-3 py-1.5 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">Substituir arquivo</a>
                                                            <?php endif; ?>
                                                            <div class="my-1 border-t border-slate-100 dark:border-[#454956]"></div>
                                                            <button type="submit" name="document_trash_action" value="trash" onclick="document.getElementById('single-document-id').value = '<?= $doc['id'] ?>'; return confirm('Mover este documento para a lixeira?')" class="block w-full px-3 py-1.5 text-left text-red-600 dark:text-red-400 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">
                                                                Mover para lixeira
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>

                <!-- ABA 3: GERENCIAMENTO DE CATEGORIAS -->
                <?php if ($activeTab === 'categorias'): ?>
                    <?php 
                        $canCreateCat = $permService->canCreateCategory((int)($loggedUser['id'] ?? 0));
                        $showCategoryForm = $editCat !== null || $canCreateCat;
                    ?>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <?php if ($showCategoryForm): ?>
                        <div class="lg:col-span-1 bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956] h-fit">
                            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">
                                <?= $editCat ? 'Editar Categoria' : 'Nova Categoria' ?>
                            </h2>
                            <form method="POST" action="index.php?tab=categorias" enctype="multipart/form-data" class="space-y-3">
                                <input type="hidden" name="save_category" value="1">
                                <?php if ($editCat): ?>
                                    <input type="hidden" name="id" value="<?= $editCat['id'] ?>">
                                <?php endif; ?>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Nome da Categoria *</label>
                                    <input type="text" name="nome" required value="<?= htmlspecialchars($editCat['nome'] ?? '') ?>" class="input-minimal w-full px-3 py-1.5 text-xs">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Descrição</label>
                                    <textarea name="descricao" rows="2" class="input-minimal w-full px-3 py-1.5 text-xs"><?= htmlspecialchars($editCat['descricao'] ?? '') ?></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Imagem da categoria</label>
                                    <?php if (!empty($editCat['image_path'])): ?>
                                        <div class="mb-2 flex items-center gap-2">
                                            <img src="../category_image.php?id=<?= (int)$editCat['id'] ?>&amp;v=<?= urlencode((string)$editCat['image_path']) ?>" alt="Imagem atual da categoria" class="h-9 w-9 rounded object-cover border border-slate-200 dark:border-[#454956]">
                                            <label class="inline-flex items-center gap-1.5 text-[10px] text-slate-500 dark:text-slate-400"><input type="checkbox" name="remove_category_image" value="1"> Remover imagem atual</label>
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="category_image" accept="image/jpeg,image/png,image/webp" class="block w-full text-[11px] text-slate-500 file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-[10px] file:font-semibold dark:file:bg-[#2c2e33] dark:file:text-slate-200">
                                    <p class="mt-1 text-[10px] text-slate-400">JPG, PNG ou WEBP, até 3 MB. Substitui o ícone no portal.</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Status</label>
                                    <select name="status" class="input-minimal w-full px-3 py-1.5 text-xs">
                                        <option value="ativo" <?= ($editCat['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                        <option value="inativo" <?= ($editCat['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                    </select>
                                </div>
                                <button type="submit" class="w-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold py-2 rounded-md text-xs">
                                    <?= $editCat ? 'Salvar Categoria' : 'Cadastrar Categoria' ?>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>

                        <div class="<?= $showCategoryForm ? 'lg:col-span-2' : 'lg:col-span-3' ?> bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956]">
                            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">Categorias Cadastradas</h2>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs text-slate-700 dark:text-slate-300">
                                    <thead class="bg-slate-50 dark:bg-[#2c2e33] uppercase text-[10px] border-b border-slate-200 dark:border-[#454956]">
                                        <tr>
                                            <th class="p-2">Nome</th>
                                            <th class="p-2">Subcategorias</th>
                                            <th class="p-2">Status</th>
                                            <th class="p-2 text-right">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                        <?php foreach ($listCategorias as $cat): ?>
                                            <tr>
                                                <td class="p-2 font-bold"><?= htmlspecialchars($cat['nome']) ?></td>
                                                <td class="p-2"><?= $cat['total_subcat'] ?> subcategorias</td>
                                                <td class="p-2">
                                                    <span class="text-[10px] px-2 py-0.5 rounded <?= $cat['status'] === 'ativo' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-slate-500/10 text-slate-400' ?>"><?= ucfirst($cat['status']) ?></span>
                                                </td>
                                                <td class="p-2 text-right">
                                                    <a href="index.php?tab=editar_estrutura&type=categoria&id=<?= $cat['id'] ?>" class="text-amber-600 font-semibold mr-2">Editar Estrutura &rarr;</a>
                                                    <a href="index.php?tab=categorias&action=delete_category&id=<?= $cat['id'] ?>" onclick="return confirm('Excluir categoria?')" class="text-red-600 font-semibold">Excluir</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ABA 4: GERENCIAMENTO DE SUBCATEGORIAS -->
                <?php if ($activeTab === 'subcategorias'): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-1 bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956] h-fit">
                            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">
                                <?= $editSub ? 'Editar Subcategoria' : 'Nova Subcategoria' ?>
                            </h2>
                            <form method="POST" action="index.php?tab=subcategorias" enctype="multipart/form-data" class="space-y-3">
                                <input type="hidden" name="save_subcategory" value="1">
                                <?php if ($editSub): ?>
                                    <input type="hidden" name="id" value="<?= $editSub['id'] ?>">
                                <?php endif; ?>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Categoria Pai *</label>
                                    <select name="category_id" required class="input-minimal w-full px-3 py-1.5 text-xs">
                                        <?php foreach ($listCategorias as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>" <?= (int)($editSub['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Nome da Subcategoria *</label>
                                    <input type="text" name="nome" required value="<?= htmlspecialchars($editSub['nome'] ?? '') ?>" class="input-minimal w-full px-3 py-1.5 text-xs">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Descrição</label>
                                    <textarea name="descricao" rows="2" class="input-minimal w-full px-3 py-1.5 text-xs"><?= htmlspecialchars($editSub['descricao'] ?? '') ?></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Imagem da subcategoria</label>
                                    <?php if (!empty($editSub['image_path'])): ?>
                                        <div class="mb-2 flex items-center gap-2">
                                            <img src="../subcategory_image.php?id=<?= (int)$editSub['id'] ?>&amp;v=<?= urlencode((string)$editSub['image_path']) ?>" alt="Imagem atual da subcategoria" class="h-9 w-9 rounded object-cover border border-slate-200 dark:border-[#454956]">
                                            <label class="inline-flex items-center gap-1.5 text-[10px] text-slate-500 dark:text-slate-400"><input type="checkbox" name="remove_subcategory_image" value="1"> Remover imagem atual</label>
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="subcategory_image" accept="image/jpeg,image/png,image/webp" class="block w-full text-[11px] text-slate-500 file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-[10px] file:font-semibold dark:file:bg-[#2c2e33] dark:file:text-slate-200">
                                    <p class="mt-1 text-[10px] text-slate-400">Opcional · JPG, PNG ou WEBP, até 3 MB.</p>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Ordem</label>
                                        <input type="number" name="ordem" value="<?= (int)($editSub['ordem'] ?? 0) ?>" class="input-minimal w-full px-3 py-1.5 text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Status</label>
                                        <select name="status" class="input-minimal w-full px-3 py-1.5 text-xs">
                                            <option value="ativo" <?= ($editSub['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                            <option value="inativo" <?= ($editSub['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="w-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold py-2 rounded-md text-xs">
                                    Salvar Subcategoria
                                </button>
                            </form>
                        </div>

                        <div class="lg:col-span-2 bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956]">
                            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">Subcategorias Cadastradas</h2>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs text-slate-700 dark:text-slate-300">
                                    <thead class="bg-slate-50 dark:bg-[#2c2e33] uppercase text-[10px] border-b border-slate-200 dark:border-[#454956]">
                                        <tr>
                                            <th class="p-2">Subcategoria</th>
                                            <th class="p-2">Categoria Pai</th>
                                            <th class="p-2">Assuntos</th>
                                            <th class="p-2 text-right">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                        <?php foreach ($listSubcategorias as $sub): ?>
                                            <tr>
                                                <td class="p-2 font-bold"><?= htmlspecialchars($sub['nome']) ?></td>
                                                <td class="p-2 text-slate-500"><?= htmlspecialchars($sub['categoria_nome']) ?></td>
                                                <td class="p-2"><?= $sub['total_assuntos'] ?> assuntos</td>
                                                <td class="p-2 text-right">
                                                    <a href="index.php?tab=editar_estrutura&type=subcategoria&id=<?= $sub['id'] ?>" class="text-amber-600 font-semibold mr-2">Editar Estrutura &rarr;</a>
                                                    <a href="index.php?tab=subcategorias&action=delete_subcategory&id=<?= $sub['id'] ?>" onclick="return confirm('Excluir subcategoria?')" class="text-red-600 font-semibold">Excluir</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ABA 5: GERENCIAMENTO DE ASSUNTOS -->
                <?php if ($activeTab === 'assuntos'): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-1 bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956] h-fit">
                            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">
                                <?= $editAss ? 'Editar Assunto' : 'Novo Assunto' ?>
                            </h2>
                            <form method="POST" action="index.php?tab=assuntos" class="space-y-3">
                                <input type="hidden" name="save_subject" value="1">
                                <?php if ($editAss): ?>
                                    <input type="hidden" name="id" value="<?= $editAss['id'] ?>">
                                <?php endif; ?>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Subcategoria Pai *</label>
                                    <select name="subcategory_id" required class="input-minimal w-full px-3 py-1.5 text-xs">
                                        <?php foreach ($listSubcategorias as $sub): ?>
                                            <option value="<?= (int)$sub['id'] ?>" <?= (int)($editAss['subcategory_id'] ?? 0) === (int)$sub['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['categoria_nome']) ?> › <?= htmlspecialchars($sub['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Nome do Assunto *</label>
                                    <input type="text" name="nome" required value="<?= htmlspecialchars($editAss['nome'] ?? '') ?>" class="input-minimal w-full px-3 py-1.5 text-xs">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Descrição</label>
                                    <textarea name="descricao" rows="2" class="input-minimal w-full px-3 py-1.5 text-xs"><?= htmlspecialchars($editAss['descricao'] ?? '') ?></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Status</label>
                                    <select name="status" class="input-minimal w-full px-3 py-1.5 text-xs">
                                        <option value="ativo" <?= ($editAss['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                        <option value="inativo" <?= ($editAss['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                    </select>
                                </div>
                                <button type="submit" class="w-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold py-2 rounded-md text-xs">
                                    Salvar Assunto
                                </button>
                            </form>
                        </div>

                        <div class="lg:col-span-2 bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956]">
                            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-4 pb-2 border-b border-slate-100 dark:border-[#454956]">Assuntos Cadastrados</h2>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs text-slate-700 dark:text-slate-300">
                                    <thead class="bg-slate-50 dark:bg-[#2c2e33] uppercase text-[10px] border-b border-slate-200 dark:border-[#454956]">
                                        <tr>
                                            <th class="p-2">Assunto</th>
                                            <th class="p-2">Subcategoria Pai</th>
                                            <th class="p-2">Docs</th>
                                            <th class="p-2 text-right">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                        <?php foreach ($listAssuntos as $ass): ?>
                                            <tr>
                                                <td class="p-2 font-bold"><?= htmlspecialchars($ass['nome']) ?></td>
                                                <td class="p-2 text-slate-500"><?= htmlspecialchars($ass['subcategoria_nome']) ?></td>
                                                <td class="p-2"><?= $ass['total_docs'] ?> docs</td>
                                                <td class="p-2 text-right">
                                                    <a href="index.php?tab=editar_estrutura&type=assunto&id=<?= $ass['id'] ?>" class="text-amber-600 font-semibold mr-2">Disposição Visual &rarr;</a>
                                                    <a href="index.php?tab=assuntos&action=delete_subject&id=<?= $ass['id'] ?>" onclick="return confirm('Excluir assunto?')" class="text-red-600 font-semibold">Excluir</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ABA 6: LIXEIRA DE DOCUMENTOS -->
                <?php if ($activeTab === 'lixeira'): ?>
                    <div class="bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                        <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-[#454956]">
                            <div>
                                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">Lixeira de Documentos</h1>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Documentos removidos podem ser restaurados ou excluídos definitivamente.</p>
                            </div>
                        </div>

                        <?php if (empty($documentosLixeira)): ?>
                            <div class="p-12 text-center">
                                <div class="w-12 h-12 rounded-full bg-slate-100 dark:bg-[#2c2e33] text-slate-400 flex items-center justify-center font-bold text-xl mx-auto mb-3">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </div>
                                <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-1">A lixeira está vazia</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Nenhum documento foi enviado para a lixeira.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs text-slate-700 dark:text-slate-300">
                                    <thead class="bg-slate-50 dark:bg-[#2c2e33] text-slate-400 uppercase font-semibold text-[10px] border-b border-slate-200 dark:border-[#454956]">
                                        <tr>
                                            <th class="p-3">Título</th>
                                            <th class="p-3">Localização</th>
                                            <th class="p-3">Removido em</th>
                                            <th class="p-3">Removido por</th>
                                            <th class="p-3 text-right">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                        <?php foreach ($documentosLixeira as $lDoc): ?>
                                            <tr>
                                                <td class="p-3 font-bold"><?= htmlspecialchars($lDoc['titulo']) ?></td>
                                                <td class="p-3 text-[11px] text-slate-500"><?= htmlspecialchars($lDoc['categoria']) ?> / <?= htmlspecialchars($lDoc['subcategoria']) ?> / <?= htmlspecialchars($lDoc['assunto']) ?></td>
                                                <td class="p-3 font-mono text-[11px]"><?= $lDoc['removido_em'] ? date('d/m/Y H:i', strtotime($lDoc['removido_em'])) : '—' ?></td>
                                                <td class="p-3 text-[11px]"><?= htmlspecialchars($lDoc['removido_por_nome'] ?: 'Admin') ?></td>
                                                <td class="p-3 text-right">
                                                    <form method="POST" action="index.php?tab=lixeira" class="inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="document_id" value="<?= (int)$lDoc['id'] ?>">
                                                        <button type="submit" name="document_trash_action" value="restore" class="text-emerald-600 font-semibold mr-3 hover:underline">Restaurar</button>
                                                    </form>
                                                    <form method="POST" action="index.php?tab=lixeira" class="inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="document_id" value="<?= (int)$lDoc['id'] ?>">
                                                        <button type="submit" name="document_trash_action" value="permanent_delete" onclick="return confirm('Atenção: Esta ação não poderá ser desfeita. Deseja realmente excluir permanentemente este documento e o arquivo do servidor?')" class="text-red-600 font-semibold hover:underline">Excluir definitivamente</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <!-- ABA 7: GESTÃO DE GRUPOS DE ACESSO -->
                <?php if ($activeTab === 'novo_documento'): ?>
                    <?php
                        // Determine o tipo inicial: edição de documento ou tipo vindo da URL
                        $isEditMode = ($editDoc !== null);
                        $initialType = $isEditMode ? 'documento' : 'documento';
                        $isAdmin = $permService->isGlobalAdmin((int)($loggedUser['id'] ?? 0));
                        $canApproveEditedDocument = $isEditMode && $workflowService->canApprove((int)($loggedUser['id'] ?? 0), (int)$editDoc['id']);
                    ?>
                    <div class="max-w-3xl mx-auto">

                        <!-- CABEÇALHO DA PÁGINA -->
                        <div class="mb-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">
                                        <?= $isEditMode ? 'Editar Conteúdo' : 'Novo Conteúdo' ?>
                                    </h1>
                                    <?php if (!$isEditMode): ?>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Escolha o tipo de conteúdo que deseja adicionar.</p>
                                    <?php endif; ?>
                                </div>
                                <a href="index.php?tab=documentos" class="text-xs text-slate-500 dark:text-slate-400 hover:underline flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                                    Cancelar
                                </a>
                            </div>

                            <?php if (!$isEditMode): ?>
                            <!-- SELETOR SEGMENTADO DE TIPO (somente em criação) -->
                            <div class="mt-5 inline-flex items-center bg-slate-100 dark:bg-[#2c2e33] rounded-lg p-1 border border-slate-200 dark:border-[#454956]" id="nc-type-selector" role="tablist">
                                <button type="button" id="nc-btn-documento" onclick="ncSwitchType('documento')" role="tab"
                                    class="nc-type-btn nc-type-active px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all duration-150 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    Documento
                                </button>
                                <?php if ($permService->canCreateCategory((int)($loggedUser['id'] ?? 0))): ?>
                                <button type="button" id="nc-btn-categoria" onclick="ncSwitchType('categoria')" role="tab"
                                    class="nc-type-btn px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all duration-150 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                    Categoria
                                </button>
                                <?php endif; ?>
                                <?php if ($_canCreateAnySub): ?>
                                <button type="button" id="nc-btn-subcategoria" onclick="ncSwitchType('subcategoria')" role="tab"
                                    class="nc-type-btn px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all duration-150 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                                    Subcategoria
                                </button>
                                <?php endif; ?>
                                <?php if ($_canCreateAnyAss): ?>
                                <button type="button" id="nc-btn-assunto" onclick="ncSwitchType('assunto')" role="tab"
                                    class="nc-type-btn px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all duration-150 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                    Assunto
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- ============================================================ -->
                        <!-- PAINEL 1: FORMULÁRIO DE DOCUMENTO (PADRÃO) -->
                        <!-- ============================================================ -->
                        <div id="nc-panel-documento" class="nc-panel bg-white dark:bg-[#353842] p-6 sm:p-8 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                            <div class="mb-5 pb-3 border-b border-slate-100 dark:border-[#454956]">
                                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100"><?= $isEditMode ? 'Editar Documento' : 'Novo Documento' ?></h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Preencha as informações do documento e selecione a hierarquia dependente.</p>
                            </div>

                            <form id="document-form" method="POST" action="index.php?tab=novo_documento" enctype="multipart/form-data" class="space-y-6">
                                <input type="hidden" name="save_doc" value="1">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" id="batch-upload-flag" name="batch_upload" value="">
                                <?php if ($editDoc): ?>
                                    <input type="hidden" name="id" value="<?= $editDoc['id'] ?>">
                                <?php endif; ?>

                                <!-- Informações básicas -->
                                <div class="space-y-4">
                                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 pb-1 border-b border-slate-100 dark:border-[#454956]">Informações Principais</h3>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Título *</label>
                                        <input type="text" id="document-title" name="titulo" required value="<?= htmlspecialchars($editDoc['titulo'] ?? '') ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex: Requerimento Padrão de Férias 2026">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                        <textarea id="document-description" name="descricao" rows="2" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Resumo do documento..."><?= htmlspecialchars($editDoc['descricao'] ?? '') ?></textarea>
                                    </div>
                                    <div class="rounded-md border border-slate-200 bg-slate-50/70 p-3 dark:border-[#454956] dark:bg-[#2c2e33]">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <label for="document-tag-input" class="block text-xs font-semibold text-slate-700 dark:text-slate-300">Tags</label>
                                                <p class="mt-0.5 text-[10px] text-slate-400">Digite uma tag e pressione Enter. Você pode usar uma existente ou criar uma nova.</p>
                                            </div>
                                            <span id="document-tag-count" class="shrink-0 text-[10px] font-semibold text-slate-400">0/12</span>
                                        </div>
                                        <div id="document-tag-selected" class="mt-2 flex flex-wrap gap-1.5"></div>
                                        <div class="mt-2 flex gap-2">
                                            <input id="document-tag-input" type="text" maxlength="80" autocomplete="off" list="document-tag-options" class="input-minimal min-w-0 flex-1 px-3 py-2 text-xs" placeholder="Ex.: Nutanix, host, backup">
                                            <button id="document-tag-add" type="button" class="rounded border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-[#454956] dark:bg-[#353842] dark:text-slate-200 dark:hover:bg-[#3e424e]">Adicionar</button>
                                        </div>
                                        <datalist id="document-tag-options"></datalist>
                                        <div id="document-tag-suggestions" class="mt-2 hidden border-t border-slate-200 pt-2 dark:border-[#454956]">
                                            <p class="mb-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Sugestões</p>
                                            <div id="document-tag-suggestion-list" class="flex flex-wrap gap-1.5"></div>
                                        </div>
                                        <p id="document-tag-feedback" class="mt-1 hidden text-[10px] text-amber-600 dark:text-amber-300"></p>
                                    </div>
                                </div>

                                <!-- Organização -->
                                <div class="space-y-3">
                                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 pb-1 border-b border-slate-100 dark:border-[#454956]">Organização</h3>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-4 rounded-md bg-slate-50 dark:bg-[#2c2e33] border border-slate-200 dark:border-[#454956]">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Categoria *</label>
                                            <select id="select-cat" name="categoria" required onchange="onCategoryChange()" class="input-minimal w-full px-2.5 py-1.5 text-xs">
                                                <option value="">-- Selecione ▾ --</option>
                                                <?php foreach ($catsParaDocumento as $catDoc): ?>
                                                    <?php $selectedCategoryValue = (string)($editDoc['categoria_id'] ?? ($_GET['cat_id'] ?? $_GET['cat'] ?? '')); ?>
                                                    <option value="<?= (int)$catDoc['id'] ?>" <?= ($selectedCategoryValue === (string)$catDoc['id'] || $selectedCategoryValue === (string)$catDoc['nome']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($catDoc['nome']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Subcategoria *</label>
                                            <select id="select-subcat" name="subcategoria" required onchange="onSubcategoryChange()" class="input-minimal w-full px-2.5 py-1.5 text-xs">
                                                <option value="">-- Selecione ▾ --</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Assunto *</label>
                                            <select id="select-assunto" name="assunto" required class="input-minimal w-full px-2.5 py-1.5 text-xs">
                                                <option value="">-- Selecione ▾ --</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Conteúdo -->
                                <div class="space-y-3">
                                    <?php $selectedVideoSource = (($editDoc['tipo_conteudo'] ?? '') === 'video' && !empty($editDoc['link_externo'])) ? 'url' : 'upload'; ?>
                                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 pb-1 border-b border-slate-100 dark:border-[#454956]">Conteúdo</h3>
                                    <div class="inline-flex flex-wrap items-center bg-slate-100 dark:bg-[#2c2e33] rounded-md p-1 border border-slate-200 dark:border-[#454956] gap-1">
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="file" <?= ($editDoc['tipo_conteudo'] ?? 'file') === 'file' ? 'checked' : '' ?> onchange="toggleFormContent('file')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                            Arquivo
                                        </label>
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="text" <?= ($editDoc['tipo_conteudo'] ?? '') === 'text' ? 'checked' : '' ?> onchange="toggleFormContent('text')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            Texto
                                        </label>
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="code" <?= ($editDoc['tipo_conteudo'] ?? '') === 'code' ? 'checked' : '' ?> onchange="toggleFormContent('code')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l-3 3 3 3m8-6l3 3-3 3m-3-8l-2 10"/></svg>
                                            Código
                                        </label>
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="video" <?= ($editDoc['tipo_conteudo'] ?? '') === 'video' ? 'checked' : '' ?> onchange="toggleFormContent('video')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                            Vídeo
                                        </label>
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="link" <?= ($editDoc['tipo_conteudo'] ?? '') === 'link' ? 'checked' : '' ?> onchange="toggleFormContent('link')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                            Link
                                        </label>
                                    </div>

                                    <?php if (!$isEditMode): ?>
                                        <div id="box-file" class="space-y-3">
                                            <div id="batch-dropzone" tabindex="0" role="button" aria-controls="file-input" class="cursor-pointer rounded-md border-2 border-dashed border-slate-300 bg-slate-50/70 p-6 text-center outline-none transition hover:border-slate-500 hover:bg-slate-100/70 focus:border-slate-500 focus:ring-2 focus:ring-slate-400/40 dark:border-slate-600 dark:bg-[#2c2e33] dark:hover:border-slate-400 dark:hover:bg-[#353842]">
                                                <div class="mx-auto mb-2 flex h-9 w-9 items-center justify-center rounded-full bg-slate-900 text-white dark:bg-white dark:text-slate-900">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 16V4m0 0L8 8m4-4 4 4M4 16.5V19a2 2 0 002 2h12a2 2 0 002-2v-2.5"/></svg>
                                                </div>
                                                <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Arraste arquivos aqui ou clique para selecionar</p>
                                                <p class="mt-1 text-[11px] text-slate-400">Até 20 arquivos por vez · arquivos gerais até 25 MB · vídeos até 250 MB</p>
                                                <input type="file" id="file-input" name="arquivo_file" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.bmp,.avif,.txt,.log,.csv,.md,.json,.xml,.doc,.docx,.mp3,.wav,.ogg,.mp4,.webm,.ogv,.m4v,.mov" class="hidden">
                                                <button type="button" id="batch-select-files" class="mt-3 rounded bg-slate-900 px-4 py-1.5 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Selecionar arquivos</button>
                                            </div>
                                            <div id="batch-upload-error" class="hidden rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs font-medium text-red-700 dark:text-red-300" role="alert"></div>
                                            <div id="batch-file-queue" class="hidden overflow-hidden rounded-md border border-slate-200 bg-white dark:border-[#454956] dark:bg-[#2c2e33]">
                                                <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2 dark:border-[#454956]"><span id="batch-file-count" class="text-xs font-bold text-slate-700 dark:text-slate-200">0 arquivos</span><button type="button" id="batch-clear-files" class="text-[11px] font-semibold text-slate-500 hover:text-red-600 dark:text-slate-300">Limpar fila</button></div>
                                                <div id="batch-file-list" class="max-h-64 divide-y divide-slate-100 overflow-y-auto dark:divide-[#454956]"></div>
                                            </div>
                                            <div id="batch-upload-progress-wrap" class="hidden" aria-live="polite"><div class="mb-1 flex items-center justify-between text-[11px] font-semibold text-slate-500 dark:text-slate-300"><span id="batch-upload-status">Preparando envio…</span><span id="batch-upload-percent">0%</span></div><div class="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-[#454956]"><div id="batch-upload-progress" class="h-full w-0 rounded-full bg-emerald-500 transition-[width] duration-150"></div></div></div>
                                        </div>
                                    <?php else: ?>
                                        <div id="box-file" class="p-6 rounded-md border-2 border-dashed border-slate-300 dark:border-slate-600 bg-slate-50/70 dark:bg-[#2c2e33] text-center">
                                            <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Arraste o arquivo aqui ou clique para selecionar</p>
                                            <p class="text-[11px] text-slate-400 mt-1 mb-3">PDF, imagens, textos, DOC/DOCX, áudio e vídeo (Máx: 25MB)</p>
                                            <input type="file" id="file-input" name="arquivo_file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.bmp,.avif,.txt,.log,.csv,.md,.json,.xml,.doc,.docx,.mp3,.wav,.ogg,.mp4,.webm,.ogv,.m4v,.mov" class="hidden" onchange="updateFilePreview(this)">
                                            <button type="button" onclick="document.getElementById('file-input').click()" class="px-4 py-1.5 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold">Selecionar arquivo</button>
                                            <div id="file-preview-name" class="mt-2 text-xs text-slate-500"></div>
                                        </div>
                                    <?php endif; ?>

                                    <div id="box-text" class="hidden p-4 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-50/70 dark:bg-[#2c2e33]">
                                        <textarea id="text-content-input" name="conteudo_html" rows="7" class="input-minimal w-full px-3 py-2 text-xs font-mono" placeholder="Conteúdo do artigo..."><?= htmlspecialchars(($editDoc['tipo_conteudo'] ?? '') === 'text' ? ($editDoc['conteudo_html'] ?? '') : '') ?></textarea>
                                    </div>

                                    <div id="box-code" class="hidden space-y-3 p-4 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-50/70 dark:bg-[#2c2e33]">
                                        <div class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_13rem] gap-3 items-end">
                                            <div>
                                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Trecho de código *</label>
                                                <p class="text-[11px] text-slate-400">Cole o conteúdo sem alterar a indentação. O botão de copiar aparecerá automaticamente no portal.</p>
                                            </div>
                                            <div>
                                                <label for="code-language" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Linguagem</label>
                                                <select id="code-language" name="linguagem_codigo" class="input-minimal w-full px-3 py-2 text-xs" onchange="updateCodePreview()">
                                                    <?php
                                                    $codeLanguageOptions = [
                                                        'auto' => 'Automática (padrão)', 'plaintext' => 'Texto simples',
                                                        'javascript' => 'JavaScript', 'typescript' => 'TypeScript', 'xml' => 'HTML / XML',
                                                        'css' => 'CSS', 'php' => 'PHP', 'python' => 'Python', 'sql' => 'SQL',
                                                        'bash' => 'Shell / Bash', 'json' => 'JSON', 'java' => 'Java', 'csharp' => 'C#',
                                                        'cpp' => 'C / C++', 'go' => 'Go', 'yaml' => 'YAML', 'markdown' => 'Markdown'
                                                    ];
                                                    $selectedCodeLanguage = $editDoc['linguagem_codigo'] ?? 'auto';
                                                    foreach ($codeLanguageOptions as $languageValue => $languageLabel):
                                                    ?>
                                                        <option value="<?= $languageValue ?>" <?= $selectedCodeLanguage === $languageValue ? 'selected' : '' ?>><?= htmlspecialchars($languageLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <textarea id="code-source-input" name="codigo_fonte" rows="11" spellcheck="false" class="input-minimal w-full px-3 py-2 text-xs font-mono leading-relaxed" placeholder="Cole seu código aqui..." oninput="updateCodePreview()"><?= htmlspecialchars(($editDoc['tipo_conteudo'] ?? '') === 'code' ? ($editDoc['conteudo_html'] ?? '') : '') ?></textarea>

                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Pré-visualização</p>
                                            <div id="code-preview" class="code-snippet code-snippet--preview" data-code-snippet data-code-language="<?= htmlspecialchars($selectedCodeLanguage) ?>">
                                                <div class="code-snippet__header">
                                                    <span class="code-snippet__language" data-code-language-label>Detectando...</span>
                                                    <button type="button" class="code-snippet__copy" data-copy-code aria-label="Copiar código">
                                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 8h10a2 2 0 012 2v10a2 2 0 01-2 2H8a2 2 0 01-2-2V10a2 2 0 012-2zM16 8V4a2 2 0 00-2-2H4a2 2 0 00-2 2v10a2 2 0 002 2h2"/></svg>
                                                        <span data-copy-label>Copiar</span>
                                                    </button>
                                                </div>
                                                <pre><code data-code-source><?= htmlspecialchars(($editDoc['tipo_conteudo'] ?? '') === 'code' ? ($editDoc['conteudo_html'] ?? '') : '// A pré-visualização aparecerá aqui.') ?></code></pre>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="box-video" class="hidden space-y-4 rounded-md border border-slate-200 bg-slate-50/70 p-4 dark:border-[#454956] dark:bg-[#2c2e33]">
                                        <div>
                                            <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Origem do vídeo *</p>
                                            <p class="mt-1 text-[11px] text-slate-400">Envie um arquivo local ou use um link do YouTube, Vimeo ou de um arquivo de vídeo externo.</p>
                                        </div>
                                        <div class="inline-flex rounded-md border border-slate-200 bg-white p-1 dark:border-[#454956] dark:bg-[#353842]">
                                            <label class="flex cursor-pointer items-center gap-1.5 rounded px-3 py-1.5 text-xs font-semibold text-slate-700 has-[:checked]:bg-slate-100 dark:text-slate-200 dark:has-[:checked]:bg-[#454956]">
                                                <input type="radio" name="video_source" value="upload" <?= $selectedVideoSource === 'upload' ? 'checked' : '' ?> onchange="toggleVideoSource()" class="sr-only">Arquivo local
                                            </label>
                                            <label class="flex cursor-pointer items-center gap-1.5 rounded px-3 py-1.5 text-xs font-semibold text-slate-700 has-[:checked]:bg-slate-100 dark:text-slate-200 dark:has-[:checked]:bg-[#454956]">
                                                <input type="radio" name="video_source" value="url" <?= $selectedVideoSource === 'url' ? 'checked' : '' ?> onchange="toggleVideoSource()" class="sr-only">Link externo
                                            </label>
                                        </div>
                                        <div id="video-upload-fields" class="rounded-md border-2 border-dashed border-slate-300 bg-white p-5 text-center dark:border-slate-600 dark:bg-[#353842]">
                                            <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Selecione um vídeo do computador</p>
                                            <p class="mt-1 text-[11px] text-slate-400">MP4, WEBM, OGV, M4V ou MOV (máximo de 250MB)</p>
                                            <input type="file" id="video-file-input" name="video_file" accept="video/mp4,video/webm,video/ogg,video/quicktime,.mp4,.webm,.ogv,.m4v,.mov" class="hidden" onchange="updateVideoFilePreview(this)">
                                            <button type="button" onclick="document.getElementById('video-file-input').click()" class="mt-3 rounded bg-slate-900 px-4 py-1.5 text-xs font-semibold text-white dark:bg-white dark:text-slate-900">Selecionar vídeo</button>
                                            <div id="video-file-preview-name" class="mt-2 text-xs text-slate-500"></div>
                                        </div>
                                        <div id="video-url-fields" class="hidden">
                                            <label for="video-url" class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">URL do vídeo *</label>
                                            <input type="url" id="video-url" name="video_url" value="<?= htmlspecialchars(($editDoc['tipo_conteudo'] ?? '') === 'video' ? ($editDoc['link_externo'] ?? '') : '') ?>" class="input-minimal w-full px-3 py-2 text-xs font-mono" placeholder="https://www.youtube.com/watch?v=..." oninput="updateVideoUrlPreview()">
                                            <p class="mt-1.5 text-[11px] text-slate-400">Links do YouTube e Vimeo são incorporados. URLs diretas (.mp4, .webm etc.) usam o player nativo.</p>
                                        </div>
                                        <div id="video-url-preview" class="hidden overflow-hidden rounded-md border border-slate-200 bg-black dark:border-[#454956]"></div>
                                    </div>

                                    <div id="box-link" class="hidden p-4 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-50/70 dark:bg-[#2c2e33]">
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">URL Externa *</label>
                                        <input type="url" name="link_externo" value="<?= htmlspecialchars($editDoc['link_externo'] ?? '') ?>" class="input-minimal w-full px-3 py-2 text-xs font-mono" placeholder="https://...">
                                    </div>
                                </div>

                                <!-- Publicação -->
                                <div class="space-y-3">
                                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 pb-1 border-b border-slate-100 dark:border-[#454956]">Publicação</h3>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Etapa atual</label>
                                            <div class="input-minimal w-full px-3 py-2 text-xs font-semibold">
                                                <?= htmlspecialchars(DocumentWorkflowService::label($editDoc['status'] ?? 'draft')) ?>
                                            </div>
                                            <p class="mt-1 text-[10px] text-slate-400">Salve como rascunho e envie para revisão quando estiver pronto.</p>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Largura no Portal</label>
                                            <select name="layout_width" class="input-minimal w-full px-3 py-2 text-xs">
                                                <option value="full" <?= ($editDoc['layout_width'] ?? 'full') === 'full' ? 'selected' : '' ?>>Completa (100%)</option>
                                                <option value="half" <?= ($editDoc['layout_width'] ?? '') === 'half' ? 'selected' : '' ?>>Metade (50%)</option>
                                                <option value="one-third" <?= ($editDoc['layout_width'] ?? '') === 'one-third' ? 'selected' : '' ?>>1/3 (33%)</option>
                                                <option value="two-thirds" <?= ($editDoc['layout_width'] ?? '') === 'two-thirds' ? 'selected' : '' ?>>2/3 (66%)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label for="workflow-note" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Observação para a revisão</label>
                                        <textarea id="workflow-note" name="workflow_note" rows="2" maxlength="2000" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Opcional: explique o que foi alterado ou deixe uma orientação ao aprovador."></textarea>
                                    </div>
                                    <div id="document-hierarchy-helper" class="hidden rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-[11px] text-amber-800 dark:text-amber-200">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <span id="document-hierarchy-helper-text"></span>
                                            <button id="document-hierarchy-helper-action" type="button" class="rounded bg-amber-600 px-3 py-1.5 font-semibold text-white transition hover:bg-amber-500"></button>
                                        </div>
                                    </div>
                                </div>

                                <div class="pt-4 border-t border-slate-100 dark:border-[#454956] flex justify-end gap-2">
                                    <a href="index.php?tab=documentos" class="px-4 py-2 rounded border border-slate-200 dark:border-[#454956] text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#2c2e33] transition">Cancelar</a>
                                    <button type="submit" name="workflow_action" value="save_draft" class="px-5 py-2 rounded border border-slate-300 dark:border-[#454956] bg-white dark:bg-[#2c2e33] text-slate-700 dark:text-slate-200 text-xs font-semibold hover:bg-slate-50 dark:hover:bg-[#3e424e] transition">Salvar como rascunho</button>
                                    <button type="submit" name="workflow_action" value="submit_review" class="px-5 py-2 rounded bg-blue-600 text-white text-xs font-semibold hover:bg-blue-500 transition shadow-xs">Enviar para revisão</button>
                                    <?php if ($canApproveEditedDocument && ($editDoc['status'] ?? '') === 'review'): ?>
                                        <button type="submit" name="workflow_action" value="approve_publish" class="px-5 py-2 rounded bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-500 transition shadow-xs">Aprovar e publicar</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>

                        <?php if (!$isEditMode): ?>

                        <?php if ($permService->canCreateCategory((int)($loggedUser['id'] ?? 0))): ?>
                        <!-- ============================================================ -->
                        <!-- PAINEL 2: FORMULÁRIO DE CATEGORIA -->
                        <!-- ============================================================ -->
                        <div id="nc-panel-categoria" class="nc-panel hidden bg-white dark:bg-[#353842] p-6 sm:p-8 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                            <div class="mb-5 pb-3 border-b border-slate-100 dark:border-[#454956]">
                                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Nova Categoria</h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Crie um novo agrupamento de primeiro nível na hierarquia documental.</p>
                            </div>

                            <form method="POST" action="index.php?tab=novo_documento" enctype="multipart/form-data" class="space-y-5">
                                <input type="hidden" name="save_category" value="1">
                                <input type="hidden" name="redirect_tab" value="novo_documento">

                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome *</label>
                                    <input type="text" name="nome" required class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex: Recursos Humanos">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                    <textarea name="descricao" rows="3" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Descrição opcional da categoria..."></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Imagem da categoria</label>
                                    <input type="file" name="category_image" accept="image/jpeg,image/png,image/webp" class="block w-full text-[11px] text-slate-500 file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-[10px] file:font-semibold dark:file:bg-[#2c2e33] dark:file:text-slate-200">
                                    <p class="mt-1 text-[10px] text-slate-400">Opcional · JPG, PNG ou WEBP, até 3 MB.</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Status</label>
                                    <select name="status" class="input-minimal w-full px-3 py-2 text-xs">
                                        <option value="ativo">Ativo</option>
                                        <option value="inativo">Inativo</option>
                                    </select>
                                </div>

                                <div class="pt-4 border-t border-slate-100 dark:border-[#454956] flex justify-end gap-2">
                                    <button type="button" onclick="ncSwitchType('documento')" class="px-4 py-2 rounded border border-slate-200 dark:border-[#454956] text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#2c2e33] transition">Cancelar</button>
                                    <button type="submit" class="px-6 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90 transition">Criar Categoria</button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- ============================================================ -->
                        <!-- PAINEL 3: FORMULÁRIO DE SUBCATEGORIA -->
                        <!-- ============================================================ -->
                        <div id="nc-panel-subcategoria" class="nc-panel hidden bg-white dark:bg-[#353842] p-6 sm:p-8 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                            <div class="mb-5 pb-3 border-b border-slate-100 dark:border-[#454956]">
                                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Nova Subcategoria</h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Selecione a Categoria pai e defina o nome da Subcategoria.</p>
                            </div>

                            <form method="POST" action="index.php?tab=novo_documento" enctype="multipart/form-data" class="space-y-5">
                                <input type="hidden" name="save_subcategory" value="1">
                                <input type="hidden" name="redirect_tab" value="novo_documento">

                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Categoria Pai *</label>
                                    <select id="nc-sub-cat" name="category_id" required class="input-minimal w-full px-3 py-2 text-xs">
                                        <option value="">-- Selecione uma Categoria --</option>
                                        <?php foreach ($catsParaSubcategoria as $catSub): ?>
                                            <option value="<?= (int)$catSub['id'] ?>"><?= htmlspecialchars($catSub['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome *</label>
                                    <input type="text" name="nome" required class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex: Férias e Licenças">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                    <textarea name="descricao" rows="3" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Descrição opcional..."></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Imagem da subcategoria</label>
                                    <input type="file" name="subcategory_image" accept="image/jpeg,image/png,image/webp" class="block w-full text-[11px] text-slate-500 file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-[10px] file:font-semibold dark:file:bg-[#2c2e33] dark:file:text-slate-200">
                                    <p class="mt-1 text-[10px] text-slate-400">Opcional · JPG, PNG ou WEBP, até 3 MB.</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Status</label>
                                    <select name="status" class="input-minimal w-full px-3 py-2 text-xs">
                                        <option value="ativo">Ativo</option>
                                        <option value="inativo">Inativo</option>
                                    </select>
                                </div>

                                <div class="pt-4 border-t border-slate-100 dark:border-[#454956] flex justify-end gap-2">
                                    <button type="button" onclick="ncSwitchType('documento')" class="px-4 py-2 rounded border border-slate-200 dark:border-[#454956] text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#2c2e33] transition">Cancelar</button>
                                    <button type="submit" class="px-6 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90 transition">Criar Subcategoria</button>
                                </div>
                            </form>
                        </div>

                        <!-- ============================================================ -->
                        <!-- PAINEL 4: FORMULÁRIO DE ASSUNTO -->
                        <!-- ============================================================ -->
                        <div id="nc-panel-assunto" class="nc-panel hidden bg-white dark:bg-[#353842] p-6 sm:p-8 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                            <div class="mb-5 pb-3 border-b border-slate-100 dark:border-[#454956]">
                                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Novo Assunto</h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Selecione a Categoria e Subcategoria e defina o nome do Assunto.</p>
                            </div>

                            <form method="POST" action="index.php?tab=novo_documento" class="space-y-5">
                                <input type="hidden" name="save_subject" value="1">
                                <input type="hidden" name="redirect_tab" value="novo_documento">

                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Categoria *</label>
                                    <select id="nc-ass-cat" name="_cat_aux" required onchange="ncLoadSubcatsForAssunto()" class="input-minimal w-full px-3 py-2 text-xs">
                                        <option value="">-- Selecione a Categoria --</option>
                                        <?php foreach ($catsParaAssunto as $catAss): ?>
                                            <option value="<?= htmlspecialchars($catAss['id']) ?>"><?= htmlspecialchars($catAss['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Subcategoria *</label>
                                    <select id="nc-ass-subcat" name="subcategory_id" required class="input-minimal w-full px-3 py-2 text-xs">
                                        <option value="">-- Selecione a Categoria primeiro --</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome *</label>
                                    <input type="text" name="nome" required class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex: Solicitação de Férias">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                    <textarea name="descricao" rows="3" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Descrição opcional..."></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Status</label>
                                    <select name="status" class="input-minimal w-full px-3 py-2 text-xs">
                                        <option value="ativo">Ativo</option>
                                        <option value="inativo">Inativo</option>
                                    </select>
                                </div>

                                <div class="pt-4 border-t border-slate-100 dark:border-[#454956] flex justify-end gap-2">
                                    <button type="button" onclick="ncSwitchType('documento')" class="px-4 py-2 rounded border border-slate-200 dark:border-[#454956] text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#2c2e33] transition">Cancelar</button>
                                    <button type="submit" class="px-6 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90 transition">Criar Assunto</button>
                                </div>
                            </form>
                        </div>

                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'substituir_arquivo' && $docDetails): ?>
                    <div class="max-w-xl mx-auto bg-white dark:bg-[#353842] p-6 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs space-y-6">
                        <div class="border-b border-slate-100 dark:border-[#454956] pb-3">
                            <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">Substituir Arquivo Físico</h1>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Substitua o arquivo anexo mantendo metadados e histórico intactos.</p>
                        </div>
                        <div class="p-4 rounded-md bg-slate-50 dark:bg-[#2c2e33] border border-slate-200 text-xs">
                            <span class="font-bold block"><?= htmlspecialchars($docDetails['titulo']) ?></span>
                            <span class="text-slate-400 block">Arquivo Atual: <strong><?= htmlspecialchars($docDetails['nome_original'] ?: 'Sem arquivo') ?></strong></span>
                        </div>
                        <form method="POST" action="index.php?tab=substituir_arquivo&id=<?= $docDetails['id'] ?>" enctype="multipart/form-data" class="space-y-4">
                            <input type="hidden" name="save_file_replace" value="1">
                            <input type="hidden" name="id" value="<?= $docDetails['id'] ?>">
                            <input type="file" name="new_arquivo_file" required class="block w-full text-xs text-slate-500">
                            <div class="flex justify-end gap-2 pt-2">
                                <a href="index.php?tab=detalhes_documento&id=<?= $docDetails['id'] ?>" class="px-4 py-2 rounded border text-xs">Cancelar</a>
                                <button type="submit" class="px-5 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold">Confirmar Substituição</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'detalhes_documento' && $docDetails): ?>
                    <div class="space-y-6">
                        <div class="bg-white dark:bg-[#353842] p-6 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                            <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                                <div>
                                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($docDetails['titulo']) ?></h1>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1"><?= htmlspecialchars($docDetails['descricao']) ?></p>
                                    <span class="mt-2 inline-flex rounded-full border border-blue-500/20 bg-blue-500/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300"><?= htmlspecialchars(DocumentWorkflowService::label($docDetails['status'])) ?></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="../ver_conteudo.php?id=<?= $docDetails['id'] ?>" target="_blank" class="px-3 py-1.5 rounded border text-xs font-semibold flex items-center gap-1.5">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        <span>Visualizar no Portal</span>
                                    </a>
                                    <a href="index.php?tab=novo_documento&action=edit_doc&id=<?= $docDetails['id'] ?>" class="px-3 py-1.5 rounded bg-amber-500/10 text-amber-700 text-xs font-semibold flex items-center gap-1.5">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        <span>Editar</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <section class="bg-white dark:bg-[#353842] p-6 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Fluxo editorial</p>
                                    <h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Histórico e aprovação</h2>
                                </div>
                                <?php if (($docDetails['status'] ?? '') === 'review' && $workflowService->canReview($currentAdminUserId, (int)$docDetails['id'])): ?>
                                    <div class="w-full max-w-lg space-y-3">
                                        <?php if (empty($docDetails['reviewed_at'])): ?>
                                            <form method="POST" action="index.php?tab=detalhes_documento&id=<?= (int)$docDetails['id'] ?>" class="space-y-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="document_id" value="<?= (int)$docDetails['id'] ?>">
                                                <textarea name="workflow_note" rows="2" maxlength="2000" required class="input-minimal w-full px-3 py-2 text-xs" placeholder="Parecer da revisão (obrigatório)"></textarea>
                                                <button type="submit" name="workflow_quick_action" value="review_document" class="rounded bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-500">Concluir revisão</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="rounded-md border border-blue-500/20 bg-blue-500/10 px-3 py-2 text-xs text-blue-800 dark:text-blue-200">
                                                Revisado por <strong><?= htmlspecialchars($docDetails['revisor_nome'] ?: 'Administrador') ?></strong> em <?= date('d/m/Y H:i', strtotime($docDetails['reviewed_at'])) ?>.
                                            </div>
                                            <form method="POST" action="index.php?tab=detalhes_documento&id=<?= (int)$docDetails['id'] ?>" class="flex justify-end">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="document_id" value="<?= (int)$docDetails['id'] ?>">
                                                <button type="submit" name="workflow_quick_action" value="approve_publish" class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-500">Aprovar e publicar</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="index.php?tab=detalhes_documento&id=<?= (int)$docDetails['id'] ?>" class="border-t border-slate-100 pt-3 dark:border-[#454956]">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="document_id" value="<?= (int)$docDetails['id'] ?>">
                                            <textarea name="workflow_note" rows="2" maxlength="2000" required class="input-minimal w-full px-3 py-2 text-xs" placeholder="Motivo da recusa (obrigatório)"></textarea>
                                            <button type="submit" name="workflow_quick_action" value="request_changes" class="mt-2 rounded border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-500/20 dark:text-amber-300">Recusar e devolver para ajustes</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mt-5 grid gap-3 border-y border-slate-100 py-4 text-xs dark:border-[#454956] sm:grid-cols-2 lg:grid-cols-4">
                                <div><span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Autor</span><span class="mt-1 block font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($docDetails['autor_nome'] ?: 'Não informado') ?></span></div>
                                <div><span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Revisor</span><span class="mt-1 block font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($docDetails['revisor_nome'] ?: 'Aguardando revisão') ?></span></div>
                                <div><span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Aprovador</span><span class="mt-1 block font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($docDetails['aprovador_nome'] ?: 'Aguardando aprovação') ?></span></div>
                                <div><span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Prazo de aprovação</span><span class="mt-1 block font-semibold text-slate-700 dark:text-slate-200"><?php if (!empty($docDetails['approval_expires_at'])): $remainingDays = max(0, (int)ceil((strtotime($docDetails['approval_expires_at']) - time()) / 86400)); ?><?= $remainingDays <= 7 ? 'Expira em ' : 'Até ' ?><?= date('d/m/Y', strtotime($docDetails['approval_expires_at'])) ?><?php if ($remainingDays <= 7): ?> <em class="font-normal text-amber-600 dark:text-amber-300">(<?= $remainingDays ?> dia(s))</em><?php endif; ?><?php else: ?>Sem prazo<?php endif; ?></span></div>
                            </div>
                            <?php if (!empty($docDetails['rejection_reason'])): ?>
                                <div class="mt-4 rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-100"><strong>Recusado por <?= htmlspecialchars($docDetails['recusador_nome'] ?: 'Administrador') ?>:</strong> <?= nl2br(htmlspecialchars($docDetails['rejection_reason'])) ?></div>
                            <?php endif; ?>

                            <ol class="mt-5 space-y-3 border-l border-slate-200 pl-4 dark:border-[#454956]">
                                <?php if (empty($workflowHistory)): ?>
                                    <li class="text-xs text-slate-400">Ainda não há movimentações registradas neste documento.</li>
                                <?php endif; ?>
                                <?php foreach ($workflowHistory as $historyItem): ?>
                                    <li class="relative text-xs">
                                        <span class="absolute -left-[1.34rem] top-1 h-2 w-2 rounded-full bg-slate-400 ring-4 ring-white dark:ring-[#353842]"></span>
                                        <p class="font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($historyItem['actor_name'] ?: 'Sistema') ?> <span class="font-normal text-slate-500 dark:text-slate-400"><?= htmlspecialchars(DocumentWorkflowService::actionLabel((string)($historyItem['action'] ?? ''))) ?><?php if (($historyItem['action'] ?? '') !== 'reviewed'): ?> · <?= htmlspecialchars(DocumentWorkflowService::label($historyItem['previous_status'] ?? '')) ?> → <?= htmlspecialchars(DocumentWorkflowService::label($historyItem['new_status'])) ?><?php endif; ?></span></p>
                                        <?php if (!empty($historyItem['note'])): ?><p class="mt-1 text-slate-500 dark:text-slate-400"><?= nl2br(htmlspecialchars($historyItem['note'])) ?></p><?php endif; ?>
                                        <time class="mt-1 block text-[10px] text-slate-400"><?= date('d/m/Y H:i', strtotime($historyItem['created_at'])) ?></time>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </section>
                        <div class="bg-white dark:bg-[#353842] p-6 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                            <?php if ($docDetails['tipo_conteudo'] === 'file' && strpos($docDetails['tipo_mime'] ?? '', 'pdf') !== false): ?>
                                <iframe src="../download.php?id=<?= $docDetails['id'] ?>&inline=1" class="w-full h-[650px] rounded-md border border-slate-200 dark:border-[#454956]"></iframe>
                            <?php else: ?>
                                <div class="p-6 text-center bg-slate-50 dark:bg-[#2c2e33] rounded-md border text-xs">Visualização disponível no portal público.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'configuracoes'): ?>
                    <?php require __DIR__ . '/partials/system-settings.php'; ?>
                <?php endif; ?>

                <?php if ($activeTab === 'tags' && $isGlobalAdminCurrent): ?>
                    <section class="space-y-5">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Catálogo transversal</p>
                                <h1 class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100">Tags e correlações</h1>
                                <p class="mt-1 max-w-2xl text-xs text-slate-500 dark:text-slate-400">Autores podem criar tags no conteúdo. Aqui você padroniza nomes, tipos e sinônimos. Tags nunca dão acesso a documentos.</p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-3 py-1.5 text-[11px] font-semibold text-slate-500 dark:bg-[#2c2e33] dark:text-slate-300"><?= count($tagCatalogDetails) ?> no catálogo</span>
                        </div>

                        <form method="POST" action="index.php?tab=tags" class="grid gap-3 rounded-md border border-slate-200 bg-white p-4 shadow-xs dark:border-[#454956] dark:bg-[#353842] sm:grid-cols-[minmax(0,1fr)_11rem_auto]">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="text" name="tag_name" maxlength="80" required class="input-minimal px-3 py-2 text-xs" placeholder="Criar uma tag no catálogo">
                            <select name="tag_type" class="input-minimal px-3 py-2 text-xs">
                                <?php foreach (TagService::TYPES as $tagTypeValue => $tagTypeLabel): ?><option value="<?= htmlspecialchars($tagTypeValue) ?>"><?= htmlspecialchars($tagTypeLabel) ?></option><?php endforeach; ?>
                            </select>
                            <button type="submit" name="tag_admin_action" value="create" class="rounded bg-slate-900 px-4 py-2 text-xs font-semibold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900">Criar tag</button>
                        </form>

                        <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                            <?php if (empty($tagCatalogDetails)): ?>
                                <div class="p-8 text-center text-xs text-slate-400">Ainda não há tags. Elas também podem ser criadas diretamente no formulário de conteúdo.</div>
                            <?php else: ?>
                                <div class="divide-y divide-slate-100 dark:divide-[#454956]">
                                    <?php foreach ($tagCatalogDetails as $tag): ?>
                                        <div class="p-4">
                                            <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
                                                <form method="POST" action="index.php?tab=tags" class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="tag_id" value="<?= (int)$tag['id'] ?>">
                                                    <input type="text" name="tag_name" maxlength="80" value="<?= htmlspecialchars($tag['name']) ?>" class="input-minimal w-40 px-2.5 py-1.5 text-xs font-semibold">
                                                    <select name="tag_type" class="input-minimal px-2.5 py-1.5 text-xs">
                                                        <?php foreach (TagService::TYPES as $tagTypeValue => $tagTypeLabel): ?><option value="<?= htmlspecialchars($tagTypeValue) ?>" <?= $tag['type'] === $tagTypeValue ? 'selected' : '' ?>><?= htmlspecialchars($tagTypeLabel) ?></option><?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="tag_admin_action" value="update" class="rounded border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-[#454956] dark:text-slate-200 dark:hover:bg-[#2c2e33]">Salvar</button>
                                                    <span class="text-[10px] text-slate-400"><?= (int)$tag['document_count'] ?> documento(s) · criada por <?= htmlspecialchars($tag['created_by_name'] ?: 'Sistema') ?></span>
                                                </form>
                                                <form method="POST" action="index.php?tab=tags">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="tag_id" value="<?= (int)$tag['id'] ?>">
                                                    <input type="hidden" name="active" value="<?= $tag['active'] ? '0' : '1' ?>">
                                                    <button type="submit" name="tag_admin_action" value="toggle" class="rounded px-2.5 py-1.5 text-[11px] font-semibold <?= $tag['active'] ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-[#2c2e33] dark:text-slate-300' ?>"><?= $tag['active'] ? 'Ativa — desativar' : 'Inativa — reativar' ?></button>
                                                </form>
                                            </div>
                                            <form method="POST" action="index.php?tab=tags" class="mt-2 flex flex-wrap items-center gap-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="tag_id" value="<?= (int)$tag['id'] ?>">
                                                <span class="text-[10px] font-semibold text-slate-400">Sinônimos:</span>
                                                <?php foreach ($tag['aliases'] as $alias): ?><span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-[#2c2e33] dark:text-slate-300"><?= htmlspecialchars($alias) ?></span><?php endforeach; ?>
                                                <input type="text" name="tag_alias" maxlength="80" class="input-minimal w-36 px-2 py-1 text-[11px]" placeholder="Adicionar sinônimo">
                                                <button type="submit" name="tag_admin_action" value="add_alias" class="text-[11px] font-semibold text-slate-600 hover:underline dark:text-slate-300">Adicionar</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- TAB: EDITAR ESTRUTURA E PERMISSÕES DO RECURSO (CATEGORIA / SUBCATEGORIA / ASSUNTO) -->
                <?php if ($activeTab === 'editar_estrutura'): ?>
                    <?php
                        $resTypeInput = strtolower(trim($_GET['type'] ?? 'categoria'));
                        $resId = (int)($_GET['id'] ?? 0);
                        $resTab = trim($_GET['res_tab'] ?? 'info');
                        if (!in_array($resTab, ['info', 'content', 'permissions'], true)) {
                            $resTab = 'info';
                        }

                        $resType = 'category';
                        if ($resTypeInput === 'subcategoria' || $resTypeInput === 'subcategory') {
                            $resType = 'subcategory';
                        } elseif ($resTypeInput === 'assunto' || $resTypeInput === 'subject') {
                            $resType = 'subject';
                        }

                        // Carregar o recurso do banco
                        $resData = null;
                        $resTypeNameLabel = 'Categoria';
                        $parentBreadcrumbs = [];
                        $canAccessResource = $resId > 0
                            && $permService->canEdit($currentAdminUserId, $resType, $resId);
                        $canManageResourcePermissions = $canAccessResource
                            && $permService->canAdmin($currentAdminUserId, $resType, $resId);
                        if ($resTab === 'permissions' && !$canManageResourcePermissions) {
                            $resTab = 'info';
                        }

                        if ($canAccessResource && $resType === 'category') {
                            $resTypeNameLabel = 'Categoria';
                            $stmtR = $pdo->prepare("SELECT id, name, description, image_path, active FROM categories WHERE id = ?");
                            $stmtR->execute([$resId]);
                            $resData = $stmtR->fetch(PDO::FETCH_ASSOC);
                        } elseif ($canAccessResource && $resType === 'subcategory') {
                            $resTypeNameLabel = 'Subcategoria';
                            $stmtR = $pdo->prepare("
                                SELECT sc.id, sc.category_id, sc.name, sc.description, sc.image_path, sc.active, c.name AS category_name
                                FROM subcategories sc
                                JOIN categories c ON sc.category_id = c.id
                                WHERE sc.id = ?
                            ");
                            $stmtR->execute([$resId]);
                            $resData = $stmtR->fetch(PDO::FETCH_ASSOC);
                            if ($resData) {
                                $parentBreadcrumbs[] = [
                                    'type' => 'categoria',
                                    'id'   => $resData['category_id'],
                                    'name' => $resData['category_name']
                                ];
                            }
                        } elseif ($canAccessResource && $resType === 'subject') {
                            $resTypeNameLabel = 'Assunto';
                            $stmtR = $pdo->prepare("
                                SELECT s.id, s.subcategory_id, s.name, s.description, s.active, 
                                       sc.name AS subcategory_name, sc.category_id, c.name AS category_name
                                FROM subjects s
                                JOIN subcategories sc ON s.subcategory_id = sc.id
                                JOIN categories c ON sc.category_id = c.id
                                WHERE s.id = ?
                            ");
                            $stmtR->execute([$resId]);
                            $resData = $stmtR->fetch(PDO::FETCH_ASSOC);
                            if ($resData) {
                                $parentBreadcrumbs[] = [
                                    'type' => 'categoria',
                                    'id'   => $resData['category_id'],
                                    'name' => $resData['category_name']
                                ];
                                $parentBreadcrumbs[] = [
                                    'type' => 'subcategoria',
                                    'id'   => $resData['subcategory_id'],
                                    'name' => $resData['subcategory_name']
                                ];
                            }
                        }

                        if ($resId <= 0) {
                            $editableResourceCount = 0;
                            foreach ($listCategorias as $treeCategory) {
                                if ($permService->canEdit($currentAdminUserId, 'category', (int)$treeCategory['id'])) {
                                    $editableResourceCount++;
                                }
                            }
                            foreach ($listSubcategorias as $treeSubcategory) {
                                if ($permService->canEdit($currentAdminUserId, 'subcategory', (int)$treeSubcategory['id'])) {
                                    $editableResourceCount++;
                                }
                            }
                            foreach ($listAssuntos as $treeSubject) {
                                if ($permService->canEdit($currentAdminUserId, 'subject', (int)$treeSubject['id'])) {
                                    $editableResourceCount++;
                                }
                            }
                    ?>
                    <div class="space-y-4">
                        <div class="flex flex-col gap-4 rounded-md border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Organização do portal</p>
                                <h1 class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">Editor da Árvore</h1>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Selecione uma categoria, subcategoria ou assunto dentro do seu escopo para editar.
                                </p>
                            </div>
                            <span class="inline-flex w-fit items-center rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1 text-[11px] font-bold text-emerald-700 dark:text-emerald-400">
                                <?= $editableResourceCount ?> recurso<?= $editableResourceCount === 1 ? ' editável' : 's editáveis' ?>
                            </span>
                        </div>

                        <div class="rounded-md border border-slate-200 bg-white shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                            <div class="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-[#454956] sm:flex-row sm:items-center sm:justify-between">
                                <label class="relative block w-full sm:max-w-sm">
                                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
                                    <input type="search" oninput="filterTreeNodes(this.value)" placeholder="Buscar na árvore..." class="input-minimal w-full rounded-md py-2 pl-9 pr-3 text-xs">
                                </label>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="expandAllTreeNodes()" class="rounded-md border border-slate-200 px-3 py-2 text-[11px] font-semibold text-slate-600 hover:bg-slate-50 dark:border-[#454956] dark:text-slate-300 dark:hover:bg-[#2c2e33]">Expandir tudo</button>
                                    <button type="button" onclick="collapseAllTreeNodes()" class="rounded-md border border-slate-200 px-3 py-2 text-[11px] font-semibold text-slate-600 hover:bg-slate-50 dark:border-[#454956] dark:text-slate-300 dark:hover:bg-[#2c2e33]">Recolher</button>
                                </div>
                            </div>

                            <div class="space-y-2 p-4" id="administrative-tree">
                                <?php if (empty($listCategorias)): ?>
                                    <div class="rounded-md border border-dashed border-slate-200 p-8 text-center text-xs text-slate-500 dark:border-[#454956]">
                                        Nenhum recurso editável foi encontrado no seu escopo.
                                    </div>
                                <?php endif; ?>

                                <?php foreach ($listCategorias as $treeCategory): ?>
                                    <?php
                                        $treeCategoryId = (int)$treeCategory['id'];
                                        $treeCategoryEditable = $permService->canEdit($currentAdminUserId, 'category', $treeCategoryId);
                                        $treeCategoryAdmin = $treeCategoryEditable && $permService->canAdmin($currentAdminUserId, 'category', $treeCategoryId);
                                        $treeSubcategories = array_values(array_filter(
                                            $listSubcategorias,
                                            static fn($item) => (int)$item['category_id'] === $treeCategoryId
                                        ));
                                    ?>
                                    <div class="tree-node-group overflow-hidden rounded-md border border-slate-200 dark:border-[#454956]">
                                        <div class="flex items-stretch bg-slate-50 dark:bg-[#2c2e33]">
                                            <?php if (!empty($treeSubcategories)): ?>
                                                <button type="button" onclick="toggleTreeNode(this)" class="tree-toggle-btn flex w-10 shrink-0 items-center justify-center text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-[#3e424e]" aria-label="Expandir categoria">
                                                    <svg class="h-3.5 w-3.5 rotate-90 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/></svg>
                                                </button>
                                            <?php else: ?>
                                                <span class="w-10 shrink-0"></span>
                                            <?php endif; ?>

                                            <?php if ($treeCategoryEditable): ?>
                                                <a href="index.php?tab=editar_estrutura&type=categoria&id=<?= $treeCategoryId ?>&res_tab=info" class="flex min-w-0 flex-1 items-center justify-between gap-3 px-3 py-3 text-decoration-none hover:bg-slate-100 dark:hover:bg-[#3e424e]">
                                            <?php else: ?>
                                                <div class="flex min-w-0 flex-1 items-center justify-between gap-3 px-3 py-3">
                                            <?php endif; ?>
                                                <div class="min-w-0">
                                                    <span class="block truncate text-xs font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($treeCategory['nome']) ?></span>
                                                    <span class="text-[10px] text-slate-400">Categoria · <?= count($treeSubcategories) ?> subcategoria<?= count($treeSubcategories) === 1 ? '' : 's' ?></span>
                                                </div>
                                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-bold uppercase <?= $treeCategoryAdmin ? 'bg-violet-500/10 text-violet-600 dark:text-violet-400' : ($treeCategoryEditable ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400' : 'bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-400') ?>">
                                                    <?= $treeCategoryAdmin ? 'Admin' : ($treeCategoryEditable ? 'Editar' : 'Navegação') ?>
                                                </span>
                                            <?php if ($treeCategoryEditable): ?>
                                                </a>
                                            <?php else: ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($treeSubcategories)): ?>
                                            <div class="tree-branch border-t border-slate-200 dark:border-[#454956]">
                                                <?php foreach ($treeSubcategories as $treeSubcategory): ?>
                                                    <?php
                                                        $treeSubcategoryId = (int)$treeSubcategory['id'];
                                                        $treeSubcategoryEditable = $permService->canEdit($currentAdminUserId, 'subcategory', $treeSubcategoryId);
                                                        $treeSubcategoryAdmin = $treeSubcategoryEditable && $permService->canAdmin($currentAdminUserId, 'subcategory', $treeSubcategoryId);
                                                        $treeSubjects = array_values(array_filter(
                                                            $listAssuntos,
                                                            static fn($item) => (int)$item['subcategory_id'] === $treeSubcategoryId
                                                        ));
                                                    ?>
                                                    <div class="tree-node-group border-b border-slate-100 last:border-b-0 dark:border-[#454956]">
                                                        <div class="flex items-stretch pl-5">
                                                            <?php if (!empty($treeSubjects)): ?>
                                                                <button type="button" onclick="toggleTreeNode(this)" class="tree-toggle-btn flex w-9 shrink-0 items-center justify-center text-slate-400 hover:text-slate-700" aria-label="Expandir subcategoria">
                                                                    <svg class="h-3 w-3 rotate-90 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/></svg>
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="w-9 shrink-0"></span>
                                                            <?php endif; ?>

                                                            <?php if ($treeSubcategoryEditable): ?>
                                                                <a href="index.php?tab=editar_estrutura&type=subcategoria&id=<?= $treeSubcategoryId ?>&res_tab=info" class="flex min-w-0 flex-1 items-center justify-between gap-3 px-3 py-2.5 text-decoration-none hover:bg-slate-50 dark:hover:bg-[#2c2e33]">
                                                            <?php else: ?>
                                                                <div class="flex min-w-0 flex-1 items-center justify-between gap-3 px-3 py-2.5">
                                                            <?php endif; ?>
                                                                <div class="min-w-0">
                                                                    <span class="block truncate text-xs font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($treeSubcategory['nome']) ?></span>
                                                                    <span class="text-[10px] text-slate-400">Subcategoria · <?= count($treeSubjects) ?> assunto<?= count($treeSubjects) === 1 ? '' : 's' ?></span>
                                                                </div>
                                                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-bold uppercase <?= $treeSubcategoryAdmin ? 'bg-violet-500/10 text-violet-600 dark:text-violet-400' : ($treeSubcategoryEditable ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400' : 'bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-400') ?>">
                                                                    <?= $treeSubcategoryAdmin ? 'Admin' : ($treeSubcategoryEditable ? 'Editar' : 'Navegação') ?>
                                                                </span>
                                                            <?php if ($treeSubcategoryEditable): ?>
                                                                </a>
                                                            <?php else: ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <?php if (!empty($treeSubjects)): ?>
                                                            <div class="tree-branch border-t border-slate-100 bg-slate-50/60 dark:border-[#454956] dark:bg-[#2c2e33]/40">
                                                                <?php foreach ($treeSubjects as $treeSubject): ?>
                                                                    <?php
                                                                        $treeSubjectId = (int)$treeSubject['id'];
                                                                        $treeSubjectEditable = $permService->canEdit($currentAdminUserId, 'subject', $treeSubjectId);
                                                                        $treeSubjectAdmin = $treeSubjectEditable && $permService->canAdmin($currentAdminUserId, 'subject', $treeSubjectId);
                                                                    ?>
                                                                    <?php if ($treeSubjectEditable): ?>
                                                                        <a href="index.php?tab=editar_estrutura&type=assunto&id=<?= $treeSubjectId ?>&res_tab=info" class="tree-node-group ml-14 flex items-center justify-between gap-3 border-b border-slate-100 px-3 py-2.5 text-decoration-none last:border-b-0 hover:bg-white dark:border-[#454956] dark:hover:bg-[#353842]">
                                                                            <div class="min-w-0">
                                                                                <span class="block truncate text-xs font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($treeSubject['nome']) ?></span>
                                                                                <span class="text-[10px] text-slate-400">Assunto · <?= (int)$treeSubject['total_docs'] ?> documento<?= (int)$treeSubject['total_docs'] === 1 ? '' : 's' ?></span>
                                                                            </div>
                                                                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-bold uppercase <?= $treeSubjectAdmin ? 'bg-violet-500/10 text-violet-600 dark:text-violet-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400' ?>">
                                                                                <?= $treeSubjectAdmin ? 'Admin' : 'Editar' ?>
                                                                            </span>
                                                                        </a>
                                                                    <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php
                        } elseif (!$resData) {
                            echo "<div class='p-4 bg-red-500/10 text-red-600 rounded-md text-xs font-semibold'>Recurso não encontrado ou fora do seu escopo administrativo.</div>";
                        } else {
                    ?>
                    <div class="space-y-4">
                        <!-- BREADCRUMB E CABEÇALHO DA PASTA/RECURSO -->
                        <div>
                            <nav class="flex items-center gap-1.5 text-xs text-slate-400 font-medium mb-1">
                                <a href="index.php?tab=categorias" class="hover:underline">Gestão da Estrutura</a>
                                <?php foreach ($parentBreadcrumbs as $bc): ?>
                                    <span>/</span>
                                    <?php
                                        $breadcrumbResourceType = $bc['type'] === 'categoria' ? 'category' : 'subcategory';
                                        $canOpenBreadcrumb = $permService->canEdit($currentAdminUserId, $breadcrumbResourceType, (int)$bc['id']);
                                    ?>
                                    <?php if ($canOpenBreadcrumb): ?>
                                        <a href="index.php?tab=editar_estrutura&type=<?= $bc['type'] ?>&id=<?= $bc['id'] ?>&res_tab=info" class="hover:underline"><?= htmlspecialchars($bc['name']) ?></a>
                                    <?php else: ?>
                                        <span title="Item exibido apenas como caminho de navegação"><?= htmlspecialchars($bc['name']) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <span>/</span>
                                <span class="font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($resData['name']) ?></span>
                            </nav>
                            <div class="flex items-center justify-between">
                                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                    <span><?= htmlspecialchars($resData['name']) ?></span>
                                    <span class="text-xs font-normal text-slate-400 dark:text-slate-500">(<?= htmlspecialchars($resTypeNameLabel) ?>)</span>
                                    <?php if ($resData['active']): ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 uppercase">Ativo</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400 border border-slate-300 dark:border-slate-600 uppercase">Inativo</span>
                                    <?php endif; ?>
                                </h1>
                            </div>
                        </div>

                        <!-- NAV ABAS EXIGIDAS: [ Informações ] [ Conteúdo ] [ Permissões ] -->
                        <div class="flex items-center gap-2 border-b border-slate-200 dark:border-[#454956]">
                            <a href="index.php?tab=editar_estrutura&type=<?= $resTypeInput ?>&id=<?= $resId ?>&res_tab=info" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $resTab === 'info' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Informações
                            </a>
                            <a href="index.php?tab=editar_estrutura&type=<?= $resTypeInput ?>&id=<?= $resId ?>&res_tab=content" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $resTab === 'content' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Conteúdo
                            </a>
                            <?php if ($canManageResourcePermissions): ?>
                                <a href="index.php?tab=editar_estrutura&type=<?= $resTypeInput ?>&id=<?= $resId ?>&res_tab=permissions" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $resTab === 'permissions' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                    Permissões
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- ABA 1: INFORMAÇÕES -->
                        <?php if ($resTab === 'info'): ?>
                            <div class="bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] max-w-xl shadow-xs">
                                <?php if ($resType === 'category'): ?>
                                    <form method="POST" action="index.php?tab=categorias" enctype="multipart/form-data" class="space-y-4">
                                        <input type="hidden" name="save_category" value="1">
                                        <input type="hidden" name="id" value="<?= $resData['id'] ?>">
                                        <input type="hidden" name="redirect_tab" value="editar_estrutura&type=categoria&id=<?= $resData['id'] ?>&res_tab=info">
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome da Categoria *</label>
                                            <input type="text" name="nome" required value="<?= htmlspecialchars($resData['name']) ?>" class="input-minimal w-full px-3 py-2 text-xs">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                            <textarea name="descricao" rows="3" class="input-minimal w-full px-3 py-2 text-xs"><?= htmlspecialchars($resData['description']) ?></textarea>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Imagem da categoria</label>
                                            <?php if (!empty($resData['image_path'])): ?>
                                                <div class="mb-2 flex items-center gap-2">
                                                    <img src="../category_image.php?id=<?= (int)$resData['id'] ?>&amp;v=<?= urlencode((string)$resData['image_path']) ?>" alt="Imagem atual da categoria" class="h-10 w-10 rounded object-cover border border-slate-200 dark:border-[#454956]">
                                                    <label class="inline-flex items-center gap-1.5 text-[10px] text-slate-500 dark:text-slate-400"><input type="checkbox" name="remove_category_image" value="1"> Remover imagem atual</label>
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="category_image" accept="image/jpeg,image/png,image/webp" class="block w-full text-[11px] text-slate-500 file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-[10px] file:font-semibold dark:file:bg-[#2c2e33] dark:file:text-slate-200">
                                            <p class="mt-1 text-[10px] text-slate-400">JPG, PNG ou WEBP, até 3 MB.</p>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Status</label>
                                            <select name="status" class="input-minimal w-full px-3 py-2 text-xs">
                                                <option value="ativo" <?= $resData['active'] ? 'selected' : '' ?>>Ativo</option>
                                                <option value="inativo" <?= !$resData['active'] ? 'selected' : '' ?>>Inativo</option>
                                            </select>
                                        </div>
                                        <div class="pt-3 border-t border-slate-100 dark:border-[#454956] flex justify-end">
                                            <button type="submit" class="px-4 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90">Salvar Categoria</button>
                                        </div>
                                    </form>
                                <?php elseif ($resType === 'subcategory'): ?>
                                    <form method="POST" action="index.php?tab=subcategorias" enctype="multipart/form-data" class="space-y-4">
                                        <input type="hidden" name="save_subcategory" value="1">
                                        <input type="hidden" name="id" value="<?= $resData['id'] ?>">
                                        <input type="hidden" name="redirect_tab" value="editar_estrutura&type=subcategoria&id=<?= $resData['id'] ?>&res_tab=info">
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Categoria Pai</label>
                                            <input type="text" disabled value="<?= htmlspecialchars($resData['category_name']) ?>" class="input-minimal w-full px-3 py-2 text-xs bg-slate-100 dark:bg-[#2c2e33]">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome da Subcategoria *</label>
                                            <input type="text" name="nome" required value="<?= htmlspecialchars($resData['name']) ?>" class="input-minimal w-full px-3 py-2 text-xs">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                            <textarea name="descricao" rows="3" class="input-minimal w-full px-3 py-2 text-xs"><?= htmlspecialchars($resData['description']) ?></textarea>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Imagem da subcategoria</label>
                                            <?php if (!empty($resData['image_path'])): ?>
                                                <div class="mb-2 flex items-center gap-2">
                                                    <img src="../subcategory_image.php?id=<?= (int)$resData['id'] ?>&amp;v=<?= urlencode((string)$resData['image_path']) ?>" alt="Imagem atual da subcategoria" class="h-10 w-10 rounded object-cover border border-slate-200 dark:border-[#454956]">
                                                    <label class="inline-flex items-center gap-1.5 text-[10px] text-slate-500 dark:text-slate-400"><input type="checkbox" name="remove_subcategory_image" value="1"> Remover imagem atual</label>
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="subcategory_image" accept="image/jpeg,image/png,image/webp" class="block w-full text-[11px] text-slate-500 file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-[10px] file:font-semibold dark:file:bg-[#2c2e33] dark:file:text-slate-200">
                                            <p class="mt-1 text-[10px] text-slate-400">JPG, PNG ou WEBP, até 3 MB.</p>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Status</label>
                                            <select name="status" class="input-minimal w-full px-3 py-2 text-xs">
                                                <option value="ativo" <?= $resData['active'] ? 'selected' : '' ?>>Ativo</option>
                                                <option value="inativo" <?= !$resData['active'] ? 'selected' : '' ?>>Inativo</option>
                                            </select>
                                        </div>
                                        <div class="pt-3 border-t border-slate-100 dark:border-[#454956] flex justify-end">
                                            <button type="submit" class="px-4 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90">Salvar Subcategoria</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="index.php?tab=assuntos" class="space-y-4">
                                        <input type="hidden" name="save_subject" value="1">
                                        <input type="hidden" name="id" value="<?= $resData['id'] ?>">
                                        <input type="hidden" name="redirect_tab" value="editar_estrutura&type=assunto&id=<?= $resData['id'] ?>&res_tab=info">
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Subcategoria Pai</label>
                                            <input type="text" disabled value="<?= htmlspecialchars($resData['subcategory_name']) ?> (<?= htmlspecialchars($resData['category_name']) ?>)" class="input-minimal w-full px-3 py-2 text-xs bg-slate-100 dark:bg-[#2c2e33]">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome do Assunto *</label>
                                            <input type="text" name="nome" required value="<?= htmlspecialchars($resData['name']) ?>" class="input-minimal w-full px-3 py-2 text-xs">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                            <textarea name="descricao" rows="3" class="input-minimal w-full px-3 py-2 text-xs"><?= htmlspecialchars($resData['description']) ?></textarea>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Status</label>
                                            <select name="status" class="input-minimal w-full px-3 py-2 text-xs">
                                                <option value="ativo" <?= $resData['active'] ? 'selected' : '' ?>>Ativo</option>
                                                <option value="inativo" <?= !$resData['active'] ? 'selected' : '' ?>>Inativo</option>
                                            </select>
                                        </div>
                                        <div class="pt-3 border-t border-slate-100 dark:border-[#454956] flex justify-end">
                                            <button type="submit" class="px-4 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90">Salvar Assunto</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- ABA 2: CONTEÚDO -->
                        <?php if ($resTab === 'content'): ?>
                            <div class="bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] shadow-xs space-y-4">
                                <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">Estrutura Documental da Pasta</h3>
                                <?php if ($resType === 'category'): ?>
                                    <?php
                                        $stmtSubs = $pdo->prepare("SELECT id, name, description, active FROM subcategories WHERE category_id = ? ORDER BY name ASC");
                                        $stmtSubs->execute([$resId]);
                                        $childSubs = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);
                                    ?>
                                    <p class="text-xs text-slate-500">Subcategorias associadas a esta categoria (<?= count($childSubs) ?>):</p>
                                    <div class="divide-y divide-slate-100 dark:divide-[#454956]">
                                        <?php foreach ($childSubs as $cSub): ?>
                                            <div class="py-2 flex items-center justify-between text-xs">
                                                <span class="font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($cSub['name']) ?></span>
                                                <a href="index.php?tab=editar_estrutura&type=subcategoria&id=<?= $cSub['id'] ?>&res_tab=permissions" class="text-amber-600 hover:underline">Gerenciar Subcategoria &rarr;</a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif ($resType === 'subcategory'): ?>
                                    <?php
                                        $stmtAss = $pdo->prepare("SELECT id, name, description, active FROM subjects WHERE subcategory_id = ? ORDER BY name ASC");
                                        $stmtAss->execute([$resId]);
                                        $childAss = $stmtAss->fetchAll(PDO::FETCH_ASSOC);
                                    ?>
                                    <p class="text-xs text-slate-500">Assuntos associados a esta subcategoria (<?= count($childAss) ?>):</p>
                                    <div class="divide-y divide-slate-100 dark:divide-[#454956]">
                                        <?php foreach ($childAss as $cAss): ?>
                                            <div class="py-2 flex items-center justify-between text-xs">
                                                <span class="font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($cAss['name']) ?></span>
                                                <a href="index.php?tab=editar_estrutura&type=assunto&id=<?= $cAss['id'] ?>&res_tab=permissions" class="text-amber-600 hover:underline">Gerenciar Assunto &rarr;</a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <?php
                                        $stmtDocs = $pdo->prepare("SELECT id, title, status FROM documents WHERE subject_id = ? ORDER BY title ASC");
                                        $stmtDocs->execute([$resId]);
                                        $childDocs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
                                    ?>
                                    <p class="text-xs text-slate-500">Documentos vinculados a este assunto (<?= count($childDocs) ?>):</p>
                                    <div class="divide-y divide-slate-100 dark:divide-[#454956]">
                                        <?php foreach ($childDocs as $cDoc): ?>
                                            <div class="py-2 flex items-center justify-between text-xs">
                                                <span class="font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($cDoc['title']) ?></span>
                                                <a href="index.php?tab=detalhes_documento&id=<?= $cDoc['id'] ?>" class="text-amber-600 hover:underline">Ver Documento &rarr;</a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($resTab === 'permissions'): ?>
                            <?php if (!$permService->canAdmin((int)($loggedUser['id'] ?? 0), $resType, $resId)): ?>
                                <div class="p-5 bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 rounded text-xs font-semibold">
                                    Acesso negado. É necessário possuir Admin neste recurso para gerenciar suas permissões.
                                </div>
                            <?php else: ?>
                                <?php
                                    $resourceTitleParts = array_column($parentBreadcrumbs, 'name');
                                    $resourceTitleParts[] = $resData['name'];
                                    $resourceTitle = implode(' > ', $resourceTitleParts);
                                    require __DIR__ . '/partials/permissions-panel.php';
                                ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php } ?>
                <?php endif; ?>

                <?php if ($activeTab === 'grupos'): ?>
                    <?php
                        if (!$isGlobalAdminCurrent) {
                            echo "<div class='p-4 bg-red-500/10 text-red-600 rounded-md text-xs font-semibold'>Apenas administradores podem gerenciar equipes.</div>";
                        } else {
                            $stmtGroups = $pdo->query("
                                SELECT g.id, g.name AS nome, g.description AS descricao, g.active AS ativo, g.created_at, g.updated_at,
                                       COUNT(DISTINCT ug.user_id) AS total_usuarios,
                                       COUNT(DISTINCT p.id) AS total_permissoes
                                FROM groups g
                                LEFT JOIN user_groups ug ON g.id = ug.group_id
                                LEFT JOIN permissions p ON g.id = p.group_id
                                GROUP BY g.id, g.name, g.description, g.active, g.created_at, g.updated_at
                                ORDER BY g.name ASC
                            ");
                            $groupsList = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="space-y-4">
                        <!-- CABEÇALHO DA PÁGINA DE GRUPOS -->
                        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-[#454956]">
                            <div>
                                <nav class="flex items-center gap-1.5 text-xs text-slate-400 font-medium mb-0.5">
                                    <span>Gestão de Acesso</span>
                                    <span>/</span>
                                    <span class="font-bold text-slate-900 dark:text-slate-100">Equipes</span>
                                </nav>
                                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Equipes</h1>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Gerencie equipes, status e seus membros.</p>
                            </div>
                            <button type="button" onclick="document.getElementById('modal-novo-grupo').classList.remove('hidden')" class="bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold px-3.5 py-2 rounded hover:bg-slate-800 transition flex items-center gap-1.5 shadow-xs">
                                <span>+ Nova Equipe</span>
                            </button>
                        </div>

                        <!-- TABELA DENSA DE GRUPOS (ESTILO GRAFANA / COMPACTA) -->
                        <?php if (empty($groupsList)): ?>
                            <div class="p-8 text-center bg-white dark:bg-[#353842] rounded border border-slate-200 dark:border-[#454956]">
                                <p class="text-xs text-slate-400">Nenhuma equipe cadastrada até o momento.</p>
                            </div>
                        <?php else: ?>
                            <div class="bg-white dark:bg-[#353842] rounded border border-slate-200 dark:border-[#454956] shadow-xs overflow-hidden">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs border-collapse">
                                        <thead>
                                            <tr class="bg-slate-50 dark:bg-[#2c2e33] border-b border-slate-200 dark:border-[#454956] text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                                <th class="py-2.5 px-4">Equipe</th>
                                                <th class="py-2.5 px-4 text-center w-28">Usuários</th>
                                                <th class="py-2.5 px-4 text-center w-28">Acessos</th>
                                                <th class="py-2.5 px-4 text-center w-24">Status</th>
                                                <th class="py-2.5 px-4 text-right w-28">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                            <?php foreach ($groupsList as $grp): ?>
                                                <tr class="hover:bg-slate-50/70 dark:hover:bg-[#2c2e33]/50 transition">
                                                    <td class="py-2.5 px-4">
                                                        <div class="flex items-center gap-2">
                                                            <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                                            <div>
                                                                <a href="index.php?tab=editar_grupo&id=<?= $grp['id'] ?>&group_tab=info" class="font-bold text-slate-900 dark:text-slate-100 hover:underline">
                                                                    <?= htmlspecialchars($grp['nome']) ?>
                                                                </a>
                                                                <?php if (!empty($grp['descricao'])): ?>
                                                                    <p class="text-[11px] text-slate-400 truncate max-w-md"><?= htmlspecialchars($grp['descricao']) ?></p>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="py-2.5 px-4 text-center">
                                                        <a href="index.php?tab=editar_grupo&id=<?= $grp['id'] ?>&group_tab=users" class="inline-flex items-center gap-1 font-semibold px-2 py-0.5 rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-700 dark:text-slate-300 hover:bg-slate-200">
                                                            <span><?= (int)$grp['total_usuarios'] ?></span>
                                                        </a>
                                                    </td>
                                                    <td class="py-2.5 px-4 text-center">
                                                        <a href="index.php?tab=editar_grupo&id=<?= $grp['id'] ?>&group_tab=access" class="inline-flex items-center gap-1 font-semibold px-2 py-0.5 rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-700 dark:text-slate-300 hover:bg-slate-200">
                                                            <span><?= (int)$grp['total_permissoes'] ?></span>
                                                        </a>
                                                    </td>
                                                    <td class="py-2.5 px-4 text-center">
                                                        <?php if ($grp['ativo']): ?>
                                                            <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 uppercase">Ativo</span>
                                                        <?php else: ?>
                                                            <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400 border border-slate-300 dark:border-slate-600 uppercase">Inativo</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-2.5 px-4 text-right">
                                                        <div class="flex items-center justify-end gap-1.5">
                                                            <a href="index.php?tab=editar_grupo&id=<?= $grp['id'] ?>&group_tab=info" class="px-2 py-1 rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-700 dark:text-slate-300 hover:bg-slate-200 text-[11px] font-semibold">
                                                                Editar
                                                            </a>
                                                            <form method="POST" action="index.php?tab=grupos" class="inline">
                                                                <input type="hidden" name="group_action" value="toggle_status">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="group_id" value="<?= $grp['id'] ?>">
                                                                <button type="submit" class="px-2 py-1 rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-700 dark:text-slate-300 hover:bg-slate-200 text-[11px] font-semibold" title="Alternar Status">
                                                                    <?= $grp['ativo'] ? 'Desativar' : 'Ativar' ?>
                                                                </button>
                                                            </form>
                                                            <form method="POST" action="index.php?tab=grupos" onsubmit="return confirm('Tem certeza que deseja excluir a equipe <?= htmlspecialchars(addslashes($grp['nome'])) ?>?');" class="inline">
                                                                <input type="hidden" name="group_action" value="delete_group">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="group_id" value="<?= $grp['id'] ?>">
                                                                <button type="submit" class="px-2 py-1 rounded bg-red-500/10 text-red-600 hover:bg-red-500/20 text-[11px] font-semibold" title="Excluir Equipe">
                                                                    Excluir
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- MODAL NOVO GRUPO -->
                        <div id="modal-novo-grupo" class="hidden fixed inset-0 z-50 bg-slate-950/50 backdrop-blur-xs flex items-center justify-center p-4">
                            <div class="bg-white dark:bg-[#353842] max-w-md w-full p-5 rounded border border-slate-200 dark:border-[#454956] shadow-lg">
                                <div class="flex items-center justify-between mb-3 pb-2 border-b border-slate-100 dark:border-[#454956]">
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">Criar nova equipe</h3>
                                    <button type="button" onclick="document.getElementById('modal-novo-grupo').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
                                </div>
                                <form method="POST" action="index.php?tab=grupos" class="space-y-4">
                                    <input type="hidden" name="group_action" value="create_group">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome da equipe *</label>
                                        <input type="text" name="name" required placeholder="Ex: Recursos Humanos, Tecnologia" class="input-minimal w-full px-3 py-2 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                        <textarea name="description" rows="2" placeholder="Finalidade ou setor atendido pela equipe..." class="input-minimal w-full px-3 py-2 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100"></textarea>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" name="active" value="1" id="active_new" checked class="rounded border-slate-300">
                                        <label for="active_new" class="text-xs font-medium text-slate-700 dark:text-slate-300">Equipe ativa</label>
                                    </div>
                                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-[#454956]">
                                        <button type="button" onclick="document.getElementById('modal-novo-grupo').classList.add('hidden')" class="px-3 py-1.5 rounded text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">Cancelar</button>
                                        <button type="submit" class="px-4 py-1.5 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90">Salvar equipe</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                <?php endif; ?>

                <!-- TAB: EDITAR & GERENCIAR GRUPO SELECIONADO -->
                <?php if ($activeTab === 'editar_grupo'): ?>
                    <?php
                        $groupId = (int)($_GET['id'] ?? 0);
                        $groupTab = trim($_GET['group_tab'] ?? 'info');
                        if ($groupTab === 'permissions') {
                            $groupTab = 'access'; // compatibilidade com links antigos
                        }
                        if (!in_array($groupTab, ['info', 'users', 'access'], true)) {
                            $groupTab = 'info';
                        }

                        $stmtSelG = $pdo->prepare("SELECT * FROM groups WHERE id = ?");
                        $stmtSelG->execute([$groupId]);
                        $grpData = $stmtSelG->fetch(PDO::FETCH_ASSOC);

                        if (!$grpData) {
                            echo "<div class='p-4 bg-red-500/10 text-red-600 rounded-md text-xs font-semibold'>Equipe não encontrada.</div>";
                        } else {
                    ?>
                    <div class="space-y-4">
                        <!-- BREADCRUMB E CABEÇALHO -->
                        <div>
                            <nav class="flex items-center gap-1.5 text-xs text-slate-400 font-medium mb-1">
                                <a href="index.php?tab=grupos" class="hover:underline">Gestão de Acesso</a>
                                <span>/</span>
                                <a href="index.php?tab=grupos" class="hover:underline">Equipes</a>
                                <span>/</span>
                                <span class="font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($grpData['name']) ?></span>
                            </nav>
                            <div class="flex items-center justify-between">
                                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                                    <span><?= htmlspecialchars($grpData['name']) ?></span>
                                    <?php if ($grpData['active']): ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 uppercase">Ativo</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400 border border-slate-300 dark:border-slate-600 uppercase">Inativo</span>
                                    <?php endif; ?>
                                </h1>
                            </div>
                        </div>

                        <!-- Identidade da equipe: informações, membros e diagnóstico de acessos -->
                        <div class="flex items-center gap-2 border-b border-slate-200 dark:border-[#454956]">
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=info" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'info' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Informações
                            </a>
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=users" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'users' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Membros
                            </a>
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=access" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'access' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Acessos
                            </a>
                        </div>

                        <!-- CONTEÚDO DA ABA 1: INFORMAÇÕES -->
                        <?php if ($groupTab === 'info'): ?>
                            <div class="bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] max-w-xl shadow-xs">
                                <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=info" class="space-y-4">
                                    <input type="hidden" name="group_action" value="edit_group">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome da equipe *</label>
                                        <input type="text" name="name" required value="<?= htmlspecialchars($grpData['name']) ?>" class="input-minimal w-full px-3 py-2 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                        <textarea name="description" rows="3" class="input-minimal w-full px-3 py-2 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100"><?= htmlspecialchars($grpData['description']) ?></textarea>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" name="active" value="1" id="active_edit" <?= $grpData['active'] ? 'checked' : '' ?> class="rounded border-slate-300">
                                        <label for="active_edit" class="text-xs font-medium text-slate-700 dark:text-slate-300">Equipe ativa</label>
                                    </div>
                                    <div class="pt-3 border-t border-slate-100 dark:border-[#454956] flex justify-end">
                                        <button type="submit" class="px-4 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90">
                                            Salvar Alterações
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- CONTEÚDO DA ABA 2: MEMBROS -->
                        <?php if ($groupTab === 'users'): ?>
                            <?php
                                $stmtGrpUsers = $pdo->prepare("
                                    SELECT u.id, u.name, u.username, u.email, u.role, u.active, ug.created_at AS vinculo_em
                                    FROM users u
                                    JOIN user_groups ug ON u.id = ug.user_id
                                    WHERE ug.group_id = ?
                                    ORDER BY u.name ASC
                                ");
                                $stmtGrpUsers->execute([$groupId]);
                                $groupUsers = $stmtGrpUsers->fetchAll(PDO::FETCH_ASSOC);

                                $stmtAvailable = $pdo->prepare("
                                    SELECT u.id, u.name, u.username, u.email, u.role
                                    FROM users u
                                    WHERE u.active = TRUE
                                      AND u.id NOT IN (SELECT user_id FROM user_groups WHERE group_id = ?)
                                    ORDER BY u.name ASC
                                ");
                                $stmtAvailable->execute([$groupId]);
                                $availableUsers = $stmtAvailable->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                                
                                <!-- COLUNA ESQUERDA: MEMBROS DA EQUIPE -->
                                <div class="lg:col-span-7 bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] shadow-xs space-y-4">
                                    <div class="flex items-center justify-between">
                                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                            Membros Atuais (<?= count($groupUsers) ?>)
                                        </h3>
                                    </div>

                                    <?php if (empty($groupUsers)): ?>
                                        <p class="text-xs text-slate-400 text-center py-6">Nenhum usuário associado a esta equipe ainda.</p>
                                    <?php else: ?>
                                        <div class="divide-y divide-slate-100 dark:divide-[#454956]">
                                            <?php foreach ($groupUsers as $uMember): ?>
                                                <div class="py-2.5 flex items-center justify-between gap-3">
                                                    <div class="flex items-center gap-2.5 min-w-0">
                                                        <div class="w-7 h-7 rounded-full bg-slate-100 dark:bg-[#2c2e33] font-bold text-xs flex items-center justify-center text-slate-700 dark:text-slate-300 shrink-0">
                                                            <?= mb_strtoupper(mb_substr($uMember['name'], 0, 1)) ?>
                                                        </div>
                                                        <div class="truncate text-xs">
                                                            <p class="font-bold text-slate-900 dark:text-slate-100 truncate leading-tight"><?= htmlspecialchars($uMember['name']) ?></p>
                                                            <p class="text-[10px] text-slate-400 font-mono truncate leading-tight">@<?= htmlspecialchars($uMember['username']) ?> • <?= htmlspecialchars($uMember['email']) ?></p>
                                                        </div>
                                                    </div>
                                                    <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=users" onsubmit="return confirm('Remover <?= htmlspecialchars($uMember['name']) ?> desta equipe? O usuário não será excluído do sistema.');">
                                                        <input type="hidden" name="group_action" value="remove_user">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                                        <input type="hidden" name="user_id" value="<?= $uMember['id'] ?>">
                                                        <button type="submit" class="text-xs text-red-600 dark:text-red-400 hover:underline font-semibold">Remover</button>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- COLUNA DIREITA: PESQUISAR E ADICIONAR MEMBRO -->
                                <div class="lg:col-span-5 bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] shadow-xs space-y-4">
                                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Adicionar membro à equipe
                                    </h3>

                                    <?php if (empty($availableUsers)): ?>
                                        <p class="text-xs text-slate-400">Todos os usuários cadastrados já pertencem a esta equipe.</p>
                                    <?php else: ?>
                                        <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=users" class="space-y-3">
                                            <input type="hidden" name="group_action" value="add_user">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                            <input type="hidden" name="user_id" id="team-member-user-id" value="">

                                            <div>
                                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Pesquisar usuário</label>
                                                <div class="relative mb-2">
                                                    <span class="absolute left-3 top-2 text-slate-400" aria-hidden="true">⌕</span>
                                                    <input type="search" id="team-member-search" oninput="filterTeamMemberOptions(this.value)" placeholder="Nome, usuário ou e-mail..." class="input-minimal w-full pl-8 pr-3 py-2 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100">
                                                </div>

                                                <div id="team-member-results" class="max-h-64 overflow-y-auto border-y border-slate-200 dark:border-[#454956] divide-y divide-slate-100 dark:divide-[#454956]" role="listbox" aria-label="Usuários disponíveis">
                                                    <?php foreach ($availableUsers as $availU): ?>
                                                        <button
                                                            type="button"
                                                            role="option"
                                                            aria-selected="false"
                                                            data-team-member-option
                                                            data-user-id="<?= (int)$availU['id'] ?>"
                                                            data-search="<?= htmlspecialchars(mb_strtolower($availU['name'] . ' ' . $availU['username'] . ' ' . $availU['email']), ENT_QUOTES) ?>"
                                                            onclick="selectTeamMember(this)"
                                                            class="w-full flex items-center justify-between gap-3 px-2 py-2.5 text-left hover:bg-slate-50 dark:hover:bg-[#2c2e33] transition"
                                                        >
                                                            <span class="flex items-center gap-2.5 min-w-0">
                                                                <span class="w-7 h-7 rounded-full bg-slate-100 dark:bg-[#2c2e33] flex items-center justify-center text-xs font-bold shrink-0"><?= htmlspecialchars(mb_strtoupper(mb_substr($availU['name'], 0, 1))) ?></span>
                                                                <span class="min-w-0">
                                                                    <strong class="block text-xs truncate"><?= htmlspecialchars($availU['name']) ?></strong>
                                                                    <small class="block text-[10px] text-slate-400 truncate">@<?= htmlspecialchars($availU['username']) ?> · <?= htmlspecialchars($availU['email']) ?></small>
                                                                </span>
                                                            </span>
                                                            <span data-team-member-check class="text-emerald-600 opacity-0 font-bold" aria-hidden="true">✓</span>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                                <p id="team-member-empty" class="hidden text-xs text-slate-400 text-center py-4">Nenhum usuário encontrado.</p>
                                            </div>

                                            <button type="submit" id="team-member-submit" disabled class="w-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold py-2 rounded hover:opacity-90 transition disabled:opacity-40 disabled:cursor-not-allowed">
                                                Adicionar à equipe
                                            </button>
                                        </form>

                                        <script>
                                            function filterTeamMemberOptions(query) {
                                                const q = query.toLowerCase().trim();
                                                const options = document.querySelectorAll('[data-team-member-option]');
                                                let visibleCount = 0;
                                                options.forEach(option => {
                                                    const matches = !q || (option.dataset.search || '').includes(q);
                                                    option.classList.toggle('hidden', !matches);
                                                    if (matches) visibleCount += 1;
                                                });
                                                document.getElementById('team-member-empty').classList.toggle('hidden', visibleCount > 0);
                                            }

                                            function selectTeamMember(selected) {
                                                document.querySelectorAll('[data-team-member-option]').forEach(option => {
                                                    const active = option === selected;
                                                    option.setAttribute('aria-selected', active ? 'true' : 'false');
                                                    option.classList.toggle('bg-emerald-50', active);
                                                    option.classList.toggle('dark:bg-emerald-950/20', active);
                                                    option.querySelector('[data-team-member-check]').classList.toggle('opacity-0', !active);
                                                });
                                                document.getElementById('team-member-user-id').value = selected.dataset.userId;
                                                document.getElementById('team-member-submit').disabled = false;
                                            }
                                        </script>
                                    <?php endif; ?>
                                </div>

                            </div>
                        <?php endif; ?>

                        <!-- ABA ACESSOS: diagnóstico das regras diretas da equipe -->
                        <?php if ($groupTab === 'access'): ?>
                            <?php $groupPermissions = $permService->getGroupPermissions($groupId, false); ?>
                            <section class="bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] rounded p-5">
                                <div class="mb-4">
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">Acessos diretos</h3>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                        Visão diagnóstica. Para alterar uma regra, abra as permissões do recurso.
                                    </p>
                                    <?php if (!$grpData['active']): ?>
                                        <p class="text-[11px] text-amber-700 dark:text-amber-400 mt-2">
                                            Esta equipe está inativa; as regras abaixo permanecem salvas, mas não concedem acesso enquanto ela estiver inativa.
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <?php if (empty($groupPermissions)): ?>
                                    <p class="text-xs text-slate-400 py-5 text-center">Nenhum acesso direto atribuído a esta equipe.</p>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-left text-xs border-collapse">
                                            <thead>
                                                <tr class="border-b border-slate-200 dark:border-[#454956] text-[10px] uppercase tracking-wider text-slate-500">
                                                    <th class="py-2 pr-4">Recurso</th>
                                                    <th class="py-2 w-28">Permissão</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                                <?php foreach ($groupPermissions as $groupPermission): ?>
                                                    <tr>
                                                        <td class="py-3 pr-4">
                                                            <a class="font-semibold text-slate-900 dark:text-slate-100 hover:underline" href="index.php?tab=editar_estrutura&type=<?= urlencode($groupPermission['resource_type']) ?>&id=<?= (int)$groupPermission['resource_id'] ?>&res_tab=permissions">
                                                                <?= htmlspecialchars(str_replace(' / ', ' > ', $groupPermission['resource_path'])) ?>
                                                            </a>
                                                            <small class="block text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars($groupPermission['resource_type_label']) ?></small>
                                                        </td>
                                                        <td class="py-3 font-semibold uppercase"><?= htmlspecialchars($groupPermission['permission_level']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                    </div>
                    <?php } ?>
                <?php endif; ?>

                <!-- TAB: LISTAGEM DE USUÁRIOS DO SISTEMA -->
                <?php if ($activeTab === 'usuarios'): ?>
                    <?php
                        $currentAdminUserId = (int)($loggedUser['id'] ?? 0);
                        $isGlobalAdminCurrent = $permService->isGlobalAdmin($currentAdminUserId);
                        $usersList = $permService->getUsersForAdministrativeScope($currentAdminUserId);
                    ?>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-[#454956]">
                            <div>
                                <nav class="flex items-center gap-1.5 text-xs text-slate-400 font-medium mb-0.5">
                                    <span>Gestão de Acesso</span>
                                    <span>/</span>
                                    <span class="font-bold text-slate-900 dark:text-slate-100">Usuários</span>
                                </nav>
                                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100"><?= $isGlobalAdminCurrent ? 'Usuários do Sistema' : 'Usuários da sua área' ?></h1>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    <?= $isGlobalAdminCurrent
                                        ? 'Diagnóstico de permissões e controle de acesso individual.'
                                        : 'Somente pessoas com acesso direto ou via equipe no seu ramo autorizado são exibidas.' ?>
                                </p>
                            </div>
                        </div>

                        <?php if ($isGlobalAdminCurrent): ?>
                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Integração corporativa</p>
                                        <h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Importar usuário do Active Directory</h2>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Informe somente o usuário corporativo. <?= htmlspecialchars($appName) ?> confirma que ele existe no AD e traz nome, e-mail e identificação automaticamente.</p>
                                    </div>
                                    <span class="w-fit rounded-full bg-sky-500/10 px-2.5 py-1 text-[10px] font-bold text-sky-700 dark:text-sky-300">Somente contas AD</span>
                                </div>

                                <form method="POST" action="index.php?tab=usuarios" class="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-end dark:border-[#454956]">
                                    <input type="hidden" name="create_user" value="1">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <label class="block sm:w-32"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Domínio *</span><select name="ad_domain" class="input-minimal w-full px-3 py-2 text-xs"><?php foreach ($availableAdDomains as $domain): ?><option value="<?= htmlspecialchars($domain) ?>"><?= htmlspecialchars($domain === 'SAUDE' ? 'SAÚDE' : $domain) ?></option><?php endforeach; ?></select></label>
                                    <label class="block flex-1"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Usuário AD *</span><input type="text" name="ad_username" required maxlength="100" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex.: ana.silva" autocomplete="off"><span class="mt-1 block text-[10px] text-slate-400">A conta precisa já existir e estar ativa no Active Directory.</span></label>
                                    <button type="submit" class="inline-flex items-center justify-center gap-1.5 rounded-md bg-slate-900 px-4 py-2 text-xs font-bold text-white transition hover:opacity-90 dark:bg-white dark:text-slate-900"><svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>Importar do AD</button>
                                </form>
                            </section>
                        <?php endif; ?>

                        <div class="bg-white dark:bg-[#353842] rounded border border-slate-200 dark:border-[#454956] shadow-xs overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs border-collapse">
                                    <thead>
                                        <tr class="bg-slate-50 dark:bg-[#2c2e33] border-b border-slate-200 dark:border-[#454956] text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                            <th class="py-2.5 px-4">Usuário</th>
                                            <th class="py-2.5 px-4">E-mail / Username</th>
                                            <th class="py-2.5 px-4 text-center">Perfil</th>
                                            <th class="py-2.5 px-4 text-center">Equipes</th>
                                            <th class="py-2.5 px-4 text-center">Status</th>
                                            <th class="py-2.5 px-4 text-right">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                        <?php if (empty($usersList)): ?>
                                            <tr><td colspan="6" class="py-8 px-4 text-center text-slate-400">Nenhum usuário vinculado à sua área.</td></tr>
                                        <?php endif; ?>
                                        <?php foreach ($usersList as $uRow): ?>
                                            <tr class="hover:bg-slate-50/70 dark:hover:bg-[#2c2e33]/50 transition">
                                                <td class="py-2.5 px-4 font-bold text-slate-900 dark:text-slate-100">
                                                    <?= htmlspecialchars($uRow['name']) ?>
                                                </td>
                                                <td class="py-2.5 px-4 text-slate-500 dark:text-slate-400 font-mono">
                                                    @<?= htmlspecialchars($uRow['username']) ?> • <?= htmlspecialchars($uRow['email']) ?>
                                                </td>
                                                <td class="py-2.5 px-4 text-center">
                                                    <?php if (strtolower($uRow['role']) === 'admin'): ?>
                                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-purple-500/15 text-purple-700 dark:text-purple-300 border border-purple-500/30 uppercase">Admin Global</span>
                                                    <?php else: ?>
                                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700 uppercase">Usuário</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-2.5 px-4 text-center font-mono font-bold">
                                                    <?= $uRow['total_grupos'] ?>
                                                </td>
                                                <td class="py-2.5 px-4 text-center">
                                                    <?php if ($uRow['active']): ?>
                                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 uppercase">Ativo</span>
                                                    <?php else: ?>
                                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-slate-200 dark:bg-slate-700 text-slate-500 uppercase">Inativo</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-2.5 px-4 text-right">
                                                    <a href="index.php?tab=editar_usuario&id=<?= $uRow['id'] ?>&user_tab=access" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-[11px] font-semibold hover:opacity-90 transition shadow-xs">
                                                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                                        <span>Acessos Efetivos</span>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- TAB: DIAGNÓSTICO DE ACESSOS EFETIVOS DO USUÁRIO -->
                <?php if ($activeTab === 'editar_usuario'): ?>
                    <?php
                        $targetUserId = (int)($_GET['id'] ?? 0);
                        $userTab = trim($_GET['user_tab'] ?? 'access');
                        if (!in_array($userTab, ['info', 'teams', 'access'], true)) {
                            $userTab = 'access';
                        }
                        $accessFilter = strtolower(trim($_GET['filter'] ?? 'all'));
                        if (!in_array($accessFilter, ['all', 'direct', 'groups', 'inherited'])) {
                            $accessFilter = 'all';
                        }

                        $currentAdminUserId = (int)($loggedUser['id'] ?? 0);
                        $canInspectTargetUser = $permService->canViewUserInAdministrativeScope($currentAdminUserId, $targetUserId);
                        $diagnosis = $canInspectTargetUser
                            ? $permService->getUserEffectiveAccessDiagnosis($targetUserId, $accessFilter)
                            : ['user' => null, 'is_global_admin' => false, 'active_groups' => [], 'resources' => []];
                        if ($canInspectTargetUser) {
                            $diagnosis = $permService->filterDiagnosisToAdministrativeScope($currentAdminUserId, $diagnosis);
                        }
                        $uData = $diagnosis['user'];
                        $userTeams = [];
                        $directUserPermissions = [];
                        if ($uData) {
                            $userTeams = $permService->getUserTeamsForAdministrativeScope($currentAdminUserId, $targetUserId);
                            if ($isGlobalAdminCurrent) {
                                $directPermissionsStmt = $pdo->prepare('
                                    SELECT
                                        p.id,
                                        p.permission_level,
                                        CASE
                                            WHEN p.category_id IS NOT NULL THEN \'category\'
                                            WHEN p.subcategory_id IS NOT NULL THEN \'subcategory\'
                                            ELSE \'subject\'
                                        END AS resource_type,
                                        CASE
                                            WHEN p.category_id IS NOT NULL THEN p.category_id
                                            WHEN p.subcategory_id IS NOT NULL THEN p.subcategory_id
                                            ELSE p.subject_id
                                        END AS resource_id,
                                        CASE
                                            WHEN p.category_id IS NOT NULL THEN c.name
                                            WHEN p.subcategory_id IS NOT NULL THEN sc_category.name || \' › \' || sc.name
                                            ELSE subject_category.name || \' › \' || subject_subcategory.name || \' › \' || s.name
                                        END AS resource_path
                                    FROM permissions p
                                    LEFT JOIN categories c ON c.id = p.category_id
                                    LEFT JOIN subcategories sc ON sc.id = p.subcategory_id
                                    LEFT JOIN categories sc_category ON sc_category.id = sc.category_id
                                    LEFT JOIN subjects s ON s.id = p.subject_id
                                    LEFT JOIN subcategories subject_subcategory ON subject_subcategory.id = s.subcategory_id
                                    LEFT JOIN categories subject_category ON subject_category.id = subject_subcategory.category_id
                                    WHERE p.user_id = :user_id
                                    ORDER BY resource_type ASC, resource_path ASC
                                ');
                                $directPermissionsStmt->execute([':user_id' => $targetUserId]);
                                $directUserPermissions = $directPermissionsStmt->fetchAll(PDO::FETCH_ASSOC);
                            }
                        }

                        if (!$uData) {
                            echo "<div class='p-4 bg-red-500/10 text-red-600 rounded-md text-xs font-semibold'>Usuário não encontrado ou fora da sua área autorizada.</div>";
                        } else {
                    ?>
                    <div class="space-y-4">
                        <!-- BREADCRUMB E CABEÇALHO DO USUÁRIO -->
                        <div>
                            <nav class="flex items-center gap-1.5 text-xs text-slate-400 font-medium mb-1">
                                <a href="index.php?tab=usuarios" class="hover:underline">Gestão de Usuários</a>
                                <span>/</span>
                                <span class="font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($uData['name']) ?></span>
                                <span>/</span>
                                <span class="text-slate-500"><?= $userTab === 'info' ? 'Informações' : ($userTab === 'teams' ? 'Equipes' : 'Acessos') ?></span>
                            </nav>
                            <div class="flex items-center justify-between">
                                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                                    <span><?= htmlspecialchars($uData['name']) ?></span>
                                    <span class="text-xs font-mono text-slate-400 font-normal">(@<?= htmlspecialchars($uData['username']) ?>)</span>
                                </h1>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 border-b border-slate-200 dark:border-[#454956]">
                            <a href="index.php?tab=editar_usuario&id=<?= $targetUserId ?>&user_tab=info" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $userTab === 'info' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500' ?>">Informações</a>
                            <a href="index.php?tab=editar_usuario&id=<?= $targetUserId ?>&user_tab=teams" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $userTab === 'teams' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500' ?>">Equipes</a>
                            <a href="index.php?tab=editar_usuario&id=<?= $targetUserId ?>&user_tab=access" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $userTab === 'access' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500' ?>">Acessos</a>
                        </div>

                        <?php if ($userTab === 'info'): ?>
                            <section class="bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] rounded p-5 max-w-2xl">
                                <h2 class="text-sm font-bold mb-4">Informações do usuário</h2>
                                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-xs">
                                    <div><dt class="text-slate-400 mb-1">Nome</dt><dd class="font-semibold"><?= htmlspecialchars($uData['name']) ?></dd></div>
                                    <div><dt class="text-slate-400 mb-1">Usuário</dt><dd class="font-mono">@<?= htmlspecialchars($uData['username']) ?></dd></div>
                                    <div><dt class="text-slate-400 mb-1">E-mail</dt><dd><?= htmlspecialchars($uData['email']) ?></dd></div>
                                    <div><dt class="text-slate-400 mb-1">Perfil</dt><dd class="uppercase font-semibold"><?= htmlspecialchars($uData['role']) ?></dd></div>
                                    <div><dt class="text-slate-400 mb-1">Status</dt><dd class="font-semibold"><?= $uData['active'] ? 'Ativo' : 'Inativo' ?></dd></div>
                                </dl>
                            </section>
                        <?php endif; ?>

                        <?php if ($userTab === 'teams'): ?>
                            <section class="bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] rounded p-5">
                                <h2 class="text-sm font-bold">Equipes do usuário</h2>
                                <p class="text-xs text-slate-500 mt-1 mb-4">A associação é administrada na aba Membros de cada equipe.</p>
                                <?php if (empty($userTeams)): ?>
                                    <p class="text-xs text-slate-400 py-5 text-center">Este usuário não pertence a nenhuma equipe.</p>
                                <?php else: ?>
                                    <div class="divide-y divide-slate-100 dark:divide-[#454956]">
                                        <?php foreach ($userTeams as $userTeam): ?>
                                            <div class="py-3 flex items-center justify-between gap-4 text-xs">
                                                <div>
                                                    <a href="index.php?tab=editar_grupo&id=<?= (int)$userTeam['id'] ?>&group_tab=users" class="font-semibold text-slate-900 dark:text-slate-100 hover:underline"><?= htmlspecialchars($userTeam['name']) ?></a>
                                                    <p class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars($userTeam['description'] ?: 'Sem descrição') ?></p>
                                                </div>
                                                <span class="text-[10px] font-semibold <?= $userTeam['active'] ? 'text-emerald-600' : 'text-amber-600' ?>"><?= $userTeam['active'] ? 'ATIVA' : 'INATIVA' ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <?php if ($userTab === 'access'): ?>
                        <?php if ($isGlobalAdminCurrent && !$diagnosis['is_global_admin']): ?>
                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Permissão individual</p>
                                        <h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Liberar acesso direto para <?= htmlspecialchars($uData['name']) ?></h2>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">A regra vale somente para esta pessoa. Para liberar a mesma área a várias pessoas, use uma equipe.</p>
                                    </div>
                                    <span class="w-fit rounded-full bg-sky-500/10 px-2.5 py-1 text-[10px] font-bold text-sky-700 dark:text-sky-300">Acesso direto</span>
                                </div>

                                <form method="POST" action="index.php?tab=editar_usuario&id=<?= $targetUserId ?>&user_tab=access" class="mt-4 grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:items-end dark:border-[#454956]">
                                    <input type="hidden" name="save_direct_user_permission" value="1">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">
                                    <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Área *</span>
                                        <select name="direct_resource" required class="input-minimal w-full px-3 py-2 text-xs">
                                            <option value="">Selecione categoria, subcategoria ou assunto</option>
                                            <?php if (!empty($listCategorias)): ?>
                                                <optgroup label="Categorias">
                                                    <?php foreach ($listCategorias as $permissionCategory): ?>
                                                        <?php if ($permissionCategory['active']): ?>
                                                            <option value="category:<?= (int)$permissionCategory['id'] ?>">Categoria · <?= htmlspecialchars($permissionCategory['nome']) ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                            <?php if (!empty($listSubcategorias)): ?>
                                                <optgroup label="Subcategorias">
                                                    <?php foreach ($listSubcategorias as $permissionSubcategory): ?>
                                                        <?php if ($permissionSubcategory['active']): ?>
                                                            <option value="subcategory:<?= (int)$permissionSubcategory['id'] ?>"><?= htmlspecialchars($permissionSubcategory['categoria_nome']) ?> › <?= htmlspecialchars($permissionSubcategory['nome']) ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                            <?php if (!empty($listAssuntos)): ?>
                                                <optgroup label="Assuntos">
                                                    <?php foreach ($listAssuntos as $permissionSubject): ?>
                                                        <?php if ($permissionSubject['active']): ?>
                                                            <option value="subject:<?= (int)$permissionSubject['id'] ?>"><?= htmlspecialchars($permissionSubject['categoria_nome']) ?> › <?= htmlspecialchars($permissionSubject['subcategoria_nome']) ?> › <?= htmlspecialchars($permissionSubject['nome']) ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                        </select>
                                    </label>
                                    <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Nível *</span>
                                        <select name="permission_level" required class="input-minimal w-full px-3 py-2 text-xs">
                                            <option value="view">Visualizar</option>
                                            <option value="edit">Editar</option>
                                            <option value="admin">Administrar</option>
                                        </select>
                                    </label>
                                    <button type="submit" class="inline-flex items-center justify-center gap-1.5 rounded-md bg-slate-900 px-4 py-2 text-xs font-bold text-white transition hover:opacity-90 dark:bg-white dark:text-slate-900">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                        Salvar acesso
                                    </button>
                                </form>

                                <?php if (!empty($directUserPermissions)): ?>
                                    <div class="mt-4 overflow-hidden rounded-md border border-slate-200 dark:border-[#454956]">
                                        <div class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:border-[#454956] dark:bg-[#2c2e33]">Acessos individuais configurados</div>
                                        <div class="divide-y divide-slate-100 dark:divide-[#454956]">
                                            <?php foreach ($directUserPermissions as $directPermission): ?>
                                                <?php
                                                    $directLevel = strtolower((string)$directPermission['permission_level']);
                                                    $directLevelClass = $directLevel === 'admin'
                                                        ? 'bg-red-500/15 text-red-700 dark:text-red-300 border-red-500/30'
                                                        : ($directLevel === 'edit'
                                                            ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30'
                                                            : 'bg-blue-500/15 text-blue-700 dark:text-blue-300 border-blue-500/30');
                                                ?>
                                                <div class="flex flex-col gap-2 px-3 py-2.5 text-xs sm:flex-row sm:items-center sm:justify-between">
                                                    <div class="min-w-0">
                                                        <a href="index.php?tab=editar_estrutura&type=<?= urlencode($directPermission['resource_type']) ?>&id=<?= (int)$directPermission['resource_id'] ?>&res_tab=permissions" class="block truncate font-semibold text-slate-900 hover:underline dark:text-slate-100"><?= htmlspecialchars((string)$directPermission['resource_path']) ?></a>
                                                        <span class="text-[10px] uppercase tracking-wide text-slate-400"><?= htmlspecialchars((string)$directPermission['resource_type']) ?></span>
                                                    </div>
                                                    <div class="flex shrink-0 items-center gap-2">
                                                        <span class="rounded border px-2 py-0.5 text-[10px] font-bold uppercase <?= $directLevelClass ?>"><?= htmlspecialchars($directLevel) ?></span>
                                                        <form method="POST" action="index.php?tab=editar_usuario&id=<?= $targetUserId ?>&user_tab=access" onsubmit="return confirm('Remover este acesso individual?');">
                                                            <input type="hidden" name="remove_direct_user_permission" value="1">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">
                                                            <input type="hidden" name="permission_id" value="<?= (int)$directPermission['id'] ?>">
                                                            <button type="submit" class="rounded px-2 py-1 text-[11px] font-semibold text-red-600 transition hover:bg-red-500/10 dark:text-red-300">Remover</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="mt-4 text-[11px] text-slate-400">Nenhum acesso individual configurado para este usuário.</p>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>
                        <!-- CARD RESUMO DO USUÁRIO & GRUPOS ATIVOS -->
                        <div class="bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] shadow-xs space-y-3">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2 border-b border-slate-100 dark:border-[#454956] text-xs">
                                <div>
                                    <span class="font-bold text-slate-900 dark:text-slate-100 block">Equipes ativas do usuário:</span>
                                    <?php if (empty($diagnosis['active_groups'])): ?>
                                        <span class="text-slate-400 text-[11px] block mt-0.5">Nenhuma equipe ativa associada a este usuário.</span>
                                    <?php else: ?>
                                        <div class="flex flex-wrap gap-1.5 mt-1">
                                            <?php foreach ($diagnosis['active_groups'] as $ag): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[11px] font-semibold rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-800 dark:text-slate-200 border border-slate-200 dark:border-[#454956]">
                                                    <svg class="w-3 h-3 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg><?= htmlspecialchars($ag['name']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] text-slate-400 font-mono block">E-mail: <?= htmlspecialchars($uData['email']) ?></span>
                                    <span class="text-[10px] text-slate-400 font-mono block uppercase">Perfil: <?= htmlspecialchars($uData['role']) ?></span>
                                </div>
                            </div>

                            <!-- BANNER PARA ADMIN GLOBAL -->
                            <?php if ($diagnosis['is_global_admin']): ?>
                                <div class="p-4 bg-purple-500/10 border border-purple-500/30 rounded text-xs space-y-1">
                                    <div class="flex items-center gap-2 font-bold text-purple-700 dark:text-purple-300 text-sm">
                                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                        <span>Administrador Global — Acesso Completo</span>
                                    </div>
                                    <p class="text-purple-900 dark:text-purple-200 leading-relaxed">
                                        Este usuário possui perfil de <strong>Administrador Global</strong> (<code>role = 'admin'</code>). Ele possui privilégio total e irrestrito (<strong>View, Edit e Admin</strong>) sobre todas as categorias, subcategorias, assuntos e documentos do sistema.
                                    </p>
                                </div>
                            <?php else: ?>

                                <!-- SELETOR DE FILTROS -->
                                <div class="flex items-center justify-between pt-2">
                                    <div class="flex items-center gap-1 bg-slate-100 dark:bg-[#2c2e33] p-1 rounded border border-slate-200 dark:border-[#454956] text-xs">
                                        <a href="index.php?tab=editar_usuario&id=<?= $targetUserId ?>&user_tab=access&filter=all" class="px-3 py-1 rounded font-semibold transition <?= $accessFilter === 'all' ? 'bg-white dark:bg-[#353842] text-slate-900 dark:text-white shadow-xs' : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' ?>">
                                            Todos os Acessos
                                        </a>
                                        <a href="index.php?tab=editar_usuario&id=<?= $targetUserId ?>&user_tab=access&filter=direct" class="px-3 py-1 rounded font-semibold transition <?= $accessFilter === 'direct' ? 'bg-white dark:bg-[#353842] text-slate-900 dark:text-white shadow-xs' : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' ?>">
                                            Diretos
                                        </a>
                                        <a href="index.php?tab=editar_usuario&id=<?= $targetUserId ?>&user_tab=access&filter=groups" class="px-3 py-1 rounded font-semibold transition <?= $accessFilter === 'groups' ? 'bg-white dark:bg-[#353842] text-slate-900 dark:text-white shadow-xs' : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' ?>">
                                            Via Equipes
                                        </a>
                                        <a href="index.php?tab=editar_usuario&id=<?= $targetUserId ?>&user_tab=access&filter=inherited" class="px-3 py-1 rounded font-semibold transition <?= $accessFilter === 'inherited' ? 'bg-white dark:bg-[#353842] text-slate-900 dark:text-white shadow-xs' : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' ?>">
                                            Herdados
                                        </a>
                                    </div>
                                    <span class="text-xs font-bold text-slate-500">
                                        <?= count($diagnosis['resources']) ?> área(s) encontrada(s)
                                    </span>
                                </div>

                                <!-- TABELA DE DIAGNÓSTICO DE ACESSOS EFETIVOS -->
                                <?php if (empty($diagnosis['resources'])): ?>
                                    <div class="p-8 text-center text-slate-400 text-xs bg-slate-50 dark:bg-[#2c2e33] rounded">
                                        Nenhuma permissão efetiva encontrada para os filtros selecionados.
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto pt-2">
                                        <table class="w-full text-left text-xs border-collapse">
                                            <thead>
                                                <tr class="bg-slate-50 dark:bg-[#2c2e33] border-b border-slate-200 dark:border-[#454956] text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                                    <th class="py-2.5 px-4">Recurso / Área</th>
                                                    <th class="py-2.5 px-4 text-center w-36">Acesso Efetivo</th>
                                                    <th class="py-2.5 px-4 text-left">Origem e Fontes Detalhadas</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                                <?php foreach ($diagnosis['resources'] as $resDiag): ?>
                                                    <tr class="hover:bg-slate-50/70 dark:hover:bg-[#2c2e33]/50 transition">
                                                        <td class="py-3 px-4">
                                                            <a href="index.php?tab=editar_estrutura&type=<?= urlencode($resDiag['resource_type']) ?>&id=<?= (int)$resDiag['resource_id'] ?>&res_tab=permissions" class="font-bold text-slate-900 dark:text-slate-100 block text-xs hover:underline">
                                                                <?= htmlspecialchars($resDiag['resource_path']) ?>
                                                            </a>
                                                            <span class="text-[10px] text-slate-400 font-mono block">
                                                                <?= htmlspecialchars($resDiag['resource_type_label']) ?>
                                                            </span>
                                                        </td>
                                                        <td class="py-3 px-4 text-center">
                                                            <?php
                                                                $effLvl = $resDiag['effective_level'];
                                                                $effClass = 'bg-slate-100 text-slate-700';
                                                                if ($effLvl === 'view') $effClass = 'bg-blue-500/15 text-blue-700 border-blue-500/30';
                                                                if ($effLvl === 'edit') $effClass = 'bg-amber-500/15 text-amber-700 border-amber-500/30';
                                                                if ($effLvl === 'admin') $effClass = 'bg-red-500/15 text-red-700 border-red-500/30';
                                                            ?>
                                                            <span class="px-3 py-1 text-[11px] font-bold rounded border uppercase <?= $effClass ?>">
                                                                <?= strtoupper($effLvl) ?>
                                                            </span>
                                                        </td>
                                                        <td class="py-3 px-4 text-left space-y-1">
                                                            <?php foreach ($resDiag['sources'] as $srcItem): ?>
                                                                <div class="flex items-center gap-2 text-xs">
                                                                    <span class="w-2 h-2 rounded-full bg-slate-400 shrink-0"></span>
                                                                    <span class="text-slate-700 dark:text-slate-300">
                                                                        <?= htmlspecialchars($srcItem['description']) ?>
                                                                    </span>
                                                                </div>
                                                            <?php endforeach; ?>

                                                            <?php if (!empty($resDiag['explanation'])): ?>
                                                                <div class="mt-1.5 p-2 bg-amber-500/10 border border-amber-500/20 rounded text-[11px] text-amber-800 dark:text-amber-300 flex items-center gap-1.5 font-medium">
                                                                    <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 01-2 2h-4a2 2 0 01-2-2v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                                                    <span><?= htmlspecialchars($resDiag['explanation']) ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php } ?>
                <?php endif; ?>

            </main>
        </div>

        <script>
            function toggleSubmenu(id) {
                const sub = document.getElementById(id);
                const arrow = document.getElementById(id + '-arrow') || document.getElementById('submenu-arrow');
                if (sub.classList.contains('hidden')) {
                    sub.classList.remove('hidden');
                    if (arrow) arrow.style.transform = 'rotate(0deg)';
                } else {
                    sub.classList.add('hidden');
                    if (arrow) arrow.style.transform = 'rotate(-90deg)';
                }
            }

            function toggleMobileSidebar() {
                const sb = document.getElementById('sidebar-menu');
                const overlay = document.getElementById('sidebar-overlay');
                sb.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            }

            function toggleActionMenu(id, event) {
                event.stopPropagation();
                const allMenus = document.querySelectorAll('[id^="action-menu-"]');
                allMenus.forEach(m => {
                    if (m.id !== 'action-menu-' + id) m.classList.add('hidden');
                });
                const targetMenu = document.getElementById('action-menu-' + id);
                if (targetMenu) targetMenu.classList.toggle('hidden');
            }

            function toggleSelectAll(master) {
                const checkboxes = document.querySelectorAll('.batch-checkbox');
                checkboxes.forEach(c => c.checked = master.checked);
            }

            document.addEventListener('click', () => {
                const allMenus = document.querySelectorAll('[id^="action-menu-"]');
                allMenus.forEach(m => m.classList.add('hidden'));
            });

            const hierarchyDataFilter = <?= json_encode($hierarchyMap) ?>;
            const currentFilterCat = <?= json_encode($filterCat) ?>;
            const currentFilterSub = <?= json_encode($filterSubcat) ?>;
            const currentFilterAss = <?= json_encode($filterAssunto) ?>;

            function onFilterCategoryChange() {
                const cat = document.getElementById('filter-cat').value;
                const subSelect = document.getElementById('filter-subcat');
                const assSelect = document.getElementById('filter-assunto');

                subSelect.innerHTML = '<option value="">Todas</option>';
                assSelect.innerHTML = '<option value="">Todos</option>';

                if (cat && hierarchyDataFilter[cat]) {
                    Object.keys(hierarchyDataFilter[cat]).forEach(sub => {
                        const opt = document.createElement('option');
                        opt.value = sub;
                        opt.textContent = sub;
                        if (sub === currentFilterSub) opt.selected = true;
                        subSelect.appendChild(opt);
                    });
                    if (subSelect.value) onFilterSubcategoryChange();
                }
            }

            function onFilterSubcategoryChange() {
                const cat = document.getElementById('filter-cat').value;
                const sub = document.getElementById('filter-subcat').value;
                const assSelect = document.getElementById('filter-assunto');

                assSelect.innerHTML = '<option value="">Todos</option>';

                if (cat && sub && hierarchyDataFilter[cat] && hierarchyDataFilter[cat][sub]) {
                    hierarchyDataFilter[cat][sub].forEach(ass => {
                        const opt = document.createElement('option');
                        opt.value = ass;
                        opt.textContent = ass;
                        if (ass === currentFilterAss) opt.selected = true;
                        assSelect.appendChild(opt);
                    });
                }
            }

            // Mapas por ID evitam colisões quando ramos diferentes usam o mesmo nome.
            const ncDocSubcatsByCategoryId = <?= json_encode($mapSubcatsParaDocumento) ?>;
            const ncDocAssuntosBySubcategoryId = <?= json_encode($mapAssuntosParaDocumento) ?>;

            function setDocumentHierarchyHelper(message = '', actionLabel = '', action = null) {
                const helper = document.getElementById('document-hierarchy-helper');
                const text = document.getElementById('document-hierarchy-helper-text');
                const button = document.getElementById('document-hierarchy-helper-action');
                if (!helper || !text || !button) return;
                const visible = Boolean(message && actionLabel && action);
                helper.classList.toggle('hidden', !visible);
                text.textContent = message;
                button.textContent = actionLabel;
                button.onclick = visible ? action : null;
            }

            function onCategoryChange() {
                const cat = document.getElementById('select-cat').value;
                const subSelect = document.getElementById('select-subcat');
                const assSelect = document.getElementById('select-assunto');

                subSelect.innerHTML = '<option value="">-- Selecione ▾ --</option>';
                assSelect.innerHTML = '<option value="">-- Selecione ▾ --</option>';

                const availableSubcategories = cat ? (ncDocSubcatsByCategoryId[cat] || []) : [];
                availableSubcategories.forEach(sc => {
                        const opt = document.createElement('option');
                        opt.value = String(sc.id);
                        opt.textContent = sc.nome;
                        subSelect.appendChild(opt);
                });

                if (cat && availableSubcategories.length === 0) {
                    subSelect.innerHTML = '<option value="">Nenhuma subcategoria ativa</option>';
                    setDocumentHierarchyHelper(
                        'Esta categoria ainda não possui uma subcategoria ativa. Crie uma para continuar.',
                        'Criar subcategoria',
                        () => {
                            ncSwitchType('subcategoria');
                            const target = document.getElementById('nc-sub-cat');
                            if (target) target.value = String(cat);
                        }
                    );
                } else {
                    setDocumentHierarchyHelper();
                }
            }

            function onSubcategoryChange() {
                const sub = document.getElementById('select-subcat').value;
                const assSelect = document.getElementById('select-assunto');

                assSelect.innerHTML = '<option value="">-- Selecione ▾ --</option>';

                const availableSubjects = sub ? (ncDocAssuntosBySubcategoryId[sub] || []) : [];
                availableSubjects.forEach(s => {
                        const opt = document.createElement('option');
                        opt.value = String(s.id);
                        opt.textContent = s.nome;
                        assSelect.appendChild(opt);
                });

                if (sub && availableSubjects.length === 0) {
                    assSelect.innerHTML = '<option value="">Nenhum assunto ativo</option>';
                    const categoryId = document.getElementById('select-cat')?.value || '';
                    setDocumentHierarchyHelper(
                        'Esta subcategoria ainda não possui um assunto ativo. Crie um para continuar.',
                        'Criar assunto',
                        () => {
                            ncSwitchType('assunto');
                            const catTarget = document.getElementById('nc-ass-cat');
                            const subTarget = document.getElementById('nc-ass-subcat');
                            if (catTarget) catTarget.value = String(categoryId);
                            ncLoadSubcatsForAssunto();
                            if (subTarget) subTarget.value = String(sub);
                        }
                    );
                } else {
                    setDocumentHierarchyHelper();
                }
            }

            function toggleFormContent(type) {
                const boxes = ['file', 'text', 'code', 'video', 'link'];
                boxes.forEach(b => {
                    const el = document.getElementById('box-' + b);
                    if (el) el.classList.add('hidden');
                });
                const target = document.getElementById('box-' + type);
                if (target) target.classList.remove('hidden');
                if (type === 'code') updateCodePreview();
                if (type === 'video') toggleVideoSource();
            }

            function updateCodePreview() {
                const sourceInput = document.getElementById('code-source-input');
                const languageInput = document.getElementById('code-language');
                const preview = document.getElementById('code-preview');
                if (!sourceInput || !languageInput || !preview) return;

                const code = preview.querySelector('[data-code-source]');
                preview.dataset.codeLanguage = languageInput.value || 'auto';
                if (code) code.textContent = sourceInput.value || '// A pré-visualização aparecerá aqui.';
                if (window.DocGovCodeSnippets) window.DocGovCodeSnippets.refresh(preview);
            }

            function toggleVideoSource() {
                const source = document.querySelector('input[name="video_source"]:checked')?.value || 'upload';
                const uploadFields = document.getElementById('video-upload-fields');
                const urlFields = document.getElementById('video-url-fields');
                if (uploadFields) uploadFields.classList.toggle('hidden', source !== 'upload');
                if (urlFields) urlFields.classList.toggle('hidden', source !== 'url');
                if (source === 'url') updateVideoUrlPreview();
            }

            let localVideoPreviewUrl = null;

            function updateVideoFilePreview(input) {
                const preview = document.getElementById('video-file-preview-name');
                const playerPreview = document.getElementById('video-url-preview');
                const selectedFile = input.files && input.files[0] ? input.files[0] : null;
                if (preview) preview.textContent = selectedFile ? selectedFile.name : '';
                if (!playerPreview) return;

                if (localVideoPreviewUrl) {
                    URL.revokeObjectURL(localVideoPreviewUrl);
                    localVideoPreviewUrl = null;
                }
                playerPreview.replaceChildren();
                if (!selectedFile) {
                    playerPreview.classList.add('hidden');
                    return;
                }

                localVideoPreviewUrl = URL.createObjectURL(selectedFile);
                const video = document.createElement('video');
                video.src = localVideoPreviewUrl;
                video.controls = true;
                video.preload = 'metadata';
                video.className = 'aspect-video w-full';
                playerPreview.append(video);
                playerPreview.classList.remove('hidden');
            }

            function getVideoEmbedUrl(url) {
                try {
                    const parsed = new URL(url);
                    const host = parsed.hostname.toLowerCase().replace(/^www\./, '');
                    const pathParts = parsed.pathname.split('/').filter(Boolean);
                    let youtubeId = '';

                    if (host === 'youtu.be') {
                        youtubeId = pathParts[0] || '';
                    } else if (['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'].includes(host)) {
                        youtubeId = parsed.searchParams.get('v') || '';
                        if (!youtubeId && ['embed', 'shorts', 'live'].includes(pathParts[0])) {
                            youtubeId = pathParts[1] || '';
                        }
                    }
                    if (/^[A-Za-z0-9_-]{11}$/.test(youtubeId)) {
                        return { kind: 'embed', src: 'https://www.youtube-nocookie.com/embed/' + youtubeId, provider: 'YouTube' };
                    }

                    if (['vimeo.com', 'player.vimeo.com'].includes(host)) {
                        const vimeoId = [...pathParts].reverse().find(part => /^\d+$/.test(part));
                        if (vimeoId) return { kind: 'embed', src: 'https://player.vimeo.com/video/' + vimeoId, provider: 'Vimeo' };
                    }

                    if (/\.(mp4|webm|ogv|ogg|m4v|mov)$/i.test(parsed.pathname)) {
                        return { kind: 'direct', src: parsed.href, provider: 'Vídeo externo' };
                    }
                } catch (_) {
                    // A validação final também acontece no servidor ao salvar.
                }
                return null;
            }

            function updateVideoUrlPreview() {
                const input = document.getElementById('video-url');
                const preview = document.getElementById('video-url-preview');
                if (!input || !preview) return;

                preview.replaceChildren();
                const resolved = getVideoEmbedUrl(input.value.trim());
                if (!resolved) {
                    preview.classList.add('hidden');
                    return;
                }

                preview.classList.remove('hidden');
                if (resolved.kind === 'embed') {
                    const frame = document.createElement('iframe');
                    frame.src = resolved.src;
                    frame.title = 'Pré-visualização de ' + resolved.provider;
                    frame.className = 'aspect-video w-full';
                    frame.loading = 'lazy';
                    frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
                    frame.allowFullscreen = true;
                    frame.referrerPolicy = 'strict-origin-when-cross-origin';
                    preview.append(frame);
                    return;
                }

                const video = document.createElement('video');
                video.src = resolved.src;
                video.controls = true;
                video.preload = 'metadata';
                video.className = 'aspect-video w-full';
                preview.append(video);
            }

            // =========================================================================
            // NOVO CONTEÚDO — SELETOR SEGMENTADO DE TIPO
            // =========================================================================
            const NC_TYPES = ['documento', 'categoria', 'subcategoria', 'assunto'];

            function ncSwitchType(type) {
                // Ocultar todos os painéis
                NC_TYPES.forEach(t => {
                    const panel = document.getElementById('nc-panel-' + t);
                    if (panel) panel.classList.add('hidden');

                    const btn = document.getElementById('nc-btn-' + t);
                    if (btn) btn.classList.remove('nc-type-active');
                });

                // Mostrar painel selecionado
                const activePanel = document.getElementById('nc-panel-' + type);
                if (activePanel) {
                    activePanel.classList.remove('hidden');
                    // Re-trigger animation
                    activePanel.style.animation = 'none';
                    activePanel.offsetHeight; // reflow
                    activePanel.style.animation = '';
                }

                // Ativar botão selecionado
                const activeBtn = document.getElementById('nc-btn-' + type);
                if (activeBtn) activeBtn.classList.add('nc-type-active');
            }

            // Carrega subcategorias dependentes de categoria no formulário de Assunto
            const hierarchyDataNC = <?= json_encode($hierarchyMap) ?>;
            // Mapas de subcategorias/assuntos filtrados por permissão (para formulários de criação)
            const ncSubcatsParaAssunto   = <?= json_encode($mapSubcatsParaAssunto) ?>;
            const ncSubcatsParaDocumento = <?= json_encode($mapSubcatsParaDocumento) ?>;
            const ncAssuntosParaDocumento = <?= json_encode($mapAssuntosParaDocumento) ?>;

            function ncLoadSubcatsForAssunto() {
                const catEl = document.getElementById('nc-ass-cat');
                const subEl = document.getElementById('nc-ass-subcat');
                if (!catEl || !subEl) return;

                const catId = parseInt(catEl.value, 10);
                subEl.innerHTML = '<option value="">-- Selecione a Subcategoria --</option>';

                if (catId && ncSubcatsParaAssunto[catId]) {
                    ncSubcatsParaAssunto[catId].forEach(sc => {
                        const opt = document.createElement('option');
                        opt.value = String(sc.id);
                        opt.textContent = sc.nome;
                        subEl.appendChild(opt);
                    });
                }
            }

            // Mostra nome do arquivo selecionado
            function updateFilePreview(input) {
                const preview = document.getElementById('file-preview-name');
                if (preview && input.files && input.files[0]) {
                    preview.textContent = input.files[0].name;
                }
            }

            // =====================================================================
            // TAGS — escolha livre para o autor, com sugestões apenas informativas.
            // =====================================================================
            const documentTagCatalog = <?= json_encode($tagCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const initialDocumentTagIds = <?= json_encode($editDocumentTagIds) ?>;

            function initDocumentTags() {
                const input = document.getElementById('document-tag-input');
                const addButton = document.getElementById('document-tag-add');
                const selected = document.getElementById('document-tag-selected');
                const datalist = document.getElementById('document-tag-options');
                const suggestions = document.getElementById('document-tag-suggestions');
                const suggestionList = document.getElementById('document-tag-suggestion-list');
                const counter = document.getElementById('document-tag-count');
                const feedback = document.getElementById('document-tag-feedback');
                if (!input || !addButton || !selected || !datalist || !suggestions || !suggestionList || !counter || !feedback) return;

                const normalise = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
                const catalogByName = new Map();
                documentTagCatalog.forEach(tag => {
                    catalogByName.set(normalise(tag.name), tag);
                    (tag.aliases || []).forEach(alias => catalogByName.set(normalise(alias), tag));
                    const option = document.createElement('option');
                    option.value = tag.name;
                    datalist.appendChild(option);
                });
                const state = new Map();
                const setFeedback = message => {
                    feedback.textContent = message || '';
                    feedback.classList.toggle('hidden', !message);
                };
                const stateKey = item => item.id ? `id:${item.id}` : `new:${normalise(item.name)}`;
                const addTag = rawValue => {
                    const rawName = String(rawValue || '').replace(/\s+/g, ' ').trim();
                    if (!rawName) return;
                    const known = catalogByName.get(normalise(rawName));
                    const item = known ? { id: Number(known.id), name: known.name, type: known.type_label || 'Tag' } : { id: 0, name: rawName, type: 'Nova tag' };
                    if (!known && (rawName.length < 2 || rawName.length > 80)) {
                        setFeedback('A nova tag deve ter entre 2 e 80 caracteres.');
                        return;
                    }
                    if (state.has(stateKey(item))) {
                        setFeedback('Essa tag já foi adicionada.');
                        return;
                    }
                    if (state.size >= 12) {
                        setFeedback('Use no máximo 12 tags por conteúdo.');
                        return;
                    }
                    state.set(stateKey(item), item);
                    input.value = '';
                    setFeedback('');
                    render();
                };
                const render = () => {
                    selected.replaceChildren();
                    state.forEach((item, key) => {
                        const chip = document.createElement('span');
                        chip.className = 'inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-600 dark:border-[#454956] dark:bg-[#353842] dark:text-slate-200';
                        const label = document.createElement('span');
                        label.textContent = item.name;
                        const remove = document.createElement('button');
                        remove.type = 'button';
                        remove.className = 'rounded-full text-slate-400 transition hover:text-red-500';
                        remove.setAttribute('aria-label', `Remover tag ${item.name}`);
                        remove.textContent = '×';
                        remove.addEventListener('click', () => { state.delete(key); setFeedback(''); render(); });
                        chip.append(label, remove);
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = item.id ? 'tag_ids[]' : 'new_tags[]';
                        hidden.value = item.id ? String(item.id) : item.name;
                        selected.append(chip, hidden);
                    });
                    counter.textContent = `${state.size}/12`;
                    refreshSuggestions();
                };
                const stopWords = new Set(['para','com','uma','uns','umas','dos','das','que','por','sem','sobre','entre','este','esta','isso','essa','como','mais','menos','tambem','sistema','documento','conteudo','arquivo','informacao','informações','orientacao','orientações','manual','novo','nova','todos','todas','serao','será','sao','são','quando','onde','cada','seus','suas']);
                const sourceText = () => [
                    document.getElementById('document-title')?.value,
                    document.getElementById('document-description')?.value,
                    document.getElementById('text-content-input')?.value,
                    document.getElementById('code-source-input')?.value,
                ].filter(Boolean).join(' ');
                const refreshSuggestions = () => {
                    const text = sourceText();
                    const normalizedText = ` ${normalise(text).replace(/-/g, ' ')} `;
                    const candidates = [];
                    documentTagCatalog.forEach(tag => {
                        const tagKey = normalise(tag.name);
                        if (tagKey && normalizedText.includes(` ${tagKey.replace(/-/g, ' ')} `) && !state.has(`id:${tag.id}`)) candidates.push({ id: tag.id, name: tag.name, known: true });
                    });
                    const words = (text.match(/[\p{L}\p{N}][\p{L}\p{N}-]{3,}/gu) || [])
                        .map(word => word.replace(/[-_]+/g, ' ').trim())
                        .filter(word => !stopWords.has(normalise(word)) && normalise(word).length >= 4);
                    const frequency = new Map();
                    words.forEach(word => { const key = normalise(word); frequency.set(key, (frequency.get(key) || { name: word, count: 0 })); frequency.get(key).count += 1; });
                    [...frequency.entries()].sort((a, b) => b[1].count - a[1].count || a[1].name.localeCompare(b[1].name, 'pt-BR')).forEach(([key, value]) => {
                        if (candidates.length >= 6 || catalogByName.has(key) || state.has(`new:${key}`)) return;
                        candidates.push({ id: 0, name: value.name, known: false });
                    });
                    suggestionList.replaceChildren();
                    candidates.slice(0, 6).forEach(candidate => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'rounded-full border border-dashed border-slate-300 px-2 py-1 text-[10px] font-semibold text-slate-500 transition hover:border-slate-500 hover:bg-white hover:text-slate-800 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-[#353842]';
                        button.textContent = `+ ${candidate.name}`;
                        button.title = candidate.known ? 'Adicionar tag existente sugerida' : 'Criar e adicionar esta nova tag sugerida';
                        button.addEventListener('click', () => addTag(candidate.name));
                        suggestionList.appendChild(button);
                    });
                    suggestions.classList.toggle('hidden', candidates.length === 0);
                };
                initialDocumentTagIds.forEach(id => {
                    const tag = documentTagCatalog.find(item => Number(item.id) === Number(id));
                    if (tag) state.set(`id:${tag.id}`, { id: Number(tag.id), name: tag.name, type: tag.type_label || 'Tag' });
                });
                addButton.addEventListener('click', () => addTag(input.value));
                input.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ',') { event.preventDefault(); addTag(input.value); } });
                ['document-title', 'document-description', 'text-content-input', 'code-source-input'].forEach(id => document.getElementById(id)?.addEventListener('input', refreshSuggestions));
                render();
            }

            // =====================================================================
            // ENVIO EM LOTE — fila de arquivos, títulos automáticos e progresso XHR
            // =====================================================================
            function initBatchFileUpload() {
                const dropzone = document.getElementById('batch-dropzone');
                const input = document.getElementById('file-input');
                const form = document.getElementById('document-form');
                const flag = document.getElementById('batch-upload-flag');
                const queue = document.getElementById('batch-file-queue');
                const list = document.getElementById('batch-file-list');
                const count = document.getElementById('batch-file-count');
                const error = document.getElementById('batch-upload-error');
                const selectButton = document.getElementById('batch-select-files');
                const clearButton = document.getElementById('batch-clear-files');
                const progressWrap = document.getElementById('batch-upload-progress-wrap');
                const progress = document.getElementById('batch-upload-progress');
                const percent = document.getElementById('batch-upload-percent');
                const status = document.getElementById('batch-upload-status');
                const titleInput = document.getElementById('document-title');
                if (!dropzone || !input || !form || !flag || !queue || !list || !count) return;

                const maxFiles = 20;
                const videoExtensions = new Set(['mp4', 'webm', 'ogv', 'm4v', 'mov']);
                const imageExtensions = new Set(['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'avif']);
                const audioExtensions = new Set(['mp3', 'wav', 'ogg']);
                let files = [];
                let uploading = false;

                const extensionOf = file => (file.name.split('.').pop() || '').toLowerCase();
                const formatSize = bytes => bytes < 1024 * 1024
                    ? `${Math.max(1, Math.round(bytes / 1024))} KB`
                    : `${(bytes / (1024 * 1024)).toFixed(bytes >= 100 * 1024 * 1024 ? 0 : 1)} MB`;
                const suggestedTitle = file => {
                    const base = file.name.replace(/\.[^.]+$/, '').replace(/[._-]+/g, ' ').replace(/\s+/g, ' ').trim();
                    return base || 'Documento sem título';
                };
                const fileKind = file => {
                    const extension = extensionOf(file);
                    if (videoExtensions.has(extension)) return { label: 'Vídeo', className: 'bg-violet-500/10 text-violet-700 dark:text-violet-300' };
                    if (imageExtensions.has(extension)) return { label: 'Imagem', className: 'bg-sky-500/10 text-sky-700 dark:text-sky-300' };
                    if (audioExtensions.has(extension)) return { label: 'Áudio', className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300' };
                    return { label: 'Arquivo', className: 'bg-slate-100 text-slate-600 dark:bg-[#353842] dark:text-slate-300' };
                };
                const showError = message => {
                    if (!error) return;
                    error.textContent = message || '';
                    error.classList.toggle('hidden', !message);
                };
                const setProgress = (value, message) => {
                    const safeValue = Math.max(0, Math.min(100, Math.round(value)));
                    if (progressWrap) progressWrap.classList.remove('hidden');
                    if (progress) progress.style.width = `${safeValue}%`;
                    if (percent) percent.textContent = `${safeValue}%`;
                    if (status && message) status.textContent = message;
                };
                const syncInputFiles = () => {
                    const transfer = new DataTransfer();
                    files.forEach(item => transfer.items.add(item.file));
                    input.files = transfer.files;
                    input.name = files.length > 0 ? 'arquivo_file[]' : 'arquivo_file';
                    flag.value = files.length > 0 ? '1' : '';
                };
                const render = () => {
                    list.replaceChildren();
                    queue.classList.toggle('hidden', files.length === 0);
                    count.textContent = `${files.length} ${files.length === 1 ? 'arquivo selecionado' : 'arquivos selecionados'}`;
                    files.forEach((item, index) => {
                        const kind = fileKind(item.file);
                        const row = document.createElement('div');
                        row.className = 'flex items-center gap-2.5 px-3 py-2.5';

                        const icon = document.createElement('span');
                        icon.className = 'flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 dark:bg-[#353842] dark:text-slate-300';
                        icon.innerHTML = '<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>';

                        const content = document.createElement('div');
                        content.className = 'min-w-0 flex-1';
                        const title = document.createElement('input');
                        title.type = 'text';
                        title.name = 'batch_titles[]';
                        title.value = item.title;
                        title.maxLength = 255;
                        title.className = 'input-minimal w-full border-0 bg-transparent p-0 text-xs font-semibold text-slate-800 focus:ring-0 dark:text-slate-100';
                        title.setAttribute('aria-label', `Título de ${item.file.name}`);
                        title.addEventListener('input', event => { files[index].title = event.target.value; });
                        const detail = document.createElement('div');
                        detail.className = 'mt-0.5 flex min-w-0 items-center gap-1.5 text-[10px] text-slate-400';
                        const filename = document.createElement('span');
                        filename.className = 'truncate';
                        filename.textContent = item.file.name;
                        const size = document.createElement('span');
                        size.className = 'shrink-0';
                        size.textContent = formatSize(item.file.size);
                        detail.append(filename, size);
                        content.append(title, detail);

                        const badge = document.createElement('span');
                        badge.className = `shrink-0 rounded px-1.5 py-1 text-[10px] font-bold ${kind.className}`;
                        badge.textContent = kind.label;
                        const remove = document.createElement('button');
                        remove.type = 'button';
                        remove.className = 'shrink-0 rounded p-1 text-slate-400 transition hover:bg-red-500/10 hover:text-red-600 disabled:opacity-50';
                        remove.setAttribute('aria-label', `Remover ${item.file.name}`);
                        remove.innerHTML = '<svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 6 12 12M18 6 6 18"/></svg>';
                        remove.disabled = uploading;
                        remove.addEventListener('click', () => {
                            files.splice(index, 1);
                            syncInputFiles();
                            render();
                            if (!files.length && titleInput && titleInput.dataset.batchAutofilled === '1') {
                                titleInput.value = '';
                                delete titleInput.dataset.batchAutofilled;
                            }
                        });
                        row.append(icon, content, badge, remove);
                        list.append(row);
                    });
                };
                const addFiles = newFiles => {
                    if (uploading || !newFiles.length) return;
                    const uniqueFiles = newFiles.filter(file => !files.some(item => item.file.name === file.name && item.file.size === file.size && item.file.lastModified === file.lastModified));
                    if (files.length + uniqueFiles.length > maxFiles) {
                        showError(`A fila aceita no máximo ${maxFiles} arquivos por vez.`);
                        return;
                    }
                    files.push(...uniqueFiles.map(file => ({ file, title: suggestedTitle(file) })));
                    showError('');
                    syncInputFiles();
                    render();
                    if (files.length && titleInput && (!titleInput.value.trim() || titleInput.dataset.batchAutofilled === '1')) {
                        titleInput.value = files[0].title;
                        titleInput.dataset.batchAutofilled = '1';
                    }
                };
                const setControlsDisabled = disabled => {
                    uploading = disabled;
                    if (selectButton) selectButton.disabled = disabled;
                    if (clearButton) clearButton.disabled = disabled;
                    form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = disabled; });
                    render();
                };

                titleInput?.addEventListener('input', () => { delete titleInput.dataset.batchAutofilled; });
                selectButton?.addEventListener('click', event => { event.stopPropagation(); input.click(); });
                clearButton?.addEventListener('click', () => {
                    if (uploading) return;
                    files = [];
                    syncInputFiles();
                    render();
                    showError('');
                    if (titleInput && titleInput.dataset.batchAutofilled === '1') {
                        titleInput.value = '';
                        delete titleInput.dataset.batchAutofilled;
                    }
                });
                input.addEventListener('change', () => addFiles(Array.from(input.files || [])));
                dropzone.addEventListener('click', event => { if (!event.target.closest('button')) input.click(); });
                dropzone.addEventListener('keydown', event => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        input.click();
                    }
                });
                ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, event => {
                    event.preventDefault();
                    if (!uploading) dropzone.classList.add('border-slate-700', 'bg-slate-100');
                }));
                ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, event => {
                    event.preventDefault();
                    dropzone.classList.remove('border-slate-700', 'bg-slate-100');
                }));
                dropzone.addEventListener('drop', event => addFiles(Array.from(event.dataTransfer?.files || [])));

                form.addEventListener('submit', event => {
                    const selectedType = form.querySelector('input[name="tipo_conteudo"]:checked')?.value || 'file';
                    const isEdit = !!(form.querySelector('input[name="id"]')?.value);
                    if (selectedType !== 'file' || isEdit || !flag.value || !files.length) {
                        if (selectedType === 'file' && !isEdit && !files.length) {
                            event.preventDefault();
                            showError('Selecione ao menos um arquivo antes de salvar.');
                        }
                        return;
                    }
                    if (uploading) {
                        event.preventDefault();
                        return;
                    }
                    event.preventDefault();
                    if (!form.reportValidity()) return;
                    const submitter = event.submitter;
                    const data = new FormData(form);
                    if (submitter?.name) data.set(submitter.name, submitter.value);
                    data.set('batch_upload', '1');
                    showError('');
                    setControlsDisabled(true);
                    setProgress(0, 'Enviando arquivos…');

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', form.action, true);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.upload.addEventListener('progress', progressEvent => {
                        if (progressEvent.lengthComputable) {
                            setProgress((progressEvent.loaded / progressEvent.total) * 100, 'Enviando arquivos…');
                        }
                    });
                    xhr.upload.addEventListener('load', () => setProgress(100, 'Criando documentos…'));
                    xhr.addEventListener('load', () => {
                        let payload = null;
                        try { payload = JSON.parse(xhr.responseText || '{}'); } catch (_) { /* resposta inválida abaixo */ }
                        if (xhr.status >= 200 && xhr.status < 300 && payload?.success) {
                            setProgress(100, `${payload.created_count} ${payload.created_count === 1 ? 'documento criado' : 'documentos criados'}!`);
                            window.setTimeout(() => { window.location.assign(payload.redirect || 'index.php?tab=documentos'); }, 350);
                            return;
                        }
                        setControlsDisabled(false);
                        showError(payload?.error || 'Não foi possível concluir o envio. Tente novamente.');
                    });
                    xhr.addEventListener('error', () => {
                        setControlsDisabled(false);
                        showError('A conexão foi interrompida durante o envio. Nenhum documento foi criado.');
                    });
                    xhr.send(data);
                });
            }

            if (currentFilterCat) onFilterCategoryChange();

            // =========================================================================
            // EDITOR VISUAL - ESTADO CENTRALIZADO E GRIDSTACK.JS (BASE)
            // =========================================================================
            const EditorState = {
                category: <?= json_encode($parentCatName ?? '') ?>,
                subcategory: <?= json_encode($selAssItem['subcategoria_nome'] ?? '') ?>,
                assunto: <?= json_encode($selAssItem['nome'] ?? '') ?>,
                contents: <?= json_encode($docsAssList ?? []) ?>,
                selectedItemId: null,
                mode: 'edit',
                isDirty: false
            };

            let gridInstance = null;

            document.addEventListener('DOMContentLoaded', function() {
                const selectedContentType = document.querySelector('input[name="tipo_conteudo"]:checked');
                if (selectedContentType) toggleFormContent(selectedContentType.value);
                initDocumentTags();
                initBatchFileUpload();

                const gridEl = document.querySelector('.grid-stack');
                if (gridEl && typeof GridStack !== 'undefined') {
                    gridInstance = GridStack.init({
                        column: 12,
                        cellHeight: 85,
                        disableDrag: true,
                        disableResize: true,
                        staticGrid: true,
                        margin: 6
                    }, gridEl);
                }

                // Continuidade do fluxo Categoria → Subcategoria → Assunto → Documento.
                const urlParams = new URLSearchParams(window.location.search);
                const setupStep = urlParams.get('setup') || '';
                const editHierarchy = <?= json_encode([
                    'category' => $editDoc['categoria_id'] ?? null,
                    'subcategory' => $editDoc['subcategoria_id'] ?? null,
                    'subject' => $editDoc['assunto_id'] ?? null,
                ]) ?>;
                const preCat = urlParams.get('cat_id') || urlParams.get('cat') || editHierarchy.category;
                const preSub = urlParams.get('subcat_id') || urlParams.get('subcat') || editHierarchy.subcategory;
                const preAss = urlParams.get('subject_id') || urlParams.get('assunto') || editHierarchy.subject;
                const selectMatchingOption = (select, requested) => {
                    if (!select || requested === null || requested === undefined || requested === '') return '';
                    const requestedText = String(requested);
                    const option = Array.from(select.options).find(item => item.value === requestedText || item.textContent.trim() === requestedText);
                    if (!option) return '';
                    select.value = option.value;
                    return option.value;
                };

                const documentCategory = document.getElementById('select-cat');
                if (preCat && documentCategory) {
                    selectMatchingOption(documentCategory, preCat);
                    onCategoryChange();
                    const documentSubcategory = document.getElementById('select-subcat');
                    if (preSub && documentSubcategory) {
                        selectMatchingOption(documentSubcategory, preSub);
                        onSubcategoryChange();
                        const documentSubject = document.getElementById('select-assunto');
                        if (preAss && documentSubject) {
                            selectMatchingOption(documentSubject, preAss);
                        }
                    }
                }

                if (setupStep === 'subcategory') {
                    ncSwitchType('subcategoria');
                    selectMatchingOption(document.getElementById('nc-sub-cat'), preCat);
                } else if (setupStep === 'subject') {
                    ncSwitchType('assunto');
                    selectMatchingOption(document.getElementById('nc-ass-cat'), preCat);
                    ncLoadSubcatsForAssunto();
                    selectMatchingOption(document.getElementById('nc-ass-subcat'), preSub);
                } else if (setupStep === 'document') {
                    ncSwitchType('documento');
                }
            });

            function setEditorMode(mode) {
                EditorState.mode = mode;
                const btnEdit = document.getElementById('btn-mode-edit');
                const btnPreview = document.getElementById('btn-mode-preview');
                const canvas = document.getElementById('editor-visual-canvas');
                if (!btnEdit || !btnPreview || !canvas) return;

                if (mode === 'preview') {
                    btnEdit.className = "px-3 py-1 rounded text-xs font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition";
                    btnPreview.className = "px-3 py-1 rounded text-xs font-semibold bg-slate-900 dark:bg-white text-white dark:text-slate-900 transition";
                    canvas.classList.add('editor-preview-mode');
                } else {
                    btnPreview.className = "px-3 py-1 rounded text-xs font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition";
                    btnEdit.className = "px-3 py-1 rounded text-xs font-semibold bg-slate-900 dark:bg-white text-white dark:text-slate-900 transition";
                    canvas.classList.remove('editor-preview-mode');
                }
            }

            function markEditorDirty() {
                EditorState.isDirty = true;
                const indicator = document.getElementById('save-status-indicator');
                if (indicator) {
                    indicator.innerHTML = '<span class="w-2 h-2 rounded-full bg-amber-500 inline-block animate-pulse"></span><span class="text-amber-600 font-semibold">Alterações pendentes</span>';
                }
            }

            function saveEditorLayoutState() {
                const formGrid = document.getElementById('form-editor-grid');
                if (formGrid) {
                    formGrid.submit();
                } else {
                    const indicator = document.getElementById('save-status-indicator');
                    if (indicator) {
                        indicator.innerHTML = '<span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span><span>Alterações salvas</span>';
                    }
                }
            }

            // =========================================================================
            // CONTROLE DA ÁRVORE HIERÁRQUICA (EXPANDIR/RECOLHER NOS 4 NÍVEIS)
            // =========================================================================
            function toggleTreeNode(btn) {
                const group = btn.closest('.tree-node-group');
                if (!group) return;
                const branch = group.querySelector(':scope > .tree-branch');
                const svg = btn.querySelector('svg');

                if (branch) {
                    if (branch.classList.contains('hidden')) {
                        branch.classList.remove('hidden');
                        if (svg) svg.classList.add('rotate-90');
                    } else {
                        branch.classList.add('hidden');
                        if (svg) svg.classList.remove('rotate-90');
                    }
                }
            }

            function expandAllTreeNodes() {
                document.querySelectorAll('.tree-branch').forEach(b => b.classList.remove('hidden'));
                document.querySelectorAll('.tree-toggle-btn svg').forEach(svg => svg.classList.add('rotate-90'));
            }

            function collapseAllTreeNodes() {
                document.querySelectorAll('.tree-branch').forEach(b => b.classList.add('hidden'));
                document.querySelectorAll('.tree-toggle-btn svg').forEach(svg => svg.classList.remove('rotate-90'));
            }

            function filterTreeNodes(query) {
                const q = query.trim().toLowerCase();
                const nodeGroups = document.querySelectorAll('.tree-node-group');
                if (!q) {
                    nodeGroups.forEach(el => el.style.display = '');
                    document.querySelectorAll('.tree-branch').forEach(b => b.classList.add('hidden'));
                    return;
                }
                document.querySelectorAll('.tree-branch').forEach(b => b.classList.remove('hidden'));
                nodeGroups.forEach(group => {
                    const text = group.textContent.toLowerCase();
                    if (text.includes(q)) {
                        group.style.display = '';
                    } else {
                        group.style.display = 'none';
                    }
                });
            }

            // =========================================================================
            // RECOLHIMENTO E PERSISTÊNCIA DAS SIDEBARS (MENU PRINCIPAL E ÁRVORE)
            // =========================================================================
            function toggleMainSidebar() {
                const sidebar = document.getElementById('sidebar-menu');
                const arrow = document.getElementById('main-dock-arrow');
                if (!sidebar) return;

                const isCompact = sidebar.classList.contains('sidebar-compact');
                if (!isCompact) {
                    sidebar.classList.add('sidebar-compact');
                    if (arrow) arrow.classList.add('rotate-180');
                    localStorage.setItem('main_sidebar_compact', '1');
                } else {
                    sidebar.classList.remove('sidebar-compact');
                    if (arrow) arrow.classList.remove('rotate-180');
                    localStorage.setItem('main_sidebar_compact', '0');
                }

                window.dispatchEvent(new Event('resize'));
            }

            // =========================================================================
        </script>
    <?php endif; ?>

    <script src="../assets/permissions.js"></script>
</body>
</html>
