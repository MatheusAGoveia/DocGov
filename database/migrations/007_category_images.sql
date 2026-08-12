-- Imagem opcional exibida no lugar do ícone padrão da categoria.
ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS image_path TEXT NULL;
