# Migrationer

Alle skemaændringer udover den oprindelige `schema.sql` ligger her, nummereret
i den orden de skal køres i. Dette er en ren historik/konvention — der er
ingen automatisk "runner"; Lennart kører hver fil manuelt i phpMyAdmin, som
hidtil. SQL vises altid også som ren tekst i selve chat-svaret, når en ny
migration leveres (se CLAUDE.md) — fil-links er ikke altid praktiske.

## Konvention for en ny migration

1. Næste fil hedder `NNN_kort_beskrivelse.sql`, hvor `NNN` er det næste
   tre-cifrede nummer (fortløbende, aldrig genbrugt — se filerne i denne
   mappe for det hidtil højeste nummer).
2. Filen afsluttes altid med en linje der registrerer den som kørt:
   ```sql
   INSERT INTO schema_migrations (filename) VALUES ('NNN_kort_beskrivelse.sql');
   ```
   Det sikrer, at man ved et kig i `schema_migrations`-tabellen altid kan se,
   hvilke migrationer der rent faktisk er kørt på den konkrete database —
   uden at skulle huske det eller gætte fra `documents`/`settings`-indhold.
3. Migrationer 001-009 er de oprindelige, løse `migration_*.sql`-filer fra
   projektets tidlige faser, omdøbt og nummereret efter deres faktiske
   kørselsorden (bestemt fra Git-historikken). `010` opretter selve
   `schema_migrations`-tabellen og registrerer 001-009 som allerede kørt.

## Hvorfor ikke en automatisk migrations-runner?

Der er ingen direkte server-/databaseadgang fra en Claude-session (se
CLAUDE.md), og webhotellet er almindelig delt cPanel-hosting uden garanteret
CLI/cron-adgang. En runner, der selv afgør og udfører pending migrationer,
ville kræve en ny, autentificeret admin-side eller et cron-job — det er en
separat, større beslutning, ikke en del af denne oprydning. Denne mappe plus
`schema_migrations`-tabellen giver i stedet en klar, synlig historik med det
workflow, der allerede fungerer i dag (phpMyAdmin), og er let at bygge en
rigtig runner oven på senere, hvis det bliver relevant.
