-- Migration: Effektivitetstjek af korrigerende handlinger
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

ALTER TABLE `cases`
  ADD COLUMN `effectiveness_review_due` date DEFAULT NULL AFTER `employee_qualification_id`,
  ADD COLUMN `effectiveness_result` enum('Effektiv','Ikke effektiv') DEFAULT NULL AFTER `effectiveness_review_due`,
  ADD COLUMN `effectiveness_reviewed_at` date DEFAULT NULL AFTER `effectiveness_result`,
  ADD COLUMN `effectiveness_notes` text AFTER `effectiveness_reviewed_at`;
