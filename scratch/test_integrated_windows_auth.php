<?php
// Teste sem LDAP: REMOTE_USER válido é provisionado localmente como leitor quando
// o login integrado está habilitado, sem criar permissões de recursos.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ActiveDirectoryAuthService.php';

function integratedAssert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$suffix = bin2hex(random_bytes(5));
$ids = [];
$config = [
    'enabled' => true,
    'integrated_windows_enabled' => true,
    'default_domain' => 'BETIM',
    'super_admin_users' => ['matheus.damiao'],
    'domains' => [
        'BETIM' => ['key' => 'BETIM', 'netbios_domain' => 'BETIM', 'dns_domain' => 'betim.pmb', 'aliases' => ['BETIM']],
        'SAUDE' => ['key' => 'SAUDE', 'netbios_domain' => 'SAUDE', 'dns_domain' => 'saude.pmb', 'aliases' => ['SAUDE']],
    ],
];

try {
    $insert = $pdo->prepare("INSERT INTO users (name, username, email, role, active, auth_source, ad_domain) VALUES (?, ?, ?, 'reader', TRUE, 'ad', ?) RETURNING id");
    $insert->execute(['Usuário Betim', "integrated.betim.{$suffix}", "integrated.betim.{$suffix}@example.invalid", 'BETIM']);
    $betimId = (int)$insert->fetchColumn();
    $ids[] = $betimId;
    $insert->execute(['Usuário Saúde', "integrated.saude.{$suffix}", "integrated.saude.{$suffix}@example.invalid", 'SAUDE']);
    $saudeId = (int)$insert->fetchColumn();
    $ids[] = $saudeId;

    $service = new ActiveDirectoryAuthService($pdo, $config);
    $betim = $service->authenticateIntegrated('BETIM\\integrated.betim.' . $suffix);
    integratedAssert($betim['success'] && (int)$betim['user']['id'] === $betimId, 'Identidade BETIM não foi associada ao usuário importado.');

    $saude = $service->authenticateIntegrated('integrated.saude.' . $suffix . '@saude.pmb');
    integratedAssert($saude['success'] && (int)$saude['user']['id'] === $saudeId, 'UPN SAÚDE não foi associado ao usuário importado.');

    $automatic = $service->authenticateIntegrated('BETIM\\integrated.auto.' . $suffix);
    integratedAssert($automatic['success'], 'Usuário do AD não foi provisionado automaticamente no login integrado.');
    integratedAssert(($automatic['user']['role'] ?? '') === 'reader', 'Novo usuário do AD deve iniciar como leitor.');
    $automaticId = (int)($automatic['user']['id'] ?? 0);
    integratedAssert($automaticId > 0, 'Provisionamento automático não retornou um usuário persistido.');
    $ids[] = $automaticId;

    $unknown = $service->authenticateIntegrated('OUTRO\\integrated.betim.' . $suffix);
    integratedAssert(!$unknown['success'] && $unknown['code'] === 'integrated_identity_missing', 'Domínio não configurado foi aceito no login integrado.');

    $disabledConfig = $config;
    $disabledConfig['integrated_windows_enabled'] = false;
    $disabled = (new ActiveDirectoryAuthService($pdo, $disabledConfig))->authenticateIntegrated('BETIM\\integrated.betim.' . $suffix);
    integratedAssert(!$disabled['success'] && $disabled['code'] === 'integrated_disabled', 'Login integrado foi aceito sem habilitação explícita.');

    echo "[OK] Login integrado BETIM/SAÚDE, UPN, domínio desconhecido e bloqueio por configuração validados.\n";
} finally {
    foreach (array_reverse($ids) as $id) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}
