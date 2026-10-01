<?php
$batchGroupId = isset($batchImportGroupId) ? (int)$batchImportGroupId : null;
$batchMode = $batchGroupId === null ? 'directory' : 'group';
$batchEnabledDomains = array_filter($adConfig['domains'] ?? [], static fn(array $domain): bool => !isset($domain['enabled']) || $domain['enabled']);
?>
<details class="batch-import" data-batch-user-import data-mode="<?= $batchMode ?>" data-group-id="<?= $batchGroupId ?? '' ?>" data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" data-endpoint="import-users.php">
    <summary class="batch-import-heading">
        <span><strong><?= $batchGroupId === null ? 'Importar usuários em lote do AD' : 'Adicionar membros em lote' ?></strong><small>Cole uma lista de nomes ou logins, ou carregue um arquivo.</small></span>
        <span aria-hidden="true">+</span>
    </summary>
    <div class="batch-import-body">
        <p class="batch-import-help"><?= $batchGroupId === null
            ? 'As contas ativas encontradas no AD serão cadastradas automaticamente. Cadastros existentes serão preservados.'
            : 'Pessoas já cadastradas serão adicionadas à equipe. Se ainda não houver cadastro, buscamos no AD e importamos antes de adicionar.' ?></p>
        <?php if ($batchGroupId !== null && !docgovDatabaseBoolean($grpData['active'])): ?>
            <p class="batch-import-warning">Esta equipe está inativa. Os novos membros só receberão os acessos quando ela for ativada.</p>
        <?php endif; ?>
        <form data-batch-form>
            <div class="batch-import-fields">
                <label>Domínio para consulta
                    <select name="domain" required data-batch-domain class="input-minimal">
                        <?php foreach ($batchEnabledDomains as $domainKey => $domain): ?>
                            <option value="<?= htmlspecialchars($domainKey, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedAdDomain === $domainKey ? 'selected' : '' ?>><?= htmlspecialchars($domainKey === 'SAUDE' ? 'SAÚDE' : $domainKey, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                        <?php if ($batchEnabledDomains === []): ?><option value="">Nenhum domínio habilitado</option><?php endif; ?>
                    </select>
                </label>
                <label>Carregar lista (.txt ou .csv)
                    <input type="file" data-batch-file accept=".txt,.csv,text/plain,text/csv">
                </label>
            </div>
            <label class="batch-import-list-label">Lista de pessoas — uma por linha
                <textarea data-batch-list rows="7" required maxlength="260000" spellcheck="false" placeholder="Ana Maria Silva&#10;joao.souza&#10;<?= htmlspecialchars($selectedAdDomain, ENT_QUOTES, 'UTF-8') ?>\maria.santos" class="input-minimal"></textarea>
            </label>
            <p class="batch-import-help">Até 1.000 entradas por lista. Use o nome completo como aparece no AD ou o login corporativo. Em caso de nomes iguais, informe o login. O CSV deve ter uma única coluna (nome ou login).</p>
            <p data-batch-count class="batch-import-help" aria-live="polite">Nenhuma entrada informada.</p>
            <div class="batch-import-actions">
                <button type="submit" data-batch-submit class="batch-import-primary"><?= $batchGroupId === null ? 'Importar lista do AD' : 'Importar e adicionar à equipe' ?></button>
                <button type="button" data-batch-stop hidden>Parar após esta etapa</button>
                <button type="button" data-batch-retry hidden>Processar pendências</button>
            </div>
        </form>
        <p data-batch-message role="status" aria-live="polite" class="batch-import-message"></p>
        <div data-batch-progress-wrap hidden>
            <progress data-batch-progress value="0" max="1" aria-label="Progresso da importação"></progress>
            <p data-batch-progress-text class="batch-import-help"></p>
        </div>
        <section data-batch-report hidden aria-label="Resultado da importação">
            <h3>Resultado da lista</h3>
            <div data-batch-summary class="batch-import-summary" aria-live="polite"></div>
            <div class="batch-import-actions">
                <button type="button" data-batch-export>Baixar relatório CSV</button>
                <button type="button" data-batch-copy hidden>Copiar não encontrados</button>
                <a href="<?= $batchGroupId === null ? 'index.php?tab=usuarios' : 'index.php?tab=editar_grupo&id=' . $batchGroupId . '&group_tab=users' ?>" data-batch-refresh>Atualizar <?= $batchGroupId === null ? 'diretório' : 'membros' ?></a>
            </div>
            <label class="batch-import-filter">Exibir
                <select data-batch-filter><option value="all">Todos os resultados</option><option value="issues">Pendências e entradas que precisam de atenção</option><option value="not_found">Não encontrados no AD</option><option value="success">Concluídos</option></select>
            </label>
            <div class="batch-import-table-wrap">
                <table><thead><tr><th scope="col">Linha</th><th scope="col">Nome ou login informado</th><th scope="col">Resultado</th><th scope="col">Detalhes</th></tr></thead><tbody data-batch-results></tbody></table>
            </div>
        </section>
        <noscript><p class="batch-import-warning">Ative o JavaScript para usar a importação em lote com acompanhamento de progresso.</p></noscript>
    </div>
</details>
