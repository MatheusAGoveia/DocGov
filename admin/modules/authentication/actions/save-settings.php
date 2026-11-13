<?php
/**
 * authentication/actions/save-settings.php
 * Entradas: $currentAdminUserId, $isGlobalAdminCurrent, $canManageAuthentication, $systemSettingsService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// GESTÃO DE SERVIDORES AD & SSO (SOMENTE SUPER ADMIN)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_ad_settings'])) {
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$canManageAuthentication) {
        http_response_code(403);
        $errorMessage = 'Seu usuário não possui acesso às configurações do Active Directory.';
    } else {
        try {
            $adAuthEnabled = isset($_POST['ad_auth_enabled']);
            $adDefaultDomain = strtoupper(trim((string)($_POST['ad_default_domain'] ?? 'BETIM')));
            $adSuperAdminUsers = $isGlobalAdminCurrent
                ? array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($_POST['ad_super_admin_users'] ?? '')) ?: []))))
                : array_values((array)($systemSettingsService->get('ad_super_admin_users', [])));
            $adIntegratedWindows = isset($_POST['ad_integrated_windows_enabled']);
            $adPrimaryDomainKey = strtoupper(trim((string)($_POST['ad_primary_domain'] ?? $adDefaultDomain)));
            $adDomainsPosted = (array)($_POST['ad_domains'] ?? []);

            // Carregar domínios existentes para preservar a independência de cada domínio cadastrado
            $currentSettings = $systemSettingsService->all();
            $adDomainsSaved = (array)($currentSettings['ad_domains'] ?? []);
            $editingDomainOnly = ($_POST['ad_settings_scope'] ?? '') === 'domain';
            $editingDomainKey = strtoupper(trim((string)($_POST['ad_edit_domain'] ?? '')));
            if ($editingDomainOnly) {
                if (!isset($adDomainsSaved[$editingDomainKey], $adDomainsPosted[$editingDomainKey])) {
                    throw new InvalidArgumentException('Selecione um domínio existente para configurar a conta de leitura.');
                }
                $adDomainsPosted = [$editingDomainKey => $adDomainsPosted[$editingDomainKey]];
                $adAuthEnabled = (bool)$currentSettings['ad_auth_enabled'];
                $adDefaultDomain = (string)$currentSettings['ad_default_domain'];
                $adSuperAdminUsers = (array)$currentSettings['ad_super_admin_users'];
                $adIntegratedWindows = (bool)$currentSettings['ad_integrated_windows_enabled'];
                $adPrimaryDomainKey = $adDefaultDomain;
            }
            if (empty($adDomainsSaved)) {
                $adDomainsSaved = [
                    'BETIM' => [
                        'key' => 'BETIM',
                        'name' => 'Prefeitura Municipal de Betim',
                        'uri' => 'ldaps://diana.betim.pmb:636',
                        'base_dn' => 'DC=betim,DC=pmb',
                        'dns_domain' => 'betim.pmb',
                        'netbios_domain' => 'BETIM',
                        'ca_certificate' => DOCGOV_PROJECT_ROOT . '/config/certs/diana.betim.pmb.pem',
                        'service_bind_dn' => '',
                        'service_bind_password' => '',
                        'enabled' => true,
                        'replication_enabled' => true,
                        'is_primary' => true,
                    ]
                ];
            }

            $hasPrimary = $editingDomainOnly && !empty($adDomainsSaved[$adPrimaryDomainKey]['is_primary']);
            foreach ($adDomainsPosted as $domKey => $domData) {
                $key = strtoupper(trim((string)($domData['key'] ?? $domKey)));
                if ($key === '') continue;
                $isPrimary = ($key === $adPrimaryDomainKey) || (!empty($domData['is_primary']) && !$hasPrimary);
                if ($isPrimary) {
                    $hasPrimary = true;
                    $adPrimaryDomainKey = $key;
                    $adDefaultDomain = $key;
                    foreach ($adDomainsSaved as &$d) {
                        $d['is_primary'] = false;
                    }
                    unset($d);
                }

                $existingDomain = $adDomainsSaved[$key] ?? [];
                $adDomainsSaved[$key] = [
                    'key' => $key,
                    'name' => trim((string)($domData['name'] ?? ($existingDomain['name'] ?? $key))),
                    'uri' => trim((string)($domData['uri'] ?? ($existingDomain['uri'] ?? ''))),
                    'base_dn' => trim((string)($domData['base_dn'] ?? ($existingDomain['base_dn'] ?? ''))),
                    'dns_domain' => trim((string)($domData['dns_domain'] ?? ($existingDomain['dns_domain'] ?? ''))),
                    'netbios_domain' => strtoupper(trim((string)($domData['netbios_domain'] ?? ($existingDomain['netbios_domain'] ?? $key)))),
                    'ca_certificate' => trim((string)($domData['ca_certificate'] ?? ($existingDomain['ca_certificate'] ?? ''))),
                    ...SystemSettingsService::mergeAdServiceAccount($domData, $existingDomain),
                    'enabled' => !empty($domData['enabled']),
                    'replication_enabled' => !empty($domData['replication_enabled']),
                    'is_primary' => $isPrimary,
                ];
            }
            if (!empty($adDomainsSaved) && !$hasPrimary) {
                $firstKey = array_key_first($adDomainsSaved);
                $adDomainsSaved[$firstKey]['is_primary'] = true;
                $adDefaultDomain = $firstKey;
            }
            if (empty($adDomainsSaved)) {
                $adDomainsSaved['BETIM'] = [
                    'key' => 'BETIM',
                    'name' => 'Prefeitura Municipal de Betim (Principal)',
                    'uri' => 'ldaps://diana.betim.pmb:636',
                    'base_dn' => 'DC=betim,DC=pmb',
                    'dns_domain' => 'betim.pmb',
                    'netbios_domain' => 'BETIM',
                    'ca_certificate' => DOCGOV_PROJECT_ROOT . '/config/certs/diana.betim.pmb.pem',
                    'service_bind_dn' => '',
                    'service_bind_password' => '',
                    'enabled' => true,
                    'is_primary' => true,
                ];
            }

            $systemSettingsService->saveMany([
                'ad_auth_enabled' => $adAuthEnabled,
                'ad_default_domain' => $adDefaultDomain,
                'ad_super_admin_users' => $adSuperAdminUsers,
                'ad_integrated_windows_enabled' => $adIntegratedWindows,
                'ad_domains' => $adDomainsSaved,
            ], $currentAdminUserId);

            $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, [
                'action' => 'ad_servers_updated',
                'primary_domain' => $adDefaultDomain,
                'total_servers' => count($adDomainsSaved),
            ]);

            header('Location: index.php?' . http_build_query(['tab' => 'servidores_ad', 'msg' => 'ad_saved'] + ($editingDomainOnly ? ['domain' => $editingDomainKey] : [])));
            exit;
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}
