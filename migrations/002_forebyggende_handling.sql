-- Migration: Forebyggende handling (preventive action) på sager
-- KØR BACKUP AF DATABASEN FØRST, før denne SQL køres i phpMyAdmin.

ALTER TABLE `cases`
  ADD COLUMN `preventive_action` text AFTER `corrective_action`;
