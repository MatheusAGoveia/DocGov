<?php
/**
 * users/actions/create.php
 * Entradas: $currentAdminUserId, $canManageDirectory, $adAuthService, $selectedAdDomain, $availableAdDomains, $usageAuditService.
 * Estado compartilhado: $errorMessage, $selectedAdDomain, $availableAdDomains.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// 0. CADASTRO DE USUÁRIOS (SOMENTE SUPER ADMIN)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['create_user'])) {
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$canManageDirectory) {
        http_response_code(403);
        $errorMessage = 'Seu usuário não possui acesso à importação do diretório.';
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
