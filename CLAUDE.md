# QMS Partner — Kvalitetsstyringssystem

PHP/MySQL kvalitetsstyringssystem (ISO 9001 + AQAP) for QMSPartner. Live på
qmspartner.dk/qms, ejer: Lennart Juhl. Dette repo er den egentlige sandhed om
systemet — denne fil beskriver de konventioner, der er etableret undervejs i
samarbejdet, så en ny session (eller en anden person) kan fortsætte uden at
skulle genopfinde dem.

## Miljø og levering — læs dette først

- **Ingen direkte adgang til den levende server eller database.** Alle
  kodeændringer leveres til Lennart som filer/zip (via SendUserFile i en
  Claude-session), aldrig ved at skrive direkte til produktionsserveren.
- **Reelt filrod på serveren:** `public_html/qms/` på cPanel-webhotellet —
  ikke kontoens rod. En tidligere levering blev udpakket forkert og førte til
  forvirring om hvorfor rettelser ikke slog igennem.
- **Zip-levering:** flad struktur uden en wrapper-mappe, der matcher
  destinationen (dvs. ikke en `qms/`-mappe udenom alt) — men filer under
  `includes/` og `includes/lang/` skal pakkes med den relative sti bevaret,
  så den matcher den virkelige mappestruktur, når Lennart udpakker oven på
  `public_html/qms/`.
- **SQL-migrationsscripts vises ALTID som ren tekst direkte i chat-svaret**,
  ikke kun i en leveret fil — Lennart kører dem manuelt i phpMyAdmin, og
  fil-links er ikke altid praktiske for ham. Dette gælder både
  skema-migrationer og engangs-data-reparationsscripts.
- **`config.php` committes ALDRIG** — den indeholder databasens rigtige
  adgangskode og er derfor i `.gitignore`. Skabelonen ligger i
  `config.example.php` (committet, med pladsholder-værdier). Den rigtige
  `config.php` findes kun direkte på serveren (og i en sessions lokale
  klon, hvis den er nødvendig til udvikling/test her) — den leveres aldrig
  til Lennart og ændres aldrig via en leveret zip-fil.
- **GitHub:** `github.com/qmspartner/qms` (privat). Push med
  `git push origin master:main` (lokal branch hedder `master`, remote
  default er `main`). Commit-beskeder afslutter med en attributionsfodnote
  — den aktuelle ordlyd og session-URL gives i hver sessions system-prompt;
  brug den, ikke en hardkodet værdi (den ændrer sig fra session til
  session).

## Validering før enhver levering

1. `php -l` på hver rørt fil — ingen undtagelser.
2. Sprog-parity mellem `includes/lang/da.php` og `includes/lang/en.php`:
   samme antal nøgler, ingen forskel i begge retninger. Kør:
   ```
   php -r '
   $da = include "includes/lang/da.php";
   $en = include "includes/lang/en.php";
   echo "da: " . count($da) . ", en: " . count($en) . "\n";
   print_r(array_diff(array_keys($da), array_keys($en)));
   print_r(array_diff(array_keys($en), array_keys($da)));
   '
   ```
3. `php tests/run_tests.php` — den (endnu begrænsede) test-suite for de rene,
   DB-fri rettigheds-/synlighedsfunktioner. Se "Tests og CI" nedenfor.
4. **`help.php` opdateres proaktivt**, hver gang en ny funktion eller en
   større ændring bygges — uden at skulle bedes om det. Både dansk og
   engelsk tekst (`help.*`-nøgler i begge sprogfiler).

Alle tre af punkt 1-3 køres også automatisk af GitHub Actions på hvert push
(`.github/workflows/ci.yml`) — se "Tests og CI" nedenfor. Det er stadig god
praksis at køre dem lokalt før en levering, så eventuelle fejl opdages før
der leveres en zip, ikke bagefter.

## Tests og CI

- **`tests/run_tests.php`**: en lille, afhængighedsfri test-runner (almindeligt
  PHP, ingen PHPUnit, ingen databaseforbindelse). Dækker i dag de rene
  rettigheds-/synlighedsfunktioner i `includes/functions.php`
  (`document_visibility_sql()`, `document_is_visible()`,
  `can_view_all_document_statuses()`, `can_approve_documents()`, `is_gqar()`,
  `user_has_contract()`, `contract_in_clause()`). Udvides med flere test-filer
  i `tests/`, efterhånden som flere rene (ikke-DB-afhængige) funktioner får
  dækning — funktioner der selv slår op i databasen (som `has_permission()`
  for ikke-admin-roller) kræver et test-skema og er bevidst ikke dækket endnu.
  Køres med `php tests/run_tests.php`.
- **`.github/workflows/ci.yml`**: kører automatisk på hvert push/PR til
  GitHub — `php -l` på alle filer, sprog-parity-tjekket, og test-suiten.
  Rører aldrig den levende server eller database; kører udelukkende på
  GitHubs egne maskiner mod koden i repoet. Dette er ikke en erstatning for
  at køre validering lokalt før en levering, men et sikkerhedsnet, der også
  fanger regressioner hvis koden redigeres et andet sted end i en
  Claude-session.

## Migrationer

- Alle skemaændringer udover `schema.sql` ligger nummereret i `migrations/`
  (`NNN_kort_beskrivelse.sql`), i den orden de skal køres i — se
  `migrations/README.md` for den fulde konvention. Der er ingen automatisk
  runner; Lennart kører hver fil manuelt i phpMyAdmin, som hidtil, og SQL
  vises altid også som ren tekst i chat-svaret (se ovenfor).
- En `schema_migrations`-tabel (oprettet af `migrations/010_...sql`) holder
  styr på hvilke migrationsfiler der faktisk er kørt på den konkrete
  database. Hver ny migrationsfil skal slutte med en
  `INSERT INTO schema_migrations (filename) VALUES ('NNN_...sql');`-linje.

## Arkitektur

- **Multi-tenant:** stort set hver tabel/forespørgsel er scopet af
  `organization_id`. Glem ikke dette filter i nye queries.
- **Rettighedsmodel** (`includes/auth.php`, `includes/functions.php`):
  - Roller: `admin` (fuld adgang), `editor` (redaktør), `approver`
    (godkender), `user` (almindelig bruger), `gqar` (ekstern,
    kontraktbegrænset — styres helt separat, se nedenfor).
  - `require_login()`, `require_role([...])`, `require_permission($module,
    $action)`, `require_permission_allow_gqar($module, $action)`.
  - `has_permission($user, $module, $action)` slår op i
    `role_permissions`-tabellen (admin er altid sand).
  - `can_edit_documents($user)`, `can_approve_documents($user)`,
    `can_view_all_document_statuses($user)` (sand for admin/editor/approver,
    falsk for `user` — se dokumentsynlighed nedenfor).
- **GQAR** (ekstern kontraktpart, NATO/AQAP-begrebet for en Government
  Quality Assurance Representative): `is_gqar($user)`,
  `user_has_contract($user, $baselineRef)`, `contract_in_clause(...)`. GQAR
  må kun se konfigurationsstyrede (`is_configuration_item = 1`), godkendte
  dokumenter inden for egen kontrakt (`baseline_reference`). Styres helt
  separat fra den almindelige rolle/rettighedsmodel — ikke en del af
  `permission_roles()`.

## Dokumentstatus og -synlighed

- `documents.status` enum: `Kladde`, `Til godkendelse`, `Godkendt`, `Udgået`.
- `document_versions.status` enum: `Kladde`, `Til godkendelse`, `Godkendt`,
  `Afvist`, `Arkiveret`.
- `documents.current_version_id` er den autoritative "aktuelt godkendte
  version"-reference. **UI skal altid følge denne**, ikke bare den senest
  uploadede række (`document_versions` sorteret efter `uploaded_at`) — en
  afventende eller afvist ny version må ikke få et dokument til fejlagtigt
  at se ud som om det har mistet sin godkendte version.
- **Synlighed pr. rolle:** almindelige brugere (`user`) ser kun dokumenter
  med `status = 'Godkendt'` — kladder, dokumenter til godkendelse og
  udgåede dokumenter er skjult for dem, overalt: dokumentlisten
  (`index.php`), dokumentsiden (`document.php`), selve fildownloadet
  (`download.php` — dette er det kritiske punkt, en direkte `version_id`-URL
  skal IKKE kunne omgå reglen), og dropdowns der linker et dokument til en
  revision/sag (`new_audit.php`, `new_case.php`, inkl. serverside-validering
  ved submit). Brug `can_view_all_document_statuses($user)` til dette, ikke
  en ny, separat kontrolstruktur. GQAR er allerede separat begrænset og
  tælles ikke med her.
- **Versionsnummerering er deferred/udregnet, ikke manuel:** et nyt
  dokument eller en ny version af et eksisterende dokument gemmer et
  pladsholder-nummer `'0.0'`, indtil den rent faktisk bliver godkendt.
  Det rigtige nummer udregnes først i `approve.php`, på godkendelsestidspunktet:
  første godkendelse nogensinde → altid `'1.0'`; senere godkendelser bruger
  et major/minor-valg truffet ved upload (`document_versions.revision_type`
  enum `major`/`minor`), som udregnes mod det forrige godkendte nummer.
  **Undtagelser med manuel indtastning:** importerede dokumenter
  (`is_imported`, deres historiske nummer kan ikke udregnes), og en "ny
  version" af et dokument hvis forrige godkendte nummer ikke følger
  `tal.tal`-formatet (uregelmæssigt legacy-nummer).
- **Dokument-skaller:** et nyt dokument kan oprettes uden fil endnu (kun
  metadata) via "Jeg har ikke filen klar endnu" i `upload_version.php`. Det
  giver en `document_versions`-række med `status = 'Kladde'` og
  `stored_filename`/`original_filename` = `NULL` (begge kolonner er
  nullable, ingen skemaændring behøvet). Godkenderen vælges IKKE ved
  oprettelsen — kun når filen rent faktisk vedhæftes, via
  `send_for_approval.php`. En skal vises med et "Ingen fil endnu"-badge og
  er allerede automatisk skjult for almindelige brugere via
  status-synlighedsreglen (status er `Kladde`).
- **"Opdater kladde af …" / Option B-mønster** (`send_for_approval.php`):
  at sende en kladde til godkendelse og at (valgfrit) udskifte dens fil er
  samme trin, samme side — ikke to separate flows. En fil-udskiftning
  opdaterer den EKSISTERENDE kladde-version-række på stedet (samme id),
  den opretter ikke en ny række, for at undgå at ophobe flere
  "Kladde"-rækker pr. dokument ved gentagne redigeringer.

## Data-/servicelag — modul for modul

Systemets ældre kode har al forretningslogik, databaseadgang og præsentation
smeltet sammen i hver enkelt scriptfil (se arkitekturproblem #1 fra
softwarearkitektur-gennemgangen i Claudes hukommelse). Det rettes IKKE i ét
stort ryk, men modul for modul — et modul flyttes kun til sit eget lag, når
der er en konkret anledning (typisk at flere filer allerede genskriver samme
forespørgsel).

- **`includes/documents.php`** er det første modul: et dokument-repository
  der kapsler de forespørgsler mod `documents`, der ellers var genskrevet i
  `index.php`, `document.php`, `new_audit.php` og `new_case.php`:
  `document_find()`, `document_list()`, `document_stats()`,
  `document_list_for_linking()`, `document_validate_link()`. Scriptfilerne
  kalder disse i stedet for selv at bygge SQL. `download.php`s forespørgsel
  (document_versions JOIN documents) og `home.php`s dashboard-statistik er
  bevidst IKKE flyttet herind — de har en anden form (version-centreret,
  hhv. et andet sæt tal pr. rolle) og passer ikke ind i de samme funktioner.
- **`includes/cases.php`** er det andet modul: et sags-repository for
  `cases`, der kapsler de forespørgsler der var genskrevet i `cases.php` og
  `case.php`: `case_find()`, `case_list()`, `case_stats()`, samt den
  centrale GQAR-synlighedsregel `case_visibility_sql()`/`case_is_visible()`
  (sager har ingen "almindelig bruger ser mindre"-regel — kun GQAR er
  begrænset, til sager med `requires_customer_notification = 1` inden for
  egen kontrakt). `home.php`s og `contract_overview.php`s statistik bruger
  samme grundregel i en anden forespørgselsform og er bevidst IKKE flyttet
  herind, af samme grund som `document_stats()`/`home.php` ovenfor.
- **`includes/audits.php`** er det tredje modul: et audit-repository for
  `audits`, der kapsler de forespørgsler der var genskrevet i `audits.php`
  og `audit.php`: `audit_find()`, `audit_list()`, `audit_stats()`
  (kører to forespørgsler — audits-tabellen for "planlagt i år", cases-
  tabellen for "åbne fund" — samlet i ét svar, fordi begge hører til samme
  stat-grid), samt `audit_visibility_sql()`/`audit_is_visible()` (samme
  mønster som sager: kun GQAR er begrænset, her til EKSTERNE audits inden
  for egen kontrakt). Samme afgrænsning som ovenfor for `home.php`s/
  `contract_overview.php`s statistik.
- **`includes/suppliers.php`** er det fjerde modul: et leverandør-
  repository for `suppliers` (`supplier_find()`, `supplier_list()`,
  `supplier_stats()`). I modsætning til de tre andre moduler er der
  INGEN GQAR-synlighedsregel her — `suppliers.php`/`supplier_detail.php`
  bruger `require_permission()`, ikke `require_permission_allow_gqar()`,
  så GQAR har slet ikke adgang til leverandørmodulet. De mindre, enkeltstående
  leverandør-forespørgsler (dropdown-liste og eksistens-tjek i `new_case.php`,
  `delete_supplier.php`, `includes/functions.php`) er bevidst IKKE flyttet
  herind — de forekommer hver kun ét sted, så der er ingen duplikering at
  rydde op på.
- **`includes/employees.php`** er det femte modul: et medarbejder-
  repository (`employee_find()`). Bemærk navnet — ved at kigge på
  "kompetencer" viste det sig at den egentlige duplikering ikke lå i selve
  kvalifikations-forespørgslerne (kompetencematricen, "Mine
  opgaver"-panelet og dashboard-statistikken henter hver sit specifikke
  udsnit af `employee_qualifications`, ikke den samme forespørgsel igen og
  igen, og er bevidst IKKE flyttet til noget modul), men i ét identisk
  `SELECT * FROM employees WHERE id = ? AND organization_id = ?`-opslag,
  skrevet tre gange (`employee_log.php`, `employee_qualification.php`,
  `edit_employee.php`). Samtidig blev en reel navngivningsfejl fundet og
  rettet: `can_manage_employee_offboarding($user, $employee)` i
  `includes/functions.php` ("admin eller denne medarbejders nærmeste
  leder") er omdøbt til `can_manage_employee()`, fordi den IKKE kun bruges
  til fratrædelse (`offboard_employee.php`, `employee_log.php`) — den
  samme regel var samtidig genskrevet to gange til under andre navne i
  `employee_qualification.php` og `download_qualification_evidence.php`.
  Den er bevidst IKKE flyttet til `includes/employees.php`, fordi den
  bruges tværs over moduler (kompetencer og fratrædelse) og derfor hører
  til det generelle rettighedslag i `functions.php`, samme sted som
  `is_gqar()` m.fl.
- **`includes/customers.php`** er det sjette modul: et kunde-repository
  (`customer_find()`, `customer_list()`, `customer_stats()`). Som
  leverandører har kunder ingen GQAR-synlighedsregel. Duplikeringen her
  var mindre end i de andre moduler (kun `edit_customer.php` genskrev
  `customer_find()`s forespørgsel ordret), men modulet følger samme
  mønster som de øvrige for konsistens. Dropdown-/eksistens-tjekket i
  `new_case.php`/`delete_customer.php` og den fulde CSV-eksport i
  `export_customers_csv.php` er bevidst IKKE flyttet herind.
- **`includes/equipment.php`** er det syvende modul: et udstyr-repository
  (`equipment_find()`, `equipment_list()`, `equipment_stats()`). Som
  leverandører/kunder er der ingen GQAR-synlighedsregel. `equipment_find()`
  erstatter to næsten-identiske opslag — `equipment_detail.php`s join mod
  `users` for ansvarligt navn, og `new_calibration.php`s simplere,
  ikke-joinede opslag — begge forenet i den ene, joinede udgave, da den
  ekstra `responsible_name`-kolonne ikke gør skade der hvor den ikke bruges.
  `equipment_stats()` samler to forespørgsler (total/snart-kalibrering og
  antal med seneste kalibrering ikke godkendt) i ét svar, samme mønster som
  `audit_stats()`. Eksistens-tjekket i `delete_equipment.php`, det
  enkeltstående dropdown-opslag i `includes/functions.php`, og
  `home.php`s/`contract_overview.php`s aggregerede udstyrsstatistik (anden
  forespørgselsform, andet formål) er bevidst IKKE flyttet herind.
- Nye moduler følger samme mønster: en fil `includes/<modul>.php`, der
  requirer `includes/functions.php` selv (så den er selvstændig), og
  almindelige funktioner — ikke klasser, for at matche resten af kodebasens
  stil.
- Når et moduls logik flyttes, udvides `tests/run_tests.php` (eller en ny
  fil i `tests/`) med dækning af det, der flyttes — se `tests/fake_pdo.php`
  for hvordan repository-funktioner, der tager en `PDO`, testes uden en
  rigtig database (en simpel stub, der husker den byggede SQL og de bundne
  parametre, i stedet for at udføre dem). For en funktion der selv laver
  flere forespørgsler i rækkefølge (fx `audit_stats()`), sæt `$db->rowsQueue`
  til et array af rækkesæt (ét pr. forespørgsel) i stedet for
  `$db->rowsToReturn`, og nulstil `$db->statements = []` umiddelbart før
  kaldet, så man kun inspicerer de forespørgsler funktionen selv laver.

## Standard-hjælpefunktioner (`includes/functions.php` m.fl.)

`t($key, $vars=[])` / `h()` (htmlspecialchars) / `csrf_field()` /
`render_header()` / `render_footer()` / `status_badge_class()` /
`status_label()` / `category_label()` / `log_action($docId, $versionId,
$action, $details)` / `notify_assigned_approver(...)` og andre
`notify_*`-funktioner i `includes/notifications.php` / `list_active_values()`
/ `document_category_prefix()` / `get_latest_template_document($category)`.
Skabeloner (`is_template`) får altid dokument-ID-præfikset `TMP`, uanset
kategoriens eget præfiks.

## Vaner og stil

- Dansk er systemets primære sprog; engelsk følger med i parallelle
  sprogfiler (`includes/lang/da.php` / `en.php`), altid i parity.
- Kodekommentarer, der forklarer *hvorfor* en ikke-triviel beslutning er
  taget (fx version-nummerering, synlighedsregler), er værdsat og bør
  bevares/udbygges, ikke fjernes, når koden ændres.
- Mock-ups af UI-forslag til diskussion leveres som interaktive
  Design-canvas-artifacts (claude.ai), ikke som statiske billeder — flere
  forslag side om side på samme canvas, når de hænger sammen tematisk.
- Lennart beslutter arkitektur gennem diskussion (ofte nummererede
  spørgsmål/svar) før noget bygges — vent på et eksplicit "værsgo, byg det"
  (eller lignende) ved større, irreversible designvalg; mindre,
  entydige ting kan bygges direkte.

## Kendte, bevidst udskudte punkter

En stående backlog/gap-liste for systemet føres i Claudes hukommelsesfil om
projektet (ikke i dette repo, for at undgå to kilder til samme sandhed) —
Lennart beder periodisk om at se den. Et par konkrete, endnu ikke rettede
ting fundet undervejs:

- `new_quality_plan.php` har stadig et manuelt, påkrævet
  versionsnummer-felt — ikke tilpasset den deferred/udregnede
  nummererings-logik, som resten af systemet nu bruger.
- `audit.php` / `case.php` viser et allerede linket dokuments titel
  (via et simpelt `LEFT JOIN`) uanset dokumentets status — ikke rettet
  med samme synlighedsregel som resten (kun titel/nummer eksponeres,
  ikke indhold).
