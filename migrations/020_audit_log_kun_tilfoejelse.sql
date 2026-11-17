-- Gør systemets egne aktivitetslogge tamper-evident på databaseniveau (arkitektur-
-- review 2026-11-17, punkt 8): hidtil var "kun INSERT, aldrig UPDATE" udelukkende en
-- konvention i applikationskoden (bekræftet: ingen af disse tabeller rammes nogen steder
-- af en UPDATE-forespørgsel) - intet forhindrede en direkte databaseændring (fejl, bug,
-- eller bevidst manipulation) i at redigere en allerede skrevet log-linje. Disse triggere
-- gør det til en databaseregel i stedet.
--
-- KUN UPDATE blokeres, bevidst IKKE DELETE: alle disse tabeller har en FK med
-- ON DELETE CASCADE til deres "ejer"-tabel (fx audit_log -> documents), og en hel
-- sletning af det overordnede dokument/sag/risiko/udstyr/medarbejder/bruger/audit skal
-- stadig kunne rydde dens egen historik op, som i dag (se delete_document.php m.fl.).
-- At blokere DELETE helt ville ødelægge de eksisterende, bevidste
-- "slet permanent"-funktioner. Det er en separat, større beslutning værd at diskutere for
-- sig (bør en slettet dokuments historik overhovedet forsvinde?), ikke noget der ændres
-- her i samme ombæring.
--
-- impersonation_log er bevidst UDELADT: den har en legitim UPDATE (ended_at sættes af
-- stop_impersonation.php, når en admin afslutter en "se som bruger"-session) - det er ikke
-- et tamper-evident revisionsspor på samme måde, men en almindelig statuskolonne.
-- login_attempts er også udeladt: den er bevidst rullerende driftsdata, der løbende
-- ryddes (se record_failed_login()/clear_login_attempts() i includes/auth.php), ikke et
-- revisionsspor.
--
-- BEMÆRK TIL KØRSEL I PHPMYADMIN: denne fil bruger "DELIMITER $$" til at afgrænse hver
-- CREATE TRIGGER-blok, fordi blokkene selv indeholder semikolon. Hvis phpMyAdmins SQL-fane
-- afviser DELIMITER-linjerne, så brug i stedet feltet "Delimiter" nederst på SQL-fanen,
-- sæt det til $$, og fjern de to "DELIMITER"-linjer herunder før du køre resten.

DELIMITER $$

CREATE TRIGGER trg_audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log er append-only og kan ikke redigeres.';
END$$

CREATE TRIGGER trg_case_log_no_update BEFORE UPDATE ON case_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'case_log er append-only og kan ikke redigeres.';
END$$

CREATE TRIGGER trg_risk_log_no_update BEFORE UPDATE ON risk_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'risk_log er append-only og kan ikke redigeres.';
END$$

CREATE TRIGGER trg_equipment_log_no_update BEFORE UPDATE ON equipment_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'equipment_log er append-only og kan ikke redigeres.';
END$$

CREATE TRIGGER trg_employee_log_no_update BEFORE UPDATE ON employee_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'employee_log er append-only og kan ikke redigeres.';
END$$

CREATE TRIGGER trg_user_log_no_update BEFORE UPDATE ON user_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_log er append-only og kan ikke redigeres.';
END$$

CREATE TRIGGER trg_audit_trail_log_no_update BEFORE UPDATE ON audit_trail_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_trail_log er append-only og kan ikke redigeres.';
END$$

CREATE TRIGGER trg_record_log_no_update BEFORE UPDATE ON record_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'record_log er append-only og kan ikke redigeres.';
END$$

CREATE TRIGGER trg_offboarding_log_no_update BEFORE UPDATE ON offboarding_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'offboarding_log er append-only og kan ikke redigeres.';
END$$

DELIMITER ;

INSERT INTO schema_migrations (filename) VALUES ('020_audit_log_kun_tilfoejelse.sql');
