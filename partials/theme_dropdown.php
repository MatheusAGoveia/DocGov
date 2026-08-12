<?php
// Partial: Dropdown Completo de Tema (Modo Claro/Escuro/Sistema + 8 Cores Institucionais)
require_once __DIR__ . '/../services/SystemSettingsService.php';
$portalThemesList = SystemSettingsService::portalThemes();
?>
<div class="relative inline-block text-left theme-dropdown-container">
    <button type="button" 
            onclick="toggleThemeDropdown(event, this)" 
            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl border border-slate-200/80 dark:border-slate-700/80 bg-slate-100/70 dark:bg-slate-800/70 hover:bg-slate-200/70 dark:hover:bg-slate-700/80 text-slate-700 dark:text-slate-300 transition text-xs font-semibold shadow-xs"
            aria-label="Alternar tema e cor da interface" 
            title="Escolha o tema e cor de destaque">
        <span class="theme-icon-sun hidden text-amber-500">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </span>
        <span class="theme-icon-moon hidden text-indigo-400">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
        </span>
        <span class="theme-icon-system hidden text-slate-400">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </span>
        
        <span class="theme-color-dot w-2.5 h-2.5 rounded-full shrink-0 border border-black/10 dark:border-white/20"></span>
        
        <span class="theme-active-label hidden sm:inline text-[11px]">Tema</span>
        <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>

    <div class="theme-dropdown-menu hidden absolute right-0 top-full mt-2 w-72 max-h-[28rem] overflow-y-auto rounded-2xl border border-slate-200 dark:border-[#454956] bg-white dark:bg-[#353842] p-3 shadow-2xl shadow-slate-900/15 z-[75] text-xs space-y-3">
        
        <!-- SEÇÃO 1: MODO (CLARO / ESCURO / SISTEMA) -->
        <div>
            <span class="block text-[10px] font-extrabold uppercase tracking-wider text-slate-400 mb-1.5 px-1">Modo de Exibição</span>
            <div class="grid grid-cols-3 gap-1 bg-slate-100 dark:bg-[#2c2e33] p-1 rounded-xl">
                <button type="button" onclick="setAppTheme('light')" class="theme-btn-light flex items-center justify-center gap-1 py-1.5 px-2 rounded-lg font-semibold text-[11px] transition text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white">
                    <svg class="w-3.5 h-3.5 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    Claro
                </button>
                <button type="button" onclick="setAppTheme('dark')" class="theme-btn-dark flex items-center justify-center gap-1 py-1.5 px-2 rounded-lg font-semibold text-[11px] transition text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white">
                    <svg class="w-3.5 h-3.5 text-indigo-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    Escuro
                </button>
                <button type="button" onclick="setAppTheme('system')" class="theme-btn-system flex items-center justify-center gap-1 py-1.5 px-2 rounded-lg font-semibold text-[11px] transition text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white">
                    <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Auto
                </button>
            </div>
        </div>

        <div class="border-t border-slate-100 dark:border-[#454956] pt-2">
            <!-- SEÇÃO 2: COR DE DESTAQUE INSTITUCIONAL -->
            <div class="flex items-center justify-between mb-1.5 px-1">
                <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Cor de Destaque</span>
                <span class="text-[10px] text-slate-400">8 temas</span>
            </div>

            <div class="space-y-0.5">
                <?php foreach ($portalThemesList as $tKey => $tInfo): ?>
                    <button type="button" 
                            onclick="setPortalAccentTheme('<?= $tKey ?>')" 
                            class="theme-color-option-<?= $tKey ?> w-full flex items-center justify-between p-2 rounded-xl text-left transition hover:bg-slate-100 dark:hover:bg-[#2c2e33] group">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="w-4 h-4 rounded-full shrink-0 shadow-xs border border-black/10 dark:border-white/20 transition-transform group-hover:scale-110" style="background-color: <?= $tInfo['accent'] ?>;"></span>
                            <div class="truncate">
                                <p class="font-bold text-xs text-slate-800 dark:text-slate-100 truncate leading-tight"><?= htmlspecialchars($tInfo['label']) ?></p>
                                <p class="text-[10px] text-slate-500 dark:text-slate-400 truncate leading-tight"><?= htmlspecialchars($tInfo['description']) ?></p>
                            </div>
                        </div>
                        <span class="theme-color-check-<?= $tKey ?> hidden font-bold text-xs text-emerald-500 ml-2">✓</span>
                    </button>
                <?php endforeach; ?>
            </div>
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

    window.applyAppThemeUI = function(mode, portalThemeKey) {
        const isDark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (isDark) {
            document.documentElement.classList.add('dark');
            document.documentElement.classList.remove('light');
        } else {
            document.documentElement.classList.remove('dark');
            document.documentElement.classList.add('light');
        }

        if (portalThemeKey) {
            document.documentElement.setAttribute('data-portal-theme', portalThemeKey);
        } else {
            portalThemeKey = document.documentElement.getAttribute('data-portal-theme') || 'emerald';
        }

        const activeAccentHex = PORTAL_ACCENT_COLORS[portalThemeKey] || '#0f8f6f';

        document.querySelectorAll('.theme-dropdown-container').forEach(container => {
            const sun = container.querySelector('.theme-icon-sun');
            const moon = container.querySelector('.theme-icon-moon');
            const system = container.querySelector('.theme-icon-system');
            const colorDot = container.querySelector('.theme-color-dot');

            if (sun) sun.classList.toggle('hidden', mode !== 'light');
            if (moon) moon.classList.toggle('hidden', mode !== 'dark');
            if (system) system.classList.toggle('hidden', mode !== 'system');

            if (colorDot) colorDot.style.backgroundColor = activeAccentHex;

            // Ativa pill de modo
            ['light', 'dark', 'system'].forEach(m => {
                const btn = container.querySelector('.theme-btn-' + m);
                if (btn) {
                    if (m === mode) {
                        btn.className = 'theme-btn-' + m + ' flex items-center justify-center gap-1 py-1.5 px-2 rounded-lg font-bold text-[11px] bg-white dark:bg-[#353842] text-slate-900 dark:text-white shadow-xs';
                    } else {
                        btn.className = 'theme-btn-' + m + ' flex items-center justify-center gap-1 py-1.5 px-2 rounded-lg font-semibold text-[11px] text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white';
                    }
                }
            });

            // Ativa checkmarks de cor de destaque
            Object.keys(PORTAL_ACCENT_COLORS).forEach(key => {
                const check = container.querySelector('.theme-color-check-' + key);
                const opt = container.querySelector('.theme-color-option-' + key);
                if (check) check.classList.toggle('hidden', key !== portalThemeKey);
                if (opt) {
                    if (key === portalThemeKey) {
                        opt.classList.add('bg-slate-100', 'dark:bg-[#2c2e33]');
                    } else {
                        opt.classList.remove('bg-slate-100', 'dark:bg-[#2c2e33]');
                    }
                }
            });
        });
    };

    window.setAppTheme = function(mode) {
        if (!['light', 'dark', 'system'].includes(mode)) mode = 'light';
        localStorage.setItem('theme', mode);
        const currentPortalTheme = localStorage.getItem('portal_theme') || document.documentElement.getAttribute('data-portal-theme') || 'emerald';
        window.applyAppThemeUI(mode, currentPortalTheme);
        fetch('api_user.php?action=update_theme&theme=' + mode).catch(() => {});
    };

    window.setPortalAccentTheme = function(themeKey) {
        if (!PORTAL_ACCENT_COLORS[themeKey]) themeKey = 'emerald';
        localStorage.setItem('portal_theme', themeKey);
        const currentMode = localStorage.getItem('theme') || 'system';
        window.applyAppThemeUI(currentMode, themeKey);
        document.querySelectorAll('.theme-dropdown-menu').forEach(m => m.classList.add('hidden'));
        fetch('api_user.php?action=update_portal_theme&theme=' + themeKey).catch(() => {});
    };

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.theme-dropdown-container')) {
            document.querySelectorAll('.theme-dropdown-menu').forEach(m => m.classList.add('hidden'));
        }
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        const currentTheme = localStorage.getItem('theme') || 'system';
        const currentPortalTheme = localStorage.getItem('portal_theme') || document.documentElement.getAttribute('data-portal-theme') || 'emerald';
        if (currentTheme === 'system') window.applyAppThemeUI('system', currentPortalTheme);
    });

    document.addEventListener('DOMContentLoaded', () => {
        const savedTheme = localStorage.getItem('theme') || 'system';
        const savedPortalTheme = localStorage.getItem('portal_theme') || document.documentElement.getAttribute('data-portal-theme') || 'emerald';
        window.applyAppThemeUI(savedTheme, savedPortalTheme);
    });
}
</script>
