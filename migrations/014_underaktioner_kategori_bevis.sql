-- Migration: Underaktioner erstatter fritekstfelterne Korrigerende/Forebyggende handling
-- i selve redigeringsformularen (cases.corrective_action/preventive_action bevares
-- uændret i databasen, de bruges blot ikke længere). Hver underaktion hører nu til én af
-- to kategorier, og kan have et vedhæftet bevis for udførelsen. For sagstyper der kræver
-- lukningsdokumentation (Afvigelse, Audit fund, Kundeklage, Leverandør reklamation) kan en
-- underaktion kun afsluttes med bevis, eller markeres 'N/A' med en påkrævet, logget
-- begrundelse.
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

ALTER TABLE `action_items`
  ADD COLUMN `category` ENUM('Korrigerende','Forebyggende') NOT NULL DEFAULT 'Korrigerende' AFTER `case_id`,
  MODIFY COLUMN `status` ENUM('Åben','Afsluttet','N/A') NOT NULL DEFAULT 'Åben',
  ADD COLUMN `na_reason` TEXT DEFAULT NULL AFTER `note`,
  ADD COLUMN `na_logged_by` INT DEFAULT NULL AFTER `na_reason`,
  ADD COLUMN `na_logged_at` DATETIME DEFAULT NULL AFTER `na_logged_by`,
  ADD KEY `na_logged_by` (`na_logged_by`),
  ADD CONSTRAINT `fk_actionitems_na_logged_by` FOREIGN KEY (`na_logged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

CREATE TABLE `action_item_evidence` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `action_item_id` int NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `uploaded_by` int NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `action_item_id` (`action_item_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_actionitemevidence_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `fk_actionitemevidence_item` FOREIGN KEY (`action_item_id`) REFERENCES `action_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_actionitemevidence_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (filename) VALUES ('014_underaktioner_kategori_bevis.sql');
