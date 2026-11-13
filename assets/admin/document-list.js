// Painel administrativo: document-list.
// Carregado como script classico; nomes globais sao usados pelos formularios.
function openDocumentRow(event) {
    const row = event.currentTarget;
    if (event.type === 'keydown') {
        if (event.target !== row || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
    } else if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.target.closest('a, button, input, select, textarea, label, [contenteditable="true"]')) {
        return;
    }
    window.location.assign(row.dataset.documentUrl);
}

function closeActionMenus() {
    document.querySelectorAll('[id^="action-menu-"]').forEach(menu => menu.classList.add('hidden'));
    document.querySelectorAll('[data-action-menu-trigger]').forEach(trigger => trigger.setAttribute('aria-expanded', 'false'));
}

function toggleActionMenu(id, event) {
    event.stopPropagation();
    const targetMenu = document.getElementById('action-menu-' + id);
    if (!targetMenu) return;

    const wasOpen = !targetMenu.classList.contains('hidden');
    closeActionMenus();
    if (wasOpen) return;

    // Fora do contêiner com overflow: o menu não é cortado quando há só uma linha.
    document.body.appendChild(targetMenu);
    targetMenu.classList.remove('hidden');
    const trigger = event.currentTarget;
    const triggerRect = trigger.getBoundingClientRect();
    const menuRect = targetMenu.getBoundingClientRect();
    const margin = 8;
    const spaceBelow = window.innerHeight - triggerRect.bottom - margin;
    const top = spaceBelow >= menuRect.height || spaceBelow >= triggerRect.top - margin
        ? triggerRect.bottom + 4
        : triggerRect.top - menuRect.height - 4;
    targetMenu.style.top = Math.max(margin, Math.min(top, window.innerHeight - menuRect.height - margin)) + 'px';
    targetMenu.style.left = Math.max(margin, Math.min(triggerRect.right - menuRect.width, window.innerWidth - menuRect.width - margin)) + 'px';
    trigger.setAttribute('aria-expanded', 'true');
}

function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.batch-checkbox');
    checkboxes.forEach(c => c.checked = master.checked);
    updateBatchToolbar();
}

function updateBatchToolbar() {
    const checkboxes = Array.from(document.querySelectorAll('.batch-checkbox'));
    const selected = checkboxes.filter(checkbox => checkbox.checked);
    const count = document.getElementById('batch-selection-count');
    if (!count) return;
    count.textContent = selected.length === 0
        ? 'Selecione documentos para ações em lote'
        : `${selected.length} ${selected.length === 1 ? 'documento selecionado' : 'documentos selecionados'}`;
    const availability = {
        submit_review: selected.length > 0 && selected.every(checkbox => ['draft', 'published'].includes(checkbox.dataset.status)),
        publish: selected.length > 0 && selected.every(checkbox => checkbox.dataset.status === 'review' && checkbox.dataset.reviewed === '1'),
        draft: selected.length > 0 && selected.every(checkbox => ['draft', 'review', 'published'].includes(checkbox.dataset.status)),
        trash: selected.length > 0 && selected.every(checkbox => ['draft', 'review', 'published'].includes(checkbox.dataset.status)),
    };
    document.querySelectorAll('[data-batch-action]').forEach(button => {
        button.disabled = !availability[button.dataset.batchAction];
    });
    const master = document.querySelector('input[aria-label="Selecionar todos os documentos desta página"]');
    if (master) {
        master.checked = checkboxes.length > 0 && selected.length === checkboxes.length;
        master.indeterminate = selected.length > 0 && selected.length < checkboxes.length;
    }
}

document.addEventListener('click', closeActionMenus);
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeActionMenus();
});
document.addEventListener('wheel', closeActionMenus, { passive: true });
document.addEventListener('touchmove', closeActionMenus, { passive: true });
window.addEventListener('resize', closeActionMenus);

const hierarchyDataFilter = window.DocGovAdminConfig.hierarchy.map;
const currentFilterCat = window.DocGovAdminConfig.filters.category;
const currentFilterSub = window.DocGovAdminConfig.filters.subcategory;
const currentFilterAss = window.DocGovAdminConfig.filters.subject;

function onFilterCategoryChange() {
    const cat = document.getElementById('filter-cat').value;
    const subSelect = document.getElementById('filter-subcat');
    const assSelect = document.getElementById('filter-assunto');

    subSelect.innerHTML = '<option value="">Todas</option>';
    assSelect.innerHTML = '<option value="">Todos</option>';

    if (cat && hierarchyDataFilter[cat]) {
        Object.keys(hierarchyDataFilter[cat]).forEach(sub => {
            const opt = document.createElement('option');
            opt.value = sub;
            opt.textContent = sub;
            if (sub === currentFilterSub) opt.selected = true;
            subSelect.appendChild(opt);
        });
        if (subSelect.value) onFilterSubcategoryChange();
    }
}

function onFilterSubcategoryChange() {
    const cat = document.getElementById('filter-cat').value;
    const sub = document.getElementById('filter-subcat').value;
    const assSelect = document.getElementById('filter-assunto');

    assSelect.innerHTML = '<option value="">Todos</option>';

    if (cat && sub && hierarchyDataFilter[cat] && hierarchyDataFilter[cat][sub]) {
        hierarchyDataFilter[cat][sub].forEach(ass => {
            const opt = document.createElement('option');
            opt.value = ass;
            opt.textContent = ass;
            if (ass === currentFilterAss) opt.selected = true;
            assSelect.appendChild(opt);
        });
    }
}
