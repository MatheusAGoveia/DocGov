<?php
// Partial: Aparência manual e cores institucionais de destaque
require_once __DIR__ . '/../services/SystemSettingsService.php';
$portalThemesList = SystemSettingsService::portalThemes();
?>
<div class="relative inline-block text-left theme-dropdown-container">
    <button type="button" 
            onclick="toggleThemeDropdown(event, this)" 
            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl border border-slate-200/80 bg-slate-100/70 hover:bg-slate-200/70 text-slate-700 transition text-xs font-semibold shadow-xs"
            aria-label="Escolher aparência e cor de destaque" aria-haspopup="true" aria-expanded="false"
            title="Escolher aparência e cor de destaque">
        <span class="theme-color-dot w-2.5 h-2.5 rounded-full shrink-0 border border-black/10"></span>
        <span class="theme-active-label hidden sm:inline text-[11px]">Tema</span>
        <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>

    <div class="theme-dropdown-menu hidden absolute right-0 top-full mt-2 w-72 max-w-[calc(100vw-1rem)] max-h-[min(36rem,80vh)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-2xl shadow-slate-900/15 z-[75] text-xs space-y-2">
        <div class="px-1 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Aparência</div>
        <div class="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 theme-appearance-picker" role="group" aria-label="Aparência da interface">
            <button type="button" class="theme-appearance-option" data-appearance="light" onclick="setPortalAppearance('light')" aria-pressed="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
                Claro
            </button>
            <button type="button" class="theme-appearance-option" data-appearance="dark" onclick="setPortalAppearance('dark')" aria-pressed="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.4 15.5A8.5 8.5 0 0 1 8.5 3.6 8.5 8.5 0 1 0 20.4 15.5Z"/></svg>
                Escuro
            </button>
        </div>
        <div class="theme-section-divider"></div>
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
    const PORTAL_THEME_API_URL = <?= json_encode($themeApiUrl ?? 'api_user.php') ?>;

    window.applyPortalAppearance = function(mode) {
        const appearance = mode === 'dark' ? 'dark' : 'light';
        document.documentElement.classList.toggle('dark', appearance === 'dark');
        document.documentElement.classList.toggle('light', appearance === 'light');
        document.querySelectorAll('.theme-appearance-option').forEach(option => {
            option.setAttribute('aria-pressed', option.dataset.appearance === appearance ? 'true' : 'false');
        });
    };

    window.setPortalAppearance = function(mode) {
        const appearance = mode === 'dark' ? 'dark' : 'light';
        try { localStorage.setItem('theme', appearance); } catch (_) {}
        window.applyPortalAppearance(appearance);
        document.querySelectorAll('.theme-dropdown-menu').forEach(menu => menu.classList.add('hidden'));
        document.querySelectorAll('.theme-dropdown-container > button').forEach(button => button.setAttribute('aria-expanded', 'false'));
    };

    window.toggleThemeDropdown = function(event, btn) {
        event.stopPropagation();
        const container = btn.closest('.theme-dropdown-container');
        const menu = container.querySelector('.theme-dropdown-menu');
        if (!menu) return;
        const isHidden = menu.classList.contains('hidden');
        document.querySelectorAll('.theme-dropdown-menu').forEach(m => m.classList.add('hidden'));
        if (isHidden) {
            menu.classList.remove('hidden');
            if (window.innerWidth <= 640) {
                const bounds = btn.getBoundingClientRect();
                const width = Math.min(288, window.innerWidth - 16);
                menu.style.position = 'fixed';
                menu.style.width = width + 'px';
                menu.style.left = Math.max(8, Math.min(bounds.right - width, window.innerWidth - width - 8)) + 'px';
                menu.style.right = 'auto';
                menu.style.top = Math.min(bounds.bottom + 8, window.innerHeight - Math.min(menu.scrollHeight, window.innerHeight * 0.8) - 8) + 'px';
                menu.style.marginTop = '0';
            } else {
                for (const property of ['position', 'width', 'left', 'right', 'top', 'marginTop']) menu.style[property] = '';
            }
        }
        btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
    };

    window.applyAppThemeUI = function(portalThemeKey) {
        if (portalThemeKey) {
            document.documentElement.setAttribute('data-portal-theme', portalThemeKey);
        } else {
            portalThemeKey = document.documentElement.getAttribute('data-portal-theme') || 'emerald';
        }

        document.querySelectorAll('.theme-dropdown-container').forEach(container => {
            const colorDot = container.querySelector('.theme-color-dot');
            if (colorDot) colorDot.style.backgroundColor = 'var(--accent)';

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
        try { localStorage.setItem('portal_theme', themeKey); } catch (_) {}
        window.applyAppThemeUI(themeKey);
        document.querySelectorAll('.theme-dropdown-menu').forEach(m => m.classList.add('hidden'));
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        if (!csrfToken) return;
        fetch(PORTAL_THEME_API_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CSRF-Token': csrfToken,
            },
            body: new URLSearchParams({ action: 'update_portal_theme', theme: themeKey }),
        }).catch(() => {});
    };

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.theme-dropdown-container')) {
            document.querySelectorAll('.theme-dropdown-menu').forEach(m => m.classList.add('hidden'));
            document.querySelectorAll('.theme-dropdown-container > button').forEach(button => button.setAttribute('aria-expanded', 'false'));
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.theme-dropdown-menu').forEach(menu => menu.classList.add('hidden'));
            document.querySelectorAll('.theme-dropdown-container > button').forEach(button => button.setAttribute('aria-expanded', 'false'));
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        let savedPortalTheme = document.documentElement.getAttribute('data-portal-theme') || 'emerald';
        let appearance = 'light';
        try {
            savedPortalTheme = localStorage.getItem('portal_theme') || savedPortalTheme;
            appearance = localStorage.getItem('theme') === 'dark' ? 'dark' : 'light';
        } catch (_) {}
        window.applyPortalAppearance(appearance);
        window.applyAppThemeUI(savedPortalTheme);
    });
}
</script>
