<?php
// Aviso exibido a quem navega sem conta. Espera $isGuestVisitor definido pela página.
if (empty($isGuestVisitor)) {
    return;
}
?>
<div class="mb-4 flex flex-col gap-2 rounded-md border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600 shadow-xs dark:border-[#454956] dark:bg-[#353842] dark:text-slate-300 sm:flex-row sm:items-center sm:justify-between" role="status" data-guest-notice>
    <p class="flex items-center gap-2">
        <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span>Você está navegando como <b>visitante</b>: somente documentos públicos são exibidos.</span>
    </p>
    <a href="<?= htmlspecialchars(GuestAccessService::loginUrl(), ENT_QUOTES, 'UTF-8') ?>" class="shrink-0 font-semibold text-slate-900 underline-offset-2 hover:underline dark:text-white">Entrar com sua conta</a>
</div>
