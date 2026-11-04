-- Migration: Kvalitetsmål-modul (ISO 9001 §6.2 kvalitetsmål / §9.1.2 kundetilfredshed)
-- + ny rolle "Ledelse", som er den eneste rolle (udover Administrator) der kan se og
-- redigere kvalitetsmål.
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

ALTER TABLE `users`
  MODIFY COLUMN `role` enum('admin','editor','approver','user','gqar','ledelse') NOT NULL DEFAULT 'user';

CREATE TABLE `quality_objectives` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `owner_user_id` int NOT NULL,
  `target_direction` enum('<=','>=') NOT NULL DEFAULT '<=',
  `target_value` decimal(10,2) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `measurement_frequency` enum('Ugentligt','Månedligt','Kvartalsvist') NOT NULL DEFAULT 'Kvartalsvist',
  `reminder_lead_days` int NOT NULL DEFAULT '7',
  `start_date` date NOT NULL,
  `target_date` date DEFAULT NULL,
  `status` enum('Ny','På kurs','Ikke på kurs','Opnået','Opgivet') NOT NULL DEFAULT 'Ny',
  `last_reminder_sent_period_end` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `owner_user_id` (`owner_user_id`),
  CONSTRAINT `quality_objectives_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `quality_objectives_ibfk_2` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `quality_objective_measurements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `objective_id` int NOT NULL,
  `period_end` date NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `recorded_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_objective_period` (`objective_id`,`period_end`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `quality_objective_measurements_ibfk_1` FOREIGN KEY (`objective_id`) REFERENCES `quality_objectives` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quality_objective_measurements_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Rollen "ledelse" findes ikke i role_permissions for nogen eksisterende organisation
-- endnu (den er helt ny), så den seedes her for alle moduler — med samme standardregler
-- som seed_default_permissions() i includes/functions.php bruger for nye organisationer:
-- fuld adgang (dog ikke slet) til quality_objectives, kun visning til de almindelige
-- moduler (bortset fra 'cases', hvor opret altid er åben for alle roller), og slet ingen
-- adgang til de to administrator-only moduler. Kan finjusteres bagefter under
-- Indstillinger → Adgangsstyring.
INSERT INTO role_permissions (organization_id, role, module, can_view, can_create, can_edit, can_delete)
SELECT id, 'ledelse', 'quality_objectives', 1, 1, 1, 0 FROM organizations;

INSERT INTO role_permissions (organization_id, role, module, can_view, can_create, can_edit, can_delete)
SELECT id, 'ledelse', m.module, 1, (m.module = 'cases'), 0, 0
FROM organizations
CROSS JOIN (
    SELECT 'documents' AS module UNION ALL SELECT 'cases' UNION ALL SELECT 'audits' UNION ALL
    SELECT 'records' UNION ALL SELECT 'equipment' UNION ALL SELECT 'risks' UNION ALL
    SELECT 'suppliers' UNION ALL SELECT 'customers' UNION ALL SELECT 'templates'
) AS m;

INSERT INTO role_permissions (organization_id, role, module, can_view, can_create, can_edit, can_delete)
SELECT id, 'ledelse', m.module, 0, 0, 0, 0
FROM organizations
CROSS JOIN (SELECT 'users' AS module UNION ALL SELECT 'lists') AS m;

INSERT INTO schema_migrations (filename) VALUES ('011_kvalitetsmaal.sql');
