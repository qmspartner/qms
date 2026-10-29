-- QMS Dokumentstyring - databaseskema
-- Importeres via phpMyAdmin (eller mysql-kommandolinje) i den database, dit webhotel har oprettet.
--
-- Denne fil er fra 2026-10-10 et verificeret aftryk af den faktiske live-database
-- (eksporteret som struktur-uden-data fra phpMyAdmin), ikke længere en rekonstruktion
-- ud fra hukommelse. Fremover: hold den ved like ved at tage en ny struktur-eksport,
-- når der er lavet skemaændringer, i stedet for at tilføje ALTER-blokke her for hånden.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------

CREATE TABLE `organizations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_format` varchar(10) NOT NULL DEFAULT 'd-m-Y',
  `aqap2210_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `fai_enabled` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','editor','approver','user','gqar') NOT NULL DEFAULT 'user',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `deactivation_requested_at` datetime DEFAULT NULL,
  `deactivation_requested_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `language` varchar(5) NOT NULL DEFAULT 'da',
  `gqar_contract_reference` varchar(255) DEFAULT NULL,
  `reset_token_hash` varchar(64) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `manager_user_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_org` (`organization_id`),
  KEY `manager_user_id` (`manager_user_id`),
  CONSTRAINT `fk_users_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `fk_users_deactivation_by` FOREIGN KEY (`deactivation_requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_users_manager` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_lookup` (`email`,`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `role_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `role` varchar(20) NOT NULL,
  `module` varchar(50) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT '0',
  `can_create` tinyint(1) NOT NULL DEFAULT '0',
  `can_edit` tinyint(1) NOT NULL DEFAULT '0',
  `can_delete` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_org_role_module` (`organization_id`,`role`,`module`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `impersonation_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `admin_user_id` int NOT NULL,
  `target_user_id` int NOT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `admin_user_id` (`admin_user_id`),
  KEY `target_user_id` (`target_user_id`),
  CONSTRAINT `impersonation_log_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `impersonation_log_ibfk_2` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `impersonation_log_ibfk_3` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `list_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `list_type` varchar(50) NOT NULL,
  `label_da` varchar(190) NOT NULL,
  `label_en` varchar(190) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `prefix` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `list_items_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `customer_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(150) NOT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `customer_id` (`customer_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `customer_log_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `customer_log_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'Andet',
  `criticality` enum('Kritisk','Ikke-kritisk') NOT NULL DEFAULT 'Ikke-kritisk',
  `status` enum('Godkendt','Under vurdering','Afvist','Udfaset') NOT NULL DEFAULT 'Under vurdering',
  `owner_id` int NOT NULL,
  `evaluation_interval_months` int DEFAULT NULL,
  `last_evaluation_date` date DEFAULT NULL,
  `next_evaluation_date` date DEFAULT NULL,
  `quality_requirements_passed_on` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `owner_id` (`owner_id`),
  CONSTRAINT `suppliers_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `suppliers_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `supplier_contracts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `contract_reference` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_supplier_contract` (`supplier_id`,`contract_reference`),
  CONSTRAINT `supplier_contracts_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `supplier_evaluations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `evaluation_date` date NOT NULL,
  `result` text,
  `stored_filename` varchar(255) DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `performed_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `supplier_evaluations_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_evaluations_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `supplier_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `supplier_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(150) NOT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `supplier_log_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `supplier_log_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `doc_number` varchar(30) NOT NULL,
  `title` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `is_template` tinyint(1) NOT NULL DEFAULT '0',
  `requires_acknowledgment` tinyint(1) NOT NULL DEFAULT '0',
  `iso_clause` varchar(60) DEFAULT NULL,
  `owner_id` int NOT NULL,
  `status` enum('Kladde','Til godkendelse','Godkendt','Udgået') NOT NULL DEFAULT 'Kladde',
  `current_version_id` int DEFAULT NULL,
  `next_review_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_configuration_item` tinyint(1) NOT NULL DEFAULT '0',
  `baseline_reference` varchar(255) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `operation` varchar(50) DEFAULT NULL,
  `is_imported` tinyint(1) NOT NULL DEFAULT '0',
  `original_issue_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doc_number` (`doc_number`),
  KEY `owner_id` (`owner_id`),
  KEY `fk_documents_org` (`organization_id`),
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_documents_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `document_versions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NOT NULL,
  `version_number` varchar(10) NOT NULL,
  `stored_filename` varchar(255) DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `uploaded_by` int NOT NULL,
  `assigned_approver_id` int DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('Kladde','Til godkendelse','Godkendt','Afvist','Arkiveret') NOT NULL DEFAULT 'Til godkendelse',
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `review_comment` text,
  `comment` text,
  `original_approver` varchar(255) DEFAULT NULL,
  `original_approval_date` date DEFAULT NULL,
  `import_source_reference` varchar(255) DEFAULT NULL,
  `revision_type` enum('major','minor') DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_id` (`document_id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `approved_by` (`approved_by`),
  KEY `fk_version_approver` (`assigned_approver_id`),
  CONSTRAINT `document_versions_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_versions_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `document_versions_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_version_approver` FOREIGN KEY (`assigned_approver_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  CONSTRAINT `fk_docack_by` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `document_id` int NOT NULL,
  `version_id` int DEFAULT NULL,
  `user_id` int NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `document_id` (`document_id`),
  KEY `user_id` (`user_id`),
  KEY `fk_audit_org` (`organization_id`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `audit_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_audit_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `quality_plan_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `version_id` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `scope` text,
  `organization_responsibility` text,
  `applicable_standards` text,
  `configuration_management_approach` text,
  `risk_management_approach` text,
  `supplier_management_approach` text,
  `inspection_test_plan` text,
  `gqar_involvement` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `version_id` (`version_id`),
  KEY `customer_id` (`customer_id`),
  CONSTRAINT `quality_plan_details_ibfk_1` FOREIGN KEY (`version_id`) REFERENCES `document_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quality_plan_details_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` varchar(100) NOT NULL,
  `owner_id` int NOT NULL,
  `current_version_id` int DEFAULT NULL,
  `next_update_date` date DEFAULT NULL,
  `retention_years` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `owner_id` (`owner_id`),
  CONSTRAINT `records_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `records_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `record_versions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `record_id` int NOT NULL,
  `version_number` varchar(10) NOT NULL,
  `stored_filename` varchar(255) DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `uploaded_by` int NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `comment` text,
  PRIMARY KEY (`id`),
  KEY `record_id` (`record_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `record_versions_ibfk_1` FOREIGN KEY (`record_id`) REFERENCES `records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `record_versions_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `record_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `record_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(150) NOT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `record_id` (`record_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `record_log_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `record_log_ibfk_2` FOREIGN KEY (`record_id`) REFERENCES `records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `record_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `management_review_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `version_id` int NOT NULL,
  `review_date` date NOT NULL,
  `previous_review_date` date DEFAULT NULL,
  `participants` text,
  `followup_previous_actions` text,
  `external_internal_changes` text,
  `customer_satisfaction` text,
  `quality_objectives` text,
  `deviations_system_value` varchar(255) DEFAULT NULL,
  `deviations_system_count` int DEFAULT NULL,
  `deviations_manual_count` int DEFAULT NULL,
  `deviations_note` text,
  `audits_system_value` varchar(255) DEFAULT NULL,
  `audits_system_count` int DEFAULT NULL,
  `audits_manual_count` int DEFAULT NULL,
  `audits_note` text,
  `suppliers_system_value` varchar(255) DEFAULT NULL,
  `suppliers_system_count` int DEFAULT NULL,
  `suppliers_manual_count` int DEFAULT NULL,
  `suppliers_note` text,
  `risks_system_value` varchar(255) DEFAULT NULL,
  `risks_system_count` int DEFAULT NULL,
  `risks_manual_count` int DEFAULT NULL,
  `risks_note` text,
  `resource_adequacy` text,
  `improvement_opportunities` text,
  `decisions_improvements` text,
  `decisions_system_changes` text,
  `decisions_resources` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `version_id` (`version_id`),
  CONSTRAINT `management_review_details_ibfk_1` FOREIGN KEY (`version_id`) REFERENCES `record_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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

-- --------------------------------------------------------

CREATE TABLE `audits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `auditor_id` int NOT NULL,
  `planned_date` date NOT NULL,
  `completed_date` date DEFAULT NULL,
  `status` enum('Planlagt','Gennemført','Aflyst') NOT NULL DEFAULT 'Planlagt',
  `conclusion` text,
  `linked_document_id` int DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `audit_type` enum('Internt','Eksternt') NOT NULL DEFAULT 'Internt',
  `contract_reference` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `auditor_id` (`auditor_id`),
  KEY `linked_document_id` (`linked_document_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `audits_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `audits_ibfk_2` FOREIGN KEY (`auditor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `audits_ibfk_3` FOREIGN KEY (`linked_document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `audits_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `audit_trail_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `audit_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(150) NOT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `audit_id` (`audit_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `audit_trail_log_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `audit_trail_log_ibfk_2` FOREIGN KEY (`audit_id`) REFERENCES `audits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `audit_trail_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `cases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` varchar(50) NOT NULL,
  `severity` enum('Høj','Mellem','Lav') DEFAULT NULL,
  `description` text NOT NULL,
  `root_cause` text,
  `corrective_action` text,
  `preventive_action` text,
  `status` enum('Registreret','Under undersøgelse','Handling igangsat','Lukket') NOT NULL DEFAULT 'Registreret',
  `reported_by` int NOT NULL,
  `assigned_to` int DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `linked_document_id` int DEFAULT NULL,
  `source_record_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `audit_id` int DEFAULT NULL,
  `requires_customer_notification` tinyint(1) NOT NULL DEFAULT '0',
  `immediate_action` text,
  `supplier_id` int DEFAULT NULL,
  `contract_reference` varchar(255) DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `customer_response_date` date DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `effectiveness_review_due` date DEFAULT NULL,
  `effectiveness_result` enum('Effektiv','Ikke effektiv') DEFAULT NULL,
  `effectiveness_reviewed_at` date DEFAULT NULL,
  `effectiveness_notes` text,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `reported_by` (`reported_by`),
  KEY `assigned_to` (`assigned_to`),
  KEY `linked_document_id` (`linked_document_id`),
  KEY `fk_cases_audit` (`audit_id`),
  KEY `fk_cases_supplier` (`supplier_id`),
  KEY `fk_cases_customer` (`customer_id`),
  KEY `fk_cases_source_record` (`source_record_id`),
  CONSTRAINT `cases_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `cases_ibfk_2` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`),
  CONSTRAINT `cases_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  CONSTRAINT `cases_ibfk_4` FOREIGN KEY (`linked_document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `fk_cases_audit` FOREIGN KEY (`audit_id`) REFERENCES `audits` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cases_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cases_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cases_source_record` FOREIGN KEY (`source_record_id`) REFERENCES `records` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `case_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `case_id` int NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `uploaded_by` int NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `case_id` (`case_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `case_attachments_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `case_attachments_ibfk_2` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `case_attachments_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `case_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `case_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(150) NOT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `case_id` (`case_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `case_log_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `case_log_ibfk_2` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `case_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `risks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `category` varchar(100) NOT NULL DEFAULT 'Andet',
  `owner_id` int NOT NULL,
  `probability` tinyint NOT NULL,
  `consequence` tinyint NOT NULL,
  `treatment_plan` text,
  `residual_probability` tinyint DEFAULT NULL,
  `residual_consequence` tinyint DEFAULT NULL,
  `status` enum('Identificeret','Under behandling','Lukket') NOT NULL DEFAULT 'Identificeret',
  `next_review_date` date DEFAULT NULL,
  `linked_document_id` int DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `contract_reference` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `owner_id` (`owner_id`),
  KEY `linked_document_id` (`linked_document_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `risks_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `risks_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  CONSTRAINT `risks_ibfk_3` FOREIGN KEY (`linked_document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `risks_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `risk_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `risk_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(150) NOT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `risk_id` (`risk_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `risk_log_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `risk_log_ibfk_2` FOREIGN KEY (`risk_id`) REFERENCES `risks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `risk_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `equipment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `responsible_id` int NOT NULL,
  `criticality` enum('Kritisk','Ikke-kritisk') NOT NULL DEFAULT 'Ikke-kritisk',
  `requires_calibration` tinyint(1) NOT NULL DEFAULT '1',
  `calibration_interval_months` int DEFAULT NULL,
  `last_calibration_date` date DEFAULT NULL,
  `next_calibration_date` date DEFAULT NULL,
  `status` enum('Aktiv','Ikke aktiv') NOT NULL DEFAULT 'Aktiv',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `responsible_id` (`responsible_id`),
  CONSTRAINT `equipment_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `equipment_ibfk_2` FOREIGN KEY (`responsible_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `equipment_calibrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `calibration_date` date NOT NULL,
  `result` text,
  `stored_filename` varchar(255) DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `performed_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `traceability` varchar(255) DEFAULT NULL,
  `measurement_uncertainty` varchar(100) DEFAULT NULL,
  `outcome` enum('Godkendt','Ikke godkendt','Justeret') NOT NULL DEFAULT 'Godkendt',
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `equipment_calibrations_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
  CONSTRAINT `equipment_calibrations_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `equipment_contracts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `contract_reference` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_equipment_contract` (`equipment_id`,`contract_reference`),
  CONSTRAINT `equipment_contracts_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `equipment_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `equipment_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(150) NOT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `equipment_log_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `equipment_log_ibfk_2` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
  CONSTRAINT `equipment_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

CREATE TABLE `gqar_contracts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `contract_reference` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_contract` (`user_id`,`contract_reference`),
  CONSTRAINT `gqar_contracts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- AQAP 2210: software-leverancer på Kontraktoversigten (styret af organizations.aqap2210_enabled)

CREATE TABLE `software_releases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `contract_reference` varchar(190) NOT NULL,
  `build_version` varchar(60) NOT NULL,
  `external_reference` varchar(500) DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `linked_document_id` int DEFAULT NULL,
  `notes` text,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `linked_document_id` (`linked_document_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `software_releases_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `software_releases_ibfk_2` FOREIGN KEY (`linked_document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `software_releases_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Kompetence- og uddannelsesmodul: medarbejdere (uafhængigt af login), kvalifikationer
-- (defineret af admin) og krydset mellem dem (kompetencematricen). En medarbejder kan
-- højst have én status-række pr. kvalifikation (se UNIQUE KEY på employee_qualifications).

CREATE TABLE `employees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `manager_user_id` int DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `deactivation_requested_at` datetime DEFAULT NULL,
  `deactivation_requested_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `manager_user_id` (`manager_user_id`),
  CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `fk_employees_manager` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_employees_deactivation_by` FOREIGN KEY (`deactivation_requested_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `qualifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `validity_months` int DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `qualifications_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `employee_qualifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `qualification_id` int NOT NULL,
  `planned_date` date DEFAULT NULL,
  `obtained_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `stored_filename` varchar(255) DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `notes` text,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_qualification` (`employee_id`,`qualification_id`),
  KEY `organization_id` (`organization_id`),
  KEY `qualification_id` (`qualification_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `employee_qualifications_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `employee_qualifications_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_qualifications_ibfk_3` FOREIGN KEY (`qualification_id`) REFERENCES `qualifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_qualifications_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `employee_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(150) NOT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `employee_id` (`employee_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `employee_log_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `employee_log_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `offboarding_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `subject_type` enum('employee','user') NOT NULL,
  `subject_id` int NOT NULL,
  `item_type` varchar(50) NOT NULL,
  `item_id` int NOT NULL,
  `item_label` varchar(255) NOT NULL,
  `action` enum('requested','cancelled','closed_not_relevant','reassigned','taken_over','deactivated','reactivated') NOT NULL,
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

CREATE TABLE `user_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `target_user_id` int NOT NULL,
  `action` varchar(150) NOT NULL,
  `details` text,
  `performed_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `target_user_id` (`target_user_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `fk_userlog_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `fk_userlog_target` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_userlog_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `target_user_id` int NOT NULL,
  `label` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `uploaded_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `target_user_id` (`target_user_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_userattach_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `fk_userattach_target` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_userattach_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `employee_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `target_employee_id` int NOT NULL,
  `label` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `uploaded_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `target_employee_id` (`target_employee_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_employeeattach_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `fk_employeeattach_target` FOREIGN KEY (`target_employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_employeeattach_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- First Article Inspection (FAI), styret af organizations.fai_enabled, uafhængig af AQAP 2210.
-- Kun én aktiv FAI pr. delnummer pr. kontrakt - en ny upload afløser den forrige (se uniq_fai_part_per_contract).

CREATE TABLE `fai_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `organization_id` int NOT NULL,
  `contract_reference` varchar(190) NOT NULL,
  `part_number` varchar(100) NOT NULL,
  `drawing_revision` varchar(30) DEFAULT NULL,
  `result` enum('Godkendt','Afvist','Under behandling') NOT NULL DEFAULT 'Under behandling',
  `inspection_date` date DEFAULT NULL,
  `stored_filename` varchar(255) DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `notes` text,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fai_part_per_contract` (`organization_id`,`contract_reference`,`part_number`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fai_records_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  CONSTRAINT `fai_records_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

COMMIT;

-- Ingen organisation eller bruger oprettes her.
-- Åbn setup.php i browseren efter import for at oprette den første organisation og administrator-konto.
