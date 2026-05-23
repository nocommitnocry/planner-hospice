-- Migrazione 0006 - Schemi di turnazione + ore effettive + conteggio assenze
--
-- Sessione 6 (2026-05-23). Generatore automatico.
-- Spec di riferimento: spec-sessione-6.md (BOZZA v1).
--
-- Cosa fa:
--   1. tipi_turno.schema_ore ENUM(da_schema|maternita_8_6_0|zero): instrada il
--      conteggio ore delle ASSENZE. Default 'da_schema' = conta quanto la
--      posizione di schema (regola unica). 'maternita_8_6_0' = 8h lun-gio,
--      6h ven, 0 weekend. 'zero' = non conta nulla (aspettativa: il deficit
--      resta visibile nel saldo come costo).
--   2. turni.ore_effettive DECIMAL(4,2) NULL: ore lavorate del singolo turno
--      (Opzione B). Se NULL, il calcolo del saldo usa tipi_turno.ore_conteggiate
--      come fallback. Serve per gli orari variabili per giorno (UCP-DOM: inf 8h
--      lun-gio / 6h ven; OSS 7,25h lun-ven / 4,25h sab) e per i casi speciali.
--   3. Tabelle schemi_turnazione + schema_passi: gli schemi di turnazione come
--      DATI configurabili (due famiglie: 'ciclico' a posizione, 'settimanale' a
--      giorno della settimana). schema_passi.ore_lavorate = ore_effettive da
--      scrivere sul turno generato; schema_passi.ore_assenza = ore contate se
--      quel giorno e' un'assenza (base, SENZA vestizione).
--   4. Vestizione (decisa 2026-05-23, da confermare alla demo): +0,25h sui turni
--      lavorati M/P/N/G -> bump di ore_conteggiate. Le ore di assenza restano
--      base (sui passi). D NON e' in lista vestizione.
--   5. Fix orari: G 08:00-15:30 (7,50 base + vestizione = 7,75); D 07:00-14:30.
--   6. Seed dei tipi turno nuovi (UCP, straordinari, assenze).
--   7. Seed dei 6 schemi concreti (4 Hospice + 2 UCP-DOM) con i loro passi.
--
-- Nota maternita: il tipo maternita (creato a mano da Olga in 4-sexies con
--   esclude_pianificazione=1) viene marcato schema_ore='maternita_8_6_0'
--   tramite UPDATE sul flag, robusto rispetto al codice scelto. L'aspettativa
--   (ASP, nuova) usa esclude_pianificazione=1 + schema_ore='zero'.
--
-- Idempotenza: le ALTER/INSERT non sono idempotenti, applicare solo su DB
--   allineato alla migrazione 0005.

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. tipi_turno.schema_ore
-- ----------------------------------------------------------------------------
ALTER TABLE tipi_turno
    ADD COLUMN schema_ore ENUM('da_schema', 'maternita_8_6_0', 'zero')
        NOT NULL DEFAULT 'da_schema'
        COMMENT 'Regola di conteggio ore quando il tipo e'' un''assenza'
        AFTER esclude_pianificazione;

-- ----------------------------------------------------------------------------
-- 2. turni.ore_effettive
-- ----------------------------------------------------------------------------
ALTER TABLE turni
    ADD COLUMN ore_effettive DECIMAL(4,2) NULL
        COMMENT 'Ore lavorate del turno (Opzione B). NULL => fallback a tipi_turno.ore_conteggiate'
        AFTER id_tipo_turno;

-- ----------------------------------------------------------------------------
-- 3. schemi_turnazione + schema_passi
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 4-5. Vestizione + fix orari sui tipi esistenti
-- ----------------------------------------------------------------------------
UPDATE tipi_turno SET ore_conteggiate = 7.75 WHERE codice = 'M';
UPDATE tipi_turno SET ore_conteggiate = 8.00 WHERE codice = 'P';
UPDATE tipi_turno SET ore_conteggiate = 10.75 WHERE codice = 'N';
UPDATE tipi_turno SET ora_inizio = '08:00:00', ora_fine = '15:30:00', ore_conteggiate = 7.75 WHERE codice = 'G';
UPDATE tipi_turno SET ora_inizio = '07:00:00', ora_fine = '14:30:00', ore_conteggiate = 7.50 WHERE codice = 'D';

-- Maternita gia' esistente (creata a mano in 4-sexies): conteggio 8/6/0.
UPDATE tipi_turno SET schema_ore = 'maternita_8_6_0' WHERE esclude_pianificazione = 1;

-- ----------------------------------------------------------------------------
-- 6. Tipi turno nuovi
-- ----------------------------------------------------------------------------
INSERT INTO tipi_turno
    (codice, descrizione, ora_inizio, ora_fine, colore, ore_conteggiate, priorita,
     is_riposo, is_ferie, is_permesso, is_malattia, is_formazione, esclude_pianificazione, schema_ore) VALUES
    -- Lavoro UCP-DOM (ore variabili per giorno: il valore qui e' il default/fallback)
    ('UI', 'Inferm. domiciliare UCP', '09:00:00', '17:00:00', '#99CCFF',  8.00, 12, 0,0,0,0,0,0, 'da_schema'),
    ('UO', 'OSS domiciliare UCP',     '08:00:00', '15:15:00', '#99FFCC',  7.25, 14, 0,0,0,0,0,0, 'da_schema'),
    ('Rec','Recupero',                NULL,       NULL,       '#FFD699',  8.00, 35, 0,0,0,0,0,0, 'da_schema'),
    -- Straordinari Hospice (ore base, solo manuali)
    ('Ms', 'Mattina straord.',        '07:00:00', '14:30:00', '#E6E6E6',  7.50, 11, 0,0,0,0,0,0, 'da_schema'),
    ('Ps', 'Pomeriggio straord.',     '13:45:00', '21:30:00', '#E6E6E6',  7.75, 21, 0,0,0,0,0,0, 'da_schema'),
    ('Ns', 'Notte straord.',          '21:00:00', '07:30:00', '#F0CCCC', 10.50, 31, 0,0,0,0,0,0, 'da_schema'),
    -- Assenze (contano "quanto lo schema")
    ('L',  'Lutto',                   NULL, NULL, '#D9D9D9', 7.50, 53, 0,0,1,0,0,0, 'da_schema'),
    ('CM', 'Congedo matrimoniale',    NULL, NULL, '#FFE0F0', 7.50, 56, 0,0,1,0,0,0, 'da_schema'),
    ('CP', 'Congedo paternità',       NULL, NULL, '#E0F0FF', 7.50, 57, 0,0,1,0,0,0, 'da_schema'),
    ('104','Permesso L.104',          NULL, NULL, '#FFEFC0', 7.50, 58, 0,0,1,0,0,0, 'da_schema'),
    ('INF','Infortunio',              NULL, NULL, '#FFB3B3', 7.50, 54, 0,0,0,1,0,0, 'da_schema'),
    ('PST','Permesso studio',         NULL, NULL, '#D0E8D0', 7.50, 59, 0,0,1,0,0,0, 'da_schema'),
    ('DS', 'Donazione sangue',        NULL, NULL, '#FFC0C0', 7.50, 61, 0,0,1,0,0,0, 'da_schema'),
    ('EL', 'Permesso elettorale',     NULL, NULL, '#D0D0F0', 7.50, 62, 0,0,1,0,0,0, 'da_schema'),
    -- Aspettativa: nasconde se mese intero (esclude_pianificazione) e conta 0 (deficit visibile)
    ('ASP','Aspettativa',             NULL, NULL, '#CCCCCC', 0.00, 65, 0,0,0,0,0,1, 'zero');

-- ----------------------------------------------------------------------------
-- 7. Schemi concreti + passi
-- ----------------------------------------------------------------------------
INSERT INTO schemi_turnazione (codice, nome, id_setting, famiglia, periodo_giorni, attivo) VALUES
    ('hospice_regolare',     'Hospice — ciclo regolare', (SELECT id FROM setting WHERE codice='hospice'), 'ciclico',     6, 1),
    ('hospice_solo_mattine', 'Hospice — solo mattine',   (SELECT id FROM setting WHERE codice='hospice'), 'ciclico',     6, 1),
    ('hospice_no_notti',     'Hospice — senza notti',    (SELECT id FROM setting WHERE codice='hospice'), 'ciclico',     6, 1),
    ('hospice_coordinatrice','Hospice — coordinatrice',  (SELECT id FROM setting WHERE codice='hospice'), 'settimanale', 7, 1),
    ('ucpdom_infermieri',    'UCP-DOM — infermieri',     (SELECT id FROM setting WHERE codice='ucp_dom'), 'settimanale', 7, 1),
    ('ucpdom_oss',           'UCP-DOM — OSS',            (SELECT id FROM setting WHERE codice='ucp_dom'), 'settimanale', 7, 1);

-- Risoluzione id per i passi (variabili di sessione: piu' leggibile delle subquery ripetute)
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
