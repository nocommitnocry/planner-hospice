-- Migrazione 0010 - tipi_turno: setting di pertinenza + soft-disable; ritiro D, rimozione DV
--
-- Sessione 7 (2026-05-25). Due aggiunte e una pulizia:
--
-- 1) tipi_turno.id_setting (FK setting, NULL = vale per ENTRAMBI i setting):
--    permette a "assegna turno" di mostrare solo i tipi pertinenti al setting del
--    piano (Hospice: M/P/N/S/G + straordinari MS/PS/NS; UCP-DOM: UI/UO). R, Rec, C
--    e tutte le assenze restano NULL = condivisi.
--
-- 2) tipi_turno.attivo (soft-disable, stesso pattern di operatori/utenti): un tipo
--    ritirato non compare piu' in nessuna griglia, ma i turni storici che lo
--    referenziano continuano a vedersi (la FK RESTRICT non viene toccata).
--
-- 3) Prestiti Hospice -> UCP-DOM: d'ora in poi si gestiscono aggiungendo
--    l'operatore "in prestito" al piano UCP-DOM (flusso 4-ter) con i codici propri
--    UCP-DOM (UI/UO); in Hospice compaiono in overlay cross-setting (4-quater). I
--    codici 'D' (Domicilio) e 'DV', usati impropriamente nel piano Hospice, non
--    servono piu':
--      - 'D' ha 47 turni nel piano Aprile 2026 -> lo RITIRIAMO (attivo=0): Aprile
--        resta leggibile, ma D non e' piu' assegnabile.
--      - 'DV' non e' referenziato da nulla (0 turni/assenze/passi) -> lo CANCELLIAMO.
--
-- schema.sql e' allineato per i nuovi install. Le migrazioni si applicano a mano
-- (vedi docs/SESSION_NOTES.md).
-- NB: la collation e' utf8mb4_unicode_ci (case-insensitive), quindi gli UPDATE per
-- codice matchano anche le varianti maiuscole/minuscole (es. 'MS' matcha 'Ms').

ALTER TABLE tipi_turno
    ADD COLUMN attivo BOOLEAN NOT NULL DEFAULT TRUE AFTER schema_ore,
    ADD COLUMN id_setting INT NULL
        COMMENT 'Setting di pertinenza per "assegna turno"; NULL = entrambi' AFTER attivo,
    ADD CONSTRAINT fk_tipo_setting FOREIGN KEY (id_setting)
        REFERENCES setting(id) ON DELETE RESTRICT;

-- Pertinenza setting dei tipi di LAVORO (le assenze + R/Rec/C restano NULL = entrambi).
UPDATE tipi_turno SET id_setting = (SELECT id FROM setting WHERE codice = 'hospice')
    WHERE codice IN ('M', 'P', 'N', 'S', 'G', 'MS', 'PS', 'NS');
UPDATE tipi_turno SET id_setting = (SELECT id FROM setting WHERE codice = 'ucp_dom')
    WHERE codice IN ('UI', 'UO');

-- Ritiro 'D' (Domicilio): non piu' assegnabile; i 47 turni di Aprile restano leggibili.
UPDATE tipi_turno SET attivo = 0 WHERE codice = 'D';

-- Rimozione 'DV' (non referenziato da turni/assenze/schema_passi).
DELETE FROM tipi_turno WHERE codice = 'DV';
