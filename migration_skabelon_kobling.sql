-- Tilføjer mulighed for at knytte en almindelig dokumentkategori (fx "Procedure")
-- til dens tilhørende skabelonkategori (fx "Skabelon - Procedure").
-- BEMÆRK: tag en backup i phpMyAdmin (Export) før du kører denne, som altid ved
-- ændringer af databasestrukturen.

ALTER TABLE list_items
  ADD COLUMN template_category_id INT DEFAULT NULL AFTER prefix,
  ADD KEY fk_list_items_template_category (template_category_id),
  ADD CONSTRAINT fk_list_items_template_category
    FOREIGN KEY (template_category_id) REFERENCES list_items (id);
