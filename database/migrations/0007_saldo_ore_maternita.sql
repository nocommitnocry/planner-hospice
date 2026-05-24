-- Migrazione 0007 - saldo_ore.ore_maternita (revisione 4-sexies)
--
-- Sessione 6 (continuazione, 2026-05-24). Spec: spec-sessione-6.md §3.
--
-- Cosa fa:
--   Aggiunge saldo_ore.ore_maternita: il bucket delle ore conteggiate per le
--   assenze "tutelate / a costo" con esclude_pianificazione=1 (oggi MAT e ASP).
--   SchemaOreService gia' calcola questo bucket ('maternita') ma finora non
--   aveva dove finire nel saldo. Solo la maternita (schema_ore=maternita_8_6_0,
--   8h lun-gio / 6h ven) ci mette ore; l'aspettativa (schema_ore=zero) ci mette
--   0, cosi' il suo saldo resta = -ore_dovute (deficit visibile = costo).
--
--   saldo_mese passa a includere anche ore_maternita:
--     saldo_mese = (lavorate + ferie + permessi + malattia + formazione
--                   + maternita) - ore_dovute.
--   Effetto: la maternita a mese intero -> saldo ~ neutro (assenza tutelata,
--   non perde ore); ma la RIGA di saldo resta (le "ore perdute" non spariscono
--   e il saldo_progressivo non salta il buco). L'operatore viene nascosto dalla
--   griglia assegnabile ma tenuto nella tabella saldi (logica applicativa).

ALTER TABLE saldo_ore
    ADD COLUMN ore_maternita DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER ore_formazione;
