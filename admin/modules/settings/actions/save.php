<?php
/**
 * settings/actions/save.php
 * Entradas: $currentAdminUserId, $canManageSystemSettings, $systemSettingsService, $categoryImageService, $usageAuditService.
 * Estado compartilhado: $errorMessage.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// CONFIGURAÇÕES GLOBAIS (SOMENTE SUPER ADMIN)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_system_settings'])) {
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    } elseif (!$canManageSystemSettings) {
        http_response_code(403);
        $errorMessage = 'Seu usuário não possui acesso às configurações gerais.';
    } else {
        try {
            $portalName = trim((string)($_POST['portal_name'] ?? ''));
            $organizationNameInput = trim((string)($_POST['organization_name'] ?? ''));
            $portalDescription = trim((string)($_POST['portal_description'] ?? ''));
            $selectedPortalTheme = SystemSettingsService::normalizePortalTheme($_POST['portal_theme'] ?? 'emerald');
            $systemLogoFile = isset($_FILES['system_logo']) && is_array($_FILES['system_logo'])
                ? $_FILES['system_logo']
                : null;
            $removeSystemLogo = isset($_POST['remove_system_logo']);
            $supportEmail = strtolower(trim((string)($_POST['support_email'] ?? '')));
            $timezoneInput = trim((string)($_POST['timezone'] ?? 'America/Sao_Paulo'));
            $sessionTimeout = (int)($_POST['session_timeout_minutes'] ?? 120);
            $corsEnabled = isset($_POST['cors_enabled']);
            $corsCredentials = isset($_POST['cors_allow_credentials']);
            $origins = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R+/', (string)($_POST['cors_allowed_origins'] ?? '')) ?: []))));
            $allowedMethodOptions = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
            $corsMethods = array_values(array_intersect($allowedMethodOptions, array_map('strtoupper', (array)($_POST['cors_allowed_methods'] ?? []))));
            $maintenanceEnabled = isset($_POST['maintenance_enabled']);
            $maintenanceMode = strtolower(trim((string)($_POST['maintenance_mode'] ?? 'full')));
            $maintenanceScope = array_values(array_intersect(['portal', 'admin', 'api', 'files'], (array)($_POST['maintenance_scope'] ?? [])));
            $maintenanceReason = trim((string)($_POST['maintenance_reason'] ?? ''));
            $maintenanceReference = trim((string)($_POST['maintenance_reference'] ?? ''));
            $maintenanceResponsible = trim((string)($_POST['maintenance_responsible'] ?? ''));
            $maintenanceProgress = (int)($_POST['maintenance_progress'] ?? 0);
            $maintenanceAnnounceMinutes = (int)($_POST['maintenance_announce_minutes'] ?? 60);
            $maintenanceRefreshSeconds = (int)($_POST['maintenance_auto_refresh_seconds'] ?? 30);
            $maintenanceTitle = trim((string)($_POST['maintenance_title'] ?? ''));
            $maintenanceMessage = trim((string)($_POST['maintenance_message'] ?? ''));

            // Configurações do Active Directory (AD)
            $adAuthEnabled = isset($_POST['ad_auth_enabled']);
            $adDefaultDomain = strtoupper(trim((string)($_POST['ad_default_domain'] ?? 'BETIM')));
            $adSuperAdminUsers = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($_POST['ad_super_admin_users'] ?? '')) ?: []))));
            $adIntegratedWindows = isset($_POST['ad_integrated_windows_enabled']);
            $adDomainsPosted = (array)($_POST['ad_domains'] ?? []);
            $adDomainsSaved = [];
            foreach ($adDomainsPosted as $domKey => $domData) {
                $key = strtoupper(trim((string)($domData['key'] ?? $domKey)));
                if ($key === '') continue;
                $adDomainsSaved[$key] = [
                    'key' => $key,
                    'name' => trim((string)($domData['name'] ?? $key)),
                    'uri' => trim((string)($domData['uri'] ?? '')),
                    'base_dn' => trim((string)($domData['base_dn'] ?? '')),
                    'dns_domain' => trim((string)($domData['dns_domain'] ?? '')),
                    'netbios_domain' => strtoupper(trim((string)($domData['netbios_domain'] ?? $key))),
                    'ca_certificate' => trim((string)($domData['ca_certificate'] ?? '')),
                    ...SystemSettingsService::mergeAdServiceAccount($domData, (array)($currentSystemSettings['ad_domains'][$key] ?? [])),
                    'enabled' => !empty($domData['enabled']),
                ];
            }
            if (empty($adDomainsSaved)) {
                $adDomainsSaved['BETIM'] = [
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
                ];
            }

            if (mb_strlen($portalName) < 2 || mb_strlen($portalName) > 60) {
                throw new InvalidArgumentException('O nome do portal deve ter entre 2 e 60 caracteres.');
            }
            if (mb_strlen($organizationNameInput) < 2 || mb_strlen($organizationNameInput) > 100) {
                throw new InvalidArgumentException('O nome da organização deve ter entre 2 e 100 caracteres.');
            }
            if (mb_strlen($portalDescription) < 2 || mb_strlen($portalDescription) > 160) {
                throw new InvalidArgumentException('A descrição deve ter entre 2 e 160 caracteres.');
            }
            if ($selectedPortalTheme !== strtolower(trim((string)($_POST['portal_theme'] ?? 'emerald')))) {
                throw new InvalidArgumentException('Tema padrão inválido.');
            }
            if (($systemLogoError = $categoryImageService->validate($systemLogoFile, 'logo do sistema')) !== null) {
                throw new InvalidArgumentException($systemLogoError);
            }
            if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Informe um e-mail de suporte válido.');
            }
            if (!in_array($timezoneInput, ['America/Sao_Paulo', 'UTC'], true)) {
                throw new InvalidArgumentException('Fuso horário inválido.');
            }
            if ($sessionTimeout < 15 || $sessionTimeout > 480) {
                throw new InvalidArgumentException('A expiração da sessão deve ficar entre 15 e 480 minutos.');
            }
            if ($corsEnabled && empty($origins)) {
                throw new InvalidArgumentException('Informe ao menos uma origem quando o CORS estiver habilitado.');
            }
            foreach ($origins as $origin) {
                if ($origin !== '*' && !preg_match('#^https?://[a-z0-9.-]+(?::\d{1,5})?$#i', $origin)) {
                    throw new InvalidArgumentException("Origem CORS inválida: {$origin}");
                }
            }
            if ($corsCredentials && in_array('*', $origins, true)) {
                throw new InvalidArgumentException('CORS com credenciais não pode usar origem curinga (*).');
            }
            if ($corsEnabled && empty($corsMethods)) {
                throw new InvalidArgumentException('Selecione ao menos um método permitido pelo CORS.');
            }
            if (mb_strlen($maintenanceTitle) < 3 || mb_strlen($maintenanceTitle) > 100 || mb_strlen($maintenanceMessage) < 5 || mb_strlen($maintenanceMessage) > 500) {
                throw new InvalidArgumentException('Revise o título e a mensagem de manutenção.');
            }
            if (!in_array($maintenanceMode, ['full', 'read_only'], true)) {
                throw new InvalidArgumentException('Modo de manutenção inválido.');
            }
            if ($maintenanceEnabled && empty($maintenanceScope)) {
                throw new InvalidArgumentException('Selecione ao menos uma área afetada pela manutenção.');
            }
            if (mb_strlen($maintenanceReason) < 3 || mb_strlen($maintenanceReason) > 160) {
                throw new InvalidArgumentException('O motivo deve ter entre 3 e 160 caracteres.');
            }
            if (mb_strlen($maintenanceReference) > 80 || mb_strlen($maintenanceResponsible) > 100) {
                throw new InvalidArgumentException('Referência ou responsável excedeu o tamanho permitido.');
            }
            if ($maintenanceProgress < 0 || $maintenanceProgress > 100) {
                throw new InvalidArgumentException('O progresso deve ficar entre 0% e 100%.');
            }
            if (!in_array($maintenanceAnnounceMinutes, [0, 15, 30, 60, 120, 240, 1440], true)) {
                throw new InvalidArgumentException('Antecedência do aviso inválida.');
            }
            if (!in_array($maintenanceRefreshSeconds, [0, 15, 30, 60, 120], true)) {
                throw new InvalidArgumentException('Intervalo de atualização inválido.');
            }

            $inputTimezone = new DateTimeZone($timezoneInput);
            $toUtc = static function (string $value) use ($inputTimezone): ?string {
                $value = trim($value);
                if ($value === '') {
                    return null;
                }
                $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $inputTimezone);
                if (!$date || DateTimeImmutable::getLastErrors() && DateTimeImmutable::getLastErrors()['warning_count'] > 0) {
                    throw new InvalidArgumentException('Data ou hora de manutenção inválida.');
                }
                return $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
            };
            $maintenanceStart = $toUtc((string)($_POST['maintenance_start_at'] ?? ''));
            $maintenanceEnd = $toUtc((string)($_POST['maintenance_end_at'] ?? ''));
            if ($maintenanceEnabled && (!$maintenanceStart || !$maintenanceEnd)) {
                throw new InvalidArgumentException('Defina o início e o fim da janela de manutenção.');
            }
            if ($maintenanceStart && $maintenanceEnd && new DateTimeImmutable($maintenanceEnd) <= new DateTimeImmutable($maintenanceStart)) {
                throw new InvalidArgumentException('O fim da manutenção deve ser posterior ao início.');
            }
            if ($maintenanceEnabled && $maintenanceEnd && new DateTimeImmutable($maintenanceEnd) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                throw new InvalidArgumentException('O término da manutenção precisa estar no futuro.');
            }

            $currentLogoPath = trim((string)$systemSettingsService->get('system_logo_path', ''));
            $newLogoPath = null;
            $hasNewLogo = $systemLogoFile !== null
                && (int)($systemLogoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            try {
                if ($hasNewLogo) {
                    $newLogoPath = $categoryImageService->storeFor($systemLogoFile, 1, 'system_logo');
                }

                $systemSettingsService->saveMany([
                'portal_name' => $portalName,
                'organization_name' => $organizationNameInput,
                'portal_description' => $portalDescription,
                'portal_theme' => $selectedPortalTheme,
                'system_logo_path' => $newLogoPath ?? ($removeSystemLogo ? '' : $currentLogoPath),
                'support_email' => $supportEmail,
                'timezone' => $timezoneInput,
                'session_timeout_minutes' => $sessionTimeout,
                'cors_enabled' => $corsEnabled,
                'cors_allowed_origins' => $origins,
                'cors_allowed_methods' => $corsMethods ?: ['GET', 'POST', 'OPTIONS'],
                'cors_allow_credentials' => $corsCredentials,
                'maintenance_enabled' => $maintenanceEnabled,
                'maintenance_mode' => $maintenanceMode,
                'maintenance_scope' => $maintenanceScope,
                'maintenance_start_at' => $maintenanceStart,
                'maintenance_end_at' => $maintenanceEnd,
                'maintenance_reason' => $maintenanceReason,
                'maintenance_reference' => $maintenanceReference,
                'maintenance_responsible' => $maintenanceResponsible,
                'maintenance_progress' => $maintenanceProgress,
                'maintenance_announce_minutes' => $maintenanceAnnounceMinutes,
                'maintenance_auto_refresh_seconds' => $maintenanceRefreshSeconds,
                'maintenance_title' => $maintenanceTitle,
                'maintenance_message' => $maintenanceMessage,
                ], $currentAdminUserId);
            } catch (Throwable $exception) {
                if ($newLogoPath !== null) {
                    $categoryImageService->remove($newLogoPath);
                }
                throw $exception;
            }
            if (($newLogoPath !== null || $removeSystemLogo) && $currentLogoPath !== '') {
                $categoryImageService->remove($currentLogoPath);
            }
            $usageAuditService->log('admin_action', $currentAdminUserId, 'ADMIN', null, [
                'action' => 'system_settings_updated',
                'maintenance_enabled' => $maintenanceEnabled,
                'cors_enabled' => $corsEnabled,
                'system_logo_updated' => $newLogoPath !== null || $removeSystemLogo,
                'portal_theme' => $selectedPortalTheme,
            ]);
            header('Location: index.php?tab=configuracoes&msg=settings_saved');
            exit;
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}
