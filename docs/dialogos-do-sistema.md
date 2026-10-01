# Avisos e confirmações do DocGov

A pesquisa no código identificou caixas nativas (`confirm`, `prompt` e `alert`)
nos fluxos de descarte e exclusão da estrutura, documentos, equipes, acessos,
domínios do AD e erros do portal. Essas caixas não recebem o CSS da aplicação.
Os fluxos agora usam o componente compartilhado `assets/dialogs.js` e
`assets/dialogs.css`, carregado por `partials/dialog_assets.php`.

O visual usa os tokens de superfícies, bordas e texto do portal, com cores
semânticas para perigo, restauração e atenção. Os diálogos têm título, ícone,
botões com ação explícita, fundo escurecido e layout para celular. A animação
respeita a preferência de redução de movimento.

## Comportamento

- O elemento nativo `<dialog>` aberto com `showModal()` torna o fundo inerte.
- Cancelar, fechar e Esc encerram a confirmação sem enviar o formulário.
- O foco começa em Cancelar, ou no campo de nome para exclusão permanente,
  e retorna ao controle que abriu o diálogo.
- A exclusão permanente mantém a confirmação pelo nome e mostra o impacto.
- Dados variáveis são inseridos como texto, sem interpretar HTML.
- `requestSubmit()` preserva validação, CSRF e `name`/`value` do botão clicado,
  inclusive quando o menu do documento está fora do formulário.
- Avisos simultâneos são enfileirados. Navegadores sem `<dialog>` usam as
  caixas nativas como compatibilidade.

Para uma confirmação em formulário, adicione `data-confirm`,
`data-confirm-title`, `data-confirm-label` e `data-confirm-tone` ao formulário
ou ao botão de envio. Para ações assíncronas, use
`await window.DocGovDialog.confirm({ title, message, confirmLabel, tone })`.
Avisos usam `window.DocGovDialog.alert({ title, message })`.

## Referências e verificação

- [W3C: Alert and Message Dialogs](https://www.w3.org/WAI/ARIA/apg/patterns/alertdialog/)
- [MDN: elemento dialog](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/dialog)
- `scratch/test_dialogs_browser.js`: claro/escuro, desktop/celular, Tab,
  Shift+Tab, Esc, cancelamento, nome exato, HTML como texto, payload dos
  formulários, ações em lote, menus externos ao formulário, fila e foco.
  Os POSTs administrativos são interceptados para preservar os dados.
- `scratch/test_admin_document_action_menu_browser.js`: regressão dos menus
  de documentos, com telas pequenas e temas claro/escuro.

Os testes usam Chrome e Playwright; `DOCGOV_PLAYWRIGHT_PACKAGE` permite indicar
um pacote instalado fora do projeto.
