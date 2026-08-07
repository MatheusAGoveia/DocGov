# 🔐 Arquitetura Definitiva de Gestão de Acesso — DocGov (Modelo Folder Permissions / Teams)

> **Documento de Arquitetura e Especificação Técnica**  
> **Status:** Proposta para Revisão (Pré-Migração)  
> **Inspiração Conceitual:** Modelo de Folder Permissions & Teams (Grafana-like)  
> **Nota:** Este documento descreve apenas os conceitos, matriz de regras, algoritmos e modelo relacional. Nenhuma alteração de código ou banco foi aplicada nesta fase.

---

## 1. Visão Geral e Princípios Fundamentais

O novo modelo de controle de acesso do **DocGov** baseia-se nos seguintes princípios conceituais:

1. **Principais (Atores):**
   - **Usuário (`user_id`)**: Usuário individual cadastrado no sistema.
   - **Grupo (`group_id`)**: Agrupamento de usuários (ex: *Recursos Humanos*, *Gestores*, *Financeiro*).

2. **Recursos Hierárquicos:**
   $$\text{Categoria} \longrightarrow \text{Subcategoria} \longrightarrow \text{Assunto} \longrightarrow \text{Documento}$$
   - **Documento** não possui tabela/permissão própria na v1. Todo documento herda 100% dos direitos concedidos ao seu **Assunto** pai.

3. **Níveis de Permissão Escalares:**
   - `View` = 1 (Visualização de metadados, estrutura e download/leitura de documentos)
   - `Edit` = 2 (Criação, alteração e publicação de documentos e conteúdos)
   - `Admin` = 3 (Gestão total do recurso + concessão/revogação de permissões)

4. **Maior Permissão Vence (Max Permissive):**
   - O nível efetivo final é **sempre o valor máximo (`MAX`)** obtido de todas as origens (acesso direto, grupos do usuário e herança de recursos pai).
   - **Sem negação (`DENY`)**: Não existem regras de negação explícita. Uma permissão menor atribuída diretamente nunca reduz uma permissão maior recebida por grupo ou ancestral.

5. **Negação Padrão (*Default Deny*):**
   - Usuário não-admin sem permissão efetiva $\ge 1$ e sem descendentes com permissão possui acesso zerado (`0`). O recurso é invisível na navegação e inacessível via rota/API (`403 Forbidden`).

---

## 2. Matriz Completa de Regras

| Atribuição de Permissão | Recurso Alvo | Herança Descendente | Combinação de Origens | Permissão Efetiva Resultante |
|---|---|---|---|---|
| Admin Global (`users.role = 'admin'`) | Qualquer recurso | N/A (Bypass) | N/A | **Admin (3)** em todo o sistema (`HAS_PERMISSION = true`) |
| Usuário / Grupo $\rightarrow$ **Categoria RH** (`Edit`) | Categoria RH | Propaga `Edit` para Subcategorias e Assuntos | N/A | **Edit (2)** em RH, Férias, Solicitações e Docs |
| Usuário em Grupo A (`View`) + Grupo B (`Edit`) no **mesmo recurso** | Categoria RH | Propaga `max(View, Edit) = Edit` | `max(1, 2)` | **Edit (2)** na Categoria RH e descendentes |
| Usuário com Acesso Direto (`View`) + Grupo A (`Admin`) no **mesmo recurso** | Subcategoria Férias | Propaga `max(View, Admin) = Admin` | `max(1, 3)` | **Admin (3)** na Subcategoria Férias e descendentes |
| **Herança + Permissão Direta Menor**: Categoria RH (`Edit`) + Subcategoria Férias (`View` direto) | Subcategoria Férias | Cat RH propaga `Edit` (2). Subcat tem `View` (1) | `max(2, 1)` | **Edit (2)** em Férias. *(A permissão direta menor NÃO reduz a herdada)* |
| **Herança + Permissão Direta Maior**: Categoria RH (`View`) + Subcategoria Férias (`Admin` direto) | Subcategoria Férias | Cat RH propaga `View` (1). Subcat tem `Admin` (3) | `max(1, 3)` | **Admin (3)** em Férias e seus Assuntos |
| Usuário com acesso **apenas ao Assunto** `RH -> Férias -> Solicitação` | Assunto Solicitação | Não propaga para cima | N/A | Assunto Solicitação: **View/Edit/Admin (1-3)**. RH e Férias: `VISIBLE_AS_ANCESTOR = true` |
| Recursos/Irmãos sem concessão (ex: `RH -> Benefícios`) | Subcat Benefícios | N/A | N/A | **Nenhum Acesso (0)** (`HAS_PERMISSION = false`, `VISIBLE_AS_ANCESTOR = false`) |

---

## 3. Algoritmo de Permissão Efetiva

Dada a entrada de um Usuário $u$ e um Recurso $R$ (Categoria, Subcategoria, Assunto ou Documento):

### Pseudo-código do Algoritmo:

```python
def get_effective_permission(user, resource):
    # 1. Bypass de Admin Global
    if user.role == 'admin':
        return PERMISSION_ADMIN  # Nível 3

    # 2. Se o recurso for Documento, resolve para seu Assunto pai
    if resource.type == 'DOCUMENT':
        resource = get_subject_of_document(resource.id)

    # 3. Mapear todos os Principais aos quais o usuário pertence
    user_principals = [user.id]
    user_groups = get_active_groups_for_user(user.id)
    
    # 4. Construir a cadeia de Recursos Ancestrais (Caminho do Nó até a Raiz)
    resource_chain = []
    if resource.type == 'SUBJECT':
        resource_chain = [
            ('SUBJECT', resource.id),
            ('SUBCATEGORY', resource.subcategory_id),
            ('CATEGORY', resource.category_id)
        ]
    elif resource.type == 'SUBCATEGORY':
        resource_chain = [
            ('SUBCATEGORY', resource.id),
            ('CATEGORY', resource.category_id)
        ]
    elif resource.type == 'CATEGORY':
        resource_chain = [
            ('CATEGORY', resource.id)
        ]

    # 5. Buscar todas as regras ativas no banco para (Principais x Cadeia de Recursos)
    permissions_found = query_resource_permissions(
        user_ids=user_principals,
        group_ids=user_groups,
        resource_chain=resource_chain
    )

    # 6. Calcular a Permissão Efetiva via MAX
    max_level = 0  # 0 = NONE, 1 = VIEW, 2 = EDIT, 3 = ADMIN
    for perm in permissions_found:
        level_value = map_level_to_int(perm.permission_level)
        if level_value > max_level:
            max_level = level_value

    return max_level
```

---

## 4. Algoritmo de Herança (Top-Down Propagation)

A herança no DocGov é descendente e aditiva. As permissões concedidas em um nó de nível superior aplicam-se automaticamente a todos os seus descendentes.

### Regra de Propagação:
$$\text{EffectiveLevel}(u, R_{\text{filho}}) = \max \Big( \text{DirectLevel}(u, R_{\text{filho}}),\, \text{EffectiveLevel}(u, R_{\text{pai}}) \Big)$$

- **Exemplo de Cascata:**
  1. Permissão atribuída na **Categoria RH** = `Edit` (2).
  2. Subcategoria **Férias** (filha de RH): não possui registro próprio no banco. Herda `Edit` (2).
  3. Assunto **Solicitação de Férias** (filho de Férias): possui registro direto `Admin` (3). Nível final em Solicitação = $\max(2, 3) = 3$ (`Admin`).
  4. Assunto **Historico de Férias** (filho de Férias): possui registro direto `View` (1). Nível final em Histórico = $\max(2, 1) = 2$ (`Edit`).

---

## 5. Comportamento de Ancestrais (`HAS_PERMISSION` vs `VISIBLE_AS_ANCESTOR`)

Para permitir a navegação em árvore sem liberar acessos indevidos a pastas/recursos irmãos, formalizamos duas flags booleanas distintas no contexto de navegação:

### 1. `HAS_PERMISSION(u, R)`
- **Condição:** `get_effective_permission(u, R) >= 1`.
- **Significado:** O usuário possui direitos explícitos (diretos ou herdados) sobre o recurso $R$.
- **Capacidades:** Pode visualizar os documentos do recurso, listar todos os subitens diretos, realizar ações conforme o nível (`View`, `Edit`, `Admin`).

### 2. `VISIBLE_AS_ANCESTOR(u, R)`
- **Condição:** `HAS_PERMISSION(u, R) == false` **E** existe ao menos um descendente $D \in \text{Descendentes}(R)$ com `HAS_PERMISSION(u, D) == true` (ou `VISIBLE_AS_ANCESTOR(u, D) == true`).
- **Significado:** O nó $R$ atua puramente como um container estrutural de passagem no menu/árvore.

### Matriz de Diferenciação de Funcionalidades:

| Ação / Funcionalidade | `HAS_PERMISSION = true` | `VISIBLE_AS_ANCESTOR = true` | `Ambos False` |
|---|---|---|---|
| Exibição no Menu / Árvore de Navegação | **SIM** | **SIM** | **NÃO** (Totalmente Oculto) |
| Permite Expandir Pasta / Nó | **SIM** | **SIM** *(apenas caminho para o filho)* | **NÃO** |
| Ver Conteúdos / Documentos Diretos do Nó | **SIM** | **NÃO** *(Retorna 403 / Lista Vazia)* | **NÃO** *(403 Forbidden)* |
| Ver Nós Irmãos sem Permissão | **SIM** *(se a permissão for no Pai)* | **NÃO** | **NÃO** |
| Ações de Edição / Upload no Nó | **SIM** *(se Edit/Admin)* | **NÃO** *(403 Forbidden)* | **NÃO** *(403 Forbidden)* |
| Gerenciamento de Permissões no Nó | **SIM** *(se Admin)* | **NÃO** *(403 Forbidden)* | **NÃO** *(403 Forbidden)* |

---

## 6. Interação entre Acesso Direto e Grupos

Um usuário $u$ pode estar associado a múltiplos grupos $G_1, G_2, \dots, G_n$ e também possuir concessões diretas vinculadas ao seu `user_id`.

- **Regra de Unificação:** Não há prioridade de "Acesso Direto sobre Grupo" ou "Grupo sobre Acesso Direto". Ambas as fontes são avaliadas em igualdade de condições sob a função $\max()$.
- **Cenário de Exemplo:**
  - Usuário *João* pertence ao Grupo *Atendimento* (`View` na Categoria *RH*).
  - Usuário *João* pertence ao Grupo *Supervisores* (`Edit` no Assunto *Solicitação*).
  - Usuário *João* possui concessão direta (`Admin` na Subcategoria *Férias*).
  
  **Resultado Efetivo para João:**
  - Categoria *RH*: $\max(1) =$ **`View` (1)**
  - Subcategoria *Férias*: $\max(\text{RH: } 1, \text{Direto: } 3) =$ **`Admin` (3)**
  - Assunto *Solicitação*: $\max(\text{RH: } 1, \text{Férias: } 3, \text{Supervisores: } 2) =$ **`Admin` (3)**

---

## 7. Comportamento do Admin Global (`role = 'admin'`)

- O campo `users.role` mantém os papéis globais do sistema (`admin`, `editor`, `reader`).
- Quando `users.role == 'admin'`:
  - O sistema ignora a verificação da tabela `resource_permissions`.
  - Retorna `get_effective_permission() = ADMIN (3)` para **todos** os recursos.
  - `HAS_PERMISSION` é `true` em todas as Categorias, Subcategorias, Assuntos e Documentos.
  - Permite manutenção de emergência, criação de novas estruturas e auditoria completa sem dependência de regras cadastrais.

---

## 8. Casos-Limite e Tratamento de Exceções

1. **Inativação de Usuário ou Grupo:**
   - Se `users.active = false`, a sessão do usuário é inválida e nenhuma permissão é concedida.
   - Se `groups.active = false`, o grupo é descartado no cálculo de `user_principals`.

2. **Reorganização de Estrutura (Mover Subcategoria / Assunto):**
   - Como os relacionamentos de herança são resolvidos em tempo de execução via chave estrangeira (`category_id` e `subcategory_id`), a mudança do pai altera imediatamente a permissão herdada de todos os filhos sem necessidade de reprocessamento em lote.

3. **Exclusão de Recurso ou Principal:**
   - A constraint `ON DELETE CASCADE` garante que, ao excluir um Usuário, Grupo, Categoria, Subcategoria ou Assunto, todas as regras associadas na tabela de permissões sejam limpas automaticamente.

4. **Busca Global e Listagem de Documentos:**
   - As queries de busca (ex: barra de pesquisa global ou favoritos) devem incluir um filtro SQL de verificação de permissão no `subject_id` do documento, evitando o vazamento de metadados ou links de documentos aos quais o usuário não tem acesso.

---

## 9. Proposta da Estrutura de Banco de Dados

Substituição da antiga tabela de rascunho `group_access` pela tabela definitiva e unificada `resource_permissions`.

### DDl PostgreSQL Proposto:

```sql
-- ==============================================================================
-- REFORMULAÇÃO DA GESTÃO DE ACESSO (Modelo Resource Permissions)
-- ==============================================================================

-- 1. Remover a estrutura antiga de rascunho (se existir)
DROP TABLE IF EXISTS group_access CASCADE;

-- 2. Criar a nova tabela unificada de permissões por recurso
CREATE TABLE resource_permissions (
    id SERIAL PRIMARY KEY,
    
    -- Principal: Exatamente UM deve ser preenchido
    user_id INT NULL REFERENCES users(id) ON DELETE CASCADE,
    group_id INT NULL REFERENCES groups(id) ON DELETE CASCADE,
    
    -- Recurso Alvo: Exatamente UM deve ser preenchido
    category_id INT NULL REFERENCES categories(id) ON DELETE CASCADE,
    subcategory_id INT NULL REFERENCES subcategories(id) ON DELETE CASCADE,
    subject_id INT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    
    -- Nível de Permissão (view = 1, edit = 2, admin = 3)
    permission_level VARCHAR(10) NOT NULL CHECK (permission_level IN ('view', 'edit', 'admin')),
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Validação: Apenas 1 Principal por registro
    CONSTRAINT chk_resource_permissions_principal CHECK (
        num_nonnulls(user_id, group_id) = 1
    ),
    
    -- Validação: Apenas 1 Recurso por registro
    CONSTRAINT chk_resource_permissions_resource CHECK (
        num_nonnulls(category_id, subcategory_id, subject_id) = 1
    )
);

-- Trigger para updated_at automático
CREATE TRIGGER trg_resource_permissions_updated_at
    BEFORE UPDATE ON resource_permissions
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- Unicidade: Evita regras duplicadas para o mesmo Principal + Recurso
CREATE UNIQUE INDEX uk_perm_user_category ON resource_permissions(user_id, category_id) WHERE user_id IS NOT NULL AND category_id IS NOT NULL;
CREATE UNIQUE INDEX uk_perm_user_subcategory ON resource_permissions(user_id, subcategory_id) WHERE user_id IS NOT NULL AND subcategory_id IS NOT NULL;
CREATE UNIQUE INDEX uk_perm_user_subject ON resource_permissions(user_id, subject_id) WHERE user_id IS NOT NULL AND subject_id IS NOT NULL;

CREATE UNIQUE INDEX uk_perm_group_category ON resource_permissions(group_id, category_id) WHERE group_id IS NOT NULL AND category_id IS NOT NULL;
CREATE UNIQUE INDEX uk_perm_group_subcategory ON resource_permissions(group_id, subcategory_id) WHERE group_id IS NOT NULL AND subcategory_id IS NOT NULL;
CREATE UNIQUE INDEX uk_perm_group_subject ON resource_permissions(group_id, subject_id) WHERE group_id IS NOT NULL AND subject_id IS NOT NULL;

-- Índices de Alta Performance para Joins
CREATE INDEX idx_perm_user_id ON resource_permissions(user_id) WHERE user_id IS NOT NULL;
CREATE INDEX idx_perm_group_id ON resource_permissions(group_id) WHERE group_id IS NOT NULL;
CREATE INDEX idx_perm_category_id ON resource_permissions(category_id) WHERE category_id IS NOT NULL;
CREATE INDEX idx_perm_subcategory_id ON resource_permissions(subcategory_id) WHERE subcategory_id IS NOT NULL;
CREATE INDEX idx_perm_subject_id ON resource_permissions(subject_id) WHERE subject_id IS NOT NULL;
```

---

## 10. Exemplo de Query SQL para Permissão Efetiva

Abaixo, a consulta SQL otimizada que resolve a permissão efetiva de um usuário (ex: `user_id = 42`) para todos os Assuntos do sistema em uma única passagem:

```sql
WITH user_principals AS (
    -- Usuário direto + Grupos ativos dos quais ele faz parte
    SELECT 42 AS user_id, NULL::int AS group_id
    UNION ALL
    SELECT NULL AS user_id, ug.group_id
    FROM user_groups ug
    JOIN groups g ON g.id = ug.group_id
    WHERE ug.user_id = 42 AND g.active = TRUE
),
active_rules AS (
    -- Regras ativas aplicáveis aos principais do usuário com valoração numérica
    SELECT 
        rp.category_id,
        rp.subcategory_id,
        rp.subject_id,
        CASE rp.permission_level
            WHEN 'view' THEN 1
            WHEN 'edit' THEN 2
            WHEN 'admin' THEN 3
            ELSE 0
        END AS lvl
    FROM resource_permissions rp
    JOIN user_principals up 
      ON (rp.user_id = up.user_id) OR (rp.group_id = up.group_id)
)
SELECT 
    cat.id AS category_id,
    cat.name AS category_name,
    sub.id AS subcategory_id,
    sub.name AS subcategory_name,
    s.id AS subject_id,
    s.name AS subject_name,
    MAX(GREATEST(
        COALESCE(p_cat.lvl, 0),
        COALESCE(p_sub.lvl, 0),
        COALESCE(p_subj.lvl, 0)
    )) AS effective_permission_level
FROM subjects s
JOIN subcategories sub ON sub.id = s.subcategory_id
JOIN categories cat ON cat.id = sub.category_id
LEFT JOIN active_rules p_cat ON p_cat.category_id = cat.id
LEFT JOIN active_rules p_sub ON p_sub.subcategory_id = sub.id
LEFT JOIN active_rules p_subj ON p_subj.subject_id = s.id
GROUP BY cat.id, cat.name, sub.id, sub.name, s.id, s.name;
```
