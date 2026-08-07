<?php
// admin/index.php - Painel Administrativo de Gestão de Documentos (Ícones 100% SVG)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/permissions.php';

// Processamento de login no painel via PostgreSQL users
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $loginInput = trim($_POST['username'] ?? $_POST['login'] ?? $_POST['email'] ?? '');
    $passInput = trim($_POST['password'] ?? '');

    if (!empty($loginInput) && !empty($passInput)) {
        $stmtAdmin = $pdo->prepare("SELECT * FROM users WHERE (email = :input OR username = :input) AND active = TRUE LIMIT 1");
        $stmtAdmin->execute([':input' => $loginInput]);
        $userAdmin = $stmtAdmin->fetch();

        if ($userAdmin && in_array($userAdmin['role'], ['admin', 'editor']) && $userAdmin['password_hash'] && password_verify($passInput, $userAdmin['password_hash'])) {
            $_SESSION['admin_logged'] = true;
            $_SESSION['user'] = [
                'id' => (int)$userAdmin['id'],
                'nome' => $userAdmin['name'],
                'login' => $userAdmin['username'],
                'email' => $userAdmin['email'],
                'role' => $userAdmin['role'],
                'active' => true,
                'avatar' => $userAdmin['avatar'] ?? null,
                'inicial' => mb_strtoupper(mb_substr($userAdmin['name'], 0, 1))
            ];
            header('Location: index.php');
            exit;
        } else {
            $login_error = "Credenciais inválidas ou usuário sem permissão de acesso ao painel!";
        }
    } else {
        $login_error = "Informe o usuário/e-mail e a senha de administração!";
    }
}

// Verifica sessão do usuário logado
$loggedUser = $_SESSION['user'] ?? null;
$accessDenied = false;
$accessErrorReason = '';

if (!$loggedUser) {
    $accessDenied = true;
    $accessErrorReason = "Informe suas credenciais para acessar a área administrativa.";
} elseif (($loggedUser['role'] ?? '') === 'leitor') {
    $accessDenied = true;
    $accessErrorReason = "Usuários com perfil Leitor não possuem acesso ao módulo de Gestão Administrativa.";
} else {
    $_SESSION['admin_logged'] = true;
}

$isLogged = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true && !$accessDenied;

require_once __DIR__ . '/../services/PermissionService.php';
$permService = new PermissionService($pdo);

$activeTab = trim($_GET['tab'] ?? 'visao_geral');
// Alias: novo_conteudo é o novo nome do tab de criação
if ($activeTab === 'novo_conteudo') $activeTab = 'novo_documento';
$message = '';
$errorMessage = '';
$editDoc = null;
$docDetails = null;
$editCat = null;
$editSub = null;
$editAss = null;

// =============================================================================
// PROCESSAMENTO COMPLETO DE AÇÕES ADMINISTRATIVAS (POSTGRESQL)
// =============================================================================
if ($isLogged) {

    // 0.0 PROCESSAMENTO DE GESTÃO DE PERMISSÕES POR RECURSO (CATEGORIA / SUBCATEGORIA / ASSUNTO)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['resource_permission_action'])) {
        if (($loggedUser['role'] ?? '') !== 'admin') {
            $errorMessage = "Apenas administradores podem alterar permissões dos recursos.";
        } else {
            require_once __DIR__ . '/../services/PermissionService.php';
            $permService = new PermissionService($pdo);
            $resPermAction = $_POST['resource_permission_action'];
            $resType = trim($_POST['resource_type'] ?? '');
            $resId = (int)($_POST['resource_id'] ?? 0);

            if (!in_array($resType, ['category', 'subcategory', 'subject']) || $resId <= 0) {
                $errorMessage = "Recurso inválido para configuração de permissão.";
            } else {
                $resTypeInput = ($resType === 'category') ? 'categoria' : (($resType === 'subcategory') ? 'subcategoria' : 'assunto');

                if ($resPermAction === 'add_permission') {
                    $principalType = trim($_POST['principal_type'] ?? 'group');
                    $principalId = (int)($_POST['principal_id'] ?? 0);
                    $permLevel = strtolower(trim($_POST['permission_level'] ?? 'view'));

                    $userId = ($principalType === 'user') ? $principalId : null;
                    $groupId = ($principalType === 'group') ? $principalId : null;

                    if (!in_array($principalType, ['user', 'group']) || $principalId <= 0) {
                        $errorMessage = "Selecione um Usuário ou Grupo válido para conceder acesso.";
                    } elseif (!in_array($permLevel, ['view', 'edit', 'admin'])) {
                        $errorMessage = "Nível de permissão inválido.";
                    } else {
                        // Validação estrita da existência do recurso
                        $resTable = ($resType === 'category') ? 'categories' : (($resType === 'subcategory') ? 'subcategories' : 'subjects');
                        $checkR = $pdo->prepare("SELECT id FROM {$resTable} WHERE id = ?");
                        $checkR->execute([$resId]);
                        if (!$checkR->fetchColumn()) {
                            $errorMessage = "O recurso informado não foi encontrado no banco de dados.";
                        } else {
                            // Validação estrita da existência do principal
                            if ($principalType === 'user') {
                                $checkP = $pdo->prepare("SELECT id FROM users WHERE id = ? AND active = TRUE");
                                $checkP->execute([$principalId]);
                                if (!$checkP->fetchColumn()) {
                                    $errorMessage = "O Usuário selecionado não existe ou está inativo.";
                                }
                            } else {
                                $checkP = $pdo->prepare("SELECT id FROM groups WHERE id = ? AND active = TRUE");
                                $checkP->execute([$principalId]);
                                if (!$checkP->fetchColumn()) {
                                    $errorMessage = "O Grupo selecionado não existe ou está inativo.";
                                }
                            }
                        }

                        if (empty($errorMessage)) {
                            try {
                                $permService->saveResourcePermission($resType, $resId, $userId, $groupId, $permLevel, (int)($loggedUser['id'] ?? 0));
                                header("Location: index.php?tab=editar_estrutura&type=$resTypeInput&id=$resId&res_tab=permissions&msg=perm_saved");
                                exit;
                            } catch (Exception $e) {
                                $errorMessage = "Erro ao salvar permissão: " . $e->getMessage();
                            }
                        }
                    }
                }

                if ($resPermAction === 'update_level') {
                    $permId = (int)($_POST['permission_id'] ?? 0);
                    $newLevel = trim($_POST['permission_level'] ?? 'view');

                    if ($permId > 0 && in_array($newLevel, ['view', 'edit', 'admin'])) {
                        $stmtUpdLvl = $pdo->prepare("UPDATE permissions SET permission_level = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmtUpdLvl->execute([$newLevel, $permId]);
                        header("Location: index.php?tab=editar_estrutura&type=$resTypeInput&id=$resId&res_tab=permissions&msg=perm_updated");
                        exit;
                    }
                }

                if ($resPermAction === 'delete_permission') {
                    $permId = (int)($_POST['permission_id'] ?? 0);
                    if ($permId > 0) {
                        $permService->deletePermission($permId);
                        header("Location: index.php?tab=editar_estrutura&type=$resTypeInput&id=$resId&res_tab=permissions&msg=perm_deleted");
                        exit;
                    }
                }
            }
        }
    }

    // 0.0.1 PROCESSAMENTO DE PERMISSÕES DA VISÃO DO GRUPO (GRUPO -> PERMISSÕES)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['group_permission_action'])) {
        if (($loggedUser['role'] ?? '') !== 'admin') {
            $errorMessage = "Apenas administradores podem gerenciar permissões de grupos.";
        } else {
            require_once __DIR__ . '/../services/PermissionService.php';
            $permService = new PermissionService($pdo);

            $grpPermAction = $_POST['group_permission_action'];
            $groupId = (int)($_POST['group_id'] ?? 0);

            if ($groupId <= 0) {
                $errorMessage = "Grupo de acesso inválido.";
            } else {
                if ($grpPermAction === 'add_group_access') {
                    $resType = trim($_POST['resource_type'] ?? '');
                    $resId = (int)($_POST['resource_id'] ?? 0);
                    $permLevel = strtolower(trim($_POST['permission_level'] ?? 'view'));

                    if (!in_array($resType, ['category', 'subcategory', 'subject']) || $resId <= 0) {
                        $errorMessage = "Selecione uma Categoria, Subcategoria ou Assunto válido.";
                    } elseif (!in_array($permLevel, ['view', 'edit', 'admin'])) {
                        $errorMessage = "Nível de permissão inválido.";
                    } else {
                        try {
                            $permService->saveResourcePermission($resType, $resId, null, $groupId, $permLevel, (int)($loggedUser['id'] ?? 0));
                            header("Location: index.php?tab=editar_grupo&id=$groupId&group_tab=permissions&msg=access_saved");
                            exit;
                        } catch (Exception $e) {
                            $errorMessage = "Erro ao conceder acesso ao grupo: " . $e->getMessage();
                        }
                    }
                }

                if ($grpPermAction === 'update_group_level') {
                    $permId = (int)($_POST['permission_id'] ?? 0);
                    $newLevel = strtolower(trim($_POST['permission_level'] ?? 'view'));

                    if ($permId > 0 && in_array($newLevel, ['view', 'edit', 'admin'])) {
                        $stmtUpd = $pdo->prepare("UPDATE permissions SET permission_level = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND group_id = ?");
                        $stmtUpd->execute([$newLevel, $permId, $groupId]);
                        header("Location: index.php?tab=editar_grupo&id=$groupId&group_tab=permissions&msg=level_updated");
                        exit;
                    }
                }

                if ($grpPermAction === 'delete_group_permission') {
                    $permId = (int)($_POST['permission_id'] ?? 0);
                    if ($permId > 0) {
                        $stmtDel = $pdo->prepare("DELETE FROM permissions WHERE id = ? AND group_id = ?");
                        $stmtDel->execute([$permId, $groupId]);
                        header("Location: index.php?tab=editar_grupo&id=$groupId&group_tab=permissions&msg=access_deleted");
                        exit;
                    }
                }
            }
        }
    }

    // 0. PROCESSAMENTO DE AÇÕES DE GRUPOS DE ACESSO (APENAS ADMIN)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['group_action'])) {
        if (($loggedUser['role'] ?? '') !== 'admin') {
            $errorMessage = "Apenas administradores podem gerenciar grupos de acesso.";
        } else {
            $grpAction = $_POST['group_action'];

            // A. CRIAR NOVO GRUPO
            if ($grpAction === 'create_group') {
                $gName = trim($_POST['name'] ?? '');
                $gDesc = trim($_POST['description'] ?? '');
                $gActive = isset($_POST['active']) && in_array($_POST['active'], ['1', 'true', 'on']);

                if (!empty($gName)) {
                    try {
                        $stmtInsG = $pdo->prepare("INSERT INTO groups (name, description, active) VALUES (?, ?, ?)");
                        $stmtInsG->execute([$gName, $gDesc, $gActive ? 'true' : 'false']);
                        $message = "Grupo '" . htmlspecialchars($gName) . "' criado com sucesso!";
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23505') {
                            $errorMessage = "Já existe um grupo de acesso cadastrado com o nome '$gName'.";
                        } else {
                            $errorMessage = "Erro ao criar grupo: " . $e->getMessage();
                        }
                    }
                } else {
                    $errorMessage = "Informe o nome do grupo!";
                }
            }

            // B. EDITAR GRUPO
            if ($grpAction === 'edit_group') {
                $gId = (int)($_POST['group_id'] ?? 0);
                $gName = trim($_POST['name'] ?? '');
                $gDesc = trim($_POST['description'] ?? '');
                $gActive = isset($_POST['active']) && in_array($_POST['active'], ['1', 'true', 'on']);

                if ($gId > 0 && !empty($gName)) {
                    try {
                        $stmtUpdG = $pdo->prepare("UPDATE groups SET name = ?, description = ?, active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmtUpdG->execute([$gName, $gDesc, $gActive ? 'true' : 'false', $gId]);
                        $message = "Informações do grupo '" . htmlspecialchars($gName) . "' atualizadas com sucesso!";
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23505') {
                            $errorMessage = "Já existe outro grupo cadastrado com o nome '$gName'.";
                        } else {
                            $errorMessage = "Erro ao atualizar grupo: " . $e->getMessage();
                        }
                    }
                } else {
                    $errorMessage = "Preencha todos os campos obrigatórios!";
                }
            }

            // C. ALTERNAR STATUS ATIVO / INATIVO (TOGGLE)
            if ($grpAction === 'toggle_status') {
                $gId = (int)($_POST['group_id'] ?? 0);
                if ($gId > 0) {
                    $stmtTgl = $pdo->prepare("UPDATE groups SET active = NOT active, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmtTgl->execute([$gId]);
                    $message = "Status do grupo alterado com sucesso!";
                }
            }

            // D. EXCLUIR GRUPO
            if ($grpAction === 'delete_group') {
                $gId = (int)($_POST['group_id'] ?? 0);
                if ($gId > 0) {
                    try {
                        $stmtDel = $pdo->prepare("DELETE FROM groups WHERE id = ?");
                        $stmtDel->execute([$gId]);
                        $message = "Grupo excluído com sucesso.";
                    } catch (PDOException $e) {
                        $errorMessage = "Erro ao excluir grupo: " . $e->getMessage();
                    }
                }
            }

            // E. ADICIONAR USUÁRIO AO GRUPO
            if ($grpAction === 'add_user') {
                $gId = (int)($_POST['group_id'] ?? 0);
                $uId = (int)($_POST['user_id'] ?? 0);

                if ($gId > 0 && $uId > 0) {
                    try {
                        $stmtAddU = $pdo->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)");
                        $stmtAddU->execute([$uId, $gId]);
                        $message = "Usuário adicionado ao grupo com sucesso!";
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23505') {
                            $errorMessage = "Este usuário já pertence a este grupo.";
                        } else {
                            $errorMessage = "Erro ao associar usuário: " . $e->getMessage();
                        }
                    }
                }
            }

            // F. REMOVER USUÁRIO DO GRUPO
            if ($grpAction === 'remove_user') {
                $gId = (int)($_POST['group_id'] ?? 0);
                $uId = (int)($_POST['user_id'] ?? 0);

                if ($gId > 0 && $uId > 0) {
                    $stmtRemU = $pdo->prepare("DELETE FROM user_groups WHERE user_id = ? AND group_id = ?");
                    $stmtRemU->execute([$uId, $gId]);
                    $message = "Usuário removido do grupo com sucesso. (O cadastro do usuário permanece intacto no sistema).";
                }
            }
        }
    }

    // 1. SALVAR / EDITAR DOCUMENTO E LAYOUT VISUAL
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_doc'])) {
        $titulo = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $catInput = trim($_POST['categoria'] ?? '');
        $subInput = trim($_POST['subcategoria'] ?? '');
        $assuntoInput = trim($_POST['assunto'] ?? '');
        $status = trim($_POST['status'] ?? 'published');
        $tipoConteudo = trim($_POST['tipo_conteudo'] ?? 'arquivo');
        $conteudoHtmlRaw = trim($_POST['conteudo_html'] ?? '');
        $linkExterno = trim($_POST['link_externo'] ?? '');
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;

        if (!in_array($status, ['draft', 'published', 'inactive'])) {
            $status = 'published';
        }
        if (!in_array($tipoConteudo, ['file', 'text', 'link'])) {
            $tipoConteudo = 'file';
        }

        $conteudoHtml = strip_tags($conteudoHtmlRaw, '<h3><h4><p><b><i><strong><em><ul><ol><li><a><br>');

        // Resolver subject_id a partir de assuntoInput (pode ser ID ou nome/slug)
        $subjectId = 0;
        if (!empty($assuntoInput)) {
            $stmtRes = $pdo->prepare("SELECT id FROM subjects WHERE id::text = :a OR slug = :a OR name = :a LIMIT 1");
            $stmtRes->execute([':a' => $assuntoInput]);
            $subjectId = (int)$stmtRes->fetchColumn();
        }

        // VALIDAÇÃO DE AUTORIZAÇÃO POR GRUPO NO BACKEND (AccessService)
        require_once __DIR__ . '/../services/AccessService.php';
        $accessService = new AccessService($pdo);
        $editorUserId = (int)($loggedUser['id'] ?? 0);

        if ($subjectId > 0 && !$accessService->canAccessSubject($editorUserId, $subjectId)) {
            $errorMessage = "Acesso Negado: Seu perfil não possui permissão no seu grupo para criar ou publicar conteúdos neste Assunto.";
        } elseif ($id && $id > 0) {
            $stmtCheckExistingDoc = $pdo->prepare("SELECT subject_id FROM documents WHERE id = ?");
            $stmtCheckExistingDoc->execute([$id]);
            $existSubjId = (int)$stmtCheckExistingDoc->fetchColumn();
            if ($existSubjId > 0 && !$accessService->canAccessSubject($editorUserId, $existSubjId)) {
                $errorMessage = "Acesso Negado: Seu perfil não possui permissão no seu grupo para editar este documento.";
            }
        }

        if (!empty($errorMessage)) {
            // Mantém $errorMessage e ignora o salvamento
        } elseif (empty($titulo) || $subjectId <= 0) {
            $errorMessage = "Por favor, preencha o Título e selecione um Assunto válido.";
        } elseif ($tipoConteudo === 'link' && (empty($linkExterno) || !filter_var($linkExterno, FILTER_VALIDATE_URL))) {
            $errorMessage = "Por favor, informe uma URL válida para o link externo.";
        } else {
            $storedFilename = null;
            $originalFilename = null;
            $tipoMime = null;
            $tamanhoBytes = 0;
            $fileExt = null;
            $filePath = null;

            if ($tipoConteudo === 'file' && isset($_FILES['arquivo_file']) && $_FILES['arquivo_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['arquivo_file'];
                $originalFilename = basename($file['name']);
                $tamanhoBytes = (int)$file['size'];
                $fileExt = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

                $extsPermitidas = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt', 'doc', 'docx'];
                if (!in_array($fileExt, $extsPermitidas)) {
                    $errorMessage = "Formato de arquivo não suportado. Utilize PDF, PNG, JPG, WEBP, GIF, TXT ou DOCX.";
                } elseif ($tamanhoBytes > 25 * 1024 * 1024) {
                    $errorMessage = "O arquivo excede o limite máximo permitido de 25MB.";
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
                if ($id) {
                    if ($storedFilename) {
                        $stmt = $pdo->prepare("
                            UPDATE documents SET 
                                subject_id = :sub_id, title = :title, slug = :slug, description = :desc, 
                                content_type = :type, status = :status, text_content = :text_content, external_url = :url,
                                stored_filename = :stored_name, original_filename = :orig_name, mime_type = :mime, 
                                file_extension = :ext, file_size = :size, file_path = :path
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':sub_id' => $subjectId, ':title' => $titulo, ':slug' => $slug, ':desc' => $descricao,
                            ':type' => $tipoConteudo, ':status' => $status, ':text_content' => $conteudoHtml, ':url' => $linkExterno,
                            ':stored_name' => $storedFilename, ':orig_name' => $originalFilename, ':mime' => $tipoMime,
                            ':ext' => $fileExt, ':size' => $tamanhoBytes, ':path' => $filePath, ':id' => $id
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE documents SET 
                                subject_id = :sub_id, title = :title, slug = :slug, description = :desc, 
                                content_type = :type, status = :status, text_content = :text_content, external_url = :url
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':sub_id' => $subjectId, ':title' => $titulo, ':slug' => $slug, ':desc' => $descricao,
                            ':type' => $tipoConteudo, ':status' => $status, ':text_content' => $conteudoHtml, ':url' => $linkExterno,
                            ':id' => $id
                        ]);
                    }
                    header('Location: index.php?tab=detalhes_documento&id=' . $id . '&msg=doc_updated');
                    exit;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO documents (
                            subject_id, created_by, title, slug, description, content_type, status, published_at,
                            original_filename, stored_filename, file_path, mime_type, file_extension, file_size,
                            text_content, external_url
                        ) VALUES (
                            :sub_id, :created_by, :title, :slug, :desc, :type, :status, CURRENT_TIMESTAMP,
                            :orig_name, :stored_name, :path, :mime, :ext, :size,
                            :text_content, :url
                        ) RETURNING id
                    ");
                    $stmt->execute([
                        ':sub_id' => $subjectId, ':created_by' => (int)$loggedUser['id'], ':title' => $titulo, ':slug' => $slug,
                        ':desc' => $descricao, ':type' => $tipoConteudo, ':status' => $status,
                        ':orig_name' => $originalFilename, ':stored_name' => $storedFilename, ':path' => $filePath,
                        ':mime' => $tipoMime, ':ext' => $fileExt, ':size' => $tamanhoBytes,
                        ':text_content' => $conteudoHtml, ':url' => $linkExterno
                    ]);
                    $newId = (int)$stmt->fetchColumn();
                    header('Location: index.php?tab=detalhes_documento&id=' . $newId . '&msg=doc_created');
                    exit;
                }
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

        $userId = (int)($loggedUser['id'] ?? 0);

        if (!$catId) {
            // CRIAR CATEGORIA: SOMENTE ADMIN GERAL
            if (!$permService->canCreateCategory($userId)) {
                http_response_code(403);
                $errorMessage = "Acesso negado. Apenas o Administrador Global pode criar novas Categorias no nível raiz.";
            } elseif (!empty($nome)) {
                $slug = slugify($nome);
                $stmt = $pdo->prepare("INSERT INTO categories (name, slug, description, active) VALUES (:name, :slug, :desc, :active)");
                $stmt->execute([':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false']);
                header('Location: index.php?tab=' . $redirectTab . '&msg=category_saved');
                exit;
            }
        } else {
            // EDITAR CATEGORIA: ADMIN DA CATEGORIA OU ADMIN GERAL
            if (!$permService->canAdminCategory($userId, $catId) && !$permService->isGlobalAdmin($userId)) {
                http_response_code(403);
                $errorMessage = "Acesso negado. É necessário privilégio Admin nesta Categoria (ou ser Administrador Global) para alterá-la.";
            } elseif (!empty($nome)) {
                $slug = slugify($nome);
                $stmt = $pdo->prepare("UPDATE categories SET name = :name, slug = :slug, description = :desc, active = :active WHERE id = :id");
                $stmt->execute([':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false', ':id' => $catId]);
                header('Location: index.php?tab=' . $redirectTab . '&msg=category_saved');
                exit;
            }
        }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'delete_category' && isset($_GET['id'])) {
        if ($loggedUser['role'] !== 'admin') {
            $errorMessage = "Usuários com perfil 'Editor' não possuem permissão para excluir Categorias.";
        } else {
            $catId = (int)$_GET['id'];
            $countSub = $pdo->prepare("SELECT COUNT(*) FROM subcategories WHERE category_id = :id");
            $countSub->execute([':id' => $catId]);
            if ((int)$countSub->fetchColumn() > 0) {
                $errorMessage = "Esta categoria possui subcategorias vinculadas. Desative-a em vez de excluir.";
            } else {
                $pdo->prepare("DELETE FROM categories WHERE id = :id")->execute([':id' => $catId]);
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

        $userId = (int)($loggedUser['id'] ?? 0);

        if (!$subId && ($catId <= 0 || !$permService->canCreateSubcategory($userId, $catId))) {
            http_response_code(403);
            $errorMessage = "Acesso negado. Você não possui permissão para criar subcategorias nesta categoria.";
        } elseif ($subId && !$permService->canEditSubcategory($userId, $subId)) {
            http_response_code(403);
            $errorMessage = "Acesso negado. Você não possui permissão para editar esta subcategoria.";
        } elseif (!empty($nome) && $catId > 0) {
            $slug = slugify($nome);
            if ($subId) {
                $stmt = $pdo->prepare("UPDATE subcategories SET category_id = :cat_id, name = :name, slug = :slug, description = :desc, active = :active WHERE id = :id");
                $stmt->execute([':cat_id' => $catId, ':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false', ':id' => $subId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO subcategories (category_id, name, slug, description, active) VALUES (:cat_id, :name, :slug, :desc, :active)");
                $stmt->execute([':cat_id' => $catId, ':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false']);
            }
            header('Location: index.php?tab=' . $redirectTab . '&msg=subcategory_saved');
            exit;
        }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'delete_subcategory' && isset($_GET['id'])) {
        if ($loggedUser['role'] !== 'admin') {
            $errorMessage = "Usuários com perfil 'Editor' não possuem permissão para excluir Subcategorias.";
        } else {
            $subId = (int)$_GET['id'];
            $countAss = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE subcategory_id = :id");
            $countAss->execute([':id' => $subId]);
            if ((int)$countAss->fetchColumn() > 0) {
                $errorMessage = "Esta subcategoria possui assuntos vinculados. Desative-a em vez de excluir.";
            } else {
                $pdo->prepare("DELETE FROM subcategories WHERE id = :id")->execute([':id' => $subId]);
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
        $redirectTab = trim($_POST['redirect_tab'] ?? 'assuntos');

        if ($loggedUser['role'] !== 'admin') {
            $errorMessage = "Usuários com perfil 'Editor' possuem acesso apenas à gestão de conteúdos.";
        } elseif (!empty($nome) && $subId > 0) {
            $slug = slugify($nome);
            if ($assId) {
                $stmt = $pdo->prepare("UPDATE subjects SET subcategory_id = :sub_id, name = :name, slug = :slug, description = :desc, active = :active WHERE id = :id");
                $stmt->execute([':sub_id' => $subId, ':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false', ':id' => $assId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO subjects (subcategory_id, name, slug, description, active) VALUES (:sub_id, :name, :slug, :desc, :active)");
                $stmt->execute([':sub_id' => $subId, ':name' => $nome, ':slug' => $slug, ':desc' => $descricao, ':active' => $statusVal ? 'true' : 'false']);
            }
            header('Location: index.php?tab=' . $redirectTab . '&msg=subject_saved');
            exit;
        }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'delete_subject' && isset($_GET['id'])) {
        if ($loggedUser['role'] !== 'admin') {
            $errorMessage = "Usuários com perfil 'Editor' não possuem permissão para excluir Assuntos.";
        } else {
            $assId = (int)$_GET['id'];
            $countDoc = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE subject_id = :id");
            $countDoc->execute([':id' => $assId]);
            if ((int)$countDoc->fetchColumn() > 0) {
                $errorMessage = "Este assunto possui documentos vinculados. Desative-o em vez de excluir.";
            } else {
                $pdo->prepare("DELETE FROM subjects WHERE id = :id")->execute([':id' => $assId]);
                header('Location: index.php?tab=assuntos&msg=subject_deleted');
                exit;
            }
        }
    }

    // CARREGAR EDICÃO DE ENTIDADES DA HIERARQUIA
    if (isset($_GET['action']) && $_GET['action'] === 'edit_category' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT id, name AS nome, description AS descricao, active FROM categories WHERE id = :id");
        $stmt->execute([':id' => (int)$_GET['id']]);
        $editCat = $stmt->fetch();
        if ($editCat) {
            $editCat['status'] = $editCat['active'] ? 'ativo' : 'inativo';
        }
    }
    if (isset($_GET['action']) && $_GET['action'] === 'edit_subcategory' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT sc.id, sc.category_id, sc.name AS nome, sc.description AS descricao, sc.active, c.name AS categoria_nome FROM subcategories sc JOIN categories c ON sc.category_id = c.id WHERE sc.id = :id");
        $stmt->execute([':id' => (int)$_GET['id']]);
        $editSub = $stmt->fetch();
        if ($editSub) {
            $editSub['status'] = $editSub['active'] ? 'ativo' : 'inativo';
        }
    }
    if (isset($_GET['action']) && $_GET['action'] === 'edit_subject' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT s.id, s.subcategory_id, s.name AS nome, s.description AS descricao, s.active, sc.name AS subcategoria_nome FROM subjects s JOIN subcategories sc ON s.subcategory_id = sc.id WHERE s.id = :id");
        $stmt->execute([':id' => (int)$_GET['id']]);
        $editAss = $stmt->fetch();
        if ($editAss) {
            $editAss['status'] = $editAss['active'] ? 'ativo' : 'inativo';
        }
    }
    if (isset($_GET['action']) && $_GET['action'] === 'edit_doc' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT d.id, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo, d.status,
                   d.text_content AS conteudo_html, d.external_url AS link_externo,
                   s.name AS assunto, sc.name AS subcategoria, c.name AS categoria
            FROM documents d
            JOIN subjects s ON d.subject_id = s.id
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON sc.category_id = c.id
            WHERE d.id = :id
        ");
        $stmt->execute([':id' => (int)$_GET['id']]);
        $editDoc = $stmt->fetch();
    }

    // CARREGAR DETALHES DE DOCUMENTO
    if ($activeTab === 'detalhes_documento' || $activeTab === 'substituir_arquivo') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT d.id, d.title AS titulo, d.description AS descricao, d.content_type AS tipo_conteudo, d.status,
                   d.original_filename AS nome_original, d.file_path AS caminho_arquivo, d.file_size AS tamanho_bytes,
                   d.mime_type AS tipo_mime, d.published_at, d.created_at,
                   s.name AS assunto, sc.name AS subcategoria, c.name AS categoria,
                   u.name AS autor_nome
            FROM documents d
            JOIN subjects s ON d.subject_id = s.id
            JOIN subcategories sc ON s.subcategory_id = sc.id
            JOIN categories c ON sc.category_id = c.id
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $docDetails = $stmt->fetch();
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

$whereClauses = ["d.status != 'inactive'"];
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
           d.status, d.created_at, d.published_at,
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

// Métricas de Visão Geral
$totalDocs = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
$totalPublicados = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'published'")->fetchColumn();
$totalRascunhos = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'draft'")->fetchColumn();
$totalInativos = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'inactive'")->fetchColumn();
$totalLixeira = $totalInativos;

$ultimosDocumentos = $pdo->query("
    SELECT d.id, d.title AS titulo, d.status, d.created_at, u.name AS autor_nome
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    ORDER BY d.id DESC LIMIT 5
")->fetchAll();

$documentosLixeira = $pdo->query("
    SELECT d.id, d.title AS titulo, d.status, d.updated_at AS removido_em
    FROM documents d
    WHERE d.status = 'inactive'
    ORDER BY d.updated_at DESC
")->fetchAll();

// Entidades de Organização
$listCategorias = $pdo->query("
    SELECT c.id, c.name AS nome, c.slug, c.description AS descricao, c.active,
           CASE WHEN c.active THEN 'ativo' ELSE 'inativo' END AS status,
           COUNT(sc.id) AS total_subcat
    FROM categories c
    LEFT JOIN subcategories sc ON sc.category_id = c.id
    GROUP BY c.id, c.name, c.slug, c.description, c.active
    ORDER BY c.name ASC
")->fetchAll();

$listSubcategorias = $pdo->query("
    SELECT sc.id, sc.category_id, sc.name AS nome, sc.slug, sc.description AS descricao, sc.active,
           CASE WHEN sc.active THEN 'ativo' ELSE 'inativo' END AS status,
           c.name AS categoria_nome,
           COUNT(s.id) AS total_assuntos
    FROM subcategories sc
    JOIN categories c ON sc.category_id = c.id
    LEFT JOIN subjects s ON s.subcategory_id = sc.id
    GROUP BY sc.id, sc.category_id, sc.name, sc.slug, sc.description, sc.active, c.name
    ORDER BY c.name ASC, sc.name ASC
")->fetchAll();

$listAssuntos = $pdo->query("
    SELECT s.id, s.subcategory_id, s.name AS nome, s.slug, s.description AS descricao, s.active,
           CASE WHEN s.active THEN 'ativo' ELSE 'inativo' END AS status,
           sc.name AS subcategoria_nome, c.name AS categoria_nome,
           COUNT(d.id) AS total_docs
    FROM subjects s
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    LEFT JOIN documents d ON d.subject_id = s.id
    GROUP BY s.id, s.subcategory_id, s.name, s.slug, s.description, s.active, sc.name, c.name
    ORDER BY c.name ASC, sc.name ASC, s.name ASC
")->fetchAll();

require_once __DIR__ . '/../services/AccessService.php';
$accessServiceAdmin = new AccessService($pdo);
$loggedAdminUserId = (int)($loggedUser['id'] ?? 0);

if ($loggedUser && ($loggedUser['role'] ?? '') === 'editor') {
    $allowedCatIdsAdmin = $accessServiceAdmin->getAllowedCategoryIds($loggedAdminUserId);
    $allowedSubcatIdsAdmin = $accessServiceAdmin->getAllowedSubcategoryIds($loggedAdminUserId);
    $allowedSubjectIdsAdmin = $accessServiceAdmin->getAllowedSubjectIds($loggedAdminUserId);

    $listCategorias = array_values(array_filter($listCategorias, fn($c) => in_array((int)$c['id'], $allowedCatIdsAdmin)));
    $listSubcategorias = array_values(array_filter($listSubcategorias, fn($sc) => in_array((int)$sc['id'], $allowedSubcatIdsAdmin)));
    $listAssuntos = array_values(array_filter($listAssuntos, fn($s) => in_array((int)$s['id'], $allowedSubjectIdsAdmin)));
}

$rawCategorias = array_column($listCategorias, 'nome');
$categoriasAutorizadas = $rawCategorias;

// Mapeamento Completo da Árvore Hierárquica
$treeStructure = [];
foreach ($listCategorias as $catItem) {
    $catName = $catItem['nome'];
    $treeStructure[$catName] = [
        'info' => $catItem,
        'subcategorias' => []
    ];
}

foreach ($listSubcategorias as $subItem) {
    $catName = $subItem['categoria_nome'];
    $subName = $subItem['nome'];
    if (isset($treeStructure[$catName])) {
        $treeStructure[$catName]['subcategorias'][$subName] = [
            'info' => $subItem,
            'assuntos' => []
        ];
    }
}

foreach ($listAssuntos as $assItem) {
    $subName = $assItem['subcategoria_nome'];
    $assName = $assItem['nome'];

    foreach ($treeStructure as $cName => &$cData) {
        if (isset($cData['subcategorias'][$subName])) {
            $cData['subcategorias'][$subName]['assuntos'][$assName] = [
                'info' => $assItem,
                'documentos' => []
            ];
        }
    }
}

$allDocsTree = $pdo->query("
    SELECT d.id, d.title AS titulo, d.status, d.content_type AS tipo_conteudo,
           s.name AS assunto, sc.name AS subcategoria, c.name AS categoria
    FROM documents d
    JOIN subjects s ON d.subject_id = s.id
    JOIN subcategories sc ON s.subcategory_id = sc.id
    JOIN categories c ON sc.category_id = c.id
    ORDER BY d.id DESC, d.title ASC
")->fetchAll();

foreach ($allDocsTree as $dItem) {
    $cName = $dItem['categoria'];
    $sName = $dItem['subcategoria'];
    $aName = $dItem['assunto'];
    if (isset($treeStructure[$cName]['subcategorias'][$sName]['assuntos'][$aName])) {
        $treeStructure[$cName]['subcategorias'][$sName]['assuntos'][$aName]['documentos'][] = $dItem;
    }
}

$hierarchyMap = [];
foreach ($treeStructure as $cName => $cData) {
    $hierarchyMap[$cName] = [];
    foreach ($cData['subcategorias'] as $sName => $sData) {
        $hierarchyMap[$cName][$sName] = array_keys($sData['assuntos']);
    }
}

$userTheme = $loggedUser['tema_preferido'] ?? ($loggedUser['theme_preference'] ?? 'light');
$userThemeClass = $userTheme === 'dark' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $userThemeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Documentos - Prefeitura Municipal</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        darkMode: 'class',
        theme: {
          extend: {
            colors: {
              graphite: {
                950: '#181a1f',
                900: '#23252a',
                800: '#2c2e33',
                700: '#353842',
                600: '#454956'
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
            color: #94a3b8; /* slate-400 */
        }
        .nc-type-btn:hover {
            color: #1e293b;
            background: rgba(255,255,255,0.7);
        }
        .dark .nc-type-btn:hover {
            color: #f1f5f9;
            background: rgba(255,255,255,0.08);
        }
        .nc-type-btn.nc-type-active {
            color: #0f172a;
            background: #ffffff;
            box-shadow: 0 1px 3px 0 rgba(0,0,0,0.12), 0 1px 2px -1px rgba(0,0,0,0.10);
        }
        .dark .nc-type-btn.nc-type-active {
            color: #f8fafc;
            background: #353842;
            box-shadow: 0 1px 4px 0 rgba(0,0,0,0.35);
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
            color: #94a3b8;
        }
        .doc-type-btn:has(input:checked) {
            color: #0f172a;
            background: #ffffff;
            box-shadow: 0 1px 3px 0 rgba(0,0,0,0.12);
        }
        .dark .doc-type-btn:has(input:checked) {
            color: #f8fafc;
            background: #454956;
        }
        .doc-type-btn:hover {
            color: #1e293b;
        }
    </style>
</head>
<body class="bg-[#f8f9fa] dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 min-h-screen flex flex-col selection:bg-slate-800 selection:text-white dark:selection:bg-slate-200 dark:selection:text-slate-900">

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

                <form method="POST" action="index.php">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3 text-left">
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Usuário ou E-mail</label>
                        <input type="text" name="username" required class="input-minimal w-full text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" placeholder="Ex: samuel ou admin@prefeitura.gov.br">
                    </div>
                    <div class="mb-4 text-left">
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Senha de Acesso</label>
                        <input type="password" name="password" required class="input-minimal w-full text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" placeholder="Digite sua senha...">
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
                                <div class="w-8 h-8 rounded-md bg-slate-900 dark:bg-white text-white dark:text-slate-900 flex items-center justify-center font-bold text-xs shadow-xs shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                                </div>
                                <div class="flex flex-col brand-text truncate">
                                    <span class="font-bold text-sm text-slate-900 dark:text-slate-100 leading-tight truncate">Administração</span>
                                    <span class="text-[10px] text-slate-400 dark:text-slate-500 font-medium truncate">Prefeitura Municipal</span>
                                </div>
                            </a>
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
                            <?php if (($loggedUser['role'] ?? '') === 'admin'): ?>
                                <div class="pt-3 border-t border-slate-100 dark:border-[#454956]">
                                    <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 menu-section-title">
                                        GESTÃO DE ACESSO
                                    </div>
                                    <div class="mt-1 space-y-1">
                                        <a href="index.php?tab=grupos" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= in_array($activeTab, ['grupos', 'editar_grupo']) ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Grupos de Acesso">
                                            <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                            <span class="menu-label font-bold">Grupos</span>
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

                                    <a href="index.php?tab=configuracoes" class="menu-item-content flex items-center gap-2.5 px-3 py-2 rounded-md transition text-decoration-none <?= $activeTab === 'configuracoes' ? 'bg-slate-100 dark:bg-[#353842] text-slate-900 dark:text-white font-bold shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#3e424e]' ?>" title="Configurações">
                                        <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        <span class="menu-label font-bold">Configurações</span>
                                    </a>
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
                                    <p class="text-[10px] text-slate-400 font-mono capitalize leading-tight"><?= htmlspecialchars($loggedUser['role'] ?? 'Admin') ?></p>
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
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errorMessage)): ?>
                    <div class="bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs p-3 rounded-md mb-6 shadow-xs font-medium">
                        Atenção: <?= htmlspecialchars($errorMessage) ?>
                    </div>
                <?php endif; ?>

                <!-- ABA NOVAS DE EDIÇÃO DA ESTRUTURA COM ÁRVORE VERTICAL E PAINEL DUPLO (TOP-DOWN) -->
                <?php if ($activeTab === 'editar_estrutura'): ?>
                    <?php
                        $selectedType = $_GET['type'] ?? 'categoria';
                        $selectedId = (int)($_GET['id'] ?? (reset($listCategorias)['id'] ?? 0));

                        $selCatItem = null;
                        $selSubItem = null;
                        $selAssItem = null;
                        $selDocItem = null;

                        if ($selectedType === 'categoria') {
                            $stmt = $pdo->prepare("SELECT id, name AS nome, slug, description AS descricao, active AS ativo, CASE WHEN active THEN 'ativo' ELSE 'inativo' END AS status FROM categories WHERE id = ?");
                            $stmt->execute([$selectedId]);
                            $selCatItem = $stmt->fetch();
                        } elseif ($selectedType === 'subcategoria') {
                            $stmt = $pdo->prepare("
                                SELECT sc.id, sc.category_id, sc.name AS nome, sc.slug, sc.description AS descricao, sc.active AS ativo,
                                       CASE WHEN sc.active THEN 'ativo' ELSE 'inativo' END AS status, c.name AS categoria_nome
                                FROM subcategories sc
                                JOIN categories c ON sc.category_id = c.id
                                WHERE sc.id = ?
                            ");
                            $stmt->execute([$selectedId]);
                            $selSubItem = $stmt->fetch();
                        } elseif ($selectedType === 'assunto') {
                            $stmt = $pdo->prepare("
                                SELECT s.id, s.subcategory_id, s.name AS nome, s.slug, s.description AS descricao, s.active AS ativo,
                                       CASE WHEN s.active THEN 'ativo' ELSE 'inativo' END AS status,
                                       sc.name AS subcategoria_nome, c.name AS categoria_nome
                                FROM subjects s
                                JOIN subcategories sc ON s.subcategory_id = sc.id
                                JOIN categories c ON sc.category_id = c.id
                                WHERE s.id = ?
                            ");
                            $stmt->execute([$selectedId]);
                            $selAssItem = $stmt->fetch();
                            if ($selAssItem) {
                                $parentCatName = $selAssItem['categoria_nome'] ?? '';
                            }
                        } elseif ($selectedType === 'documento') {
                            $stmt = $pdo->prepare("
                                SELECT d.id, d.title AS titulo, d.slug, d.description AS descricao, d.status,
                                       s.name AS assunto_nome, sc.name AS subcategoria_nome, c.name AS categoria_nome
                                FROM documents d
                                JOIN subjects s ON d.subject_id = s.id
                                JOIN subcategories sc ON s.subcategory_id = sc.id
                                JOIN categories c ON sc.category_id = c.id
                                WHERE d.id = ?
                            ");
                            $stmt->execute([$selectedId]);
                            $selDocItem = $stmt->fetch();
                        }
                    ?>
                    <div class="space-y-6">
                        <!-- CABEÇALHO DA SEÇÃO DE ORGANIZAÇÃO -->
                        <div class="pb-2 border-b border-slate-100 dark:border-[#454956]">
                            <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Administração Hierárquica Top-Down</h1>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Gerencie a estrutura documental e ordene o layout visual dos conteúdos ao lado do acervo.</p>
                        </div>

                        <!-- PAINEL DUPLO LADO A LADO: ÁRVORE DO ACERVO + EDITOR DE FORMULÁRIOS -->
                        <div class="organization-layout grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                            
                            <!-- COLUNA ESQUERDA: ÁRVORE DO ACERVO (PAINEL LATERAL SECUNDÁRIO FIXO E PERMANENTE) -->
                            <aside id="admin-tree-sidebar" class="lg:col-span-4 bg-white dark:bg-[#353842] p-4 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs flex flex-col justify-between sticky top-4 max-h-[calc(100vh-2rem)] overflow-y-auto">
                                
                                <!-- CONTEÚDO DA ÁRVORE DO ACERVO -->
                                <div class="tree-content space-y-4">
                                    <div class="pb-3 mb-3 border-b border-slate-100 dark:border-[#454956]">
                                        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 block truncate">Estrutura do Acervo</h2>
                                        <p class="text-[10px] text-slate-400 truncate mt-0.5 mb-2">Categoria → Subcategoria → Assunto → Documento</p>

                                        <!-- BUSCA DISCRETA NA ÁRVORE DO ACERVO -->
                                        <div class="mb-2">
                                            <input type="text" id="tree-search-input" oninput="filterTreeNodes(this.value)" placeholder="Pesquisar na estrutura..." class="input-minimal w-full px-2.5 py-1.5 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-[#353842]" title="Pesquisar categoria, subcategoria, assunto ou documento">
                                        </div>

                                        <div class="flex items-center gap-1.5 text-[10px] font-semibold text-slate-600 dark:text-slate-300">
                                            <button type="button" onclick="expandAllTreeNodes()" class="hover:underline">Expandir tudo</button>
                                            <span class="text-slate-300">|</span>
                                            <button type="button" onclick="collapseAllTreeNodes()" class="hover:underline">Recolher tudo</button>
                                        </div>
                                    </div>

                                    <div class="space-y-2 text-xs font-medium overflow-x-auto pr-1">
                                        <?php foreach ($treeStructure as $cName => $cData): ?>
                                            <?php 
                                                $cId = $cData['info']['id']; 
                                                $isCatActive = ($selectedType === 'categoria' && $selectedId == $cId);
                                                $hasSelectedSub = false;
                                                foreach ($cData['subcategorias'] as $sName => $sData) {
                                                    if (($selectedType === 'subcategoria' && $selectedId == $sData['info']['id']) ||
                                                        ($selectedType === 'assunto' && isset($sData['assuntos'][$selAssItem['nome'] ?? '']))) {
                                                        $hasSelectedSub = true;
                                                        break;
                                                    }
                                                }
                                                $catOpen = ($isCatActive || $hasSelectedSub || count($treeStructure) === 1);
                                            ?>
                                            <div class="tree-node-group border-l-2 border-slate-200 dark:border-[#454956] pl-2 space-y-1">
                                                
                                                <!-- NÍVEL 1: CATEGORIA -->
                                                <div class="flex items-center justify-between group p-1.5 rounded-md transition <?= $isCatActive ? 'bg-slate-100 dark:bg-[#2c2e33] text-slate-900 dark:text-white font-bold border-l-2 border-slate-900 dark:border-white shadow-2xs' : 'text-slate-800 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-[#2c2e33]/60' ?>">
                                                    <div class="flex items-center gap-1.5 min-w-0 flex-1">
                                                        <button type="button" onclick="toggleTreeNode(this)" class="tree-toggle-btn p-0.5 rounded hover:bg-slate-200 dark:hover:bg-slate-700 transition" title="Expandir/Recolher Categoria">
                                                            <svg class="w-3 h-3 transform transition-transform duration-200 <?= $catOpen ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                                        </button>
                                                        <a href="index.php?tab=editar_estrutura&type=categoria&id=<?= $cId ?>" class="flex items-center gap-1.5 truncate text-decoration-none text-current flex-1">
                                                            <svg class="w-3.5 h-3.5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                                            <span class="truncate font-semibold"><?= htmlspecialchars($cName) ?></span>
                                                        </a>
                                                    </div>
                                                    <a href="index.php?tab=editar_estrutura&type=subcategoria_new&cat=<?= urlencode($cName) ?>" class="text-[10px] px-1.5 py-0.5 rounded bg-slate-200/60 dark:bg-slate-700/60 text-slate-600 dark:text-slate-300 font-semibold text-decoration-none hover:bg-slate-300" title="Criar Subcategoria">+ Subcat</a>
                                                </div>

                                                <!-- NÍVEL 2: SUBCATEGORIAS -->
                                                <div class="tree-branch pl-3 space-y-1 <?= $catOpen ? '' : 'hidden' ?>">
                                                    <?php foreach ($cData['subcategorias'] as $sName => $sData): ?>
                                                        <?php 
                                                            $sId = $sData['info']['id'];
                                                            $isSubActive = ($selectedType === 'subcategoria' && $selectedId == $sId);
                                                            $hasSelectedAss = ($selectedType === 'assunto' && isset($sData['assuntos'][$selAssItem['nome'] ?? '']));
                                                            $subOpen = ($isSubActive || $hasSelectedAss);
                                                        ?>
                                                        <div class="tree-node-group border-l border-slate-200 dark:border-[#454956]/70 pl-2">
                                                            <div class="flex items-center justify-between group p-1 rounded-md transition <?= $isSubActive ? 'bg-slate-100 dark:bg-[#2c2e33] text-slate-900 dark:text-white font-bold border-l-2 border-slate-700 dark:border-slate-300 shadow-2xs' : 'text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#2c2e33]/60' ?>">
                                                                <div class="flex items-center gap-1 min-w-0 flex-1">
                                                                    <button type="button" onclick="toggleTreeNode(this)" class="tree-toggle-btn p-0.5 rounded hover:bg-slate-200 dark:hover:bg-slate-700 transition" title="Expandir/Recolher Subcategoria">
                                                                        <svg class="w-3 h-3 transform transition-transform duration-200 <?= $subOpen ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                                                    </button>
                                                                    <a href="index.php?tab=editar_estrutura&type=subcategoria&id=<?= $sId ?>" class="flex items-center gap-1.5 truncate text-decoration-none text-current flex-1">
                                                                        <svg class="w-3.5 h-3.5 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                                                                        <span class="truncate"><?= htmlspecialchars($sName) ?></span>
                                                                    </a>
                                                                </div>
                                                                <a href="index.php?tab=editar_estrutura&type=assunto_new&sub=<?= urlencode($sName) ?>" class="text-[9px] px-1 py-0.2 rounded bg-slate-100 dark:bg-slate-800 text-slate-500 font-semibold text-decoration-none hover:bg-slate-200">+ Assunto</a>
                                                            </div>

                                                            <!-- NÍVEL 3: ASSUNTOS -->
                                                            <div class="tree-branch pl-3 space-y-0.5 mt-0.5 <?= $subOpen ? '' : 'hidden' ?>">
                                                                <?php foreach ($sData['assuntos'] as $aName => $aData): ?>
                                                                    <?php 
                                                                        $aId = $aData['info']['id']; 
                                                                        $isAssActive = ($selectedType === 'assunto' && $selectedId == $aId);
                                                                        $assDocs = $aData['documentos'] ?? [];
                                                                        $assOpen = ($isAssActive || !empty($assDocs));
                                                                    ?>
                                                                    <div class="tree-node-group border-l border-slate-100 dark:border-[#454956]/50 pl-1.5">
                                                                        <div class="flex items-center justify-between p-1 rounded transition <?= $isAssActive ? 'bg-slate-100 dark:bg-[#2c2e33] text-slate-900 dark:text-white font-bold border-l-2 border-slate-500 dark:border-slate-400 shadow-2xs' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-[#2c2e33]/60' ?>">
                                                                            <div class="flex items-center gap-1 min-w-0 flex-1">
                                                                                <?php if (!empty($assDocs)): ?>
                                                                                    <button type="button" onclick="toggleTreeNode(this)" class="tree-toggle-btn p-0.5 rounded hover:bg-slate-200 dark:hover:bg-slate-700 transition" title="Expandir/Recolher Documentos">
                                                                                        <svg class="w-3 h-3 transform transition-transform duration-200 <?= $assOpen ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                                                                    </button>
                                                                                <?php else: ?>
                                                                                    <span class="w-4 inline-block"></span>
                                                                                <?php endif; ?>
                                                                                <a href="index.php?tab=editar_estrutura&type=assunto&id=<?= $aId ?>" class="flex items-center gap-1.5 text-decoration-none text-current truncate flex-1">
                                                                                    <svg class="w-3 h-3 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                                                                    <span class="truncate"><?= htmlspecialchars($aName) ?></span>
                                                                                </a>
                                                                            </div>
                                                                            <span class="text-[9px] font-mono text-slate-400 ml-1">(<?= count($assDocs) ?>)</span>
                                                                        </div>

                                                                        <!-- NÍVEL 4: DOCUMENTOS DO ASSUNTO -->
                                                                        <?php if (!empty($assDocs)): ?>
                                                                            <div class="tree-branch pl-4 space-y-0.5 my-0.5 <?= $assOpen ? '' : 'hidden' ?>">
                                                                                <?php foreach ($assDocs as $dItem): ?>
                                                                                    <div class="flex items-center justify-between p-1 rounded hover:bg-slate-100 dark:hover:bg-[#2c2e33] text-slate-500 dark:text-slate-400 text-[11px]">
                                                                                        <a href="ver_conteudo.php?id=<?= $dItem['id'] ?>" class="flex items-center gap-1.5 text-decoration-none text-current truncate flex-1 hover:text-slate-900 dark:hover:text-white" title="Visualizar Documento">
                                                                                            <svg class="w-3 h-3 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                                                            <span class="truncate"><?= htmlspecialchars($dItem['titulo']) ?></span>
                                                                                        </a>
                                                                                        <a href="index.php?tab=novo_documento&edit=<?= $dItem['id'] ?>" class="text-[9px] font-semibold text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 ml-1 text-decoration-none">Editar</a>
                                                                                    </div>
                                                                                <?php endforeach; ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </aside>

                            <!-- COLUNA DIREITA: FORMULÁRIO DE EDIÇÃO E DETALHES -->
                            <section id="admin-main-canvas" class="lg:col-span-8 bg-white dark:bg-[#353842] p-6 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                                
                                <!-- 1. EDITANDO CATEGORIA -->
                                <?php if ($selectedType === 'categoria' && $selCatItem): ?>
                                    <div class="space-y-6">
                                        <div class="pb-3 border-b border-slate-100 dark:border-[#454956] flex items-center justify-between">
                                            <div>
                                                <span class="text-[10px] font-bold uppercase text-slate-400 block">Editando Categoria</span>
                                                <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($selCatItem['nome']) ?></h2>
                                            </div>
                                            <a href="index.php?tab=editar_estrutura&type=subcategoria_new&cat=<?= urlencode($selCatItem['nome']) ?>" class="px-3 py-1.5 bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold text-xs rounded shadow-xs">
                                                + Nova Subcategoria nesta Categoria
                                            </a>
                                        </div>

                                        <form method="POST" action="index.php?tab=editar_estrutura&type=categoria&id=<?= $selCatItem['id'] ?>" class="space-y-4">
                                            <input type="hidden" name="save_category" value="1">
                                            <input type="hidden" name="id" value="<?= $selCatItem['id'] ?>">

                                            <div>
                                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome da Categoria *</label>
                                                <input type="text" name="nome" required value="<?= htmlspecialchars($selCatItem['nome']) ?>" class="input-minimal w-full px-3 py-2 text-xs">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                                <textarea name="descricao" rows="2" class="input-minimal w-full px-3 py-2 text-xs"><?= htmlspecialchars($selCatItem['descricao']) ?></textarea>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Status</label>
                                                <select name="status" class="input-minimal w-full px-3 py-2 text-xs">
                                                    <option value="ativo" <?= $selCatItem['status'] === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                                    <option value="inativo" <?= $selCatItem['status'] === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                                </select>
                                            </div>

                                            <div class="flex justify-end gap-2 pt-2">
                                                <button type="submit" class="px-5 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold">Salvar Categoria</button>
                                            </div>
                                        </form>

                                        <!-- LISTAGEM TOP-DOWN DAS SUBCATEGORIAS DESTA CATEGORIA -->
                                        <div class="pt-4 border-t border-slate-100 dark:border-[#454956]">
                                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">Subcategorias Pertencentes</h3>
                                            <div class="divide-y divide-slate-100 dark:divide-[#454956]">
                                                <?php 
                                                    $subsInCat = $treeStructure[$selCatItem['nome']]['subcategorias'] ?? [];
                                                    if (empty($subsInCat)):
                                                ?>
                                                    <p class="text-xs text-slate-400 py-2">Nenhuma subcategoria cadastrada nesta categoria.</p>
                                                <?php else: ?>
                                                    <?php foreach ($subsInCat as $subN => $subD): ?>
                                                        <div class="py-2.5 flex items-center justify-between text-xs">
                                                            <div class="flex items-center gap-2">
                                                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                                                                <div>
                                                                    <span class="font-bold text-slate-900 dark:text-slate-100 block"><?= htmlspecialchars($subN) ?></span>
                                                                    <span class="text-[11px] text-slate-400"><?= count($subD['assuntos']) ?> assuntos cadastrados</span>
                                                                </div>
                                                            </div>
                                                            <a href="index.php?tab=editar_estrutura&type=subcategoria&id=<?= $subD['info']['id'] ?>" class="text-amber-600 font-semibold hover:underline">Administrar Subcategoria &rarr;</a>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- 2. EDITANDO SUBCATEGORIA (REGRA TOP-DOWN: NÃO PERMITE EDITAR CATEGORIA PAI) -->
                                <?php if ($selectedType === 'subcategoria' && $selSubItem): ?>
                                    <div class="space-y-6">
                                        <div class="pb-3 border-b border-slate-100 dark:border-[#454956] flex items-center justify-between">
                                            <div>
                                                <span class="text-[10px] font-bold uppercase text-slate-400 block">Editando Subcategoria</span>
                                                <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($selSubItem['nome']) ?></h2>
                                            </div>
                                            <a href="index.php?tab=editar_estrutura&type=assunto_new&sub=<?= urlencode($selSubItem['nome']) ?>" class="px-3 py-1.5 bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold text-xs rounded shadow-xs">
                                                + Novo Assunto nesta Subcategoria
                                            </a>
                                        </div>

                                        <form method="POST" action="index.php?tab=editar_estrutura&type=subcategoria&id=<?= $selSubItem['id'] ?>" class="space-y-4">
                                            <input type="hidden" name="save_subcategory" value="1">
                                            <input type="hidden" name="id" value="<?= $selSubItem['id'] ?>">

                                            <!-- CATEGORIA PAI SOMENTE LEITURA -->
                                            <div class="p-3 rounded bg-slate-50 dark:bg-[#2c2e33] border border-slate-200 dark:border-[#454956] text-xs">
                                                <span class="text-slate-400 font-bold uppercase text-[10px] block">Categoria Pai (Somente Leitura):</span>
                                                <span class="font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5 mt-0.5">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                                    <?= htmlspecialchars($selSubItem['categoria_nome']) ?>
                                                </span>
                                                <input type="hidden" name="categoria_nome" value="<?= htmlspecialchars($selSubItem['categoria_nome']) ?>">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome da Subcategoria *</label>
                                                <input type="text" name="nome" required value="<?= htmlspecialchars($selSubItem['nome']) ?>" class="input-minimal w-full px-3 py-2 text-xs">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                                <textarea name="descricao" rows="2" class="input-minimal w-full px-3 py-2 text-xs"><?= htmlspecialchars($selSubItem['descricao']) ?></textarea>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Status</label>
                                                <select name="status" class="input-minimal w-full px-3 py-2 text-xs">
                                                    <option value="ativo" <?= $selSubItem['status'] === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                                    <option value="inativo" <?= $selSubItem['status'] === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                                </select>
                                            </div>

                                            <div class="flex justify-end gap-2 pt-2">
                                                <button type="submit" class="px-5 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold">Salvar Subcategoria</button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                <!-- 3. EDITANDO ASSUNTO & EDITOR VISUAL (GRIDSTACK CANVAS BASE) -->
                                <?php if ($selectedType === 'assunto' && $selAssItem): ?>
                                    <?php
                                        $docsInAssunto = $pdo->prepare("
                                            SELECT d.id, d.title AS titulo, d.slug, d.description AS descricao, d.status, d.content_type AS tipo_conteudo, d.published_at, d.updated_at
                                            FROM documents d
                                            WHERE d.subject_id = ? AND d.status != 'inactive'
                                            ORDER BY d.title ASC
                                        ");
                                        $docsInAssunto->execute([$selAssItem['id']]);
                                        $docsAssList = $docsInAssunto->fetchAll();
                                    ?>
                                    <div class="space-y-5">
                                        <!-- 4. BREADCRUMB DO CONTEXTO -->
                                        <nav class="flex items-center gap-1.5 text-xs text-slate-400 font-medium pb-2 border-b border-slate-100 dark:border-[#454956]">
                                            <span><?= htmlspecialchars($parentCatName ?: 'Categoria') ?></span>
                                            <span>/</span>
                                            <span><?= htmlspecialchars($selAssItem['subcategoria_nome']) ?></span>
                                            <span>/</span>
                                            <span class="font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($selAssItem['nome']) ?></span>
                                        </nav>

                                        <!-- 5. BARRA SUPERIOR DO EDITOR -->
                                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pb-3 border-b border-slate-100 dark:border-[#454956]">
                                            <div class="flex flex-wrap items-center gap-3">
                                                <h2 class="text-base font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($selAssItem['nome']) ?></h2>
                                                <div class="inline-flex items-center rounded-md border border-slate-200 dark:border-[#454956] p-0.5 bg-slate-50 dark:bg-[#2c2e33]">
                                                    <button type="button" id="btn-mode-edit" onclick="setEditorMode('edit')" class="px-3 py-1 rounded text-xs font-semibold bg-slate-900 dark:bg-white text-white dark:text-slate-900 transition">
                                                        Editar layout
                                                    </button>
                                                    <button type="button" id="btn-mode-preview" onclick="setEditorMode('preview')" class="px-3 py-1 rounded text-xs font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition">
                                                        Visualizar
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="flex items-center gap-3">
                                                <span id="save-status-indicator" class="text-xs text-slate-400 font-mono flex items-center gap-1.5">
                                                    <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>
                                                    <span>Alterações salvas</span>
                                                </span>
                                                <button type="button" onclick="saveEditorLayoutState()" class="px-4 py-1.5 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90 transition">
                                                    Salvar
                                                </button>
                                            </div>
                                        </div>

                                        <!-- 6 & 7. CANVAS DO EDITOR VISUAL (GRIDSTACK & ESTADO VAZIO) -->
                                        <div id="editor-visual-canvas">
                                            <?php if (empty($docsAssList)): ?>
                                                <!-- ESTADO VAZIO -->
                                                <div class="p-10 text-center border-2 border-dashed border-slate-200 dark:border-[#454956] rounded-md bg-slate-50/50 dark:bg-[#2c2e33]/50">
                                                    <div class="w-10 h-10 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-300 flex items-center justify-center mx-auto mb-3">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                                                    </div>
                                                    <p class="text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Nenhum conteúdo neste assunto.</p>
                                                    <p class="text-[11px] text-slate-400 mb-4">Adicione o primeiro documento para organizar este assunto.</p>
                                                    <a href="index.php?tab=novo_documento&cat=<?= urlencode($parentCatName) ?>&subcat=<?= urlencode($selAssItem['subcategoria_nome']) ?>&assunto=<?= urlencode($selAssItem['nome']) ?>" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold shadow-xs text-decoration-none">
                                                        + Adicionar conteúdo
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <!-- GRIDSTACK CONTAINER BASE -->
                                                <form method="POST" action="index.php?tab=editar_estrutura&type=assunto&id=<?= $selAssItem['id'] ?>" id="form-editor-grid" class="space-y-3">
                                                    <input type="hidden" name="save_contents_layout" value="1">
                                                    <div class="grid-stack grid-stack-12 min-h-[220px] p-2 bg-slate-50/60 dark:bg-[#2c2e33]/60 border border-slate-200 dark:border-[#454956] rounded-md">
                                                        <?php foreach ($docsAssList as $idx => $dAss): ?>
                                                            <?php
                                                                $w = $dAss['layout_width'] ?? 'full';
                                                                $gsW = 12;
                                                                if ($w === 'half') $gsW = 6;
                                                                elseif ($w === 'one-third') $gsW = 4;
                                                                elseif ($w === 'two-thirds') $gsW = 8;
                                                            ?>
                                                            <div class="grid-stack-item p-1" data-gs-id="<?= $dAss['id'] ?>" data-gs-width="<?= $gsW ?>" data-gs-height="3" data-gs-auto-position="true">
                                                                <div class="grid-stack-item-content p-4 bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] rounded-md shadow-xs flex flex-col justify-between h-full group">
                                                                    <div>
                                                                        <div class="flex items-center justify-between mb-2">
                                                                            <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-[#454956]">
                                                                                <?= strtoupper($dAss['tipo_conteudo'] ?? $dAss['content_type'] ?? 'DOC') ?>
                                                                            </span>
                                                                            <span class="text-[10px] font-mono text-slate-400">#<?= $dAss['id'] ?></span>
                                                                        </div>
                                                                        <h4 class="text-xs font-bold text-slate-900 dark:text-slate-100 line-clamp-1"><?= htmlspecialchars($dAss['titulo']) ?></h4>
                                                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 line-clamp-2 mt-1"><?= htmlspecialchars($dAss['descricao']) ?></p>
                                                                    </div>

                                                                    <div class="mt-3 pt-2.5 border-t border-slate-100 dark:border-[#454956] flex items-center justify-between gap-2 text-[11px] text-slate-400">
                                                                        <div class="flex items-center gap-2">
                                                                            <label class="block text-[9px] font-bold uppercase text-slate-400">Largura:</label>
                                                                            <select name="doc_widths[<?= $dAss['id'] ?>]" class="input-minimal px-1.5 py-0.5 text-[11px]" onchange="markEditorDirty()">
                                                                                <option value="full" <?= ($w === 'full') ? 'selected' : '' ?>>Completa (100%)</option>
                                                                                <option value="half" <?= ($w === 'half') ? 'selected' : '' ?>>Metade (50%)</option>
                                                                                <option value="one-third" <?= ($w === 'one-third') ? 'selected' : '' ?>>1/3 (33%)</option>
                                                                                <option value="two-thirds" <?= ($w === 'two-thirds') ? 'selected' : '' ?>>2/3 (66%)</option>
                                                                            </select>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        </div>

                                        <!-- COMPACT DETALHES DE METADADOS DO ASSUNTO -->
                                        <details class="pt-4 border-t border-slate-100 dark:border-[#454956]">
                                            <summary class="text-xs font-bold text-slate-500 cursor-pointer hover:text-slate-800 dark:hover:text-slate-200">
                                                Editar Dados do Assunto (Nome)
                                            </summary>
                                            <form method="POST" action="index.php?tab=editar_estrutura&type=assunto&id=<?= $selAssItem['id'] ?>" class="space-y-4 mt-3">
                                                <input type="hidden" name="save_subject" value="1">
                                                <input type="hidden" name="id" value="<?= $selAssItem['id'] ?>">

                                                <div class="p-3 rounded bg-slate-50 dark:bg-[#2c2e33] border border-slate-200 dark:border-[#454956] text-xs">
                                                    <span class="text-slate-400 font-bold uppercase text-[10px] block">Subcategoria Pai (Somente Leitura):</span>
                                                    <span class="font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5 mt-0.5">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                                                        <?= htmlspecialchars($selAssItem['subcategoria_nome']) ?>
                                                    </span>
                                                    <input type="hidden" name="subcategoria_nome" value="<?= htmlspecialchars($selAssItem['subcategoria_nome']) ?>">
                                                </div>

                                                <div>
                                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome do Assunto *</label>
                                                    <input type="text" name="nome" required value="<?= htmlspecialchars($selAssItem['nome']) ?>" class="input-minimal w-full px-3 py-2 text-xs">
                                                </div>

                                                <div class="flex justify-end gap-2 pt-2">
                                                    <button type="submit" class="px-5 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold">Salvar Dados do Assunto</button>
                                                </div>
                                            </form>
                                        </details>
                                    </div>
                                <?php endif; ?>

                                <!-- CRIAÇÃO CONTEXTUAL DE SUBCATEGORIA OU ASSUNTO -->
                                <?php if ($selectedType === 'subcategoria_new'): ?>
                                    <div class="space-y-4">
                                        <h2 class="text-base font-bold text-slate-900 dark:text-slate-100 pb-2 border-b">Nova Subcategoria Contextual</h2>
                                        <form method="POST" action="index.php?tab=editar_estrutura" class="space-y-3">
                                            <input type="hidden" name="save_subcategory" value="1">
                                            <div class="p-3 rounded bg-slate-50 dark:bg-[#2c2e33] border text-xs">
                                                <span class="text-slate-400 font-bold uppercase text-[10px] block">Categoria Pai:</span>
                                                <span class="font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5 mt-0.5">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                                    <?= htmlspecialchars($_GET['cat'] ?? '') ?>
                                                </span>
                                                <input type="hidden" name="categoria_nome" value="<?= htmlspecialchars($_GET['cat'] ?? '') ?>">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-semibold mb-1">Nome da Subcategoria *</label>
                                                <input type="text" name="nome" required class="input-minimal w-full px-3 py-1.5 text-xs">
                                            </div>
                                            <button type="submit" class="px-5 py-2 rounded bg-slate-900 text-white text-xs font-semibold">Cadastrar Subcategoria</button>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                <?php if ($selectedType === 'assunto_new'): ?>
                                    <div class="space-y-4">
                                        <h2 class="text-base font-bold text-slate-900 dark:text-slate-100 pb-2 border-b">Novo Assunto Contextual</h2>
                                        <form method="POST" action="index.php?tab=editar_estrutura" class="space-y-3">
                                            <input type="hidden" name="save_subject" value="1">
                                            <div class="p-3 rounded bg-slate-50 dark:bg-[#2c2e33] border text-xs">
                                                <span class="text-slate-400 font-bold uppercase text-[10px] block">Subcategoria Pai:</span>
                                                <span class="font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5 mt-0.5">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                                                    <?= htmlspecialchars($_GET['sub'] ?? '') ?>
                                                </span>
                                                <input type="hidden" name="subcategoria_nome" value="<?= htmlspecialchars($_GET['sub'] ?? '') ?>">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-semibold mb-1">Nome do Assunto *</label>
                                                <input type="text" name="nome" required class="input-minimal w-full px-3 py-1.5 text-xs">
                                            </div>
                                            <button type="submit" class="px-5 py-2 rounded bg-slate-900 text-white text-xs font-semibold">Cadastrar Assunto</button>
                                        </form>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ABA 1: VISÃO GERAL -->
                <?php if ($activeTab === 'visao_geral'): ?>
                    <div class="space-y-6">
                        <div>
                            <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Visão Geral da Gestão de Documentos</h1>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Resumo quantitativo e acesso rápido às operações recentes do acervo municipal.</p>
                        </div>

                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
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
                                            <span class="text-[11px] text-slate-400 block"><?= htmlspecialchars($uDoc['categoria'] ?? 'Geral') ?> &rsaquo; <?= htmlspecialchars($uDoc['subcategoria'] ?? 'Geral') ?> &rsaquo; <?= htmlspecialchars($uDoc['assunto'] ?? 'Geral') ?> • Atualizado em <?= isset($uDoc['criado_em']) ? date('d/m/Y H:i', strtotime($uDoc['criado_em'])) : 'Recente' ?></span>
                                        </div>
                                        <a href="index.php?tab=detalhes_documento&id=<?= $uDoc['id'] ?>" class="text-xs font-semibold text-slate-600 dark:text-slate-300 hover:underline">Ver Detalhes &rarr;</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ABA 2: PÁGINA DOCUMENTOS (BUSCA, FILTROS, AÇÕES EM LOTE, TABELA E ⋯) -->
                <?php if ($activeTab === 'documentos'): ?>
                    <form method="POST" action="index.php?tab=documentos" id="batch-form" class="space-y-5">
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
                                        <option value="arquivo" <?= $filterTipo === 'arquivo' ? 'selected' : '' ?>>Arquivo</option>
                                        <option value="texto" <?= $filterTipo === 'texto' ? 'selected' : '' ?>>Texto</option>
                                        <option value="link" <?= $filterTipo === 'link' ? 'selected' : '' ?>>Link</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Status</label>
                                    <select name="filter_status" class="input-minimal w-full px-2 py-1.5 text-xs">
                                        <option value="">Todos</option>
                                        <option value="publicado" <?= $filterStatus === 'publicado' ? 'selected' : '' ?>>Publicado</option>
                                        <option value="rascunho" <?= $filterStatus === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                                        <option value="inativo" <?= $filterStatus === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- BARRA DE AÇÕES EM LOTE -->
                        <div class="flex items-center justify-between p-3 rounded-md bg-slate-100 dark:bg-[#2c2e33] border border-slate-200 dark:border-[#454956] text-xs">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-slate-700 dark:text-slate-300">Ações em Lote:</span>
                                <button type="submit" name="batch_action" value="publish" onclick="return confirm('Publicar os documentos selecionados?')" class="px-3 py-1 rounded bg-emerald-600 text-white font-semibold hover:opacity-90">
                                    Publicar Selecionados
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
                                                <?php $st = strtolower($doc['status'] ?: 'publicado'); ?>
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
                                                        <?php if ($st === 'publicado'): ?>
                                                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20">Publicado</span>
                                                        <?php elseif ($st === 'rascunho'): ?>
                                                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20">Rascunho</span>
                                                        <?php else: ?>
                                                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-slate-500/10 text-slate-600 dark:text-slate-400 border border-slate-500/20">Inativo</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="p-3 text-right relative">
                                                        <button type="button" onclick="toggleActionMenu(<?= $doc['id'] ?>, event)" class="px-2 py-1 rounded text-slate-500 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-[#454956] font-bold text-sm">
                                                            ⋯
                                                        </button>

                                                        <div id="action-menu-<?= $doc['id'] ?>" class="hidden absolute right-3 top-10 w-48 bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] rounded-md shadow-md py-1 z-50 text-left text-xs font-medium">
                                                            <a href="index.php?tab=detalhes_documento&id=<?= $doc['id'] ?>" class="block px-3 py-1.5 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">Visualizar Detalhes</a>
                                                            <a href="index.php?tab=novo_documento&action=edit_doc&id=<?= $doc['id'] ?>" class="block px-3 py-1.5 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">Editar Metadados</a>
                                                            <?php if ($doc['tipo_conteudo'] === 'arquivo'): ?>
                                                                <a href="index.php?tab=substituir_arquivo&id=<?= $doc['id'] ?>" class="block px-3 py-1.5 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">Substituir arquivo</a>
                                                            <?php endif; ?>
                                                            <div class="my-1 border-t border-slate-100 dark:border-[#454956]"></div>
                                                            <a href="index.php?tab=documentos&action=move_to_trash&id=<?= $doc['id'] ?>" onclick="return confirm('Mover este documento para a lixeira?')" class="block px-3 py-1.5 text-red-600 dark:text-red-400 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">
                                                                Mover para lixeira
                                                            </a>
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
                            <form method="POST" action="index.php?tab=categorias" class="space-y-3">
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
                            <form method="POST" action="index.php?tab=subcategorias" class="space-y-3">
                                <input type="hidden" name="save_subcategory" value="1">
                                <?php if ($editSub): ?>
                                    <input type="hidden" name="id" value="<?= $editSub['id'] ?>">
                                <?php endif; ?>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Categoria Pai *</label>
                                    <select name="categoria_nome" required class="input-minimal w-full px-3 py-1.5 text-xs">
                                        <?php foreach ($rawCategorias as $c): ?>
                                            <option value="<?= htmlspecialchars($c) ?>" <?= ($editSub['categoria_nome'] ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
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
                                    <select name="subcategoria_nome" required class="input-minimal w-full px-3 py-1.5 text-xs">
                                        <?php foreach ($listSubcategorias as $sub): ?>
                                            <option value="<?= htmlspecialchars($sub['nome']) ?>" <?= ($editAss['subcategoria_nome'] ?? '') === $sub['nome'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['nome']) ?></option>
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
                                                    <a href="index.php?tab=lixeira&action=restore_trash&id=<?= $lDoc['id'] ?>" class="text-emerald-600 font-semibold mr-3 hover:underline">Restaurar</a>
                                                    <a href="index.php?tab=lixeira&action=permanent_delete&id=<?= $lDoc['id'] ?>" onclick="return confirm('Atenção: Esta ação não poderá ser desfeita. Deseja realmente excluir permanentemente este documento e o arquivo do servidor?')" class="text-red-600 font-semibold hover:underline">Excluir Definitivamente</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <!-- NOVO CONTEÚDO (FORMULÁRIO DINÂMICO COM SELETOR DE TIPO) -->
                <?php if ($activeTab === 'novo_documento'): ?>
                    <?php
                        // Determine o tipo inicial: edição de documento ou tipo vindo da URL
                        $isEditMode = ($editDoc !== null);
                        $initialType = $isEditMode ? 'documento' : 'documento';
                        $isAdmin = ($loggedUser['role'] === 'admin');
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
                                <button type="button" id="nc-btn-subcategoria" onclick="ncSwitchType('subcategoria')" role="tab"
                                    class="nc-type-btn px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all duration-150 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                                    Subcategoria
                                </button>
                                <button type="button" id="nc-btn-assunto" onclick="ncSwitchType('assunto')" role="tab"
                                    class="nc-type-btn px-3.5 py-1.5 rounded-md text-xs font-semibold transition-all duration-150 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                    Assunto
                                </button>
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

                            <form method="POST" action="index.php?tab=novo_documento" enctype="multipart/form-data" class="space-y-6">
                                <input type="hidden" name="save_doc" value="1">
                                <?php if ($editDoc): ?>
                                    <input type="hidden" name="id" value="<?= $editDoc['id'] ?>">
                                <?php endif; ?>

                                <!-- Informações básicas -->
                                <div class="space-y-4">
                                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 pb-1 border-b border-slate-100 dark:border-[#454956]">Informações Principais</h3>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Título *</label>
                                        <input type="text" name="titulo" required value="<?= htmlspecialchars($editDoc['titulo'] ?? '') ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex: Requerimento Padrão de Férias 2026">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                        <textarea name="descricao" rows="2" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Resumo do documento..."><?= htmlspecialchars($editDoc['descricao'] ?? '') ?></textarea>
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
                                                <?php foreach ($categoriasAutorizadas as $catOption): ?>
                                                    <option value="<?= htmlspecialchars($catOption) ?>" <?= ($editDoc['categoria'] ?? ($_GET['cat'] ?? '')) === $catOption ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($catOption) ?>
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
                                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 pb-1 border-b border-slate-100 dark:border-[#454956]">Conteúdo</h3>
                                    <div class="inline-flex items-center bg-slate-100 dark:bg-[#2c2e33] rounded-md p-1 border border-slate-200 dark:border-[#454956] gap-1">
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="arquivo" <?= ($editDoc['tipo_conteudo'] ?? 'arquivo') === 'arquivo' ? 'checked' : '' ?> onchange="toggleFormContent('arquivo')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                            Arquivo
                                        </label>
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="texto" <?= ($editDoc['tipo_conteudo'] ?? '') === 'texto' ? 'checked' : '' ?> onchange="toggleFormContent('texto')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            Texto
                                        </label>
                                        <label class="doc-type-btn flex items-center gap-1.5 px-3 py-1.5 rounded cursor-pointer text-xs font-semibold transition-all">
                                            <input type="radio" name="tipo_conteudo" value="link" <?= ($editDoc['tipo_conteudo'] ?? '') === 'link' ? 'checked' : '' ?> onchange="toggleFormContent('link')" class="sr-only">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                            Link
                                        </label>
                                    </div>

                                    <div id="box-arquivo" class="p-6 rounded-md border-2 border-dashed border-slate-300 dark:border-slate-600 bg-slate-50/70 dark:bg-[#2c2e33] text-center">
                                        <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Arraste o arquivo aqui ou clique para selecionar</p>
                                        <p class="text-[11px] text-slate-400 mt-1 mb-3">Suportados: PDF, PNG, JPG, WEBP, GIF, TXT, DOCX (Máx: 15MB)</p>
                                        <input type="file" id="file-input" name="arquivo_file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.txt,.doc,.docx" class="hidden" onchange="updateFilePreview(this)">
                                        <button type="button" onclick="document.getElementById('file-input').click()" class="px-4 py-1.5 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold">Selecionar arquivo</button>
                                        <div id="file-preview-name" class="mt-2 text-xs text-slate-500"></div>
                                    </div>

                                    <div id="box-texto" class="hidden p-4 rounded-md border border-slate-200 dark:border-[#454956] bg-slate-50/70 dark:bg-[#2c2e33]">
                                        <textarea name="conteudo_html" rows="7" class="input-minimal w-full px-3 py-2 text-xs font-mono" placeholder="Conteúdo do artigo..."><?= htmlspecialchars($editDoc['conteudo_html'] ?? '') ?></textarea>
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
                                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Status *</label>
                                            <select name="status" class="input-minimal w-full px-3 py-2 text-xs">
                                                <option value="rascunho" <?= ($editDoc['status'] ?? 'rascunho') === 'rascunho' ? 'selected' : '' ?>>Rascunho (Padrão)</option>
                                                <option value="publicado" <?= ($editDoc['status'] ?? '') === 'publicado' ? 'selected' : '' ?>>Publicado</option>
                                                <option value="inativo" <?= ($editDoc['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                            </select>
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
                                </div>

                                <div class="pt-4 border-t border-slate-100 dark:border-[#454956] flex justify-end gap-2">
                                    <a href="index.php?tab=documentos" class="px-4 py-2 rounded border border-slate-200 dark:border-[#454956] text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-[#2c2e33] transition">Cancelar</a>
                                    <button type="submit" class="px-6 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90 transition"><?= $isEditMode ? 'Salvar Alterações' : 'Criar Documento' ?></button>
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

                            <form method="POST" action="index.php?tab=novo_documento" class="space-y-5">
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

                            <form method="POST" action="index.php?tab=novo_documento" class="space-y-5">
                                <input type="hidden" name="save_subcategory" value="1">
                                <input type="hidden" name="redirect_tab" value="novo_documento">

                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Categoria Pai *</label>
                                    <select id="nc-sub-cat" name="categoria_nome" required class="input-minimal w-full px-3 py-2 text-xs">
                                        <option value="">-- Selecione uma Categoria --</option>
                                        <?php foreach ($listCategorias as $catItem): ?>
                                            <?php if ($catItem['status'] === 'ativo'): ?>
                                            <option value="<?= htmlspecialchars($catItem['nome']) ?>"><?= htmlspecialchars($catItem['nome']) ?></option>
                                            <?php endif; ?>
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
                                        <?php foreach ($listCategorias as $catItem): ?>
                                            <?php if ($catItem['status'] === 'ativo'): ?>
                                            <option value="<?= htmlspecialchars($catItem['nome']) ?>"><?= htmlspecialchars($catItem['nome']) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Subcategoria *</label>
                                    <select id="nc-ass-subcat" name="subcategoria_nome" required class="input-minimal w-full px-3 py-2 text-xs">
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
                        <div class="bg-white dark:bg-[#353842] p-6 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs">
                            <?php if ($docDetails['tipo_conteudo'] === 'arquivo' && strpos($docDetails['tipo_mime'] ?? '', 'pdf') !== false): ?>
                                <iframe src="../download.php?id=<?= $docDetails['id'] ?>&inline=1" class="w-full h-[650px] rounded-md border border-slate-200 dark:border-[#454956]"></iframe>
                            <?php else: ?>
                                <div class="p-6 text-center bg-slate-50 dark:bg-[#2c2e33] rounded-md border text-xs">Visualização disponível no portal público.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'configuracoes'): ?>
                    <div class="bg-white dark:bg-[#353842] p-5 rounded-md border border-slate-200 dark:border-[#454956] shadow-xs max-w-xl">
                        <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100 mb-2">Configurações Gerais do Sistema</h2>
                        <div class="space-y-3 text-xs">
                            <div>
                                <label class="block font-semibold mb-1">Nome do Portal</label>
                                <input type="text" value="Portal de Documentos da Prefeitura" class="input-minimal w-full px-3 py-1.5 text-xs">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- TAB: EDITAR ESTRUTURA E PERMISSÕES DO RECURSO (CATEGORIA / SUBCATEGORIA / ASSUNTO) -->
                <?php if ($activeTab === 'editar_estrutura'): ?>
                    <?php
                        require_once __DIR__ . '/../services/PermissionService.php';
                        $permService = new PermissionService($pdo);

                        $resTypeInput = strtolower(trim($_GET['type'] ?? 'categoria'));
                        $resId = (int)($_GET['id'] ?? 0);
                        $resTab = trim($_GET['res_tab'] ?? 'permissions');

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

                        if ($resType === 'category') {
                            $resTypeNameLabel = 'Categoria';
                            $stmtR = $pdo->prepare("SELECT id, name, description, active FROM categories WHERE id = ?");
                            $stmtR->execute([$resId]);
                            $resData = $stmtR->fetch(PDO::FETCH_ASSOC);
                        } elseif ($resType === 'subcategory') {
                            $resTypeNameLabel = 'Subcategoria';
                            $stmtR = $pdo->prepare("
                                SELECT sc.id, sc.category_id, sc.name, sc.description, sc.active, c.name AS category_name
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
                        } elseif ($resType === 'subject') {
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

                        if (!$resData) {
                            echo "<div class='p-4 bg-red-500/10 text-red-600 rounded-md text-xs font-semibold'>Recurso não encontrado.</div>";
                        } else {
                    ?>
                    <div class="space-y-4">
                        <!-- BREADCRUMB E CABEÇALHO DA PASTA/RECURSO -->
                        <div>
                            <nav class="flex items-center gap-1.5 text-xs text-slate-400 font-medium mb-1">
                                <a href="index.php?tab=categorias" class="hover:underline">Gestão da Estrutura</a>
                                <?php foreach ($parentBreadcrumbs as $bc): ?>
                                    <span>/</span>
                                    <a href="index.php?tab=editar_estrutura&type=<?= $bc['type'] ?>&id=<?= $bc['id'] ?>&res_tab=permissions" class="hover:underline"><?= htmlspecialchars($bc['name']) ?></a>
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
                            <a href="index.php?tab=editar_estrutura&type=<?= $resTypeInput ?>&id=<?= $resId ?>&res_tab=permissions" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $resTab === 'permissions' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Permissões
                            </a>
                        </div>

                        <!-- ABA 1: INFORMAÇÕES -->
                        <?php if ($resTab === 'info'): ?>
                            <div class="bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] max-w-xl shadow-xs">
                                <?php if ($resType === 'category'): ?>
                                    <form method="POST" action="index.php?tab=categorias" class="space-y-4">
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
                                    <form method="POST" action="index.php?tab=subcategorias" class="space-y-4">
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

                        <!-- ABA 3: PERMISSÕES (ESTILO GRAFANA FOLDER PERMISSIONS) -->
                        <?php if ($resTab === 'permissions'): ?>
                            <?php
                                $canAdminFolder = $permService->canAdmin((int)($loggedUser['id'] ?? 0), $resType, $resId) || $permService->isGlobalAdmin((int)($loggedUser['id'] ?? 0));
                                if (!$canAdminFolder):
                            ?>
                                <div class="p-6 bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 rounded-md text-xs font-semibold">
                                    Acesso Negado: É necessário privilégio Admin nesta pasta (ou ser Administrador Global) para gerenciar permissões de acesso.
                                </div>
                            <?php else: ?>
                                <?php
                                    $resourcePermissions = $permService->getResourcePermissions($resType, $resId);

                                    // Buscar usuários e grupos para o modal de nova permissão
                                    $allUsersList = $pdo->query("SELECT id, name, username, email FROM users WHERE active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                                    $allGroupsList = $pdo->query("SELECT id, name, description FROM groups WHERE active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                <div class="bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] shadow-xs space-y-4">
                                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-[#454956]">
                                    <div>
                                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">Gerenciar permissões</h3>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Quem possui acesso a esta área.</p>
                                    </div>
                                    <button type="button" onclick="openAddPermModal()" class="bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold px-3.5 py-2 rounded hover:bg-slate-800 transition flex items-center gap-1.5 shadow-xs">
                                        <span>+ Adicionar permissão</span>
                                    </button>
                                </div>

                                <!-- TABELA DE PERMISSÕES -->
                                <?php if (empty($resourcePermissions)): ?>
                                    <div class="p-8 text-center text-slate-400 text-xs bg-slate-50 dark:bg-[#2c2e33] rounded">
                                        Nenhuma permissão específica configurada nesta pasta ou herdada de seus ancestrais.
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-left text-xs border-collapse">
                                            <thead>
                                                <tr class="bg-slate-50 dark:bg-[#2c2e33] border-b border-slate-200 dark:border-[#454956] text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                                    <th class="py-2.5 px-4">Principal</th>
                                                    <th class="py-2.5 px-4 text-center w-28">Tipo</th>
                                                    <th class="py-2.5 px-4 text-center w-36">Permissão</th>
                                                    <th class="py-2.5 px-4 text-left w-56">Origem</th>
                                                    <th class="py-2.5 px-4 text-right w-24">Ações</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                                <?php foreach ($resourcePermissions as $rPerm): ?>
                                                    <tr class="hover:bg-slate-50/70 dark:hover:bg-[#2c2e33]/50 transition">
                                                        <!-- PRINCIPAL (NOME + DETALHES) -->
                                                        <td class="py-2.5 px-4">
                                                            <div class="flex items-center gap-2.5">
                                                                <div class="w-7 h-7 rounded-full bg-slate-100 dark:bg-[#2c2e33] font-bold text-xs flex items-center justify-center text-slate-700 dark:text-slate-300 shrink-0">
                                                                    <?= mb_strtoupper(mb_substr($rPerm['principal_name'], 0, 1)) ?>
                                                                </div>
                                                                <div>
                                                                    <span class="font-bold text-slate-900 dark:text-slate-100 block leading-tight">
                                                                        <?= htmlspecialchars($rPerm['principal_name']) ?>
                                                                    </span>
                                                                    <span class="text-[10px] text-slate-400 font-mono block leading-tight">
                                                                        <?= htmlspecialchars($rPerm['principal_subtext']) ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </td>

                                                        <!-- TIPO (USUÁRIO / GRUPO) -->
                                                        <td class="py-2.5 px-4 text-center">
                                                            <?php if ($rPerm['principal_type'] === 'group'): ?>
                                                                <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-purple-500/15 text-purple-700 dark:text-purple-300 border border-purple-500/30 uppercase">Grupo</span>
                                                            <?php else: ?>
                                                                <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-sky-500/15 text-sky-700 dark:text-sky-300 border border-sky-500/30 uppercase">Usuário</span>
                                                            <?php endif; ?>
                                                        </td>

                                                        <!-- PERMISSÃO (VIEW / EDIT / ADMIN) -->
                                                        <td class="py-2.5 px-4 text-center">
                                                            <?php if ($rPerm['is_direct']): ?>
                                                                <!-- ALTERAÇÃO DIRETA DO NÍVEL -->
                                                                <form method="POST" action="index.php?tab=editar_estrutura&type=<?= $resTypeInput ?>&id=<?= $resId ?>&res_tab=permissions" class="inline">
                                                                    <input type="hidden" name="resource_permission_action" value="update_level">
                                                                    <input type="hidden" name="resource_type" value="<?= $resType ?>">
                                                                    <input type="hidden" name="resource_id" value="<?= $resId ?>">
                                                                    <input type="hidden" name="permission_id" value="<?= $rPerm['permission_id'] ?>">
                                                                    <select name="permission_level" onchange="this.form.submit()" class="text-xs font-semibold px-2 py-1 rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 cursor-pointer">
                                                                        <option value="view" <?= $rPerm['permission_level'] === 'view' ? 'selected' : '' ?>>View</option>
                                                                        <option value="edit" <?= $rPerm['permission_level'] === 'edit' ? 'selected' : '' ?>>Edit</option>
                                                                        <option value="admin" <?= $rPerm['permission_level'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                                    </select>
                                                                </form>
                                                            <?php else: ?>
                                                                <!-- LEITURA DE PERMISSÃO HERDADA -->
                                                                <?php
                                                                    $lvlClass = 'bg-slate-100 text-slate-700';
                                                                    if ($rPerm['permission_level'] === 'view') $lvlClass = 'bg-blue-500/15 text-blue-700 border-blue-500/30';
                                                                    if ($rPerm['permission_level'] === 'edit') $lvlClass = 'bg-amber-500/15 text-amber-700 border-amber-500/30';
                                                                    if ($rPerm['permission_level'] === 'admin') $lvlClass = 'bg-red-500/15 text-red-700 border-red-500/30';
                                                                ?>
                                                                <span class="px-2.5 py-0.5 text-[10px] font-bold rounded border uppercase <?= $lvlClass ?>">
                                                                    <?= strtoupper($rPerm['permission_level']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>

                                                        <!-- ORIGEM (DIRETA OU HERDADA DE PASTOR PAI COM "VER ORIGEM") -->
                                                        <td class="py-2.5 px-4 text-left">
                                                            <?php if ($rPerm['is_direct']): ?>
                                                                <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30 uppercase">
                                                                    Direta
                                                                </span>
                                                            <?php else: ?>
                                                                <div class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                                                    <span><?= htmlspecialchars($rPerm['origin_label']) ?></span>
                                                                    <?php if (!empty($rPerm['ancestor_info'])): ?>
                                                                        <a href="index.php?tab=editar_estrutura&type=<?= $rPerm['ancestor_info']['type'] ?>&id=<?= $rPerm['ancestor_info']['id'] ?>&res_tab=permissions" class="text-[11px] font-semibold text-amber-600 dark:text-amber-400 hover:underline">
                                                                            Ver origem
                                                                        </a>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>

                                                        <!-- AÇÕES (EXCLUIR REGRA DIRETA) -->
                                                        <td class="py-2.5 px-4 text-right">
                                                            <?php if ($rPerm['is_direct']): ?>
                                                                <form method="POST" action="index.php?tab=editar_estrutura&type=<?= $resTypeInput ?>&id=<?= $resId ?>&res_tab=permissions" onsubmit="return confirm('Deseja remover a permissão direta de <?= htmlspecialchars(addslashes($rPerm['principal_name'])) ?> nesta pasta?');" class="inline">
                                                                    <input type="hidden" name="resource_permission_action" value="delete_permission">
                                                                    <input type="hidden" name="resource_type" value="<?= $resType ?>">
                                                                    <input type="hidden" name="resource_id" value="<?= $resId ?>">
                                                                    <input type="hidden" name="permission_id" value="<?= $rPerm['permission_id'] ?>">
                                                                    <button type="submit" class="px-2 py-1 rounded bg-red-500/10 text-red-600 hover:bg-red-500/20 text-[11px] font-semibold" title="Remover Permissão Direta">
                                                                        Remover
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span class="text-slate-300 dark:text-slate-600 text-xs">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <!-- MODAL DE INCLUSÃO DE NOVA PERMISSÃO (REFINADO E ACESSÍVEL) -->
                                <div id="modal-add-perm" class="hidden fixed inset-0 z-50 bg-slate-950/50 backdrop-blur-xs flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="modal-add-perm-title">
                                    <div id="modal-add-perm-card" class="bg-white dark:bg-[#353842] max-w-md w-full p-5 rounded-lg border border-slate-200 dark:border-[#454956] shadow-xl space-y-4 text-xs">
                                        
                                        <!-- CABEÇALHO DO MODAL -->
                                        <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-[#454956]">
                                            <h3 id="modal-add-perm-title" class="text-sm font-bold text-slate-900 dark:text-slate-100">Conceder acesso para</h3>
                                            <button type="button" onclick="closeAddPermModal()" class="text-slate-400 hover:text-slate-600 focus:outline-hidden text-sm p-1 rounded" aria-label="Fechar modal">✕</button>
                                        </div>

                                        <form method="POST" action="index.php?tab=editar_estrutura&type=<?= $resTypeInput ?>&id=<?= $resId ?>&res_tab=permissions" id="form-add-perm" class="space-y-4">
                                            <input type="hidden" name="resource_permission_action" value="add_permission">
                                            <input type="hidden" name="resource_type" value="<?= $resType ?>">
                                            <input type="hidden" name="resource_id" value="<?= $resId ?>">
                                            <input type="hidden" name="principal_id" id="input-selected-principal-id" value="">

                                            <!-- SELETOR DE TIPO: SEGMENTED BUTTONS [ Usuário | Grupo ] -->
                                            <div>
                                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Tipo de acesso</label>
                                                <div class="grid grid-cols-2 gap-1 p-1 bg-slate-100 dark:bg-[#2c2e33] rounded-md border border-slate-200 dark:border-[#454956]">
                                                    <button type="button" id="btn-type-user" onclick="selectPrincipalType('user')" class="py-1.5 font-bold rounded text-center transition">
                                                        Usuário
                                                    </button>
                                                    <button type="button" id="btn-type-group" onclick="selectPrincipalType('group')" class="py-1.5 font-bold rounded text-center transition">
                                                        Grupo
                                                    </button>
                                                </div>
                                                <input type="hidden" name="principal_type" id="input-principal-type" value="group">
                                            </div>

                                            <!-- CAMPO DE PESQUISA EM TEMPO REAL NO POSTGRESQL -->
                                            <div>
                                                <label for="perm-search-input" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Pesquisar</label>
                                                <div class="relative">
                                                    <input type="text" id="perm-search-input" placeholder="Buscar por nome, username, email..." oninput="onSearchInput()" class="input-minimal w-full pl-8 pr-3 py-2 rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100">
                                                    <svg class="w-4 h-4 text-slate-400 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                                </div>
                                            </div>

                                            <!-- LISTA DE RESULTADOS DA PESQUISA COM RADIO BUTTONS -->
                                            <div>
                                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Resultados</label>
                                                <div id="perm-search-results" class="max-h-40 overflow-y-auto divide-y divide-slate-100 dark:divide-[#454956] border border-slate-200 dark:border-[#454956] rounded bg-slate-50/50 dark:bg-[#2c2e33]/50 p-1">
                                                    <div class="p-3 text-center text-slate-400 text-[11px]">Buscando no PostgreSQL...</div>
                                                </div>
                                            </div>

                                            <!-- ALERTA DE PERMISSÃO DIRETA JÁ EXISTENTE -->
                                            <div id="box-existing-perm-warning" class="hidden p-2.5 bg-amber-500/10 border border-amber-500/30 rounded text-amber-700 dark:text-amber-300 text-[11px]">
                                                <div class="flex items-start gap-1.5">
                                                    <svg class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <div>
                                                        <strong class="block font-bold">Permissão direta já existente:</strong>
                                                        Este principal já possui permissão <span id="text-existing-level" class="font-bold uppercase"></span> nesta pasta. Ao salvar, a permissão existente será alterada para o novo nível selecionado.
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- SELEÇÃO DE NÍVEIS DE PERMISSÃO COM DESCRIÇÕES EXPLICATIVAS EXIGIDAS -->
                                            <div>
                                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Permissão</label>
                                                <div class="space-y-2">
                                                    <label class="flex items-start gap-2.5 p-2 rounded border border-slate-200 dark:border-[#454956] hover:bg-slate-50 dark:hover:bg-[#2c2e33] cursor-pointer">
                                                        <input type="radio" name="permission_level" value="view" checked class="mt-0.5 border-slate-300 text-slate-900 focus:ring-0">
                                                        <div>
                                                            <span class="font-bold text-slate-900 dark:text-slate-100 block">View</span>
                                                            <span class="text-[11px] text-slate-500 dark:text-slate-400 block">Pode visualizar conteúdo.</span>
                                                        </div>
                                                    </label>

                                                    <label class="flex items-start gap-2.5 p-2 rounded border border-slate-200 dark:border-[#454956] hover:bg-slate-50 dark:hover:bg-[#2c2e33] cursor-pointer">
                                                        <input type="radio" name="permission_level" value="edit" class="mt-0.5 border-slate-300 text-slate-900 focus:ring-0">
                                                        <div>
                                                            <span class="font-bold text-slate-900 dark:text-slate-100 block">Edit</span>
                                                            <span class="text-[11px] text-slate-500 dark:text-slate-400 block">Pode visualizar e editar conteúdo.</span>
                                                        </div>
                                                    </label>

                                                    <label class="flex items-start gap-2.5 p-2 rounded border border-slate-200 dark:border-[#454956] hover:bg-slate-50 dark:hover:bg-[#2c2e33] cursor-pointer">
                                                        <input type="radio" name="permission_level" value="admin" class="mt-0.5 border-slate-300 text-slate-900 focus:ring-0">
                                                        <div>
                                                            <span class="font-bold text-slate-900 dark:text-slate-100 block">Admin</span>
                                                            <span class="text-[11px] text-slate-500 dark:text-slate-400 block">Pode gerenciar conteúdo e permissões desta área.</span>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>

                                            <!-- AVISO DE HERANÇA SOLICITADO -->
                                            <div class="p-2 bg-blue-500/10 border border-blue-500/20 rounded text-blue-700 dark:text-blue-300 text-[11px] flex items-center gap-1.5">
                                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                <span>Esta permissão será herdada pelos itens abaixo desta área.</span>
                                            </div>

                                            <!-- BOTÕES: [Cancelar] [Adicionar permissão] -->
                                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-[#454956]">
                                                <button type="button" onclick="closeAddPermModal()" class="px-4 py-2 rounded font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">
                                                    Cancelar
                                                </button>
                                                <button type="submit" id="btn-submit-add-perm" disabled class="px-4 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed">
                                                    Adicionar permissão
                                                </button>
                                            </div>
                                        </form>

                                        <script>
                                            let currentPrincipalType = 'group';
                                            let searchDebounceTimer = null;
                                            let lastFocusedElement = null;

                                            function openAddPermModal() {
                                                lastFocusedElement = document.activeElement;
                                                const modal = document.getElementById('modal-add-perm');
                                                modal.classList.remove('hidden');
                                                selectPrincipalType('group');
                                                document.getElementById('perm-search-input').focus();

                                                // Adicionar listener de tecla ESC e Focus Trap
                                                document.addEventListener('keydown', handleModalKeyDown);
                                            }

                                            function closeAddPermModal() {
                                                const modal = document.getElementById('modal-add-perm');
                                                modal.classList.add('hidden');
                                                document.removeEventListener('keydown', handleModalKeyDown);
                                                if (lastFocusedElement) {
                                                    lastFocusedElement.focus();
                                                }
                                            }

                                            function handleModalKeyDown(e) {
                                                if (e.key === 'Escape') {
                                                    closeAddPermModal();
                                                    return;
                                                }
                                                if (e.key === 'Tab') {
                                                    const modalCard = document.getElementById('modal-add-perm-card');
                                                    const focusables = modalCard.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])');
                                                    if (focusables.length === 0) return;
                                                    const first = focusables[0];
                                                    const last = focusables[focusables.length - 1];

                                                    if (e.shiftKey) {
                                                        if (document.activeElement === first) {
                                                            last.focus();
                                                            e.preventDefault();
                                                        }
                                                    } else {
                                                        if (document.activeElement === last) {
                                                            first.focus();
                                                            e.preventDefault();
                                                        }
                                                    }
                                                }
                                            }

                                            function selectPrincipalType(type) {
                                                currentPrincipalType = type;
                                                document.getElementById('input-principal-type').value = type;

                                                const btnUser = document.getElementById('btn-type-user');
                                                const btnGroup = document.getElementById('btn-type-group');

                                                if (type === 'user') {
                                                    btnUser.className = 'py-1.5 font-bold rounded text-center bg-white dark:bg-[#353842] text-slate-900 dark:text-white shadow-xs';
                                                    btnGroup.className = 'py-1.5 font-bold rounded text-center text-slate-500 hover:text-slate-800 dark:hover:text-slate-200';
                                                    document.getElementById('perm-search-input').placeholder = 'Buscar por nome, username, email...';
                                                } else {
                                                    btnGroup.className = 'py-1.5 font-bold rounded text-center bg-white dark:bg-[#353842] text-slate-900 dark:text-white shadow-xs';
                                                    btnUser.className = 'py-1.5 font-bold rounded text-center text-slate-500 hover:text-slate-800 dark:hover:text-slate-200';
                                                    document.getElementById('perm-search-input').placeholder = 'Buscar por nome do grupo...';
                                                }

                                                // Resetar seleção e alerta
                                                document.getElementById('input-selected-principal-id').value = '';
                                                document.getElementById('btn-submit-add-perm').disabled = true;
                                                document.getElementById('box-existing-perm-warning').classList.add('hidden');

                                                fetchPrincipals();
                                            }

                                            function onSearchInput() {
                                                clearTimeout(searchDebounceTimer);
                                                searchDebounceTimer = setTimeout(() => {
                                                    fetchPrincipals();
                                                }, 250);
                                            }

                                            function fetchPrincipals() {
                                                const q = encodeURIComponent(document.getElementById('perm-search-input').value.trim());
                                                const resType = '<?= $resType ?>';
                                                const resId = '<?= $resId ?>';
                                                const container = document.getElementById('perm-search-results');

                                                container.innerHTML = '<div class="p-3 text-center text-slate-400 text-[11px]">Buscando no PostgreSQL...</div>';

                                                fetch(`api/search_principals.php?type=${currentPrincipalType}&q=${q}&resource_type=${resType}&resource_id=${resId}`)
                                                    .then(res => res.json())
                                                    .then(res => {
                                                        if (!res.success) {
                                                            container.innerHTML = `<div class="p-3 text-center text-red-500 text-[11px]">${res.error || 'Erro ao carregar'}</div>`;
                                                            return;
                                                        }

                                                        if (res.data.length === 0) {
                                                            container.innerHTML = '<div class="p-3 text-center text-slate-400 text-[11px]">Nenhum principal encontrado.</div>';
                                                            return;
                                                        }

                                                        let html = '';
                                                        res.data.forEach(item => {
                                                            const icon = item.type === 'group' 
                                                                ? `<svg class="w-4 h-4 text-slate-400 inline shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>`
                                                                : `<svg class="w-4 h-4 text-slate-400 inline shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>`;
                                                            const existingAttr = item.existing_level ? `data-existing="${item.existing_level}"` : '';
                                                            html += `
                                                                <label class="flex items-center justify-between p-2 rounded hover:bg-slate-100 dark:hover:bg-[#2c2e33] cursor-pointer transition">
                                                                    <div class="flex items-center gap-2">
                                                                        <input type="radio" name="principal_radio" value="${item.id}" ${existingAttr} onchange="onSelectPrincipal(${item.id}, '${item.existing_level || ''}')" class="border-slate-300 text-slate-900 focus:ring-0">
                                                                        <div>
                                                                            <span class="font-bold text-slate-900 dark:text-slate-100 block">${icon} ${escapeHtml(item.name)}</span>
                                                                            <span class="text-[10px] text-slate-400 block">${escapeHtml(item.subtext)}</span>
                                                                        </div>
                                                                    </div>
                                                                    ${item.existing_level ? `<span class="px-1.5 py-0.5 text-[9px] font-bold rounded bg-amber-500/15 text-amber-700 dark:text-amber-300 uppercase">Já possui: ${item.existing_level}</span>` : ''}
                                                                </label>
                                                            `;
                                                        });

                                                        container.innerHTML = html;
                                                    })
                                                    .catch(err => {
                                                        container.innerHTML = '<div class="p-3 text-center text-red-500 text-[11px]">Erro ao conectar à API.</div>';
                                                    });
                                            }

                                            function onSelectPrincipal(id, existingLevel) {
                                                document.getElementById('input-selected-principal-id').value = id;
                                                document.getElementById('btn-submit-add-perm').disabled = false;

                                                const warningBox = document.getElementById('box-existing-perm-warning');
                                                const levelSpan = document.getElementById('text-existing-level');

                                                if (existingLevel) {
                                                    levelSpan.textContent = existingLevel;
                                                    warningBox.classList.remove('hidden');
                                                } else {
                                                    warningBox.classList.add('hidden');
                                                }
                                            }

                                            function escapeHtml(str) {
                                                return (str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
                                            }
                                        </script>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>

                    </div>
                    <?php } ?>
                <?php endif; ?>

                <!-- TAB: LISTAGEM DE GRUPOS DE ACESSO -->
                <?php if ($activeTab === 'grupos'): ?>
                    <?php
                        if (($loggedUser['role'] ?? '') !== 'admin') {
                            echo "<div class='p-4 bg-red-500/10 text-red-600 rounded-md text-xs font-semibold'>Apenas administradores podem gerenciar grupos de acesso.</div>";
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
                                    <span class="font-bold text-slate-900 dark:text-slate-100">Grupos</span>
                                </nav>
                                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Grupos de Acesso</h1>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Gerencie os grupos de permissão e associados da organização.</p>
                            </div>
                            <button type="button" onclick="document.getElementById('modal-novo-grupo').classList.remove('hidden')" class="bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold px-3.5 py-2 rounded hover:bg-slate-800 transition flex items-center gap-1.5 shadow-xs">
                                <span>+ Novo Grupo</span>
                            </button>
                        </div>

                        <!-- TABELA DENSA DE GRUPOS (ESTILO GRAFANA / COMPACTA) -->
                        <?php if (empty($groupsList)): ?>
                            <div class="p-8 text-center bg-white dark:bg-[#353842] rounded border border-slate-200 dark:border-[#454956]">
                                <p class="text-xs text-slate-400">Nenhum grupo de acesso cadastrado até o momento.</p>
                            </div>
                        <?php else: ?>
                            <div class="bg-white dark:bg-[#353842] rounded border border-slate-200 dark:border-[#454956] shadow-xs overflow-hidden">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs border-collapse">
                                        <thead>
                                            <tr class="bg-slate-50 dark:bg-[#2c2e33] border-b border-slate-200 dark:border-[#454956] text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                                <th class="py-2.5 px-4">Grupo</th>
                                                <th class="py-2.5 px-4 text-center w-28">Usuários</th>
                                                <th class="py-2.5 px-4 text-center w-28">Permissões</th>
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
                                                        <a href="index.php?tab=editar_grupo&id=<?= $grp['id'] ?>&group_tab=permissions" class="inline-flex items-center gap-1 font-semibold px-2 py-0.5 rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-700 dark:text-slate-300 hover:bg-slate-200">
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
                                                                <input type="hidden" name="group_id" value="<?= $grp['id'] ?>">
                                                                <button type="submit" class="px-2 py-1 rounded bg-slate-100 dark:bg-[#2c2e33] text-slate-700 dark:text-slate-300 hover:bg-slate-200 text-[11px] font-semibold" title="Alternar Status">
                                                                    <?= $grp['ativo'] ? 'Desativar' : 'Ativar' ?>
                                                                </button>
                                                            </form>
                                                            <form method="POST" action="index.php?tab=grupos" onsubmit="return confirm('Tem certeza que deseja excluir o grupo <?= htmlspecialchars(addslashes($grp['nome'])) ?>?');" class="inline">
                                                                <input type="hidden" name="group_action" value="delete_group">
                                                                <input type="hidden" name="group_id" value="<?= $grp['id'] ?>">
                                                                <button type="submit" class="px-2 py-1 rounded bg-red-500/10 text-red-600 hover:bg-red-500/20 text-[11px] font-semibold" title="Excluir Grupo">
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
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">Criar Novo Grupo de Acesso</h3>
                                    <button type="button" onclick="document.getElementById('modal-novo-grupo').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
                                </div>
                                <form method="POST" action="index.php?tab=grupos" class="space-y-4">
                                    <input type="hidden" name="group_action" value="create_group">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome do Grupo *</label>
                                        <input type="text" name="name" required placeholder="Ex: Recursos Humanos, Tecnologia" class="input-minimal w-full px-3 py-2 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                        <textarea name="description" rows="2" placeholder="Finalidade ou setor atendido pelo grupo..." class="input-minimal w-full px-3 py-2 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100"></textarea>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" name="active" value="1" id="active_new" checked class="rounded border-slate-300">
                                        <label for="active_new" class="text-xs font-medium text-slate-700 dark:text-slate-300">Grupo Ativo</label>
                                    </div>
                                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-[#454956]">
                                        <button type="button" onclick="document.getElementById('modal-novo-grupo').classList.add('hidden')" class="px-3 py-1.5 rounded text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">Cancelar</button>
                                        <button type="submit" class="px-4 py-1.5 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold hover:opacity-90">Salvar Grupo</button>
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
                        if (!in_array($groupTab, ['info', 'users', 'permissions'])) {
                            $groupTab = 'info';
                        }

                        $stmtSelG = $pdo->prepare("SELECT * FROM groups WHERE id = ?");
                        $stmtSelG->execute([$groupId]);
                        $grpData = $stmtSelG->fetch(PDO::FETCH_ASSOC);

                        if (!$grpData) {
                            echo "<div class='p-4 bg-red-500/10 text-red-600 rounded-md text-xs font-semibold'>Grupo não encontrado.</div>";
                        } else {
                    ?>
                    <div class="space-y-4">
                        <!-- BREADCRUMB E CABEÇALHO -->
                        <div>
                            <nav class="flex items-center gap-1.5 text-xs text-slate-400 font-medium mb-1">
                                <a href="index.php?tab=grupos" class="hover:underline">Gestão de Acesso</a>
                                <span>/</span>
                                <a href="index.php?tab=grupos" class="hover:underline">Grupos</a>
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

                        <!-- ABAS SOLICITADAS: [ Informações ] [ Membros ] [ Permissões ] -->
                        <div class="flex items-center gap-2 border-b border-slate-200 dark:border-[#454956]">
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=info" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'info' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Informações
                            </a>
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=users" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'users' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Membros
                            </a>
                            <a href="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=permissions" class="px-4 py-2 text-xs font-bold border-b-2 transition <?= $groupTab === 'permissions' ? 'border-slate-900 dark:border-white text-slate-900 dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300' ?>">
                                Permissões
                            </a>
                        </div>

                        <!-- CONTEÚDO DA ABA 1: INFORMAÇÕES -->
                        <?php if ($groupTab === 'info'): ?>
                            <div class="bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] max-w-xl shadow-xs">
                                <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=info" class="space-y-4">
                                    <input type="hidden" name="group_action" value="edit_group">
                                    <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nome do Grupo *</label>
                                        <input type="text" name="name" required value="<?= htmlspecialchars($grpData['name']) ?>" class="input-minimal w-full px-3 py-2 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Descrição</label>
                                        <textarea name="description" rows="3" class="input-minimal w-full px-3 py-2 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100"><?= htmlspecialchars($grpData['description']) ?></textarea>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" name="active" value="1" id="active_edit" <?= $grpData['active'] ? 'checked' : '' ?> class="rounded border-slate-300">
                                        <label for="active_edit" class="text-xs font-medium text-slate-700 dark:text-slate-300">Grupo Ativo</label>
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
                                    WHERE u.id NOT IN (SELECT user_id FROM user_groups WHERE group_id = ?)
                                    ORDER BY u.name ASC
                                ");
                                $stmtAvailable->execute([$groupId]);
                                $availableUsers = $stmtAvailable->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                                
                                <!-- COLUNA ESQUERDA: MEMBROS DO GRUPO -->
                                <div class="lg:col-span-7 bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] shadow-xs space-y-4">
                                    <div class="flex items-center justify-between">
                                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                            Membros Atuais (<?= count($groupUsers) ?>)
                                        </h3>
                                    </div>

                                    <?php if (empty($groupUsers)): ?>
                                        <p class="text-xs text-slate-400 text-center py-6">Nenhum usuário associado a este grupo ainda.</p>
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
                                                    <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=users" onsubmit="return confirm('Remover <?= htmlspecialchars($uMember['name']) ?> deste grupo? (O usuário não será excluído do sistema)');">
                                                        <input type="hidden" name="group_action" value="remove_user">
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
                                        Adicionar Membro ao Grupo
                                    </h3>

                                    <?php if (empty($availableUsers)): ?>
                                        <p class="text-xs text-slate-400">Todos os usuários cadastrados já pertencem a este grupo.</p>
                                    <?php else: ?>
                                        <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=users" class="space-y-3">
                                            <input type="hidden" name="group_action" value="add_user">
                                            <input type="hidden" name="group_id" value="<?= $groupId ?>">

                                            <div>
                                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Pesquisar Usuário</label>
                                                <input type="text" id="user-search-filter" oninput="filterUserOptions(this.value)" placeholder="Digite nome, username ou e-mail..." class="input-minimal w-full px-3 py-1.5 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 mb-2">
                                                
                                                <select name="user_id" id="user-select-list" required size="5" class="w-full text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 p-1">
                                                    <?php foreach ($availableUsers as $availU): ?>
                                                        <option value="<?= $availU['id'] ?>" data-search="<?= mb_strtolower($availU['name'] . ' ' . $availU['username'] . ' ' . $availU['email']) ?>">
                                                            <?= htmlspecialchars($availU['name']) ?> (@<?= htmlspecialchars($availU['username']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <button type="submit" class="w-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold py-2 rounded hover:opacity-90 transition">
                                                Adicionar ao Grupo &rarr;
                                            </button>
                                        </form>

                                        <script>
                                            function filterUserOptions(query) {
                                                const q = query.toLowerCase().trim();
                                                const select = document.getElementById('user-select-list');
                                                if (!select) return;
                                                Array.from(select.options).forEach(opt => {
                                                    const text = opt.getAttribute('data-search') || '';
                                                    opt.style.display = (!q || text.includes(q)) ? '' : 'none';
                                                });
                                            }
                                        </script>
                                    <?php endif; ?>
                                </div>

                            </div>
                        <?php endif; ?>

                        <!-- CONTEÚDO DA ABA 3: PERMISSÕES (VISÃO DO GRUPO) -->
                        <?php if ($groupTab === 'permissions'): ?>
                            <?php
                                $showInherited = isset($_GET['show_inherited']) && $_GET['show_inherited'] == '1';
                                $groupPermissions = $permService->getGroupPermissions($groupId, $showInherited);
                                $resourceTree = $permService->getResourceTree();
                            ?>
                            <div class="bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] shadow-xs space-y-4">
                                <!-- CABEÇALHO DA TABELA E CONTROLES -->
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100 dark:border-[#454956]">
                                    <div>
                                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">Recursos acessíveis por este grupo</h3>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Áreas e pastas com permissões concedidas a <strong><?= htmlspecialchars($grpData['name']) ?></strong>.</p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <!-- TOGGLE / CHECKBOX: MOSTRAR ACESSOS HERDADOS -->
                                        <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 cursor-pointer bg-slate-100 dark:bg-[#2c2e33] px-2.5 py-1.5 rounded border border-slate-200 dark:border-[#454956]">
                                            <input type="checkbox" onchange="window.location.href='index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=permissions&show_inherited=' + (this.checked ? '1' : '0')" <?= $showInherited ? 'checked' : '' ?> class="rounded border-slate-300">
                                            <span>Mostrar acessos herdados</span>
                                        </label>

                                        <!-- BOTÃO: + ADICIONAR ACESSO -->
                                        <button type="button" onclick="openAddGroupAccessModal()" class="bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold px-3.5 py-2 rounded hover:bg-slate-800 transition flex items-center gap-1.5 shadow-xs">
                                            <span>+ Adicionar acesso</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- TABELA DE ACESSOS DO GRUPO -->
                                <?php if (empty($groupPermissions)): ?>
                                    <div class="p-8 text-center text-slate-400 text-xs bg-slate-50 dark:bg-[#2c2e33] rounded">
                                        Nenhum acesso configurado diretamente para este grupo<?= $showInherited ? ' ou herdado de pastas pai' : '' ?>.
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-left text-xs border-collapse">
                                            <thead>
                                                <tr class="bg-slate-50 dark:bg-[#2c2e33] border-b border-slate-200 dark:border-[#454956] text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                                    <th class="py-2.5 px-4">Recurso</th>
                                                    <th class="py-2.5 px-4 text-center w-36">Permissão</th>
                                                    <th class="py-2.5 px-4 text-left w-56">Origem</th>
                                                    <th class="py-2.5 px-4 text-right w-24">Ações</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                                                <?php foreach ($groupPermissions as $gPerm): ?>
                                                    <tr class="hover:bg-slate-50/70 dark:hover:bg-[#2c2e33]/50 transition">
                                                        <!-- RECURSO (NOME DO CAMINHO + TIPO) -->
                                                        <td class="py-2.5 px-4">
                                                            <div class="flex items-center gap-2">
                                                                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                                                <div>
                                                                    <span class="font-bold text-slate-900 dark:text-slate-100 block leading-tight">
                                                                        <?= htmlspecialchars($gPerm['resource_path']) ?>
                                                                    </span>
                                                                    <span class="text-[10px] text-slate-400 font-mono block leading-tight">
                                                                        <?= htmlspecialchars($gPerm['resource_type_label']) ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </td>

                                                        <!-- PERMISSÃO (VIEW / EDIT / ADMIN) -->
                                                        <td class="py-2.5 px-4 text-center">
                                                            <?php if ($gPerm['is_direct']): ?>
                                                                <!-- ALTERAÇÃO DIRETA DO NÍVEL DO GRUPO -->
                                                                <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=permissions" class="inline">
                                                                    <input type="hidden" name="group_permission_action" value="update_group_level">
                                                                    <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                                                    <input type="hidden" name="permission_id" value="<?= $gPerm['permission_id'] ?>">
                                                                    <select name="permission_level" onchange="this.form.submit()" class="text-xs font-semibold px-2 py-1 rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100 cursor-pointer">
                                                                        <option value="view" <?= $gPerm['permission_level'] === 'view' ? 'selected' : '' ?>>View</option>
                                                                        <option value="edit" <?= $gPerm['permission_level'] === 'edit' ? 'selected' : '' ?>>Edit</option>
                                                                        <option value="admin" <?= $gPerm['permission_level'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                                    </select>
                                                                </form>
                                                            <?php else: ?>
                                                                <!-- LEITURA DE REGRA HERDADA -->
                                                                <?php
                                                                    $lvlClass = 'bg-slate-100 text-slate-700';
                                                                    if ($gPerm['permission_level'] === 'view') $lvlClass = 'bg-blue-500/15 text-blue-700 border-blue-500/30';
                                                                    if ($gPerm['permission_level'] === 'edit') $lvlClass = 'bg-amber-500/15 text-amber-700 border-amber-500/30';
                                                                    if ($gPerm['permission_level'] === 'admin') $lvlClass = 'bg-red-500/15 text-red-700 border-red-500/30';
                                                                ?>
                                                                <span class="px-2.5 py-0.5 text-[10px] font-bold rounded border uppercase <?= $lvlClass ?>">
                                                                    <?= strtoupper($gPerm['permission_level']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>

                                                        <!-- ORIGEM (DIRETA OU HERDADA) -->
                                                        <td class="py-2.5 px-4 text-left">
                                                            <?php if ($gPerm['is_direct']): ?>
                                                                <span class="px-2 py-0.5 text-[10px] font-bold rounded bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30 uppercase">
                                                                    Direta
                                                                </span>
                                                            <?php else: ?>
                                                                <div class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                                                    <span><?= htmlspecialchars($gPerm['origin_label']) ?></span>
                                                                    <?php if (!empty($gPerm['ancestor_info'])): ?>
                                                                        <a href="index.php?tab=editar_estrutura&type=<?= $gPerm['ancestor_info']['type'] ?>&id=<?= $gPerm['ancestor_info']['id'] ?>&res_tab=permissions" class="text-[11px] font-semibold text-amber-600 dark:text-amber-400 hover:underline">
                                                                            (Ver pasta)
                                                                        </a>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>

                                                        <!-- AÇÕES (REMOVER ACESSO DIRETO DO GRUPO) -->
                                                        <td class="py-2.5 px-4 text-right">
                                                            <?php if ($gPerm['is_direct']): ?>
                                                                <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=permissions" onsubmit="return confirm('Deseja remover este acesso direto do grupo <?= htmlspecialchars(addslashes($grpData['name'])) ?>?');" class="inline">
                                                                    <input type="hidden" name="group_permission_action" value="delete_group_permission">
                                                                    <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                                                    <input type="hidden" name="permission_id" value="<?= $gPerm['permission_id'] ?>">
                                                                    <button type="submit" class="px-2 py-1 rounded bg-red-500/10 text-red-600 hover:bg-red-500/20 text-[11px] font-semibold" title="Remover Regra Direta">
                                                                        Remover
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span class="text-slate-300 dark:text-slate-600 text-xs">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <!-- MODAL DE ADICIONAR ACESSO A RECURSO PELA ÁRVORE COMPACTA -->
                                <div id="modal-add-group-access" class="hidden fixed inset-0 z-50 bg-slate-950/50 backdrop-blur-xs flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="modal-add-group-access-title">
                                    <div id="modal-add-group-access-card" class="bg-white dark:bg-[#353842] max-w-lg w-full p-5 rounded-lg border border-slate-200 dark:border-[#454956] shadow-xl space-y-4 text-xs">
                                        
                                        <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-[#454956]">
                                            <h3 id="modal-add-group-access-title" class="text-sm font-bold text-slate-900 dark:text-slate-100">Adicionar acesso ao grupo</h3>
                                            <button type="button" onclick="closeAddGroupAccessModal()" class="text-slate-400 hover:text-slate-600 focus:outline-hidden text-sm p-1 rounded" aria-label="Fechar modal">✕</button>
                                        </div>

                                        <form method="POST" action="index.php?tab=editar_grupo&id=<?= $groupId ?>&group_tab=permissions" id="form-add-group-access" class="space-y-4">
                                            <input type="hidden" name="group_permission_action" value="add_group_access">
                                            <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                            <input type="hidden" name="resource_type" id="input-group-res-type" value="">
                                            <input type="hidden" name="resource_id" id="input-group-res-id" value="">

                                            <!-- ÁRVORE COMPACTA DE NAVEGAÇÃO -->
                                            <div>
                                                <div class="flex items-center justify-between mb-1.5">
                                                    <label class="font-semibold text-slate-700 dark:text-slate-300">Selecione o Recurso na Árvore *</label>
                                                    <input type="text" id="tree-search-input" oninput="filterTreeNodes(this.value)" placeholder="Filtrar pastas..." class="input-minimal px-2.5 py-1 text-[11px] rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33]">
                                                </div>

                                                <div id="tree-container" class="max-h-56 overflow-y-auto border border-slate-200 dark:border-[#454956] rounded bg-slate-50/50 dark:bg-[#2c2e33]/50 p-2 font-mono text-[11px] space-y-1">
                                                    <?php foreach ($resourceTree as $catItem): ?>
                                                        <div class="tree-node-cat" data-name="<?= mb_strtolower($catItem['name']) ?>">
                                                            <label class="flex items-center gap-1.5 p-1 rounded hover:bg-slate-100 dark:hover:bg-[#2c2e33] cursor-pointer font-bold text-slate-900 dark:text-slate-100">
                                                                <input type="radio" name="tree_radio" onchange="selectGroupTreeResource('category', <?= $catItem['id'] ?>, '<?= htmlspecialchars(addslashes($catItem['name'])) ?>')" class="border-slate-300 text-slate-900 focus:ring-0">
                                                                <span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg><?= htmlspecialchars($catItem['name']) ?></span>
                                                            </label>

                                                            <div class="pl-4 space-y-0.5 border-l-2 border-slate-200 dark:border-[#454956] ml-2 mt-0.5">
                                                                <?php foreach ($catItem['subcategories'] as $subItem): ?>
                                                                    <div class="tree-node-sub" data-name="<?= mb_strtolower($catItem['name'] . ' ' . $subItem['name']) ?>">
                                                                        <label class="flex items-center gap-1.5 p-1 rounded hover:bg-slate-100 dark:hover:bg-[#2c2e33] cursor-pointer text-slate-800 dark:text-slate-200">
                                                                            <input type="radio" name="tree_radio" onchange="selectGroupTreeResource('subcategory', <?= $subItem['id'] ?>, '<?= htmlspecialchars(addslashes($catItem['name'] . ' / ' . $subItem['name'])) ?>')" class="border-slate-300 text-slate-900 focus:ring-0">
                                                                            <span class="inline-flex items-center gap-1">├── <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg><?= htmlspecialchars($subItem['name']) ?></span>
                                                                        </label>

                                                                        <div class="pl-5 space-y-0.5 border-l border-slate-200 dark:border-[#454956] ml-3 mt-0.5">
                                                                            <?php foreach ($subItem['subjects'] as $subjItem): ?>
                                                                                <div class="tree-node-subj" data-name="<?= mb_strtolower($catItem['name'] . ' ' . $subItem['name'] . ' ' . $subjItem['name']) ?>">
                                                                                    <label class="flex items-center gap-1.5 p-1 rounded hover:bg-slate-100 dark:hover:bg-[#2c2e33] cursor-pointer text-slate-600 dark:text-slate-400">
                                                                                        <input type="radio" name="tree_radio" onchange="selectGroupTreeResource('subject', <?= $subjItem['id'] ?>, '<?= htmlspecialchars(addslashes($catItem['name'] . ' / ' . $subItem['name'] . ' / ' . $subjItem['name'])) ?>')" class="border-slate-300 text-slate-900 focus:ring-0">
                                                                                        <span class="inline-flex items-center gap-1">└── <svg class="w-3.5 h-3.5 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg><?= htmlspecialchars($subjItem['name']) ?></span>
                                                                                    </label>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                            <!-- RECURSO SELECIONADO -->
                                            <div id="box-selected-resource-info" class="hidden p-2 bg-slate-100 dark:bg-[#2c2e33] rounded border border-slate-200 dark:border-[#454956]">
                                                <span class="text-[10px] text-slate-400 uppercase font-bold block">Recurso Selecionado:</span>
                                                <span id="text-selected-resource-path" class="font-bold text-slate-900 dark:text-slate-100 text-xs"></span>
                                            </div>

                                            <!-- NÍVEL DE PERMISSÃO -->
                                            <div>
                                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Permissão</label>
                                                <div class="space-y-2">
                                                    <label class="flex items-start gap-2.5 p-2 rounded border border-slate-200 dark:border-[#454956] hover:bg-slate-50 dark:hover:bg-[#2c2e33] cursor-pointer">
                                                        <input type="radio" name="permission_level" value="view" checked class="mt-0.5 border-slate-300 text-slate-900 focus:ring-0">
                                                        <div>
                                                            <span class="font-bold text-slate-900 dark:text-slate-100 block">View</span>
                                                            <span class="text-[11px] text-slate-500 dark:text-slate-400 block">Pode visualizar conteúdo.</span>
                                                        </div>
                                                    </label>

                                                    <label class="flex items-start gap-2.5 p-2 rounded border border-slate-200 dark:border-[#454956] hover:bg-slate-50 dark:hover:bg-[#2c2e33] cursor-pointer">
                                                        <input type="radio" name="permission_level" value="edit" class="mt-0.5 border-slate-300 text-slate-900 focus:ring-0">
                                                        <div>
                                                            <span class="font-bold text-slate-900 dark:text-slate-100 block">Edit</span>
                                                            <span class="text-[11px] text-slate-500 dark:text-slate-400 block">Pode visualizar e editar conteúdo.</span>
                                                        </div>
                                                    </label>

                                                    <label class="flex items-start gap-2.5 p-2 rounded border border-slate-200 dark:border-[#454956] hover:bg-slate-50 dark:hover:bg-[#2c2e33] cursor-pointer">
                                                        <input type="radio" name="permission_level" value="admin" class="mt-0.5 border-slate-300 text-slate-900 focus:ring-0">
                                                        <div>
                                                            <span class="font-bold text-slate-900 dark:text-slate-100 block">Admin</span>
                                                            <span class="text-[11px] text-slate-500 dark:text-slate-400 block">Pode gerenciar conteúdo e permissões desta área.</span>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-[#454956]">
                                                <button type="button" onclick="closeAddGroupAccessModal()" class="px-4 py-2 rounded font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#2c2e33]">
                                                    Cancelar
                                                </button>
                                                <button type="submit" id="btn-submit-group-access" disabled class="px-4 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-semibold hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed">
                                                    Conceder acesso
                                                </button>
                                            </div>
                                        </form>

                                        <script>
                                            let groupLastFocused = null;

                                            function openAddGroupAccessModal() {
                                                groupLastFocused = document.activeElement;
                                                const modal = document.getElementById('modal-add-group-access');
                                                modal.classList.remove('hidden');
                                                document.getElementById('tree-search-input').focus();
                                                document.addEventListener('keydown', handleGroupModalKeyDown);
                                            }

                                            function closeAddGroupAccessModal() {
                                                const modal = document.getElementById('modal-add-group-access');
                                                modal.classList.add('hidden');
                                                document.removeEventListener('keydown', handleGroupModalKeyDown);
                                                if (groupLastFocused) groupLastFocused.focus();
                                            }

                                            function handleGroupModalKeyDown(e) {
                                                if (e.key === 'Escape') {
                                                    closeAddGroupAccessModal();
                                                    return;
                                                }
                                            }

                                            function selectGroupTreeResource(type, id, pathName) {
                                                document.getElementById('input-group-res-type').value = type;
                                                document.getElementById('input-group-res-id').value = id;
                                                document.getElementById('text-selected-resource-path').textContent = pathName;
                                                document.getElementById('box-selected-resource-info').classList.remove('hidden');
                                                document.getElementById('btn-submit-group-access').disabled = false;
                                            }

                                            function filterTreeNodes(q) {
                                                const term = q.toLowerCase().trim();
                                                const nodes = document.querySelectorAll('#tree-container [data-name]');
                                                nodes.forEach(node => {
                                                    if (!term || node.getAttribute('data-name').includes(term)) {
                                                        node.style.display = '';
                                                    } else {
                                                        node.style.display = 'none';
                                                    }
                                                });
                                            }
                                        </script>
                                    </div>
                                </div>

                            </div>
                        <?php endif; ?>

                    </div>
                    <?php } ?>
                <?php endif; ?>

                <!-- TAB: LISTAGEM DE USUÁRIOS DO SISTEMA -->
                <?php if ($activeTab === 'usuarios'): ?>
                    <?php
                        $stmtUsers = $pdo->query("
                            SELECT u.id, u.name, u.username, u.email, u.role, u.active, u.created_at,
                                   COUNT(DISTINCT ug.group_id) AS total_grupos
                            FROM users u
                            LEFT JOIN user_groups ug ON u.id = ug.user_id
                            GROUP BY u.id, u.name, u.username, u.email, u.role, u.active, u.created_at
                            ORDER BY u.name ASC
                        ");
                        $usersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-[#454956]">
                            <div>
                                <nav class="flex items-center gap-1.5 text-xs text-slate-400 font-medium mb-0.5">
                                    <span>Gestão de Acesso</span>
                                    <span>/</span>
                                    <span class="font-bold text-slate-900 dark:text-slate-100">Usuários</span>
                                </nav>
                                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Usuários do Sistema</h1>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Diagnóstico de permissões e controle de acesso individual.</p>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-[#353842] rounded border border-slate-200 dark:border-[#454956] shadow-xs overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs border-collapse">
                                    <thead>
                                        <tr class="bg-slate-50 dark:bg-[#2c2e33] border-b border-slate-200 dark:border-[#454956] text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                            <th class="py-2.5 px-4">Usuário</th>
                                            <th class="py-2.5 px-4">E-mail / Username</th>
                                            <th class="py-2.5 px-4 text-center">Perfil</th>
                                            <th class="py-2.5 px-4 text-center">Grupos Ativos</th>
                                            <th class="py-2.5 px-4 text-center">Status</th>
                                            <th class="py-2.5 px-4 text-right">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
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
                        require_once __DIR__ . '/../services/PermissionService.php';
                        $permService = new PermissionService($pdo);

                        $targetUserId = (int)($_GET['id'] ?? 0);
                        $userTab = trim($_GET['user_tab'] ?? 'access');
                        $accessFilter = strtolower(trim($_GET['filter'] ?? 'all'));
                        if (!in_array($accessFilter, ['all', 'direct', 'groups', 'inherited'])) {
                            $accessFilter = 'all';
                        }

                        $diagnosis = $permService->getUserEffectiveAccessDiagnosis($targetUserId, $accessFilter);
                        $uData = $diagnosis['user'];

                        if (!$uData) {
                            echo "<div class='p-4 bg-red-500/10 text-red-600 rounded-md text-xs font-semibold'>Usuário não encontrado.</div>";
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
                                <span class="text-slate-500">Acessos Efetivos</span>
                            </nav>
                            <div class="flex items-center justify-between">
                                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                                    <span><?= htmlspecialchars($uData['name']) ?></span>
                                    <span class="text-xs font-mono text-slate-400 font-normal">(@<?= htmlspecialchars($uData['username']) ?>)</span>
                                </h1>
                            </div>
                        </div>

                        <!-- CARD RESUMO DO USUÁRIO & GRUPOS ATIVOS -->
                        <div class="bg-white dark:bg-[#353842] p-5 rounded border border-slate-200 dark:border-[#454956] shadow-xs space-y-3">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2 border-b border-slate-100 dark:border-[#454956] text-xs">
                                <div>
                                    <span class="font-bold text-slate-900 dark:text-slate-100 block">Grupos Ativos do Usuário:</span>
                                    <?php if (empty($diagnosis['active_groups'])): ?>
                                        <span class="text-slate-400 text-[11px] block mt-0.5">Nenhum grupo ativo associado a este usuário.</span>
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
                                            Via Grupos
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
                                                            <span class="font-bold text-slate-900 dark:text-slate-100 block text-xs">
                                                                <?= htmlspecialchars($resDiag['resource_path']) ?>
                                                            </span>
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

            function onCategoryChange() {
                const cat = document.getElementById('select-cat').value;
                const subSelect = document.getElementById('select-subcat');
                const assSelect = document.getElementById('select-assunto');

                subSelect.innerHTML = '<option value="">-- Selecione ▾ --</option>';
                assSelect.innerHTML = '<option value="">-- Selecione ▾ --</option>';

                if (cat && hierarchyDataFilter[cat]) {
                    Object.keys(hierarchyDataFilter[cat]).forEach(sub => {
                        const opt = document.createElement('option');
                        opt.value = sub;
                        opt.textContent = sub;
                        subSelect.appendChild(opt);
                    });
                }
            }

            function onSubcategoryChange() {
                const cat = document.getElementById('select-cat').value;
                const sub = document.getElementById('select-subcat').value;
                const assSelect = document.getElementById('select-assunto');

                assSelect.innerHTML = '<option value="">-- Selecione ▾ --</option>';

                if (cat && sub && hierarchyDataFilter[cat] && hierarchyDataFilter[cat][sub]) {
                    hierarchyDataFilter[cat][sub].forEach(ass => {
                        const opt = document.createElement('option');
                        opt.value = ass;
                        opt.textContent = ass;
                        assSelect.appendChild(opt);
                    });
                }
            }

            function toggleFormContent(type) {
                const boxes = ['arquivo', 'texto', 'link'];
                boxes.forEach(b => {
                    const el = document.getElementById('box-' + b);
                    if (el) el.classList.add('hidden');
                });
                const target = document.getElementById('box-' + type);
                if (target) target.classList.remove('hidden');
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

            function ncLoadSubcatsForAssunto() {
                const catEl = document.getElementById('nc-ass-cat');
                const subEl = document.getElementById('nc-ass-subcat');
                if (!catEl || !subEl) return;

                const cat = catEl.value;
                subEl.innerHTML = '<option value="">-- Selecione a Subcategoria --</option>';

                if (cat && hierarchyDataNC[cat]) {
                    Object.keys(hierarchyDataNC[cat]).forEach(sub => {
                        const opt = document.createElement('option');
                        opt.value = sub;
                        opt.textContent = sub;
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

                // Preenchimento automático para criação de novo documento quando vindo do estado vazio
                const urlParams = new URLSearchParams(window.location.search);
                const preCat = urlParams.get('cat');
                const preSub = urlParams.get('subcat');
                const preAss = urlParams.get('assunto');

                if (preCat && document.getElementById('select-cat')) {
                    document.getElementById('select-cat').value = preCat;
                    onCategoryChange();
                    if (preSub && document.getElementById('select-subcat')) {
                        document.getElementById('select-subcat').value = preSub;
                        onSubcategoryChange();
                        if (preAss && document.getElementById('select-assunto')) {
                            document.getElementById('select-assunto').value = preAss;
                        }
                    }
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

            document.addEventListener('DOMContentLoaded', function() {
                if (localStorage.getItem('main_sidebar_compact') === '1') {
                    const sidebar = document.getElementById('sidebar-menu');
                    const arrow = document.getElementById('main-dock-arrow');
                    if (sidebar) sidebar.classList.add('sidebar-compact');
                    if (arrow) arrow.classList.add('rotate-180');
                }
            });
        </script>
    <?php endif; ?>

</body>
</html>
