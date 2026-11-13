// Painel administrativo: groups.
// Carregado como script classico; nomes globais sao usados pelos formularios.
function filterTeamMemberOptions(query) {
    const q = query.toLowerCase().trim();
    const options = document.querySelectorAll('[data-team-member-option]');
    let visibleCount = 0;
    options.forEach(option => {
        const matches = !q || (option.dataset.search || '').includes(q);
        option.classList.toggle('hidden', !matches);
        if (matches) visibleCount += 1;
    });
    document.getElementById('team-member-empty').classList.toggle('hidden', visibleCount > 0);
}

function selectTeamMember(selected) {
    document.querySelectorAll('[data-team-member-option]').forEach(option => {
        const active = option === selected;
        option.setAttribute('aria-selected', active ? 'true' : 'false');
        option.classList.toggle('bg-emerald-50', active);
        option.classList.toggle('dark:bg-emerald-950/20', active);
        option.querySelector('[data-team-member-check]').classList.toggle('opacity-0', !active);
    });
    document.getElementById('team-member-user-id').value = selected.dataset.userId;
    document.getElementById('team-member-submit').disabled = false;
}
