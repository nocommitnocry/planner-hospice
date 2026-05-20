-- =============================================================================
-- Planner Hospice - Schema iniziale
--
-- Versione: 1.0.0 (refactor 2026-05-10)
-- Compatibile: MySQL 5.7+ / MariaDB 10.3+
-- =============================================================================

-- Il database deve essere creato esternamente con il nome scelto in .env (DB_NAME).
-- Esempio:
--   CREATE DATABASE hospice_turni CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   GRANT ALL ON hospice_turni.* TO 'hospice_user'@'localhost' IDENTIFIED BY '...';
-- Poi importare questo file:
--   mysql -u hospice_user -p hospice_turni < database/schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Setting (hospice / UCP-DOM)
--
-- La struttura opera su due setting paralleli, ciascuno con la propria
-- caposala. Operatori e piani turno appartengono a un setting. Gli utenti
-- applicativi possono averlo come "setting di default" (filtro UX) ma
-- non è un vincolo di scrittura: caposala/admin possono operare su entrambi
-- per coprire sostituzioni in emergenza.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS setting (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(30) NOT NULL UNIQUE COMMENT 'Identificativo tecnico: hospice, ucp_dom',
    nome VARCHAR(100) NOT NULL COMMENT 'Etichetta visibile in UI',
    descrizione VARCHAR(255) NULL,
    attivo BOOLEAN NOT NULL DEFAULT TRUE,
    ordine_visualizzazione INT NOT NULL DEFAULT 0,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_ordine (ordine_visualizzazione)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Tabella utenti (accesso al sistema)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS utenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL COMMENT 'Hash bcrypt/argon2 - mai password in chiaro',
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    email VARCHAR(150),
    ruolo ENUM('admin', 'caposala', 'visualizzatore') NOT NULL DEFAULT 'visualizzatore',
    id_setting INT NULL COMMENT 'Setting di default (filtro UX). NULL = utente globale (admin/visualizzatore)',
    attivo BOOLEAN NOT NULL DEFAULT TRUE,
    ultimo_accesso DATETIME NULL,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_utenti_setting FOREIGN KEY (id_setting)
        REFERENCES setting(id) ON DELETE SET NULL,
    INDEX idx_utenti_username (username),
    INDEX idx_utenti_ruolo (ruolo),
    INDEX idx_utenti_setting (id_setting)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Tentativi di login (per lockout per username E per IP)
--
-- Refactor rispetto al vecchio schema:
-- - Identifier libero (username inserito o IP) invece di FK rigida a utenti.id
--   Così possiamo tracciare tentativi anche per username inesistenti senza
--   rivelare l'esistenza dell'utente.
-- - Composite key su (identifier, type) per separare i due conteggi.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL COMMENT 'Username tentato oppure IP del client',
    type ENUM('username', 'ip') NOT NULL,
    tentativi INT NOT NULL DEFAULT 1,
    primo_tentativo DATETIME NOT NULL,
    ultimo_tentativo DATETIME NOT NULL,
    UNIQUE KEY uk_login_attempts_identifier (identifier, type),
    INDEX idx_login_attempts_ultimo (ultimo_tentativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Categorie operatori (es. INFERMIERE, OSS, COORDINATORE)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categorie_operatori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE,
    descrizione VARCHAR(255),
    ordine_visualizzazione INT NOT NULL DEFAULT 0,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_categorie_ordine (ordine_visualizzazione)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Operatori (personale dell'hospice)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS operatori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    id_categoria INT NOT NULL,
    id_setting INT NOT NULL COMMENT 'Setting "di casa": cambiare = spostamento lungo (mesi/anni)',
    ore_contrattuali_mensili DECIMAL(6,2) NOT NULL DEFAULT 165.00,
    data_assunzione DATE NULL COMMENT 'Informativo: niente pro-rata automatico',
    data_cessazione DATE NULL COMMENT 'Operatori cessati pre-mese non vengono fotografati nel piano',
    email VARCHAR(150),
    telefono VARCHAR(30),
    note TEXT,
    attivo BOOLEAN NOT NULL DEFAULT TRUE,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_operatori_categoria FOREIGN KEY (id_categoria)
        REFERENCES categorie_operatori(id) ON DELETE RESTRICT,
    CONSTRAINT fk_operatori_setting FOREIGN KEY (id_setting)
        REFERENCES setting(id) ON DELETE RESTRICT,
    INDEX idx_operatori_cognome (cognome, nome),
    INDEX idx_operatori_attivo (attivo),
    INDEX idx_operatori_setting (id_setting),
    INDEX idx_operatori_cessazione (data_cessazione)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Vincoli operatori (es. no_notti, no_weekend)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS operatori_vincoli (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_operatore INT NOT NULL,
    tipo_vincolo VARCHAR(50) NOT NULL COMMENT 'es. no_notti, no_weekend, solo_mattina',
    attivo BOOLEAN NOT NULL DEFAULT TRUE,
    data_inizio DATE NULL,
    data_fine DATE NULL,
    note TEXT,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vincoli_operatore FOREIGN KEY (id_operatore)
        REFERENCES operatori(id) ON DELETE CASCADE,
    INDEX idx_vincoli_operatore (id_operatore, attivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Tipi turno (M=Mattina, P=Pomeriggio, N=Notte, R=Riposo, ecc.)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tipi_turno (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(10) NOT NULL UNIQUE,
    descrizione VARCHAR(100) NOT NULL,
    ora_inizio TIME NULL,
    ora_fine TIME NULL,
    colore VARCHAR(7) NOT NULL DEFAULT '#FFFFFF' COMMENT 'Colore HEX #RRGGBB',
    ore_conteggiate DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    priorita INT NOT NULL DEFAULT 0,
    is_riposo BOOLEAN NOT NULL DEFAULT FALSE,
    is_ferie BOOLEAN NOT NULL DEFAULT FALSE,
    is_permesso BOOLEAN NOT NULL DEFAULT FALSE,
    is_malattia BOOLEAN NOT NULL DEFAULT FALSE,
    is_formazione BOOLEAN NOT NULL DEFAULT FALSE,
    esclude_pianificazione BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Se 1, gli operatori con assenza di questo tipo che copre l''intero mese non vengono fotografati nel piano (es. maternita)',
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Piano turni (intestazione mensile)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS piano_turni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    anno SMALLINT NOT NULL,
    mese TINYINT NOT NULL,
    id_setting INT NOT NULL,
    nome VARCHAR(100),
    stato ENUM('bozza', 'pubblicato', 'archiviato') NOT NULL DEFAULT 'bozza',
    creato_da INT NULL,
    pubblicato_il DATETIME NULL,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_piano_creato_da FOREIGN KEY (creato_da)
        REFERENCES utenti(id) ON DELETE SET NULL,
    CONSTRAINT fk_piano_setting FOREIGN KEY (id_setting)
        REFERENCES setting(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_piano_anno_mese_setting (anno, mese, id_setting),
    INDEX idx_piano_stato (stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Turni (dettaglio per operatore e giorno)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS turni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_piano INT NOT NULL,
    id_operatore INT NOT NULL,
    data DATE NOT NULL,
    id_tipo_turno INT NOT NULL,
    note TEXT,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_turni_piano FOREIGN KEY (id_piano)
        REFERENCES piano_turni(id) ON DELETE CASCADE,
    CONSTRAINT fk_turni_operatore FOREIGN KEY (id_operatore)
        REFERENCES operatori(id) ON DELETE RESTRICT,
    CONSTRAINT fk_turni_tipo FOREIGN KEY (id_tipo_turno)
        REFERENCES tipi_turno(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_turni_operatore_data (id_operatore, data),
    INDEX idx_turni_piano (id_piano),
    INDEX idx_turni_data (data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Assenze operatori (ferie, malattie, permessi pianificati)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assenze (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_operatore INT NOT NULL,
    id_tipo_turno INT NOT NULL COMMENT 'Riferimento al tipo turno che rappresenta l''assenza (F, MA, ...)',
    data_inizio DATE NOT NULL,
    data_fine DATE NOT NULL,
    note TEXT,
    creato_da INT NULL,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_assenze_operatore FOREIGN KEY (id_operatore)
        REFERENCES operatori(id) ON DELETE CASCADE,
    CONSTRAINT fk_assenze_tipo FOREIGN KEY (id_tipo_turno)
        REFERENCES tipi_turno(id) ON DELETE RESTRICT,
    CONSTRAINT fk_assenze_creato_da FOREIGN KEY (creato_da)
        REFERENCES utenti(id) ON DELETE SET NULL,
    INDEX idx_assenze_operatore_periodo (id_operatore, data_inizio, data_fine)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Piano operatori: appartenenza esplicita di un operatore a un piano.
--
-- Fino alla 4-bis era implicita (operatori "di casa" nel setting del piano);
-- dalla 4-ter è materializzata in tabella per consentire l'aggiunta esplicita
-- in itinere di operatori dell'altro setting o assunti dopo la create.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS piano_operatori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_piano INT NOT NULL,
    id_operatore INT NOT NULL,
    aggiunto_manualmente BOOLEAN NOT NULL DEFAULT FALSE COMMENT '0=fotografato dalla create, 1=aggiunto in itinere',
    aggiunto_da INT NULL,
    note_aggiunta TEXT NULL,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_piano_op_piano FOREIGN KEY (id_piano)
        REFERENCES piano_turni(id) ON DELETE CASCADE,
    CONSTRAINT fk_piano_op_operatore FOREIGN KEY (id_operatore)
        REFERENCES operatori(id) ON DELETE RESTRICT,
    CONSTRAINT fk_piano_op_aggiunto_da FOREIGN KEY (aggiunto_da)
        REFERENCES utenti(id) ON DELETE SET NULL,
    UNIQUE KEY uk_piano_operatore (id_piano, id_operatore),
    INDEX idx_piano_op_piano (id_piano),
    INDEX idx_piano_op_operatore (id_operatore)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Saldo ore (riepilogo mensile per operatore)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saldo_ore (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_operatore INT NOT NULL,
    anno SMALLINT NOT NULL,
    mese TINYINT NOT NULL,
    ore_dovute DECIMAL(6,2) NOT NULL,
    ore_lavorate DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    ore_ferie DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    ore_permessi DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    ore_malattia DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    ore_formazione DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    saldo_mese DECIMAL(6,2) NOT NULL,
    saldo_progressivo DECIMAL(6,2) NOT NULL,
    note TEXT,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_saldo_operatore FOREIGN KEY (id_operatore)
        REFERENCES operatori(id) ON DELETE CASCADE,
    UNIQUE KEY uk_saldo_operatore_periodo (id_operatore, anno, mese),
    INDEX idx_saldo_periodo (anno, mese)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Saldo modifiche: storico delle modifiche manuali ai saldi (audit).
--
-- Una riga per ogni intervento manuale su ore_dovute / saldo_progressivo o
-- per l'aggiunta esplicita di un operatore al piano (con valori iniziali
-- custom). Nota motivazione obbligatoria a livello applicativo.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saldo_modifiche (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_saldo INT NOT NULL,
    id_utente INT NULL,
    tipo_modifica ENUM('ore_dovute', 'saldo_progressivo', 'aggiunta_operatore') NOT NULL,
    valore_precedente DECIMAL(6,2) NULL,
    valore_nuovo DECIMAL(6,2) NULL,
    note TEXT NOT NULL,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_saldomod_saldo FOREIGN KEY (id_saldo)
        REFERENCES saldo_ore(id) ON DELETE CASCADE,
    CONSTRAINT fk_saldomod_utente FOREIGN KEY (id_utente)
        REFERENCES utenti(id) ON DELETE SET NULL,
    INDEX idx_saldomod_saldo (id_saldo, creato_il)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Log modifiche (audit trail)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS log_modifiche (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_utente INT NULL,
    tabella VARCHAR(50) NOT NULL,
    id_record INT NULL,
    azione ENUM('inserimento', 'modifica', 'eliminazione', 'login', 'logout', 'login_failed') NOT NULL,
    dati_vecchi JSON NULL,
    dati_nuovi JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_utente FOREIGN KEY (id_utente)
        REFERENCES utenti(id) ON DELETE SET NULL,
    INDEX idx_log_tabella_record (tabella, id_record),
    INDEX idx_log_timestamp (timestamp),
    INDEX idx_log_azione (azione)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Dati iniziali (seed)
-- =============================================================================

INSERT INTO setting (codice, nome, descrizione, ordine_visualizzazione) VALUES
    ('hospice', 'Hospice', 'Reparto di degenza interna',           1),
    ('ucp_dom', 'UCP-DOM', 'Unità Cure Palliative Domiciliari',    2);

INSERT INTO categorie_operatori (nome, descrizione, ordine_visualizzazione) VALUES
    ('INFERMIERE', 'Personale infermieristico', 1),
    ('OSS',        'Operatori socio-sanitari', 2),
    ('COORD.',     'Coordinatori e caposala', 3);

INSERT INTO tipi_turno
    (codice, descrizione, ora_inizio, ora_fine, colore, ore_conteggiate, priorita,
     is_riposo, is_ferie, is_permesso, is_malattia, is_formazione) VALUES
    ('M',  'Mattina',    '07:00:00', '14:30:00', '#FFFFFF',  7.50, 10, 0, 0, 0, 0, 0),
    ('P',  'Pomeriggio', '13:45:00', '21:30:00', '#FFFFFF',  7.75, 20, 0, 0, 0, 0, 0),
    ('N',  'Notte',      '21:00:00', '07:30:00', '#FFCCCC', 10.50, 30, 0, 0, 0, 0, 0),
    ('S',  'Smonto',     NULL,       NULL,       '#CCCCFF',  0.00, 45, 1, 0, 0, 0, 0),
    ('R',  'Riposo',     NULL,       NULL,       '#CCFFCC',  0.00, 40, 1, 0, 0, 0, 0),
    ('F',  'Ferie',      NULL,       NULL,       '#FFFFCC',  7.50, 50, 0, 1, 0, 0, 0),
    ('D',  'Domicilio',  '14:30:00', '21:30:00', '#66CCFF',  7.00, 25, 0, 0, 0, 0, 0),
    ('G',  'Giornata',   '08:00:00', '16:00:00', '#FFCC99',  8.00,  5, 0, 0, 0, 0, 0),
    ('C',  'Corso',      NULL,       NULL,       '#FF99CC',  7.50, 60, 0, 0, 0, 0, 1),
    ('MA', 'Malattia',   NULL,       NULL,       '#FFCC99',  7.50, 55, 0, 0, 0, 1, 0),
    ('PE', 'Permesso',   NULL,       NULL,       '#E0E0E0',  7.50, 52, 0, 0, 1, 0, 0);

-- -----------------------------------------------------------------------------
-- L'utente amministratore va creato dopo l'import dello schema con lo script:
--   php bin/create-admin.php
-- (Vedi README.md sezione "Installazione". Non inseriamo qui un hash di default
-- per evitare che venga lasciato in produzione.)
-- -----------------------------------------------------------------------------
