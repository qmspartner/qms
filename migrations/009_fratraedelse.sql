-- Migration: Fratrædelseshåndtering (offboarding) for medarbejdere og systembrugere
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

ALTER TABLE `employees`
  ADD COLUMN `deactivation_requested_at` datetime DEFAULT NULL AFTER `active`,
  ADD COLUMN `deactivation_requested_by` int DEFAULT NULL AFTER `deactivation_requested_at`,
  ADD CONSTRAINT `fk_employees_deactivation_by` FOREIGN KEY (`deactivation_requested_by`) REFERENCES `users` (`id`);

ALTER TABLE `users`
  ADD COLUMN `deactivation_requested_at` datetime DEFAULT NULL AFTER `active`,
  ADD COLUMN `deactivation_requested_by` int DEFAULT NULL AFTER `deactivation_requested_at`,
  ADD CONSTRAINT `fk_users_deactivation_by` FOREIGN KEY (`deactivation_requested_by`) REFERENCES `users` (`id`);

CREATE TABLE `offboarding_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `subject_type` enum('employee','user') NOT NULL,
  `subject_id` int NOT NULL,
  `item_type` varchar(50) NOT NULL,
  `item_id` int NOT NULL,
  `item_label` varchar(255) NOT NULL,
  `action` enum('requested','cancelled','closed_not_relevant','reassigned','taken_over','deactivated') NOT NULL,
  `new_owner_id` int DEFAULT NULL,
  `note` text,
  `performed_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `subject` (`subject_type`,`subject_id`),
  KEY `new_owner_id` (`new_owner_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `fk_offboardinglog_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `fk_offboardinglog_new_owner` FOREIGN KEY (`new_owner_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_offboardinglog_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
