-- Udvider risikomodulet til også at dække muligheder (ISO 9001 §6.1 kræver begge).
-- Samme tabel/register som risici, ikke et nyt modul - se CLAUDE.md. type styrer om en
-- række er en risiko eller en mulighed; eksisterende rækker bliver automatisk 'Risiko' via
-- DEFAULT, så intet eksisterende data eller nogen eksisterende adfærd ændres.
ALTER TABLE risks ADD COLUMN type ENUM('Risiko','Mulighed') NOT NULL DEFAULT 'Risiko' AFTER organization_id;

-- Muligheder bruger egne kategorier (adskilt fra risk_category), samme list_items-mekanisme
-- som alle andre lister - redigerbar under Indstillinger → Lister uden kodeændring.
INSERT INTO list_items (organization_id, list_type, label_da, label_en, sort_order)
SELECT o.id, 'opportunity_category', v.label_da, v.label_en, v.sort_order
FROM organizations o
CROSS JOIN (
    SELECT 'Nyt marked' AS label_da, 'New market' AS label_en, 1 AS sort_order
    UNION ALL SELECT 'Procesforbedring', 'Process improvement', 2
    UNION ALL SELECT 'Partnerskab', 'Partnership', 3
    UNION ALL SELECT 'Produktudvikling', 'Product development', 4
    UNION ALL SELECT 'Andet', 'Other', 5
) v;

INSERT INTO schema_migrations (filename) VALUES ('016_muligheder.sql');
