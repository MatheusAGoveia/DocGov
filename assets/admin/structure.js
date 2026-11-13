// Painel administrativo: structure.
// Carregado como script classico; nomes globais sao usados pelos formularios.
// =========================================================================
// NOVO CONTEÚDO — SELETOR SEGMENTADO DE TIPO
// =========================================================================
const NC_TYPES = ['documento', 'categoria', 'subcategoria', 'assunto'];

function ncSwitchType(type) {
    // Ocultar todos os painéis
    NC_TYPES.forEach(t => {
        const panel = document.getElementById('nc-panel-' + t);
        if (panel) panel.classList.add('hidden');

        const btn = document.getElementById('nc-btn-' + t);
        if (btn) btn.classList.remove('nc-type-active');
    });

    // Mostrar painel selecionado
    const activePanel = document.getElementById('nc-panel-' + type);
    if (activePanel) {
        activePanel.classList.remove('hidden');
        // Re-trigger animation
        activePanel.style.animation = 'none';
        activePanel.offsetHeight; // reflow
        activePanel.style.animation = '';
    }

    // Ativar botão selecionado
    const activeBtn = document.getElementById('nc-btn-' + type);
    if (activeBtn) activeBtn.classList.add('nc-type-active');
}

// Carrega subcategorias dependentes de categoria no formulário de Assunto
const hierarchyDataNC = window.DocGovAdminConfig.hierarchy.map;
// Mapas de subcategorias/assuntos filtrados por permissão (para formulários de criação)
const ncSubcatsParaAssunto   = window.DocGovAdminConfig.hierarchy.subjectSubcategories;
const ncSubcatsParaDocumento = window.DocGovAdminConfig.hierarchy.documentSubcategories;
const ncAssuntosParaDocumento = window.DocGovAdminConfig.hierarchy.documentSubjects;

function ncLoadSubcatsForAssunto() {
    const catEl = document.getElementById('nc-ass-cat');
    const subEl = document.getElementById('nc-ass-subcat');
    if (!catEl || !subEl) return;

    const catId = parseInt(catEl.value, 10);
    subEl.innerHTML = '<option value="">-- Selecione a Subcategoria --</option>';

    if (catId && ncSubcatsParaAssunto[catId]) {
        ncSubcatsParaAssunto[catId].forEach(sc => {
            const opt = document.createElement('option');
            opt.value = String(sc.id);
            opt.textContent = sc.nome;
            subEl.appendChild(opt);
        });
    }
}

// =========================================================================
// CONTROLE DA ÁRVORE HIERÁRQUICA (EXPANDIR/RECOLHER NOS 4 NÍVEIS)
// =========================================================================
function toggleTreeNode(btn) {
    const group = btn.closest('.tree-node-group');
    if (!group) return;
    const branch = group.querySelector(':scope > .tree-branch');
    const svg = btn.querySelector('svg');

    if (branch) {
        if (branch.classList.contains('hidden')) {
            branch.classList.remove('hidden');
            if (svg) svg.classList.add('rotate-90');
        } else {
            branch.classList.add('hidden');
            if (svg) svg.classList.remove('rotate-90');
        }
    }
}

function expandAllTreeNodes() {
    document.querySelectorAll('.tree-branch').forEach(b => b.classList.remove('hidden'));
    document.querySelectorAll('.tree-toggle-btn svg').forEach(svg => svg.classList.add('rotate-90'));
}

function collapseAllTreeNodes() {
    document.querySelectorAll('.tree-branch').forEach(b => b.classList.add('hidden'));
    document.querySelectorAll('.tree-toggle-btn svg').forEach(svg => svg.classList.remove('rotate-90'));
}

function filterTreeNodes(query) {
    const q = query.trim().toLowerCase();
    const nodeGroups = document.querySelectorAll('.tree-node-group');
    if (!q) {
        nodeGroups.forEach(el => el.style.display = '');
        document.querySelectorAll('.tree-branch').forEach(b => b.classList.add('hidden'));
        return;
    }
    document.querySelectorAll('.tree-branch').forEach(b => b.classList.remove('hidden'));
    nodeGroups.forEach(group => {
        const text = group.textContent.toLowerCase();
        if (text.includes(q)) {
            group.style.display = '';
        } else {
            group.style.display = 'none';
        }
    });
}
