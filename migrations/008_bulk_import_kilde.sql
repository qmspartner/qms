-- Migration: Kilde/reference-felt på dokumentversioner (til Model A bulk-import)
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

ALTER TABLE `document_versions`
  ADD COLUMN `import_source_reference` varchar(255) DEFAULT NULL AFTER `original_approval_date`;
