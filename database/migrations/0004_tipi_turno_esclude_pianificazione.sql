-- Migrazione 0004 - Flag esclude_pianificazione sui tipi turno
--
-- Sessione 4-sexies (2026-05-20).
--
-- Cosa fa:
--   Aggiunge `tipi_turno.esclude_pianificazione BOOLEAN NOT NULL DEFAULT FALSE`.
--   Marca i tipi turno che rappresentano interdizioni dalla pianificazione
--   (caso d'uso primario: maternità). Quando il fotografa-operatori della
--   `PianiTurnoController::store` costruisce la lista degli operatori del
--   piano, esclude chi ha un'assenza con un tipo turno avente questo flag
--   attivo che copre l'INTERO mese del piano
--   (data_inizio <= primo_del_mese AND data_fine >= ultimo_del_mese).
--
-- Perche' un flag e non uno stato dedicato sull'operatore:
--   La verita' dello stato di interdizione vive nella tabella `assenze` -
--   un solo modello, un solo punto da aggiornare quando la situazione cambia
--   (vedi memoria project-operatori-stati-assenze). Il flag sul tipo_turno
--   permette di marcare ulteriori interdizioni in futuro (es. aspettativa)
--   senza nuove migrazioni.
--
-- Niente seed forzato di un tipo "MAT": Olga lo crea da /tipi-turno con il
-- flag attivo. Coerente col principio "niente automatismi opachi" e con la
-- gestione libera dei codici (F/PE/MA gia' creati a mano).
--
-- Idempotenza: la ALTER non e' idempotente, applicare solo su DB allineato
-- alla migrazione 0003.

SET NAMES utf8mb4;

ALTER TABLE tipi_turno
    ADD COLUMN esclude_pianificazione BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'Se 1, gli operatori con assenza di questo tipo che copre l''intero mese non vengono fotografati nel piano (es. maternita)'
        AFTER is_formazione;
