# Specifica sessione 4-quater — Refactoring ricalcolo saldi e revisione rimozione operatore

## Contesto

Refactoring mirato a quattro problemi emersi durante la rilettura del codice della sessione 4-ter:

1. `SaldoRicalcoloService::ricalcola()` mescola responsabilità (ricalcolo del mese \+ propagazione progressivo). Quando viene chiamata da `SaldiController::update` con entrambi i campi modificati, propaga due volte (la seconda sovrascrive la prima).  
2. Il commento "deve essere DOPO altrimenti il ricalcolo lo sovrascriverebbe" in `SaldiController::update` è una pezza, non un design.  
3. `SaldiController::removeOperatore` gate la rimozione su `aggiunto_manualmente=1`. Questo blocca casi legittimi come dimissioni infra-mese, dove l'operatore ha zero turni e non ha più senso restare nel piano.  
4. `SaldiController::removeOperatore` e `PianiTurnoController::destroy` duplicano la logica "delete saldo se non in altri piani del mese", con ordine di operazioni opposto. Inoltre nessuno dei due ricalcola la catena dei progressivi dei mesi successivi quando un saldo viene effettivamente cancellato.

## Modifiche richieste

### 1\. Split di `SaldoRicalcoloService::ricalcola()`

**File**: `src/Services/SaldoRicalcoloService.php`

Estrarre dal corpo di `ricalcola()` un nuovo metodo pubblico `ricalcolaMese()` che fa **solo** il ricalcolo del mese corrente, senza propagazione. Mantenere `ricalcola()` come metodo di comodo che chiama `ricalcolaMese()` \+ `propagaProgressivo()`.

**Firma nuova**:

/\*\*

 \* Ricalcola le ore e il saldo\_mese/saldo\_progressivo del mese indicato

 \* dai turni effettivi. NON propaga ai mesi successivi.

 \*

 \* Ritorna il nuovo saldo\_progressivo (utile per chi vuole poi propagare).

 \* Ritorna null se il saldo del mese non esiste.

 \*/

public function ricalcolaMese(int $idOperatore, int $anno, int $mese): ?float

**Comportamento**:

- Estrarre tutto il corpo attuale di `ricalcola()` *escluso* l'ultimo statement (`$this->propagaProgressivo(...)`).  
- Ritornare `$saldoProg` (float) alla fine. Ritornare `null` se `$saldo === null`.

**`ricalcola()` diventa**:

public function ricalcola(int $idOperatore, int $anno, int $mese): void

{

    $progressivo \= $this-\>ricalcolaMese($idOperatore, $anno, $mese);

    if ($progressivo \=== null) {

        return;

    }

    $this-\>propagaProgressivo($idOperatore, $anno, $mese, $progressivo);

}

`propagaDaQui()` resta come è (è già pubblico, già wrapper di `propagaProgressivo`).

Aggiornare il docblock di classe per riflettere i tre metodi pubblici:

- `ricalcolaMese`: ricalcola solo il mese, ritorna il nuovo progressivo  
- `propagaDaQui`: propaga ai mesi successivi a partire da un valore dato  
- `ricalcola`: comodo, combina i due (uso primario da `TurniController`)

### 2\. Riscrivere `SaldiController::update()` per usare i due metodi separati

**File**: `src/Controllers/SaldiController.php`

Nella callback della transazione, sostituire la sequenza attuale (ricalcola → propagaDaQui se cambia entrambi) con una propagazione **unica**. Logica:

if (cambia ore\_dovute):

    UPDATE saldo set ore\_dovute

    INSERT saldo\_modifiche

    progressivoCorrente \= ricalcolaMese(op, anno, mese)   // riscrive ore\_\*, saldo\_mese, saldo\_progressivo

else:

    progressivoCorrente \= (float) saldo\['saldo\_progressivo'\]

if (cambia saldo\_progressivo):

    UPDATE saldo set saldo\_progressivo \= input

    INSERT saldo\_modifiche

    progressivoCorrente \= (float) data\['saldo\_progressivo'\]

if (cambia ore\_dovute || cambia saldo\_progressivo):

    propagaDaQui(op, anno, mese, progressivoCorrente)

In questo modo:

- Se cambiano entrambi, la propagazione avviene **una sola volta**, con il valore finale del progressivo (quello manuale, che ha la precedenza).  
- Se cambia solo `ore_dovute`, si propaga il valore calcolato.  
- Se cambia solo `saldo_progressivo`, si propaga il valore manuale.

Rimuovere il commento "Va fatto DOPO il ricalcolo sopra, altrimenti il ricalcolo lo sovrascriverebbe" perché non è più vero: ora `ricalcolaMese` non propaga. Sostituire con un commento che spieghi che la propagazione finale unica usa il valore "vincitore" (manuale se presente, calcolato altrimenti).

### 3\. Allargare `SaldiController::removeOperatore`

**File**: `src/Controllers/SaldiController.php`

**Rimuovere il check `aggiunto_manualmente=1`**. Mantenere il check "zero turni nel piano".

Codice attuale da eliminare:

if ((int) $appartenenza\['aggiunto\_manualmente'\] \!== 1\) {

    return $this-\>redirect(

        "/piani-turno/{$idPiano}",

        'error',

        'Si possono rimuovere solo gli operatori aggiunti in itinere. ...',

    );

}

**Audit log arricchito**: nel `Logger::get()->info('Operatore rimosso dal piano (4-ter)', ...)` aggiungere `aggiunto_manualmente` (il valore originale letto da `$appartenenza`) tra i campi loggati, per preservare l'informazione storica anche se la riga `piano_operatori` viene cancellata. Suggerito:

Logger::get()-\>info('Operatore rimosso dal piano (4-quater)', \[

    'piano'                \=\> $idPiano,

    'operatore'            \=\> $idOp,

    'aggiunto\_manualmente' \=\> (int) $appartenenza\['aggiunto\_manualmente'\],

    'user\_id'              \=\> $this-\>currentUserId(),

\]);

**Messaggio di errore zero-turni**: invariato.

### 4\. Helper condiviso per la pulizia saldo orfano

**File**: nuovo metodo in `SaldoRicalcoloService` (riuso del service esistente, evita di proliferare classi).

Aggiungere:

/\*\*

 \* Cancella il saldo (op, anno, mese) SE l'operatore non è presente in altri

 \* piani dello stesso mese, e in tal caso ricalcola la catena dei progressivi

 \* dei mesi successivi (sottraendo di fatto il saldo\_mese che è sparito).

 \*

 \* Va chiamato dentro la stessa transazione del flusso chiamante.

 \*

 \* @param list\<int\> $operatoriInAltriPianiDelMese  Lista degli id\_operatore

 \*        che compaiono in piani del mese diversi da quello in cui stiamo

 \*        operando. Se $idOperatore è in questa lista, il saldo NON viene

 \*        toccato (resta valido per gli altri piani).

 \*/

public function rimuoviSaldoSeOrfano(

    int $idOperatore,

    int $anno,

    int $mese,

    array $operatoriInAltriPianiDelMese,

): void

**Implementazione**:

public function rimuoviSaldoSeOrfano(

    int $idOperatore,

    int $anno,

    int $mese,

    array $operatoriInAltriPianiDelMese,

): void {

    if (in\_array($idOperatore, $operatoriInAltriPianiDelMese, true)) {

        return; // il saldo serve a un altro piano dello stesso mese

    }

    $saldo \= $this-\>saldi-\>findOneBy(\[

        'id\_operatore' \=\> $idOperatore,

        'anno'         \=\> $anno,

        'mese'         \=\> $mese,

    \]);

    if ($saldo \=== null) {

        return;

    }

    // Progressivo "di partenza" per la catena successiva \= quello del mese

    // PRECEDENTE a quello che sto eliminando. Cosi' i mesi successivi non

    // includono piu' il saldo\_mese del mese cancellato.

    $progPrev \= (float) $this-\>saldi-\>getProgressivoPrevious($idOperatore, $anno, $mese);

    $this-\>saldi-\>delete((int) $saldo\['id'\]);

    $this-\>propagaDaQui($idOperatore, $anno, $mese, $progPrev);

}

**Nota di correttezza**: `getProgressivoPrevious` ritorna il progressivo del mese precedente all'operatore (o 0.0 se non esiste un mese precedente). `propagaDaQui` con questa base ricostruisce i progressivi dei mesi successivi a quello cancellato, escludendolo dalla catena. Il mese cancellato sparisce sia come record sia come contributo alla catena.

### 5\. Usare l'helper in `removeOperatore` con ordine corretto

**File**: `src/Controllers/SaldiController.php` — metodo `removeOperatore`

Sostituire l'attuale corpo della transazione:

$this-\>db-\>transaction(function () use ($idAppartenenza, $idPiano, $idOp, $anno, $mese): void {

    $this-\>pianoOperatori-\>delete($idAppartenenza);

    $opInAltri \= $this-\>pianoOperatori-\>listOperatoriInAltriPianiDelMese($idPiano, $anno, $mese);

    if (\!in\_array($idOp, $opInAltri, true)) {

        $saldo \= $this-\>saldi-\>findOneBy(\[...\]);

        if ($saldo \!== null) {

            $this-\>saldi-\>delete((int) $saldo\['id'\]);

        }

    }

});

Con:

$this-\>db-\>transaction(function () use ($idAppartenenza, $idPiano, $idOp, $anno, $mese): void {

    // 1\. Leggiamo PRIMA chi e' in altri piani del mese (ordine simmetrico

    //    a PianiTurnoController::destroy).

    $opInAltri \= $this-\>pianoOperatori-\>listOperatoriInAltriPianiDelMese($idPiano, $anno, $mese);

    // 2\. Rimuoviamo l'appartenenza al piano corrente.

    $this-\>pianoOperatori-\>delete($idAppartenenza);

    // 3\. Se il saldo non serve a nessun altro piano del mese, lo cancelliamo

    //    e ricalcoliamo la catena dei progressivi futuri.

    $this-\>ricalcolo-\>rimuoviSaldoSeOrfano($idOp, $anno, $mese, $opInAltri);

});

### 6\. Usare l'helper anche in `PianiTurnoController::destroy`

**File**: `src/Controllers/PianiTurnoController.php` — metodo `destroy`

Oggi `destroy` usa `SaldoOreModel::deleteByAnnoMeseEscludendoOperatori` che fa una DELETE batch ma non propaga i progressivi successivi.

Sostituire la transazione corrente:

$this-\>db-\>transaction(function () use ($id, $anno, $mese): void {

    $opInAltriPiani \= $this-\>pianoOperatori-\>listOperatoriInAltriPianiDelMese($id, $anno, $mese);

    $this-\>saldi-\>deleteByAnnoMeseEscludendoOperatori($anno, $mese, $id, $opInAltriPiani);

    $this-\>piani-\>delete($id);

});

Con un ciclo per operatore che chiama l'helper:

$this-\>db-\>transaction(function () use ($id, $anno, $mese): void {

    $opInAltriPiani \= $this-\>pianoOperatori-\>listOperatoriInAltriPianiDelMese($id, $anno, $mese);

    // Lista degli operatori di questo piano (presa da piano\_operatori

    // PRIMA del CASCADE provocato dal delete del piano).

    $operatoriDelPiano \= array\_map(

        static fn ($r) \=\> (int) $r\['id\_operatore'\],

        $this-\>pianoOperatori-\>listIdOperatoriByPiano($id),

    );

    foreach ($operatoriDelPiano as $idOp) {

        $this-\>ricalcolo-\>rimuoviSaldoSeOrfano($idOp, $anno, $mese, $opInAltriPiani);

    }

    $this-\>piani-\>delete($id); // CASCADE pulisce piano\_operatori e turni

});

**Richiede**:

- Iniettare `SaldoRicalcoloService` in `PianiTurnoController` (al momento non c'è). Aggiungere campo `private SaldoRicalcoloService $ricalcolo;` e inizializzazione in `__construct`:  
    
  $this-\>ricalcolo \= new SaldoRicalcoloService($this-\>saldi, $this-\>turni);  
    
  (`$this->turni` e `$this->saldi` esistono già nel controller).  
    
- Aggiungere un metodo `listIdOperatoriByPiano(int $idPiano): array` in `PianoOperatoreModel` che ritorna solo `[{id_operatore: int}, ...]`. Se esiste già un metodo equivalente, usarlo; altrimenti aggiungerlo (query semplice: `SELECT id_operatore FROM piano_operatori WHERE id_piano = :id_piano`).

**Cosa fare di `SaldoOreModel::deleteByAnnoMeseEscludendoOperatori`**: rimuoverlo. Era usato solo da `destroy` e con il nuovo flusso non serve più. Cercare in tutto il codice se ci sono altri call site; non dovrebbero essercene.

## Test manuali da fare dopo l'applicazione

Prerequisito: `composer dump-autoload`, riavvio del server, login admin o caposala.

### Test 1 — Update saldo con entrambi i campi cambiati

1. Aprire un piano in bozza, scegliere un operatore con almeno un mese successivo già esistente (o crearne uno con `PianiTurnoController::store`).  
2. Modifica saldo: cambia sia `ore_dovute` (es. da 165 a 140\) sia `saldo_progressivo` (es. da \-2.00 a \+5.00), nota "test 4-quater".  
3. Verificare:  
   - `saldo_ore` del mese corrente: `ore_dovute=140`, `saldo_mese` ricalcolato dalle ore turni meno 140, `saldo_progressivo=5.00`.  
   - `saldo_modifiche`: due righe (una per ore\_dovute, una per saldo\_progressivo), entrambe con la nota.  
   - `saldo_ore` del mese successivo: `saldo_progressivo` \= `5.00 + saldo_mese_successivo`.  
4. **Verifica log applicazione**: una sola sequenza di propagazione, non due. (Se c'è logging per UPDATE su saldo\_ore aggiungere temporaneamente uno scrivi-su-file se serve evidenza.)

### Test 2 — Update saldo con un solo campo

Stesso piano, modifica solo `ore_dovute`. Verificare che `saldo_mese` cambi e che il `saldo_progressivo` venga ricalcolato dalle ore (non resti uguale). Mese successivo aggiornato di conseguenza.

Poi modifica solo `saldo_progressivo`. Verificare che `ore_dovute` resti uguale, `saldo_mese` resti uguale, `saldo_progressivo` sia il valore manuale, e il mese successivo si aggiorni.

### Test 3 — Rimozione operatore "auto" senza turni (caso dimissione)

1. Su un piano in bozza, scegliere un operatore incluso automaticamente alla create (badge senza "in itinere"), assicurarsi che abbia **zero turni** assegnati nel piano.  
2. Cliccare "Rimuovi": **deve riuscire** (prima era bloccato).  
3. Verificare:  
   - Riga `piano_operatori` cancellata.  
   - Se l'op non è in altri piani del mese: `saldo_ore` cancellato.  
   - Log applicazione contiene `aggiunto_manualmente: 0`.

### Test 4 — Rimozione operatore con turni → ancora bloccata

Stesso scenario di sopra ma con un turno assegnato. Il bottone Rimuovi (o il submit del form) deve fallire con messaggio "Rimuovi prima i turni assegnati...". Invariato rispetto a prima.

### Test 5 — Catena progressivo dopo rimozione che cancella il saldo

1. Operatore con saldi in tre mesi consecutivi (es. maggio, giugno, luglio). Saldo\_mese di maggio \= \+10, giugno \= \-5, luglio \= \+3. Progressivi maggio=10, giugno=5, luglio=8 (assumendo progPrev di aprile \= 0).  
2. Rimuovere l'operatore dal piano di maggio (assicurandosi che non sia in nessun altro piano di maggio). Il saldo di maggio viene cancellato.  
3. Verificare: progressivo di giugno deve diventare `0 + (-5) = -5`. Progressivo di luglio \= `-5 + 3 = -2`. **Prima del refactoring restavano 5 e 8\.**

### Test 6 — Destroy piano con operatore cross-setting

1. Operatore X presente sia nel piano A (setting Hospice) sia nel piano B (setting Cure Domiciliari), stesso mese. Saldo unico cross-setting.  
2. Eliminare il piano A.  
3. Verificare:  
   - Piano A cancellato.  
   - Saldo di X **non** cancellato (serve a B).  
   - Riga `piano_operatori` di X per il piano A cancellata (via CASCADE).  
   - Piano B ancora funzionante con X nella tabella saldi.

### Test 7 — Destroy piano con operatore solo in quel piano

1. Operatore Y solo nel piano A.  
2. Eliminare il piano A.  
3. Verificare:  
   - Saldo di Y cancellato.  
   - Se Y aveva saldi in mesi successivi: progressivi dei mesi successivi ricalcolati senza il contributo del saldo\_mese eliminato.

## File toccati

- `src/Services/SaldoRicalcoloService.php` — split `ricalcola()`, nuovo metodo `rimuoviSaldoSeOrfano()`.  
- `src/Controllers/SaldiController.php` — refactor `update()`, allargamento `removeOperatore()`.  
- `src/Controllers/PianiTurnoController.php` — usa l'helper in `destroy`, inietta `SaldoRicalcoloService`.  
- `src/Models/PianoOperatoreModel.php` — aggiunge `listIdOperatoriByPiano()` se non c'è già.  
- `src/Models/SaldoOreModel.php` — rimuove `deleteByAnnoMeseEscludendoOperatori()` (se non ha altri call site).  
- **Migrazione DB**: nessuna. Solo modifiche di codice.  
- **Viste**: nessuna. La UI non cambia (il bottone Rimuovi può ora apparire su più righe: verificare il twig `views/piani_turno/show.twig` e rimuovere la condizione `aggiunto_manualmente` se presente sul bottone).

## Note di design da scrivere in `docs/SESSION_NOTES.md` a fine sessione

| Punto | Scelta |
| :---- | :---- |
| Split ricalcola | `ricalcolaMese` (solo mese) \+ `propagaDaQui` (solo catena successiva). `ricalcola` resta come wrapper di comodo |
| Update saldo doppio | Propagazione unica a fine transazione con il valore "vincitore" (manuale se presente, calcolato altrimenti) |
| Rimozione operatore | Gate unico: zero turni nel piano. Il flag `aggiunto_manualmente` non e' piu' una condizione di rimovibilita', resta informazione storica nella tabella e nel log |
| Helper saldo orfano | `SaldoRicalcoloService::rimuoviSaldoSeOrfano` centralizza "delete se non in altri piani \+ propaga catena". Usato sia da `SaldiController::removeOperatore` sia da `PianiTurnoController::destroy` |
| Catena progressivo post-cancellazione | Dopo aver cancellato un saldo orfano, i progressivi dei mesi successivi vengono ricalcolati partendo dal progressivo del mese precedente (esclude il contributo del mese cancellato) |
| `deleteByAnnoMeseEscludendoOperatori` | Rimosso. Sostituito da loop con helper in `destroy` |

## Ordine di esecuzione consigliato

1. Modificare `SaldoRicalcoloService` (split \+ nuovo helper).  
2. Modificare `SaldiController::update` per usare il nuovo flusso.  
3. Modificare `SaldiController::removeOperatore` (rimozione gate \+ uso helper).  
4. Aggiungere `listIdOperatoriByPiano` in `PianoOperatoreModel` se manca.  
5. Modificare `PianiTurnoController::destroy` per usare l'helper.  
6. Rimuovere `deleteByAnnoMeseEscludendoOperatori` da `SaldoOreModel`.  
7. Verificare le viste (in particolare `views/piani_turno/show.twig`): se il bottone "Rimuovi" e' condizionato a `aggiunto_manualmente`, rimuovere la condizione.  
8. Eseguire i test manuali 1-7.  
9. Aggiornare `docs/SESSION_NOTES.md`.

