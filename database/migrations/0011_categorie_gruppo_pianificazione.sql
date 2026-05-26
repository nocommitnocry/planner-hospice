-- Migrazione 0011 - categorie_operatori: gruppo di pianificazione (classificazione)
--
-- Sessione 8 (2026-05-26). Aggiunge una classificazione esplicita della categoria
-- in uno dei gruppi usati per il raggruppamento del piano: infermiere | oss |
-- coordinatore | altro.
--
-- Motivo: la tabella non aveva alcun flag semantico (solo `nome` libero +
-- `ordine_visualizzazione`). La stampa PDF del piano (spec-pdf-piano-turni)
-- raggruppa gli operatori in Infermieri -> OSS -> Coordinatrice -> Altri; senza
-- una colonna dedicata bisognerebbe hardcodare il match sul `nome` (fragile e
-- anti multi-organizzazione, vedi ADR 0001). La colonna rende la classificazione
-- editabile dal CRUD categorie (solo admin).
--
-- Default 'altro' (nessuno scompare): le categorie non classificate finiscono nel
-- gruppo "Altri" della stampa. Il backfill copre i nomi noti (collation
-- utf8mb4_unicode_ci = case-insensitive); ogni categoria rimasta 'altro' va
-- rivista a mano in /categorie-operatori.
--
-- schema.sql e' allineato per i nuovi install. Le migrazioni si applicano a mano
-- (vedi docs/SESSION_NOTES.md).

ALTER TABLE categorie_operatori
    ADD COLUMN gruppo_pianificazione ENUM('infermiere', 'oss', 'coordinatore', 'altro')
        NOT NULL DEFAULT 'altro'
        COMMENT 'Gruppo per il raggruppamento del piano (stampa PDF): infermiere|oss|coordinatore|altro'
        AFTER descrizione;

-- Backfill dei seed noti. Tutto cio' che non matcha resta 'altro' (da rivedere nel CRUD).
UPDATE categorie_operatori SET gruppo_pianificazione = 'infermiere'
    WHERE nome IN ('INFERMIERE', 'INFERMIERI', 'INF');
UPDATE categorie_operatori SET gruppo_pianificazione = 'oss'
    WHERE nome IN ('OSS', 'OPERATORE SOCIO-SANITARIO');
UPDATE categorie_operatori SET gruppo_pianificazione = 'coordinatore'
    WHERE nome IN ('COORD.', 'COORD', 'COORDINATORE', 'COORDINATRICE', 'CAPOSALA');
