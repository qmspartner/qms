-- Tilføjer en jobtitel til systembrugere, så organisationsdiagrammet (Indstillinger →
-- Organisation) kan vise mere end navn og systemrolle (admin/redaktør/godkender/bruger/GQAR
-- er en rettighed, ikke en jobtitel). Nullable, fritekst — samme mønster som employees.title.
ALTER TABLE users ADD COLUMN title VARCHAR(255) DEFAULT NULL AFTER name;

INSERT INTO schema_migrations (filename) VALUES ('015_organisation_titel.sql');
