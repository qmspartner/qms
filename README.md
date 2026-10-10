# QMS Dokumentstyring

Et enkelt webbaseret dokumenthåndteringssystem til ISO 9001-dokumentation:
dokumentoversigt, versionering, godkendelsesflow og audit-log. Bygget i almindelig
PHP + MySQL, så det kan køre på et standard-webhotel med cPanel.

## Hvad systemet kan
- Upload af dokumenter og nye versioner (PDF, Word, Excel)
- Godkendelsesflow: kladde → til godkendelse → godkendt/afvist
- Automatisk arkivering af tidligere godkendte versioner
- Fuld audit-log pr. dokument (hvem gjorde hvad, hvornår)
- Roller: administrator, godkender, medarbejder
- Beskyttet filnedhentning (kræver login, direkte URL-adgang er blokeret)

## Installation på et cPanel-webhotel

1. **Opret en MySQL-database**
   I cPanel: "MySQL Databases" → opret en database og en databasebruger, giv
   brugeren alle rettigheder til databasen. Notér dig databasenavn, brugernavn
   og adgangskode.

2. **Importér databaseskemaet**
   Åbn phpMyAdmin fra cPanel, vælg din nye database, gå til "Importér" og vælg
   filen `schema.sql`.

3. **Upload filerne**
   Upload hele mappens indhold til `public_html/` (eller en undermappe, hvis
   du vil have systemet på f.eks. `dinside.dk/dokumenter/`) via cPanel's
   filhåndtering eller FTP.

4. **Udfyld `config.php`**
   Åbn `config.php` og indsæt dine databaseoplysninger fra trin 1:
   ```
   DB_HOST, DB_NAME, DB_USER, DB_PASS
   ```

5. **Sæt rettigheder på uploads-mappen**
   Sørg for at `uploads/`-mappen er skrivbar (typisk 755 eller 775, afhængig
   af serverens opsætning — prøv 755 først).

6. **Opret den første bruger**
   Gå til `dinside.dk/setup.php` i browseren og opret jeres organisation samt en administrator-konto.
   **Slet derefter `setup.php` fra serveren** — den kan kun bruges én gang, men
   det er god praksis at fjerne den helt.

7. **Log ind**
   Gå til `dinside.dk/login.php` og log ind med den konto, du lige har oprettet.

## Efter installation
- Log ind som administrator og opret dine kolleger under "Brugere"
  (giv dem rollen "Godkender", hvis de skal kunne godkende dokumenter).
- Begynd at oprette dokumenter via "Nyt dokument" på forsiden.

## Vigtigt om drift og sikkerhed
- **Backup**: Dit webhotel bør have automatisk backup af både database og
  filer — tjek dette i cPanel. Ellers bør du eksportere databasen jævnligt via
  phpMyAdmin og tage kopi af `uploads/`-mappen.
- **HTTPS**: Sørg for at siden kun kører over HTTPS (de fleste webhoteller
  tilbyder gratis SSL-certifikat via "Let's Encrypt" i cPanel).
- **E-signatur**: Systemet logger hvem der godkender hvad og hvornår
  (svarer til en simpel elektronisk godkendelse). Hvis I har brug for
  juridisk bindende digital signatur, bør det suppleres med en dedikeret
  signeringsløsning.
- Dette er en solid grundmodel — den dækker ISO 9001's krav til dokumentstyring
  (identifikation, godkendelse, version, tilgængelighed, sporbarhed), men er
  bevidst holdt enkel. Naturlige næste udvidelser er: e-mail-notifikationer ved
  godkendelsesanmodninger, automatiske påmindelser før revisionsdatoer, og et
  modul til afvigelser/korrigerende handlinger.

## Standard-login
Der findes ingen standard-login — du opretter selv den første konto via
`setup.php`, se trin 6 ovenfor.
