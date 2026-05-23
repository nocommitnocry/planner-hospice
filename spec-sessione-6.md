# Spec Sessione 6 — Generatore automatico + modello degli schemi di turnazione

> **Stato:** BOZZA v1 — rivista con Olga il 2026-05-23. Convenzione: `DA CONFERMARE` = punto ancora aperto · `confermato` = approvato da Olga.
> **Principio guida (deciso 2026-05-23):** non una demo usa-e-getta per il 28 maggio, ma
> un prodotto mantenibile. Gli schemi di turnazione sono **dati configurabili**, non `if`
> hardcoded. Quando dopo la demo una coordinatrice dirà "il giovedì in realtà facciamo X",
> si cambia un dato, non si riscrive il generatore. La demo del 28 è una tappa, non il traguardo.

---

## 1. Modello di dominio: due famiglie di schema

Dalla raccolta requisiti (`spec ore e turni sessione 6.xlsx`) emergono **due famiglie**, non un
ciclo unico:

- **Ciclico** — periodo di N giorni, basato sulla *posizione* nel ciclo. L'operatore avanza di
  una posizione al giorno; la posizione **si congela** durante le assenze (vedi §3) e, per la
  variante `no_weekend`, durante i weekend. → *Hospice infermieri/OSS*.
- **Settimanale** — periodo di 7 giorni, basato sul *giorno della settimana*. → *coordinatrice
  Hospice, tutto UCP-DOM*.

### Tabelle proposte

```
schemi_turnazione
  id              INT PK
  codice          VARCHAR(40) UNIQUE     -- 'hospice_regolare', 'hospice_solo_mattine', ...
  nome            VARCHAR(100)
  id_setting      INT FK setting          -- a quale setting appartiene
  famiglia        ENUM('ciclico','settimanale')
  periodo_giorni  TINYINT                 -- 6 per il ciclo Hospice, 7 per i settimanali
  attivo          BOOLEAN

schema_passi
  id              INT PK
  id_schema       INT FK schemi_turnazione ON DELETE CASCADE
  posizione       TINYINT                 -- 0..periodo-1 (ciclico) oppure 0=lun..6=dom (settimanale)
  id_tipo_turno   INT FK tipi_turno NULL  -- NULL = riposo non assegnato (cella vuota)
  ore_assenza     DECIMAL(5,2)            -- ore conteggiate se quel giorno è un'assenza (vedi §3)
  UNIQUE (id_schema, posizione)
```

`ore_assenza` vive sul **passo dello schema** (non sul tipo turno) proprio perché diverge dalle ore
lavorate (vestizione) e perché negli schemi settimanali UCP-DOM le ore cambiano da giorno a giorno
(8h lun-gio, 6h ven). Il `id_tipo_turno` del passo è ciò che il generatore *propone* nella cella.

> **DECISO — le ore *lavorate* vivono sul turno** (`turni.ore_effettive`, Opzione B, §6).
> `SaldoRicalcoloService` somma quel campo.

---

## 2. Catalogo schemi concreti

### Hospice

| Schema | Famiglia | Passi | Note |
|---|---|---|---|
| `hospice_regolare` | ciclico (6) | `M M P N S R` | infermieri/OSS |
| `hospice_solo_mattine` | ciclico (6) | `M M M M R R` | variante per vincolo `solo_mattine` |
| `hospice_no_notti` | ciclico (6) | `M M P P R R` | variante per vincolo `no_notti` |
| `hospice_regolare` + `no_weekend` | ciclico (6) | come `regolare`, ma **salta il weekend e riprende il lunedì dalla stessa posizione** | il vincolo `no_weekend` è un *modificatore* dello schema, non uno schema a sé |
| `hospice_coordinatrice` | settimanale (7) | `G G G G G R R` (lun-ven) | occasionalmente altri turni → corretti **a mano** dalla coordinatrice |

**Copertura minima Hospice** (vincolo che lo sfalsamento tra operatori deve garantire):

| Fascia | Presenze minime |
|---|---|
| M | 2 infermieri + 1 OSS + coordinatrice (lun-ven) |
| P | 1 infermiere + 1 OSS |
| N | 1 infermiere + 1 OSS |
| S | è la continuazione della N: stessa persona, entra con N alle 21:00 ed esce con S alle 07:30 |

### UCP-DOM

| Schema | Famiglia | Passi | Note |
|---|---|---|---|
| `ucpdom_infermieri` | settimanale (7) | lun-gio turno 09:00–17:00 (8h), ven 09:00–15:00 (6h), sab/dom R | **niente notti** |
| `ucpdom_oss` | settimanale (7) | lun-ven turno 08:00–15:15 (7,25h), sab 08:00–12:15 (4,25h), dom R | |

- **Tutti lavorano** salvo assenze giustificate (ferie, permessi, malattia, L.104, infortunio).
- **Weekend UCP-DOM**: inserito **a mano** dalla coordinatrice (tipo `Rec` = recupero). Fuori dal generatore.
- **Notti UCP-DOM**: reperibilità → serve solo un **piano vuoto**, nessuna automazione.

---

## 3. Ore: lavorate vs assenza, vestizione, conteggio

### Vestizione (deciso 2026-05-23, da confermare alla demo)

Introdotta quest'anno: aggiunge **+0,25h (15 min)** ai turni **M, P, N, G** — solo quando l'operatore
è **presente** (lavorato), non in assenza. Niente controllo separato in UI: è automatica. Olga la
conferma alla coordinatrice Hospice durante la demo.

| Tipo | Orario | Ore base | Ore **lavorate** (con vestizione) |
|---|---|---|---|
| M | 07:00–14:30 | 7,50 | **7,75** |
| P | 13:45–21:30 | 7,75 | **8,00** |
| N | 21:00–07:30 | 10,50 | **10,75** |
| G | 08:00–15:30 *(corretto)* | 7,50 | **7,75** |
| S, R | — | 0 | 0 |
| D (Hospice, manuale) | 07:00–14:30 *(corretto)* | 7,50 | 7,50 *(D non è in lista vestizione — `confermato`)* |
| UCP inf | 09–17 / ven 09–15 | 8 / 6 | 8 / 6 *('confermato' no vestizione)* |
| UCP OSS | 08–15:15 / sab 08–12:15 | 7,25 / 4,25 | 7,25 / 4,25 *('confermato' no vestizione)* |

### Ore di assenza per schema (le tabelle dell'Excel — **base, senza vestizione**)

| Schema | Ore-assenza per posizione/giorno |
|---|---|
| Hospice regolare | M→7,50 · P→7,75 · N→10,50 · S→0 · R→0 |
| Hospice coordinatrice | lun-ven 7,50 · sab/dom 0 |
| UCP infermieri | lun-gio 8 · ven 6 · sab/dom 0 |
| UCP OSS | lun-ven 7,25 · sab 4,25 · dom 0 |

### Regole di conteggio

- **Regola unica:** ogni assenza (permesso, malattia, L.104, infortunio, lutto, congedi…) conta
  **quanto la posizione di schema** di quel giorno (la tabella sopra). L'unica eccezione con tabella
  propria è la **maternità: 8h lun-gio / 6h ven / 0 weekend**.
- **Congelamento del ciclo durante l'assenza** *(deciso 2026-05-23)*: quando il generatore (continuazione,
  §5) incontra giorni di ferie/assenza, **non li conta come avanzamento del ciclo**: la posizione si
  congela e riprende dopo l'assenza. Identico meccanismo del modificatore `no_weekend`.
- **Assenze tutelate / a costo — maternità e aspettativa (deciso 2026-05-23).** Per i tipi con
  `esclude_pianificazione=1` (oggi MAT, ora anche ASP):
  - **Mese intero coperto** → l'operatore è **nascosto dalla griglia** assegnabile (non ha senso
    pianificarlo), **ma la riga di saldo resta** nella tabella saldi. Rivede la 4-sexies, che oggi lo
    toglie del tutto (niente `piano_operatori`, niente `saldo_ore`): senza la riga, le "ore perdute"
    sparirebbero e il `saldo_progressivo` salterebbe il buco.
  - **Mese parziale** → operatore **visibile**; i giorni dell'assenza in **overlay grigio** (riusa
    `.cella-in-assenza` della sessione 5), i giorni presenti restano assegnabili.
  - **Conteggio ore** (qui sta la differenza fra le due):
    - **Maternità** → schema **8/6/0** → saldo ≈ **neutro**: assenza tutelata, non perde ore.
    - **Aspettativa** → **0 ore** e `ore_dovute` **non ridotte** → `saldo_mese = -ore_dovute`: il
      **deficit** resta visibile e si accumula nel progressivo. È la "presenza statistica" che fa
      capire mesi dopo perché quell'operatore è più basso di ore (costo che la cooperativa copre con
      altri o con un'assunzione).
  - **Meccanismo:** una regola di conteggio per-tipo `tipi_turno.schema_ore` ∈
    `{ da_schema, maternita_8_6_0, zero }` instrada il calcolo. Default `da_schema` (regola unica);
    MAT → `maternita_8_6_0`; ASP → `zero`. Vedi memoria `project-conteggio-ore-assenze`.

---

## 4. Catalogo tipi turno completo

Esistenti (seed): `M P N S R F D G C MA PE`. Da aggiungere / sistemare:

| Codice | Descrizione | Tipo | Ore | Flag principali | Stato |
|---|---|---|---|---|---|
| M, P, N | Mattina/Pom./Notte | lavoro Hospice | 7,75 / 8,00 / 10,75 | — | **agg. ore (vestizione)** |
| G | Giornata (coordinatrice) | lavoro Hospice | 7,75 | — | **fix orario 08:00–15:30 + vestizione** `confermato` |
| D | Domicilio | lavoro Hospice (manuale) | 7,50 | — | **fix orario 07:00–14:30** `confermato` (no vestizione) |
| S, R | Smonto / Riposo | servizio | 0 | `is_riposo` | invariati |
| F | Ferie | assenza | da schema | `is_ferie` | invariato |
| PE | Permesso | assenza | da schema | `is_permesso` | invariato |
| MA | Malattia | assenza | da schema | `is_malattia` | invariato |
| C | Corso | formazione | 7,50 | `is_formazione` | già presente |
| MAT | Maternità | assenza | 8/6/0 | `esclude_pianificazione`? (vedi §3) | creato da UI (4-sexies) |
| **UI** | Infermiere domiciliare UCP | lavoro UCP | 8 / 6 *(var. giorno)* | — | **nuovo** · codice `confermato` |
| **UO** | OSS domiciliare UCP | lavoro UCP | 7,25 / 4,25 *(var. giorno)* | — | **nuovo** · codice `confermato` |
| **Rec** | Recupero (weekend UCP) | lavoro UCP (manuale) | `confermato` | — | **nuovo** |
| **Ms/Ps/Ns** | Straordinario Hospice | lavoro (manuale) | base + extra? | — | **nuovo** · solo manuale, fuori generatore `confermato` BASE |
| **L** | Lutto | assenza | da schema | `is_permesso` | **nuovo** |
| **CM** | Congedo matrimoniale | assenza | da schema | `is_permesso` | **nuovo** |
| **CP** | Congedo paternità | assenza | da schema | `is_permesso` | **nuovo** |
| **104** | Permesso L.104 | assenza | da schema | `is_permesso` | **nuovo** · codice `confermato` |
| **INF** | Infortunio | assenza | da schema | `is_malattia` | **nuovo** · codice `confermato` |
| **ASP** | Aspettativa | assenza | **0** | `esclude_pianificazione` + `schema_ore=zero` (vedi §3) | **nuovo** · `confermato` |
| **PST** | Permesso studio | assenza | da schema | `is_permesso` | **nuovo** |
| **DS** | Donazione sangue | assenza | da schema | `is_permesso` | **nuovo** |
| **EL** | Permesso elettorale (scrutatori) | assenza | da schema | `is_permesso` | **nuovo** · codice e regola `confermato` |

> Colori e priorità da assegnare. I codici proposti (UI, UO, 104, INF, ASP, PST, DS, EL) sono mie
> proposte: correggili come preferisci.

---

## 5. Il generatore

> **Deciso 2026-05-23:** l'unico generatore è l'**Automatismo 2** (continuazione). L'Automatismo 1
> "schema fisso + sfalsamento" è **abbandonato**: non esiste il caso "nessun mese precedente", perché
> il primo piano si crea **vuoto** e si riporta a mano l'ultima mensilità reale utile, che diventa il
> punto di partenza della continuazione.

### Bootstrap — primo piano di un setting

Si crea un **piano vuoto** (la `create` attuale: piano + saldi, zero turni, valida anche per mesi
passati) e si trascrive a mano il pregresso reale. Pubblicato quello, l'Automatismo 2 ci si aggancia.
Nessuna generazione automatica del primo piano. La **copertura minima** del §2 non è più input del
generatore: diventa un **controllo/avviso a posteriori** sul piano prodotto.

### Automatismo 2 — Continuazione dal mese precedente pubblicato - concordato nella chat con Claude principio della Soluzione 2: "la notte è un intervallo, le ore seguono il calendario".

Per ogni operatore: leggi gli ultimi giorni del piano del **mese precedente, stesso setting,
pubblicato**; individua la posizione nel ciclo sull'**ultimo turno regolare** (scivolando indietro
oltre ferie/assenze/turni irregolari da sostituzione, che **congelano** il ciclo); prosegui dal giorno 1.

**Casi limite → lista "da assegnare a mano"** (mai scelta arbitraria):
- operatore in ferie/malattia tutto il mese precedente;
- operatore non presente il mese precedente (assunzione, rientro maternità, spostamento lungo);
- mese precedente assente/non pubblicato (primo piano in assoluto del setting) → l'Automatismo 2 non parte: si crea un **piano vuoto** e si riporta a mano il pregresso (vedi "Bootstrap" sopra).

### Vincoli operatori → varianti di schema (non bloccanti)

I `operatori_vincoli` orientano la **proposta**, non bloccano la modifica successiva:
- `solo_mattine` → schema `hospice_solo_mattine`;
- `no_notti` → schema `hospice_no_notti`;
- `no_weekend` → modificatore "salta weekend e congela" sullo schema regolare.

### Tracciabilità e UI

- Ogni popolamento = N turni in **transazione**; log in `log_modifiche` con metadata (algoritmo,
  operatori coinvolti, operatori finiti in lista manuale).
- UI: blocco "Popolamento iniziale" nella `show` del piano in **bozza** con due bottoni
  ("Schema fisso" / "Continua dal mese precedente"). Nessuna esecuzione automatica alla create.

---

## 6. Decisione architetturale aperta — dove vivono le ore lavorate?

Le ore lavorate divergono dalle ore-assenza (vestizione) e variano per giorno negli schemi UCP
(8h lun-gio, 6h ven). Un singolo `tipi_turno.ore_conteggiate` costante non basta. Due opzioni:

- **Opzione A — ore sul tipo turno.** Servono più tipi (es. un tipo UCP-inf-8h e uno UCP-inf-6h).
  `SaldoRicalcoloService` resta com'è (somma `tipo.ore_conteggiate`). Più tipi turno, calcolo invariato.
- **Opzione B — ore effettive sul turno** (`turni.ore_effettive`). Il generatore/inserimento calcola e
  *salva* le ore di quel turno specifico; `SaldoRicalcoloService` somma `turni.ore_effettive` (fallback a
  `tipo.ore_conteggiate` per i turni vecchi). Pochi tipi, calcolo più robusto, ma tocca tabella `turni` e
  il service. **Propendo per B**: regge vestizione, ore variabili per giorno e casi speciali con un solo
  numero per turno, ed è la fondazione più pulita per il futuro.

> **DECISO (2026-05-23): Opzione B.** Le ore lavorate vivono su `turni.ore_effettive`;
> `SaldoRicalcoloService` somma quel campo. Per la Soluzione 2 (notte) il service ripartisce le ore
> notturne tra i mesi leggendo `ora_inizio`/`ora_fine` del turno.

---

## 7. Stato delle decisioni

**Decise (2026-05-23):**
- Architettura ore → **Opzione B** (`turni.ore_effettive`).
- Notte → **Soluzione 2** ("la notte è un intervallo, le ore seguono il calendario").
- Generatore → **solo Automatismo 2** (continuazione); bootstrap = piano vuoto + pregresso a mano.
- Maternità/aspettativa → modello §3 (nascosto se mese intero ma **saldo preservato**; 8/6/0 vs 0+deficit).
- Tipi e codici: `UI`, `UO`, `Rec` (8h), `Ms/Ps/Ns` (ore base, solo manuali), `104`, `INF`, `ASP` (0h),
  `PST`, `DS`, `EL`. `D` senza vestizione (07:00–14:30). `G` 08:00–15:30 con vestizione. Straordinari e
  `Rec` solo manuali, fuori generatore.

**Ancora aperto:**
- **Vestizione**: da confermare con la coordinatrice **alla demo** (assunto: solo sul lavorato, M/P/N/G,
  non D). Isolata: se cambia, si tocca un punto solo.
- **Colori e priorità** dei tipi turno nuovi: da assegnare (cosmetico).
- **Bug estetico (notato 2026-05-23, da fare)**: il `tipo_colore` non viene applicato alle celle del
  calendario nella `show` (né bozza né pubblicato). Da indagare nel rendering cella di
  `views/piani_turno/show.twig` e/o `public/css/app.css`. NB: `M`/`P` nel seed sono bianchi, ma Olga
  segnala il problema come generale.
- **Verifiche rinviate a domani**: confronto somme del mese e saldi progressivi sui turni generati.

---

## 8. Sequenza di implementazione proposta

1. **Migration `0006`**: tabelle `schemi_turnazione` + `schema_passi`; `turni.ore_effettive`
   DECIMAL NULL (Opz. B); `tipi_turno.schema_ore` (`da_schema|maternita_8_6_0|zero`); seed dei tipi
   turno nuovi (UI, UO, Rec, Ms/Ps/Ns, L, CM, CP, 104, INF, ASP, PST, DS, EL); fix orari `D`/`G`;
   bump ore lavorate M/P/N/G (vestizione); seed degli schemi concreti (§2). `schema.sql` allineato.
2. **Model**: `SchemaTurnazioneModel`, `SchemaPassoModel`; estensione `TipoTurnoModel` (`schema_ore`).
3. **Service**:
   - `SchemaOreService` — conteggio ore: regola "quanto lo schema" + `maternita_8_6_0` + `zero`,
     con **congelamento** del ciclo durante le assenze.
   - `GeneratoreService` — **Automatismo 2** (continuazione): posizione dal mese precedente pubblicato,
     freeze su assenze/irregolari, lista "da assegnare a mano" per i casi limite.
   - `SaldoRicalcoloService` — adattato a Opz. B (somma `ore_effettive`) e alla Soluzione 2 (split
     mezzanotte/mese per i turni notturni, da `ora_inizio`/`ora_fine`).
4. **Revisione 4-sexies**: maternità/aspettativa → modello §3 (nascosto se mese intero **ma saldo
   preservato**; overlay sui giorni se parziale). Tocca `PianiTurnoController::store` e la `show`.
5. **Controller**: azione "Continua dal mese precedente" sotto `/piani-turno/{id}` (solo bozza,
   admin+caposala), in transazione, con log in `log_modifiche`.
6. **UI**: bottone "Continua dal mese precedente" nella `show` del piano in bozza + box "da assegnare
   a mano"; avviso copertura minima (§2) a posteriori.
7. **Test manuali**: come da pattern delle sessioni precedenti, in coda alla spec a fine sessione.
