-- Migration: Fjern koblingen mellem sager og kompetence/kvalifikation igen
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

ALTER TABLE `cases`
  DROP FOREIGN KEY `fk_cases_employee_qualification`,
  DROP KEY `fk_cases_employee_qualification`,
  DROP COLUMN `employee_qualification_id`;
