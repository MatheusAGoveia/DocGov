-- Imagem opcional exibida no card da subcategoria.
ALTER TABLE subcategories
    ADD COLUMN IF NOT EXISTS image_path TEXT NULL;
