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
3. **`help.php` opdateres proaktivt**, hver gang en ny funktion eller en
   større ændring bygges — uden at skulle bedes om det. Både dansk og
   engelsk tekst (`help.*`-nøgler i begge sprogfiler).

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
