<?php
// admin/partials/user-detail-modal.php
// Modal Ficha 360° do Usuário (Active Directory & DocGov)
?>
<!-- MODAL FICHA 360° DO USUÁRIO -->
<div id="user-360-modal-backdrop" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs transition-opacity duration-200 flex items-center justify-center p-4">
    <div class="relative w-full max-w-3xl rounded-xl border border-slate-200 bg-white shadow-2xl transition-all dark:border-[#454956] dark:bg-[#212328] overflow-hidden flex flex-col max-h-[90vh]">
        
        <!-- HEADER DO MODAL -->
        <div class="relative bg-slate-900 px-6 py-5 text-white dark:bg-[#181a1f] flex items-center justify-between border-b border-slate-800">
            <div class="flex items-center gap-4">
                <div id="modal-user-avatar-container" class="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-slate-800 border-2 border-slate-700 text-lg font-bold text-white shadow-inner overflow-hidden">
                    <span id="modal-user-initials">--</span>
                    <img id="modal-user-avatar-img" class="hidden h-full w-full object-cover" src="" alt="Avatar">
                </div>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 id="modal-user-name" class="text-lg font-bold tracking-tight text-white">Carregando...</h3>
                        <span id="modal-user-status-badge" class="rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-500/30 uppercase">Ativo</span>
                    </div>
                    <div class="mt-1 flex items-center gap-2 text-xs text-slate-300 font-mono flex-wrap">
                        <span id="modal-user-username">@--</span>
                        <span>•</span>
                        <span id="modal-user-email">--</span>
                    </div>
                </div>
            </div>

            <button type="button" onclick="closeUser360Modal()" class="rounded-lg p-2 text-slate-400 hover:bg-slate-800 hover:text-white transition">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- ABAS DE NAVEGAÇÃO INTERNA DO MODAL -->
        <div class="flex border-b border-slate-200 bg-slate-50 px-6 text-xs font-semibold dark:border-[#353842] dark:bg-[#1a1c21]">
            <button type="button" onclick="switchUserModalTab('profile')" id="tab-btn-profile" class="user-modal-tab-btn border-b-2 border-slate-900 px-4 py-3 text-slate-900 dark:border-white dark:text-white">
                Perfil Corporativo AD
            </button>
            <button type="button" onclick="switchUserModalTab('security')" id="tab-btn-security" class="user-modal-tab-btn border-b-2 border-transparent px-4 py-3 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                Segurança & Logins (IPs)
            </button>
            <button type="button" onclick="switchUserModalTab('teams')" id="tab-btn-teams" class="user-modal-tab-btn border-b-2 border-transparent px-4 py-3 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                Equipes & Permissões
            </button>
        </div>

        <!-- CORPO DO MODAL -->
        <div class="p-6 overflow-y-auto flex-1 space-y-5 text-xs text-slate-700 dark:text-slate-300">
            
            <!-- LOADER -->
            <div id="modal-user-loading" class="py-12 text-center">
                <svg class="mx-auto h-8 w-8 animate-spin text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span class="mt-3 block text-xs font-semibold text-slate-500">Extraindo dados detalhados do Active Directory e perfil...</span>
            </div>

            <!-- CONTEÚDO: PERFIL CORPORATIVO AD -->
            <div id="modal-content-profile" class="hidden user-modal-tab-content space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    
                    <div class="rounded-lg border border-slate-200 bg-slate-50/50 p-3.5 dark:border-[#353842] dark:bg-[#1a1c21]">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Identidade Corporativa</span>
                        <div class="space-y-1.5 font-mono text-xs">
                            <div class="flex justify-between"><span class="text-slate-500">Origem Auth:</span> <span id="modal-user-auth-source" class="font-bold text-slate-800 dark:text-slate-200">Active Directory</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Domínio AD:</span> <span id="modal-user-ad-domain" class="font-bold text-emerald-600 dark:text-emerald-400">BETIM</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Perfil Global:</span> <span id="modal-user-role" class="font-bold text-purple-600 dark:text-purple-400">Admin Global</span></div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-slate-50/50 p-3.5 dark:border-[#353842] dark:bg-[#1a1c21]">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Estrutura Orgânica</span>
                        <div class="space-y-1.5 text-xs">
                            <div class="flex justify-between"><span class="text-slate-500">Departamento:</span> <span id="modal-user-department" class="font-bold text-slate-800 dark:text-slate-200 truncate max-w-[180px]">--</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Cargo / Função:</span> <span id="modal-user-job-title" class="font-bold text-slate-800 dark:text-slate-200 truncate max-w-[180px]">--</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Telefone:</span> <span id="modal-user-phone" class="font-bold font-mono text-slate-800 dark:text-slate-200">--</span></div>
                        </div>
                    </div>

                </div>

                <div class="rounded-lg border border-slate-200 bg-slate-50/50 p-3.5 dark:border-[#353842] dark:bg-[#1a1c21] space-y-1.5 font-mono text-xs">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Identificador GUID Único (Active Directory)</span>
                    <div class="flex items-center justify-between">
                        <span id="modal-user-guid" class="truncate text-slate-700 dark:text-slate-300 font-bold select-all">--</span>
                        <span class="text-[10px] text-slate-400 font-sans">bin2hex objectGUID</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-[#353842] dark:bg-[#252830]">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Documentos Criados</span>
                        <span id="modal-user-docs-created" class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">0</span>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-[#353842] dark:bg-[#252830]">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Documentos Publicados</span>
                        <span id="modal-user-docs-published" class="mt-1 text-xl font-bold text-emerald-600 dark:text-emerald-400">0</span>
                    </div>
                </div>
            </div>

            <!-- CONTEÚDO: SEGURANÇA E AUDITORIA DE LOGINS (IPS) -->
            <div id="modal-content-security" class="hidden user-modal-tab-content space-y-4">
                
                <div class="rounded-lg border border-slate-200 bg-slate-900 p-4 text-white dark:border-[#353842] dark:bg-[#181a1f] flex items-center justify-between">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Última Sessão Registrada</span>
                        <span id="modal-user-last-login" class="text-sm font-bold font-mono text-emerald-400">--</span>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">IP do Último Acesso</span>
                        <span id="modal-user-last-ip" class="text-sm font-bold font-mono text-slate-200">--</span>
                    </div>
                </div>

                <div>
                    <h4 class="text-xs font-bold text-slate-800 dark:text-slate-200 mb-2">Histórico de Conexões e Tentativas (Rastreio de IP e Servidor AD)</h4>
                    <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-[#353842]">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-500 dark:bg-[#1a1c21] dark:text-slate-400">
                                <tr>
                                    <th class="px-3 py-2">Data / Hora</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2">IP de Origem</th>
                                    <th class="px-3 py-2">Servidor LDAP</th>
                                    <th class="px-3 py-2">Latência</th>
                                </tr>
                            </thead>
                            <tbody id="modal-user-auth-logs-body" class="divide-y divide-slate-100 font-mono text-[11px] dark:divide-[#353842]">
                                <tr><td colspan="5" class="px-3 py-4 text-center text-slate-400">Nenhum log recente.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- CONTEÚDO: EQUIPES E PERMISSÕES -->
            <div id="modal-content-teams" class="hidden user-modal-tab-content space-y-4">
                
                <div>
                    <h4 class="text-xs font-bold text-slate-800 dark:text-slate-200 mb-2">Equipes e Grupos Pertencentes</h4>
                    <div id="modal-user-teams-list" class="space-y-2">
                        <div class="p-3 rounded-lg border border-slate-200 dark:border-[#353842] text-slate-400 text-center">Carregando equipes...</div>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-slate-50/50 p-4 dark:border-[#353842] dark:bg-[#1a1c21]">
                    <h4 class="text-xs font-bold text-slate-800 dark:text-slate-200 mb-2">Resumo de Recursos Autorizados</h4>
                    <div class="grid grid-cols-3 gap-2 text-center font-mono">
                        <div class="p-2 rounded bg-white dark:bg-[#252830] border border-slate-200 dark:border-[#353842]">
                            <span id="modal-perm-cat-count" class="text-lg font-bold text-slate-900 dark:text-white">0</span>
                            <span class="block text-[9px] font-sans font-semibold text-slate-400 uppercase">Categorias</span>
                        </div>
                        <div class="p-2 rounded bg-white dark:bg-[#252830] border border-slate-200 dark:border-[#353842]">
                            <span id="modal-perm-sub-count" class="text-lg font-bold text-slate-900 dark:text-white">0</span>
                            <span class="block text-[9px] font-sans font-semibold text-slate-400 uppercase">Subcategorias</span>
                        </div>
                        <div class="p-2 rounded bg-white dark:bg-[#252830] border border-slate-200 dark:border-[#353842]">
                            <span id="modal-perm-ass-count" class="text-lg font-bold text-slate-900 dark:text-white">0</span>
                            <span class="block text-[9px] font-sans font-semibold text-slate-400 uppercase">Assuntos</span>
                        </div>
                    </div>

                    <div class="mt-3 text-right">
                        <a id="modal-user-full-access-link" href="#" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-xs font-bold transition hover:opacity-90 text-decoration-none">
                            <span>Ver Matriz Completa de Acessos Efetivos</span>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </a>
                    </div>
                </div>

            </div>

        </div>

        <!-- FOOTER DO MODAL -->
        <div class="bg-slate-50 px-6 py-3 border-t border-slate-200 dark:border-[#353842] dark:bg-[#1a1c21] flex justify-end">
            <button type="button" onclick="closeUser360Modal()" class="px-4 py-2 rounded-lg bg-slate-200 dark:bg-[#353842] text-slate-700 dark:text-slate-200 font-bold text-xs hover:bg-slate-300 dark:hover:bg-slate-700 transition">
                Fechar Ficha
            </button>
        </div>

    </div>
</div>

<script>
window.openUser360Modal = async function(userId) {
    const backdrop = document.getElementById('user-360-modal-backdrop');
    const loading = document.getElementById('modal-user-loading');
    const csrfToken = document.getElementById('ad-csrf-token')?.value || document.querySelector('input[name="csrf_token"]')?.value || '';

    if (!backdrop || !userId) return;

    // Abrir modal e mostrar spinner
    backdrop.classList.remove('hidden');
    loading.classList.remove('hidden');
    document.querySelectorAll('.user-modal-tab-content').forEach(el => el.classList.add('hidden'));
    switchUserModalTab('profile');

    const formData = new FormData();
    formData.append('get_user_details', '1');
    formData.append('user_id', userId);
    formData.append('csrf_token', csrfToken);

    try {
        const response = await fetch('index.php', { method: 'POST', body: formData });
        const res = await response.json();

        loading.classList.add('hidden');

        if (!res.success || !res.user) {
            alert(res.error || 'Não foi possível carregar os detalhes do usuário.');
            closeUser360Modal();
            return;
        }

        const u = res.user;
        const logs = res.auth_logs || [];
        const groups = res.groups || [];
        const activity = res.activity || {};
        const diag = res.diagnosis_summary || {};

        // Preencher Header
        document.getElementById('modal-user-name').textContent = u.name || u.username;
        document.getElementById('modal-user-username').textContent = '@' + u.username;
        document.getElementById('modal-user-email').textContent = u.email || 'Sem e-mail';
        
        const initials = (u.name || u.username).substring(0, 2).toUpperCase();
        document.getElementById('modal-user-initials').textContent = initials;
        
        const img = document.getElementById('modal-user-avatar-img');
        if (u.avatar) {
            img.src = '../' + u.avatar;
            img.classList.remove('hidden');
        } else {
            img.classList.add('hidden');
        }

        const statusBadge = document.getElementById('modal-user-status-badge');
        if (u.active) {
            statusBadge.className = 'rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-[10px] font-bold text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 uppercase';
            statusBadge.textContent = 'Ativo';
        } else {
            statusBadge.className = 'rounded-full bg-slate-200 dark:bg-slate-700 px-2.5 py-0.5 text-[10px] font-bold text-slate-500 uppercase';
            statusBadge.textContent = 'Inativo';
        }

        // Preencher Tab Perfil
        document.getElementById('modal-user-auth-source').textContent = (u.auth_source === 'ad' ? 'Active Directory (' + (u.ad_domain || 'BETIM') + ')' : 'Local');
        document.getElementById('modal-user-ad-domain').textContent = u.ad_domain || 'BETIM';
        document.getElementById('modal-user-role').textContent = (u.role === 'admin' ? 'Admin Global' : 'Leitor');
        document.getElementById('modal-user-department').textContent = u.department || 'Não informado no AD';
        document.getElementById('modal-user-job-title').textContent = u.job_title || 'Não informado no AD';
        document.getElementById('modal-user-phone').textContent = u.phone || 'Não informado';
        document.getElementById('modal-user-guid').textContent = u.ad_object_guid || 'N/A';
        document.getElementById('modal-user-docs-created').textContent = activity.docs_created || 0;
        document.getElementById('modal-user-docs-published').textContent = activity.docs_published || 0;

        // Preencher Tab Segurança
        document.getElementById('modal-user-last-login').textContent = u.last_login_at || 'Nunca acessou';
        const firstLogIp = logs[0]?.user_ip || 'Sem registros';
        document.getElementById('modal-user-last-ip').textContent = firstLogIp;

        const logsBody = document.getElementById('modal-user-auth-logs-body');
        logsBody.innerHTML = '';
        if (logs.length === 0) {
            logsBody.innerHTML = '<tr><td colspan="5" class="px-3 py-4 text-center text-slate-400">Nenhum log registrado para este usuário.</td></tr>';
        } else {
            logs.forEach(l => {
                const tr = document.createElement('tr');
                const st = l.status;
                let badgeHtml = `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700">${st}</span>`;
                if (st === 'success') badgeHtml = '<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Sucesso</span>';
                else if (st === 'invalid_credentials') badgeHtml = '<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-800">Senha Incorreta</span>';
                else if (st === 'account_locked') badgeHtml = '<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">Bloqueada AD</span>';
                else if (st === 'password_expired') badgeHtml = '<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">Senha Expirada</span>';

                tr.innerHTML = `
                    <td class="px-3 py-2 text-slate-500">${l.created_at}</td>
                    <td class="px-3 py-2">${badgeHtml}</td>
                    <td class="px-3 py-2 font-bold text-slate-800 dark:text-slate-200">${l.user_ip}</td>
                    <td class="px-3 py-2 text-slate-500 truncate max-w-[150px]" title="${l.server_uri}">${l.server_uri}</td>
                    <td class="px-3 py-2 text-slate-500">${l.latency_ms}ms</td>
                `;
                logsBody.appendChild(tr);
            });
        }

        // Preencher Tab Equipes & Permissões
        const teamsContainer = document.getElementById('modal-user-teams-list');
        teamsContainer.innerHTML = '';
        if (groups.length === 0) {
            teamsContainer.innerHTML = '<div class="p-3 rounded-lg border border-slate-200 dark:border-[#353842] text-slate-400 text-center">Usuário não pertence a nenhuma equipe.</div>';
        } else {
            groups.forEach(g => {
                const div = document.createElement('div');
                div.className = 'p-3 rounded-lg border border-slate-200 dark:border-[#353842] bg-slate-50/50 dark:bg-[#1a1c21] flex items-center justify-between';
                div.innerHTML = `
                    <div>
                        <span class="font-bold text-slate-900 dark:text-slate-100">${g.name}</span>
                        <span class="block text-[10px] text-slate-400">${g.description || 'Sem descrição'}</span>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-sky-500/15 text-sky-600 uppercase">${g.role_in_group || 'Membro'}</span>
                `;
                teamsContainer.appendChild(div);
            });
        }

        document.getElementById('modal-perm-cat-count').textContent = diag.categories_count || 0;
        document.getElementById('modal-perm-sub-count').textContent = diag.subcategories_count || 0;
        document.getElementById('modal-perm-ass-count').textContent = diag.subjects_count || 0;

        document.getElementById('modal-user-full-access-link').href = 'index.php?tab=editar_usuario&id=' + u.id + '&user_tab=access';

        // Exibir aba profile
        document.getElementById('modal-content-profile').classList.remove('hidden');

    } catch (err) {
        loading.classList.add('hidden');
        alert('Erro de conexão ao carregar ficha do usuário: ' + err.message);
        closeUser360Modal();
    }
};

window.closeUser360Modal = function() {
    const backdrop = document.getElementById('user-360-modal-backdrop');
    if (backdrop) backdrop.classList.add('hidden');
};

window.switchUserModalTab = function(tabName) {
    document.querySelectorAll('.user-modal-tab-btn').forEach(btn => {
        btn.classList.remove('border-slate-900', 'text-slate-900', 'dark:border-white', 'dark:text-white');
        btn.classList.add('border-transparent', 'text-slate-500', 'dark:text-slate-400');
    });

    document.querySelectorAll('.user-modal-tab-content').forEach(content => {
        content.classList.add('hidden');
    });

    const activeBtn = document.getElementById('tab-btn-' + tabName);
    const activeContent = document.getElementById('modal-content-' + tabName);

    if (activeBtn) {
        activeBtn.classList.remove('border-transparent', 'text-slate-500', 'dark:text-slate-400');
        activeBtn.classList.add('border-slate-900', 'text-slate-900', 'dark:border-white', 'dark:text-white');
    }
    if (activeContent) {
        activeContent.classList.remove('hidden');
    }
};

// Event listener global para cliques em usuários
document.addEventListener('click', function(e) {
    const userTrigger = e.target.closest('[data-user-360-id]');
    if (userTrigger) {
        e.preventDefault();
        const userId = userTrigger.getAttribute('data-user-360-id');
        if (userId) {
            window.openUser360Modal(userId);
        }
    }
});
</script>
