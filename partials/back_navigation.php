<?php
/**
 * Seta de retorno com destino conhecido. Em páginas de consulta, a origem
 * interna pode ser preferida para preservar buscas e listas de favoritos.
 * $backHref e $backLabel são definidos pela página antes deste include.
 */
$backHref = (string)($backHref ?? '');
$backLabel = (string)($backLabel ?? 'Voltar');
$backUseHistory = (bool)($backUseHistory ?? false);
?>
<?php if ($backHref !== ''): ?>
<nav class="mb-4" aria-label="Navegação de retorno">
    <a href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>"
       <?= $backUseHistory ? 'data-docgov-back-history' : '' ?>
       class="inline-flex min-h-9 items-center gap-2 rounded-md px-2 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500 dark:text-slate-300 dark:hover:bg-[#353842] dark:hover:text-white">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 12H5m7 7-7-7 7-7"/></svg>
        <span><?= htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') ?></span>
    </a>
</nav>
<?php if ($backUseHistory): ?>
<script>
if (!window.docgovBackNavigationReady) {
    window.docgovBackNavigationReady = true;
    function docgovSafePreviousPage() {
        if (!document.referrer || history.length < 2) return false;
        try {
            const previous = new URL(document.referrer);
            // Evita voltar a um POST da mesma página após salvar um formulário.
            if (previous.origin !== location.origin || previous.pathname === location.pathname) return false;
            if (/(?:^|\/)(?:login|logout|api_user|download|document-file)\.php$/i.test(previous.pathname)) return false;
            return true;
        } catch (_) {
            return false;
        }
    }
    if (docgovSafePreviousPage()) {
        document.querySelectorAll('a[data-docgov-back-history] span').forEach(function (label) {
            label.textContent = 'Voltar à página anterior';
        });
    }
    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[data-docgov-back-history]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !docgovSafePreviousPage()) return;
        event.preventDefault();
        history.back();
    });
}
</script>
<?php endif; ?>
<?php endif; ?>
