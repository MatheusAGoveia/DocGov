<?php
/**
 * integrations/actions/manage.php
 * Entradas: $isGlobalAdminCurrent, $currentAdminUserId, $apiClientService, $usageAuditService.
 * Estado compartilhado: $errorMessage, $apiClientFormInput.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 *
 * Cria e revoga chaves da API de integração. Somente o Super Admin opera esta
 * área, pois uma chave vinculada concede leitura equivalente à conta escolhida.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}

$apiClientFormInput = $apiClientFormInput ?? [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['api_client_action'])) {
    $apiClientAction = (string)$_POST['api_client_action'];
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$isGlobalAdminCurrent) {
        http_response_code(403);
        $errorMessage = 'Somente o Super Admin gerencia as chaves da API.';
    } elseif ($apiClientAction === 'create') {
        $apiClientFormInput = [
            'name' => (string)($_POST['name'] ?? ''),
            'description' => (string)($_POST['description'] ?? ''),
            'access_scope' => (string)($_POST['access_scope'] ?? ApiClientService::SCOPE_PUBLIC),
            'username' => (string)($_POST['username'] ?? ''),
            'expires_on' => (string)($_POST['expires_on'] ?? ''),
            'rate_limit_per_minute' => (string)($_POST['rate_limit_per_minute'] ?? ApiClientService::DEFAULT_RATE_LIMIT),
        ];
        try {
            $createdApiClient = $apiClientService->create($apiClientFormInput, $currentAdminUserId);
            // O token em claro aparece uma única vez, na próxima página.
            $_SESSION['api_client_new_token'] = [
                'client_id' => $createdApiClient['client']['id'],
                'name' => $createdApiClient['client']['name'],
                'token' => $createdApiClient['token'],
                'created_at' => time(),
            ];
            $usageAuditService->logAdminAction($currentAdminUserId, 'api_client_created', 'ADMIN', $createdApiClient['client']['id']);
            header('Location: index.php?tab=integracoes&msg=api_client_created');
            exit;
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('DocGov criação de chave da API: ' . $exception->getMessage());
            http_response_code(500);
            $errorMessage = 'Não foi possível criar a chave. Tente novamente.';
        }
    } elseif ($apiClientAction === 'revoke') {
        $revokedClientId = (int)($_POST['api_client_id'] ?? 0);
        if ($apiClientService->revoke($revokedClientId, $currentAdminUserId)) {
            $usageAuditService->logAdminAction($currentAdminUserId, 'api_client_revoked', 'ADMIN', $revokedClientId);
            header('Location: index.php?tab=integracoes&msg=api_client_revoked');
            exit;
        }
        http_response_code(404);
        $errorMessage = 'Chave não encontrada ou já revogada.';
    } else {
        http_response_code(422);
        $errorMessage = 'Ação de integração inválida.';
    }
}
