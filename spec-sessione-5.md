# Specifica sessione 5 — Check assenze sovrapposte ai turni

## Contesto

La 4-sexies ha introdotto la CRUD `assenze` ma il `TurniController` non ne sa nulla: una coordinatrice può oggi assegnare un turno a un operatore in ferie, malattia o maternità. Va aggiunto un check.

**Decisione di dominio (2026-05-21):** le **assenze programmate vincono sempre**. Non si può inserire un nuovo turno in un giorno coperto da un'assenza dell'operatore. Se l'utente vuole davvero quel turno, deve prima modificare il periodo di assenza (o eliminarla).

**Simmetria col pattern "fuori finestra" della 4-quinquies:** `update` e `destroy` di turni **esistenti** che si trovano sovrapposti a un'assenza creata DOPO restano permessi. Servono a permettere alla coordinatrice di pulire i turni preesistenti dopo aver inserito un'assenza retroattiva. Nessun cleanup automatico.

**Cosa NON entra in questa sessione**

- I **vincoli operatori** (`no_notti`, `no_weekend`, `solo_mattine`) restano warning informativi non bloccanti, come oggi. La CRUD `operatori_vincoli` + il warning leggibile nel form turno va nella **sessione 5-bis**. Motivo: i vincoli sono input del generatore (sessione 6), non check runtime; la coordinatrice deve poter derogare quando esiste un accordo di copertura per carenza personale. Vedi memoria `project-vincoli-operatori`.
- Niente migration DB: la tabella `assenze` esiste già dalla 4-sexies.
- Niente nuove rotte.

## Modifiche richieste

### 1. `AssenzaModel::findAttivaPerOperatoreData(idOp, data)`

**File**: `src/Models/AssenzaModel.php`

Nuovo metodo. Ritorna l'assenza che copre quella data per quell'operatore, o `null`. Se ce ne sono più (caso teorico di assenze sovrapposte) ne ritorna una qualsiasi: per il check serve solo "c'è o no" + informazioni per il messaggio user-friendly.

```php
/**
 * Ritorna l'assenza dell'operatore che copre la data indicata, o null.
 *
 * @return array{
 *   id: int,
 *   id_operatore: int,
 *   id_tipo_turno: int,
 *   data_inizio: string,
 *   data_fine: string,
 *   note: ?string,
 *   tipo_codice: string,
 *   tipo_descrizione: string,
 * }|null
 */
public function findAttivaPerOperatoreData(int $idOperatore, string $data): ?array
```

Query: JOIN `tipi_turno` per esporre `tipo_codice` + `tipo_descrizione` da inserire nel messaggio. Confronto lessicografico (==cronologico) su stringa `Y-m-d` come in 4-quinquies/4-sexies.

```sql
SELECT a.id, a.id_operatore, a.id_tipo_turno, a.data_inizio, a.data_fine, a.note,
       tt.codice AS tipo_codice, tt.descrizione AS tipo_descrizione
FROM assenze a
JOIN tipi_turno tt ON tt.id = a.id_tipo_turno
WHERE a.id_operatore = :id_op
  AND a.data_inizio <= :data_lo
  AND a.data_fine   >= :data_hi
LIMIT 1
```

PDO: due placeholder distinti per la data (`:data_lo`, `:data_hi`) con lo stesso valore — coerente con la regola "no named placeholder riusati" (vedi memoria `feedback-real-user-pressure`).

### 2. `AssenzaModel::listAttiveInPeriodo($idOperatori, $dataInizio, $dataFine)`

**File**: `src/Models/AssenzaModel.php`

Nuovo metodo per il rendering del calendario: una sola query che ritorna tutte le assenze che si sovrappongono almeno parzialmente al periodo `[dataInizio, dataFine]` per il set di operatori del piano. Evita N query nel loop del Twig.

```php
/**
 * Assenze degli operatori indicati che si sovrappongono al periodo
 * [dataInizio, dataFine] anche parzialmente. Ordinate per (id_operatore, data_inizio).
 *
 * @param list<int> $idOperatori
 * @return list<array{
 *   id_operatore: int,
 *   data_inizio: string,
 *   data_fine: string,
 *   tipo_codice: string,
 *   tipo_descrizione: string,
 * }>
 */
public function listAttiveInPeriodo(array $idOperatori, string $dataInizio, string $dataFine): array
```

Implementazione: se `$idOperatori` è vuoto ritorna `[]` subito (evita SQL invalido `IN ()`). Altrimenti costruzione di placeholder posizionali `?,?,?…` per la `IN (...)` + due `?` per le date (regola "no named riusati" → meglio posizionali quando c'è `IN` variabile, vedi 4-ter `deleteByAnnoMeseEscludendoOperatori`).

```sql
SELECT a.id_operatore, a.data_inizio, a.data_fine,
       tt.codice AS tipo_codice, tt.descrizione AS tipo_descrizione
FROM assenze a
JOIN tipi_turno tt ON tt.id = a.id_tipo_turno
WHERE a.id_operatore IN (?,?,?...)
  AND a.data_inizio <= ?   -- dataFineMese
  AND a.data_fine   >= ?   -- dataInizioMese
ORDER BY a.id_operatore, a.data_inizio
```

### 3. Helper `TurniController::messaggioAssenza`

**File**: `src/Controllers/TurniController.php`

Nuovo metodo privato gemello di `messaggioFuoriFinestra` (4-quinquies):

```php
/**
 * Ritorna un messaggio user-friendly se la data del turno cade dentro
 * un'assenza dell'operatore, null altrimenti.
 */
private function messaggioAssenza(int $idOperatore, string $dataTurno): ?string
```

Implementazione: chiama `AssenzaModel::findAttivaPerOperatoreData`. Se trovata, formatta:

```
L'operatore è in assenza dal {dd/mm/yyyy} al {dd/mm/yyyy}
({tipo_codice} {tipo_descrizione}). Modifica il periodo di assenza
se è sbagliato, o scegli un altro giorno.
```

(Helper di formattazione date già disponibili in `BaseController`/Twig — usare quello consistente con `messaggioFuoriFinestra`.)

Iniettare `AssenzaModel` nel costruttore di `TurniController` (al momento non c'è). Aggiungere il campo + inizializzazione coerente col pattern già usato per gli altri model.

### 4. Integrazione in `TurniController::validateRiferimenti`

**File**: `src/Controllers/TurniController.php`

Dopo il check `messaggioFuoriFinestra` (già esistente), aggiungere il check `messaggioAssenza`. La struttura attuale del metodo ritorna errori sulla chiave `data`; manteniamo la stessa convenzione:

```php
// dopo il check messaggioFuoriFinestra
$msgAssenza = $this->messaggioAssenza($idOperatore, $dataTurno);
if ($msgAssenza !== null) {
    $errors['data'][] = $msgAssenza;
    return $errors;
}
```

Il flusso `store` raccoglie l'errore, redirige al form con flash + old input via `redirectAlForm` (pattern già consolidato).

**`update` e `destroy`: nessuna modifica.** Continuano a funzionare anche se il turno è in conflitto: servono al cleanup retroattivo.

### 5. `TurniController::edit` — alert per turno esistente in assenza

**File**: `src/Controllers/TurniController.php` — metodo `edit`

Calcolare `$inAssenza = $this->messaggioAssenza($idOperatore, $dataTurno)` e passarlo alla view (variabile Twig `inAssenza`), accanto al già esistente `fuoriFinestra`.

Vale **sia** quando si apre il form per modificare un turno esistente (`?operatore=X&data=Y` punta a un turno esistente), **sia** quando si apre il form per creare un nuovo turno (lì il messaggio anticipa il rifiuto al submit).

### 6. Alert nel form

**File**: `views/turni/form.twig`

Subito dopo l'alert `fuoriFinestra`, aggiungere il blocco `inAssenza`. Lo stile è `alert-warning` (giallo) quando il turno esistente è già in conflitto (si può modificare o eliminare), `alert-danger` (rosso) quando si sta creando un nuovo turno in giorno coperto (il submit fallirà). Il pattern segue il blocco `fuoriFinestra` esistente.

```twig
{% if inAssenza %}
    <div class="alert alert-{{ turno ? 'warning' : 'danger' }} small">
        <strong>{{ turno ? 'Turno in periodo di assenza.' : 'Operatore in assenza.' }}</strong>
        {{ inAssenza }}
        {% if turno %}
            Il turno esistente può essere modificato o eliminato, ma non se ne possono creare di nuovi in date coperte da assenza.
        {% else %}
            L'assegnazione di un nuovo turno verrà rifiutata. Modifica il periodo di assenza in <a href="{{ url('/assenze') }}">/assenze</a> se è sbagliato.
        {% endif %}
    </div>
{% endif %}
```

### 7. Overlay nel calendario del piano

**File**: `src/Controllers/PianiTurnoController.php` — metodo `show`

Costruire `assenzeByOp[idOperatore] = list<assenza>` dalla `AssenzaModel::listAttiveInPeriodo` per gli operatori in `piano_operatori` del piano corrente, sull'intervallo `[primo_del_mese, ultimo_del_mese]`. Passarla alla view.

```php
$idOperatori = array_map(static fn ($r) => (int) $r['id_operatore'], $operatoriDelPiano);
$dataInizio = sprintf('%04d-%02d-01', $anno, $mese);
$dataFine   = (new \DateTimeImmutable($dataInizio))->modify('last day of this month')->format('Y-m-d');
$assenzeRows = $this->assenze->listAttiveInPeriodo($idOperatori, $dataInizio, $dataFine);

$assenzeByOp = [];
foreach ($assenzeRows as $r) {
    $assenzeByOp[(int) $r['id_operatore']][] = $r;
}
```

(Iniettare `AssenzaModel` nel costruttore — già presente solo nel `AssenzeController` e nel `PianiTurnoController::store` come `$this->assenze`, da verificare: se in `PianiTurnoController` il campo esiste già dalla 4-sexies basta riusarlo.)

**File**: `views/piani_turno/show.twig`

Nuovo ramo cella **dopo** `cross-setting` e `fuori-finestra`, **prima** del ramo `+`. Precedenza nel rendering della cella (dall'alto):

1. `turno` esistente nel piano corrente → cella turno (con eventuale bordo rosso `.cella-conflitto-assenza` se è in periodo di assenza).
2. `cross` → `.cella-cross-setting`.
3. `fuoriFinestra` (data fuori da assunzione/cessazione) → `.cella-fuori-finestra`.
4. `inAssenza` (data dentro un'assenza dell'op) → **nuovo** `.cella-in-assenza`.
5. cella editabile → link `+`.
6. cella read-only → spazio vuoto.

Per il ramo nuovo (cella vuota dentro un'assenza):

```twig
{% set assenzaCorrente = null %}
{% if not turno and not cross and not fuoriFinestra %}
    {% for a in assenzeByOp[op.id_operatore] | default([]) %}
        {% if a.data_inizio <= g.date and a.data_fine >= g.date %}
            {% set assenzaCorrente = a %}
        {% endif %}
    {% endfor %}
{% endif %}
{% if assenzaCorrente %}
    <td class="cella-in-assenza"
        title="{{ assenzaCorrente.tipo_codice }} · {{ assenzaCorrente.tipo_descrizione }} — dal {{ format_date(assenzaCorrente.data_inizio) }} al {{ format_date(assenzaCorrente.data_fine) }}">
        <span class="assenza-codice">{{ assenzaCorrente.tipo_codice }}</span>
    </td>
{% elseif ... }
    {# resto della cascata #}
{% endif %}
```

Per il bordo rosso sui turni esistenti in conflitto (ramo 1 con flag aggiuntivo):

```twig
{% set conflittoAssenza = false %}
{% if turno %}
    {% for a in assenzeByOp[op.id_operatore] | default([]) %}
        {% if a.data_inizio <= g.date and a.data_fine >= g.date %}
            {% set conflittoAssenza = true %}
        {% endif %}
    {% endfor %}
{% endif %}
<td class="cella-turno {{ conflittoAssenza ? 'cella-conflitto-assenza' : '' }}" ...>
```

(L'iteratore Twig sulle assenze dell'op è O(k) con k = numero assenze dell'op che si sovrappongono al mese — di norma 0-2. Niente preoccupazioni di performance.)

### 8. CSS

**File**: `public/css/app.css`

```css
.cella-in-assenza {
    background-image: repeating-linear-gradient(
        0deg,                /* orizzontale, distinto dal 135° di fuori-finestra */
        transparent 0 3px,
        rgba(108, 117, 125, .18) 3px 6px
    );
    background-color: #f8f9fa;
    cursor: not-allowed;
    text-align: center;
    vertical-align: middle;
    font-size: 0.85em;
    color: #6c757d;
    font-style: italic;
}
.cella-in-assenza .assenza-codice {
    font-weight: 600;
    opacity: 0.7;
}
.cella-conflitto-assenza {
    box-shadow: inset 0 0 0 2px #dc3545; /* bordo rosso interno */
}
```

Note:
- `.cella-fuori-finestra` usa retino diagonale 135° (dalla 4-quinquies).
- `.cella-cross-setting` usa banda laterale colorata (dalla 4-quinquies).
- `.cella-in-assenza` usa retino orizzontale (0deg) — terza decorazione visivamente distinta.
- `.cella-conflitto-assenza` si sovrappone a `.cella-turno` esistente con un bordo rosso interno, non un retino (la cella è già piena del colore del tipo turno).

### 9. Banner dashboard

**File**: `views/dashboard/index.twig`

Aggiornare il banner a "sessione 5".

## Decisioni di sessione (da trascrivere in `docs/SESSION_NOTES.md` a fine sessione)

| Punto | Scelta |
|---|---|
| Assenze vs nuovo turno | **Block** in `TurniController::store`. Le assenze programmate vincono sempre. Messaggio user-friendly con periodo `dd/mm/yyyy → dd/mm/yyyy` + tipo + suggerimento "modifica il periodo di assenza o scegli un altro giorno" |
| Assenze vs turno esistente | **Non block** in `update`/`destroy`. Simmetria col pattern fuori-finestra (4-quinquies): permette cleanup retroattivo dopo creazione di un'assenza che copre turni preesistenti |
| Visualizzazione in calendario | Cella vuota dentro periodo di assenza → overlay `.cella-in-assenza` (retino orizzontale, distinto da `.cella-fuori-finestra` diagonale e `.cella-cross-setting` con banda colorata) con codice del tipo assenza attenuato + tooltip. Cella con turno esistente in conflitto → bordo rosso `.cella-conflitto-assenza`, cliccabile come gli altri turni |
| Helper assenza | `TurniController::messaggioAssenza` come `messaggioFuoriFinestra` — privato, ritorna `?string`, riusato da `validateRiferimenti` e `edit` |
| Una sola query per il calendario | `AssenzaModel::listAttiveInPeriodo($idOperatori, $dataInizio, $dataFine)` per evitare N query nel ciclo Twig. Tutta l'iterazione cella-per-cella legge dalla mappa in memoria |
| Confronto date | Lessicografico su stringa `Y-m-d` (== cronologico), coerente con 4-quinquies/4-sexies sia in PHP sia in Twig |
| Vincoli operatori | Restano warning informativi (come da sessione 4). La CRUD `operatori_vincoli` + il warning leggibile sono scorporati in **sessione 5-bis**. Motivo: non sono bloccanti per design (input del generatore della 6, derogabili dalla coordinatrice in caso di carenza personale). Vedi `project-vincoli-operatori` |
| Niente cleanup automatico turni→assenza retroattiva | Quando un'assenza creata dopo copre turni esistenti, i turni restano e vengono segnalati con bordo rosso nel calendario e alert giallo nel form `edit`. La coordinatrice decide caso per caso (cambia tipo, elimina, oppure modifica/elimina l'assenza). Coerente con la decisione 4-sexies di non rimuovere automaticamente operatori per maternità retroattiva |
| `AssenzeController::store` | **Nessun check di turni in conflitto** nel momento della creazione di un'assenza retroattiva. Il conflitto è visibile dal piano. Aggiungere un flash di warning post-store eventualmente in 5-bis se la coordinatrice lo richiede |

## Test manuali

Prerequisito: `composer dump-autoload`, riavvio del server, login admin o caposala.

### Test 1 — Block su nuovo turno in periodo di assenza

1. Operatore X attivo, niente assenze. Piano in bozza per maggio 2026 (lo stesso mese del test).
2. Crea un'assenza per X dal `2026-05-10` al `2026-05-15` tipo `F` (ferie).
3. Aprire il piano, cliccare la cella di X il giorno 12: il form si apre con alert rosso che indica il periodo di assenza ("Operatore in assenza dal 10/05/2026 al 15/05/2026 (F Ferie). …").
4. Selezionare un tipo turno (es. `M`) e salvare: deve fallire. Si torna al form con flash errore "L'operatore è in assenza dal 10/05/2026 al 15/05/2026 (F Ferie). Modifica il periodo di assenza se è sbagliato, o scegli un altro giorno." e old input ripopolato.
5. Tornare al calendario: la cella del giorno 12 deve essere un overlay con retino orizzontale grigio, codice `F` in italico attenuato, tooltip "F · Ferie — dal 10/05/2026 al 15/05/2026". Non cliccabile (cursor not-allowed).

### Test 2 — Edit di turno esistente che diventa in conflitto

1. Op X, niente assenze. Assegna un turno `M` il `2026-05-12`.
2. Crea un'assenza per X dal `2026-05-10` al `2026-05-15`.
3. Tornare al piano: la cella del giorno 12 mostra `M` colorato come prima, ma con **bordo rosso** (`.cella-conflitto-assenza`).
4. Cliccare: il form si apre con alert giallo "Turno in periodo di assenza" + indicazione del periodo.
5. Cambiare tipo a `F` (o un altro tipo qualsiasi) e salvare: il salvataggio **riesce** (update non bloccato).
6. Tornare al piano, eliminare il turno (delete dal form): **riesce** (destroy non bloccato).
7. Riaprire la stessa cella vuota (giorno 12): adesso è un overlay `.cella-in-assenza`. Cliccare ora il form si apre con alert rosso "Operatore in assenza" e il submit di un nuovo turno fallisce (come Test 1).

### Test 3 — Validazione boundary date

1. Assenza per X dal `2026-05-10` al `2026-05-15`.
2. Verifica boundary:
   - Nuovo turno il 9: OK.
   - Nuovo turno il 10: KO.
   - Nuovo turno il 15: KO.
   - Nuovo turno il 16: OK.

### Test 4 — Più assenze sovrapposte (caso teorico)

1. Crea per X due assenze:
   - dal `2026-05-01` al `2026-05-10` tipo `F`.
   - dal `2026-05-08` al `2026-05-20` tipo `PE` (permesso).
2. Tentare un nuovo turno il 9: deve essere bloccato. Il messaggio cita una delle due assenze (non importa quale).

### Test 5 — Assenza in altro mese non interferisce

1. Op X con assenza dal `2026-06-01` al `2026-06-30`. Piano in bozza per maggio 2026.
2. Calendario di maggio: nessuna cella in overlay assenza. Nuovi turni assegnabili regolarmente in tutti i giorni del mese.
3. Aprire un piano di giugno (se esiste, altrimenti creane uno): tutto giugno deve essere in overlay assenza.

### Test 6 — Combinazione con fuori-finestra

1. Op X con `data_cessazione = 2026-05-25` e assenza `F` dal `2026-05-20` al `2026-05-24`.
2. Calendario di maggio:
   - Giorni 20-24: overlay `.cella-in-assenza` (priorità più alta perché `fuoriFinestra` non scatta — il 24 è dentro la finestra).
   - Giorno 25: cella vuota cliccabile `+` (ultimo giorno di servizio, niente assenza).
   - Giorni 26-31: overlay `.cella-fuori-finestra` (cessato).
3. Verifica che la cascata di rendering rispetti la precedenza definita in §7: prima fuori-finestra, poi in-assenza.

### Test 7 — Read-only / visualizzatore

1. Pubblica un piano con celle in overlay assenza.
2. Loggarsi come visualizzatore: l'overlay deve restare visibile, tooltip funzionante, nessuna cella cliccabile. I turni esistenti in conflitto (bordo rosso) restano visibili come display ma non cliccabili.

### Test 8 — Tentativo di forzatura via URL

1. Op X con assenza dal `2026-05-10` al `2026-05-15`. Nel piano in bozza.
2. URL diretto: `/piani-turno/{id}/turni/edit?operatore={idX}&data=2026-05-12`. Il form si apre con alert rosso.
3. Submit del POST con `id_tipo_turno=qualcosa`: deve fallire con messaggio user-friendly (check server-side in `validateRiferimenti`).
4. Verifica anche con DELETE manipolato: se l'op ha già un turno esistente il delete passa (corretto, simmetria 4-quinquies).

## File toccati

- `src/Models/AssenzaModel.php` — `findAttivaPerOperatoreData`, `listAttiveInPeriodo`.
- `src/Controllers/TurniController.php` — campo `private AssenzaModel $assenze`, helper `messaggioAssenza`, check in `validateRiferimenti`, `inAssenza` in `edit`.
- `src/Controllers/PianiTurnoController.php` — costruisce `assenzeByOp` in `show` (riusando `$this->assenze` già presente dalla 4-sexies).
- `views/turni/form.twig` — alert `inAssenza` (warning su turno esistente, danger su nuovo).
- `views/piani_turno/show.twig` — calcolo `assenzaCorrente` e `conflittoAssenza` nella cella, ramo `.cella-in-assenza`, classe `.cella-conflitto-assenza` sui turni esistenti.
- `public/css/app.css` — `.cella-in-assenza`, `.cella-conflitto-assenza`.
- `views/dashboard/index.twig` — banner sessione 5.
- **Migrazione DB**: nessuna.
- **Routing**: nessuna nuova rotta.

## Ordine di esecuzione consigliato

1. `AssenzaModel::findAttivaPerOperatoreData` + `listAttiveInPeriodo`.
2. Iniettare `AssenzaModel` nel costruttore di `TurniController` (se non c'è già).
3. `TurniController::messaggioAssenza` + integrazione in `validateRiferimenti`.
4. Calcolo `inAssenza` in `TurniController::edit` + alert in `views/turni/form.twig`.
5. Caricamento `assenzeByOp` in `PianiTurnoController::show` + passaggio alla view.
6. Cascata rendering in `views/piani_turno/show.twig`: nuovo ramo `.cella-in-assenza`, classe `.cella-conflitto-assenza` sui turni esistenti.
7. CSS `.cella-in-assenza` (retino orizzontale) e `.cella-conflitto-assenza` (bordo rosso interno).
8. Banner dashboard a "sessione 5".
9. Test manuali 1-8.
10. Aggiornare `docs/SESSION_NOTES.md` (sezione "Sessione 5") + roadmap memoria a fine sessione.

## Punto aperto per la sessione 5-bis

CRUD `operatori_vincoli` + warning leggibile nel form turno. Decisioni da prendere all'apertura:
1. Posizione UI: top-level `/vincoli` (pattern `AssenzeController`) o nidificato `/operatori/{id}/vincoli`.
2. Eventuale aggiunta del flash post-store in `AssenzeController` che informi se l'assenza appena creata copre turni esistenti (numero + link al piano), se i test della 5 lo rendono utile.
