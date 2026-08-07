// assets/app.js - Filtros, Interações do Navbar, Leitura de Documentos e Tema

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('mysearch-input');
    const navCards = document.querySelectorAll('.nav-card');
    const themeToggleBtn = document.getElementById('theme-toggle');
    const userMenuBtn = document.getElementById('user-menu-btn');
    const userDropdown = document.getElementById('user-dropdown');

    // =========================================================================
    // 1. Menu Dropdown do Usuário
    // =========================================================================
    if (userMenuBtn && userDropdown) {
        userMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            userDropdown.classList.toggle('hidden');
        });

        // Fechar dropdown ao clicar fora
        document.addEventListener('click', (e) => {
            if (!userDropdown.contains(e.target) && !userMenuBtn.contains(e.target)) {
                userDropdown.classList.add('hidden');
            }
        });

        // Fechar dropdown ao pressionar ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                userDropdown.classList.add('hidden');
            }
        });
    }

    // =========================================================================
    // 2. Filtro de Busca em Tempo Real nos Cards da Página Atual
    // =========================================================================
    const normalizeText = (value = '') => value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[^\w\s]/g, '')
        .replace(/\s+/g, ' ')
        .trim();

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = normalizeText(e.target.value);

            navCards.forEach(card => {
                const cardText = normalizeText(card.textContent || '');
                const shouldShow = query === '' || cardText.includes(query);
                const wrapper = card.parentElement || card;
                wrapper.style.display = shouldShow ? '' : 'none';
            });
        });

        // Atalho de teclado "/"
        document.addEventListener('keydown', (e) => {
            if (e.key === '/' && document.activeElement !== searchInput) {
                e.preventDefault();
                searchInput.focus();
            }
        });
    }

    // =========================================================================
    // 3. Modal Apenas para Leitura do Conteúdo do Documento (Nível 4)
    // =========================================================================
    const readerModal = document.getElementById('doc-reader-modal');
    const modalTitle = document.getElementById('modal-doc-title');
    const modalBody = document.getElementById('modal-doc-body');
    const modalLink = document.getElementById('modal-doc-link');
    const closeReaderBtns = document.querySelectorAll('.close-reader-modal');

    const docCards = document.querySelectorAll('.doc-reader-card');
    docCards.forEach(card => {
        card.addEventListener('click', (e) => {
            const docId = card.getAttribute('data-id') || '';
            const title = card.getAttribute('data-title') || '';
            const link = card.getAttribute('data-link') || '';
            const htmlContent = card.querySelector('.doc-html-store')?.innerHTML || '';

            if (docId) {
                fetch('api_user.php?action=record_view&doc_id=' + docId).catch(() => {});
            }

            if (readerModal) {
                modalTitle.textContent = title;
                modalBody.innerHTML = htmlContent || '<p class="text-slate-500 italic">Nenhum conteúdo detalhado disponível.</p>';

                if (link && link.trim() !== '') {
                    modalLink.href = link;
                    modalLink.classList.remove('hidden');
                    modalLink.classList.add('inline-flex');
                } else {
                    modalLink.classList.add('hidden');
                    modalLink.classList.remove('inline-flex');
                }

                readerModal.classList.remove('hidden');
                readerModal.classList.add('flex');
                document.body.style.overflow = 'hidden';
            }
        });
    });

    closeReaderBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            if (readerModal) {
                readerModal.classList.add('hidden');
                readerModal.classList.remove('flex');
                document.body.style.overflow = 'auto';
            }
        });
    });

    if (readerModal) {
        readerModal.addEventListener('click', (e) => {
            if (e.target === readerModal) {
                readerModal.classList.add('hidden');
                readerModal.classList.remove('flex');
                document.body.style.overflow = 'auto';
            }
        });
    }

    // =========================================================================
    // 4. Alternância do Tema Claro (Padrão) / Escuro + Persistência no Usuário
    // =========================================================================
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            const newTheme = isDark ? 'dark' : 'light';
            localStorage.setItem('theme', newTheme);

            // Salva a configuração no perfil do usuário
            fetch('api_user.php?action=update_theme&theme=' + newTheme).catch(() => {});
        });
    }

    // Aplica preferência do localStorage caso não tenha sido definida pelo servidor
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
        document.documentElement.classList.add('dark');
    } else if (savedTheme === 'light') {
        document.documentElement.classList.remove('dark');
    }
});
