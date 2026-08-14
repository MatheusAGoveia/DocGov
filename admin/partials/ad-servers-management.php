<?php
// admin/partials/ad-servers-management.php
if (!$isGlobalAdminCurrent) {
    http_response_code(403);
    echo '<div class="p-6 text-center font-bold text-red-600">Acesso restrito. Apenas administradores globais possuem permissão para gerenciar a autenticação corporativa.</div>';
    return;
}

$adAuthEnabled = (bool)($currentSystemSettings['ad_auth_enabled'] ?? true);
$adDefaultDomain = strtoupper((string)($currentSystemSettings['ad_default_domain'] ?? 'BETIM'));
$adSuperAdminUsersText = implode(', ', (array)($currentSystemSettings['ad_super_admin_users'] ?? ['matheus.damiao', 'marcuss']));
$adIntegratedWindowsEnabled = (bool)($currentSystemSettings['ad_integrated_windows_enabled'] ?? false);
$adDomains = (array)($currentSystemSettings['ad_domains'] ?? []);

if (empty($adDomains)) {
    $adDomains = [
        'BETIM' => [
            'key' => 'BETIM',
            'name' => 'Prefeitura Municipal de Betim',
            'uri' => 'ldaps://diana.betim.pmb:636',
            'base_dn' => 'DC=betim,DC=pmb',
            'dns_domain' => 'betim.pmb',
            'netbios_domain' => 'BETIM',
            'ca_certificate' => __DIR__ . '/../../config/certs/diana.betim.pmb.pem',
            'service_bind_dn' => '',
            'service_bind_password' => '',
            'enabled' => true,
            'replication_enabled' => true,
            'is_primary' => true,
        ],
        'SAUDE' => [
            'key' => 'SAUDE',
            'name' => 'Secretaria de Saúde',
            'uri' => '',
            'base_dn' => '',
            'dns_domain' => 'saude.betim.pmb',
            'netbios_domain' => 'SAUDE',
            'ca_certificate' => '',
            'service_bind_dn' => '',
            'service_bind_password' => '',
            'enabled' => true,
            'replication_enabled' => true,
            'is_primary' => false,
        ]
    ];
}

$primaryKey = null;
foreach ($adDomains as $k => $d) {
    if (!empty($d['is_primary'])) {
        $primaryKey = strtoupper($k);
        break;
    }
}
if (!$primaryKey) {
    $primaryKey = isset($adDomains[$adDefaultDomain]) ? $adDefaultDomain : strtoupper((string)array_key_first($adDomains));
}

$selectedDomainKey = strtoupper(trim((string)($_GET['domain'] ?? '')));
$currentDomain = null;
if ($selectedDomainKey !== '' && isset($adDomains[$selectedDomainKey])) {
    $currentDomain = $adDomains[$selectedDomainKey];
}

$subtab = trim((string)($_GET['subtab'] ?? 'servers'));

// Buscar registros recentes de auditoria de login
$stmtLogs = $pdo->query("
    SELECT domain_key, username, server_uri, server_name, status, status_message, latency_ms, user_ip, created_at
    FROM ad_auth_logs
    ORDER BY created_at DESC
    LIMIT 50
");
$recentLogs = $stmtLogs ? $stmtLogs->fetchAll(PDO::FETCH_ASSOC) : [];
?>

<div class="mx-auto max-w-5xl space-y-5">

    <!-- BARRA NAVEGAÇÃO DE SUBTABS: SERVIDORES VS AUDITORIA DE LOGINS -->
    <div class="flex items-center justify-between border-b border-slate-200 pb-3 dark:border-[#454956]">
        <div class="flex items-center gap-2">
            <a href="index.php?tab=servidores_ad&subtab=servers" class="rounded-lg px-3.5 py-1.5 text-xs font-bold transition text-decoration-none <?= $subtab !== 'logs' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300' ?>">
                Servidores & Domínios
            </a>
            <a href="index.php?tab=servidores_ad&subtab=logs" class="rounded-lg px-3.5 py-1.5 text-xs font-bold transition text-decoration-none <?= $subtab === 'logs' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300' ?>">
                Auditoria de Logins AD (<?= count($recentLogs) ?>)
            </a>
        </div>

        <button type="button" id="btn-trigger-health-check" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 shadow-2xs transition hover:bg-slate-50 dark:border-slate-700 dark:bg-[#353842] dark:text-slate-200">
            <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Verificar Saúde dos Servidores
        </button>
    </div>

    <!-- PAINEL VIVO DE MONITOR DE SAÚDE DOS SERVIDORES (LIVE HEALTH CHECK) -->
    <div id="live-health-container" class="hidden rounded-lg border border-slate-200 bg-white p-4 shadow-2xs dark:border-[#454956] dark:bg-[#353842]">
        <div class="mb-3 flex items-center justify-between border-b border-slate-100 pb-2 dark:border-[#454956]">
            <span class="text-xs font-bold text-slate-800 dark:text-slate-200">Monitor de Saúde dos Servidores LDAP (Tempo Real)</span>
            <span id="health-check-spinner" class="hidden text-[11px] font-semibold text-slate-500">Testando conectividade de todos os controladores de domínio...</span>
        </div>
        <div id="health-check-results-grid" class="grid gap-2.5 sm:grid-cols-2 md:grid-cols-3"></div>
    </div>

    <?php if ($subtab === 'logs'): ?>
        <!-- ================================================================= -->
        <!-- ABA: PAINEL DE AUDITORIA DE LOGINS AD E DIAGNÓSTICO EM TEMPO REAL  -->
        <!-- ================================================================= -->
        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-2xs dark:border-[#454956] dark:bg-[#353842]">
            <div class="mb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3 dark:border-[#454956]">
                <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Histórico de Auditoria e Tentativas de Login AD</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Rastreabilidade completa de logins corporativos, status de resposta e latência de rede.</p>
                </div>

                <div class="flex items-center gap-2">
                    <a href="index.php?export_ad_audit_logs=1" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 shadow-2xs transition hover:bg-slate-50 dark:border-slate-700 dark:bg-[#353842] dark:text-slate-200 text-decoration-none">
                        <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Exportar Relatório (CSV)
                    </a>
                    
                    <button type="button" onclick="window.print()" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 shadow-2xs transition hover:bg-slate-50 dark:border-slate-700 dark:bg-[#353842] dark:text-slate-200">
                        <svg class="h-3.5 w-3.5 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        Imprimir Relatório (PDF)
                    </button>
                </div>
            </div>

            <?php if (empty($recentLogs)): ?>
                <div class="p-8 text-center text-xs text-slate-400">Nenhum log de autenticação AD registrado recentemente.</div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:bg-[#2c2e33] dark:text-slate-400">
                            <tr>
                                <th class="px-3 py-2.5">Data / Hora</th>
                                <th class="px-3 py-2.5">Domínio & Usuário</th>
                                <th class="px-3 py-2.5">Servidor LDAP</th>
                                <th class="px-3 py-2.5">Status</th>
                                <th class="px-3 py-2.5">Latência</th>
                                <th class="px-3 py-2.5">IP de Origem</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-mono text-[11px] dark:divide-[#454956]">
                            <?php foreach ($recentLogs as $log): ?>
                                <?php 
                                $st = (string)($log['status'] ?? '');
                                $statusBadge = match($st) {
                                    'success' => '<span class="inline-flex items-center gap-1 rounded bg-emerald-100 px-2 py-0.5 font-bold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">Sucesso</span>',
                                    'invalid_credentials' => '<span class="inline-flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 font-bold text-red-800 dark:bg-red-950/60 dark:text-red-300">Senha Incorreta</span>',
                                    'account_disabled' => '<span class="inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-0.5 font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">Bloqueada no AD</span>',
                                    'password_expired' => '<span class="inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-0.5 font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">Senha Expirada / Alteração Obrigatória</span>',
                                    'server_unreachable' => '<span class="inline-flex items-center gap-1 rounded bg-slate-200 px-2 py-0.5 font-bold text-slate-800 dark:bg-slate-800 dark:text-slate-200">Inacessível</span>',
                                    default => '<span class="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 text-slate-700">' . htmlspecialchars($st) . '</span>',
                                };
                                ?>
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-[#2c2e33]/50">
                                    <td class="px-3 py-2 text-slate-500 dark:text-slate-400"><?= htmlspecialchars((string)$log['created_at']) ?></td>
                                    <td class="px-3 py-2 font-bold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($log['domain_key'] . '\\' . $log['username']) ?></td>
                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300 truncate max-w-xs" title="<?= htmlspecialchars((string)$log['server_uri']) ?>"><?= htmlspecialchars((string)$log['server_uri']) ?></td>
                                    <td class="px-3 py-2"><?= $statusBadge ?></td>
                                    <td class="px-3 py-2 font-bold <?= (int)$log['latency_ms'] < 30 ? 'text-emerald-600' : 'text-amber-600' ?>"><?= (int)$log['latency_ms'] ?>ms</td>
                                    <td class="px-3 py-2 text-slate-500 dark:text-slate-400"><?= htmlspecialchars((string)$log['user_ip']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($currentDomain !== null): ?>
        <!-- ================================================================= -->
        <!-- VISÃO DETALHADA DO DOMÍNIO SELECIONADO                            -->
        <!-- ================================================================= -->
        <div class="flex items-center justify-between">
            <a href="index.php?tab=servidores_ad" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white text-decoration-none">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Voltar para Lista de Domínios
            </a>
            
            <div class="flex items-center gap-2">
                <span class="rounded px-2.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wide <?= !empty($currentDomain['is_primary']) ? 'bg-amber-100 text-amber-900 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' ?>">
                    <?= !empty($currentDomain['is_primary']) ? 'Domínio Principal' : 'Domínio Secundário' ?>
                </span>

                <?php if (count($adDomains) > 1): ?>
                    <form method="POST" action="index.php?tab=servidores_ad" onsubmit="return confirm('Deseja realmente excluir o domínio corporativo [<?= htmlspecialchars($currentDomain['key']) ?>]?');" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="delete_ad_domain" value="<?= htmlspecialchars($currentDomain['key']) ?>">
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-700 hover:bg-red-100 transition dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Excluir Domínio
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="index.php?tab=servidores_ad&domain=<?= htmlspecialchars($currentDomain['key']) ?>" class="space-y-5" id="form-domain-servers">
            <input type="hidden" name="save_ad_settings" value="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="ad_auth_enabled" value="<?= $adAuthEnabled ? '1' : '0' ?>">
            <input type="hidden" name="ad_primary_domain" value="<?= htmlspecialchars($primaryKey) ?>">
            <input type="hidden" name="ad_domains[<?= htmlspecialchars($currentDomain['key']) ?>][key]" value="<?= htmlspecialchars($currentDomain['key']) ?>">

            <!-- CONFIGURAÇÃO GERAL DO DOMÍNIO SELECIONADO -->
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-2xs dark:border-[#454956] dark:bg-[#353842]">
                <div class="mb-4 border-b border-slate-100 pb-3 dark:border-[#454956]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Domínio Corporativo [<?= htmlspecialchars($currentDomain['key']) ?>]</span>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars((string)($currentDomain['name'] ?? $currentDomain['key'])) ?></h2>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Nome de Exibição no Login *</span>
                            <input type="text" name="ad_domains[<?= htmlspecialchars($currentDomain['key']) ?>][name]" value="<?= htmlspecialchars((string)($currentDomain['name'] ?? $currentDomain['key'])) ?>" required class="input-minimal w-full px-3 py-2 text-xs font-bold" placeholder="Ex: Secretaria de Saúde">
                        </label>
                    </div>

                    <div class="flex items-center gap-6 pt-4">
                        <label class="inline-flex cursor-pointer items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200">
                            <input type="checkbox" name="ad_domains[<?= htmlspecialchars($currentDomain['key']) ?>][enabled]" value="1" <?= !isset($currentDomain['enabled']) || $currentDomain['enabled'] ? 'checked' : '' ?> class="h-4 w-4 rounded accent-sky-600">
                            Ativo na Tela de Login
                        </label>

                        <label class="inline-flex cursor-pointer items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200">
                            <input type="checkbox" name="ad_domains[<?= htmlspecialchars($currentDomain['key']) ?>][replication_enabled]" value="1" <?= !isset($currentDomain['replication_enabled']) || $currentDomain['replication_enabled'] ? 'checked' : '' ?> class="h-4 w-4 rounded accent-emerald-600">
                            Replicação de Usuários
                        </label>
                    </div>
                </div>
            </section>

            <!-- SERVIDORES DO DOMÍNIO COM NOME DO SERVIDOR E PROPRIEDADES INDIVIDUAIS -->
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-2xs dark:border-[#454956] dark:bg-[#353842] ad-domain-card" data-domain-key="<?= htmlspecialchars($currentDomain['key']) ?>">
                <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3 dark:border-[#454956]">
                    <div>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">Servidores do Domínio [<?= htmlspecialchars($currentDomain['key']) ?>]</h3>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">Cadastre o Nome do Servidor, Host/IP, Certificado CA e credenciais para cada réplica.</p>
                    </div>
                    <button type="button" id="btn-add-server-row" class="inline-flex items-center gap-1.5 rounded-md bg-sky-600 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-sky-700 shadow-xs">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Cadastrar Novo Servidor
                    </button>
                </div>

                <?php 
                $uriRaw = trim((string)($currentDomain['uri'] ?? ''));
                $serverList = array_values(array_filter(explode(' ', $uriRaw)));
                if (empty($serverList)) {
                    $serverList = ['ldaps://diana.betim.pmb:636'];
                }
                ?>

                <input type="hidden" name="ad_domains[<?= htmlspecialchars($currentDomain['key']) ?>][uri]" id="input-domain-combined-uris" value="<?= htmlspecialchars(implode(' ', $serverList)) ?>">

                <!-- LISTA DE CARDS DE SERVIDORES NOMEADOS -->
                <div class="space-y-4" id="server-rows-container">
                    <?php foreach ($serverList as $idx => $sUri): ?>
                        <?php 
                        $defaultServerName = ($idx === 0) ? "DC1 - Servidor Principal" : "DC" . ($idx + 1) . " - Servidor Réplica";
                        ?>
                        <div class="server-item-row rounded-lg border border-slate-200 bg-slate-50/60 p-4 shadow-2xs dark:border-[#565b68] dark:bg-[#2c2e33]">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200/80 pb-3 dark:border-[#454956]">
                                <div class="flex items-center gap-2">
                                    <span class="server-badge rounded font-mono text-[10px] font-bold px-2 py-0.5 <?= $idx === 0 ? 'bg-amber-100 text-amber-900 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200' ?>">
                                        <?= htmlspecialchars($defaultServerName) ?>
                                    </span>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" class="btn-test-single-server inline-flex items-center gap-1 rounded bg-slate-900 px-3 py-1.5 text-[11px] font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                        Autenticar & Testar Este Servidor
                                    </button>
                                    
                                    <button type="button" class="btn-replicate-server-row inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200" title="Replicar e clonar este servidor para criar um novo servidor réplica editável">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                        Replicar Servidor
                                    </button>

                                    <?php if ($idx > 0): ?>
                                        <button type="button" class="btn-remove-server-row text-xs font-bold text-red-600 hover:text-red-800 px-1" title="Remover este servidor">&times; Remover</button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3 pt-3">
                                <div>
                                    <label class="block">
                                        <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Nome do Servidor *</span>
                                        <input type="text" class="server-name-input input-minimal w-full px-2.5 py-1.5 text-xs font-bold" value="<?= htmlspecialchars($defaultServerName) ?>" placeholder="Ex: DC-PRINCIPAL-BETIM">
                                    </label>
                                </div>

                                <div class="lg:col-span-2">
                                    <label class="block">
                                        <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Endereço URI do Servidor LDAP (LDAPS / Port 636) *</span>
                                        <input type="text" class="server-uri-input input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-uri" value="<?= htmlspecialchars($sUri) ?>" placeholder="ldaps://servidor.dominio:636">
                                    </label>
                                </div>

                                <div>
                                    <label class="block">
                                        <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Certificado CA PEM Específico</span>
                                        <input type="text" class="input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-ca-cert" value="<?= htmlspecialchars((string)($currentDomain['ca_certificate'] ?? '')) ?>" placeholder="C:\caminho\ca.pem">
                                    </label>
                                </div>

                                <div>
                                    <label class="block">
                                        <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Conta Técnica Específica (Bind DN)</span>
                                        <input type="text" class="input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-bind-dn" value="<?= htmlspecialchars((string)($currentDomain['service_bind_dn'] ?? '')) ?>" placeholder="CN=Conta,DC=betim,DC=pmb">
                                    </label>
                                </div>

                                <div>
                                    <label class="block">
                                        <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Senha da Conta Técnica</span>
                                        <input type="password" class="input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-bind-pass" value="<?= htmlspecialchars((string)($currentDomain['service_bind_password'] ?? '')) ?>" placeholder="••••••••">
                                    </label>
                                </div>
                            </div>

                            <div class="ad-test-result-box mt-3 hidden rounded-md p-2.5 text-xs font-semibold"></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- PARÂMETROS GERAIS DO DOMÍNIO -->
                <div class="mt-6 border-t border-slate-100 pt-4 dark:border-[#454956]">
                    <h4 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Parâmetros Estruturais do Domínio</h4>
                    
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="block">
                                <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Base DN (Estrutura de Busca Padrão) *</span>
                                <input type="text" name="ad_domains[<?= htmlspecialchars($currentDomain['key']) ?>][base_dn]" value="<?= htmlspecialchars((string)($currentDomain['base_dn'] ?? '')) ?>" class="input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-base-dn" placeholder="DC=betim,DC=pmb">
                            </label>
                        </div>

                        <div>
                            <label class="block">
                                <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Domínio NetBIOS e DNS</span>
                                <div class="grid grid-cols-2 gap-1">
                                    <input type="text" name="ad_domains[<?= htmlspecialchars($currentDomain['key']) ?>][netbios_domain]" value="<?= htmlspecialchars((string)($currentDomain['netbios_domain'] ?? $currentDomain['key'])) ?>" class="input-minimal w-full px-2 py-1.5 text-xs font-mono uppercase js-test-netbios" placeholder="BETIM">
                                    <input type="text" name="ad_domains[<?= htmlspecialchars($currentDomain['key']) ?>][dns_domain]" value="<?= htmlspecialchars((string)($currentDomain['dns_domain'] ?? '')) ?>" class="input-minimal w-full px-2 py-1.5 text-xs font-mono js-test-dns" placeholder="betim.pmb">
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex justify-end gap-3 pt-2">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-5 py-2.5 text-xs font-bold text-white shadow-xs transition hover:bg-sky-700">
                    Salvar Configurações do Domínio
                </button>
            </div>
        </form>

    <?php else: ?>
        <!-- ================================================================= -->
        <!-- VISÃO PRINCIPAL: LISTA EM FAIXAS LIMPAS E MINIMALISTAS             -->
        <!-- ================================================================= -->
        <div class="flex items-center justify-between pb-2 border-b border-slate-200 dark:border-[#454956]">
            <div>
                <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">Autenticação & Domínios Corporativos</h1>
                <p class="text-xs text-slate-500 dark:text-slate-400">Domínios habilitados para o login da rede e status de ativação.</p>
            </div>
            <button type="button" id="btn-open-add-modal" class="inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-3.5 py-2 text-xs font-bold text-white shadow-xs transition hover:bg-sky-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Cadastrar Novo Domínio
            </button>
        </div>

        <form method="POST" action="index.php?tab=servidores_ad" class="space-y-4">
            <input type="hidden" name="save_ad_settings" value="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="ad_primary_domain" id="input-ad-primary-domain" value="<?= htmlspecialchars($primaryKey) ?>">

            <!-- CONFIGURAÇÃO GLOBAL COMPACTA -->
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white p-3.5 shadow-2xs dark:border-[#454956] dark:bg-[#353842]">
                <label class="inline-flex cursor-pointer items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-200">
                    <input type="checkbox" name="ad_auth_enabled" value="1" class="h-4 w-4 rounded accent-sky-600" <?= $adAuthEnabled ? 'checked' : '' ?>>
                    Habilitar Autenticação no Active Directory
                </label>

                <div class="flex items-center gap-2 flex-1 max-w-md">
                    <span class="text-xs font-semibold text-slate-600 dark:text-slate-400 whitespace-nowrap">Super Admins:</span>
                    <input type="text" name="ad_super_admin_users" required value="<?= htmlspecialchars($adSuperAdminUsersText) ?>" class="input-minimal w-full px-2.5 py-1 text-xs font-mono" placeholder="matheus.damiao, marcuss">
                </div>
            </div>

            <!-- FAIXAS DE DOMÍNIOS EM FORMA DE LISTA -->
            <div class="rounded-lg border border-slate-200 bg-white shadow-2xs dark:border-[#454956] dark:bg-[#353842] overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-100 dark:border-[#454956] flex items-center justify-between bg-slate-50/50 dark:bg-[#2c2e33]/50">
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300">Domínios Corporativos (<?= count($adDomains) ?>)</span>
                    <button type="button" id="btn-replicate-all-users" class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1 text-xs font-bold text-white transition hover:bg-emerald-700">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Replicar Usuários do AD
                    </button>
                </div>

                <div id="replication-global-result-box" class="m-3 hidden rounded-md p-2.5 text-xs font-semibold"></div>

                <div class="divide-y divide-slate-100 dark:divide-[#454956]">
                    <?php foreach ($adDomains as $key => $domain): ?>
                        <?php 
                        $domKeyUpper = strtoupper((string)($domain['key'] ?? $key));
                        $isServerPrimary = ($domKeyUpper === $primaryKey);
                        $isDomainActive = !isset($domain['enabled']) || (bool)$domain['enabled'];
                        $isReplicationEnabled = !isset($domain['replication_enabled']) || (bool)$domain['replication_enabled'];
                        $domainName = (string)($domain['name'] ?? $domKeyUpper);
                        $uriString = (string)($domain['uri'] ?? '');
                        $serverCount = count(array_filter(explode(' ', $uriString)));
                        ?>
                        <div class="ad-domain-card flex flex-col sm:flex-row sm:items-center justify-between p-3.5 gap-3 hover:bg-slate-50/70 dark:hover:bg-[#2c2e33]/50 transition-colors" data-domain-key="<?= htmlspecialchars($domKeyUpper) ?>">
                            
                            <!-- FAIXA - COLUNA ESQUERDA: SIGLA E NOME -->
                            <div class="flex items-center gap-3">
                                <span class="flex h-8 w-14 shrink-0 items-center justify-center rounded font-mono text-xs font-bold <?= $isServerPrimary ? 'bg-amber-100 text-amber-900 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' ?>">
                                    <?= htmlspecialchars($domKeyUpper) ?>
                                </span>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($domainName) ?></span>
                                        <?php if ($isServerPrimary): ?>
                                            <span class="rounded bg-amber-500/20 px-1.5 py-0.2 text-[9px] font-bold text-amber-700 dark:text-amber-300 uppercase tracking-wide">Principal</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[11px] text-slate-400 font-mono"><?= $serverCount ?> Servidor(es) LDAP</span>
                                </div>
                            </div>

                            <!-- FAIXA - COLUNA DIREITA: STATUS & NAVEGAÇÃO / EXCLUSÃO -->
                            <div class="flex items-center gap-3">
                                <input type="hidden" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][key]" value="<?= htmlspecialchars($domKeyUpper) ?>">
                                <input type="hidden" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][name]" value="<?= htmlspecialchars($domainName) ?>">
                                <input type="hidden" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][uri]" value="<?= htmlspecialchars($uriString) ?>">
                                <input type="hidden" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][base_dn]" value="<?= htmlspecialchars((string)($domain['base_dn'] ?? '')) ?>">
                                <input type="hidden" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][dns_domain]" value="<?= htmlspecialchars((string)($domain['dns_domain'] ?? '')) ?>">
                                <input type="hidden" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][netbios_domain]" value="<?= htmlspecialchars((string)($domain['netbios_domain'] ?? $domKeyUpper)) ?>">
                                <input type="hidden" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][ca_certificate]" value="<?= htmlspecialchars((string)($domain['ca_certificate'] ?? '')) ?>">
                                <input type="hidden" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][service_bind_dn]" value="<?= htmlspecialchars((string)($domain['service_bind_dn'] ?? '')) ?>">
                                <input type="hidden" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][service_bind_password]" value="<?= htmlspecialchars((string)($domain['service_bind_password'] ?? '')) ?>">

                                <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    <input type="checkbox" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][enabled]" value="1" <?= $isDomainActive ? 'checked' : '' ?> class="h-3.5 w-3.5 rounded accent-sky-600">
                                    Ativo
                                </label>

                                <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    <input type="checkbox" name="ad_domains[<?= htmlspecialchars($domKeyUpper) ?>][replication_enabled]" value="1" <?= $isReplicationEnabled ? 'checked' : '' ?> class="h-3.5 w-3.5 rounded accent-emerald-600">
                                    Replicação
                                </label>

                                <a href="index.php?tab=servidores_ad&domain=<?= htmlspecialchars($domKeyUpper) ?>" class="inline-flex items-center gap-1 rounded border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-800 transition hover:bg-slate-100 dark:border-slate-700 dark:bg-[#353842] dark:text-slate-200 dark:hover:bg-slate-800 text-decoration-none">
                                    <span>Configurar Servidores</span>
                                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </a>

                                <?php if (count($adDomains) > 1): ?>
                                    <button type="button" class="btn-delete-domain-inline text-xs font-bold text-red-600 hover:text-red-800 px-1" data-domain-key="<?= htmlspecialchars($domKeyUpper) ?>" title="Excluir este domínio">
                                        &times; Excluir
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="flex justify-end pt-1">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-5 py-2 text-xs font-bold text-white shadow-xs transition hover:bg-sky-700">
                    Salvar Alterações
                </button>
            </div>
        </form>

        <form id="form-delete-ad-domain" method="POST" action="index.php?tab=servidores_ad" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="delete_ad_domain" id="input-delete-ad-domain-key" value="">
        </form>
    <?php endif; ?>

</div>

<!-- MODAL ELEGANTE DE CADASTRO DE DOMÍNIO E SERVIDOR CORPORATIVO -->
<div id="modal-create-ad-server" class="fixed inset-0 z-50 flex hidden items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs">
    <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-[#454956] dark:bg-[#353842]">
        <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3 dark:border-[#454956]">
            <div>
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Novo Domínio / Servidor</span>
                <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">Cadastrar Domínio e Servidor</h3>
            </div>
            <button type="button" id="btn-close-ad-modal" class="rounded-md text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="space-y-3.5">
            <div>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Sigla do Domínio *</span>
                    <input type="text" id="modal-ad-key" class="input-minimal w-full px-3 py-2 text-xs font-mono uppercase" placeholder="Ex: SAUDE, BETIM, OBRAS">
                </label>
            </div>

            <div>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Nome do Domínio para Exibição no Login *</span>
                    <input type="text" id="modal-ad-name" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex: Secretaria de Saúde">
                </label>
            </div>

            <div>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Endereço URI do Servidor Inicial</span>
                    <input type="text" id="modal-ad-uri" class="input-minimal w-full px-3 py-2 text-xs font-mono" placeholder="Ex: ldaps://diana.betim.pmb:636">
                </label>
            </div>

            <div id="modal-ad-error" class="hidden rounded-md bg-red-50 p-2.5 text-xs font-semibold text-red-700 dark:bg-red-950/40 dark:text-red-300"></div>
        </div>

        <div class="mt-5 flex items-center justify-end gap-2 border-t border-slate-100 pt-3 dark:border-[#454956]">
            <button type="button" id="btn-cancel-ad-modal" class="rounded-lg border border-slate-200 px-3.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800">
                Cancelar
            </button>
            <button type="button" id="btn-confirm-ad-modal" class="inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-4 py-1.5 text-xs font-bold text-white transition hover:bg-sky-700">
                Cadastrar Domínio
            </button>
        </div>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('modal-create-ad-server');
    const modalKeyInput = document.getElementById('modal-ad-key');
    const modalNameInput = document.getElementById('modal-ad-name');
    const modalUriInput = document.getElementById('modal-ad-uri');
    const modalError = document.getElementById('modal-ad-error');

    const openModal = () => {
        if (!modal) return;
        modalError.classList.add('hidden');
        modalError.textContent = '';
        modalKeyInput.value = '';
        modalNameInput.value = '';
        modalUriInput.value = '';
        modal.classList.remove('hidden');
        setTimeout(() => modalKeyInput.focus(), 50);
    };

    const closeModal = () => {
        if (modal) modal.classList.add('hidden');
    };

    document.getElementById('btn-open-add-modal')?.addEventListener('click', openModal);
    document.getElementById('btn-close-ad-modal')?.addEventListener('click', closeModal);
    document.getElementById('btn-cancel-ad-modal')?.addEventListener('click', closeModal);

    modal?.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });

    document.getElementById('btn-confirm-ad-modal')?.addEventListener('click', () => {
        const rawKey = modalKeyInput.value.trim();
        const keyUpper = rawKey.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
        const name = modalNameInput.value.trim() || keyUpper;
        const uri = modalUriInput.value.trim();

        if (!keyUpper) {
            modalError.textContent = 'Informe uma sigla válida (apenas letras, números e hífen).';
            modalError.classList.remove('hidden');
            return;
        }

        window.location.href = `index.php?tab=servidores_ad&domain=${keyUpper}&new=1&name=${encodeURIComponent(name)}&uri=${encodeURIComponent(uri)}`;
    });

    document.addEventListener('click', e => {
        const btnDelete = e.target.closest('.btn-delete-domain-inline');
        if (btnDelete) {
            const domainKey = btnDelete.dataset.domainKey;
            if (domainKey && confirm(`Deseja realmente excluir o domínio [${domainKey}] e suas configurações de servidores?`)) {
                const formDelete = document.getElementById('form-delete-ad-domain');
                const inputKey = document.getElementById('input-delete-ad-domain-key');
                if (formDelete && inputKey) {
                    inputKey.value = domainKey;
                    formDelete.submit();
                }
            }
        }
    });

    const syncCombinedUris = () => {
        const inputCombined = document.getElementById('input-domain-combined-uris');
        if (!inputCombined) return;
        const uris = [];
        document.querySelectorAll('.server-uri-input').forEach(inp => {
            const v = inp.value.trim();
            if (v !== '') uris.push(v);
        });
        inputCombined.value = uris.join(' ');
    };

    document.getElementById('form-domain-servers')?.addEventListener('submit', syncCombinedUris);

    // ATUALIZAR RÓTULO DO CARDO QUANDO O NOME DO SERVIDOR É EDITADO
    document.addEventListener('input', e => {
        if (e.target.classList.contains('server-name-input')) {
            const card = e.target.closest('.server-item-row');
            const badge = card?.querySelector('.server-badge');
            if (badge) {
                const val = e.target.value.trim();
                if (val !== '') {
                    badge.textContent = val;
                }
            }
        }
    });

    // CADASTRAR NOVA LINHA DE SERVIDOR NO DOMÍNIO SELECIONADO
    document.getElementById('btn-add-server-row')?.addEventListener('click', () => {
        const container = document.getElementById('server-rows-container');
        if (!container) return;
        const count = container.querySelectorAll('.server-item-row').length + 1;
        const defaultName = `DC${count} - Servidor Réplica`;
        const div = document.createElement('div');
        div.className = 'server-item-row rounded-lg border border-slate-200 bg-slate-50/60 p-4 shadow-2xs dark:border-[#565b68] dark:bg-[#2c2e33]';
        div.innerHTML = `
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200/80 pb-3 dark:border-[#454956]">
                <div class="flex items-center gap-2">
                    <span class="server-badge rounded font-mono text-[10px] font-bold px-2 py-0.5 bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200">
                        ${defaultName}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn-test-single-server inline-flex items-center gap-1 rounded bg-slate-900 px-3 py-1.5 text-[11px] font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Autenticar & Testar Este Servidor
                    </button>
                    
                    <button type="button" class="btn-replicate-server-row inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200" title="Replicar este servidor em uma nova réplica editável">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        Replicar Servidor
                    </button>
                    <button type="button" class="btn-remove-server-row text-xs font-bold text-red-600 hover:text-red-800 px-1" title="Remover servidor">&times; Remover</button>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3 pt-3">
                <div>
                    <label class="block">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Nome do Servidor *</span>
                        <input type="text" class="server-name-input input-minimal w-full px-2.5 py-1.5 text-xs font-bold" value="${defaultName}" placeholder="Ex: DC-REPLICA-OBRAS">
                    </label>
                </div>

                <div class="lg:col-span-2">
                    <label class="block">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Endereço URI do Servidor LDAP (LDAPS / Port 636) *</span>
                        <input type="text" class="server-uri-input input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-uri" value="" placeholder="ldaps://servidor-replica.dominio:636">
                    </label>
                </div>

                <div>
                    <label class="block">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Certificado CA PEM Específico</span>
                        <input type="text" class="input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-ca-cert" value="" placeholder="C:\\caminho\\ca.pem">
                    </label>
                </div>

                <div>
                    <label class="block">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Conta Técnica Específica (Bind DN)</span>
                        <input type="text" class="input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-bind-dn" value="" placeholder="CN=Conta,DC=betim,DC=pmb">
                    </label>
                </div>

                <div>
                    <label class="block">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Senha da Conta Técnica</span>
                        <input type="password" class="input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-bind-pass" value="" placeholder="••••••••">
                    </label>
                </div>
            </div>

            <div class="ad-test-result-box mt-3 hidden rounded-md p-2.5 text-xs font-semibold"></div>
        `;
        container.appendChild(div);
        div.querySelector('.server-name-input')?.focus();
        syncCombinedUris();
    });

    // REPLICAR / CLONAR LINHA DE SERVIDOR DENTRO DO DOMÍNIO COM COPIA DE NOME E PROPRIEDADES
    document.addEventListener('click', e => {
        const btn = e.target.closest('.btn-replicate-server-row');
        if (btn) {
            const sourceRow = btn.closest('.server-item-row');
            const sourceName = sourceRow.querySelector('.server-name-input')?.value || 'Servidor Réplica';
            const sourceUri = sourceRow.querySelector('.server-uri-input')?.value || '';
            const sourceCa = sourceRow.querySelector('.js-test-ca-cert')?.value || '';
            const sourceBindDn = sourceRow.querySelector('.js-test-bind-dn')?.value || '';
            const sourceBindPass = sourceRow.querySelector('.js-test-bind-pass')?.value || '';
            const container = document.getElementById('server-rows-container');
            if (!container) return;

            const count = container.querySelectorAll('.server-item-row').length + 1;
            const newServerName = sourceName + ` (Réplica ${count})`;
            const div = document.createElement('div');
            div.className = 'server-item-row rounded-lg border border-slate-200 bg-slate-50/60 p-4 shadow-2xs dark:border-[#565b68] dark:bg-[#2c2e33]';
            div.innerHTML = `
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200/80 pb-3 dark:border-[#454956]">
                    <div class="flex items-center gap-2">
                        <span class="server-badge rounded font-mono text-[10px] font-bold px-2 py-0.5 bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200">
                            ${newServerName}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="btn-test-single-server inline-flex items-center gap-1 rounded bg-slate-900 px-3 py-1.5 text-[11px] font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            Autenticar & Testar Este Servidor
                        </button>
                        
                        <button type="button" class="btn-replicate-server-row inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200" title="Replicar este servidor em uma nova réplica editável">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            Replicar Servidor
                        </button>
                        <button type="button" class="btn-remove-server-row text-xs font-bold text-red-600 hover:text-red-800 px-1" title="Remover servidor">&times; Remover</button>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3 pt-3">
                    <div>
                        <label class="block">
                            <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Nome do Servidor *</span>
                            <input type="text" class="server-name-input input-minimal w-full px-2.5 py-1.5 text-xs font-bold" value="${newServerName}" placeholder="Ex: DC-REPLICA-OBRAS">
                        </label>
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block">
                            <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Endereço URI do Servidor LDAP (LDAPS / Port 636) *</span>
                            <input type="text" class="server-uri-input input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-uri" value="${sourceUri}" placeholder="ldaps://servidor-replica.dominio:636">
                        </label>
                    </div>

                    <div>
                        <label class="block">
                            <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Certificado CA PEM Específico</span>
                            <input type="text" class="input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-ca-cert" value="${sourceCa}" placeholder="C:\\caminho\\ca.pem">
                        </label>
                    </div>

                    <div>
                        <label class="block">
                            <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Conta Técnica Específica (Bind DN)</span>
                            <input type="text" class="input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-bind-dn" value="${sourceBindDn}" placeholder="CN=Conta,DC=betim,DC=pmb">
                        </label>
                    </div>

                    <div>
                        <label class="block">
                            <span class="mb-1 block text-[11px] font-semibold text-slate-700 dark:text-slate-300">Senha da Conta Técnica</span>
                            <input type="password" class="input-minimal w-full px-2.5 py-1.5 text-xs font-mono js-test-bind-pass" value="${sourceBindPass}" placeholder="••••••••">
                        </label>
                    </div>
                </div>

                <div class="ad-test-result-box mt-3 hidden rounded-md p-2.5 text-xs font-semibold"></div>
            `;
            container.appendChild(div);
            div.querySelector('.server-name-input')?.focus();
            syncCombinedUris();
        }
    });

    document.addEventListener('click', e => {
        const btn = e.target.closest('.btn-remove-server-row');
        if (btn) {
            const row = btn.closest('.server-item-row');
            if (row) row.remove();
            syncCombinedUris();
        }
    });

    // EXECUÇÃO DO HEALTH CHECK EM TEMPO REAL DOS SERVIDORES
    const runLiveHealthCheck = async () => {
        const healthContainer = document.getElementById('live-health-container');
        const grid = document.getElementById('health-check-results-grid');
        const spinner = document.getElementById('health-check-spinner');
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

        if (!healthContainer || !grid) return;
        healthContainer.classList.remove('hidden');
        spinner?.classList.remove('hidden');
        grid.innerHTML = '<div class="col-span-full py-4 text-center text-xs font-semibold text-slate-500 animate-pulse">Consultando estado de comunicação dos controladores de domínio e réplicas...</div>';

        const formData = new FormData();
        formData.append('test_ad_health', '1');
        formData.append('csrf_token', csrfToken);

        try {
            const res = await fetch('index.php?tab=servidores_ad', { method: 'POST', body: formData }).then(r => r.json());
            spinner?.classList.add('hidden');
            if (res.success && res.health) {
                grid.innerHTML = '';
                res.health.forEach(srv => {
                    const div = document.createElement('div');
                    const isOnline = srv.online;
                    div.className = `rounded-md border p-2.5 text-xs font-mono flex flex-col justify-between gap-1.5 ${isOnline ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-800 dark:bg-emerald-950/40' : 'border-red-200 bg-red-50/50 dark:border-red-800 dark:bg-red-950/40'}`;
                    div.innerHTML = `
                        <div class="flex items-center justify-between">
                            <span class="font-bold font-sans text-slate-800 dark:text-slate-200">${srv.name}</span>
                            <span class="rounded px-1.5 py-0.2 text-[9px] font-bold uppercase ${isOnline ? 'bg-emerald-200 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' : 'bg-red-200 text-red-800 dark:bg-red-900 dark:text-red-200'}">
                                ${isOnline ? 'Operacional (' + srv.latency_ms + 'ms)' : 'Indisponível'}
                            </span>
                        </div>
                        <span class="text-[10px] text-slate-500 truncate" title="${srv.uri}">${srv.uri}</span>
                    `;
                    grid.appendChild(div);
                });
            } else {
                const errMsg = res.error || 'Erro ao consultar estado de saúde dos servidores.';
                grid.innerHTML = `<div class="col-span-full text-xs font-bold text-red-600">${errMsg}</div>`;
            }
        } catch (err) {
            spinner?.classList.add('hidden');
            grid.innerHTML = `<div class="col-span-full text-xs font-bold text-red-600">Erro na requisição de health check: ${err.message}</div>`;
        }
    };

    document.getElementById('btn-trigger-health-check')?.addEventListener('click', runLiveHealthCheck);

    document.addEventListener('click', async e => {
        const btn = e.target.closest('.btn-test-single-server');
        if (btn) {
            const card = btn.closest('.server-item-row');
            const resultBox = card.querySelector('.ad-test-result-box');
            const serverName = card.querySelector('.server-name-input')?.value || 'Servidor';
            const uri = card.querySelector('.server-uri-input')?.value || '';
            const caCert = card.querySelector('.js-test-ca-cert')?.value || '';
            const bindDn = card.querySelector('.js-test-bind-dn')?.value || '';
            const bindPass = card.querySelector('.js-test-bind-pass')?.value || '';
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

            resultBox.classList.remove('hidden', 'bg-emerald-100', 'text-emerald-800', 'bg-red-100', 'text-red-800', 'dark:bg-emerald-900/50', 'dark:text-emerald-200', 'dark:bg-red-900/50', 'dark:text-red-200');
            resultBox.classList.add('bg-slate-100', 'text-slate-800', 'dark:bg-slate-800', 'dark:text-slate-200');
            resultBox.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="h-4 w-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Conectando e autenticando no servidor [' + serverName + ']...</span>';

            const formData = new FormData();
            formData.append('test_ad_connection', '1');
            formData.append('csrf_token', csrfToken);
            formData.append('test_uri', uri);
            formData.append('test_ca_cert', caCert);
            formData.append('test_bind_dn', bindDn);
            formData.append('test_bind_pass', bindPass);

            try {
                const response = await fetch('index.php?tab=servidores_ad', { method: 'POST', body: formData });
                const res = await response.json();
                resultBox.classList.remove('bg-slate-100', 'text-slate-800', 'dark:bg-slate-800', 'dark:text-slate-200');
                if (res.success) {
                    resultBox.classList.add('bg-emerald-100', 'text-emerald-800', 'dark:bg-emerald-900/50', 'dark:text-emerald-200');
                    resultBox.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg> ' + res.message + '</span>';
                } else {
                    resultBox.classList.add('bg-red-100', 'text-red-800', 'dark:bg-red-900/50', 'dark:text-red-200');
                    resultBox.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg> ' + res.error + '</span>';
                }
            } catch (err) {
                resultBox.classList.remove('bg-slate-100', 'text-slate-800', 'dark:bg-slate-800', 'dark:text-slate-200');
                resultBox.classList.add('bg-red-100', 'text-red-800', 'dark:bg-red-900/50', 'dark:text-red-200');
                resultBox.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg> Erro na requisição: ' + err.message + '</span>';
            }
        }
    });

    document.getElementById('btn-replicate-all-users')?.addEventListener('click', async () => {
        const resultBox = document.getElementById('replication-global-result-box');
        const primaryKey = document.getElementById('input-ad-primary-domain')?.value || '';
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

        if (!resultBox) return;
        resultBox.classList.remove('hidden', 'bg-emerald-100', 'text-emerald-800', 'bg-red-100', 'text-red-800', 'dark:bg-emerald-900/50', 'dark:text-emerald-200', 'dark:bg-red-900/50', 'dark:text-red-200');
        resultBox.classList.add('bg-slate-100', 'text-slate-800', 'dark:bg-slate-800', 'dark:text-slate-200');
        resultBox.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="h-4 w-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Executando varredura e replicação em lote de usuários do AD...</span>';

        const formData = new FormData();
        formData.append('replicate_ad_users', '1');
        formData.append('csrf_token', csrfToken);
        formData.append('domain_key', primaryKey);

        try {
            const response = await fetch('index.php?tab=servidores_ad', { method: 'POST', body: formData });
            const res = await response.json();
            resultBox.classList.remove('bg-slate-100', 'text-slate-800', 'dark:bg-slate-800', 'dark:text-slate-200');
            if (res.success) {
                resultBox.classList.add('bg-emerald-100', 'text-emerald-800', 'dark:bg-emerald-900/50', 'dark:text-emerald-200');
                resultBox.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg> ' + res.message + '</span>';
            } else {
                resultBox.classList.add('bg-red-100', 'text-red-800', 'dark:bg-red-900/50', 'dark:text-red-200');
                resultBox.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg> ' + res.error + '</span>';
            }
        } catch (err) {
            resultBox.classList.remove('bg-slate-100', 'text-slate-800', 'dark:bg-slate-800', 'dark:text-slate-200');
            resultBox.classList.add('bg-red-100', 'text-red-800', 'dark:bg-red-900/50', 'dark:text-red-200');
            resultBox.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg> Erro na requisição: ' + err.message + '</span>';
        }
    });
})();
</script>
