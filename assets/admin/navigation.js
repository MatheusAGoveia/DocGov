// Painel administrativo: navigation.
// Carregado como script classico; nomes globais sao usados pelos formularios.
function toggleSubmenu(id) {
    const sub = document.getElementById(id);
    const arrow = document.getElementById(id + '-arrow') || document.getElementById('submenu-arrow');
    if (sub.classList.contains('hidden')) {
        sub.classList.remove('hidden');
        if (arrow) arrow.style.transform = 'rotate(0deg)';
    } else {
        sub.classList.add('hidden');
        if (arrow) arrow.style.transform = 'rotate(-90deg)';
    }
}

function toggleMobileSidebar() {
    const sb = document.getElementById('sidebar-menu');
    const overlay = document.getElementById('sidebar-overlay');
    sb.classList.toggle('-translate-x-full');
    overlay.classList.toggle('hidden');
}


// =========================================================================
// RECOLHIMENTO E PERSISTÊNCIA DAS SIDEBARS (MENU PRINCIPAL E ÁRVORE)
// =========================================================================
function toggleMainSidebar() {
    const sidebar = document.getElementById('sidebar-menu');
    const arrow = document.getElementById('main-dock-arrow');
    if (!sidebar) return;

    const isCompact = sidebar.classList.contains('sidebar-compact');
    if (!isCompact) {
        sidebar.classList.add('sidebar-compact');
        if (arrow) arrow.classList.add('rotate-180');
        localStorage.setItem('main_sidebar_compact', '1');
    } else {
        sidebar.classList.remove('sidebar-compact');
        if (arrow) arrow.classList.remove('rotate-180');
        localStorage.setItem('main_sidebar_compact', '0');
    }

    window.dispatchEvent(new Event('resize'));
}

// =========================================================================
