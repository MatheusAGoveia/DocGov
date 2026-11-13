<?php
/**
 * authentication/actions/delete-domain.php
 * Entradas: $currentAdminUserId, $canManageAuthentication, $systemSettingsService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// EXCLUIR DOMÍNIO DO AD VIA POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_ad_domain'])) {
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$canManageAuthentication) {
        http_response_code(403);
        $errorMessage = 'Seu usuário não possui acesso à administração dos domínios.';
    } else {
        try {
            $domainToDelete = strtoupper(trim((string)$_POST['delete_ad_domain']));
            $currentSettings = $systemSettingsService->all();
            $domains = (array)($currentSettings['ad_domains'] ?? []);

            if (count($domains) <= 1) {
                throw new RuntimeException('Não é possível excluir o único domínio cadastrado no sistema.');
            }

            if (isset($domains[$domainToDelete])) {
                $wasPrimary = !empty($domains[$domainToDelete]['is_primary']);
                unset($domains[$domainToDelete]);

                if ($wasPrimary && !empty($domains)) {
                    $firstKey = array_key_first($domains);
                    $domains[$firstKey]['is_primary'] = true;
                    $systemSettingsService->saveMany(['ad_default_domain' => $firstKey], $currentAdminUserId);
                }

                $systemSettingsService->saveMany(['ad_domains' => $domains], $currentAdminUserId);

                $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, [
                    'action' => 'ad_domain_deleted',
                    'deleted_domain' => $domainToDelete,
                ]);
            }

            header('Location: index.php?tab=servidores_ad&msg=domain_deleted');
            exit;
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}
