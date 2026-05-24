-- Migrazione 0009 - Orari notte/smonto: riallineamento al seed + abilita split tra mesi
--
-- Sessione 6 (continuazione, 2026-05-24). Contesto: implementazione di
-- "Soluzione 2" (le ore della notte seguono il calendario). Una notte a cavallo
-- di fine mese deve contare ~3,25h sul mese d'inizio (sera + vestizione) e 7,50h
-- sul mese successivo (mattino, giorno dello smonto). Lo split vive in
-- SaldoRicalcoloService e legge la coda post-mezzanotte da `tipi_turno.ora_fine`.
--
-- Problema: il DB live e il seed (schema.sql) sono DRIFTATI sugli orari della
-- notte (lo stesso fenomeno del gap #4):
--   * seed:     N 21:00->07:30 (notte intera, 10,75h) ; S senza orari (marcatore)
--   * DB live:  N 21:00->00:00 (solo la sera)         ; S 00:00->07:30
-- Con N che "finisce a 00:00" lo split calcola 0h dopo mezzanotte e non spacca
-- niente. Le ore (10,75 su N, 0 su S) sono identiche nei due modelli: cambia solo
-- come gli orari descrivono la notte.
--
-- Cosa fa: porta il DB live al modello del seed (un install fresco da schema.sql
-- e' gia' corretto). La `S` resta lo smonto esplicito a 0h/riposo (scelta di
-- Olga: lo smonto visibile e' un bene); le togliamo solo gli orari ridondanti.
-- Le ore_conteggiate/ore_effettive NON cambiano (nessun ricalcolo le deriva
-- dalla durata). Idempotente grazie alle WHERE.

-- N: la notte arriva fino alle 07:30 del giorno dopo (non si ferma a mezzanotte).
UPDATE tipi_turno SET ora_fine = '07:30:00' WHERE codice = 'N' AND ora_fine = '00:00:00';

-- S smonto: marcatore puro, senza orari (come nel seed). Nel DB live aveva
-- 00:00->07:30, fuorviante perche' sembrerebbe lavorato mentre conta 0h.
UPDATE tipi_turno SET ora_inizio = NULL, ora_fine = NULL WHERE codice = 'S';
