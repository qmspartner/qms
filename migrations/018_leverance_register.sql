-- Generelt leverance-register (konklusion fra AQAP/ACMP-2100-diskussionen, punkt 1 — se
-- Claudes hukommelsesfil om projektet): samme "leverance → gældende dokumentrevision"-mønster
-- som software_releases/fai_records, men for leverandører der hverken leverer software
-- (AQAP 2210) eller udfører FAI. Eget organisationsflag (delivery_tracking_enabled), samme
-- mønster som aqap2210_enabled/fai_enabled — tre uafhængige kontrakt-register-funktioner, en
-- organisation kan have alle, nogle eller ingen af dem slået til.
ALTER TABLE organizations
    ADD COLUMN delivery_tracking_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER fai_enabled;

-- `linked_version_id` peger bevidst på den SPECIFIKKE document_versions-række, der var
-- dokumentets gældende godkendte version (documents.current_version_id) på det tidspunkt,
-- leverancen blev registreret — ikke på selve dokumentet (modsat software_releases/
-- fai_records, der ikke sporer en version). Det sikrer, at leverancesporet forbliver
-- historisk korrekt, selvom dokumentet senere får en ny godkendt version.
CREATE TABLE `deliveries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `contract_reference` varchar(190) NOT NULL,
  `reference` varchar(100) NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `linked_version_id` int DEFAULT NULL,
  `notes` text,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `linked_version_id` (`linked_version_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `deliveries_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `deliveries_ibfk_2` FOREIGN KEY (`linked_version_id`) REFERENCES `document_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deliveries_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (filename) VALUES ('018_leverance_register.sql');
