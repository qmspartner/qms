-- Migration: Kvittering for læsning af dokumenter
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

ALTER TABLE `documents`
  ADD COLUMN `requires_acknowledgment` tinyint(1) NOT NULL DEFAULT '0' AFTER `is_template`;

-- Kvittering for læsning af et dokument, der kræver det. Subjektet er enten en
-- systembruger (user_id, selv-kvitteret) eller en medarbejder uden login
-- (employee_id, kvitteret af nærmeste leder på vedkommendes vegne - fx ved et
-- tavlemøde). Præcis ét af de to skal være sat. acknowledged_by er altid den
-- systembruger, der rent faktisk udførte handlingen.
CREATE TABLE `document_acknowledgments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `document_id` int NOT NULL,
  `version_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `employee_id` int DEFAULT NULL,
  `acknowledged_by` int NOT NULL,
  `method` enum('self','manager_proxy') NOT NULL,
  `context_note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_version_user` (`version_id`,`user_id`),
  UNIQUE KEY `uniq_version_employee` (`version_id`,`employee_id`),
  KEY `organization_id` (`organization_id`),
  KEY `document_id` (`document_id`),
  KEY `acknowledged_by` (`acknowledged_by`),
  CONSTRAINT `fk_docack_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `fk_docack_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_docack_version` FOREIGN KEY (`version_id`) REFERENCES `document_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_docack_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_docack_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_docack_by` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_docack_subject` CHECK (((`user_id` is not null) and (`employee_id` is null)) or ((`user_id` is null) and (`employee_id` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
