-- Dokument-reservation (konklusion fra AQAP/ACMP-2100-diskussionens punkt 2 — change control
-- — se Claudes hukommelsesfil om projektet): forhindrer at to personer uafhængigt af hinanden
-- begynder at opdatere samme dokument. Et nyt, selvstændigt felt-par, adskilt fra
-- documents.status, så den eksisterende statussynlighedsregel for almindelige brugere
-- (document_is_visible()/can_view_all_document_statuses()) slet ikke berøres - en reservation
-- er ikke en statusændring. Gælder ALLE dokumenter, ikke kun konfigurationsstyrede.
ALTER TABLE documents
    ADD COLUMN checked_out_by INT DEFAULT NULL AFTER owner_id,
    ADD COLUMN checked_out_at DATETIME DEFAULT NULL AFTER checked_out_by,
    ADD KEY `checked_out_by` (`checked_out_by`),
    ADD CONSTRAINT `fk_documents_checked_out_by` FOREIGN KEY (`checked_out_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

INSERT INTO schema_migrations (filename) VALUES ('019_dokument_reservation.sql');
