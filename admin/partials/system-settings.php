<?php
$corsOriginsText = implode("\n", (array)($currentSystemSettings['cors_allowed_origins'] ?? []));
$corsMethodsCurrent = (array)($currentSystemSettings['cors_allowed_methods'] ?? []);
$maintenanceScopeCurrent = (array)($currentSystemSettings['maintenance_scope'] ?? ['portal', 'admin', 'api', 'files']);
$maintenanceModeCurrent = (string)($currentSystemSettings['maintenance_mode'] ?? 'full');
$portalThemes = SystemSettingsService::portalThemes();
$currentPortalTheme = SystemSettingsService::normalizePortalTheme($currentSystemSettings['portal_theme'] ?? 'emerald');
$maintenanceBadge = $currentMaintenanceStatus['active'] ? 'Em manutenção' : ($currentMaintenanceStatus['scheduled'] ? 'Agendada' : 'Operação normal');
$maintenanceBadgeClass = $currentMaintenanceStatus['active'] ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300' : ($currentMaintenanceStatus['scheduled'] ? 'bg-sky-500/10 text-sky-700 dark:text-sky-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300');
?>
<div class="mx-auto max-w-6xl space-y-4">
    <header class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-[.18em] text-slate-400">Administração global</p>
            <h1 class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">Configurações do sistema</h1>
            <p class="mt-1 max-w-2xl text-xs text-slate-500 dark:text-slate-400">Identidade do portal, segurança de acesso, integrações e janelas operacionais em um único lugar.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full px-3 py-1.5 text-[11px] font-bold <?= $maintenanceBadgeClass ?>"><?= htmlspecialchars($maintenanceBadge) ?></span>
        </div>
    </header>

    <form method="POST" action="index.php?tab=configuracoes" enctype="multipart/form-data" class="space-y-4">
        <input type="hidden" name="save_system_settings" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
            <div class="mb-4">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Identidade</p>
                <h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Nome e apresentação do portal</h2>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Nome do sistema *</span><input type="text" name="portal_name" required maxlength="60" value="<?= htmlspecialchars((string)$currentSystemSettings['portal_name']) ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex.: DocSec"></label>
                <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Organização *</span><input type="text" name="organization_name" required maxlength="100" value="<?= htmlspecialchars((string)$currentSystemSettings['organization_name']) ?>" class="input-minimal w-full px-3 py-2 text-xs"></label>
                <label class="block md:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Descrição curta *</span><input type="text" name="portal_description" required maxlength="160" value="<?= htmlspecialchars((string)$currentSystemSettings['portal_description']) ?>" class="input-minimal w-full px-3 py-2 text-xs"></label>
                <div class="md:col-span-2 rounded-lg border border-dashed border-slate-300 bg-slate-50/70 p-4 dark:border-[#565b68] dark:bg-[#2c2e33]">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-[#454956] dark:bg-[#353842]">
                            <?php if (!empty($currentSystemSettings['system_logo_path'])): ?>
                                <img id="system-logo-preview" src="../app_logo.php?v=<?= urlencode((string)$currentSystemSettings['system_logo_path']) ?>" alt="Logo atual do sistema" class="h-full w-full object-contain p-1">
                            <?php else: ?>
                                <svg id="system-logo-placeholder" class="h-6 w-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="block text-xs font-semibold text-slate-700 dark:text-slate-300">Logo do sistema</span>
                            <p class="mt-0.5 text-[10px] leading-4 text-slate-400">Exibida no portal, login e painel. Use JPG, PNG ou WebP de até 3 MB; prefira uma imagem quadrada ou horizontal com fundo transparente.</p>
                            <div class="mt-2 flex flex-wrap items-center gap-3">
                                <input id="system-logo-input" type="file" name="system_logo" accept="image/jpeg,image/png,image/webp" class="block max-w-full text-[11px] text-slate-500 file:mr-2 file:rounded-md file:border-0 file:bg-slate-900 file:px-2.5 file:py-1.5 file:text-[10px] file:font-bold file:text-white hover:file:bg-slate-800 dark:text-slate-300 dark:file:bg-white dark:file:text-slate-900">
                                <?php if (!empty($currentSystemSettings['system_logo_path'])): ?><label class="inline-flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-300"><input type="checkbox" name="remove_system_logo" value="1" class="h-3.5 w-3.5 rounded">Remover logo</label><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">E-mail de suporte</span><input type="email" name="support_email" maxlength="255" value="<?= htmlspecialchars((string)$currentSystemSettings['support_email']) ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="suporte@prefeitura.gov.br"></label>
                <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Fuso horário</span><select name="timezone" class="input-minimal w-full px-3 py-2 text-xs"><option value="America/Sao_Paulo" <?= $currentSystemSettings['timezone'] === 'America/Sao_Paulo' ? 'selected' : '' ?>>Brasília — America/Sao_Paulo</option><option value="UTC" <?= $currentSystemSettings['timezone'] === 'UTC' ? 'selected' : '' ?>>UTC</option></select></label>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
            <div class="mb-4">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Aparência</p>
                <h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Tema padrão do sistema</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">A paleta é aplicada ao portal, painel, campos, ações principais e modo escuro. Cada pessoa ainda pode escolher entre claro e escuro.</p>
            </div>
            <div class="settings-theme-grid" role="radiogroup" aria-label="Tema padrão do sistema">
                <?php foreach ($portalThemes as $themeKey => $theme): ?>
                    <?php $themeInputId = 'portal-theme-' . $themeKey; ?>
                    <label class="settings-theme-option" for="<?= htmlspecialchars($themeInputId, ENT_QUOTES, 'UTF-8') ?>" style="--theme-preview-accent: <?= htmlspecialchars($theme['accent'], ENT_QUOTES, 'UTF-8') ?>">
                        <input id="<?= htmlspecialchars($themeInputId, ENT_QUOTES, 'UTF-8') ?>" type="radio" name="portal_theme" value="<?= htmlspecialchars($themeKey, ENT_QUOTES, 'UTF-8') ?>" class="sr-only" <?= $currentPortalTheme === $themeKey ? 'checked' : '' ?> data-portal-theme-option>
                        <span class="settings-theme-option__card">
                            <span class="settings-theme-option__preview">
                                <i></i><i></i><i></i>
                            </span>
                            <span class="settings-theme-option__copy"><strong><?= htmlspecialchars($theme['label']) ?></strong><small><?= htmlspecialchars($theme['description']) ?></small></span>
                            <span class="settings-theme-option__check" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="m5 12 4 4L19 6"/></svg>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Segurança</p>
                <h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Sessão administrativa</h2>
                <label class="mt-4 block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Expirar após inatividade</span><div class="flex items-center gap-2"><input type="number" name="session_timeout_minutes" min="15" max="480" required value="<?= (int)$currentSystemSettings['session_timeout_minutes'] ?>" class="input-minimal w-28 px-3 py-2 text-xs"><span class="text-xs text-slate-400">minutos</span></div><span class="mt-1 block text-[10px] text-slate-400">Entre 15 minutos e 8 horas.</span></label>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
                <div class="flex items-start justify-between gap-3">
                    <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Integração</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Política de CORS</h2></div>
                    <label class="inline-flex cursor-pointer items-center gap-2 text-xs font-semibold"><input type="checkbox" name="cors_enabled" value="1" class="h-4 w-4 rounded" <?= !empty($currentSystemSettings['cors_enabled']) ? 'checked' : '' ?>>Habilitar</label>
                </div>
                <label class="mt-4 block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Origens permitidas</span><textarea name="cors_allowed_origins" rows="3" class="input-minimal w-full px-3 py-2 font-mono text-[11px]" placeholder="https://sistema.betim.mg.gov.br&#10;http://localhost:3000"><?= htmlspecialchars($corsOriginsText) ?></textarea><span class="mt-1 block text-[10px] text-slate-400">Uma origem por linha, sem caminho. Evite * em produção.</span></label>
                <div class="mt-3"><span class="mb-2 block text-xs font-semibold text-slate-700 dark:text-slate-300">Métodos permitidos</span><div class="flex flex-wrap gap-2"><?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method): ?><label class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2 py-1 text-[11px] dark:border-[#565b68]"><input type="checkbox" name="cors_allowed_methods[]" value="<?= $method ?>" <?= in_array($method, $corsMethodsCurrent, true) ? 'checked' : '' ?>><?= $method ?></label><?php endforeach; ?></div></div>
                <label class="mt-3 inline-flex items-center gap-2 text-xs"><input type="checkbox" name="cors_allow_credentials" value="1" class="h-4 w-4 rounded" <?= !empty($currentSystemSettings['cors_allow_credentials']) ? 'checked' : '' ?>>Permitir cookies/credenciais entre origens</label>
            </section>
        </div>

        <section class="overflow-hidden rounded-lg border border-amber-200 bg-white shadow-xs dark:border-amber-900/60 dark:bg-[#353842]">
            <div class="border-b border-amber-100 bg-amber-50/60 p-5 dark:border-amber-900/40 dark:bg-amber-950/10">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div><p class="text-[10px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">Operação controlada</p><h2 class="mt-1 text-base font-bold text-slate-900 dark:text-slate-100">Janela de manutenção</h2><p class="mt-1 max-w-2xl text-xs text-slate-500 dark:text-slate-400">Defina alcance, nível de bloqueio, comunicação e acompanhamento. O Super Admin continua com acesso operacional para acompanhar a intervenção.</p></div>
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-md bg-amber-500/10 px-3 py-2 text-xs font-bold text-amber-700 dark:text-amber-300"><input type="checkbox" name="maintenance_enabled" value="1" class="h-4 w-4 rounded" <?= !empty($currentSystemSettings['maintenance_enabled']) ? 'checked' : '' ?>>Agendar/ativar</label>
                </div>
            </div>
            <div class="space-y-6 p-5">
                <div>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2"><span class="text-xs font-bold text-slate-700 dark:text-slate-200">Período da operação</span><div class="flex flex-wrap gap-1.5"><button type="button" data-maintenance-duration="30" class="maintenance-preset rounded-md border border-slate-200 px-2 py-1 text-[10px] font-bold dark:border-[#565b68]">Agora +30min</button><button type="button" data-maintenance-duration="60" class="maintenance-preset rounded-md border border-slate-200 px-2 py-1 text-[10px] font-bold dark:border-[#565b68]">+1h</button><button type="button" data-maintenance-duration="120" class="maintenance-preset rounded-md border border-slate-200 px-2 py-1 text-[10px] font-bold dark:border-[#565b68]">+2h</button><button type="button" data-maintenance-duration="240" class="maintenance-preset rounded-md border border-slate-200 px-2 py-1 text-[10px] font-bold dark:border-[#565b68]">+4h</button><button type="button" id="maintenance-clear-window" class="rounded-md px-2 py-1 text-[10px] font-bold text-slate-400">Limpar</button></div></div>
                    <div class="grid gap-3 md:grid-cols-2"><label class="block"><span class="mb-1 block text-[11px] text-slate-500">Início</span><input id="maintenance-start" type="datetime-local" name="maintenance_start_at" value="<?= htmlspecialchars($settingsMaintenanceStartLocal) ?>" class="input-minimal w-full px-3 py-2 text-xs"></label><label class="block"><span class="mb-1 block text-[11px] text-slate-500">Fim previsto</span><input id="maintenance-end" type="datetime-local" name="maintenance_end_at" value="<?= htmlspecialchars($settingsMaintenanceEndLocal) ?>" class="input-minimal w-full px-3 py-2 text-xs"></label></div>
                </div>

                <div class="grid gap-5 lg:grid-cols-2">
                    <div><span class="mb-2 block text-xs font-bold text-slate-700 dark:text-slate-200">Modo de operação</span><div class="grid gap-2 sm:grid-cols-2"><label class="cursor-pointer rounded-md border border-slate-200 p-3 dark:border-[#565b68]"><span class="flex items-center gap-2 text-xs font-bold"><input type="radio" name="maintenance_mode" value="full" <?= $maintenanceModeCurrent === 'full' ? 'checked' : '' ?>>Bloqueio total</span><span class="mt-1 block pl-5 text-[10px] leading-4 text-slate-400">Exibe somente a tela de manutenção.</span></label><label class="cursor-pointer rounded-md border border-slate-200 p-3 dark:border-[#565b68]"><span class="flex items-center gap-2 text-xs font-bold"><input type="radio" name="maintenance_mode" value="read_only" <?= $maintenanceModeCurrent === 'read_only' ? 'checked' : '' ?>>Somente leitura</span><span class="mt-1 block pl-5 text-[10px] leading-4 text-slate-400">Consultas continuam; alterações são bloqueadas.</span></label></div></div>
                    <div><span class="mb-2 block text-xs font-bold text-slate-700 dark:text-slate-200">Áreas afetadas</span><div class="grid grid-cols-2 gap-2"><?php foreach (['portal' => 'Portal público', 'admin' => 'Painel administrativo', 'api' => 'APIs e integrações', 'files' => 'Arquivos e downloads'] as $scopeKey => $scopeLabel): ?><label class="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-[11px] dark:border-[#565b68]"><input type="checkbox" name="maintenance_scope[]" value="<?= $scopeKey ?>" <?= in_array($scopeKey, $maintenanceScopeCurrent, true) ? 'checked' : '' ?>><?= $scopeLabel ?></label><?php endforeach; ?></div></div>
                </div>

                <div class="grid gap-3 md:grid-cols-3"><label class="block md:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Motivo da intervenção *</span><input type="text" name="maintenance_reason" required maxlength="160" value="<?= htmlspecialchars((string)$currentSystemSettings['maintenance_reason']) ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex.: atualização do banco de dados"></label><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Referência / chamado</span><input type="text" name="maintenance_reference" maxlength="80" value="<?= htmlspecialchars((string)$currentSystemSettings['maintenance_reference']) ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="CHG-000123"></label><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Responsável</span><input type="text" name="maintenance_responsible" maxlength="100" value="<?= htmlspecialchars((string)$currentSystemSettings['maintenance_responsible']) ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Equipe de TI"></label><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Avisar antes</span><select name="maintenance_announce_minutes" class="input-minimal w-full px-3 py-2 text-xs"><?php foreach ([0 => 'Não avisar', 15 => '15 minutos', 30 => '30 minutos', 60 => '1 hora', 120 => '2 horas', 240 => '4 horas', 1440 => '1 dia'] as $minutes => $label): ?><option value="<?= $minutes ?>" <?= (int)$currentSystemSettings['maintenance_announce_minutes'] === $minutes ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Atualizar tela pública</span><select name="maintenance_auto_refresh_seconds" class="input-minimal w-full px-3 py-2 text-xs"><?php foreach ([0 => 'Manual', 15 => 'A cada 15s', 30 => 'A cada 30s', 60 => 'A cada 1min', 120 => 'A cada 2min'] as $seconds => $label): ?><option value="<?= $seconds ?>" <?= (int)$currentSystemSettings['maintenance_auto_refresh_seconds'] === $seconds ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label></div>

                <div><div class="mb-2 flex items-center justify-between"><span class="text-xs font-bold text-slate-700 dark:text-slate-200">Progresso inicial</span><output id="maintenance-progress-output" class="text-xs font-bold text-amber-600"><?= (int)$currentSystemSettings['maintenance_progress'] ?>%</output></div><input id="maintenance-progress" type="range" name="maintenance_progress" min="0" max="100" step="5" value="<?= (int)$currentSystemSettings['maintenance_progress'] ?>" class="w-full accent-amber-500"></div>

                <div class="grid gap-3 md:grid-cols-2"><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Título da tela</span><input type="text" name="maintenance_title" required maxlength="100" value="<?= htmlspecialchars((string)$currentSystemSettings['maintenance_title']) ?>" class="input-minimal w-full px-3 py-2 text-xs"></label><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Mensagem aos usuários</span><textarea name="maintenance_message" required maxlength="500" rows="3" class="input-minimal w-full px-3 py-2 text-xs"><?= htmlspecialchars((string)$currentSystemSettings['maintenance_message']) ?></textarea></label></div>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
            <div class="mb-3 flex items-center justify-between"><div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Diagnóstico</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Saúde do ambiente</h2></div><span class="text-[10px] text-slate-400">Última alteração <?= $settingsLastUpdate ? date('d/m/Y H:i', strtotime((string)$settingsLastUpdate)) : '—' ?></span></div>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4"><div class="rounded-md bg-slate-50 p-3 dark:bg-[#2c2e33]"><span class="block text-[10px] text-slate-400">Banco</span><strong class="mt-1 block text-xs text-emerald-600 dark:text-emerald-400">PostgreSQL <?= htmlspecialchars($settingsDbVersion) ?></strong></div><div class="rounded-md bg-slate-50 p-3 dark:bg-[#2c2e33]"><span class="block text-[10px] text-slate-400">PHP</span><strong class="mt-1 block text-xs text-slate-700 dark:text-slate-200"><?= htmlspecialchars(PHP_VERSION) ?></strong></div><div class="rounded-md bg-slate-50 p-3 dark:bg-[#2c2e33]"><span class="block text-[10px] text-slate-400">Armazenamento</span><strong class="mt-1 block text-xs <?= $settingsStorageWritable ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' ?>"><?= $settingsStorageWritable ? 'Gravação disponível' : 'Sem permissão de escrita' ?></strong></div><div class="rounded-md bg-slate-50 p-3 dark:bg-[#2c2e33]"><span class="block text-[10px] text-slate-400">LDAP</span><strong class="mt-1 block text-xs <?= extension_loaded('ldap') ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' ?>"><?= extension_loaded('ldap') ? 'Extensão ativa' : 'Extensão ausente' ?></strong></div></div>
        </section>

        <div class="sticky bottom-4 flex justify-end"><button type="submit" class="rounded-md bg-slate-900 px-5 py-2.5 text-xs font-bold text-white shadow-lg transition hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">Salvar configurações</button></div>
    </form>
</div>
<script>
(() => {
    const start = document.getElementById('maintenance-start');
    const end = document.getElementById('maintenance-end');
    const range = document.getElementById('maintenance-progress');
    const output = document.getElementById('maintenance-progress-output');
    const formatLocal = date => { const offset = date.getTimezoneOffset(); return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 16); };
    document.querySelectorAll('.maintenance-preset').forEach(button => button.addEventListener('click', () => { const now = new Date(); const finish = new Date(now.getTime() + Number(button.dataset.maintenanceDuration) * 60000); start.value = formatLocal(now); end.value = formatLocal(finish); }));
    document.getElementById('maintenance-clear-window')?.addEventListener('click', () => { start.value = ''; end.value = ''; });
    range?.addEventListener('input', () => { output.value = `${range.value}%`; output.textContent = `${range.value}%`; });
    document.querySelectorAll('[data-portal-theme-option]').forEach(input => input.addEventListener('change', () => {
        document.documentElement.dataset.portalTheme = input.value;
    }));
    document.getElementById('system-logo-input')?.addEventListener('change', event => {
        const file = event.target.files?.[0];
        if (!file) return;
        const preview = document.getElementById('system-logo-preview');
        const placeholder = document.getElementById('system-logo-placeholder');
        const image = preview || document.createElement('img');
        image.id = 'system-logo-preview';
        image.alt = 'Prévia da nova logo';
        image.className = 'h-full w-full object-contain p-1';
        image.src = URL.createObjectURL(file);
        if (!preview) {
            placeholder?.replaceWith(image);
        }
    });
})();
</script>
