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
    tipo_vincolo VARCHAR(50) NOT NULL COMMENT 'no_notti | no_weekend | solo_mattine — set chiuso lato applicativo',
    attivo BOOLEAN NOT NULL DEFAULT TRUE,
    data_inizio DATE NULL,
    data_fine DATE NULL,
    note TEXT,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creato_da INT NULL,
    CONSTRAINT fk_vincoli_operatore FOREIGN KEY (id_operatore)
        REFERENCES operatori(id) ON DELETE CASCADE,
    CONSTRAINT fk_vincoli_creato_da FOREIGN KEY (creato_da)
        REFERENCES utenti(id) ON DELETE SET NULL,
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
    schema_ore ENUM('da_schema', 'maternita_8_6_0', 'zero') NOT NULL DEFAULT 'da_schema' COMMENT 'Regola di conteggio ore quando il tipo e'' un''assenza: da_schema | maternita_8_6_0 (8/6/0) | zero',
    attivo BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Soft-disable: se 0 il tipo non e'' assegnabile (resta per i turni storici)',
    id_setting INT NULL COMMENT 'Setting di pertinenza per "assegna turno"; NULL = entrambi',
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tipo_setting FOREIGN KEY (id_setting)
        REFERENCES setting(id) ON DELETE RESTRICT
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
    ore_effettive DECIMAL(4,2) NULL COMMENT 'Ore lavorate del turno (Opzione B). NULL => fallback a tipi_turno.ore_conteggiate',
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
-- Schemi di turnazione (dati configurabili per il generatore).
--
-- Due famiglie: 'ciclico' (periodo N giorni, posizione-based: Hospice inf/OSS)
-- e 'settimanale' (periodo 7, giorno-settimana-based: coordinatrice, UCP-DOM).
-- I passi portano sia il tipo proposto sia le ore (lavorate vs assenza, che
-- divergono per la vestizione e per gli orari variabili per giorno di UCP-DOM).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schemi_turnazione (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(40) NOT NULL UNIQUE,
    nome VARCHAR(100) NOT NULL,
    id_setting INT NOT NULL,
    famiglia ENUM('ciclico', 'settimanale') NOT NULL,
    periodo_giorni TINYINT NOT NULL COMMENT '6 per il ciclo Hospice, 7 per i settimanali',
    attivo BOOLEAN NOT NULL DEFAULT TRUE,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_schema_setting FOREIGN KEY (id_setting)
        REFERENCES setting(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_passi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_schema INT NOT NULL,
    posizione TINYINT NOT NULL COMMENT '0..periodo-1 (ciclico) oppure 0=lun..6=dom (settimanale)',
    id_tipo_turno INT NULL COMMENT 'Tipo proposto dal generatore; NULL = nessun turno',
    ore_lavorate DECIMAL(4,2) NULL COMMENT 'ore_effettive da scrivere sul turno generato',
    ore_assenza DECIMAL(4,2) NOT NULL DEFAULT 0.00 COMMENT 'Ore contate se questa posizione e'' un''assenza (base, no vestizione)',
    CONSTRAINT fk_passo_schema FOREIGN KEY (id_schema)
        REFERENCES schemi_turnazione(id) ON DELETE CASCADE,
    CONSTRAINT fk_passo_tipo FOREIGN KEY (id_tipo_turno)
        REFERENCES tipi_turno(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_passo_schema_posizione (id_schema, posizione)
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
    ore_maternita DECIMAL(6,2) NOT NULL DEFAULT 0.00,
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

-- Ore M/P/N/G gia' comprensive della vestizione (+0,25h, decisa 2026-05-23,
-- da confermare alla demo). D NON ha vestizione. Le ore di ASSENZA (base, senza
-- vestizione) vivono sui passi degli schemi piu' sotto.
INSERT INTO tipi_turno
    (codice, descrizione, ora_inizio, ora_fine, colore, ore_conteggiate, priorita,
     is_riposo, is_ferie, is_permesso, is_malattia, is_formazione, esclude_pianificazione, schema_ore) VALUES
    ('M',  'Mattina',                '07:00:00', '14:30:00', '#8FF0A4',  7.75, 10, 0,0,0,0,0,0, 'da_schema'),
    ('P',  'Pomeriggio',             '13:45:00', '21:30:00', '#99C1F1',  8.00, 20, 0,0,0,0,0,0, 'da_schema'),
    ('N',  'Notte',                  '21:00:00', '07:30:00', '#FFBE6F', 10.75, 30, 0,0,0,0,0,0, 'da_schema'),
    ('S',  'Smonto',                 NULL,       NULL,       '#FFBE6F',  0.00, 45, 1,0,0,0,0,0, 'da_schema'),
    ('R',  'Riposo',                 NULL,       NULL,       '#CCFFCC',  0.00, 40, 1,0,0,0,0,0, 'da_schema'),
    ('F',  'Ferie',                  NULL,       NULL,       '#F9F06B',  7.50, 50, 0,1,0,0,0,0, 'da_schema'),
    ('D',  'Domicilio',              '07:00:00', '14:30:00', '#66CCFF',  7.50, 25, 0,0,0,0,0,0, 'da_schema'),
    ('G',  'Giornata',               '08:00:00', '15:30:00', '#FFE08A',  7.75,  5, 0,0,0,0,0,0, 'da_schema'),
    ('C',  'Corso',                  NULL,       NULL,       '#FF99CC',  7.50, 60, 0,0,0,0,1,0, 'da_schema'),
    ('MA', 'Malattia',               NULL,       NULL,       '#FFCC99',  7.50, 55, 0,0,0,1,0,0, 'da_schema'),
    ('PE', 'Permesso',               NULL,       NULL,       '#E0E0E0',  7.50, 52, 0,0,1,0,0,0, 'da_schema'),
    -- Lavoro UCP-DOM (ore variabili per giorno: il valore qui e' default/fallback)
    ('UI', 'Inferm. domiciliare UCP','09:00:00', '17:00:00', '#99CCFF',  8.00, 12, 0,0,0,0,0,0, 'da_schema'),
    ('UO', 'OSS domiciliare UCP',    '08:00:00', '15:15:00', '#99FFCC',  7.25, 14, 0,0,0,0,0,0, 'da_schema'),
    ('Rec','Recupero',               NULL,       NULL,       '#FFD699',  8.00, 35, 0,0,0,0,0,0, 'da_schema'),
    -- Straordinari Hospice (ore base, solo manuali)
    ('Ms', 'Mattina straord.',       '07:00:00', '14:30:00', '#E6E6E6',  7.50, 11, 0,0,0,0,0,0, 'da_schema'),
    ('Ps', 'Pomeriggio straord.',    '13:45:00', '21:30:00', '#E6E6E6',  7.75, 21, 0,0,0,0,0,0, 'da_schema'),
    ('Ns', 'Notte straord.',         '21:00:00', '07:30:00', '#F0CCCC', 10.50, 31, 0,0,0,0,0,0, 'da_schema'),
    -- Assenze (contano "quanto lo schema")
    ('L',  'Lutto',                  NULL, NULL, '#D9D9D9', 7.50, 53, 0,0,1,0,0,0, 'da_schema'),
    ('CM', 'Congedo matrimoniale',   NULL, NULL, '#FFE0F0', 7.50, 56, 0,0,1,0,0,0, 'da_schema'),
    ('CP', 'Congedo paternità',      NULL, NULL, '#E0F0FF', 7.50, 57, 0,0,1,0,0,0, 'da_schema'),
    ('104','Permesso L.104',         NULL, NULL, '#FFEFC0', 7.50, 58, 0,0,1,0,0,0, 'da_schema'),
    ('INF','Infortunio',             NULL, NULL, '#FFB3B3', 7.50, 54, 0,0,0,1,0,0, 'da_schema'),
    ('PST','Permesso studio',        NULL, NULL, '#D0E8D0', 7.50, 59, 0,0,1,0,0,0, 'da_schema'),
    ('DS', 'Donazione sangue',       NULL, NULL, '#FFC0C0', 7.50, 61, 0,0,1,0,0,0, 'da_schema'),
    ('EL', 'Permesso elettorale',    NULL, NULL, '#D0D0F0', 7.50, 62, 0,0,1,0,0,0, 'da_schema'),
    -- Aspettativa: nasconde se mese intero (esclude_pianificazione) e conta 0 (deficit visibile)
    ('ASP','Aspettativa',            NULL, NULL, '#CCCCCC', 0.00, 65, 0,0,0,0,0,1, 'zero');

-- Pertinenza setting dei tipi di LAVORO (assenze + R/Rec/C restano NULL = entrambi).
-- Collation case-insensitive: 'MS' matcha anche 'Ms'/'Ps'/'Ns' del seed sopra.
UPDATE tipi_turno SET id_setting = (SELECT id FROM setting WHERE codice = 'hospice')
    WHERE codice IN ('M', 'P', 'N', 'S', 'G', 'MS', 'PS', 'NS');
UPDATE tipi_turno SET id_setting = (SELECT id FROM setting WHERE codice = 'ucp_dom')
    WHERE codice IN ('UI', 'UO');
-- 'D' (Domicilio) ritirato: i prestiti Hospice->UCP-DOM usano i codici UCP-DOM (UI/UO).
-- ('DV' non e' nel seed: viene rimosso solo dal DB live via migration 0010.)
UPDATE tipi_turno SET attivo = 0 WHERE codice = 'D';

-- -----------------------------------------------------------------------------
-- Schemi di turnazione concreti (6: 4 Hospice + 2 UCP-DOM) e relativi passi
-- -----------------------------------------------------------------------------
INSERT INTO schemi_turnazione (codice, nome, id_setting, famiglia, periodo_giorni, attivo) VALUES
    ('hospice_regolare',     'Hospice — ciclo regolare', (SELECT id FROM setting WHERE codice='hospice'), 'ciclico',     6, 1),
    ('hospice_solo_mattine', 'Hospice — solo mattine',   (SELECT id FROM setting WHERE codice='hospice'), 'ciclico',     6, 1),
    ('hospice_no_notti',     'Hospice — senza notti',    (SELECT id FROM setting WHERE codice='hospice'), 'ciclico',     6, 1),
    ('hospice_coordinatrice','Hospice — coordinatrice',  (SELECT id FROM setting WHERE codice='hospice'), 'settimanale', 7, 1),
    ('ucpdom_infermieri',    'UCP-DOM — infermieri',     (SELECT id FROM setting WHERE codice='ucp_dom'), 'settimanale', 7, 1),
    ('ucpdom_oss',           'UCP-DOM — OSS',            (SELECT id FROM setting WHERE codice='ucp_dom'), 'settimanale', 7, 1);

SET @t_M  := (SELECT id FROM tipi_turno WHERE codice='M');
SET @t_P  := (SELECT id FROM tipi_turno WHERE codice='P');
SET @t_N  := (SELECT id FROM tipi_turno WHERE codice='N');
SET @t_S  := (SELECT id FROM tipi_turno WHERE codice='S');
SET @t_R  := (SELECT id FROM tipi_turno WHERE codice='R');
SET @t_G  := (SELECT id FROM tipi_turno WHERE codice='G');
SET @t_UI := (SELECT id FROM tipi_turno WHERE codice='UI');
SET @t_UO := (SELECT id FROM tipi_turno WHERE codice='UO');

SET @sc_reg  := (SELECT id FROM schemi_turnazione WHERE codice='hospice_regolare');
SET @sc_solm := (SELECT id FROM schemi_turnazione WHERE codice='hospice_solo_mattine');
SET @sc_nonot:= (SELECT id FROM schemi_turnazione WHERE codice='hospice_no_notti');
SET @sc_coord:= (SELECT id FROM schemi_turnazione WHERE codice='hospice_coordinatrice');
SET @sc_uinf := (SELECT id FROM schemi_turnazione WHERE codice='ucpdom_infermieri');
SET @sc_uoss := (SELECT id FROM schemi_turnazione WHERE codice='ucpdom_oss');

-- Hospice regolare: M M P N S R
INSERT INTO schema_passi (id_schema, posizione, id_tipo_turno, ore_lavorate, ore_assenza) VALUES
    (@sc_reg, 0, @t_M, 7.75, 7.50),
    (@sc_reg, 1, @t_M, 7.75, 7.50),
    (@sc_reg, 2, @t_P, 8.00, 7.75),
    (@sc_reg, 3, @t_N, 10.75, 10.50),
    (@sc_reg, 4, @t_S, 0.00, 0.00),
    (@sc_reg, 5, @t_R, 0.00, 0.00);

-- Hospice solo mattine: M M M M R R
INSERT INTO schema_passi (id_schema, posizione, id_tipo_turno, ore_lavorate, ore_assenza) VALUES
    (@sc_solm, 0, @t_M, 7.75, 7.50),
    (@sc_solm, 1, @t_M, 7.75, 7.50),
    (@sc_solm, 2, @t_M, 7.75, 7.50),
    (@sc_solm, 3, @t_M, 7.75, 7.50),
    (@sc_solm, 4, @t_R, 0.00, 0.00),
    (@sc_solm, 5, @t_R, 0.00, 0.00);

-- Hospice no notti: M M P P R R
INSERT INTO schema_passi (id_schema, posizione, id_tipo_turno, ore_lavorate, ore_assenza) VALUES
    (@sc_nonot, 0, @t_M, 7.75, 7.50),
    (@sc_nonot, 1, @t_M, 7.75, 7.50),
    (@sc_nonot, 2, @t_P, 8.00, 7.75),
    (@sc_nonot, 3, @t_P, 8.00, 7.75),
    (@sc_nonot, 4, @t_R, 0.00, 0.00),
    (@sc_nonot, 5, @t_R, 0.00, 0.00);

-- Hospice coordinatrice (settimanale, 0=lun..6=dom): G lun-ven, R sab-dom
INSERT INTO schema_passi (id_schema, posizione, id_tipo_turno, ore_lavorate, ore_assenza) VALUES
    (@sc_coord, 0, @t_G, 7.75, 7.50),
    (@sc_coord, 1, @t_G, 7.75, 7.50),
    (@sc_coord, 2, @t_G, 7.75, 7.50),
    (@sc_coord, 3, @t_G, 7.75, 7.50),
    (@sc_coord, 4, @t_G, 7.75, 7.50),
    (@sc_coord, 5, @t_R, 0.00, 0.00),
    (@sc_coord, 6, @t_R, 0.00, 0.00);

-- UCP-DOM infermieri (settimanale): UI lun-gio 8h, ven 6h, R sab-dom
INSERT INTO schema_passi (id_schema, posizione, id_tipo_turno, ore_lavorate, ore_assenza) VALUES
    (@sc_uinf, 0, @t_UI, 8.00, 8.00),
    (@sc_uinf, 1, @t_UI, 8.00, 8.00),
    (@sc_uinf, 2, @t_UI, 8.00, 8.00),
    (@sc_uinf, 3, @t_UI, 8.00, 8.00),
    (@sc_uinf, 4, @t_UI, 6.00, 6.00),
    (@sc_uinf, 5, @t_R,  0.00, 0.00),
    (@sc_uinf, 6, @t_R,  0.00, 0.00);

-- UCP-DOM OSS (settimanale): UO lun-ven 7,25h, sab 4,25h, R dom
INSERT INTO schema_passi (id_schema, posizione, id_tipo_turno, ore_lavorate, ore_assenza) VALUES
    (@sc_uoss, 0, @t_UO, 7.25, 7.25),
    (@sc_uoss, 1, @t_UO, 7.25, 7.25),
    (@sc_uoss, 2, @t_UO, 7.25, 7.25),
    (@sc_uoss, 3, @t_UO, 7.25, 7.25),
    (@sc_uoss, 4, @t_UO, 7.25, 7.25),
    (@sc_uoss, 5, @t_UO, 4.25, 4.25),
    (@sc_uoss, 6, @t_R,  0.00, 0.00);

-- -----------------------------------------------------------------------------
-- L'utente amministratore va creato dopo l'import dello schema con lo script:
--   php bin/create-admin.php
-- (Vedi README.md sezione "Installazione". Non inseriamo qui un hash di default
-- per evitare che venga lasciato in produzione.)
-- -----------------------------------------------------------------------------
