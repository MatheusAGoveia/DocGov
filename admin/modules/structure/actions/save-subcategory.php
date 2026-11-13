<?php
/**
 * structure/actions/save-subcategory.php
 * Entradas: $pdo, $loggedUser, $permService, $categoryImageService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
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
