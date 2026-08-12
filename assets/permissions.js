(() => {
    'use strict';

    const LEVEL_LABELS = { view: 'View', edit: 'Edit', admin: 'Admin', none: 'None' };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    class DocGovPermissionsPanel {
        constructor(root) {
            this.root = root;
            this.resourceType = root.dataset.resourceType;
            this.resourceId = Number(root.dataset.resourceId);
            this.permissionsApi = root.dataset.permissionsApi;
            this.searchApi = root.dataset.searchApi;
            this.csrfToken = root.dataset.csrfToken;
            this.content = root.querySelector('[data-permissions-content]');
            this.loading = root.querySelector('[data-permissions-loading]');
            this.notice = root.querySelector('[data-permissions-notice]');
            this.modal = root.querySelector('[data-permissions-modal]');
            this.searchInput = root.querySelector('[data-principal-search]');
            this.searchResults = root.querySelector('[data-principal-results]');
            this.submitButton = root.querySelector('.dg-permissions-modal__submit');
            this.formError = root.querySelector('[data-permissions-form-error]');
            this.principalType = 'user';
            this.selectedPrincipal = null;
            this.searchTimer = null;
            this.noticeTimer = null;
            this.permissions = [];
            this.roles = [];
            this.resource = null;
            this.bindEvents();
            this.load();
        }

        bindEvents() {
            this.root.querySelector('[data-permissions-add]').addEventListener('click', () => this.openModal());
            this.root.querySelectorAll('[data-permissions-close]').forEach((button) => {
                button.addEventListener('click', () => this.closeModal());
            });
            this.root.querySelectorAll('[data-principal-type]').forEach((button) => {
                button.addEventListener('click', () => this.setPrincipalType(button.dataset.principalType));
            });
            this.searchInput.addEventListener('input', () => {
                window.clearTimeout(this.searchTimer);
                this.searchTimer = window.setTimeout(() => this.search(), 300);
            });
            this.root.querySelector('[data-permissions-form]').addEventListener('submit', (event) => this.addPermission(event));
            this.content.addEventListener('change', (event) => {
                const select = event.target.closest('[data-permission-update]');
                if (select) this.updatePermission(select);
            });
            this.content.addEventListener('click', (event) => {
                const button = event.target.closest('[data-permission-remove]');
                if (button) this.removePermission(button);
            });
            this.modal.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') this.closeModal();
            });
        }

        async request(url, options = {}) {
            const response = await fetch(url, {
                credentials: 'same-origin',
                ...options,
                headers: {
                    Accept: 'application/json',
                    ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                    ...(options.method && options.method !== 'GET' ? { 'X-CSRF-Token': this.csrfToken } : {}),
                    ...(options.headers || {}),
                },
            });
            let payload;
            try {
                payload = await response.json();
            } catch (_error) {
                throw new Error('O servidor retornou uma resposta inválida.');
            }
            if (!response.ok || !payload.success) {
                throw new Error(payload.error || 'Não foi possível concluir a operação.');
            }
            return payload;
        }

        async load() {
            this.setBusy(true);
            try {
                const query = new URLSearchParams({
                    resource_type: this.resourceType,
                    resource_id: String(this.resourceId),
                });
                const payload = await this.request(`${this.permissionsApi}?${query}`);
                this.resource = payload.resource;
                this.roles = Array.isArray(payload.roles) ? payload.roles : [];
                this.permissions = Array.isArray(payload.data) ? payload.data : [];
                const path = this.root.querySelector('[data-permissions-path]');
                if (path && this.resource?.path) path.textContent = this.resource.path;
                this.render();
            } catch (error) {
                this.showNotice(error.message, 'error', false);
                this.content.hidden = true;
            } finally {
                this.setBusy(false);
            }
        }

        setBusy(busy) {
            this.root.setAttribute('aria-busy', busy ? 'true' : 'false');
            this.loading.hidden = !busy;
            if (!busy && this.resource) this.content.hidden = false;
        }

        render() {
            const users = this.permissions.filter((item) => item.principal_type === 'user');
            const groups = this.permissions.filter((item) => item.principal_type === 'group');
            this.content.innerHTML = [
                this.renderSection('Função', this.roles, 'role'),
                this.renderSection('Usuário', users, 'user'),
                this.renderSection('Equipe', groups, 'group'),
            ].join('');
            this.content.hidden = false;
        }

        renderSection(title, items, sectionType) {
            const rows = items.length
                ? items.map((item) => this.renderRow(item, sectionType)).join('')
                : `<p class="dg-permissions__empty">Nenhuma permissão de ${escapeHtml(title.toLowerCase())} configurada.</p>`;
            return `<section class="dg-permissions__section"><h3>${escapeHtml(title)}</h3>${rows}</section>`;
        }

        renderRow(item, sectionType) {
            const active = item.principal_active !== false;
            const direct = item.is_direct === true;
            const locked = item.locked === true || !direct;
            const icon = sectionType === 'role' ? '◇' : (sectionType === 'user' ? '●' : '◆');
            const level = String(item.permission_level || 'view').toLowerCase();
            const effective = String(item.effective_level || level).toLowerCase();
            const select = `
                <select
                    aria-label="Permissão de ${escapeHtml(item.principal_name)}"
                    ${locked ? 'disabled' : ''}
                    ${direct ? `data-permission-update data-principal-type="${escapeHtml(item.principal_type)}" data-principal-id="${Number(item.principal_id)}" data-previous="${escapeHtml(level)}"` : ''}
                >
                    ${['view', 'edit', 'admin'].map((option) => `<option value="${option}" ${option === level ? 'selected' : ''}>${LEVEL_LABELS[option]}</option>`).join('')}
                </select>`;

            let identitySubtext = item.principal_subtext || '';
            if (!active) identitySubtext = 'INATIVA · regra não participa do acesso efetivo';
            const inherited = !direct && item.origin_label && item.origin_label !== 'Direta';
            let origin = direct ? '<small>Direta</small>' : '<small>Regra protegida</small>';
            if (inherited) {
                const ancestor = item.ancestor_info;
                const label = escapeHtml(item.origin_label);
                origin = ancestor
                    ? `<small>${label}</small><a href="index.php?tab=editar_estrutura&amp;type=${encodeURIComponent(ancestor.type)}&amp;id=${Number(ancestor.id)}&amp;res_tab=permissions">Ver origem</a>`
                    : `<small>${label}</small>`;
            }
            origin += `<small>Efetiva: ${escapeHtml(LEVEL_LABELS[effective] || effective)}</small>`;

            const action = locked
                ? '<span class="dg-permissions__lock" title="Esta regra não pode ser alterada neste nível" aria-label="Bloqueado">▣</span>'
                : `<button type="button" class="dg-permissions__action" data-permission-remove="${Number(item.permission_id)}" aria-label="Remover permissão de ${escapeHtml(item.principal_name)}" title="Remover regra direta">×</button>`;
            const warning = item.conflict_warning
                ? `<p class="dg-permissions__warning">${escapeHtml(item.conflict_warning)}</p>`
                : '';

            return `
                <div class="dg-permissions__row ${active ? '' : 'dg-permissions__row--inactive'}">
                    <div class="dg-permissions__principal">
                        <span class="dg-permissions__icon" aria-hidden="true">${icon}</span>
                        <div class="dg-permissions__identity">
                            <strong>${escapeHtml(item.principal_name)}</strong>
                            <small ${active ? '' : 'data-inactive'}>${escapeHtml(identitySubtext)}</small>
                        </div>
                    </div>
                    <div class="dg-permissions__origin">${origin}</div>
                    <div class="dg-permissions__level">${select}</div>
                    ${action}
                    ${warning}
                </div>`;
        }

        showNotice(message, kind = 'success', autoHide = true) {
            window.clearTimeout(this.noticeTimer);
            this.notice.textContent = message;
            this.notice.dataset.kind = kind;
            this.notice.hidden = false;
            if (autoHide) {
                this.noticeTimer = window.setTimeout(() => { this.notice.hidden = true; }, 3500);
            }
        }

        applyMutationPayload(payload) {
            if (Array.isArray(payload.data)) {
                this.permissions = payload.data;
                this.render();
            }
            this.showNotice(payload.message || 'Permissões atualizadas.');
        }

        async updatePermission(select) {
            const previous = select.dataset.previous;
            select.disabled = true;
            try {
                const payload = await this.request(this.permissionsApi, {
                    method: 'POST',
                    body: JSON.stringify({
                        resource_type: this.resourceType,
                        resource_id: this.resourceId,
                        principal_type: select.dataset.principalType,
                        principal_id: Number(select.dataset.principalId),
                        permission_level: select.value,
                    }),
                });
                this.applyMutationPayload(payload);
            } catch (error) {
                select.value = previous;
                select.disabled = false;
                this.showNotice(error.message, 'error');
            }
        }

        async removePermission(button) {
            if (!window.confirm('Remover apenas esta regra direta de permissão?')) return;
            button.disabled = true;
            try {
                const payload = await this.request(this.permissionsApi, {
                    method: 'DELETE',
                    body: JSON.stringify({
                        permission_id: Number(button.dataset.permissionRemove),
                        resource_type: this.resourceType,
                        resource_id: this.resourceId,
                    }),
                });
                this.applyMutationPayload(payload);
            } catch (error) {
                button.disabled = false;
                this.showNotice(error.message, 'error');
            }
        }

        openModal() {
            this.selectedPrincipal = null;
            this.searchInput.value = '';
            this.root.querySelector('[data-permission-level]').value = 'view';
            this.root.querySelector('[data-permissions-modal-path]').textContent = this.resource?.path || this.root.dataset.resourceTitle;
            this.formError.hidden = true;
            this.submitButton.disabled = true;
            this.modal.hidden = false;
            this.setPrincipalType('user', false);
            this.searchInput.focus();
            this.search();
        }

        closeModal() {
            this.modal.hidden = true;
            window.clearTimeout(this.searchTimer);
        }

        setPrincipalType(type, runSearch = true) {
            this.principalType = type === 'group' ? 'group' : 'user';
            this.selectedPrincipal = null;
            this.submitButton.disabled = true;
            this.root.querySelectorAll('[data-principal-type]').forEach((button) => {
                button.setAttribute('aria-pressed', button.dataset.principalType === this.principalType ? 'true' : 'false');
            });
            if (runSearch) this.search();
        }

        async search() {
            this.searchResults.innerHTML = '<p>Pesquisando…</p>';
            try {
                const query = new URLSearchParams({
                    q: this.searchInput.value.trim(),
                    type: this.principalType,
                    resource_type: this.resourceType,
                    resource_id: String(this.resourceId),
                });
                const payload = await this.request(`${this.searchApi}?${query}`);
                this.renderSearchResults(payload.data || []);
            } catch (error) {
                this.searchResults.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
            }
        }

        renderSearchResults(items) {
            if (!items.length) {
                this.searchResults.innerHTML = '<p>Nenhum resultado encontrado.</p>';
                return;
            }
            this.searchResults.innerHTML = items.map((item) => `
                <button type="button" class="dg-permissions-modal__result" role="option" aria-selected="false" data-result-id="${Number(item.id)}" data-result-name="${escapeHtml(item.name)}">
                    <span><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.subtext)}</small></span>
                    ${item.existing_level ? `<span class="dg-permissions-modal__existing">Direta: ${escapeHtml(LEVEL_LABELS[item.existing_level] || item.existing_level)}</span>` : ''}
                </button>`).join('');
            this.searchResults.querySelectorAll('[data-result-id]').forEach((button) => {
                button.addEventListener('click', () => {
                    this.searchResults.querySelectorAll('[aria-selected]').forEach((result) => result.setAttribute('aria-selected', 'false'));
                    button.setAttribute('aria-selected', 'true');
                    this.selectedPrincipal = { id: Number(button.dataset.resultId), name: button.dataset.resultName };
                    this.submitButton.disabled = false;
                });
            });
        }

        async addPermission(event) {
            event.preventDefault();
            if (!this.selectedPrincipal) return;
            this.submitButton.disabled = true;
            this.formError.hidden = true;
            try {
                const payload = await this.request(this.permissionsApi, {
                    method: 'POST',
                    body: JSON.stringify({
                        resource_type: this.resourceType,
                        resource_id: this.resourceId,
                        principal_type: this.principalType,
                        principal_id: this.selectedPrincipal.id,
                        permission_level: this.root.querySelector('[data-permission-level]').value,
                    }),
                });
                this.closeModal();
                this.applyMutationPayload(payload);
            } catch (error) {
                this.formError.textContent = error.message;
                this.formError.hidden = false;
                this.submitButton.disabled = false;
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-permissions-panel]').forEach((root) => new DocGovPermissionsPanel(root));
    });
})();
