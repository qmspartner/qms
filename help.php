<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$gqar = is_gqar($user);
$isAdmin = $user['role'] === 'admin';

// Synlighed pr. sektion — samme logik som navigationen bruger
$showOverviewCards = !$gqar;
$showRoles = true;
$showDocuments = true;
$showQualityPlan = !$gqar;
$showDocNumbering = !$gqar;
$showTemplates = has_permission($user, 'templates');
$showCases = true;
$showCounterfeit = !$gqar;
$showAudits = true;
$showMeetings = !$gqar;
$showEmployees = $isAdmin;
$showUsers = $isAdmin;
$showEquipment = !$gqar;
$showRisks = !$gqar;
$showSuppliers = !$gqar;
$showCustomers = !$gqar;
$showContractOverview = true;
$showFai = organization_has_fai();
$showReports = !$gqar;
$showAccessControl = $isAdmin;
$showImpersonation = $isAdmin;
$showAqapGlossary = true;
$showTables = true;
$showTasks = !$gqar;
$showLanguage = true;

// Grupperet indholdsfortegnelse: samme gruppering som topmenuen bruger, så den er genkendelig.
$groups = [];

$generalItems = [];
if ($showOverviewCards) $generalItems[] = ['overview', 'help.section.overview'];
if ($gqar) $generalItems[] = ['gqar-role', 'help.section.gqar_role'];
$generalItems[] = ['roles', 'help.section.roles'];
if ($showAqapGlossary) $generalItems[] = ['aqap-glossary', 'help.section.aqap_glossary'];
if ($showTables) $generalItems[] = ['tables', 'help.section.tables'];
if ($showTasks) $generalItems[] = ['tasks', 'help.section.tasks'];
$generalItems[] = ['language', 'help.section.language'];
if ($generalItems) $groups[] = ['help.toc_group.general', $generalItems];

$docItems = [];
if ($showDocuments) $docItems[] = ['documents', 'help.section.documents'];
if ($showDocNumbering) $docItems[] = ['doc-numbering', 'help.section.doc_numbering'];
if ($showQualityPlan) $docItems[] = ['quality-plan', 'help.section.quality_plan'];
if ($showCounterfeit) $docItems[] = ['counterfeit', 'help.section.counterfeit'];
if ($showTemplates) $docItems[] = ['templates', 'help.section.templates'];
if ($docItems) $groups[] = ['help.toc_group.documents', $docItems];

$casesItems = [];
if ($showCases) $casesItems[] = ['cases', 'help.section.cases'];
if ($casesItems) $groups[] = ['help.toc_group.cases', $casesItems];

$operationsItems = [];
if ($showAudits) $operationsItems[] = ['audits', 'help.section.audits'];
if ($showMeetings) $operationsItems[] = ['meetings', 'help.section.meetings'];
if ($showEquipment) $operationsItems[] = ['equipment', 'help.section.equipment'];
if ($showRisks) $operationsItems[] = ['risks', 'help.section.risks'];
if ($showSuppliers) $operationsItems[] = ['suppliers', 'help.section.suppliers'];
if ($operationsItems) $groups[] = ['help.toc_group.operations', $operationsItems];

$overviewItems = [];
if ($showContractOverview) $overviewItems[] = ['contract-overview', 'help.section.contract_overview'];
if ($showFai) $overviewItems[] = ['fai', 'help.section.fai'];
if ($showReports) $overviewItems[] = ['reports', 'help.section.reports'];
if ($overviewItems) $groups[] = ['help.toc_group.overview_reports', $overviewItems];

$settingsItems = [];
if ($showCustomers) $settingsItems[] = ['customers', 'help.section.customers'];
if ($showUsers) $settingsItems[] = ['users', 'help.section.users'];
if ($showEmployees) $settingsItems[] = ['employees', 'help.section.employees'];
if ($showAccessControl) $settingsItems[] = ['access-control', 'help.section.access_control'];
if ($showImpersonation) $settingsItems[] = ['impersonation', 'help.section.impersonation'];
if ($settingsItems) $groups[] = ['help.toc_group.settings_access', $settingsItems];

render_header(t('help.title'));
?>
<div class="page-heading"><h1><?= h(t('help.title')) ?></h1></div>
<p class="muted" style="margin:-8px 0 20px;"><?= h(t('help.intro')) ?></p>

<div class="help-layout">
    <nav class="help-toc">
        <input type="search" id="help-search-input" class="help-search-input" placeholder="<?= h(t('help.search.placeholder')) ?>">
        <?php foreach ($groups as [$groupLabelKey, $items]): ?>
            <p class="help-toc-group"><?= h(t($groupLabelKey)) ?></p>
            <?php foreach ($items as [$anchor, $labelKey]): ?>
                <a class="help-toc-link" href="#<?= h($anchor) ?>"><?= h(t($labelKey)) ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="help-content">

        <p id="help-no-results" class="muted" style="display:none;"><?= h(t('help.search.no_results')) ?></p>

        <?php if ($gqar): ?>
        <h2 id="gqar-role"><?= h(t('help.section.gqar_role')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.gqar_role.p1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.gqar_role.p2')) ?></p>
            <p style="margin:0;"><?= h(t('help.gqar_role.p3')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showOverviewCards): ?>
        <h2 id="overview"><?= h(t('help.section.overview')) ?></h2>
        <div class="module-grid" style="margin-bottom:1.5rem;">
            <?php if (has_permission($user, 'documents')): ?>
            <div class="module-card" style="cursor:default;">
                <div class="module-icon module-icon-accent">D</div>
                <p class="module-title"><?= h(t('home.documents.title')) ?></p>
                <p class="module-desc"><?= h(t('home.documents.desc')) ?></p>
            </div>
            <?php endif; ?>
            <?php if (has_permission($user, 'cases')): ?>
            <div class="module-card" style="cursor:default;">
                <div class="module-icon module-icon-danger">S</div>
                <p class="module-title"><?= h(t('home.cases.title')) ?></p>
                <p class="module-desc"><?= h(t('home.cases.desc')) ?></p>
            </div>
            <?php endif; ?>
            <?php if (!$gqar): ?>
            <div class="module-card" style="cursor:default;">
                <div class="module-icon module-icon-success">M</div>
                <p class="module-title"><?= h(t('home.meetings.title')) ?></p>
                <p class="module-desc"><?= h(t('home.meetings.desc')) ?></p>
            </div>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <div class="module-card" style="cursor:default;">
                <div class="module-icon module-icon-success">M</div>
                <p class="module-title"><?= h(t('home.competence.title')) ?></p>
                <p class="module-desc"><?= h(t('home.competence.desc')) ?></p>
            </div>
            <?php endif; ?>
            <?php if (has_permission($user, 'audits')): ?>
            <div class="module-card" style="cursor:default;">
                <div class="module-icon module-icon-accent">A</div>
                <p class="module-title"><?= h(t('home.audits.title')) ?></p>
                <p class="module-desc"><?= h(t('home.audits.desc')) ?></p>
            </div>
            <?php endif; ?>
            <?php if (has_permission($user, 'equipment')): ?>
            <div class="module-card" style="cursor:default;">
                <div class="module-icon module-icon-danger">U</div>
                <p class="module-title"><?= h(t('home.equipment.title')) ?></p>
                <p class="module-desc"><?= h(t('home.equipment.desc')) ?></p>
            </div>
            <?php endif; ?>
            <?php if (has_permission($user, 'risks')): ?>
            <div class="module-card" style="cursor:default;">
                <div class="module-icon module-icon-accent">R</div>
                <p class="module-title"><?= h(t('home.risks.title')) ?></p>
                <p class="module-desc"><?= h(t('home.risks.desc')) ?></p>
            </div>
            <?php endif; ?>
            <?php if (has_permission($user, 'suppliers')): ?>
            <div class="module-card" style="cursor:default;">
                <div class="module-icon module-icon-success">L</div>
                <p class="module-title"><?= h(t('home.suppliers.title')) ?></p>
                <p class="module-desc"><?= h(t('home.suppliers.desc')) ?></p>
            </div>
            <?php endif; ?>
            <?php if (has_permission($user, 'customers')): ?>
            <div class="module-card" style="cursor:default;">
                <div class="module-icon module-icon-success">K</div>
                <p class="module-title"><?= h(t('help.customers.title')) ?></p>
                <p class="module-desc"><?= h(t('help.customers.desc')) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <h2 id="roles"><?= h(t('help.section.roles')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <?php
                $rolesToShow = $isAdmin ? ['admin', 'editor', 'approver', 'user', 'gqar'] : [$user['role']];
            ?>
            <?php foreach ($rolesToShow as $role): ?>
                <div class="log-row">
                    <p><strong><?= h(role_label($role)) ?></strong> — <?= h(t('help.role.' . $role . '.desc')) ?></p>
                </div>
            <?php endforeach; ?>
            <?php if ($isAdmin): ?>
                <p class="muted" style="margin:10px 0 0;"><?= h(t('help.roles.access_control_note')) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($showDocuments): ?>
        <h2 id="documents"><?= h(t('help.section.documents')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
                <span class="<?= status_badge_class('Kladde') ?>"><?= h(status_label('Kladde')) ?></span>
                <span class="muted">→</span>
                <span class="<?= status_badge_class('Til godkendelse') ?>"><?= h(status_label('Til godkendelse')) ?></span>
                <span class="muted">→</span>
                <span class="<?= status_badge_class('Godkendt') ?>"><?= h(status_label('Godkendt')) ?></span>
            </div>
            <p style="margin:0 0 8px;"><?= h(t('help.documents.search')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.documents.step1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.documents.draft')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.documents.step2')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.documents.step3')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.documents.config')) ?></p>
            <p style="margin:0;"><?= h(t('help.documents.acknowledgment')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showDocNumbering): ?>
        <h2 id="doc-numbering"><?= h(t('help.section.doc_numbering')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.doc_numbering.p1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.doc_numbering.p2')) ?></p>
            <p style="margin:0;"><?= h(t('help.doc_numbering.p3')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showQualityPlan): ?>
        <h2 id="quality-plan"><?= h(t('help.section.quality_plan')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.quality_plan.p1')) ?></p>
            <p style="margin:0;"><?= h(t('help.quality_plan.p2')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showCounterfeit): ?>
        <h2 id="counterfeit"><?= h(t('help.section.counterfeit')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.counterfeit.p1')) ?></p>
            <p style="margin:0;"><?= h(t('help.counterfeit.p2')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showTemplates): ?>
        <h2 id="templates"><?= h(t('help.section.templates')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.templates.p1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.templates.p2')) ?></p>
            <p style="margin:0;"><?= h(t('help.templates.p3')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showCases): ?>
        <h2 id="cases"><?= h(t('help.section.cases')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
                <span class="<?= case_status_class('Registreret') ?>"><?= h(case_status_label('Registreret')) ?></span>
                <span class="muted">→</span>
                <span class="<?= case_status_class('Under undersøgelse') ?>"><?= h(case_status_label('Under undersøgelse')) ?></span>
                <span class="muted">→</span>
                <span class="<?= case_status_class('Handling igangsat') ?>"><?= h(case_status_label('Handling igangsat')) ?></span>
                <span class="muted">→</span>
                <span class="<?= case_status_class('Lukket') ?>"><?= h(case_status_label('Lukket')) ?></span>
            </div>
            <p style="margin:0 0 8px;"><?= h(t('help.cases.step1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.cases.step2')) ?></p>
            <p style="margin:0;"><?= h(t('help.cases.step3')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showMeetings): ?>
        <h2 id="meetings"><?= h(t('help.section.meetings')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.meetings.step1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.meetings.step2')) ?></p>
            <p style="margin:0;"><?= h(t('help.meetings.step3')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showAudits): ?>
        <h2 id="audits"><?= h(t('help.section.audits')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
                <span class="<?= audit_status_class('Planlagt') ?>"><?= h(audit_status_label('Planlagt')) ?></span>
                <span class="muted">→</span>
                <span class="<?= audit_status_class('Gennemført') ?>"><?= h(audit_status_label('Gennemført')) ?></span>
            </div>
            <p style="margin:0 0 8px;"><?= h(t('help.audits.step1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.audits.step2')) ?></p>
            <p style="margin:0;"><?= h(t('help.audits.step3')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showEquipment): ?>
        <h2 id="equipment"><?= h(t('help.section.equipment')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.equipment.step1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.equipment.step2')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.equipment.step3')) ?></p>
            <p style="margin:0;"><?= h(t('help.equipment.step4')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showRisks): ?>
        <h2 id="risks"><?= h(t('help.section.risks')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.risks.step1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.risks.step2')) ?></p>
            <p style="margin:0;"><?= h(t('help.risks.step3')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showSuppliers): ?>
        <h2 id="suppliers"><?= h(t('help.section.suppliers')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.suppliers.step1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.suppliers.step2')) ?></p>
            <p style="margin:0;"><?= h(t('help.suppliers.step3')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showCustomers): ?>
        <h2 id="customers"><?= h(t('help.section.customers')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0;"><?= h(t('help.customers.text')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showUsers): ?>
        <h2 id="users"><?= h(t('help.section.users')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.users.step1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.users.step2')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.users.step3')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.users.step4')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.users.step5')) ?></p>
            <p style="margin:0;"><?= h(t('help.users.step6')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showEmployees): ?>
        <h2 id="employees"><?= h(t('help.section.employees')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.employees.step1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.employees.step2')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.employees.step3')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.employees.step4')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.employees.step5')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.employees.step6')) ?></p>
            <p style="margin:0;"><?= h(t('help.employees.step7')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showContractOverview): ?>
        <h2 id="contract-overview"><?= h(t('help.section.contract_overview')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0;"><?= h($gqar ? t('help.contract_overview.gqar') : t('help.contract_overview.text')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showFai): ?>
        <h2 id="fai"><?= h(t('help.section.fai')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.fai.p1')) ?></p>
            <p style="margin:0 0 8px;"><?= h(t('help.fai.p2')) ?></p>
            <p style="margin:0;"><?= h(t('help.fai.p3')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showReports): ?>
        <h2 id="reports"><?= h(t('help.section.reports')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0;"><?= h(t('help.reports.text')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showAccessControl): ?>
        <h2 id="access-control"><?= h(t('help.section.access_control')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 8px;"><?= h(t('help.access_control.p1')) ?></p>
            <p style="margin:0;"><?= h(t('help.access_control.p2')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showImpersonation): ?>
        <h2 id="impersonation"><?= h(t('help.section.impersonation')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0;"><?= h(t('help.impersonation.text')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showAqapGlossary): ?>
        <h2 id="aqap-glossary"><?= h(t('help.section.aqap_glossary')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0 0 10px;"><strong><?= h(t('help.aqap.config_term')) ?></strong> — <?= h(t('help.aqap.config_desc')) ?></p>
            <p style="margin:0 0 10px;"><strong><?= h(t('help.aqap.contract_term')) ?></strong> — <?= h(t('help.aqap.contract_desc')) ?></p>
            <p style="margin:0 0 10px;"><strong><?= h(t('help.aqap.gqar_term')) ?></strong> — <?= h(t('help.aqap.gqar_desc')) ?></p>
            <p style="margin:0;"><strong><?= h(t('help.aqap.traceability_term')) ?></strong> — <?= h(t('help.aqap.traceability_desc')) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showTables): ?>
        <h2 id="tables"><?= h(t('help.section.tables')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                <i class="ti ti-arrows-sort" style="font-size:16px; color:var(--text-muted);"></i>
                <p style="margin:0;"><?= h(t('help.tables.text1')) ?></p>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="ti ti-filter" style="font-size:16px; color:var(--text-muted);"></i>
                <p style="margin:0;"><?= h(t('help.tables.text2')) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($showTasks): ?>
        <h2 id="tasks"><?= h(t('help.section.tasks')) ?></h2>
        <div class="card" style="margin-bottom:1.5rem;">
            <p style="margin:0;"><?= h(t('help.tasks.text')) ?></p>
        </div>
        <?php endif; ?>

        <h2 id="language"><?= h(t('help.section.language')) ?></h2>
        <div class="card">
            <p style="margin:0;"><?= h(t('help.language.text')) ?></p>
        </div>

    </div>
</div>

<script>
(function () {
    // Sidebarens "sticky"-opførsel aktiveres først når siden er scrollet forbi dens
    // naturlige placering (under topmenu + sidehoved). En fast vh-baseret højde tager
    // ikke højde for det, så bunden af listen kan ligge uden for skærmen indtil da.
    // Her måles den faktiske afstand fra toppen af dokumentet, så højden altid passer —
    // uanset om "Se som anden bruger"-banneret vises eller ikke.
    var toc = document.querySelector('.help-toc');
    if (!toc) return;

    function distanceFromDocumentTop(el) {
        var top = 0;
        while (el) {
            top += el.offsetTop || 0;
            el = el.offsetParent;
        }
        return top;
    }

    var naturalTop = distanceFromDocumentTop(toc);

    function sizeToc() {
        if (window.innerWidth <= 720) {
            toc.style.maxHeight = ''; // lad mobil-CSS'en styre (menuen lægger sig ovenpå indholdet der)
            return;
        }
        var available = window.innerHeight - naturalTop - 20;
        toc.style.maxHeight = Math.max(available, 160) + 'px';
    }

    sizeToc();
    window.addEventListener('resize', sizeToc);
})();

(function () {
    // Søgning i hjælpen: grupperer hjælpeindholdet i sektioner (hver <h2> plus alt
    // indhold indtil næste <h2>) ud fra den faktiske DOM frem for en hardkodet liste,
    // så nye afsnit, der tilføjes senere, søges med automatisk uden kodeændring her.
    var input = document.getElementById('help-search-input');
    var content = document.querySelector('.help-content');
    var noResults = document.getElementById('help-no-results');
    if (!input || !content) return;

    var tocLinks = Array.prototype.slice.call(document.querySelectorAll('.help-toc-link'));
    var tocGroupLabels = Array.prototype.slice.call(document.querySelectorAll('.help-toc-group'));

    var sections = [];
    var current = null;
    Array.prototype.forEach.call(content.children, function (el) {
        if (el === noResults) return;
        if (el.tagName === 'H2') {
            if (current) sections.push(current);
            current = { id: el.id, elements: [el], text: '' };
        } else if (current) {
            current.elements.push(el);
        }
    });
    if (current) sections.push(current);
    sections.forEach(function (s) {
        s.text = s.elements.map(function (el) { return el.textContent || ''; }).join(' ').toLowerCase();
    });

    function setVisible(section, visible) {
        section.elements.forEach(function (el) { el.style.display = visible ? '' : 'none'; });
    }

    function filter() {
        var query = input.value.trim().toLowerCase();

        if (!query) {
            sections.forEach(function (s) { setVisible(s, true); });
            tocLinks.forEach(function (a) { a.style.display = ''; });
            tocGroupLabels.forEach(function (p) { p.style.display = ''; });
            if (noResults) noResults.style.display = 'none';
            return;
        }

        var visibleIds = {};
        var anyVisible = false;
        sections.forEach(function (s) {
            // Simpel delstrengs-match: finder også beslægtede ordformer, da danske
            // flertals-/bøjningsformer typisk bygger videre på samme stamme
            // ("bruger" matcher "brugere", "fratrådt" matcher "fratrådte").
            var match = s.text.indexOf(query) !== -1;
            setVisible(s, match);
            if (match) {
                anyVisible = true;
                visibleIds[s.id] = true;
            }
        });

        tocLinks.forEach(function (a) {
            var id = (a.getAttribute('href') || '').replace('#', '');
            a.style.display = visibleIds[id] ? '' : 'none';
        });

        tocGroupLabels.forEach(function (label) {
            var hasVisible = false;
            var el = label.nextElementSibling;
            while (el && el.classList.contains('help-toc-link')) {
                if (el.style.display !== 'none') hasVisible = true;
                el = el.nextElementSibling;
            }
            label.style.display = hasVisible ? '' : 'none';
        });

        if (noResults) noResults.style.display = anyVisible ? 'none' : '';
    }

    input.addEventListener('input', filter);
})();
</script>

<?php render_footer(); ?>
