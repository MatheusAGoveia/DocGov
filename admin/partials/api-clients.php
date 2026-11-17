<?php
// admin/partials/api-clients.php — chaves da API de integração (somente Super Admin).
// Espera: $apiClientService, $csrfToken, $apiClientFormInput (opcional, após erro).

$apiClients = $apiClientService->listAll();
// O token em claro existe só neste flash: é descartado após a exibição e
// ignorado se a página não for aberta logo após a criação.
$apiTokenFlashSeconds = 300;
$apiClientNewToken = $_SESSION['api_client_new_token'] ?? null;
unset($_SESSION['api_client_new_token']);
if (!is_array($apiClientNewToken) || time() - (int)($apiClientNewToken['created_at'] ?? 0) > $apiTokenFlashSeconds) {
    $apiClientNewToken = null;
}
$apiClientFormInput = $apiClientFormInput ?? [];
$apiFormValue = static fn(string $key, string $default = ''): string => htmlspecialchars((string)($apiClientFormInput[$key] ?? $default), ENT_QUOTES, 'UTF-8');
$apiFormScope = ($apiClientFormInput['access_scope'] ?? ApiClientService::SCOPE_PUBLIC) === ApiClientService::SCOPE_USER
    ? ApiClientService::SCOPE_USER
    : ApiClientService::SCOPE_PUBLIC;
$apiDate = static function (?string $value, bool $withTime = true): string {
    if ($value === null || $value === '') return '—';
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
};
$apiStatusLabels = [
    'active' => ['Ativa', 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'],
    'revoked' => ['Revogada', 'bg-slate-500/10 text-slate-600 dark:text-slate-300'],
    'expired' => ['Expirada', 'bg-amber-500/10 text-amber-700 dark:text-amber-300'],
    'user_inactive' => ['Conta inativa', 'bg-red-500/10 text-red-700 dark:text-red-300'],
    'user_admin' => ['Bloqueada: conta administradora', 'bg-red-500/10 text-red-700 dark:text-red-300'],
    'inactive' => ['Inativa', 'bg-slate-500/10 text-slate-600 dark:text-slate-300'],
];
$apiTodayPlusOne = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
?>
<div class="mx-auto max-w-6xl space-y-4">
    <header class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842] sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-[.18em] text-slate-400">Somente Super Admin</p>
            <h1 class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">Integrações (API)</h1>
            <p class="mt-1 max-w-2xl text-xs text-slate-500 dark:text-slate-400">Chaves de acesso à API somente leitura em <code class="rounded bg-slate-100 px-1 dark:bg-[#2c2e33]">api/v1/</code>. Elas nunca permitem criar, alterar ou excluir conteúdo e enxergam apenas documentos publicados.</p>
        </div>
        <a href="../api/v1/openapi.json" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3.5 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-50 dark:border-[#565b68] dark:text-slate-200 dark:hover:bg-[#3e424e] text-decoration-none">Especificação OpenAPI &rarr;</a>
    </header>

    <?php if (is_array($apiClientNewToken) && !empty($apiClientNewToken['token'])): ?>
        <section class="rounded-lg border border-amber-300 bg-amber-50 p-5 shadow-xs dark:border-amber-800 dark:bg-amber-950/20" role="alert" data-api-new-token>
            <p class="text-[10px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-300">Copie agora</p>
            <h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Chave criada para “<?= htmlspecialchars((string)$apiClientNewToken['name'], ENT_QUOTES, 'UTF-8') ?>”</h2>
            <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">Por segurança, o DocGov guarda apenas um resumo criptográfico. Esta chave não será exibida novamente; se for perdida, revogue-a e gere outra.</p>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input id="api-new-token" type="text" readonly value="<?= htmlspecialchars((string)$apiClientNewToken['token'], ENT_QUOTES, 'UTF-8') ?>" class="input-minimal w-full px-3 py-2 font-mono text-[11px]" onclick="this.select()" aria-label="Chave da API">
                <button type="button" class="shrink-0 rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-900" onclick="(function(button){const input=document.getElementById('api-new-token');input.select();(navigator.clipboard?navigator.clipboard.writeText(input.value):Promise.reject()).then(()=>{button.textContent='Copiada';}).catch(()=>{document.execCommand('copy');button.textContent='Copiada';});})(this)">Copiar chave</button>
            </div>
        </section>
    <?php endif; ?>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Nova chave</p>
            <h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Gerar acesso para um sistema</h2>
            <form method="POST" action="index.php?tab=integracoes" class="mt-4 space-y-3" data-api-client-form>
                <input type="hidden" name="api_client_action" value="create">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Nome da integração *</span><input type="text" name="name" required minlength="3" maxlength="120" value="<?= $apiFormValue('name') ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex.: Site da Prefeitura"></label>
                <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Finalidade</span><textarea name="description" rows="2" maxlength="500" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Quem usa e para quê"><?= $apiFormValue('description') ?></textarea></label>
                <fieldset>
                    <legend class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">O que a chave pode ler</legend>
                    <label class="flex items-start gap-2 rounded-md border border-slate-200 p-2.5 text-xs dark:border-[#565b68]"><input type="radio" name="access_scope" value="public" class="mt-0.5" <?= $apiFormScope === 'public' ? 'checked' : '' ?> onchange="document.getElementById('api-username-field').classList.add('hidden')"><span><b class="text-slate-800 dark:text-slate-100">Somente documentos públicos</b><span class="block text-[11px] text-slate-500 dark:text-slate-400">Mesmo acervo que um visitante sem conta.</span></span></label>
                    <label class="mt-2 flex items-start gap-2 rounded-md border border-slate-200 p-2.5 text-xs dark:border-[#565b68]"><input type="radio" name="access_scope" value="user" class="mt-0.5" <?= $apiFormScope === 'user' ? 'checked' : '' ?> onchange="document.getElementById('api-username-field').classList.remove('hidden')"><span><b class="text-slate-800 dark:text-slate-100">Leitura de uma conta</b><span class="block text-[11px] text-slate-500 dark:text-slate-400">Mesmos documentos publicados que a conta pode ler. Use uma conta de serviço com permissões mínimas; administradores globais não são aceitos.</span></span></label>
                </fieldset>
                <label id="api-username-field" class="block <?= $apiFormScope === 'user' ? '' : 'hidden' ?>"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Login da conta vinculada</span><input type="text" name="username" maxlength="255" value="<?= $apiFormValue('username') ?>" class="input-minimal w-full px-3 py-2 text-xs" placeholder="Ex.: svc.integracao" autocapitalize="none" spellcheck="false"></label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Expira em</span><input type="date" name="expires_on" min="<?= $apiTodayPlusOne ?>" value="<?= $apiFormValue('expires_on') ?>" class="input-minimal w-full px-2 py-2 text-xs"><span class="mt-1 block text-[10px] text-slate-400">Vazio = sem expiração.</span></label>
                    <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Limite/minuto</span><input type="number" name="rate_limit_per_minute" min="<?= ApiClientService::MIN_RATE_LIMIT ?>" max="<?= ApiClientService::MAX_RATE_LIMIT ?>" required value="<?= $apiFormValue('rate_limit_per_minute', (string)ApiClientService::DEFAULT_RATE_LIMIT) ?>" class="input-minimal w-full px-2 py-2 text-xs"></label>
                </div>
                <button type="submit" class="btn-accent w-full rounded-lg px-3 py-2 text-xs font-bold transition">Gerar chave</button>
            </form>
        </section>

        <section class="min-w-0 rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
            <div class="flex items-center justify-between gap-3">
                <div><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Chaves emitidas</p><h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100"><?= count($apiClients) ?> chave(s)</h2></div>
            </div>
            <?php if ($apiClients === []): ?>
                <p class="mt-4 rounded-md border border-dashed border-slate-300 p-6 text-center text-xs text-slate-500 dark:border-[#565b68] dark:text-slate-400">Nenhuma chave emitida ainda.</p>
            <?php else: ?>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-left text-xs">
                        <thead class="border-b border-slate-200 text-[10px] uppercase tracking-wider text-slate-400 dark:border-[#454956]">
                            <tr><th class="py-2 pr-3">Integração</th><th class="py-2 pr-3">Escopo</th><th class="py-2 pr-3">Situação</th><th class="py-2 pr-3">Último uso</th><th class="py-2 pr-3">Expira</th><th class="py-2"><span class="sr-only">Ações</span></th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-[#454956]">
                            <?php foreach ($apiClients as $apiClientRow): ?>
                                <?php [$apiStatusText, $apiStatusClass] = $apiStatusLabels[$apiClientRow['status']] ?? $apiStatusLabels['inactive']; ?>
                                <tr data-api-client-row="<?= (int)$apiClientRow['id'] ?>">
                                    <td class="py-2.5 pr-3 align-top">
                                        <span class="block font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($apiClientRow['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <code class="block text-[10px] text-slate-400"><?= htmlspecialchars($apiClientRow['token_hint'], ENT_QUOTES, 'UTF-8') ?></code>
                                        <?php if ($apiClientRow['description'] !== ''): ?><span class="mt-0.5 block text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($apiClientRow['description'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                        <span class="mt-0.5 block text-[10px] text-slate-400">Criada em <?= $apiDate($apiClientRow['created_at']) ?><?= $apiClientRow['created_by_name'] ? ' por ' . htmlspecialchars((string)$apiClientRow['created_by_name'], ENT_QUOTES, 'UTF-8') : '' ?></span>
                                    </td>
                                    <td class="py-2.5 pr-3 align-top text-slate-600 dark:text-slate-300">
                                        <?php if ($apiClientRow['access_scope'] === ApiClientService::SCOPE_USER): ?>
                                            Conta: <?= htmlspecialchars((string)($apiClientRow['user_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> <span class="text-slate-400">(<?= htmlspecialchars((string)($apiClientRow['user_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</span>
                                        <?php else: ?>
                                            Documentos públicos
                                        <?php endif; ?>
                                        <span class="block text-[10px] text-slate-400"><?= (int)$apiClientRow['rate_limit_per_minute'] ?> req/min</span>
                                    </td>
                                    <td class="py-2.5 pr-3 align-top"><span class="rounded-full px-2 py-0.5 text-[10px] font-bold <?= $apiStatusClass ?>"><?= $apiStatusText ?></span></td>
                                    <td class="py-2.5 pr-3 align-top text-slate-500 dark:text-slate-400"><?= $apiDate($apiClientRow['last_used_at']) ?></td>
                                    <td class="py-2.5 pr-3 align-top text-slate-500 dark:text-slate-400"><?= $apiDate($apiClientRow['expires_at'], false) ?></td>
                                    <td class="py-2.5 align-top text-right">
                                        <?php if ($apiClientRow['revoked_at'] === null): ?>
                                            <form method="POST" action="index.php?tab=integracoes" class="inline">
                                                <input type="hidden" name="api_client_action" value="revoke">
                                                <input type="hidden" name="api_client_id" value="<?= (int)$apiClientRow['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" data-confirm="Revogar a chave “<?= htmlspecialchars($apiClientRow['name'], ENT_QUOTES, 'UTF-8') ?>”? Os sistemas que a utilizam perderão o acesso imediatamente." data-confirm-title="Revogar chave" data-confirm-label="Revogar" data-confirm-tone="danger" class="rounded-md px-2 py-1 text-[11px] font-bold text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30">Revogar</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-xs dark:border-[#454956] dark:bg-[#353842]">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Como usar</p>
        <h2 class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">Exemplo de chamada</h2>
        <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 p-3 text-[11px] leading-5 text-slate-100"><code>curl -H "Authorization: Bearer dgv_xxxxxxxx_..." \
     "https://&lt;servidor&gt;/api/v1/documents.php?per_page=20&amp;updated_since=2026-11-01T00:00:00-03:00"</code></pre>
        <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">Endpoints: <code>status.php</code>, <code>tree.php</code>, <code>documents.php</code> (lista ou <code>?id=</code>) e <code>document-file.php?id=</code>. Se o servidor web não repassar o cabeçalho <code>Authorization</code> ao PHP, envie a chave em <code>X-API-Key</code>. Nunca coloque a chave na URL.</p>
    </section>
</div>
