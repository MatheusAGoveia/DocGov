<?php
// Partial: Dropdown de Cores Institucionais de Destaque
require_once __DIR__ . '/../services/SystemSettingsService.php';
$portalThemesList = SystemSettingsService::portalThemes();
?>
<div class="relative inline-block text-left theme-dropdown-container">
    <button type="button" 
            onclick="toggleThemeDropdown(event, this)" 
            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl border border-slate-200/80 bg-slate-100/70 hover:bg-slate-200/70 text-slate-700 transition text-xs font-semibold shadow-xs"
            aria-label="Cor de destaque da interface" 
            title="Escolha a cor de destaque">
        <span class="theme-color-dot w-2.5 h-2.5 rounded-full shrink-0 border border-black/10"></span>
        <span class="theme-active-label hidden sm:inline text-[11px]">Tema</span>
        <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>

    <div class="theme-dropdown-menu hidden absolute right-0 top-full mt-2 w-72 max-h-[28rem] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-2xl shadow-slate-900/15 z-[75] text-xs space-y-2">
        <!-- SEÇÃO: COR DE DESTAQUE INSTITUCIONAL -->
        <div class="flex items-center justify-between mb-1.5 px-1">
            <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Cor de Destaque</span>
            <span class="text-[10px] text-slate-400">8 temas</span>
        </div>

        <div class="space-y-0.5">
            <?php foreach ($portalThemesList as $tKey => $tInfo): ?>
                <button type="button" 
                        onclick="setPortalAccentTheme('<?= $tKey ?>')" 
                        class="theme-color-option-<?= $tKey ?> w-full flex items-center justify-between p-2 rounded-xl text-left transition hover:bg-slate-100 group">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="w-4 h-4 rounded-full shrink-0 shadow-xs border border-black/10 transition-transform group-hover:scale-110" style="background-color: <?= $tInfo['accent'] ?>;"></span>
                        <div class="truncate">
                            <p class="font-bold text-xs text-slate-800 truncate leading-tight"><?= htmlspecialchars($tInfo['label']) ?></p>
                            <p class="text-[10px] text-slate-500 truncate leading-tight"><?= htmlspecialchars($tInfo['description']) ?></p>
                        </div>
                    </div>
                    <span class="theme-color-check-<?= $tKey ?> hidden font-bold text-xs text-emerald-500 ml-2">✓</span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
if (typeof window.toggleThemeDropdown === 'undefined') {
    const PORTAL_ACCENT_COLORS = <?= json_encode(array_map(fn($t) => $t['accent'], $portalThemesList)) ?>;

    window.toggleThemeDropdown = function(event, btn) {
        event.stopPropagation();
        const container = btn.closest('.theme-dropdown-container');
        const menu = container.querySelector('.theme-dropdown-menu');
        if (!menu) return;
        const isHidden = menu.classList.contains('hidden');
        document.querySelectorAll('.theme-dropdown-menu').forEach(m => m.classList.add('hidden'));
        if (isHidden) menu.classList.remove('hidden');
    };

    window.applyAppThemeUI = function(portalThemeKey) {
        // Garantir modo claro
        document.documentElement.classList.remove('dark');
        document.documentElement.classList.add('light');
        localStorage.removeItem('theme');

        if (portalThemeKey) {
            document.documentElement.setAttribute('data-portal-theme', portalThemeKey);
        } else {
            portalThemeKey = document.documentElement.getAttribute('data-portal-theme') || 'emerald';
        }

        const activeAccentHex = PORTAL_ACCENT_COLORS[portalThemeKey] || '#0f8f6f';

        document.querySelectorAll('.theme-dropdown-container').forEach(container => {
            const colorDot = container.querySelector('.theme-color-dot');
            if (colorDot) colorDot.style.backgroundColor = activeAccentHex;

            // Ativa checkmarks de cor de destaque
            Object.keys(PORTAL_ACCENT_COLORS).forEach(key => {
                const check = container.querySelector('.theme-color-check-' + key);
                const opt = container.querySelector('.theme-color-option-' + key);
                if (check) check.classList.toggle('hidden', key !== portalThemeKey);
                if (opt) {
                    if (key === portalThemeKey) {
                        opt.classList.add('bg-slate-100');
                    } else {
                        opt.classList.remove('bg-slate-100');
                    }
                }
            });
        });
    };

    window.setPortalAccentTheme = function(themeKey) {
        if (!PORTAL_ACCENT_COLORS[themeKey]) themeKey = 'emerald';
        localStorage.setItem('portal_theme', themeKey);
        window.applyAppThemeUI(themeKey);
        document.querySelectorAll('.theme-dropdown-menu').forEach(m => m.classList.add('hidden'));
        fetch('api_user.php?action=update_portal_theme&theme=' + themeKey).catch(() => {});
    };

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.theme-dropdown-container')) {
            document.querySelectorAll('.theme-dropdown-menu').forEach(m => m.classList.add('hidden'));
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        const savedPortalTheme = localStorage.getItem('portal_theme') || document.documentElement.getAttribute('data-portal-theme') || 'emerald';
        window.applyAppThemeUI(savedPortalTheme);
    });
}
</script>
