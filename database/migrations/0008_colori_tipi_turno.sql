-- Migrazione 0008 - Palette colori tipi turno (allineamento)
--
-- Sessione 6 (continuazione, 2026-05-24). Contesto: i colori dei tipi turno
-- vengono ora resi come classi CSS (.tt-bg-{id}) servite da
-- /assets/tipi-turno-colori, perché la CSP (style-src 'self') blocca gli
-- attributi style inline. Perché si vedano servono colori NON bianchi.
--
-- Cosa fa: porta alla palette canonica i tipi che nel seed originale erano
-- bianchi o poco distinguibili, e dà un colore a G (turno coordinatrice) e DV
-- (domicilio venerdì), che erano rimasti #FFFFFF (invisibili in griglia).
-- I valori M/P/N/S/F coincidono con quelli gia' scelti via UI nel DB di Olga:
-- su quel DB l'UPDATE e' un no-op tranne G e DV. Su un DB ricostruito dalle
-- migrazioni allinea tutto.

UPDATE tipi_turno SET colore = '#8FF0A4' WHERE codice = 'M';   -- verde
UPDATE tipi_turno SET colore = '#99C1F1' WHERE codice = 'P';   -- blu
UPDATE tipi_turno SET colore = '#FFBE6F' WHERE codice = 'N';   -- arancio
UPDATE tipi_turno SET colore = '#FFBE6F' WHERE codice = 'S';   -- arancio (smonto della notte)
UPDATE tipi_turno SET colore = '#F9F06B' WHERE codice = 'F';   -- giallo (ferie)
UPDATE tipi_turno SET colore = '#FFE08A' WHERE codice = 'G';   -- oro (coordinatrice) — era bianco
UPDATE tipi_turno SET colore = '#BDE0FE' WHERE codice = 'DV';  -- celeste (domicilio venerdì) — era bianco
