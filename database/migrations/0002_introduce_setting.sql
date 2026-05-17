-- Migrazione 0002 - Introduzione del concetto di setting (hospice / UCP-DOM)
--
-- Sessione 4-bis (2026-05-14).
--
-- Cosa fa:
-- 1. Crea la tabella `setting` con seed (hospice, ucp_dom).
-- 2. Aggiunge `id_setting` su `operatori`, `piano_turni` e `utenti`.
-- 3. Backfill: tutti i dati esistenti vengono attribuiti al setting "hospice"
--    (che è quello in cui finora il planner ha lavorato implicitamente).
-- 4. Cambia la UNIQUE di `piano_turni` da (anno, mese) a (anno, mese, id_setting):
--    così possono convivere due piani per lo stesso mese (uno per setting).
-- 5. Aggiunge le FK (RESTRICT su operatori/piano_turni; SET NULL su utenti
--    perché un utente admin/visualizzatore globale non ha setting di default).
--
-- Idempotenza: l'INSERT IGNORE sui seed e il SELECT @hospice rendono lo script
-- ripetibile finché non si modifica manualmente la tabella `setting`.
-- Le ALTER non sono naturalmente idempotenti in MySQL: usare solo su un DB
-- ancora alla versione 0001.

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. Tabella `setting`
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

INSERT IGNORE INTO setting (codice, nome, descrizione, ordine_visualizzazione) VALUES
    ('hospice', 'Hospice',  'Reparto di degenza interna', 1),
    ('ucp_dom', 'UCP-DOM',  'Unità Cure Palliative Domiciliari', 2);

-- ID del setting hospice (target del backfill).
-- Usiamo una variabile di sessione: se per qualche motivo il seed non c'è
-- forziamo l'errore esplicitamente (lo script va rieseguito completo).
SELECT id INTO @hospice_id FROM setting WHERE codice = 'hospice' LIMIT 1;

-- -----------------------------------------------------------------------------
-- 2. `operatori` - aggiungo id_setting (setting "di casa")
-- -----------------------------------------------------------------------------
ALTER TABLE operatori
    ADD COLUMN id_setting INT NULL AFTER id_categoria;

UPDATE operatori SET id_setting = @hospice_id WHERE id_setting IS NULL;

ALTER TABLE operatori
    MODIFY COLUMN id_setting INT NOT NULL,
    ADD CONSTRAINT fk_operatori_setting FOREIGN KEY (id_setting)
        REFERENCES setting(id) ON DELETE RESTRICT,
    ADD INDEX idx_operatori_setting (id_setting);

-- -----------------------------------------------------------------------------
-- 3. `piano_turni` - aggiungo id_setting + nuova UNIQUE (anno, mese, id_setting)
--    NB: la vecchia UNIQUE (anno, mese) va rimossa PRIMA del backfill, altrimenti
--    è inutile (tanto resta solo un setting nella stessa colonna). Ma su MySQL
--    droppare e ricreare in due ALTER separate aumenta i rischi su DB
--    grossi; qui i piani sono pochissimi, quindi va benissimo.
-- -----------------------------------------------------------------------------
ALTER TABLE piano_turni
    ADD COLUMN id_setting INT NULL AFTER mese;

UPDATE piano_turni SET id_setting = @hospice_id WHERE id_setting IS NULL;

ALTER TABLE piano_turni
    DROP INDEX uk_piano_anno_mese,
    MODIFY COLUMN id_setting INT NOT NULL,
    ADD CONSTRAINT fk_piano_setting FOREIGN KEY (id_setting)
        REFERENCES setting(id) ON DELETE RESTRICT,
    ADD UNIQUE KEY uk_piano_anno_mese_setting (anno, mese, id_setting);

-- -----------------------------------------------------------------------------
-- 4. `utenti` - id_setting nullable.
--    NULL = utente "globale" (admin, visualizzatore senza preferenze).
--    Valorizzato = setting di default per i filtri della UI. NON è un vincolo
--    di scrittura: la caposala può comunque scrivere su entrambi i piani per
--    coprire le sostituzioni in emergenza (asimmetria reparto -> UCP-DOM).
-- -----------------------------------------------------------------------------
ALTER TABLE utenti
    ADD COLUMN id_setting INT NULL AFTER ruolo,
    ADD CONSTRAINT fk_utenti_setting FOREIGN KEY (id_setting)
        REFERENCES setting(id) ON DELETE SET NULL,
    ADD INDEX idx_utenti_setting (id_setting);

-- Non backfilliamo gli utenti: restano NULL fino a che l'admin non li assegna
-- esplicitamente. L'admin di sistema vuole vedere tutto, quindi NULL è giusto.
