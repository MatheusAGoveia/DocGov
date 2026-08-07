# Matriz Definitiva de Capacidades do DocGov

> **Documento de Formalização Arquitetural**  
> Este documento consolida a especificação oficial dos privilégios, papéis, níveis de acesso e capacidades operacionais do sistema DocGov.

---

## 1. Definição e Distinção de Papéis

### 🛡️ 1.1 Administrador Geral (Global Admin)
- **Identificador de Banco**: `users.role = 'admin'`
- **Escopo**: **Global e Irrestrito**.
- **Capacidades**:
  - Possui autorização total para criar, editar, visualizar e excluir qualquer recurso em todo o sistema.
  - **Único papel capaz de criar novas Categorias de nível raiz**.
  - Possui acesso direto a todas as telas administrativas e de configuração de permissões.

### 👑 1.2 Administrador de Recurso (Folder / Resource Admin)
- **Identificador no Motor**: `permission_level = 'admin'` (nível 3 / peso 3) associado diretamente ao usuário ou herdado via Grupo/Ancestral.
- **Escopo**: **Restrito ao recurso específico e seus descendentes**.
- **Capacidades**:
  - **NÃO é um Administrador Geral**. Não possui o flag `users.role = 'admin'`.
  - Pode **editar metadados** da pasta/recurso sob sua administração.
  - Pode **criar e editar descendentes** (subcategorias, assuntos, documentos).
  - Pode **gerenciar a aba de permissões da pasta** (`res_tab=permissions`), concedendo ou revogando acessos de usuários e grupos naquela pasta.

### ✏️ 1.3 Editor de Recurso (Resource Editor)
- **Identificador no Motor**: `permission_level = 'edit'` (nível 2 / peso 2) associado diretamente ao usuário ou herdado via Grupo/Ancestral.
- **Escopo**: **Restrito ao recurso específico e seus descendentes**.
- **Capacidades**:
  - Pode **criar e editar conteúdos** (subcategorias, assuntos e documentos) na pasta sob seu escopo.
  - Pode **alterar dados e publicar documentos** sob seu escopo de edição.
  - **NÃO pode gerenciar permissões da pasta** (a aba `Permissões` fica bloqueada).

### 👁️ 1.4 Visualizador (Resource Viewer / Reader)
- **Identificador no Motor**: `permission_level = 'view'` (nível 1 / peso 1) associado diretamente ao usuário ou herdado via Grupo/Ancestral.
- **Escopo**: **Restrito ao recurso específico e seus descendentes**.
- **Capacidades**:
  - Pode **visualizar e ler conteúdos publicados** no portal operacional, visualizadores (PDF/Imagem/Texto) e endpoints de download.
  - **NÃO pode criar, editar ou gerenciar permissões**.

---

## 2. Matriz Definitiva de Operações e Capacidades

| Ação Operacional | Recurso Alvo | Nível Mínimo Exigido | Herança Conta? | Quem Pode Executar |
| :--- | :--- | :--- | :---: | :--- |
| **CREATE CATEGORY** | Sistema (Raiz) | Admin Global | N/A | Exclusivamente **Admin Geral** (`role = 'admin'`) |
| **EDIT CATEGORY** | Categoria | Admin de Recurso | Sim | Admin Geral **OU** Admin Efetivo da Categoria (`admin`) |
| **CREATE SUBCATEGORY** | Categoria Pai | Edit de Recurso | Sim | Admin Geral **OU** Edit/Admin Efetivo da Categoria pai (`>= edit`) |
| **EDIT SUBCATEGORY** | Subcategoria | Edit de Recurso | Sim | Admin Geral **OU** Edit/Admin Efetivo da Subcategoria (`>= edit`) |
| **CREATE SUBJECT** | Subcategoria Pai | Edit de Recurso | Sim | Admin Geral **OU** Edit/Admin Efetivo da Subcategoria pai (`>= edit`) |
| **EDIT SUBJECT** | Assunto | Edit de Recurso | Sim | Admin Geral **OU** Edit/Admin Efetivo do Assunto (`>= edit`) |
| **CREATE DOCUMENT** | Assunto Pai | Edit de Recurso | Sim | Admin Geral **OU** Edit/Admin Efetivo do Assunto pai (`>= edit`) |
| **EDIT DOCUMENT** | Assunto Pai / Doc | Edit de Recurso | Sim | Admin Geral **OU** Edit/Admin Efetivo do Assunto pai (`>= edit`) |
| **MANAGE PERMISSIONS** | Categoria / Subcat / Assunto | Admin de Recurso | Sim | Admin Geral **OU** Admin Efetivo daquela pasta (`admin`) |
| **VIEW DOCUMENT / CONTENT** | Categoria / Subcat / Assunto / Doc | View de Recurso | Sim | Admin Geral **OU** View Efetivo daquele recurso (`>= view`) |

---

## 3. Detalhamento por Ação com Exemplos Práticos

### 3.1 `CREATE CATEGORY`
- **Ação**: Criar uma nova Categoria no nível raiz do sistema.
- **Recurso Necessário**: Sistema (Raiz).
- **Nível Mínimo**: Admin Global (`users.role = 'admin'`).
- **Herança Conta?**: Não (recurso raiz não possui ancestrais).
- **Exemplos Permitidos**:
  - Usuário `João` (`role = 'admin'`) cria a Categoria *"Obras Públicas"*.
- **Exemplos Bloqueados**:
  - Usuário `Maria` (`role = 'reader'`, mas com permissão `admin` na Categoria *"Recursos Humanos"*) tenta criar uma nova Categoria *"Finanças"*. **[BLOQUEADO: Apenas Admin Geral pode criar categorias no nível raiz]**.

---

### 3.2 `EDIT CATEGORY`
- **Ação**: Alterar nome, descrição ou status de uma Categoria existente.
- **Recurso Necessário**: Categoria.
- **Nível Mínimo**: `admin` (Admin de Recurso) na Categoria.
- **Herança Conta?**: Não (Categoria é a raiz da árvore).
- **Exemplos Permitidos**:
  - Usuário `João` (`role = 'admin'`) edita a Categoria *"Recursos Humanos"*.
  - Usuário `Carlos` (`role = 'reader'`), que possui permissão direta ou via grupo `admin` na Categoria *"Recursos Humanos"*, edita seus metadados.
- **Exemplos Bloqueados**:
  - Usuário `Ana` (`role = 'reader'`), com permissão `edit` na Categoria *"Recursos Humanos"*, tenta alterar o nome da Categoria. **[BLOQUEADO: Edição de Categoria exige nível Admin da pasta]**.

---

### 3.3 `CREATE SUBCATEGORY`
- **Ação**: Adicionar uma nova Subcategoria dentro de uma Categoria.
- **Recurso Necessário**: Categoria Pai.
- **Nível Mínimo**: `edit` (ou `admin`) na Categoria Pai.
- **Herança Conta?**: Sim (se o usuário tem `edit` na Categoria pai, a capacidade de criar subcategorias é garantida).
- **Exemplos Permitidos**:
  - Usuário `Ana` com permissão `edit` na Categoria *"Recursos Humanos"* cria a Subcategoria *"Férias"*.
- **Exemplos Bloqueados**:
  - Usuário `Pedro` com permissão `view` na Categoria *"Recursos Humanos"* tenta criar a Subcategoria *"Benefícios"*. **[BLOQUEADO: Exige nível >= Edit na Categoria pai]**.

---

### 3.4 `EDIT SUBCATEGORY`
- **Ação**: Alterar nome, descrição ou status de uma Subcategoria.
- **Recurso Necessário**: Subcategoria Alvo.
- **Nível Mínimo**: `edit` (ou `admin`) efetivo na Subcategoria.
- **Herança Conta?**: Sim (permissão `edit` herdada da Categoria pai autoriza a edição da Subcategoria).
- **Exemplos Permitidos**:
  - Usuário `Ana` com permissão `edit` na Categoria *"Recursos Humanos"* edita a Subcategoria *"Férias"*.
  - Usuário `Marcos` com permissão direta `edit` na Subcategoria *"Férias"* edita a Subcategoria *"Férias"*.
- **Exemplos Bloqueados**:
  - Usuário `Pedro` com permissão `view` na Categoria *"Recursos Humanos"* tenta editar a Subcategoria *"Férias"*. **[BLOQUEADO: Nível efetivo é apenas View]**.

---

### 3.5 `CREATE SUBJECT`
- **Ação**: Criar um novo Assunto dentro de uma Subcategoria.
- **Recurso Necessário**: Subcategoria Pai.
- **Nível Mínimo**: `edit` (ou `admin`) efetivo na Subcategoria Pai.
- **Herança Conta?**: Sim (permissão `edit` herdada da Categoria ou da Subcategoria).
- **Exemplos Permitidos**:
  - Usuário com `edit` na Subcategoria *"Férias"* cria o Assunto *"Solicitação de Licença"*.
- **Exemplos Bloqueados**:
  - Usuário com permissão `view` na Subcategoria *"Férias"* tenta criar o Assunto *"Solicitação de Licença"*. **[BLOQUEADO: Nível de permissão insuficiente]**.

---

### 3.6 `EDIT SUBJECT`
- **Ação**: Alterar nome, descrição ou status de um Assunto.
- **Recurso Necessário**: Assunto Alvo.
- **Nível Mínimo**: `edit` (ou `admin`) efetivo no Assunto.
- **Herança Conta?**: Sim (permissão herdada da Categoria pai ou Subcategoria pai).
- **Exemplos Permitidos**:
  - Usuário com `edit` na Categoria *"RH"* edita o Assunto *"Solicitação de Licença"*.
- **Exemplos Bloqueados**:
  - Usuário com permissão `view` no Assunto tenta alterar sua descrição. **[BLOQUEADO: Requer permissão >= Edit]**.

---

### 3.7 `CREATE DOCUMENT` & `EDIT DOCUMENT`
- **Ação**: Cadastrar novo documento ou alterar arquivo/metadados/status de um documento existente.
- **Recurso Necessário**: Assunto Pai.
- **Nível Mínimo**: `edit` (ou `admin`) efetivo no Assunto.
- **Herança Conta?**: Sim (documento herda permissões do Assunto pai, que por sua vez herda de Subcategoria e Categoria).
- **Exemplos Permitidos**:
  - Usuário com permissão `edit` no Grupo *"RH"* envia novo PDF ou edita o documento *"Formulário 2026.pdf"* no Assunto *"Solicitação de Licença"*.
- **Exemplos Bloqueados**:
  - Usuário com permissão `view` no Assunto tenta alterar o arquivo PDF ou mudar o status para *"Publicado"*. **[BLOQUEADO: Requer nível >= Edit]**.

---

### 3.8 `MANAGE PERMISSIONS` (Gerenciar Permissões da Pasta)
- **Ação**: Acessar a aba `Permissões` (`res_tab=permissions`) e adicionar ou remover concessões para Usuários ou Grupos naquele recurso.
- **Recurso Necessário**: Categoria, Subcategoria ou Assunto.
- **Nível Mínimo**: `admin` (Admin de Recurso) efetivo naquele recurso específico.
- **Herança Conta?**: Sim (se o usuário tem `admin` na Categoria pai, ele é Admin de Recurso de todas as subcategorias e assuntos descendentes e pode gerenciar suas permissões).
- **Exemplos Permitidos**:
  - Usuário `Carlos` com permissão `admin` concedida no Grupo *"Gestores RH"* na Categoria *"Recursos Humanos"* acessa a aba `Permissões` da Subcategoria *"Férias"* e adiciona o Grupo *"Estagiários"* com nível `View`.
- **Exemplos Bloqueados**:
  - Usuário `Ana` com permissão `edit` na Categoria *"Recursos Humanos"* tenta acessar a aba `Permissões`. **[BLOQUEADO: Nível Edit permite criar/editar conteúdos, mas NÃO autoriza gestão de permissões da pasta]**.

---

## 4. Resumo de Princípios Invioláveis

1. **Separação Rígida entre Admin Geral e Admin de Recurso**:
   - `users.role = 'admin'` é o **único** papel de administração global.
   - `permission_level = 'admin'` concede privilégios de gestão **estritamente limitados à pasta específica e seus descendentes**.
2. **Modelo Inclusivo MAX()**:
   - Sem regras de negação (`DENY`). A maior concessão ativa (`view = 1`, `edit = 2`, `admin = 3`) prevalece.
3. **Validação Obrigatória no Backend**:
   - Esconder um botão no frontend é um recurso puramente estético. Todos os endpoints (`POST` e `GET`) validam autorizações no servidor via `PermissionService.php`.
