<?php
if (!isset($runtimeMaintenanceStatus, $appSettings) || empty($runtimeMaintenanceStatus['enabled'])) {
    return;
}
$announceMinutes = max(0, (int)($appSettings['maintenance_announce_minutes'] ?? 60));
$secondsUntilStart = $runtimeMaintenanceStatus['start'] instanceof DateTimeImmutable
    ? $runtimeMaintenanceStatus['start']->getTimestamp() - time()
    : null;
$showScheduledNotice = $runtimeMaintenanceStatus['scheduled']
    && $announceMinutes > 0
    && $secondsUntilStart !== null
    && $secondsUntilStart <= $announceMinutes * 60;
$showActiveNotice = $runtimeMaintenanceStatus['active'];
if (!$showScheduledNotice && !$showActiveNotice) {
    return;
}
$noticeTimezone = new DateTimeZone((string)($appSettings['timezone'] ?? 'America/Sao_Paulo'));
$noticeStart = $runtimeMaintenanceStatus['start'] instanceof DateTimeImmutable ? $runtimeMaintenanceStatus['start']->setTimezone($noticeTimezone)->format('d/m H:i') : null;
$noticeEnd = $runtimeMaintenanceStatus['end'] instanceof DateTimeImmutable ? $runtimeMaintenanceStatus['end']->setTimezone($noticeTimezone)->format('d/m H:i') : null;
$noticeMode = ($appSettings['maintenance_mode'] ?? 'full') === 'read_only' ? 'somente leitura' : 'acesso suspenso';
?>
<aside class="fixed bottom-4 right-4 z-[100] w-[min(390px,calc(100vw-2rem))] rounded-lg border border-amber-200 bg-white p-4 shadow-2xl dark:border-amber-900/60 dark:bg-[#2c2e33]" role="status" aria-live="polite">
    <div class="flex items-start gap-3"><span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-amber-400"></span><div class="min-w-0"><strong class="block text-xs text-slate-900 dark:text-slate-100"><?= $showActiveNotice ? 'Manutenção em andamento' : 'Manutenção programada' ?></strong><p class="mt-1 text-[11px] leading-5 text-slate-500 dark:text-slate-300"><?= htmlspecialchars((string)($appSettings['maintenance_reason'] ?? 'Atualização planejada')) ?><?php if ($showActiveNotice): ?> · <?= $noticeMode ?><?php endif; ?></p><?php if ($noticeStart || $noticeEnd): ?><p class="mt-1 font-mono text-[10px] text-slate-400"><?= $noticeStart ? 'Início ' . htmlspecialchars($noticeStart) : '' ?><?= $noticeStart && $noticeEnd ? ' · ' : '' ?><?= $noticeEnd ? 'Fim ' . htmlspecialchars($noticeEnd) : '' ?></p><?php endif; ?></div></div>
</aside>
