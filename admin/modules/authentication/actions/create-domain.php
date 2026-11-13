<?php
/**
 * authentication/actions/create-domain.php
 * Entradas: $currentAdminUserId, $canManageAuthentication, $systemSettingsService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// CRIAÇÃO DE DOMÍNIO AD: operação mutável somente via POST + CSRF.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['create_ad_domain'])) {
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$canManageAuthentication) {
        http_response_code(403);
        $errorMessage = 'Seu usuário não possui acesso à administração dos domínios.';
    } else {
        $rawDomainKey = strtoupper(trim((string)($_POST['domain_key'] ?? '')));
        try {
            if (!preg_match('/^[A-Z0-9_-]{2,50}$/', $rawDomainKey)) {
                throw new InvalidArgumentException('A sigla do domínio deve ter de 2 a 50 letras, números, hífens ou sublinhados.');
            }
            $currentSettings = $systemSettingsService->all();
            $domains = (array)($currentSettings['ad_domains'] ?? []);
            if (isset($domains[$rawDomainKey])) {
                throw new InvalidArgumentException('Já existe um domínio com esta sigla.');
            }

            $rawUri = trim((string)($_POST['domain_uri'] ?? ''));
            // Sanitização automática de erros de digitação de protocolo na URL (ex.: ldasps:// ou ldaps//)
            $rawUri = preg_replace('/^ldasps:\/\//i', 'ldaps://', $rawUri);
            $rawUri = preg_replace('/^ldaps\/\//i', 'ldaps://', $rawUri);
            if ($rawUri === '') {
                $rawUri = 'ldaps://diana.betim.pmb:636';
            }

            if (!preg_match('#^ldaps?://[a-z0-9._-]+(?::\d{1,5})?$#i', $rawUri)) {
                throw new InvalidArgumentException('Informe uma URI LDAP ou LDAPS válida.');
            }
            $domainName = trim((string)($_POST['domain_name'] ?? $rawDomainKey));
            if (mb_strlen($domainName) < 2 || mb_strlen($domainName) > 100) {
                throw new InvalidArgumentException('O nome do domínio deve ter entre 2 e 100 caracteres.');
            }
            $dnsDomain = strtolower($rawDomainKey) . '.betim.pmb';
            $baseDn = 'DC=' . strtolower($rawDomainKey) . ',DC=betim,DC=pmb';

            $domains[$rawDomainKey] = [
                'key' => $rawDomainKey,
                'name' => $domainName !== '' ? $domainName : $rawDomainKey,
                'uri' => $rawUri,
                'base_dn' => $baseDn,
                'dns_domain' => $dnsDomain,
                'netbios_domain' => $rawDomainKey,
                'ca_certificate' => '',
                'service_bind_dn' => '',
                'service_bind_password' => '',
                'enabled' => true,
                'replication_enabled' => true,
                'is_primary' => count($domains) === 0,
            ];

            $systemSettingsService->saveMany(['ad_domains' => $domains], $currentAdminUserId);

            $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, [
                'action' => 'ad_domain_created',
                'domain_key' => $rawDomainKey,
                'uri' => $rawUri,
            ]);

            header("Location: index.php?tab=servidores_ad&domain={$rawDomainKey}&msg=domain_added");
            exit;
        } catch (Throwable $ex) {
            $errorMessage = 'Não foi possível criar o domínio: ' . $ex->getMessage();
        }
    }
}
