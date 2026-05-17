-- Migrazione 0003 - Operatori in itinere + saldi editabili
--
-- Sessione 4-ter (2026-05-17).
--
-- Cosa fa:
-- 1. Aggiunge `data_assunzione` e `data_cessazione` (DATE NULL) su `operatori`.
--    Informativi: la coordinatrice vede "fino a quando è qui" e il fotografa-
--    operatori del piano filtra automaticamente chi è già cessato o non ancora
--    assunto rispetto al mese del piano. Nessun pro-rata automatico delle ore
--    contrattuali: la riduzione resta a mano (vedi flusso "Modifica saldo").
--
-- 2. Tabella `piano_operatori`: traccia esplicitamente quali operatori sono
--    inclusi in quale piano. Fino alla 4-bis l'appartenenza era implicita
--    (operatori "di casa" nel setting). Con la 4-ter possiamo aggiungere
--    al piano anche operatori dell'altro setting (spostamenti lunghi, doppi
--    ruoli) e operatori assunti infra-mese.
--
--    Backfill: ogni saldo esistente (anno, mese) viene associato al piano
--    dello stesso (anno, mese) il cui setting coincide col setting "di casa"
--    dell'operatore al momento della migrazione. Aggiunto_manualmente = 0
--    per il backfill (sono "fotografati" dalla create del piano).
--
-- 3. Tabella `saldo_modifiche`: storico delle modifiche manuali a ore_dovute
--    e saldo_progressivo, con utente, valori prima/dopo e nota motivazione
--    (obbligatoria a livello applicativo). Serve come audit tracciabile per
--    le coordinatrici.
--
-- Idempotenza: le CREATE TABLE sono IF NOT EXISTS; le ALTER no, quindi
-- eseguire solo su un DB già allineato alla 0002.

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. operatori: date di assunzione/cessazione (informative)
-- -----------------------------------------------------------------------------
ALTER TABLE operatori
    ADD COLUMN data_assunzione DATE NULL AFTER ore_contrattuali_mensili,
    ADD COLUMN data_cessazione DATE NULL AFTER data_assunzione,
    ADD INDEX idx_operatori_cessazione (data_cessazione);

-- -----------------------------------------------------------------------------
-- 2. Tabella piano_operatori (appartenenza esplicita)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS piano_operatori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_piano INT NOT NULL,
    id_operatore INT NOT NULL,
    aggiunto_manualmente BOOLEAN NOT NULL DEFAULT FALSE COMMENT '0=fotografato dalla create, 1=aggiunto in itinere',
    aggiunto_da INT NULL COMMENT 'utente che ha aggiunto l''operatore (NULL per fotografati)',
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

-- Backfill: ogni saldo_ore esistente (op, anno, mese) viene associato al piano
-- dello stesso (anno, mese) il cui setting coincide col setting di casa dell'op.
-- Fino alla 4-bis l'invariante era che esistesse il saldo SSE l'op era "di casa";
-- quindi il join 1:1 sotto popola correttamente piano_operatori per i piani
-- preesistenti.
INSERT IGNORE INTO piano_operatori (id_piano, id_operatore, aggiunto_manualmente, aggiunto_da)
SELECT p.id, s.id_operatore, 0, NULL
FROM piano_turni p
JOIN saldo_ore s ON s.anno = p.anno AND s.mese = p.mese
JOIN operatori o ON o.id = s.id_operatore
WHERE o.id_setting = p.id_setting;

-- -----------------------------------------------------------------------------
-- 3. Tabella saldo_modifiche (storico modifiche manuali al saldo)
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
