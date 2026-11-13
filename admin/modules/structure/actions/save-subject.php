<?php
/**
 * structure/actions/save-subject.php
 * Entradas: $pdo, $loggedUser, $permService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
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
    $redirectQuery = trim((string)($_POST['redirect_query'] ?? ''));
    $requestedSubjectVisibility = $_POST['visibility'] ?? null;

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
    } elseif ($requestedSubjectVisibility !== null
        && (!is_string($requestedSubjectVisibility) || !in_array($requestedSubjectVisibility, ['private', 'public'], true))) {
        http_response_code(422);
        $errorMessage = 'Visibilidade inválida. Escolha Privado ou Público.';
    } elseif (!empty($nome) && $subId > 0) {
        try {
            $pdo->beginTransaction();
            $slug = slugify($nome);
            $subjectVisibilityChanged = false;
            if ($assId) {
                $existingStmt = $pdo->prepare('SELECT visibility FROM subjects WHERE id = :id FOR UPDATE');
                $existingStmt->execute([':id' => $assId]);
                $existingVisibility = $existingStmt->fetchColumn();
                if ($existingVisibility === false) throw new RuntimeException('Assunto não encontrado.');
                $subjectVisibility = $requestedSubjectVisibility ?? $existingVisibility;
                $subjectVisibilityChanged = $subjectVisibility !== $existingVisibility;
                if ($subjectVisibilityChanged && !$permService->canAdminSubject($userId, $assId)) {
                    http_response_code(403);
                    throw new RuntimeException('Somente administradores do assunto podem alterar sua visibilidade.');
                }
                $stmt = $pdo->prepare("UPDATE subjects SET subcategory_id = :sub_id, name = :name, slug = :slug, description = :desc, active = :active, visibility = :visibility WHERE id = :id");
                $stmt->execute([':sub_id' => $subId, ':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false', ':visibility' => $subjectVisibility, ':id' => $assId]);
            } else {
                $subjectVisibility = $requestedSubjectVisibility ?? 'private';
                if ($subjectVisibility === 'public' && !$permService->canAdminSubcategory($userId, $subId)) {
                    http_response_code(403);
                    throw new RuntimeException('Somente administradores da subcategoria podem criar assuntos públicos.');
                }
                $subjectVisibilityChanged = $subjectVisibility === 'public';
                $stmt = $pdo->prepare("INSERT INTO subjects (subcategory_id, name, slug, description, active, visibility) VALUES (:sub_id, :name, :slug, :desc, :active, :visibility) RETURNING id");
                $stmt->execute([':sub_id' => $subId, ':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false', ':visibility' => $subjectVisibility]);
                $assId = (int)$stmt->fetchColumn();
            }
            $pdo->commit();
            $usageAuditService->logAdminAction($userId, $isNewSubject ? 'subject_created' : 'subject_updated', 'SUBJECT', (int)$assId);
            if ($subjectVisibilityChanged) {
                $usageAuditService->logAdminAction($userId, 'subject_visibility_' . $subjectVisibility, 'SUBJECT', (int)$assId);
            }
            if ($redirectQuery !== '' && preg_match('/^tab=editar_estrutura(?:&[a-z_]+=[a-z0-9_-]+)*$/i', $redirectQuery) === 1) {
                header('Location: index.php?' . $redirectQuery . '&msg=subject_saved');
                exit;
            }
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
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorMessage = $error instanceof PDOException
                ? ($error->getCode() === '23505' ? 'Já existe um assunto com este nome nesta subcategoria.' : 'Erro ao salvar assunto no banco de dados.')
                : $error->getMessage();
        }
    } else {
        $errorMessage = 'Informe a subcategoria pai e o nome do assunto.';
    }
}

