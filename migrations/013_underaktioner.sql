-- Migration: Underaktioner på sager (Fase 1 af "handlingsplaner med underaktioner").
-- Giver en sags korrigerende/forebyggende handling et sporbart lag af delhandlinger
-- (ejer, deadline, status), ved siden af det eksisterende fritekstfelt.
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

CREATE TABLE `action_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `case_id` int DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  `title` varchar(255) NOT NULL,
  `owner_user_id` int DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('Åben','Afsluttet') NOT NULL DEFAULT 'Åben',
  `completed_at` datetime DEFAULT NULL,
  `note` text,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `case_id` (`case_id`),
  KEY `owner_user_id` (`owner_user_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_actionitems_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `fk_actionitems_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_actionitems_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_actionitems_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  -- Forberedt til senere faser (Risici, Ledelsens evaluering): præcis én forælder-kolonne
  -- skal være sat. I Fase 1 er case_id altid den, så constraint'en er reelt "NOT NULL" indtil
  -- en senere migration tilføjer flere nullable forælder-kolonner og udvider dette tjek.
  CONSTRAINT `chk_actionitems_has_parent` CHECK (`case_id` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (filename) VALUES ('013_underaktioner.sql');
