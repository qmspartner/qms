-- Erstatter den tidligere "Skabelon - X"-kategorikonvention med et rigtigt felt
-- på dokumentet selv: documents.is_template. En skabelon er fremover et
-- dokument i sin almindelige kategori (fx "Procedure"), markeret som skabelon
-- - ikke et dokument i en kunstig "Skabelon - Procedure"-kategori.
--
-- BEMÆRK: tag en backup i phpMyAdmin (Export) før du kører denne, som altid
-- ved ændringer af databasestrukturen.
--
-- Du behøver IKKE at have kørt den tidligere "migration_skabelon_kobling.sql"
-- (kobling mellem kategori og skabelonkategori) - den idé er droppet igen til
-- fordel for denne enklere løsning. Har du alligevel kørt den tidligere, gør
-- denne migration ikke skade; den rører ikke template_category_id-feltet.

-- 1) Nyt felt på documents: er dette dokument en skabelon?
ALTER TABLE documents
  ADD COLUMN is_template TINYINT(1) NOT NULL DEFAULT 0 AFTER category;

-- 2) Flyt eksisterende skabelondokumenter (dem hvis kategori starter med
--    "Skabelon") over i deres rigtige kategori og markér dem som skabelon.
--    Fx "Skabelon - FAI" -> kategori "FAI", is_template = 1.
UPDATE documents
SET is_template = 1,
    category = TRIM(REPLACE(category, 'Skabelon - ', ''))
WHERE category LIKE 'Skabelon%';

-- 3) Deaktivér de gamle "Skabelon - X"-kategorier under Lister - de bruges
--    ikke længere, da en skabelon nu er en markering på dokumentet, ikke en
--    selvstændig kategori.
UPDATE list_items
SET active = 0
WHERE list_type = 'document_category' AND label_da LIKE 'Skabelon%';

-- Tjek bagefter i phpMyAdmin at trin 2 gav mening for jeres data, fx:
--   SELECT id, title, category, is_template FROM documents WHERE is_template = 1;
-- Hvis en skabelons nye kategori (fx "FAI") ikke allerede findes som en
-- aktiv kategori under Lister, så opret den der, så dokumentet vises korrekt.
