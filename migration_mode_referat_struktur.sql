-- Migration: Struktureret mødereferat (Optegnelser)
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

CREATE TABLE `meeting_minutes_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `version_id` int NOT NULL,
  `meeting_type` varchar(100) DEFAULT NULL,
  `meeting_date` date NOT NULL,
  `participants` text,
  `absentees` text,
  `next_meeting_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `version_id` (`version_id`),
  CONSTRAINT `meeting_minutes_details_ibfk_1` FOREIGN KEY (`version_id`) REFERENCES `record_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `meeting_minutes_agenda_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `details_id` int NOT NULL,
  `position` int NOT NULL DEFAULT '0',
  `topic` varchar(255) DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`id`),
  KEY `details_id` (`details_id`),
  CONSTRAINT `meeting_minutes_agenda_items_ibfk_1` FOREIGN KEY (`details_id`) REFERENCES `meeting_minutes_details` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Kobling fra en sag (case) til den optegnelse den stammer fra (f.eks. en handling
-- oprettet direkte ud fra et mødereferat). NULL for alle almindelige sager.
ALTER TABLE `cases`
  ADD COLUMN `source_record_id` int DEFAULT NULL AFTER `linked_document_id`,
  ADD KEY `fk_cases_source_record` (`source_record_id`),
  ADD CONSTRAINT `fk_cases_source_record` FOREIGN KEY (`source_record_id`) REFERENCES `records` (`id`) ON DELETE SET NULL;
