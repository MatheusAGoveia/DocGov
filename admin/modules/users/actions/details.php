<?php
/**
 * users/actions/details.php
 * Entradas: $pdo, $loggedUser, $currentAdminUserId, $canManageDirectory, $permService, $groupMembershipService.
 * Estado compartilhado: resposta HTTP/efeitos da acao.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// AJAX: FICHA 360° DETALHADA DO USUÁRIO (AD + DOCGOV)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['get_user_details'])) {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'Sessão expirada ou token inválido.']);
            exit;
        }

        $currentAdminUserId = (int)($loggedUser['id'] ?? 0);
        $targetUserId = (int)($_POST['user_id'] ?? 0);

        if ($targetUserId <= 0 || (!$canManageDirectory && !$permService->canViewUserInAdministrativeScope($currentAdminUserId, $targetUserId))) {
            echo json_encode(['success' => false, 'error' => 'Acesso negado ou usuário não localizado no seu escopo.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, name, username, email, role, active, auth_source, ad_domain, ad_object_guid, department, job_title, phone, avatar, created_at, last_login_at FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            echo json_encode(['success' => false, 'error' => 'Usuário não encontrado.']);
            exit;
        }

        // Histórico recente de auditoria de logins (ad_auth_logs)
        $stmtLogs = $pdo->prepare("
            SELECT created_at, domain_key, username, server_name, server_uri, status, status_message, latency_ms, user_ip
            FROM ad_auth_logs
            WHERE LOWER(username) = LOWER(?) AND UPPER(domain_key) = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmtLogs->execute([$userData['username'], strtoupper((string)($userData['ad_domain'] ?? ''))]);
        $authLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

        // Equipes que o usuário pertence
        $userGroups = $canManageDirectory
            ? $groupMembershipService->getUserGroups($targetUserId)
            : $permService->getUserTeamsForAdministrativeScope($currentAdminUserId, $targetUserId);
        foreach ($userGroups as &$userGroup) $userGroup['joined_at'] = $userGroup['member_since'];
        unset($userGroup);

        // Estatísticas de documentos
        $stmtDocsCount = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE created_by = ?");
        $stmtDocsCount->execute([$targetUserId]);
        $docsCreatedCount = (int)$stmtDocsCount->fetchColumn();

        $stmtDocsPub = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE created_by = ? AND status = 'published'");
        $stmtDocsPub->execute([$targetUserId]);
        $docsPublishedCount = (int)$stmtDocsPub->fetchColumn();

        // Diagnóstico de acessos efetivos
        $diagnosis = $permService->getUserEffectiveAccessDiagnosis($targetUserId);

        echo json_encode([
            'success' => true,
            'user' => $userData,
            'auth_logs' => $authLogs,
            'groups' => $userGroups,
            'activity' => [
                'docs_created' => $docsCreatedCount,
                'docs_published' => $docsPublishedCount,
            ],
            'diagnosis_summary' => [
                'is_global_admin' => (bool)($diagnosis['is_global_admin'] ?? false),
                'categories_count' => count($diagnosis['resources']['categories'] ?? []),
                'subcategories_count' => count($diagnosis['resources']['subcategories'] ?? []),
                'subjects_count' => count($diagnosis['resources']['subjects'] ?? []),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $ex) {
        echo json_encode(['success' => false, 'error' => 'Erro ao carregar detalhes: ' . $ex->getMessage()]);
        exit;
    }
}
