-- Migrazione 0005 - operatori_vincoli.creato_da
--
-- Sessione 5-bis (2026-05-23).
--
-- Cosa fa:
--   Aggiunge `operatori_vincoli.creato_da INT NULL` con FK verso `utenti(id)`
--   ON DELETE SET NULL, per coerenza col pattern `assenze.creato_da` (4-sexies).
--   Permette di mostrare l'autore del vincolo nella lista /vincoli e di
--   preservare il record se l'utente che l'ha inserito viene poi rimosso.
--
-- Contesto:
--   La tabella `operatori_vincoli` esiste dallo schema iniziale (sessione 1)
--   ma fino alla 5-bis non aveva CRUD: i record si inserivano a mano nel DB.
--   La sessione 5-bis introduce VincoliController e mette il pattern in linea
--   con AssenzeController.
--
-- Idempotenza: la ALTER non e' idempotente, applicare solo su DB allineato
-- alla migrazione 0004.

SET NAMES utf8mb4;

ALTER TABLE operatori_vincoli
    ADD COLUMN creato_da INT NULL AFTER creato_il,
    ADD CONSTRAINT fk_vincoli_creato_da
        FOREIGN KEY (creato_da) REFERENCES utenti(id) ON DELETE SET NULL;
