-- Migration: Tilføj "nærmeste leder" til medarbejdere (Fase 3 — Medarbejdere)
-- Bruges til at vise udløbsalarmer for en medarbejders kvalifikationer
-- på lederens egen "Mine opgaver"-liste.
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

ALTER TABLE `employees`
  ADD COLUMN `manager_user_id` int DEFAULT NULL AFTER `department`,
  ADD KEY `manager_user_id` (`manager_user_id`),
  ADD CONSTRAINT `fk_employees_manager` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
