<?php
if (!$isGlobalAdminCurrent) {
    http_response_code(403);
    echo '<p class="text-xs text-red-600">Somente o Super Admin pode gerenciar subgrupos.</p>';
    return;
}
if (!$groupMembershipService->isAvailable()) {
    echo '<p class="text-xs text-slate-500">A opção de subgrupos ainda não está disponível.</p>';
    return;
}
$childGroups = $groupMembershipService->getRelations($groupId);
$parentGroups = $groupMembershipService->getRelations($groupId, true);
$availableChildren = $groupMembershipService->getAvailableChildren($groupId);
$inheritedMembers = array_values(array_filter($groupMembershipService->getEffectiveMembers($groupId),
    static fn(array $member): bool => !filter_var($member['is_direct'], FILTER_VALIDATE_BOOLEAN)));
$escapeGroup = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<section class="bg-white dark:bg-[#353842] border border-slate-200 dark:border-[#454956] rounded p-5 space-y-5" data-group-nesting>
    <div>
        <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Subgrupos da equipe</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Os membros dos subgrupos recebem os acessos ao conteúdo desta equipe. Cada subgrupo mantém seus próprios acessos.</p>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Todas as equipes do caminho precisam estar ativas. Capacidades administrativas de sistema são concedidas apenas aos membros diretos.</p>
        <?php if (!filter_var($grpData['active'], FILTER_VALIDATE_BOOLEAN)): ?>
            <p class="text-xs text-amber-700 dark:text-amber-400 mt-2">Esta equipe está inativa. Os vínculos ficam salvos, mas não transmitem acesso por ela.</p>
        <?php endif; ?>
    </div>
    <form method="POST" action="index.php?tab=editar_grupo&amp;id=<?= (int)$groupId ?>&amp;group_tab=groups" class="flex flex-col sm:flex-row sm:items-end gap-3">
        <input type="hidden" name="group_action" value="add_subgroup">
        <input type="hidden" name="csrf_token" value="<?= $escapeGroup($csrfToken) ?>">
        <input type="hidden" name="group_id" value="<?= (int)$groupId ?>">
        <div class="flex-1 min-w-0">
            <label for="child-group-id" class="block text-xs font-semibold mb-1">Equipe a incluir</label>
            <select id="child-group-id" name="child_group_id" required <?= $availableChildren === [] ? 'disabled' : '' ?> class="input-minimal w-full px-3 py-2 text-xs rounded border border-slate-200 dark:border-[#454956] bg-slate-50 dark:bg-[#2c2e33] text-slate-900 dark:text-slate-100">
                <option value="">Selecione uma equipe</option>
                <?php foreach ($availableChildren as $child): ?>
                    <option value="<?= (int)$child['id'] ?>" <?= (int)($_POST['child_group_id'] ?? 0) === (int)$child['id'] ? 'selected' : '' ?>><?= $escapeGroup($child['name']) ?><?= filter_var($child['active'], FILTER_VALIDATE_BOOLEAN) ? '' : ' (inativa)' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" <?= $availableChildren === [] ? 'disabled' : '' ?> class="px-4 py-2 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-semibold disabled:opacity-40">Incluir subgrupo</button>
    </form>
    <?php if ($availableChildren === []): ?><p class="text-xs text-slate-500">Não há outras equipes disponíveis para inclusão.</p><?php endif; ?>
    <?php if ($childGroups === []): ?>
        <p class="text-xs text-slate-500 py-3">Nenhum subgrupo incluído nesta equipe.</p>
    <?php else: ?>
        <ul class="divide-y divide-slate-100 dark:divide-[#454956]">
            <?php foreach ($childGroups as $child): ?>
                <li class="flex items-center justify-between gap-3 py-3" data-child-group="<?= (int)$child['id'] ?>">
                    <div class="min-w-0">
                        <a class="text-xs font-semibold hover:underline break-words" href="index.php?tab=editar_grupo&amp;id=<?= (int)$child['id'] ?>&amp;group_tab=groups"><?= $escapeGroup($child['name']) ?></a>
                        <span class="text-[11px] text-slate-500 ml-2"><?= filter_var($child['active'], FILTER_VALIDATE_BOOLEAN) ? 'Ativa' : 'Inativa' ?></span>
                    </div>
                    <form method="POST" action="index.php?tab=editar_grupo&amp;id=<?= (int)$groupId ?>&amp;group_tab=groups" data-confirm="Remover o vínculo com <?= $escapeGroup($child['name']) ?>? A equipe e seus membros serão preservados. Os acessos recebidos por este vínculo deixarão de valer." data-confirm-title="Remover subgrupo" data-confirm-label="Remover vínculo" data-confirm-tone="danger">
                        <input type="hidden" name="group_action" value="remove_subgroup">
                        <input type="hidden" name="csrf_token" value="<?= $escapeGroup($csrfToken) ?>">
                        <input type="hidden" name="group_id" value="<?= (int)$groupId ?>">
                        <input type="hidden" name="child_group_id" value="<?= (int)$child['id'] ?>">
                        <button type="submit" class="text-xs text-red-600 dark:text-red-400 font-semibold whitespace-nowrap">Remover vínculo</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <div class="border-t border-slate-200 dark:border-[#454956] pt-4">
        <h3 class="text-xs font-bold">Esta equipe faz parte de</h3>
        <?php if ($parentGroups === []): ?><p class="text-xs text-slate-500 mt-2">Nenhuma equipe superior.</p><?php else: ?>
            <ul class="flex flex-wrap gap-2 mt-2">
                <?php foreach ($parentGroups as $parent): ?>
                    <li><a class="text-xs px-2 py-1 inline-block rounded bg-slate-100 dark:bg-[#2c2e33] hover:underline" href="index.php?tab=editar_grupo&amp;id=<?= (int)$parent['id'] ?>&amp;group_tab=groups"><?= $escapeGroup($parent['name']) ?><?= filter_var($parent['active'], FILTER_VALIDATE_BOOLEAN) ? '' : ' (inativa)' ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="border-t border-slate-200 dark:border-[#454956] pt-4">
        <h3 class="text-xs font-bold">Membros ativos via subgrupos (<?= count($inheritedMembers) ?>)</h3>
        <p class="text-xs text-slate-500 mt-1">Gerencie o vínculo de cada usuário na equipe em que ele foi incluído diretamente.</p>
        <?php if ($inheritedMembers === []): ?><p class="text-xs text-slate-500 mt-2">Nenhum membro indireto ativo.</p><?php else: ?>
            <ul class="divide-y divide-slate-100 dark:divide-[#454956] max-h-72 overflow-y-auto mt-2">
                <?php foreach ($inheritedMembers as $member): ?>
                    <li class="py-2 text-xs"><a class="font-semibold hover:underline" href="index.php?tab=editar_usuario&amp;id=<?= (int)$member['id'] ?>&amp;user_tab=teams"><?= $escapeGroup($member['name']) ?></a><span class="text-slate-500 ml-2">@<?= $escapeGroup($member['username']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
