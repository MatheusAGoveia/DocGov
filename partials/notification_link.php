<?php
/** @var int $unreadNotificationCount */
$notificationLink = $notificationLink ?? 'notificacoes.php';
?>
<a href="<?= htmlspecialchars($notificationLink) ?>" class="relative inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white" title="Notificações" aria-label="Notificações">
    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
    <?php if (($unreadNotificationCount ?? 0) > 0): ?>
        <span class="absolute -right-0.5 -top-0.5 min-w-4 rounded-full bg-red-500 px-1 text-center text-[9px] font-bold leading-4 text-white"><?= $unreadNotificationCount > 99 ? '99+' : (int)$unreadNotificationCount ?></span>
    <?php endif; ?>
</a>
