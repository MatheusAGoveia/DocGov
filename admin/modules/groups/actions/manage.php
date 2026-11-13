<?php
/**
 * groups/actions/manage.php
 * Entradas: $pdo, $currentAdminUserId, $isGlobalAdminCurrent, $permService, $groupMembershipService, $notificationService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
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
        $allowedGroupActions = ['create_group', 'edit_group', 'toggle_status', 'delete_group', 'add_user', 'remove_user', 'sync_system_capabilities', 'add_subgroup', 'remove_subgroup'];

        if (!in_array($grpAction, $allowedGroupActions, true)) {
            http_response_code(400);
            $errorMessage = 'Ação de equipe inválida.';
        } elseif ($grpAction === 'sync_system_capabilities') {
            $gId = (int)($_POST['group_id'] ?? 0);
            try {
                $capabilityChanges = $systemAccessService->syncGroupCapabilities(
                    $gId,
                    (array)($_POST['system_capabilities'] ?? []),
                    $currentAdminUserId
                );
                $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, [
                    'action' => 'team_system_capabilities_updated',
                    'team_id' => $gId,
                    'granted' => $capabilityChanges['granted'],
                    'revoked' => $capabilityChanges['revoked'],
                ]);
                if ($capabilityChanges['granted'] !== [] || $capabilityChanges['revoked'] !== []) {
                    try {
                        $memberIdsStmt = $pdo->prepare('
                            SELECT u.id
                            FROM users u
                            JOIN user_groups ug ON ug.user_id = u.id
                            WHERE ug.group_id = :group_id AND u.active = TRUE
                        ');
                        $memberIdsStmt->execute([':group_id' => $gId]);
                        $memberIds = array_map('intval', $memberIdsStmt->fetchAll(PDO::FETCH_COLUMN));
                        $changeParts = [];
                        if ($capabilityChanges['granted'] !== []) {
                            $changeParts[] = count($capabilityChanges['granted']) . ' módulo(s) concedido(s)';
                        }
                        if ($capabilityChanges['revoked'] !== []) {
                            $changeParts[] = count($capabilityChanges['revoked']) . ' módulo(s) removido(s)';
                        }
                        $notificationService->createForUsers(
                            $memberIds,
                            'system_access_updated',
                            'Acessos administrativos atualizados',
                            'Os acessos globais da sua equipe foram atualizados: ' . implode(' e ', $changeParts) . '.'
                        );
                    } catch (Throwable $notificationException) {
                        error_log('DocGov: falha ao notificar capacidades administrativas: ' . $notificationException->getMessage());
                    }
                }
                header('Location: index.php?tab=editar_grupo&id=' . $gId . '&group_tab=system&msg=team_system_access_saved');
                exit;
            } catch (Throwable $exception) {
                http_response_code($exception instanceof InvalidArgumentException ? 422 : 403);
                $errorMessage = $exception->getMessage();
            }
        } elseif (in_array($grpAction, ['add_subgroup', 'remove_subgroup'], true)) {
            $gId = (int)($_POST['group_id'] ?? 0);
            $childId = (int)($_POST['child_group_id'] ?? 0);
            try {
                $changed = $groupMembershipService->changeChild($gId, $childId, $currentAdminUserId, $grpAction === 'add_subgroup');
                if (!$changed) throw new InvalidArgumentException($grpAction === 'add_subgroup'
                    ? 'Esta equipe já está incluída como subgrupo.' : 'O vínculo com este subgrupo não foi encontrado.');
                header('Location: index.php?tab=editar_grupo&id=' . $gId . '&group_tab=groups&msg=' . ($grpAction === 'add_subgroup' ? 'team_subgroup_added' : 'team_subgroup_removed'));
                exit;
            } catch (InvalidArgumentException $exception) {
                http_response_code(422);
                $errorMessage = $exception->getMessage();
            } catch (Throwable $exception) {
                http_response_code(503);
                error_log('DocGov: falha ao alterar subgrupo: ' . $exception->getMessage());
                $errorMessage = 'Não foi possível alterar o vínculo entre equipes. Tente novamente.';
            }
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
                if ($groupMembershipService->isAvailable()) $pdo->query('SELECT pg_advisory_xact_lock(20261001, 27)');
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

                // Revoga e audita capacidades globais antes de excluir a equipe.
                $systemAccessService->syncGroupCapabilities($gId, [], $currentAdminUserId);

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
