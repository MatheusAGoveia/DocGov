// Aplica a escolha manual antes dos estilos para evitar o flash do tema claro.
(function () {
    try {
        const appearance = localStorage.getItem('theme') === 'dark' ? 'dark' : 'light';
        document.documentElement.classList.toggle('dark', appearance === 'dark');
        document.documentElement.classList.toggle('light', appearance === 'light');
        const accent = localStorage.getItem('portal_theme');
        if (accent) document.documentElement.setAttribute('data-portal-theme', accent);
    } catch (_) {
        // Armazenamento indisponível: o modo claro continua utilizável.
    }
})();
