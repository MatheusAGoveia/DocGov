<?php
/**
 * Painel reutilizável de permissões por recurso.
 * Variáveis esperadas: $resType, $resId e, opcionalmente, $resourceTitle.
 */
$permissionsResourceType = (string)($resType ?? '');
$permissionsResourceId = (int)($resId ?? 0);
$permissionsResourceTitle = (string)($resourceTitle ?? ($resData['name'] ?? ''));
?>
<section
    class="dg-permissions"
    data-permissions-panel
    data-resource-type="<?= htmlspecialchars($permissionsResourceType, ENT_QUOTES, 'UTF-8') ?>"
    data-resource-id="<?= $permissionsResourceId ?>"
    data-resource-title="<?= htmlspecialchars($permissionsResourceTitle, ENT_QUOTES, 'UTF-8') ?>"
    data-permissions-api="../api/permissions.php"
    data-search-api="../api/search_principals.php"
    data-csrf-token="<?= htmlspecialchars((string)($csrfToken ?? ''), ENT_QUOTES, 'UTF-8') ?>"
>
    <header class="dg-permissions__header">
        <div>
            <h2>Gerenciar permissões</h2>
            <p class="dg-permissions__path" data-permissions-path><?= htmlspecialchars($permissionsResourceTitle) ?></p>
            <p class="dg-permissions__hint">As permissões configuradas neste nível podem ser herdadas pelos descendentes.</p>
        </div>
        <button type="button" class="dg-permissions__add" data-permissions-add>
            <span aria-hidden="true">+</span> Adicionar uma permissão
        </button>
    </header>

    <div class="dg-permissions__notice" data-permissions-notice role="status" aria-live="polite" hidden></div>
    <div class="dg-permissions__loading" data-permissions-loading>Carregando permissões…</div>
    <div class="dg-permissions__content" data-permissions-content hidden></div>

    <div class="dg-permissions-modal" data-permissions-modal hidden>
        <div class="dg-permissions-modal__backdrop" data-permissions-close></div>
        <div class="dg-permissions-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="dg-permissions-modal-title">
            <header>
                <div>
                    <h3 id="dg-permissions-modal-title">Adicionar uma permissão</h3>
                    <p data-permissions-modal-path></p>
                </div>
                <button type="button" class="dg-permissions-modal__close" data-permissions-close aria-label="Fechar">×</button>
            </header>

            <form data-permissions-form>
                <fieldset>
                    <legend>Tipo</legend>
                    <div class="dg-permissions-modal__types">
                        <button type="button" data-principal-type="user" aria-pressed="true">Usuário</button>
                        <button type="button" data-principal-type="group" aria-pressed="false">Equipe</button>
                    </div>
                </fieldset>

                <label class="dg-permissions-modal__field">
                    <span>Pesquisar</span>
                    <input type="search" data-principal-search autocomplete="off" placeholder="nome, usuário, e-mail ou equipe…">
                </label>

                <div class="dg-permissions-modal__results" data-principal-results role="listbox" aria-label="Resultados da pesquisa">
                    <p>Digite para pesquisar usuários ou equipes.</p>
                </div>

                <label class="dg-permissions-modal__field">
                    <span>Permissão</span>
                    <select data-permission-level>
                        <option value="view">View</option>
                        <option value="edit">Edit</option>
                        <option value="admin">Admin</option>
                    </select>
                </label>

                <p class="dg-permissions-modal__error" data-permissions-form-error hidden></p>
                <footer>
                    <button type="button" class="dg-permissions-modal__cancel" data-permissions-close>Cancelar</button>
                    <button type="submit" class="dg-permissions-modal__submit" disabled>Adicionar</button>
                </footer>
            </form>
        </div>
    </div>
</section>
