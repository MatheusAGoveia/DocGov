<?php
// admin/index.php - Painel Administrativo de Gestão de Documentos (Ícones 100% SVG)
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../services/UploadRequestService.php';
$uploadRequestError = UploadRequestService::requestError();
if ($uploadRequestError !== null && str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $uploadRequestError], JSON_UNESCAPED_UNICODE);
    exit;
}
docgovStartSession();
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    // O player incorporado do YouTube exige a origem da pagina no HTTP Referer.
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/SystemAccessService.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';
require_once __DIR__ . '/../services/VideoEmbedService.php';
require_once __DIR__ . '/../services/CategoryImageService.php';
require_once __DIR__ . '/../services/BatchDocumentUploadService.php';
require_once __DIR__ . '/../services/DocumentWorkflowService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/UsageAuditService.php';
require_once __DIR__ . '/../services/CsrfService.php';
require_once __DIR__ . '/../services/HierarchyService.php';
require_once __DIR__ . '/../services/SubjectWorkspaceService.php';
require_once __DIR__ . '/../services/DocumentSlugService.php';
require_once __DIR__ . '/../services/DocumentSectionService.php';
require_once __DIR__ . '/../services/RichTextSanitizer.php';
require_once __DIR__ . '/../services/DocumentInlineMediaService.php';
require_once __DIR__ . '/../services/StructuredContentService.php';
require_once __DIR__ . '/../services/StructureArchiveService.php';
require_once __DIR__ . '/../services/StructureDeletionService.php';
$tagService = new TagService($pdo);
$hierarchyService = new HierarchyService($pdo);
$subjectWorkspaceService = new SubjectWorkspaceService($pdo);
$documentSlugService = new DocumentSlugService($pdo);
$documentSectionService = new DocumentSectionService($pdo);
$inlineMediaService = new DocumentInlineMediaService($pdo, dirname(__DIR__));
$permService = new PermissionService($pdo);
$groupMembershipService = new GroupMembershipService($pdo);
$structureArchiveService = new StructureArchiveService($pdo, $permService);
$structureDeletionService = new StructureDeletionService($pdo, $permService);
$systemAccessService = new SystemAccessService($pdo, $permService);
$adAuthService = new ActiveDirectoryAuthService($pdo);
$categoryImageService = new CategoryImageService(dirname(__DIR__));
$notificationService = new NotificationService($pdo);
$workflowService = new DocumentWorkflowService($pdo, $permService);
$usageAuditService = new UsageAuditService($pdo);
$batchDocumentUploadService = new BatchDocumentUploadService($pdo, $permService, $workflowService, $usageAuditService, $tagService, dirname(__DIR__));
$availableDocumentSections = $documentSectionService->activeSections();
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
$initialAdminUserId = (int)($loggedUser['id'] ?? 0);
require_once __DIR__ . '/../services/ProfileAvatarService.php';
$profileAvatarPath = ProfileAvatarService::pathForUser($pdo, $initialAdminUserId);
$hasContentAdministrativeAccess = $loggedUser
    ? $permService->canAccessAdminPanel($initialAdminUserId)
    : false;
$hasDelegatedSystemAccess = $loggedUser
    ? $systemAccessService->hasAnyCapability($initialAdminUserId)
    : false;

if (!$loggedUser || (!$hasContentAdministrativeAccess && !$hasDelegatedSystemAccess)) {
    unset($_SESSION['admin_logged']);
    header('Location: ../index.php');
    exit;
} else {
    $_SESSION['admin_logged'] = true;
}

$isLogged = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true && !$accessDenied;
$adminAccessLabel = $loggedUser
    ? ($hasContentAdministrativeAccess
        ? $permService->getAdminPanelAccessLabel($initialAdminUserId)
        : 'Administração delegada')
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
$errorMessage = UploadRequestService::requestError() ?? '';
if ($errorMessage !== '') http_response_code(413);
$editDoc = null;
$failedDocumentSubmission = null;
$docDetails = null;
$workflowHistory = [];
$editCat = null;
$editSub = null;
$editAss = null;
$currentAdminUserId = (int)($loggedUser['id'] ?? 0);
$unreadNotificationCount = $isLogged ? $notificationService->unreadCount($currentAdminUserId) : 0;
$isGlobalAdminCurrent = $isLogged && $permService->isGlobalAdmin($currentAdminUserId);
$canManageSystemSettings = $isLogged && $systemAccessService->hasCapability($currentAdminUserId, SystemAccessService::SETTINGS_MANAGE);
$canManageAuthentication = $isLogged && $systemAccessService->hasCapability($currentAdminUserId, SystemAccessService::AUTHENTICATION_MANAGE);
$canManageDirectory = $isLogged && $systemAccessService->hasCapability($currentAdminUserId, SystemAccessService::DIRECTORY_MANAGE);
$canViewGlobalAudit = $isLogged && $systemAccessService->hasCapability($currentAdminUserId, SystemAccessService::AUDIT_VIEW);
$canManageTags = $isLogged && $systemAccessService->hasCapability($currentAdminUserId, SystemAccessService::TAGS_MANAGE);
$canUseUsersArea = $hasContentAdministrativeAccess || $canManageDirectory;
if ($isLogged && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $usageAuditService->log('admin_page_view', $currentAdminUserId, 'ADMIN', null, ['tab' => $activeTab]);

    // Lembretes idempotentes: uma notificação por data de revisão configurada.
    try {
        $dueWorkspaceStmt = $pdo->query("\n            SELECT sw.subject_id, sw.next_review_on, s.name\n            FROM subject_workspaces sw\n            JOIN subjects s ON s.id = sw.subject_id\n            WHERE sw.documentation_status = 'approved'\n              AND sw.next_review_on IS NOT NULL\n              AND sw.next_review_on <= CURRENT_DATE + 14\n              AND sw.review_reminder_sent_for IS DISTINCT FROM sw.next_review_on\n            ORDER BY sw.next_review_on, sw.subject_id\n            LIMIT 30\n        ");
        $dueWorkspaces = $dueWorkspaceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($dueWorkspaces !== []) {
            $activeUserIds = $pdo->query('SELECT id FROM users WHERE active = TRUE')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($dueWorkspaces as $dueWorkspace) {
                $dueSubjectId = (int)$dueWorkspace['subject_id'];
                $reviewers = [];
                foreach ($activeUserIds as $candidateId) {
                    if ($permService->canAdminSubject((int)$candidateId, $dueSubjectId)) {
                        $reviewers[] = (int)$candidateId;
                    }
                }
                $markReminder = $pdo->prepare('UPDATE subject_workspaces SET review_reminder_sent_for = next_review_on WHERE subject_id = :subject_id AND review_reminder_sent_for IS DISTINCT FROM next_review_on RETURNING subject_id');
                $markReminder->execute([':subject_id' => $dueSubjectId]);
                if (!$markReminder->fetchColumn()) {
                    continue;
                }
                $reviewDateLabel = date('d/m/Y', strtotime((string)$dueWorkspace['next_review_on']));
                $isOverdue = (string)$dueWorkspace['next_review_on'] <= date('Y-m-d');
                $notificationService->createForUsers(
                    $reviewers,
                    'subject_review_due',
                    $isOverdue ? 'Revisão periódica vencida' : 'Revisão periódica próxima',
                    '“' . $dueWorkspace['name'] . '” deve ser revisada em ' . $reviewDateLabel . '.',
                    null
                );
            }
            $unreadNotificationCount = $notificationService->unreadCount($currentAdminUserId);
        }
    } catch (Throwable $reminderException) {
        error_log('DocGov lembrete de revisão: ' . $reminderException->getMessage());
    }
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
$administrativeDocumentScopeSql = $canViewGlobalAudit
    ? 'TRUE'
    : (!empty($administrativeSubjectIds)
        ? 'd.subject_id IN (' . implode(',', $administrativeSubjectIds) . ')'
        : 'FALSE');

$restrictedTabCapabilities = [
    'configuracoes' => $canManageSystemSettings,
    'servidores_ad' => $canManageAuthentication,
    'tags' => $canManageTags,
    'usuarios' => $canUseUsersArea,
    'editar_usuario' => $canUseUsersArea,
];
$superAdminOnlyTabs = ['grupos', 'editar_grupo'];
if ($isLogged && !$isGlobalAdminCurrent && in_array($activeTab, $superAdminOnlyTabs, true)) {
    http_response_code(403);
    $activeTab = 'visao_geral';
    $errorMessage = 'A gestão de equipes e de poderes administrativos está disponível somente para o Super Admin.';
} elseif ($isLogged && isset($restrictedTabCapabilities[$activeTab]) && !$restrictedTabCapabilities[$activeTab]) {
    http_response_code(403);
    $activeTab = 'visao_geral';
    $errorMessage = 'Seu usuário não possui o módulo administrativo solicitado.';
}

// =============================================================================
// PROCESSAMENTO COMPLETO DE AÇÕES ADMINISTRATIVAS (POSTGRESQL)
// =============================================================================
if ($isLogged) {

    // Contexto interno: os manipuladores nao sao pontos de entrada HTTP.
    define('DOCGOV_ADMIN_REQUEST', true);
    define('DOCGOV_PROJECT_ROOT', dirname(__DIR__));
    require __DIR__ . '/dispatch.php';

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
            $stmt = $pdo->prepare("SELECT s.id, s.subcategory_id, s.name AS nome, s.description AS descricao, s.active, s.visibility, sc.name AS subcategoria_nome FROM subjects s JOIN subcategories sc ON s.subcategory_id = sc.id WHERE s.id = :id");
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
                SELECT d.id, d.updated_at AS document_version, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo, d.section_key, d.status,
                       d.text_content AS conteudo_html, d.structured_content, d.code_language AS linguagem_codigo, d.external_url AS link_externo,
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
                SELECT d.id, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo, d.section_key, d.status,
                       d.original_filename AS nome_original, d.file_path AS caminho_arquivo, d.file_size AS tamanho_bytes,
                       d.mime_type AS tipo_mime, d.published_at, d.created_at, d.updated_at AS document_version, d.approval_expires_at,
                       d.reviewed_at, d.approved_at, d.rejected_at, d.rejection_reason,
                       s.name AS assunto, sc.name AS subcategoria, c.name AS categoria,
                       u.name AS autor_nome, reviewer.name AS revisor_nome,
                       approver.name AS aprovador_nome, rejector.name AS recusador_nome,
                       ds.label AS section_label
                FROM documents d
                JOIN document_sections ds ON ds.section_key = d.section_key
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
$filterStatus = trim((string)($_GET['filter_status'] ?? 'pending'));
if (!in_array($filterStatus, ['pending', 'published', 'draft', 'review', 'inactive', 'all'], true)) {
    $filterStatus = 'pending';
}

$documentFilterQuery = array_filter([
    'tab' => 'documentos',
    'search' => $searchQuery,
    'filter_cat' => $filterCat,
    'filter_subcat' => $filterSubcat,
    'filter_assunto' => $filterAssunto,
    'filter_tipo' => $filterTipo,
], static fn($value): bool => $value !== '');
$documentFilterUrl = static function (string $status, ?int $targetPage = null) use ($documentFilterQuery): string {
    $query = $documentFilterQuery;
    $query['filter_status'] = $status;
    if ($targetPage !== null && $targetPage > 1) {
        $query['page'] = $targetPage;
    }
    return 'index.php?' . http_build_query($query);
};

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$whereClauses = ['d.trashed_at IS NULL', $administrativeDocumentScopeSql];
$params = [];

if (!empty($searchQuery)) {
    $whereClauses[] = "(LOWER(d.title) LIKE :sq OR LOWER(d.description) LIKE :sq OR LOWER(s.name) LIKE :sq OR LOWER(sc.name) LIKE :sq OR LOWER(c.name) LIKE :sq OR EXISTS (SELECT 1 FROM document_tags dt JOIN tags t ON t.id = dt.tag_id WHERE dt.document_id = d.id AND t.active = TRUE AND LOWER(t.name) LIKE :sq))";
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
if ($filterStatus === 'pending') {
    $whereClauses[] = "d.status IN ('draft', 'review')";
} elseif ($filterStatus !== 'all') {
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
$totalPages = max(1, (int)ceil($totalDocsFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sqlDocs = "
    SELECT d.id, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo,
           d.section_key, ds.label AS section_label,
           d.status, d.created_at, d.updated_at, d.published_at, d.approval_expires_at, d.reviewed_by,
           s.name AS assunto, sc.name AS subcategoria, c.name AS categoria,
           u.name AS autor_nome
    FROM documents d
    JOIN document_sections ds ON ds.section_key = d.section_key
    JOIN subjects s ON d.subject_id = s.id
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    LEFT JOIN users u ON d.created_by = u.id
    {$whereSql}
    ORDER BY d.updated_at DESC, d.id DESC LIMIT {$perPage} OFFSET {$offset}
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
        COUNT(*) FILTER (WHERE d.status = 'inactive' AND d.trashed_at IS NULL) AS inactive,
        COUNT(*) FILTER (WHERE d.trashed_at IS NOT NULL) AS trashed
    FROM documents d
    WHERE {$administrativeDocumentScopeSql}
");
$metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalDocs = (int)($metrics['total'] ?? 0);
$totalPublicados = (int)($metrics['published'] ?? 0);
$totalRascunhos = (int)($metrics['draft'] ?? 0);
$totalEmRevisao = (int)($metrics['review'] ?? 0);
$totalInativos = (int)($metrics['inactive'] ?? 0);
$totalLixeira = (int)($metrics['trashed'] ?? 0);

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
if ($canViewGlobalAudit) {
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
    SELECT s.id, s.subcategory_id, s.name AS nome, s.slug, s.description AS descricao, s.active, s.visibility,
           CASE WHEN s.active THEN 'ativo' ELSE 'inativo' END AS status,
           sc.category_id, sc.name AS subcategoria_nome, sc.active AS subcategoria_active,
           c.name AS categoria_nome, c.active AS categoria_active,
           COUNT(d.id) AS total_docs
    FROM subjects s
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    LEFT JOIN documents d ON d.subject_id = s.id
    GROUP BY s.id, s.subcategory_id, s.name, s.slug, s.description, s.active, s.visibility, sc.category_id, sc.name, sc.active, c.name, c.active
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

// A árvore principal mostra apenas nós utilizáveis. Itens desativados continuam
// disponíveis na visão de descartados para restauração sem perda de vínculos.
$treeVisibleCategories = array_values(array_filter($listCategorias, static fn($item) => docgovDatabaseBoolean($item['active'])));
$treeVisibleSubcategories = array_values(array_filter($listSubcategorias, static fn($item) => docgovDatabaseBoolean($item['active']) && docgovDatabaseBoolean($item['categoria_active'])));
$treeVisibleSubjects = array_values(array_filter($listAssuntos, static fn($item) => docgovDatabaseBoolean($item['active']) && docgovDatabaseBoolean($item['subcategoria_active']) && docgovDatabaseBoolean($item['categoria_active'])));
$treeDiscardedCategories = array_values(array_filter($listCategorias, static fn($item) => !docgovDatabaseBoolean($item['active'])));
$treeDiscardedSubcategories = array_values(array_filter($listSubcategorias, static fn($item) => !docgovDatabaseBoolean($item['active'])));
$treeDiscardedSubjects = array_values(array_filter($listAssuntos, static fn($item) => !docgovDatabaseBoolean($item['active'])));
$showDiscardedTree = ($activeTab === 'editar_estrutura' && ($_GET['view'] ?? '') === 'discarded');

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
$subjectVisibilitySubcategoryPermissions = [];
foreach ($subcatsParaAssunto as $visibilitySubcategory) {
    $subjectVisibilitySubcategoryPermissions[(int)$visibilitySubcategory['id']] = $permService->canAdminSubcategory($_currentUserId, (int)$visibilitySubcategory['id']);
}
$_canManageAnySubjectVisibility = in_array(true, $subjectVisibilitySubcategoryPermissions, true);

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
if ($failedDocumentSubmission !== null) $editDoc = $failedDocumentSubmission;
$tagCatalog = $tagService->allActive();
$inactiveTagNameKeys = $tagService->inactiveNameKeys();
$editDocumentTagIds = $failedDocumentSubmission !== null ? $requestedTagIds : ($editDoc ? $tagService->getDocumentTagIds((int)$editDoc['id']) : []);
$tagCatalogDetails = $canManageTags ? $tagService->allWithDetails() : [];

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
    if (!$canManageDirectory && !$permService->canViewUserInAdministrativeScope($currentAdminUserId, $preflightTargetUserId)) {
        http_response_code(403);
    }
}

$userTheme = 'light';
$userThemeClass = 'light';
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
    <?php $faviconPrefix = '../'; require __DIR__ . '/../partials/favicon.php'; ?>
    
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
    <script src="../assets/theme-bootstrap.js"></script>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/batch-user-import.css">
    <?php define('DOCGOV_ADMIN_DIALOG_ASSETS', true); require __DIR__ . '/../partials/dialog_assets.php'; ?>
    <link rel="stylesheet" href="../assets/permissions.css">
    <link rel="stylesheet" href="../assets/code-snippets.css">
    <link rel="stylesheet" href="../assets/structured-content.css">
    <?php if ($activeTab === 'novo_documento'): ?>
        <link rel="stylesheet" href="../assets/vendor/quill/quill.snow.css">
        <link rel="stylesheet" href="../assets/quill-editor.css?v=<?= filemtime(__DIR__ . '/../assets/quill-editor.css') ?>">
    <?php endif; ?>
    <script defer src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.11.1/build/highlight.min.js"></script>
    <script defer src="../assets/code-snippets.js"></script>
    <?php if ($activeTab === 'novo_documento'): ?>
        <script defer src="../assets/vendor/quill/quill.js"></script>
        <script defer src="../assets/article-media-layout.js?v=<?= filemtime(__DIR__ . '/../assets/article-media-layout.js') ?>"></script>
        <script defer src="../assets/structured-editors.js?v=<?= filemtime(__DIR__ . '/../assets/structured-editors.js') ?>"></script>
    <?php endif; ?>
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
                                    <img src="../<?= htmlspecialchars($appLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Brasão da Prefeitura Municipal de Betim" class="docgov-brand-logo h-10 w-10">
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
                                            <?= $totalDocs - $totalLixeira ?>
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
                            <?php if ($isGlobalAdminCurrent || $canManageAuthentication): ?>
                                <div class="pt-3 border-t border-slate-100 dark:border-[#454956]">
                                    <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 menu-section-title">
                                        GESTÃO DE ACESSO
                                    </div>
                                    <div class="mt-1 space-y-1">
                                        <?php if ($isGlobalAdminCurrent): ?>
                                        <a href="index.php?tab=grupos" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= in_array($activeTab, ['grupos', 'editar_grupo']) ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Equipes">
                                            <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                            <span class="menu-label font-bold">Equipes</span>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($canManageAuthentication): ?>
                                        <a href="index.php?tab=servidores_ad" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= $activeTab === 'servidores_ad' ? 'bg-sky-600 text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Autenticação">
                                            <svg class="w-4 h-4 text-sky-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                                            <span class="menu-label font-bold">Autenticação</span>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- SEÇÃO SISTEMA -->
                            <div class="pt-3 border-t border-slate-100 dark:border-[#454956]">
                                <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 menu-section-title">
                                    SISTEMA
                                </div>
                                <div class="mt-1 space-y-1">
                                    <?php if ($canUseUsersArea): ?>
                                    <a href="index.php?tab=usuarios" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= in_array($activeTab, ['usuarios', 'editar_usuario'], true) ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Gestão de Usuários">
                                        <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                        <span class="menu-label font-bold">Usuários</span>
                                    </a>
                                    <?php endif; ?>

                                    <?php if ($canManageTags): ?>
                                    <a href="index.php?tab=tags" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= $activeTab === 'tags' ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Tags">
                                        <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                        <span class="menu-label font-bold">Tags</span>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($canManageSystemSettings): ?>
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
                                <div class="admin-profile-avatar w-6 h-6 overflow-hidden rounded-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold text-[11px] flex items-center justify-center shrink-0">
                                    <?php if ($profileAvatarPath): ?>
                                        <img src="../<?= htmlspecialchars($profileAvatarPath, ENT_QUOTES, 'UTF-8') ?>" alt="" class="h-full w-full object-cover" loading="eager" decoding="async">
                                    <?php else: ?>
                                        <?= mb_strtoupper(mb_substr($loggedUser['nome'] ?? 'A', 0, 1)) ?>
                                    <?php endif; ?>
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

                <?php
                    $backHref = 'index.php?tab=visao_geral';
                    $backLabel = 'Voltar à visão geral';
                    $backUseHistory = false; // Formulários POST não devem retornar pelo histórico.
                    if ($activeTab === 'visao_geral') {
                        $backHref = '../index.php';
                        $backLabel = 'Voltar ao acervo';
                    } elseif (in_array($activeTab, ['novo_documento', 'detalhes_documento', 'lixeira'], true)) {
                        $backHref = 'index.php?tab=documentos';
                        $backLabel = 'Voltar aos documentos';
                    } elseif ($activeTab === 'substituir_arquivo') {
                        $backHref = 'index.php?tab=detalhes_documento&id=' . (int)($_GET['id'] ?? 0);
                        $backLabel = 'Voltar ao documento';
                    } elseif (in_array($activeTab, ['categorias', 'subcategorias', 'assuntos'], true)) {
                        $backHref = 'index.php?tab=editar_estrutura';
                        $backLabel = 'Voltar à árvore';
                    } elseif ($activeTab === 'editar_estrutura' && (int)($_GET['id'] ?? 0) > 0) {
                        $backHref = 'index.php?tab=editar_estrutura';
                        $backLabel = 'Voltar à árvore';
                    } elseif ($activeTab === 'editar_grupo') {
                        $backHref = 'index.php?tab=grupos';
                        $backLabel = 'Voltar às equipes';
                    } elseif ($activeTab === 'editar_usuario') {
                        $backHref = 'index.php?tab=usuarios';
                        $backLabel = 'Voltar aos usuários';
                    }
                    require __DIR__ . '/../partials/back_navigation.php';
                ?>

                <div class="md:hidden flex items-center justify-between mb-4 pb-3 border-b border-slate-200 dark:border-[#454956]">
                    <button type="button" onclick="toggleMobileSidebar()" class="flex items-center gap-2 px-3 py-1.5 rounded-md bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] text-xs font-semibold text-slate-700 dark:text-slate-200 shadow-xs">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        <span>Menu Administração</span>
                    </button>
                </div>

                <div class="flex justify-end mb-4">
                    <?php $themeApiUrl = '../api_user.php'; require __DIR__ . '/../partials/theme_dropdown.php'; ?>
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
                            if ($_GET['msg'] === 'structure_permanently_deleted') {
                                echo "Item da estrutura e seus vínculos excluídos permanentemente.";
                                if (($_GET['cleanup'] ?? '') === 'partial') echo " Alguns arquivos físicos não puderam ser removidos; consulte os logs do servidor.";
                            }
                            if ($_GET['msg'] === 'category_saved') echo "✓ Categoria criada com sucesso!";
                            if ($_GET['msg'] === 'category_deleted') echo "Categoria removida com sucesso!";
                            if ($_GET['msg'] === 'subcategory_saved') echo "✓ Subcategoria criada com sucesso!";
                            if ($_GET['msg'] === 'subcategory_deleted') echo "Subcategoria removida com sucesso!";
                            if ($_GET['msg'] === 'subject_saved') echo "✓ Assunto criado com sucesso!";
                            if ($_GET['msg'] === 'subject_deleted') echo "Assunto removido com sucesso!";
                            if ($_GET['msg'] === 'workspace_saved') echo "Documentação do processo atualizada com sucesso.";
                            if ($_GET['msg'] === 'workspace_submitted') echo "Documentação enviada para revisão. Os gestores responsáveis foram notificados.";
                            if ($_GET['msg'] === 'workspace_returned') echo "Documentação devolvida para ajustes com a justificativa registrada.";
                            if ($_GET['msg'] === 'workspace_approved') echo "Documentação homologada e liberada no portal.";
                            if ($_GET['msg'] === 'workspace_deprecated') echo "Documentação marcada como obsoleta.";
                            if ($_GET['msg'] === 'workspace_reopened') echo "Documentação reaberta para edição.";
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
                            if ($_GET['msg'] === 'team_system_access_saved') echo "✓ Acessos administrativos da equipe atualizados e auditados.";
                            if ($_GET['msg'] === 'team_subgroup_added') echo "✓ Subgrupo incluído. A herança de conteúdo vale pelos caminhos de equipes ativas.";
                            if ($_GET['msg'] === 'team_subgroup_removed') echo "✓ Vínculo com o subgrupo removido. Os demais acessos foram preservados.";
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errorMessage)): ?>
                    <div class="bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs p-3 rounded-md mb-6 shadow-xs font-medium">
                        Atenção: <?= htmlspecialchars($errorMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'visao_geral'): ?>
                    <?php if ($canViewGlobalAudit && $globalDashboard): ?>
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
                                <a href="index.php?tab=documentos&amp;filter_status=all" class="dashboard-link-card dashboard-stat-card rounded-xl border border-violet-500/20 bg-violet-500/5 p-4 text-decoration-none shadow-xs transition hover:-translate-y-0.5 hover:shadow-sm dark:bg-violet-500/10">
                                    <div class="flex items-start justify-between gap-3"><span class="dashboard-stat-label text-[11px] font-bold uppercase tracking-wider text-violet-700 dark:text-violet-300">Novos conteúdos</span><span class="dashboard-stat-icon flex h-7 w-7 items-center justify-center rounded-md bg-violet-500/15 text-violet-700 dark:text-violet-300"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></span></div>
                                    <span class="mt-3 block font-mono text-3xl font-bold text-slate-900 dark:text-slate-100"><?= (int)$dashboardContent['created_in_period'] ?></span>
                                    <span class="mt-6 block text-[11px] text-slate-500">Criados nos últimos <?= htmlspecialchars($dashboardPeriodLabel) ?></span>
                                </a>
                                <a href="index.php?tab=documentos&amp;filter_status=all" class="dashboard-link-card dashboard-stat-card rounded-xl border border-slate-300 bg-white p-4 text-decoration-none shadow-xs transition hover:-translate-y-0.5 hover:shadow-sm dark:border-[#454956] dark:bg-[#353842]">
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
                                                <a href="index.php?tab=documentos&amp;filter_status=all&amp;filter_cat=<?= urlencode($categoryReport['name']) ?>" class="group block text-decoration-none"><div class="mb-1.5 flex items-center justify-between gap-4 text-xs"><span class="truncate font-semibold text-slate-700 group-hover:underline dark:text-slate-200"><?= htmlspecialchars($categoryReport['name']) ?></span><span class="shrink-0 font-mono text-slate-500"><?= (int)$categoryReport['documents_total'] ?> doc<?= (int)$categoryReport['documents_total'] === 1 ? '' : 's' ?></span></div><div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-[#2c2e33]"><div class="h-full rounded-full bg-slate-700 transition group-hover:bg-emerald-500 dark:bg-slate-300" style="width: <?= $categoryWidth ?>%"></div></div></a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="mt-5 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-xs dark:border-[#454956] sm:grid-cols-4"><div><span class="block text-[10px] text-slate-400">Categorias ativas</span><span class="font-mono font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardStructure['categories_active'] ?>/<?= (int)$dashboardStructure['categories_total'] ?></span></div><div><span class="block text-[10px] text-slate-400">Subcategorias</span><span class="font-mono font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardStructure['subcategories_total'] ?></span></div><div><span class="block text-[10px] text-slate-400">Assuntos</span><span class="font-mono font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardStructure['subjects_total'] ?></span></div><div><span class="block text-[10px] text-slate-400">Criados no período</span><span class="font-mono font-bold text-slate-800 dark:text-slate-100"><?= (int)$dashboardContent['created_in_period'] ?></span></div></div>
                                </section>

                                <section class="dashboard-panel border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] xl:col-span-5">
                                    <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Governança de acesso</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Equipes e permissões</h2></div><?php if ($isGlobalAdminCurrent): ?><a href="index.php?tab=grupos" class="text-xs font-bold text-slate-600 hover:underline dark:text-slate-300">Gerir equipes &rarr;</a><?php endif; ?></div>
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
                                    <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Atividade do acervo</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Documentos alterados</h2></div><a href="index.php?tab=documentos&amp;filter_status=all" class="text-xs font-bold text-slate-600 hover:underline dark:text-slate-300">Ver todos &rarr;</a></div>
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
                                        <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Documentos mais consultados</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">O que as pessoas usam</h2></div><a href="index.php?tab=documentos&amp;filter_status=all" class="text-xs font-bold text-slate-600 hover:underline dark:text-slate-300">Ver acervo &rarr;</a></div>
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
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1"><?= $canViewGlobalAudit ? 'Resumo global e operações recentes de todo o acervo municipal.' : 'Resumo exclusivo das categorias em que você possui acesso de edição ou administração.' ?></p>
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
                                <a href="index.php?tab=documentos&amp;filter_status=all" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-700 dark:text-slate-300 text-xs font-semibold hover:bg-slate-100 dark:hover:bg-[#3e424e] transition text-decoration-none">
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
                    <div class="space-y-5">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                            <div>
                                <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Documentos</h1>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Acompanhe as pendências e consulte o acervo publicado separadamente.</p>
                            </div>

                            <div class="flex items-center gap-2">
                                <a href="index.php?tab=novo_documento" class="px-4 py-2 rounded-md bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90 transition shadow-xs text-decoration-none flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                    <span>Novo Conteúdo</span>
                                </a>
                            </div>
                        </div>

                        <nav class="grid grid-cols-1 gap-2 sm:grid-cols-3" aria-label="Visualização dos documentos">
                            <?php foreach ([
                                ['status' => 'pending', 'label' => 'Pendentes', 'count' => $totalRascunhos + $totalEmRevisao, 'hint' => 'Rascunhos e em revisão'],
                                ['status' => 'published', 'label' => 'Publicados', 'count' => $totalPublicados, 'hint' => 'Acervo disponível no portal'],
                                ['status' => 'all', 'label' => 'Todos', 'count' => $totalRascunhos + $totalEmRevisao + $totalPublicados + $totalInativos, 'hint' => 'Todos os status, exceto lixeira'],
                            ] as $documentView): ?>
                                <?php $documentViewActive = $filterStatus === $documentView['status']; ?>
                                <a href="<?= htmlspecialchars($documentFilterUrl($documentView['status']), ENT_QUOTES, 'UTF-8') ?>"
                                   class="rounded-lg border px-4 py-3 text-decoration-none transition <?= $documentViewActive ? 'border-slate-900 bg-slate-900 text-white shadow-sm dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900' : 'border-slate-200 bg-white text-slate-800 hover:border-slate-400 dark:border-[#454956] dark:bg-[#353842] dark:text-slate-100 dark:hover:border-slate-400' ?>"
                                   <?= $documentViewActive ? 'aria-current="page"' : '' ?>>
                                    <span class="flex items-baseline justify-between gap-3"><span class="text-sm font-bold"><?= $documentView['label'] ?></span><span class="text-lg font-bold tabular-nums"><?= (int)$documentView['count'] ?></span></span>
                                    <span class="mt-0.5 block text-[11px] <?= $documentViewActive ? 'text-slate-200 dark:text-slate-600' : 'text-slate-500 dark:text-slate-400' ?>"><?= $documentView['hint'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </nav>

                        <!-- BARRA DE PESQUISA E FILTROS -->
                        <form method="GET" action="index.php" id="document-filter-form" class="p-4 rounded-md bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] shadow-xs space-y-3">
                            <input type="hidden" name="tab" value="documentos">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                                <div class="relative flex-1">
                                    <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" aria-label="Pesquisar documentos" class="input-minimal w-full pl-9 pr-3 py-2 text-xs" placeholder="Pesquisar por título, resumo, assunto ou tag...">
                                    <span class="absolute left-3 top-2.5 text-slate-400 text-xs">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                    </span>
                                </div>
                                <button type="submit" class="px-4 py-2 rounded-md bg-slate-200 dark:bg-[#2c2e33] hover:bg-slate-300 dark:hover:bg-[#3e424e] text-slate-700 dark:text-slate-300 font-semibold text-xs transition">
                                    Filtrar
                                </button>
                                <?php if ($searchQuery !== '' || $filterCat !== '' || $filterSubcat !== '' || $filterAssunto !== '' || $filterTipo !== '' || $filterStatus !== 'pending'): ?>
                                    <a href="index.php?tab=documentos" class="px-3 py-2 rounded-md bg-slate-100 dark:bg-[#2c2e33] text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 text-xs font-semibold">Limpar</a>
                                <?php endif; ?>
                            </div>

                            <div class="grid grid-cols-2 lg:grid-cols-5 gap-2.5 pt-2 border-t border-slate-100 dark:border-[#454956]">
                                <div>
                                    <label for="filter-cat" class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Categoria</label>
                                    <select id="filter-cat" name="filter_cat" onchange="onFilterCategoryChange()" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="">Todas</option>
                                        <?php foreach ($rawCategorias as $c): ?>
                                            <option value="<?= htmlspecialchars($c) ?>" <?= $filterCat === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="filter-subcat" class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Subcategoria</label>
                                    <select id="filter-subcat" name="filter_subcat" onchange="onFilterSubcategoryChange()" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="">Todas</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="filter-assunto" class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Assunto</label>
                                    <select id="filter-assunto" name="filter_assunto" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="">Todos</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Tipo</label>
                                    <select name="filter_tipo" aria-label="Filtrar por tipo" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="">Todos</option>
                                        <option value="file" <?= $filterTipo === 'file' ? 'selected' : '' ?>>Arquivo</option>
                                        <option value="text" <?= $filterTipo === 'text' ? 'selected' : '' ?>>Texto</option>
                                        <option value="code" <?= $filterTipo === 'code' ? 'selected' : '' ?>>Código</option>
                                        <option value="video" <?= $filterTipo === 'video' ? 'selected' : '' ?>>Vídeo</option>
                                        <option value="link" <?= $filterTipo === 'link' ? 'selected' : '' ?>>Link</option>
                                        <option value="flow" <?= $filterTipo === 'flow' ? 'selected' : '' ?>>Fluxo do Processo</option>
                                        <option value="orgchart" <?= $filterTipo === 'orgchart' ? 'selected' : '' ?>>Organograma</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Status</label>
                                    <select name="filter_status" aria-label="Filtrar por status" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pendentes</option>
                                        <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>Publicado</option>
                                        <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Rascunho</option>
                                        <option value="review" <?= $filterStatus === 'review' ? 'selected' : '' ?>>Em revisão</option>
                                        <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inativo</option>
                                        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Todos (exceto lixeira)</option>
                                    </select>
                                </div>
                            </div>
                        </form>

                        <form method="POST" action="<?= htmlspecialchars($documentFilterUrl($filterStatus, $page), ENT_QUOTES, 'UTF-8') ?>" id="batch-form" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="document_id" id="single-document-id" value="">

                        <!-- BARRA DE AÇÕES EM LOTE -->
                        <div class="flex flex-wrap items-center justify-between gap-3 p-3 rounded-md bg-slate-100 dark:bg-[#2c2e33] border border-slate-200 dark:border-[#454956] text-xs">
                            <div class="flex flex-wrap items-center gap-2">
                                <span id="batch-selection-count" class="font-bold text-slate-700 dark:text-slate-300" aria-live="polite">Selecione documentos para ações em lote</span>
                                <button type="submit" name="batch_action" value="submit_review" data-batch-action="submit_review" disabled data-confirm="Enviar os documentos selecionados para revisão?" data-confirm-title="Enviar para revisão" data-confirm-label="Enviar para revisão" class="px-3 py-1 rounded bg-blue-600 text-white font-semibold hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40">
                                    Enviar para revisão
                                </button>
                                <button type="submit" name="batch_action" value="publish" data-batch-action="publish" disabled data-confirm="Aprovar e publicar os documentos selecionados? Somente itens revisados serão aceitos." data-confirm-title="Publicar documentos" data-confirm-label="Aprovar e publicar" data-confirm-tone="success" class="px-3 py-1 rounded bg-emerald-600 text-white font-semibold hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40">
                                    Aprovar e publicar
                                </button>
                                <button type="submit" name="batch_action" value="draft" data-batch-action="draft" disabled data-confirm="Mover selecionados para rascunho?" data-confirm-title="Mover para rascunho" data-confirm-label="Mover para rascunho" data-confirm-tone="warning" class="px-3 py-1 rounded bg-amber-600 text-white font-semibold hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40">
                                    Mover para Rascunho
                                </button>
                                <button type="submit" name="batch_action" value="trash" data-batch-action="trash" disabled data-confirm="Mover selecionados para a lixeira?" data-confirm-title="Mover para a lixeira" data-confirm-label="Mover para a lixeira" data-confirm-tone="danger" class="px-3 py-1 rounded bg-red-600 text-white font-semibold hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40 flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    <span>Mover para Lixeira</span>
                                </button>
                            </div>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400">A seleção vale apenas para esta página.</span>
                        </div>

                        <div class="bg-white dark:bg-[#353842] rounded-md border border-slate-200 dark:border-[#454956] shadow-xs overflow-hidden">
                            <?php if (empty($documentosPaginados)): ?>
                                <div class="p-12 text-center">
                                    <div class="w-12 h-12 rounded-full bg-slate-100 dark:bg-[#2c2e33] text-slate-400 flex items-center justify-center font-bold text-xl mx-auto mb-3">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                    </div>
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-1">Nenhum documento nesta visualização</h3>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Altere os filtros ou consulte todos os documentos.</p>
                                    <a href="<?= htmlspecialchars($documentFilterUrl('all'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold shadow-xs">
                                        Ver todos
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs text-slate-700 dark:text-slate-300">
                                        <thead class="bg-slate-50 dark:bg-[#2c2e33] text-slate-400 uppercase font-semibold text-[10px] border-b border-slate-200 dark:border-[#454956]">
                                            <tr>
                                                <th class="p-3 w-8">
                                                    <input type="checkbox" onclick="toggleSelectAll(this)" aria-label="Selecionar todos os documentos desta página">
                                                </th>
                                                <th class="p-3">Título</th>
                                                <th class="p-3">Localização</th>
                                                <th class="p-3">Seção</th>
                                                <th class="p-3">Atualizado</th>
                                                <th class="p-3">Status</th>
                                                <th class="p-3 text-right">Ação</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-[#454956]/60">
                                            <?php foreach ($documentosPaginados as $doc): ?>
                                                <?php $st = strtolower($doc['status'] ?: 'published'); ?>
                                                <tr class="cursor-pointer hover:bg-slate-50/70 dark:hover:bg-[#3e424e]/50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500 transition" tabindex="0" aria-label="Abrir detalhes de <?= htmlspecialchars($doc['titulo'], ENT_QUOTES, 'UTF-8') ?>" data-document-url="index.php?tab=detalhes_documento&amp;id=<?= (int)$doc['id'] ?>" onclick="openDocumentRow(event)" onkeydown="openDocumentRow(event)">
                                                    <td class="p-3">
                                                        <input type="checkbox" name="selected_docs[]" value="<?= $doc['id'] ?>" class="batch-checkbox" data-status="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>" data-reviewed="<?= !empty($doc['reviewed_by']) ? '1' : '0' ?>" onchange="updateBatchToolbar()" aria-label="Selecionar <?= htmlspecialchars($doc['titulo'], ENT_QUOTES, 'UTF-8') ?>">
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
                                                            <?= htmlspecialchars((string)($doc['section_label'] ?? strtoupper($doc['tipo_conteudo']))) ?>
                                                        </span>
                                                    </td>
                                                    <td class="p-3 text-[11px] text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                                        <?= date('d/m/Y H:i', strtotime((string)$doc['updated_at'])) ?>
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
                                                        <button type="button" onclick="toggleActionMenu(<?= $doc['id'] ?>, event)" data-action-menu-trigger aria-label="Ações do documento <?= htmlspecialchars($doc['titulo'], ENT_QUOTES, 'UTF-8') ?>" aria-controls="action-menu-<?= $doc['id'] ?>" aria-expanded="false" class="px-2 py-1 rounded text-slate-500 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-[#454956] font-bold text-sm">
                                                            ⋯
                                                        </button>

                                                        <div id="action-menu-<?= $doc['id'] ?>" class="hidden fixed w-48 bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] rounded-md shadow-md py-1 text-left text-xs font-medium" style="z-index: 1000">
                                                            <a href="index.php?tab=detalhes_documento&id=<?= $doc['id'] ?>" class="block px-3 py-1.5 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">Visualizar Detalhes</a>
                                                            <a href="index.php?tab=novo_documento&action=edit_doc&id=<?= $doc['id'] ?>" class="block px-3 py-1.5 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">Editar Metadados</a>
                                                            <?php if ($doc['tipo_conteudo'] === 'file'): ?>
                                                                <a href="index.php?tab=substituir_arquivo&id=<?= $doc['id'] ?>" class="block px-3 py-1.5 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">Substituir arquivo</a>
                                                            <?php endif; ?>
                                                            <div class="my-1 border-t border-slate-100 dark:border-[#454956]"></div>
                                                            <button type="submit" form="batch-form" name="document_trash_action" value="trash" onclick="document.getElementById('single-document-id').value = '<?= $doc['id'] ?>'" data-confirm="Mover este documento para a lixeira?" data-confirm-title="Mover para a lixeira" data-confirm-label="Mover para a lixeira" data-confirm-tone="danger" class="block w-full px-3 py-1.5 text-left text-red-600 dark:text-red-400 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">
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
                        <div class="flex flex-col gap-3 px-1 text-xs text-slate-500 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                            <p><?= (int)$totalDocsFiltered ?> <?= $totalDocsFiltered === 1 ? 'documento encontrado' : 'documentos encontrados' ?> · página <?= $page ?> de <?= $totalPages ?></p>
                            <?php if ($totalPages > 1): ?>
                                <nav class="flex items-center gap-2" aria-label="Paginação dos documentos">
                                    <?php if ($page > 1): ?><a href="<?= htmlspecialchars($documentFilterUrl($filterStatus, $page - 1), ENT_QUOTES, 'UTF-8') ?>" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50 dark:border-[#454956] dark:bg-[#353842] dark:text-slate-200 dark:hover:bg-[#3e424e]">Anterior</a><?php endif; ?>
                                    <?php if ($page < $totalPages): ?><a href="<?= htmlspecialchars($documentFilterUrl($filterStatus, $page + 1), ENT_QUOTES, 'UTF-8') ?>" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50 dark:border-[#454956] dark:bg-[#353842] dark:text-slate-200 dark:hover:bg-[#3e424e]">Próxima</a><?php endif; ?>
                                </nav>
                            <?php endif; ?>
                        </div>
                        </form>
                    </div>
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
                                    <?php if ($isGlobalAdminCurrent): ?>
                                        <select name="status" class="input-minimal w-full px-3 py-1.5 text-xs">
                                            <option value="ativo" <?= ($editCat['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                            <option value="inativo" <?= ($editCat['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                        </select>
                                    <?php else: ?>
                                        <input type="hidden" name="status" value="<?= htmlspecialchars($editCat['status'] ?? 'ativo', ENT_QUOTES, 'UTF-8') ?>">
                                        <p class="text-xs text-slate-500"><?= ucfirst(htmlspecialchars($editCat['status'] ?? 'ativo')) ?> · somente Super Admins podem alterar.</p>
                                    <?php endif; ?>
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
                                                    <?php if ($isGlobalAdminCurrent): ?>
                                                        <form method="post" class="inline" data-confirm="<?= $cat['status'] === 'ativo' ? 'Descartar esta categoria e ocultar todo o ramo? Os vínculos serão preservados.' : 'Restaurar esta categoria?' ?>" data-confirm-title="<?= $cat['status'] === 'ativo' ? 'Descartar categoria' : 'Restaurar categoria' ?>" data-confirm-label="<?= $cat['status'] === 'ativo' ? 'Descartar' : 'Restaurar' ?>" data-confirm-tone="<?= $cat['status'] === 'ativo' ? 'danger' : 'success' ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="structure_type" value="category">
                                                            <input type="hidden" name="structure_id" value="<?= (int)$cat['id'] ?>">
                                                            <button type="submit" name="structure_action" value="<?= $cat['status'] === 'ativo' ? 'discard' : 'restore' ?>" class="font-semibold <?= $cat['status'] === 'ativo' ? 'text-red-600' : 'text-emerald-600' ?>"><?= $cat['status'] === 'ativo' ? 'Descartar' : 'Restaurar' ?></button>
                                                        </form>
                                                    <?php endif; ?>
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
                                                    <?php if ($permService->canAdmin($currentAdminUserId, 'subcategory', (int)$sub['id'])): ?>
                                                        <form method="post" class="inline" data-confirm="<?= $sub['status'] === 'ativo' ? 'Descartar esta subcategoria e ocultar seus assuntos?' : 'Restaurar esta subcategoria?' ?>" data-confirm-title="<?= $sub['status'] === 'ativo' ? 'Descartar subcategoria' : 'Restaurar subcategoria' ?>" data-confirm-label="<?= $sub['status'] === 'ativo' ? 'Descartar' : 'Restaurar' ?>" data-confirm-tone="<?= $sub['status'] === 'ativo' ? 'danger' : 'success' ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="structure_type" value="subcategory">
                                                            <input type="hidden" name="structure_id" value="<?= (int)$sub['id'] ?>">
                                                            <button type="submit" name="structure_action" value="<?= $sub['status'] === 'ativo' ? 'discard' : 'restore' ?>" class="font-semibold <?= $sub['status'] === 'ativo' ? 'text-red-600' : 'text-emerald-600' ?>"><?= $sub['status'] === 'ativo' ? 'Descartar' : 'Restaurar' ?></button>
                                                        </form>
                                                    <?php endif; ?>
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
                                <?php
                                    $subjectVisibilityValue = $editAss['visibility'] ?? 'private';
                                    $subjectVisibilityControlId = 'subject-visibility';
                                    $subjectVisibilityCreationMode = !$editAss;
                                    $canManageSubjectVisibility = $editAss
                                        ? $permService->canAdminSubject($currentAdminUserId, (int)$editAss['id'])
                                        : $_canManageAnySubjectVisibility;
                                    require __DIR__ . '/../partials/subject_visibility.php';
                                ?>
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
                                            <th class="p-2">Visibilidade</th>
                                            <th class="p-2">Docs</th>
                                            <th class="p-2 text-right">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                        <?php foreach ($listAssuntos as $ass): ?>
                                            <tr>
                                                <td class="p-2 font-bold"><?= htmlspecialchars($ass['nome']) ?></td>
                                                <td class="p-2 text-slate-500"><?= htmlspecialchars($ass['subcategoria_nome']) ?></td>
                                                <td class="p-2"><span class="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-semibold dark:border-[#454956] dark:bg-[#2c2e33]"><?= $ass['visibility'] === 'public' ? 'Público' : 'Privado' ?></span></td>
                                                <td class="p-2"><?= $ass['total_docs'] ?> docs</td>
                                                <td class="p-2 text-right">
                                                    <a href="index.php?tab=editar_estrutura&type=assunto&id=<?= $ass['id'] ?>" class="text-amber-600 font-semibold mr-2">Disposição Visual &rarr;</a>
                                                    <?php if ($permService->canAdmin($currentAdminUserId, 'subject', (int)$ass['id'])): ?>
                                                        <form method="post" class="inline" data-confirm="<?= $ass['status'] === 'ativo' ? 'Descartar este assunto e ocultar seus documentos?' : 'Restaurar este assunto?' ?>" data-confirm-title="<?= $ass['status'] === 'ativo' ? 'Descartar assunto' : 'Restaurar assunto' ?>" data-confirm-label="<?= $ass['status'] === 'ativo' ? 'Descartar' : 'Restaurar' ?>" data-confirm-tone="<?= $ass['status'] === 'ativo' ? 'danger' : 'success' ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="structure_type" value="subject">
                                                            <input type="hidden" name="structure_id" value="<?= (int)$ass['id'] ?>">
                                                            <button type="submit" name="structure_action" value="<?= $ass['status'] === 'ativo' ? 'discard' : 'restore' ?>" class="font-semibold <?= $ass['status'] === 'ativo' ? 'text-red-600' : 'text-emerald-600' ?>"><?= $ass['status'] === 'ativo' ? 'Descartar' : 'Restaurar' ?></button>
                                                        </form>
                                                    <?php endif; ?>
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
                                                        <button type="submit" name="document_trash_action" value="permanent_delete" data-confirm="Esta ação não poderá ser desfeita. Deseja excluir permanentemente este documento e o arquivo do servidor?" data-confirm-title="Excluir documento definitivamente" data-confirm-label="Excluir definitivamente" data-confirm-tone="danger" class="text-red-600 font-semibold hover:underline">Excluir definitivamente</button>
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
                        if ($failedDocumentSubmission !== null) $editDoc = $failedDocumentSubmission;
                        $isEditMode = !empty($editDoc['id']);
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
                                    <input type="hidden" name="document_version" value="<?= htmlspecialchars((string)($editDoc['document_version'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>

                                <!-- Informações básicas -->
                                <div class="space-y-4">
                                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 pb-1 border-b border-slate-100 dark:border-[#454956]">Informações Principais</h3>
                                    <div>
                                        <label for="document-title" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Título *</label>
                                        <input type="text" id="document-title" name="titulo" required value="<?= htmlspecialchars($editDoc['titulo'] ?? '') ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex: Requerimento Padrão de Férias 2026">
                                    </div>
                                    <div>
                                        <label for="document-description" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
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
                                            <label for="select-cat" class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Categoria *</label>
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
                                            <label for="select-subcat" class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Subcategoria *</label>
                                            <select id="select-subcat" name="subcategoria" required onchange="onSubcategoryChange()" class="input-minimal w-full px-2.5 py-1.5 text-xs">
                                                <option value="">-- Selecione ▾ --</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label for="select-assunto" class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Assunto *</label>
                                            <select id="select-assunto" name="assunto" required class="input-minimal w-full px-2.5 py-1.5 text-xs">
                                                <option value="">-- Selecione ▾ --</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Conteúdo -->
                                <div class="space-y-3">
                                    <?php
                                    $selectedDocumentSectionKey = (string)($editDoc['section_key'] ?? ($_GET['section'] ?? 'documents'));
                                    $selectedSectionDefinition = null;
                                    foreach ($availableDocumentSections as $sectionDefinition) {
                                        if (($sectionDefinition['section_key'] ?? '') === $selectedDocumentSectionKey) {
                                            $selectedSectionDefinition = $sectionDefinition;
                                            break;
                                        }
                                    }
                                    if ($selectedSectionDefinition === null) {
                                        $selectedSectionDefinition = $availableDocumentSections[0] ?? ['section_key' => 'documents', 'editor_kind' => 'richtext'];
                                        $selectedDocumentSectionKey = (string)$selectedSectionDefinition['section_key'];
                                    }
                                    $selectedEditorKind = (string)($selectedSectionDefinition['editor_kind'] ?? 'richtext');
                                    $selectedContentType = $documentSectionService->resolveSelection($selectedDocumentSectionKey)['content_type'];
                                    $selectedVideoSource = ($selectedContentType === 'video' && !empty($editDoc['link_externo'])) ? 'url' : 'upload';
                                    $initialStructuredContent = [];
                                    if (!empty($editDoc['structured_content'])) {
                                        $decodedStructuredContent = json_decode((string)$editDoc['structured_content'], true);
                                        $initialStructuredContent = is_array($decodedStructuredContent) ? $decodedStructuredContent : [];
                                    }
                                    ?>
                                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 pb-1 border-b border-slate-100 dark:border-[#454956]">Conteúdo</h3>
                                    <div class="rounded-md border border-slate-200 bg-slate-50/70 p-4 dark:border-[#454956] dark:bg-[#2c2e33]">
                                        <label for="document-section" class="block text-xs font-semibold text-slate-700 dark:text-slate-300">Seção e formato *</label>
                                        <p class="mt-0.5 text-[10px] text-slate-400">A seção aparecerá no assunto automaticamente depois que este conteúdo for publicado.</p>
                                        <select id="document-section" name="section_key" required class="input-minimal mt-2 w-full px-3 py-2 text-xs" onchange="selectDocumentSection()">
                                            <?php foreach ($availableDocumentSections as $sectionDefinition): ?>
                                                <option value="<?= htmlspecialchars((string)$sectionDefinition['section_key']) ?>" data-editor-kind="<?= htmlspecialchars((string)$sectionDefinition['editor_kind']) ?>" <?= $selectedDocumentSectionKey === (string)$sectionDefinition['section_key'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$sectionDefinition['label']) ?> — <?= htmlspecialchars((string)$sectionDefinition['description']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <input type="hidden" id="structured-content-input" name="structured_content" value="<?= htmlspecialchars(json_encode($initialStructuredContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="hidden" aria-hidden="true">
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="file" <?= $selectedContentType === 'file' ? 'checked' : '' ?> onchange="toggleFormContent('file')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                            Arquivo
                                        </label>
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="text" <?= $selectedContentType === 'text' ? 'checked' : '' ?> onchange="toggleFormContent('text')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            Texto
                                        </label>
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="code" <?= $selectedContentType === 'code' ? 'checked' : '' ?> onchange="toggleFormContent('code')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l-3 3 3 3m8-6l3 3-3 3m-3-8l-2 10"/></svg>
                                            Código
                                        </label>
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="video" <?= $selectedContentType === 'video' ? 'checked' : '' ?> onchange="toggleFormContent('video')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                            Vídeo
                                        </label>
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="link" <?= $selectedContentType === 'link' ? 'checked' : '' ?> onchange="toggleFormContent('link')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                            Link
                                        </label>
                                        <input type="radio" name="tipo_conteudo" value="flow" <?= $selectedContentType === 'flow' ? 'checked' : '' ?> class="sr-only">
                                        <input type="radio" name="tipo_conteudo" value="orgchart" <?= $selectedContentType === 'orgchart' ? 'checked' : '' ?> class="sr-only">
                                    </div>

                                    <?php if (!$isEditMode): ?>
                                        <div id="box-file" class="<?= $selectedContentType === 'file' ? '' : 'hidden ' ?>space-y-3">
                                            <div id="batch-dropzone" tabindex="0" role="button" aria-controls="file-input" class="cursor-pointer rounded-md border-2 border-dashed border-slate-300 bg-slate-50/70 p-6 text-center outline-none transition hover:border-slate-500 hover:bg-slate-100/70 focus:border-slate-500 focus:ring-2 focus:ring-slate-400/40 dark:border-slate-600 dark:bg-[#2c2e33] dark:hover:border-slate-400 dark:hover:bg-[#353842]">
                                                <div class="mx-auto mb-2 flex h-9 w-9 items-center justify-center rounded-full bg-slate-900 text-white dark:bg-white dark:text-slate-900">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 16V4m0 0L8 8m4-4 4 4M4 16.5V19a2 2 0 002 2h12a2 2 0 002-2v-2.5"/></svg>
                                                </div>
                                                <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Arraste arquivos aqui ou clique para selecionar</p>
                                                <p class="mt-1 text-[11px] text-slate-400">Até 20 arquivos por vez · arquivos gerais até 25 MB · vídeos até 50 MB</p>
                                                <input type="file" id="file-input" name="arquivo_file" multiple aria-label="Selecionar arquivos para envio" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.bmp,.avif,.txt,.log,.csv,.md,.json,.xml,.doc,.docx,.mp3,.wav,.ogg,.mp4,.webm,.ogv,.m4v,.mov" class="hidden">
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
                                        <div id="box-file" class="<?= $selectedContentType === 'file' ? '' : 'hidden ' ?>p-6 rounded-md border-2 border-dashed border-slate-300 dark:border-slate-600 bg-slate-50/70 dark:bg-[#2c2e33] text-center">
                                            <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Arraste o arquivo aqui ou clique para selecionar</p>
                                            <p class="text-[11px] text-slate-400 mt-1 mb-3">PDF, imagens, textos, DOC/DOCX, áudio e vídeo (Máx: 25MB)</p>
                                            <input type="file" id="file-input" name="arquivo_file" aria-label="Selecionar arquivo" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.bmp,.avif,.txt,.log,.csv,.md,.json,.xml,.doc,.docx,.mp3,.wav,.ogg,.mp4,.webm,.ogv,.m4v,.mov" class="hidden" onchange="updateFilePreview(this)">
                                            <button type="button" onclick="document.getElementById('file-input').click()" class="px-4 py-1.5 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold">Selecionar arquivo</button>
                                            <div id="file-preview-name" class="mt-2 text-xs text-slate-500"></div>
                                        </div>
                                    <?php endif; ?>

                                    <div id="box-text" class="<?= $selectedContentType === 'text' ? '' : 'hidden ' ?>rounded-md border border-slate-200 bg-slate-50/70 p-4 dark:border-[#454956] dark:bg-[#2c2e33]">
                                        <div class="mb-3"><h4 class="text-xs font-bold text-slate-800 dark:text-slate-100">Editor de artigo</h4><p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Clique na imagem para selecioná-la. Arraste qualquer parte da imagem para movê-la no texto ou escolher uma zona lateral. Arraste os pontos nos cantos para redimensionar.</p></div>
                                        <textarea id="text-content-input" name="conteudo_html" rows="14" aria-label="Conteúdo formatado do documento" class="input-minimal min-h-80 w-full px-3 py-2 text-sm" placeholder="Comece a escrever o conteúdo..."><?= htmlspecialchars($selectedContentType === 'text' ? ($editDoc['conteudo_html'] ?? '') : '') ?></textarea>
                                        <div id="quill-editor-container" class="govdoc-quill-editor hidden" aria-label="Conteúdo formatado do documento"></div>
                                        <div id="article-selected-media" class="govdoc-media-inspector hidden mt-3" aria-label="Ajustar mídia selecionada">
                                            <div class="govdoc-media-inspector__head">
                                                <div><strong data-media-kind>Imagem selecionada</strong><span class="mt-1 block">Arraste no editor para escolher centro ou texto ao lado; use os controles abaixo para ajuste fino.</span></div>
                                                <button type="button" data-media-remove class="govdoc-media-inspector__delete" aria-label="Excluir imagem"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 4h4M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg><span data-media-remove-label>Excluir imagem</span></button>
                                            </div>
                                            <div class="govdoc-media-inspector__layouts" role="group" aria-label="Posição e contorno do texto">
                                                <button type="button" data-media-layout="center" aria-pressed="true">Centro</button>
                                                <button type="button" data-media-layout="wrap-left" aria-pressed="false">Texto à direita</button>
                                                <button type="button" data-media-layout="wrap-right" aria-pressed="false">Texto à esquerda</button>
                                            </div>
                                            <div class="govdoc-media-inspector__fields">
                                                <label class="govdoc-media-inspector__width">Largura <output data-media-width-output>80%</output><input data-media-width type="range" min="25" max="100" step="1" value="80" aria-label="Largura da mídia"></label>
                                                <label data-media-alt-wrap>Descrição da imagem<input data-media-alt type="text" maxlength="200" placeholder="Descreva a imagem"></label>
                                                <label>Legenda<input data-media-caption type="text" maxlength="300" placeholder="Legenda opcional"></label>
                                            </div>
                                            <div class="govdoc-media-inspector__actions"><button type="button" data-media-move="up">↑ Mover acima</button><button type="button" data-media-move="down">↓ Mover abaixo</button><span class="govdoc-media-inspector__hint">Dica: Delete ou Backspace exclui o bloco selecionado.</span></div>
                                        </div>
                                        <div id="article-media-tools" class="mt-3 hidden flex-wrap items-end gap-2 rounded-md border border-slate-200 bg-white p-3 dark:border-[#565b68] dark:bg-[#353842]">
                                            <div class="min-w-36 flex-1"><label for="article-image-alt" class="mb-1 block text-[11px] font-semibold text-slate-600 dark:text-slate-300">Descrição da imagem</label><input id="article-image-alt" type="text" maxlength="200" class="input-minimal w-full px-2 py-1.5 text-xs" placeholder="Ex.: Fachada da unidade"></div>
                                            <div class="min-w-36 flex-1"><label for="article-image-caption" class="mb-1 block text-[11px] font-semibold text-slate-600 dark:text-slate-300">Legenda (opcional)</label><input id="article-image-caption" type="text" maxlength="300" class="input-minimal w-full px-2 py-1.5 text-xs" placeholder="Fonte ou explicação breve"></div>
                                            <input id="article-image-file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="sr-only" aria-label="Selecionar imagem para o artigo">
                                            <button id="article-insert-image" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-[#565b68] dark:bg-[#2c2e33] dark:text-slate-100">Inserir imagem</button>
                                            <div class="basis-full border-t border-slate-100 pt-2 dark:border-[#565b68]"><label for="article-video-url" class="mb-1 block text-[11px] font-semibold text-slate-600 dark:text-slate-300">Vídeo no artigo</label><div class="flex flex-wrap gap-2"><input id="article-video-url" type="url" class="input-minimal min-w-48 flex-1 px-2 py-1.5 text-xs" placeholder="URL do YouTube ou Vimeo"><button id="article-insert-video" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-[#565b68] dark:bg-[#2c2e33] dark:text-slate-100">Inserir vídeo</button></div></div>
                                            <p id="article-media-status" class="basis-full text-[11px] text-slate-500 dark:text-slate-400" role="status" aria-live="polite">JPEG, PNG, GIF ou WebP. Limite do servidor: <?= htmlspecialchars((string)ini_get('upload_max_filesize')) ?> por imagem.</p>
                                        </div>
                                        <div id="quill-table-tools" class="mt-2 hidden flex-wrap items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                                            <span class="font-semibold">Tabela:</span>
                                            <label class="inline-flex items-center gap-1">Linhas <select id="quill-table-rows" class="input-minimal px-2 py-1 text-[11px]"><?php for ($tableSize = 2; $tableSize <= 10; $tableSize++): ?><option value="<?= $tableSize ?>" <?= $tableSize === 3 ? 'selected' : '' ?>><?= $tableSize ?></option><?php endfor; ?></select></label>
                                            <label class="inline-flex items-center gap-1">Colunas <select id="quill-table-columns" class="input-minimal px-2 py-1 text-[11px]"><?php for ($tableSize = 2; $tableSize <= 10; $tableSize++): ?><option value="<?= $tableSize ?>" <?= $tableSize === 3 ? 'selected' : '' ?>><?= $tableSize ?></option><?php endfor; ?></select></label>
                                            <button type="button" id="quill-insert-table" class="rounded-md border border-slate-200 bg-white px-2.5 py-1.5 font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-[#565b68] dark:bg-[#353842] dark:text-slate-200 dark:hover:bg-[#3e424e]">Inserir tabela</button>
                                        </div>
                                    </div>

                                    <div id="box-flow" class="<?= $selectedContentType === 'flow' ? '' : 'hidden ' ?>space-y-4 rounded-md border border-slate-200 bg-slate-50/70 p-4 dark:border-[#454956] dark:bg-[#2c2e33]">
                                        <div class="flex flex-wrap items-start justify-between gap-3"><div><h4 class="text-xs font-bold text-slate-800 dark:text-slate-100">Editor de Fluxo do Processo</h4><p class="mt-0.5 text-[11px] text-slate-400">Organize as etapas na ordem de execução. Use “Decisão” para destacar pontos de escolha.</p></div><button type="button" onclick="addFlowNode()" class="rounded-md bg-slate-900 px-3 py-2 text-[11px] font-semibold text-white dark:bg-white dark:text-slate-900">Adicionar etapa</button></div>
                                        <div id="flow-editor-list" class="space-y-2"></div>
                                        <div><p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">Pré-visualização</p><div id="flow-editor-preview" class="mx-auto max-w-2xl space-y-0"></div></div>
                                    </div>

                                    <div id="box-orgchart" class="<?= $selectedContentType === 'orgchart' ? '' : 'hidden ' ?>space-y-4 rounded-md border border-slate-200 bg-slate-50/70 p-4 dark:border-[#454956] dark:bg-[#2c2e33]">
                                        <div class="flex flex-wrap items-start justify-between gap-3"><div><h4 class="text-xs font-bold text-slate-800 dark:text-slate-100">Editor de Organograma</h4><p class="mt-0.5 text-[11px] text-slate-400">Cadastre unidades ou pessoas e selecione o nível superior de cada nó.</p></div><button type="button" onclick="addOrgNode()" class="rounded-md bg-slate-900 px-3 py-2 text-[11px] font-semibold text-white dark:bg-white dark:text-slate-900">Adicionar nó</button></div>
                                        <div id="orgchart-editor-list" class="space-y-2"></div>
                                        <div><p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">Pré-visualização hierárquica</p><div id="orgchart-editor-preview" class="overflow-x-auto rounded-md border border-slate-200 bg-white p-4 dark:border-[#454956] dark:bg-[#353842]"></div></div>
                                    </div>

                                    <div id="box-code" class="<?= $selectedContentType === 'code' ? '' : 'hidden ' ?>space-y-3 p-4 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-50/70 dark:bg-[#2c2e33]">
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
                                        <textarea id="code-source-input" name="codigo_fonte" rows="11" spellcheck="false" aria-label="Código-fonte" class="input-minimal w-full px-3 py-2 text-xs font-mono leading-relaxed" placeholder="Cole seu código aqui..." oninput="updateCodePreview()"><?= htmlspecialchars(($editDoc['tipo_conteudo'] ?? '') === 'code' ? ($editDoc['conteudo_html'] ?? '') : '') ?></textarea>

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

                                    <div id="box-video" class="<?= $selectedContentType === 'video' ? '' : 'hidden ' ?>space-y-4 rounded-md border border-slate-200 bg-slate-50/70 p-4 dark:border-[#454956] dark:bg-[#2c2e33]">
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
                                            <p class="mt-1 text-[11px] text-slate-400">MP4, WEBM, OGV, M4V ou MOV (máximo de 50 MB)</p>
                                            <input type="file" id="video-file-input" name="video_file" aria-label="Selecionar vídeo" accept="video/mp4,video/webm,video/ogg,video/quicktime,.mp4,.webm,.ogv,.m4v,.mov" class="hidden" onchange="updateVideoFilePreview(this)">
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

                                    <div id="box-link" class="<?= $selectedContentType === 'link' ? '' : 'hidden ' ?>p-4 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-50/70 dark:bg-[#2c2e33]">
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
                                <?php
                                    $subjectVisibilityValue = 'private';
                                    $subjectVisibilityControlId = 'nc-subject-visibility';
                                    $subjectVisibilityCreationMode = true;
                                    $canManageSubjectVisibility = $_canManageAnySubjectVisibility;
                                    require __DIR__ . '/../partials/subject_visibility.php';
                                ?>
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
                                    <div class="mt-2 flex flex-wrap gap-1.5"><span class="inline-flex rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600 dark:border-[#454956] dark:bg-[#2c2e33] dark:text-slate-300"><?= htmlspecialchars((string)($docDetails['section_label'] ?? 'Documento')) ?></span><span class="inline-flex rounded-full border border-blue-500/20 bg-blue-500/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300"><?= htmlspecialchars(DocumentWorkflowService::label($docDetails['status'])) ?></span></div>
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

                <?php if ($activeTab === 'servidores_ad' && $canManageAuthentication): ?>
                    <?php require __DIR__ . '/partials/ad-servers-management.php'; ?>
                <?php endif; ?>

                <?php if ($activeTab === 'tags' && $canManageTags): ?>
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
                        $resTab = strtolower(trim($_GET['res_tab'] ?? 'info'));

                        $resType = 'category';
                        if ($resTypeInput === 'subcategoria' || $resTypeInput === 'subcategory') {
                            $resType = 'subcategory';
                        } elseif ($resTypeInput === 'assunto' || $resTypeInput === 'subject') {
                            $resType = 'subject';
                        }
                        if ($resType === 'subject') {
                            $resTab = ['info' => 'overview', 'content' => 'evidence'][$resTab] ?? $resTab;
                            if (!in_array($resTab, ['overview', 'description', 'flow', 'steps', 'video', 'evidence', 'faq', 'permissions', 'integrations', 'history', 'section'], true)) {
                                $resTab = 'overview';
                            }
                        } elseif (!in_array($resTab, ['info', 'content', 'permissions'], true)) {
                            $resTab = 'info';
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
                            $resTab = $resType === 'subject' ? 'overview' : 'info';
                        }

                        $subjectWorkspace = [];
                        $subjectWorkspaceHistory = [];
                        $subjectWorkspaceCompleteness = [];
                        $subjectWorkspaceEnabledSections = [];
                        $subjectWorkspaceDocuments = [];
                        $subjectDocumentSectionsAdmin = [];
                        $selectedAdminDocumentSection = null;
                        $subjectVideoEmbed = ['kind' => 'invalid'];

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
                                SELECT s.id, s.subcategory_id, s.name, s.description, s.active, s.visibility,
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
                                $subjectWorkspace = $subjectWorkspaceService->get($resId);
                                $subjectWorkspaceEnabledSections = $subjectWorkspaceService->enabledSections($subjectWorkspace);
                                $subjectWorkspaceHistory = $subjectWorkspaceService->history($resId);
                                $subjectWorkspaceCompleteness = $subjectWorkspaceService->completeness($subjectWorkspace);
                                $subjectDocumentSectionsAdmin = $documentSectionService->existingSectionsForSubject($resId);
                                if ($resTab === 'section') {
                                    $requestedSectionKey = strtolower(trim((string)($_GET['section'] ?? '')));
                                    foreach ($subjectDocumentSectionsAdmin as $sectionDefinition) {
                                        if ($sectionDefinition['section_key'] === $requestedSectionKey) {
                                            $selectedAdminDocumentSection = $sectionDefinition;
                                            break;
                                        }
                                    }
                                    if ($selectedAdminDocumentSection === null) {
                                        $resTab = 'overview';
                                    }
                                }
                                $stmtWorkspaceDocs = $pdo->prepare("SELECT id, title, description, status, content_type, section_key, external_url, stored_filename FROM documents WHERE subject_id = :subject_id AND status <> 'inactive' ORDER BY title, id");
                                $stmtWorkspaceDocs->execute([':subject_id' => $resId]);
                                $subjectWorkspaceDocuments = $stmtWorkspaceDocs->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                $workspaceVideoDocumentId = (int)($subjectWorkspace['video_document_id'] ?? 0);
                                $workspaceVideoDocument = null;
                                foreach ($subjectWorkspaceDocuments as $workspaceDocument) {
                                    if ((int)$workspaceDocument['id'] === $workspaceVideoDocumentId) {
                                        $workspaceVideoDocument = $workspaceDocument;
                                        break;
                                    }
                                }
                                if ($workspaceVideoDocument && trim((string)$workspaceVideoDocument['external_url']) !== '') {
                                    $subjectVideoEmbed = VideoEmbedService::resolve((string)$workspaceVideoDocument['external_url']);
                                } elseif ($workspaceVideoDocument && !empty($workspaceVideoDocument['stored_filename'])) {
                                    $subjectVideoEmbed = ['kind' => 'direct', 'url' => '../document-file.php?id=' . (int)$workspaceVideoDocument['id']];
                                } elseif (trim((string)$subjectWorkspace['video_url']) !== '') {
                                    $subjectVideoEmbed = VideoEmbedService::resolve((string)$subjectWorkspace['video_url']);
                                }
                            }
                        }

                        if ($resId <= 0) {
                            $editableResourceCount = 0;
                            foreach ($treeVisibleCategories as $treeCategory) {
                                if ($permService->canEdit($currentAdminUserId, 'category', (int)$treeCategory['id'])) {
                                    $editableResourceCount++;
                                }
                            }
                            foreach ($treeVisibleSubcategories as $treeSubcategory) {
                                if ($permService->canEdit($currentAdminUserId, 'subcategory', (int)$treeSubcategory['id'])) {
                                    $editableResourceCount++;
                                }
                            }
                            foreach ($treeVisibleSubjects as $treeSubject) {
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
                                    Selecione um item para editar ou descartá-lo. Descartar preserva os vínculos e pode ser desfeito.
                                </p>
                            </div>
                            <span class="inline-flex w-fit items-center rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1 text-[11px] font-bold text-emerald-700 dark:text-emerald-400">
                                <?= $editableResourceCount ?> recurso<?= $editableResourceCount === 1 ? ' editável' : 's editáveis' ?>
                            </span>
                        </div>

                        <div class="flex gap-2 border-b border-slate-200 dark:border-[#454956]">
                            <a href="index.php?tab=editar_estrutura" class="px-3 py-2 text-xs font-semibold <?= !$showDiscardedTree ? 'border-b-2 border-slate-900 text-slate-900 dark:border-white dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white' ?>">Árvore ativa</a>
                            <a href="index.php?tab=editar_estrutura&amp;view=discarded" class="px-3 py-2 text-xs font-semibold <?= $showDiscardedTree ? 'border-b-2 border-slate-900 text-slate-900 dark:border-white dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white' ?>">Descartados (<?= count($treeDiscardedCategories) + count($treeDiscardedSubcategories) + count($treeDiscardedSubjects) ?>)</a>
                        </div>

                        <?php if ($showDiscardedTree): ?>
                            <div class="rounded-md border border-slate-200 bg-white p-4 dark:border-[#454956] dark:bg-[#353842]">
                                <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">Restaure primeiro a categoria e depois a subcategoria para reativar um ramo completo. A exclusão permanente apaga também todos os itens abaixo do selecionado.</p>
                                <?php foreach ([['category', 'Categoria', $treeDiscardedCategories], ['subcategory', 'Subcategoria', $treeDiscardedSubcategories], ['subject', 'Assunto', $treeDiscardedSubjects]] as [$discardedType, $discardedLabel, $discardedItems]): ?>
                                    <?php foreach ($discardedItems as $discardedItem): ?>
                                        <?php
                                            $canRestoreDiscarded = $discardedType === 'category'
                                                ? $isGlobalAdminCurrent
                                                : $permService->canAdmin($currentAdminUserId, $discardedType, (int)$discardedItem['id']);
                                            $deletePreview = $isGlobalAdminCurrent
                                                ? $structureDeletionService->preview($discardedType, (int)$discardedItem['id'], $currentAdminUserId)
                                                : null;
                                            $deleteSummary = $deletePreview !== null
                                                ? (int)$deletePreview['subcategories'] . ' subcategoria(s), ' . (int)$deletePreview['subjects'] . ' assunto(s) e ' . (int)$deletePreview['documents'] . ' documento(s)'
                                                : '';
                                        ?>
                                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 py-3 last:border-b-0 dark:border-[#454956]">
                                            <div>
                                                <p class="text-xs font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($discardedItem['nome']) ?></p>
                                                <p class="text-[11px] text-slate-500"><?= $discardedLabel ?><?php if ($discardedType !== 'category'): ?> · <?= htmlspecialchars($discardedItem['categoria_nome']) ?><?php endif; ?></p>
                                                <?php if ($deletePreview !== null): ?><p class="mt-0.5 text-[10px] text-slate-400">Exclusão definitiva: <?= htmlspecialchars($deleteSummary) ?></p><?php endif; ?>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <?php if ($canRestoreDiscarded): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="structure_type" value="<?= $discardedType ?>">
                                                        <input type="hidden" name="structure_id" value="<?= (int)$discardedItem['id'] ?>">
                                                        <button type="submit" name="structure_action" value="restore" class="rounded-md border border-emerald-500/30 px-3 py-1.5 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400">Restaurar</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($deletePreview !== null): ?>
                                                    <form method="post" data-delete-name="<?= htmlspecialchars((string)$discardedItem['nome'], ENT_QUOTES, 'UTF-8') ?>" data-delete-summary="<?= htmlspecialchars($deleteSummary, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="structure_type" value="<?= $discardedType ?>">
                                                        <input type="hidden" name="structure_id" value="<?= (int)$discardedItem['id'] ?>">
                                                        <input type="hidden" name="confirmation_name" value="">
                                                        <button type="submit" name="structure_delete_action" value="permanent_delete" class="rounded-md border border-red-500/30 px-3 py-1.5 text-[11px] font-semibold text-red-700 hover:bg-red-500/10 dark:text-red-400">Excluir permanentemente</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <?php if ($treeDiscardedCategories === [] && $treeDiscardedSubcategories === [] && $treeDiscardedSubjects === []): ?>
                                    <p class="py-5 text-center text-xs text-slate-500">Nenhum item descartado no seu escopo.</p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>

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
                                <?php if (empty($treeVisibleCategories)): ?>
                                    <div class="rounded-md border border-dashed border-slate-200 p-8 text-center text-xs text-slate-500 dark:border-[#454956]">
                                        Nenhum recurso editável foi encontrado no seu escopo.
                                    </div>
                                <?php endif; ?>

                                <?php foreach ($treeVisibleCategories as $treeCategory): ?>
                                    <?php
                                        $treeCategoryId = (int)$treeCategory['id'];
                                        $treeCategoryEditable = $permService->canEdit($currentAdminUserId, 'category', $treeCategoryId);
                                        $treeCategoryAdmin = $treeCategoryEditable && $permService->canAdmin($currentAdminUserId, 'category', $treeCategoryId);
                                        $treeSubcategories = array_values(array_filter(
                                            $treeVisibleSubcategories,
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
                                                            $treeVisibleSubjects,
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
                        <?php endif; ?>
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

                        <?php if ($resType === 'subject'): ?>
                            <?php require __DIR__ . '/partials/subject-workspace.php'; ?>
                        <?php else: ?>
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
                            $groupChildCounts = $groupMembershipService->getChildCounts();
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
                                                <th class="py-2.5 px-4 text-center w-28">Membros diretos</th>
                                                <?php if ($groupMembershipService->isAvailable()): ?><th class="py-2.5 px-4 text-center w-28">Subgrupos</th><?php endif; ?>
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
                                                    <?php if ($groupMembershipService->isAvailable()): ?>
                                                    <td class="py-2.5 px-4 text-center"><a href="index.php?tab=editar_grupo&amp;id=<?= (int)$grp['id'] ?>&amp;group_tab=groups" class="font-semibold hover:underline"><?= (int)($groupChildCounts[$grp['id']] ?? 0) ?></a></td>
                                                    <?php endif; ?>
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
                                                            <form method="POST" action="index.php?tab=grupos" data-confirm="Excluir a equipe <?= htmlspecialchars($grp['nome'], ENT_QUOTES, 'UTF-8') ?>?" data-confirm-title="Excluir equipe" data-confirm-label="Excluir equipe" data-confirm-tone="danger" class="inline">
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
                        if (!in_array($groupTab, ['info', 'users', 'groups', 'access', 'system'], true)) {
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
                        <div class="flex items-center gap-2 overflow-x-auto whitespace-nowrap border-b border-slate-200 dark:border-[#454956]">
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=info" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'info' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Informações
                            </a>
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=users" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'users' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Membros
                            </a>
                            <?php if ($groupMembershipService->isAvailable()): ?>
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=groups" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'groups' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">Subgrupos</a>
                            <?php endif; ?>
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=access" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'access' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Conteúdo
                            </a>
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=system" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'system' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Sistema
                            </a>
                        </div>

                        <?php if ($groupTab === 'groups'): ?>
                            <?php require __DIR__ . '/partials/group-nesting.php'; ?>
                        <?php endif; ?>

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
                            <?php $batchImportGroupId = $groupId; require __DIR__ . '/partials/batch-user-import.php'; unset($batchImportGroupId); ?>
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
                                                    <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=users" data-confirm="Remover <?= htmlspecialchars($uMember['name'], ENT_QUOTES, 'UTF-8') ?> desta equipe? O usuário não será excluído do sistema." data-confirm-title="Remover da equipe" data-confirm-label="Remover da equipe" data-confirm-tone="danger">
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

                                        <script src="../assets/admin/groups.js?v=<?= filemtime(__DIR__ . '/../assets/admin/groups.js') ?>"></script>
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

                        <!-- ABA SISTEMA: capacidades administrativas globais, delegadas somente pelo Super Admin -->
                        <?php if ($groupTab === 'system'): ?>
                            <?php
                                $systemCapabilityCatalog = SystemAccessService::catalog();
                                $groupSystemCapabilities = $systemAccessService->getGroupCapabilities($groupId);
                                $systemCapabilityAuditStmt = $pdo->prepare('
                                    SELECT sca.capability, sca.action, sca.ip_address, sca.created_at,
                                           COALESCE(u.name, \'Usuário removido\') AS actor_name
                                    FROM system_capability_audit sca
                                    LEFT JOIN users u ON u.id = sca.actor_id
                                    WHERE sca.group_id = :group_id
                                    ORDER BY sca.created_at DESC, sca.id DESC
                                    LIMIT 12
                                ');
                                $systemCapabilityAuditStmt->execute([':group_id' => $groupId]);
                                $groupSystemCapabilityAudit = $systemCapabilityAuditStmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                                <div class="border-b border-slate-100 p-5 dark:border-[#454956]">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Administração delegada</p>
                                            <h3 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Módulos globais do sistema</h3>
                                            <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500 dark:text-slate-400">Esses poderes não alteram o acesso da equipe a categorias ou documentos. Somente o Super Admin pode concedê-los ou removê-los.</p>
                                        </div>
                                        <span class="w-fit rounded-full bg-amber-500/10 px-2.5 py-1 text-[10px] font-bold text-amber-700 dark:text-amber-300">Acesso sensível</span>
                                    </div>
                                    <?php if (!$grpData['active']): ?>
                                        <p class="mt-3 rounded-md border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-[11px] font-medium text-amber-700 dark:text-amber-300">A equipe está inativa. As escolhas permanecem salvas, mas nenhum membro recebe esses acessos até a reativação.</p>
                                    <?php endif; ?>
                                </div>

                                <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=system" class="p-5">
                                    <input type="hidden" name="group_action" value="sync_system_capabilities">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="group_id" value="<?= $groupId ?>">

                                    <div class="grid gap-3 md:grid-cols-2">
                                        <?php foreach ($systemCapabilityCatalog as $capabilityKey => $capabilityDefinition): ?>
                                            <?php
                                                $capabilityInputId = 'system-capability-' . substr(hash('sha256', $capabilityKey), 0, 12);
                                                $isCriticalCapability = ($capabilityDefinition['sensitivity'] ?? '') === 'critical';
                                            ?>
                                            <label for="<?= htmlspecialchars($capabilityInputId, ENT_QUOTES, 'UTF-8') ?>" class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-4 transition hover:border-slate-400 hover:bg-slate-50/70 dark:border-[#565b68] dark:hover:bg-[#2c2e33]">
                                                <input id="<?= htmlspecialchars($capabilityInputId, ENT_QUOTES, 'UTF-8') ?>" type="checkbox" name="system_capabilities[]" value="<?= htmlspecialchars($capabilityKey, ENT_QUOTES, 'UTF-8') ?>" class="mt-0.5 h-4 w-4 rounded" <?= in_array($capabilityKey, $groupSystemCapabilities, true) ? 'checked' : '' ?>>
                                                <span class="min-w-0">
                                                    <span class="flex flex-wrap items-center gap-2">
                                                        <strong class="text-xs text-slate-900 dark:text-slate-100"><?= htmlspecialchars($capabilityDefinition['label']) ?></strong>
                                                        <?php if ($isCriticalCapability): ?><small class="rounded bg-red-500/10 px-1.5 py-0.5 text-[9px] font-bold uppercase text-red-600 dark:text-red-300">Crítico</small><?php endif; ?>
                                                    </span>
                                                    <small class="mt-1 block text-[11px] leading-4 text-slate-500 dark:text-slate-400"><?= htmlspecialchars($capabilityDefinition['description']) ?></small>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="mt-5 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-[#454956]">
                                        <p class="text-[10px] leading-4 text-slate-400">A desativação da equipe ou do usuário revoga o acesso efetivo imediatamente. Toda alteração fica na auditoria.</p>
                                        <button type="submit" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-xs font-bold text-white transition hover:opacity-90 dark:bg-white dark:text-slate-900">Salvar acessos do sistema</button>
                                    </div>
                                </form>

                                <div class="border-t border-slate-100 p-5 dark:border-[#454956]">
                                    <h4 class="text-xs font-bold text-slate-900 dark:text-slate-100">Histórico recente</h4>
                                    <?php if (empty($groupSystemCapabilityAudit)): ?>
                                        <p class="py-4 text-xs text-slate-400">Ainda não houve alteração nos módulos administrativos desta equipe.</p>
                                    <?php else: ?>
                                        <div class="mt-3 divide-y divide-slate-100 dark:divide-[#454956]">
                                            <?php foreach ($groupSystemCapabilityAudit as $capabilityAuditEvent): ?>
                                                <?php $auditDefinition = $systemCapabilityCatalog[$capabilityAuditEvent['capability']] ?? ['label' => $capabilityAuditEvent['capability']]; ?>
                                                <div class="flex flex-col gap-1 py-2.5 text-xs sm:flex-row sm:items-center sm:justify-between">
                                                    <div class="min-w-0">
                                                        <span class="font-semibold <?= $capabilityAuditEvent['action'] === 'GRANTED' ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-600 dark:text-red-300' ?>"><?= $capabilityAuditEvent['action'] === 'GRANTED' ? 'Concedido' : 'Removido' ?></span>
                                                        <span class="text-slate-600 dark:text-slate-300"> · <?= htmlspecialchars($auditDefinition['label']) ?></span>
                                                        <small class="ml-1 text-[10px] text-slate-400">por <?= htmlspecialchars($capabilityAuditEvent['actor_name']) ?></small>
                                                    </div>
                                                    <time class="shrink-0 text-[10px] text-slate-400"><?= date('d/m/Y H:i', strtotime($capabilityAuditEvent['created_at'])) ?></time>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                    </div>
                    <?php } ?>
                <?php endif; ?>

                <!-- TAB: LISTAGEM DE USUÁRIOS DO SISTEMA -->
                <?php if ($activeTab === 'usuarios'): ?>
                    <?php
                        $currentAdminUserId = (int)($loggedUser['id'] ?? 0);
                        if ($canManageDirectory) {
                            $usersListStmt = $pdo->query($groupMembershipService->allMembershipsCte() . '
                                SELECT u.id, u.name, u.username, u.email, u.role, u.active, u.created_at,
                                       COUNT(DISTINCT ug.group_id) AS total_grupos
                                FROM users u
                                LEFT JOIN all_user_groups ug ON ug.user_id = u.id
                                GROUP BY u.id, u.name, u.username, u.email, u.role, u.active, u.created_at
                                ORDER BY u.name ASC
                            ');
                            $usersList = $usersListStmt->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            $usersList = $permService->getUsersForAdministrativeScope($currentAdminUserId);
                        }
                    ?>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-[#454956]">
                            <div>
                                <nav class="flex items-center gap-1.5 text-xs text-slate-400 font-medium mb-0.5">
                                    <span>Gestão de Acesso</span>
                                    <span>/</span>
                                    <span class="font-bold text-slate-900 dark:text-slate-100">Usuários</span>
                                </nav>
                                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100"><?= $canManageDirectory ? 'Usuários do Sistema' : 'Usuários da sua área' ?></h1>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    <?= $canManageDirectory
                                        ? 'Diretório corporativo, vínculos e diagnóstico de acesso.'
                                        : 'Somente pessoas com acesso direto ou via equipe no seu ramo autorizado são exibidas.' ?>
                                </p>
                            </div>
                        </div>

                        <?php if ($canManageDirectory): ?>
                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Integração corporativa</p>
                                        <h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Importar usuário do Active Directory</h2>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Informe somente o usuário corporativo. <?= htmlspecialchars($appName) ?> confirma que ele existe no AD e traz nome, e-mail e identificação automaticamente.</p>
                                    </div>
                                    <span class="w-fit rounded-full bg-sky-500/10 px-2.5 py-1 text-[10px] font-bold text-sky-700 dark:text-sky-300">Somente contas AD</span>
                                </div>

                                <form method="POST" action="index.php?tab=usuarios" class="mt-4 border-t border-slate-100 pt-4 dark:border-[#454956]">
                                    <input type="hidden" name="create_user" value="1">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    
                                    <div class="flex flex-col sm:flex-row sm:items-start gap-3">
                                        <div class="w-full sm:w-44">
                                            <label class="block">
                                                <span class="mb-1.5 block text-xs font-semibold text-slate-700 dark:text-slate-300">Domínio *</span>
                                                <select name="ad_domain" class="input-minimal h-9 w-full px-3 text-xs font-semibold">
                                                    <?php foreach ($availableAdDomains as $domain): ?>
                                                        <option value="<?= htmlspecialchars($domain) ?>"><?= htmlspecialchars($domain === 'SAUDE' ? 'SAÚDE' : $domain) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                        </div>

                                        <div class="flex-1 w-full">
                                            <label class="block">
                                                <span class="mb-1.5 block text-xs font-semibold text-slate-700 dark:text-slate-300">Usuário AD *</span>
                                                <input type="text" name="ad_username" required maxlength="100" class="input-minimal h-9 w-full px-3 text-xs" placeholder="Ex.: ana.silva" autocomplete="off">
                                            </label>
                                        </div>

                                        <div class="w-full sm:w-auto pt-0 sm:pt-[22px]">
                                            <button type="submit" class="inline-flex h-9 w-full sm:w-auto items-center justify-center gap-1.5 rounded-md bg-slate-900 px-4 text-xs font-bold text-white transition hover:opacity-90 dark:bg-white dark:text-slate-900">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                                <span>Importar do AD</span>
                                            </button>
                                        </div>
                                    </div>
                                    <p class="mt-1.5 text-[10px] text-slate-400">A conta precisa já existir e estar ativa no Active Directory.</p>
                                </form>
                            </section>
                            <?php require __DIR__ . '/partials/batch-user-import.php'; ?>
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
                                                    <div class="flex items-center justify-end gap-1.5">
                                                        <button type="button" data-user-360-id="<?= $uRow['id'] ?>" class="inline-flex items-center gap-1 px-2.5 py-1 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#353842] text-slate-700 dark:text-slate-200 text-[11px] font-semibold hover:bg-slate-50 dark:hover:bg-slate-800 transition shadow-2xs">
                                                            <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                            <span>Ficha 360°</span>
                                                        </button>
                                                        <a href="index.php?tab=editar_usuario&id=<?= $uRow['id'] ?>&user_tab=access" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-[11px] font-semibold hover:opacity-90 transition shadow-xs">
                                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                                            <span>Acessos</span>
                                                        </a>
                                                    </div>
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
                        $canInspectTargetUser = $canManageDirectory
                            || $permService->canViewUserInAdministrativeScope($currentAdminUserId, $targetUserId);
                        $diagnosis = $canInspectTargetUser
                            ? $permService->getUserEffectiveAccessDiagnosis($targetUserId, $accessFilter)
                            : ['user' => null, 'is_global_admin' => false, 'active_groups' => [], 'resources' => []];
                        if ($canInspectTargetUser && !$canManageDirectory) {
                            $diagnosis = $permService->filterDiagnosisToAdministrativeScope($currentAdminUserId, $diagnosis);
                        }
                        $uData = $diagnosis['user'];
                        $userTeams = [];
                        $directUserPermissions = [];
                        if ($uData) {
                            if ($canManageDirectory) {
                                $userTeams = $groupMembershipService->getUserGroups($targetUserId, true);
                            } else {
                                $userTeams = $permService->getUserTeamsForAdministrativeScope($currentAdminUserId, $targetUserId);
                            }
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
                                                    <?php if ($isGlobalAdminCurrent): ?><a href="index.php?tab=editar_grupo&id=<?= (int)$userTeam['id'] ?>&group_tab=users" class="font-semibold text-slate-900 dark:text-slate-100 hover:underline"><?= htmlspecialchars($userTeam['name']) ?></a><?php else: ?><span class="font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($userTeam['name']) ?></span><?php endif; ?>
                                                    <p class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars($userTeam['description'] ?: 'Sem descrição') ?></p>
                                                    <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-1"><?= !empty($userTeam['is_direct']) ? 'Membro direto' : 'Via subgrupos: ' . htmlspecialchars($userTeam['membership_label'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
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
                                                        <form method="POST" action="index.php?tab=editar_usuario&id=<?= $targetUserId ?>&user_tab=access" data-confirm="Remover este acesso individual?" data-confirm-title="Remover acesso" data-confirm-label="Remover acesso" data-confirm-tone="danger">
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

        <?php require __DIR__ . '/partials/client-config.php'; ?>
        <script src="../assets/admin/navigation.js?v=<?= filemtime(__DIR__ . '/../assets/admin/navigation.js') ?>"></script>
        <script src="../assets/admin/document-list.js?v=<?= filemtime(__DIR__ . '/../assets/admin/document-list.js') ?>"></script>
        <script src="../assets/admin/structure.js?v=<?= filemtime(__DIR__ . '/../assets/admin/structure.js') ?>"></script>
        <script src="../assets/admin/document-editor.js?v=<?= filemtime(__DIR__ . '/../assets/admin/document-editor.js') ?>"></script>
    <?php endif; ?>

    <?php require __DIR__ . '/partials/user-detail-modal.php'; ?>
    <script src="../assets/permissions.js"></script>
    <script id="upload-validation" src="../assets/upload-validation.js?v=<?= filemtime(__DIR__ . '/../assets/upload-validation.js') ?>" data-limits="<?= htmlspecialchars(json_encode(UploadRequestService::limits()), ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php if ($activeTab === 'usuarios' || ($activeTab === 'editar_grupo' && ($groupTab ?? '') === 'users')): ?>
        <script src="../assets/batch-user-import.js"></script>
    <?php endif; ?>
</body>
</html>
