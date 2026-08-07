# 🐘 Modelagem de Banco de Dados PostgreSQL — DocGov

Este diretório contém a modelagem física, scripts DDL e dados de teste (seed) para o banco de dados oficial do sistema **DocGov**.

---

## 📁 Estrutura de Arquivos

```text
database/
├── schema.sql      # Script DDL com a criação de tabelas, chaves, constraints, índices e triggers
├── seed.sql        # Script DML com a carga inicial de desenvolvimento
└── README.md       # Documentação da arquitetura e guia de execução
```

---

## 🚀 Como Executar no PostgreSQL

### 1. Criar o Banco de Dados (caso ainda não exista)
```sql
CREATE DATABASE docsec;
```

### 2. Executar a Estrutura (Schema)
No terminal da sua máquina (via `psql`):
```bash
psql -U postgres -d docsec -f database/schema.sql
```

### 3. Executar o Seed Inicial
```bash
psql -U postgres -d docsec -f database/seed.sql
```

---

## 🧱 Arquitetura Relacional (Fonte Única de Verdade)

```text
users (1) ────< favorites >──── (N) documents
                                       │
categories (1) ──< subcategories (1) ──< subjects (1) ──< documents (N)
```

1. **`users`**: Armazena usuários do sistema (`admin`, `editor`, `reader`). `password_hash` é opcional para acomodar futura autenticação via LDAP/Active Directory.
2. **`categories`**: Categorias de topo (ex: Recursos Humanos). Ordenação alfabética automática por nome (`ORDER BY name ASC`).
3. **`subcategories`**: Subcategorias vinculadas obrigatoriamente a uma Categoria. Unicidade composta `(category_id, slug)`.
4. **`subjects`**: Assuntos vinculados obrigatoriamente a uma Subcategoria. Unicidade composta `(subcategory_id, slug)`.
5. **`documents`**: Central de conteúdos. Suporta tipos `'file'`, `'text'` e `'link'`, status (`'draft'`, `'published'`, `'inactive'`), e caminhos de armazenamento físico no sistema de arquivos local (`storage/documents/`).
6. **`favorites`**: Relacionamento N:N entre Usuários e Documentos favoritados. Unicidade por `(user_id, document_id)`.
