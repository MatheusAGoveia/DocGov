<?php
/**
 * authentication/actions/test-connection.php
 * Entradas: $canManageAuthentication, $systemSettingsService, $adAuthService.
 * Estado compartilhado: resposta HTTP/efeitos da acao.
 * Incluido somente pelo despacho administrativo; preserva o escopo da entrada.
 */
if (!defined('DOCGOV_ADMIN_REQUEST') || DOCGOV_ADMIN_REQUEST !== true) {
    http_response_code(404);
    exit;
}


// AJAX: TESTAR CONEXÃO LDAP DO AD VIA ActiveDirectoryAuthService
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['test_ad_connection'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!CsrfService::isValid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'A sessão de segurança expirou. Atualize a página e tente novamente.']);
        exit;
    }
    if (!$canManageAuthentication) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Seu usuário não possui acesso à administração do Active Directory.']);
        exit;
    }
    $testUri = trim((string)($_POST['test_uri'] ?? ''));
    $testCaCert = trim((string)($_POST['test_ca_cert'] ?? ''));
    $testBindDn = trim((string)($_POST['test_bind_dn'] ?? ''));
    $testBindPass = (string)($_POST['test_bind_pass'] ?? '');
    $testDomainKey = strtoupper(trim((string)($_POST['test_domain_key'] ?? '')));
    $testBaseDn = '';
    if ($testDomainKey !== '') {
        $savedDomains = (array)$systemSettingsService->get('ad_domains', []);
        $savedDomain = $savedDomains[$testDomainKey] ?? null;
        if (!is_array($savedDomain)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Domínio não encontrado.']);
            exit;
        }
        try {
            $credentials = SystemSettingsService::mergeAdServiceAccount([
                'service_bind_dn' => $testBindDn,
                'service_bind_password' => $testBindPass,
            ], $savedDomain);
            if ($credentials['service_bind_dn'] === '') {
                throw new InvalidArgumentException('Configure a conta técnica de leitura e sua senha antes de testar a importação.');
            }
            // A senha salva só pode ser reutilizada em um servidor deste domínio.
            $savedUris = preg_split('/\s+/', trim((string)($savedDomain['uri'] ?? ''))) ?: [];
            if ($testBindPass === '' && !in_array($testUri, $savedUris, true)) {
                throw new InvalidArgumentException('Salve o servidor neste domínio antes de usar a senha da conta técnica já configurada.');
            }
            $testBindDn = $credentials['service_bind_dn'];
            $testBindPass = $credentials['service_bind_password'];
            $testBaseDn = trim((string)($_POST['test_base_dn'] ?? $savedDomain['base_dn'] ?? ''));
            if ($testBaseDn === '') throw new InvalidArgumentException('Informe a Base DN para validar a leitura do diretório.');
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
            exit;
        }
    }

    $res = $adAuthService->testServerConnection($testUri, $testCaCert, $testBindDn, $testBindPass, $testBaseDn);
    echo json_encode($res);
    exit;
}
