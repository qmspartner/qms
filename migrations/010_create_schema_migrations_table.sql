-- Opretter en tabel, der holder styr på hvilke migrationsfiler der allerede er
-- kørt på denne database, og registrerer migration 001-009 som allerede anvendt
-- (de er kørt manuelt tidligere, før denne tabel fandtes). Se migrations/README.md
-- for den fremadrettede konvention.

CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO schema_migrations (filename) VALUES
    ('001_effektivitetstjek.sql'),
    ('002_forebyggende_handling.sql'),
    ('003_fjern_kompetencekobling.sql'),
    ('004_skabelonmarkering.sql'),
    ('005_mode_referat_struktur.sql'),
    ('006_medarbejder_leder.sql'),
    ('007_dokument_kvittering.sql'),
    ('008_bulk_import_kilde.sql'),
    ('009_fratraedelse.sql')
ON DUPLICATE KEY UPDATE filename = filename;
