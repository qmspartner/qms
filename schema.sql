-- QMS Dokumentstyring - databaseskema
-- Importeres via phpMyAdmin (eller mysql-kommandolinje) i den database, dit webhotel har oprettet.

CREATE TABLE organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'editor', 'approver', 'user') NOT NULL DEFAULT 'user',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    doc_number VARCHAR(30) NOT NULL,
    title VARCHAR(255) NOT NULL,
    category ENUM('Procedure', 'Arbejdsinstruktion', 'Formular', 'Politik') NOT NULL,
    iso_clause VARCHAR(60) DEFAULT NULL,
    owner_id INT NOT NULL,
    status ENUM('Kladde', 'Til godkendelse', 'Godkendt', 'Udgået') NOT NULL DEFAULT 'Kladde',
    current_version_id INT DEFAULT NULL,
    next_review_date DATE DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_doc_number_per_org (organization_id, doc_number),
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    version_number VARCHAR(10) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    uploaded_by INT NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Til godkendelse', 'Godkendt', 'Afvist', 'Arkiveret') NOT NULL DEFAULT 'Til godkendelse',
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    comment TEXT,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    document_id INT NOT NULL,
    version_id INT DEFAULT NULL,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bulk-import af dokumenter: historiske godkendelsesfelter, adskilt fra
-- systemets egen (levende) godkendelsesworkflow (approved_by/approved_at).
-- assigned_approver_id gøres eksplicit NULL-bar, da bulk-import springer
-- den levende godkendelsesrunde over og indsætter direkte som "Godkendt".
ALTER TABLE document_versions
    MODIFY COLUMN assigned_approver_id INT NULL DEFAULT NULL,
    ADD COLUMN original_approver VARCHAR(255) DEFAULT NULL AFTER comment,
    ADD COLUMN original_approval_date DATE DEFAULT NULL AFTER original_approver;

-- Globalt (pr. organisation) datoformat til visning i hele systemet og til
-- parsing af fritekst-datoer (fx bulk-import af dokumenter). Datoer gemmes
-- altid som rigtige DATE-kolonner i databasen uanset dette valg — det styrer
-- kun hvordan de vises/indtastes som tekst.
ALTER TABLE organizations
    ADD COLUMN date_format VARCHAR(10) NOT NULL DEFAULT 'd-m-Y';

-- Software-leverancer på Kontraktoversigten (AQAP 2210-sporbarhedsbeviser).
-- Selve kildekoden/build-historikken forbliver i eksterne værktøjer (fx GitHub/CI) —
-- dette er kun et letvægts "bevis-punkt", der peger derud, pr. kontrakt.
CREATE TABLE software_releases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    contract_reference VARCHAR(190) NOT NULL,
    build_version VARCHAR(60) NOT NULL,
    external_reference VARCHAR(500) DEFAULT NULL,
    delivery_date DATE DEFAULT NULL,
    linked_document_id INT DEFAULT NULL,
    notes TEXT,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (linked_document_id) REFERENCES documents(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin-styret kontakt: viser/skjuler hele "Software-leverancer"-sektionen på
-- Kontraktoversigten. Slået fra som standard, da det kun er relevant for
-- organisationer der rent faktisk leverer software som del af deres kontrakter
-- (jf. AQAP 2210).
ALTER TABLE organizations
    ADD COLUMN aqap2210_enabled TINYINT(1) NOT NULL DEFAULT 0;

-- First Article Inspection (FAI): admin-styret kontakt der viser/skjuler
-- FAI-sektionen på Kontraktoversigten, uafhængig af AQAP 2210-kontakten
-- (software-leverancer og FAI er relevante for forskellige typer organisationer).
ALTER TABLE organizations
    ADD COLUMN fai_enabled TINYINT(1) NOT NULL DEFAULT 0;

-- FAI-registreringer pr. kontrakt. Selve karakteristik-tabellen forbliver i et
-- eksternt Excel-/PDF-bilag, der uploades direkte på posten (det ER beviset,
-- i modsætning til software-leverancer hvor den eksterne reference er primær
-- og det tilknyttede dokument er valgfrit). Kun ÉN aktiv FAI pr. delnummer
-- pr. kontrakt - en ny upload for samme delnummer afløser den forrige.
CREATE TABLE fai_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    contract_reference VARCHAR(190) NOT NULL,
    part_number VARCHAR(100) NOT NULL,
    drawing_revision VARCHAR(30) DEFAULT NULL,
    result ENUM('Godkendt', 'Afvist', 'Under behandling') NOT NULL DEFAULT 'Under behandling',
    inspection_date DATE DEFAULT NULL,
    stored_filename VARCHAR(255) DEFAULT NULL,
    original_filename VARCHAR(255) DEFAULT NULL,
    notes TEXT,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fai_part_per_contract (organization_id, contract_reference, part_number),
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Brute-force-bremse på login. Ikke organisationsspecifik (loginsiden kender ikke
-- organisationen før e-mailen er slået op), og gamle rækker ryddes løbende i koden.
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_lookup (email, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ingen organisation eller bruger oprettes her.
-- Åbn setup.php i browseren efter import for at oprette den første organisation og administrator-konto.
