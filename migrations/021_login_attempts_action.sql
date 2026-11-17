-- Arkitektur-review 2026-11-17, punkt 5 (del 2): forgot_password.php havde ingen
-- hastighedsbegrænsning, i modsætning til login-flowet. I stedet for en ny tabel
-- genbruges login_attempts' eksisterende mekanisme (samme tælle-/låse-logik,
-- samme housekeeping) til også at dække adgangskode-nulstillingsanmodninger, skelnet
-- via en ny 'action'-kolonne. Eksisterende rækker (alle login-forsøg indtil nu)
-- får bevidst DEFAULT 'login', så de fortsætter uændret.

ALTER TABLE login_attempts
    ADD COLUMN action VARCHAR(20) NOT NULL DEFAULT 'login' AFTER ip_address;

DROP INDEX idx_login_attempts_lookup ON login_attempts;
ALTER TABLE login_attempts
    ADD KEY `idx_login_attempts_lookup` (`email`, `ip_address`, `action`, `attempted_at`);

INSERT INTO schema_migrations (filename) VALUES ('021_login_attempts_action.sql');
