# Guia de uso do DocGov

Base: comportamento inspecionado em 01/10/2026. Os nomes e funcionalidades visíveis dependem das permissões da conta.

## Entrada e consulta

1. Abra `login.php`, selecione um domínio habilitado e informe usuário e senha corporativos.
2. Após o login, o sistema abre o acervo em `index.php`.
3. Navegue por categoria, subcategoria e assunto ou use a pesquisa e os filtros disponíveis.
4. No assunto, abra a seção e o conteúdo desejado. As seções de documentos publicadas aparecem conforme o acervo existente.
5. Use favoritos para guardar documentos, subcategorias e assuntos aos quais você possui acesso. Uma marcação de favorito não mantém o acesso após sua revogação.

`minha_conta.php` reúne dados da conta e preferências. `notificacoes.php` mostra avisos relacionados aos fluxos. O botão administrativo aparece conforme o acesso ao painel.

## Quem pode fazer o quê

| Acesso | Atribuições |
| --- | --- |
| Leitura (`view`) | Consultar e baixar conteúdo publicado autorizado |
| Edição (`edit`) | Criar/editar conteúdo no escopo e enviar para revisão |
| Administração do recurso (`admin`) | Administrar o ramo, gerenciar permissões e revisar/aprovar documentos no escopo |
| Administrador global (`users.role = admin`) | Administrar o sistema e criar categorias na raiz |
| Capacidade administrativa por equipe | Usar somente os módulos globais explicitamente delegados |

Uma permissão de edição não autoriza publicação direta. Administração de um assunto também não libera automaticamente configurações globais ou gestão de equipes.

## Criação e revisão de conteúdo

Selecione um assunto permitido e a seção correspondente ao conteúdo. Os editores disponíveis incluem texto formatado, arquivo, código, vídeo, link, fluxo e organograma. Para texto, o HTML passa por sanitização; para fluxos e organogramas, o sistema valida a estrutura enviada.

O fluxo de documentos é:

1. **Rascunho:** o editor prepara o conteúdo e pode salvá-lo sem publicar.
2. **Em revisão:** o editor envia o documento para avaliação.
3. **Revisão concluída:** um administrador autorizado registra o parecer; o estado continua em revisão.
4. **Publicado:** um administrador autorizado aprova depois da conclusão da revisão.
5. **Ajustes:** uma recusa com justificativa devolve o documento a rascunho.

Arquivamento usa o estado inativo. A lixeira possui metadados próprios e não equivale à exclusão permanente da estrutura. Pendências editoriais com prazo vencido podem ser inativadas pelo runtime; o prazo inicial é de um mês e não significa um mês adicional a cada edição.

Rascunhos e conteúdos em revisão ficam restritos a quem pode editá-los. Ao reenviar um documento publicado para revisão, o estado deixa de ser publicado; confira o efeito no acervo antes de atualizar um conteúdo em uso.

## Assunto público ou privado

**Público** libera a leitura dos conteúdos publicados para contas autenticadas e ativas. **Privado** segue as concessões de acesso. Público não significa acesso sem login e não concede edição.

Os ancestrais aparecem para permitir chegar ao assunto público sem liberar os irmãos privados. A alteração da visibilidade exige administração do assunto; criar um assunto público exige administração da subcategoria. Veja [as regras detalhadas](assuntos-publicos-privados.md).

## Documentação do processo no assunto

Além dos documentos, o assunto pode ter um workspace com visão geral, descrição, fluxo, etapas, vídeo, evidências, perguntas frequentes e integrações. Esse workspace possui histórico e fluxo próprio: rascunho, revisão, aprovado e obsoleto. Sua aprovação é diferente da publicação individual de documentos.

## Administração e suporte

Gerencie permissões no recurso correto. A permissão efetiva usa o maior nível entre concessões diretas, equipes e ancestrais; uma concessão menor não reduz outra maior.

Configurações, autenticação, diretório, auditoria e tags podem ser delegados por capacidades de equipe. A gestão das equipes e dessas capacidades permanece exclusiva do administrador global.

Em **Equipes → Editar equipe → Subgrupos**, o administrador global pode incluir outras equipes. Membros dos subgrupos recebem os acessos ao conteúdo da equipe superior por caminhos ativos. A tela mostra membros indiretos e equipes superiores; remover um vínculo preserva a equipe e suas outras origens de acesso. Capacidades administrativas globais continuam vinculadas aos membros diretos. Veja [regras e exemplos](grupos-aninhados.md).

Para solicitar suporte, informe a página, ação, horário e identificação do recurso. Evite enviar senhas, tokens ou cookies. Consulte [operação e diagnóstico](INSTALACAO_OPERACAO.md) quando houver erro de banco, upload, sessão ou AD.
