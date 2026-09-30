<?php
// A autorização do controle é revalidada no backend ao salvar.
?>
<div>
    <label for="<?= htmlspecialchars($subjectVisibilityControlId, ENT_QUOTES, 'UTF-8') ?>" class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Visibilidade</label>
    <select id="<?= htmlspecialchars($subjectVisibilityControlId, ENT_QUOTES, 'UTF-8') ?>" name="visibility" class="input-minimal w-full px-3 py-2 text-xs" <?= $canManageSubjectVisibility ? '' : 'disabled' ?> aria-describedby="<?= htmlspecialchars($subjectVisibilityControlId, ENT_QUOTES, 'UTF-8') ?>-help">
        <option value="private" <?= $subjectVisibilityValue === 'private' ? 'selected' : '' ?>>Privado — acesso por permissão</option>
        <option value="public" <?= $subjectVisibilityValue === 'public' ? 'selected' : '' ?>>Público — todos os usuários autenticados</option>
    </select>
    <p id="<?= htmlspecialchars($subjectVisibilityControlId, ENT_QUOTES, 'UTF-8') ?>-help" class="mt-1 text-[11px] leading-4 text-slate-500 dark:text-slate-400">Público libera a leitura dos conteúdos publicados deste assunto para todos os usuários autenticados. Privado segue as permissões de usuários e equipes. Apenas administradores podem alterar a visibilidade.</p>
</div>
<?php if ($subjectVisibilityCreationMode): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const control = document.getElementById(<?= json_encode($subjectVisibilityControlId) ?>);
    const subcategory = control?.form?.querySelector('select[name="subcategory_id"]');
    const allowedSubcategories = <?= json_encode($subjectVisibilitySubcategoryPermissions) ?>;
    if (!control || !subcategory) return;
    const update = () => {
        control.disabled = allowedSubcategories[subcategory.value] !== true;
        if (control.disabled) control.value = 'private';
    };
    subcategory.addEventListener('change', update);
    new MutationObserver(update).observe(subcategory, { childList: true });
    update();
});
</script>
<?php endif; ?>
