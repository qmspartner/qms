-- Udvider Leverance-kvalitetsplanens konfigurationsstyrings-afsnit fra ét frit tekstfelt
-- til tre strukturerede felter, efter konklusionen i AQAP/ACMP-2100-diskussionen (se Claudes
-- hukommelsesfil om projektet): en formel, selvstændig "Configuration Management Plan"-
-- dokumenttype er vurderet som overkill for målgruppen (små producenter/dual-use med simple
-- produkter) — i stedet struktureres det eksisterende fritekstfelt.
--
-- Det gamle `configuration_management_approach`-felt bevares uændret (droppes ikke) for ikke
-- at miste data på allerede oprettede kvalitetsplaner. Nye kvalitetsplaner udfylder det aldrig
-- længere — kun de tre nye felter — men visningssiden viser stadig det gamle felt, hvis en
-- ældre plan allerede har data i det.
ALTER TABLE quality_plan_details
    ADD COLUMN cm_scope TEXT DEFAULT NULL AFTER configuration_management_approach,
    ADD COLUMN cm_baseline_approach TEXT DEFAULT NULL AFTER cm_scope,
    ADD COLUMN cm_change_process TEXT DEFAULT NULL AFTER cm_baseline_approach;

INSERT INTO schema_migrations (filename) VALUES ('017_kvalitetsplan_konfigurationsstyring_struktur.sql');
