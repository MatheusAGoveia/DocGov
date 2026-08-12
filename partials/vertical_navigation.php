<?php
$navigationTrail = $navigationTrail ?? [];
?>
<?php if (!empty($navigationTrail)): ?>
    <aside class="navigation-rail min-w-0 lg:sticky lg:top-20 lg:self-start">
        <nav aria-label="Caminho de navegação" class="navigation-trail overflow-x-auto lg:overflow-visible">
        <ol class="flex min-w-max items-center gap-1.5 whitespace-nowrap text-[11px] lg:block lg:min-w-0 lg:space-y-0 lg:whitespace-normal">
            <?php foreach ($navigationTrail as $trailIndex => $trailItem): ?>
                <?php
                    $isLastTrailItem = $trailIndex === array_key_last($navigationTrail);
                    $trailUrl = $trailItem['url'] ?? null;
                    $trailLabel = (string)($trailItem['label'] ?? '');
                ?>
                <?php if ($trailIndex > 0): ?>
                    <li aria-hidden="true" class="select-none text-slate-300 dark:text-slate-600 lg:hidden">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/></svg>
                    </li>
                <?php endif; ?>
                <li class="relative max-w-52 truncate sm:max-w-72 lg:max-w-none lg:min-h-8 lg:pl-5">
                    <span aria-hidden="true" class="absolute left-0 top-1.5 hidden h-2 w-2 rounded-full border <?= $isLastTrailItem ? 'border-emerald-500 bg-emerald-500 shadow-[0_0_0_3px_rgba(16,185,129,0.12)]' : 'border-slate-300 bg-white dark:border-slate-600 dark:bg-[#2c2e33]' ?> lg:block"></span>
                    <?php if (!$isLastTrailItem): ?>
                        <span aria-hidden="true" class="absolute left-[0.22rem] top-4 hidden h-4 w-px bg-slate-200 dark:bg-slate-700 lg:block"></span>
                    <?php endif; ?>
                    <?php if ($trailUrl && !$isLastTrailItem): ?>
                        <a href="<?= htmlspecialchars($trailUrl) ?>" class="block truncate text-slate-400 transition hover:text-slate-900 hover:underline dark:text-slate-500 dark:hover:text-slate-100" title="<?= htmlspecialchars($trailLabel) ?>">
                            <?= htmlspecialchars($trailLabel) ?>
                        </a>
                    <?php else: ?>
                        <span aria-current="page" class="block truncate font-semibold text-slate-700 dark:text-slate-200" title="<?= htmlspecialchars($trailLabel) ?>">
                            <?= htmlspecialchars($trailLabel) ?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        </nav>
    </aside>
<?php endif; ?>
