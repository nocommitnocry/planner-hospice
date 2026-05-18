# Note di sessione

## Sessione 1 — 2026-05-10 — Fondamenta + autenticazione

### Cosa è stato fatto

- Struttura cartelle e file di configurazione (`composer.json`, `.env.example`, `.gitignore`, `.editorconfig`)
- Schema DB rivisto: `login_attempts` con identifier libero (anti-enumeration), audit log con JSON e azioni di login, FK con cascade/restrict sensati, indici aggiuntivi
- Front controller unico (`public/index.php`) + bootstrap PHP + autoloader Composer
- Router custom con rotte parametriche, metadati per ruoli, CSRF on/off, public/private
- Pipeline middleware: `ErrorHandler` → `SecurityHeaders` → `Authentication` → `Csrf` → `Authorization` → dispatch
- Helper core: `Container`, `Config`, `Database` (PDO), `Logger` (Monolog), `Session` (hardenata), `Csrf`, `View` (Twig), `Url`
- `BaseModel` con allow-list dei campi e validazione delle colonne ordering/where
- `UtenteModel`, `LoginAttemptModel`
- `AuthService` con lockout per username e IP, `session_regenerate_id` su login/cambio password, `password_needs_rehash` automatico
- `BaseController`, `AuthController`, `DashboardController` (placeholder)
- Viste Twig: layout base, navbar, login, change_password, dashboard, errori 403/404/500
- Script CLI: `bin/install-assets.sh` (Bootstrap 5 self-hosted), `bin/create-admin.php` (interattivo)
- README installazione

### Issues sistemate rispetto al vecchio progetto

| Problema | Risolto come |
|---|---|
| Credenziali DB in chiaro nel repo | Spostate in `.env` (gitignored), `.env.example` con placeholder |
| XSS endemico (540 echo non escapati) | Twig con auto-escape attivo, niente più echo nelle view |
| Nessuna CSRF | Middleware obbligatorio su POST/PUT/PATCH/DELETE |
| Session fixation (no regenerate_id) | `regenerate(true)` su login e cambio password |
| Cookie senza HttpOnly/SameSite/Secure | Espliciti in `Session::start()` |
| Authorization sparsa nei controller | Middleware centralizzato sul router |
| Login senza lockout effettivo (lockout_time=0, FK rigida) | Lockout reale per username+IP, identifier libero in DB |
| Password hash check con `strlen($pwd) < 60` | `password_hash()` sempre al SET, `password_needs_rehash` al verify |
| File pericolosi nella root (create_hash.php, test_password.php) | Sostituiti da `bin/create-admin.php` (CLI, no web) |
| Controller monolitici, routing in cieco | Router con metadati di rotta, controller piccoli |
| CDN jQuery + Bootstrap senza SRI | Bootstrap 5 self-hosted via script una-tantum, niente jQuery |
| Nessun logging strutturato | Monolog con rotazione |

### Da fare prima della prossima sessione (lato utente)

1. Installare PHP 8.2 e Composer sulla macchina di sviluppo:
   ```bash
   sudo apt install php8.2 php8.2-cli php8.2-pdo php8.2-mysql php8.2-mbstring php8.2-intl php8.2-xml php8.2-curl composer
   ```
2. Eseguire `composer install` per scaricare le dipendenze.
3. Eseguire `chmod +x bin/install-assets.sh bin/create-admin.php` (non l'ho potuto fare io).
4. Eseguire `./bin/install-assets.sh` per scaricare Bootstrap.
5. Creare il database, importare `schema.sql`, copiare `.env.example` in `.env` e configurare.
6. Lanciare `php -S localhost:8000 -t public/` e verificare:
   - `/login` mostra la pagina di accesso
   - Login con admin (creato via `bin/create-admin.php`) funziona
   - Dashboard mostra il riepilogo di sessione 1
   - `/logout` riporta a `/login`

### Prossima sessione (2)

CRUD anagrafiche: operatori, categorie, tipi turno, utenti. Stabilisce il pattern Validator + flash messages che si replicherà nelle sessioni successive.

---

## Sessione 2 — 2026-05-11 — CRUD anagrafiche + pattern Validator

### Cosa è stato fatto

- **Pattern Validator** (`src/Validators/`):
  - `Rules.php`: regole atomiche riusabili (required, maxLen/minLen, integer, decimal, intRange/decimalRange, inSet, hexColor, time, email, username, toBool). Ogni regola ritorna `null` se OK o `string` con messaggio in italiano.
  - `BaseValidator.php`: contratto `validate(array $input): {ok, data, errors}`.
- **Form errors + old input**: aggiunti a `Session` (`flashInput`/`consumeOldInput`, `flashErrors`/`consumeFormErrors`) ed esposti come globals Twig (`old_input`, `form_errors`) in `View::render()`. Helper `BaseController::redirectWithErrors()` per il pattern POST-Redirect-GET con re-display del form.
- **CRUD Categorie operatori** — solo admin. FK RESTRICT da `operatori` gestita: cattura `PDOException` con SQLSTATE `23000` e messaggio user-friendly. Nome forzato in maiuscolo.
- **CRUD Tipi turno** — solo admin. Input HEX color, time HH:MM, decimal con virgola o punto, bool flags da checkbox normalizzati. FK RESTRICT da `turni` e `assenze` gestita.
- **CRUD Operatori** — admin + caposala. FK validata contro lista categorie esistenti. Azione separata `toggle-attivo`: alternativa al delete per operatori con storico (FK RESTRICT su `turni`). Filtro `?inattivi=1` nell'index.
- **CRUD Utenti applicativi** — solo admin. Punti caldi:
  - password gestita in chiaro SOLO nel validator → hash con `password_hash()` nel controller PRIMA di salvare; il Model non vede mai la password in chiaro.
  - in create: password obbligatoria. In edit: vuoto = invariata.
  - hash mai esposto alle view (rimosso esplicitamente dopo `find()`/`listAllOrdered()`).
  - `password` rimossa dall'`old_input` salvato in sessione (non vogliamo password in cookie/sessione persistente).
  - **auto-protezioni admin**: non può eliminarsi, non può degradare il proprio ruolo, non può disattivarsi. Forzato lato server in `update()` (defense in depth) e lato UI con `disabled` + hidden fallback.
- **Routes**: pattern uniforme `index / create / store / edit / update / destroy` (POST anche per update e destroy, compatibili con form HTML senza JS). Ruoli applicati a livello di rotta.
- **Navbar**: dropdown «Anagrafiche» visibile a admin/caposala con voci filtrate per ruolo.
- **Dashboard**: tile rapide alle anagrafiche, filtrate per ruolo.

### Decisioni di sicurezza ricorrenti

| Punto | Scelta |
|---|---|
| Validazione | Centralizzata in `App\Validators`, mai nei controller |
| FK RESTRICT | Catturata via SQLSTATE 23000, messaggio specifico, nessun 500 al cliente |
| Soft-disable vs hard-delete | Operatori e utenti hanno entrambe le opzioni; il delete fallisce se ci sono FK e suggerisce di disattivare |
| Password | Hash sempre nel controller (`password_hash`), mai nel Model. Niente password in `old_input` o flash. |
| Admin auto-lockout | Prevenuto: l'admin non può togliersi ruolo/attivo/se stesso. Sia UI che server-side. |
| Form errors | Re-display con `is-invalid` + `invalid-feedback`, old input ripopolato (esclusi campi sensibili) |
| Maiuscolo dei codici | `codice` tipi turno e `nome` categorie normalizzati in uppercase prima del save |
| FK operatori → categoria | Validata contro lista ID realmente esistenti, non solo type-check |
| Autorizzazione | A livello di rotta nel router, mai dentro i controller |

### Da fare prima della prossima sessione (lato utente)

1. `composer dump-autoload` per essere sicura che le nuove classi siano in classmap (non strettamente necessario in dev, ma utile in produzione).
2. Riavviare il dev server (`php -S localhost:8000 -t public/`) e testare con l'utente admin:
   - `/categorie-operatori` → crea/modifica/elimina (delete dovrebbe fallire se ci sono operatori che la usano).
   - `/tipi-turno` → modifica un tipo esistente (es. cambia il colore di "F"). Crea un nuovo codice (es. `RC` Recupero), poi eliminalo.
   - `/operatori` → crea 2-3 operatori di test, prova a disattivarne uno, prova a eliminare uno (se non ha turni andrà a buon fine).
   - `/utenti` → crea un secondo utente di prova (caposala), verifica che dal tuo profilo non puoi cambiarti il ruolo. Logout/login con il nuovo utente per verificare che non veda «Categorie», «Tipi turno», «Utenti» in navbar.
3. Far testare il flow a un secondo paio d'occhi (validazione errori, redisplay form) sui browser principali.

### Prossima sessione (3)

Piani turni mensili: tabella `piano_turni` con stati `bozza/pubblicato/archiviato`, creazione di un piano vuoto per (anno, mese), elenco piani esistenti, vista calendario placeholder (sarà popolata nella sessione 4). Si introdurrà la prima transazione DB significativa (creazione piano + saldi iniziali).

---

## Sessione 3 — 2026-05-12 — Piani turni mensili + prima transazione DB

### Cosa è stato fatto

- **Model `PianoTurnoModel`** (`piano_turni`): fillable allow-list `[anno, mese, nome, stato, creato_da, pubblicato_il]`. Costante `STATI = ['bozza', 'pubblicato', 'archiviato']`. Metodi `listOrdered(?stato)` (JOIN con `utenti` + sub-query `COUNT(turni)`), `findByAnnoMese`, `countTurni`.
- **Model `SaldoOreModel`** (`saldo_ore`): fillable con tutte le colonne di saldo. `listByAnnoMese` joina con `operatori` + `categorie_operatori` e ordina per ordine categoria → cognome → nome. `getProgressivoPrevious` recupera il saldo del mese precedente con rollover di anno (gennaio → dicembre precedente). `deleteByAnnoMese` per la cancellazione del piano in bozza.
- **Validator `PianoTurnoValidator`**: anno (2020-2100), mese (1-12), nome opzionale (max 100). Lo `stato` NON è validato qui: lo gestiscono azioni dedicate sul controller (publish/unpublish/archive).
- **Controller `PianiTurnoController`** — admin + caposala per scrittura, tutti gli autenticati in lettura:
  - `index` con filtro `?stato=bozza|pubblicato|archiviato` via tab pills.
  - `create`/`store`: in caso di successo la creazione viene fatta in **transazione**:
    1. Insert in `piano_turni` (`stato='bozza'`, `creato_da` = utente corrente)
    2. Per ogni operatore attivo: insert in `saldo_ore` con `ore_dovute = ore_contrattuali_mensili`, ore lavorate a zero, `saldo_mese = -ore_dovute`, `saldo_progressivo = saldo_progressivo(mese precedente, stesso operatore) + saldo_mese`.
    Pre-check su `findByAnnoMese` evita la PDOException nel caso normale; SQLSTATE 23000 viene comunque catturato per la race condition.
  - `show`: tre card riepilogo (periodo / turni / operatori), **griglia calendario placeholder** (operatori × giorni del mese, weekend evidenziati, prima colonna sticky), tabella saldi iniziali con saldo mese/progressivo evidenziati in rosso se negativi.
  - `destroy`: ammessa solo se `stato = 'bozza'` E `countTurni == 0`. In transazione cancella anche i record di `saldo_ore` per quel (anno, mese) (la FK non è dichiarata fra `saldo_ore` e `piano_turni`, quindi va fatta esplicita).
  - `publish` (bozza→pubblicato, setta `pubblicato_il = NOW()`), `unpublish` (pubblicato→bozza, azzera `pubblicato_il` — utile per correggere errori dopo la pubblicazione), `archive` (pubblicato→archiviato, irreversibile). Ogni transizione passa per il metodo privato `cambioStato` che verifica che lo stato di partenza sia quello atteso.
- **Routing**: `index` e `show` accessibili a qualsiasi utente autenticato; `create/store/destroy/publish/unpublish/archive` riservati ad admin+caposala. Pattern POST per le transizioni di stato e il delete (CSRF attivo).
- **Navbar**: aggiunta voce «Piani turno» visibile a tutti gli autenticati. **Dashboard**: nuova tile «Piani turno» per tutti i ruoli; banner aggiornato a sessione 3.
- **CSS**: classe `.calendario-piano` in `public/css/app.css` con prima colonna sticky a sinistra e tinta leggera per le colonne weekend.

### Decisioni di sessione

| Punto | Scelta |
|---|---|
| Macchina a stati | bozza ↔ pubblicato → archiviato. `archiviato` è terminale; `unpublish` permette correzioni post-pubblicazione |
| Cambio stato vs form | Form dedicati POST per ogni transizione (`/publish`, `/unpublish`, `/archive`). Niente edit libero dello stato dalla UI |
| Transazione store | Piano + tutti i `saldo_ore` iniziali in una sola transazione. Rollback se anche un solo saldo fallisce |
| Operatori attivi a piano-creation | Si "fotografano" gli operatori `attivo=1` al momento della creazione del piano. Disattivare un operatore dopo non rimuove il suo saldo del mese |
| FK assente saldo_ore → piano_turni | Lo schema non collega `saldo_ore` al piano direttamente (è collegato per `(id_operatore, anno, mese)`). La destroy del piano elimina manualmente i saldi del mese (in transazione) |
| Permessi lettura | `index` e `show` aperti anche al visualizzatore: il piano pubblicato è informazione di servizio per tutti |
| Permessi scrittura | Tutte le mutazioni richiedono admin o caposala. Applicato a livello di rotta |
| Calendario | In sessione 3 è solo placeholder visivo (griglia operatori × giorni). L'assegnazione turni arriverà in sessione 4 |
| Saldo progressivo iniziale | Calcolato a partire dal saldo progressivo del mese precedente, se esiste. Altrimenti parte da 0. Rollover anno gestito |

### Da fare prima della prossima sessione (lato utente)

1. `composer dump-autoload` (non strettamente necessario in dev).
2. Riavviare `php -S localhost:8000 -t public/` e con utente **admin** o **caposala**:
   - Andare in `/piani-turno` → bottone «+ Nuovo piano».
   - Creare il piano per il mese corrente. Verificare il redirect alla pagina di dettaglio: calendario operatori × giorni, tabella saldi iniziali con `ore_dovute` = ore contrattuali mensili e saldo mese negativo.
   - Provare a creare un secondo piano per lo stesso (anno, mese): deve fallire con messaggio user-friendly.
   - Provare ad eliminare il piano in bozza: deve funzionare e ripulire i saldi del mese.
   - Pubblicare il piano (bottone «Pubblica»). Verificare che il bottone «Elimina» sparisca e compaiano «Riporta in bozza» / «Archivia».
   - Loggarsi con utente **visualizzatore**: deve vedere `/piani-turno` e poter aprire i piani pubblicati, ma non deve vedere bottoni di scrittura né accedere a `/piani-turno/create` (otterrebbe 403).
   - Provare a creare un piano quando non ci sono operatori attivi (disattivarli tutti): deve mostrare un avviso e non scrivere nulla.

### Prossima sessione (4)

Assegnazione turni nel calendario: click su una cella per scegliere un tipo di turno (M/P/N/F…), salvataggio in `turni`, ricalcolo automatico del saldo dell'operatore. Pattern: edit inline minimale via form HTML (no JS pesante), con validazione lato server. Vincoli operatori (`operatori_vincoli`) consultati ma ancora non bloccanti — i conflitti veri arriveranno in sessione 5.


---

## Sessione 4 — 2026-05-13 — Assegnazione turni nel calendario + ricalcolo saldi

### Cosa è stato fatto

- **Model `TurnoModel`** (`turni`): fillable `[id_piano, id_operatore, data, id_tipo_turno, note]`. Metodi: `listByPiano` (JOIN tipi_turno per codice/colore/descrizione, ordinato per data+operatore), `findInPianoByOperatoreData` per check unique con dati tipo turno, `listByOperatoreInMese` (per il ricalcolo: ritorna ore_conteggiate + i flag is_riposo/is_ferie/is_permesso/is_malattia/is_formazione tra il primo e l'ultimo del mese).
- **Validator `TurnoValidator`**: valida `id_operatore` (int required), `id_tipo_turno` (int required), `data` (formato `Y-m-d` con `createFromFormat` round-trip per evitare date "morbide" tipo 2026-02-31), `note` (opzionale, max 1000 char). NON verifica l'esistenza degli ID — quello lo fa il controller dopo aver letto il piano (pattern consolidato dalla sessione 2).
- **Service `SaldoRicalcoloService`** (`src/Services/`): `ricalcola(idOperatore, anno, mese)`:
  1. Somma le ore dei turni del mese, partizionandole secondo i flag del tipo turno: `is_riposo` → niente, `is_ferie/permesso/malattia/formazione` → categorie omonime, altrimenti → ore_lavorate. Priorità in `elseif`.
  2. Ricava `ore_dovute` dal record `saldo_ore` esistente (fonte di verità per quel piano, già "fotografata" alla creazione).
  3. `saldo_mese = somma(ore_*) - ore_dovute`, `saldo_progressivo = getProgressivoPrevious + saldo_mese`.
  4. Update del saldo del mese corrente.
  5. **Propagazione**: per ogni mese successivo che ha un saldo (fino a 24 mesi di sicurezza) ricalcola solo `saldo_progressivo = progressivo_prev_nuovo + saldo_mese_di_quel_mese`. Le ore degli altri mesi non cambiano, solo la catena progressiva.
- **Controller `TurniController`** — admin + caposala, solo su piani in `bozza`:
  - `edit` (GET con `?operatore=X&data=Y`): valida che (operatore, data) siano coerenti col piano (data nel mese, operatore presente nel `saldo_ore` di quel mese), carica il turno esistente per (piano, op, data) se c'è, mostra il form con la griglia tipi turno colorata e i vincoli attivi dell'operatore in quella data.
  - `store`: valida, controlla esistenza FK (operatore, tipo), data nel mese del piano, operatore con saldo nel piano. Pre-check unique → messaggio user-friendly. Transazione: insert + `ricalcola`. SQLSTATE 23000 catturato per race.
  - `update`: modifica **solo** `id_tipo_turno` e `note`. Operatore/data restano quelli del turno: per spostarli si elimina e ricrea (mantiene la UI semplice e il flusso "una cella = un'azione").
  - `destroy`: in transazione delete + `ricalcola`.
  - Verifica appartenenza turno→piano via `id_piano` letto dal record (no fiducia sul route param).
- **Vincoli operatori**: lettura inline da `operatori_vincoli` filtrata per `attivo=1` e data dentro `[data_inizio, data_fine]` (con NULL = aperto). Mostrati nel form come `alert-warning` informativo. **Non bloccanti** — è una decisione esplicita: le validazioni hard sui vincoli arriveranno in sessione 5.
- **Routing**: `GET /piani-turno/{id}/turni/edit`, `POST /piani-turno/{id}/turni` (store), `POST /piani-turno/{id}/turni/{tid}` (update), `POST /piani-turno/{id}/turni/{tid}/delete`. Tutte riservate ad admin+caposala. Verificato che le rotte non collidano con `/piani-turno/{id}` (il `$` finale della regex impedisce match parziali).
- **UI calendario** (`views/piani_turno/show.twig`): la griglia ora carica la matrice `[id_operatore][YYYY-MM-DD] → turno` passata dal controller e dipinge ogni cella con `tipo_codice` su `tipo_colore`. Variabile `celleEditabili` (`puoModificare && stato=='bozza'`) determina se la cella è un link verso il form di edit. Visualizzatore + piani non bozza vedono solo display.
- **UI form turno** (`views/turni/form.twig`): card con operatore e data fissi (read-only nella UI; per il submit del nuovo turno passano in hidden); griglia di radio nascoste sopra `label` colorate per la selezione del tipo turno (codice grande + descrizione); textarea note; bottone "Elimina turno" che submitta un secondo form a parte (action delete) per evitare di mescolare azioni.
- **CSS** (`public/css/app.css`): aggiunta classe `.cella-link` per le celle cliccabili con hover outline, `.cella-vuota` per il "+", `.tipi-turno-grid` responsive auto-fill con `:has(input:checked)` per highlight selezione.
- **Dashboard**: banner aggiornato a sessione 4.

### Decisioni di sessione

| Punto | Scelta |
|---|---|
| Mutazioni e stato piano | Tutte le modifiche ai turni richiedono `stato='bozza'`. Per modificare un piano pubblicato l'utente fa prima `unpublish`. Coerente con la macchina a stati della sessione 3 |
| Update parziale | Update modifica solo tipo turno + note. Spostamento operatore/data = delete + create. Riduce branch nel ricalcolo (un solo mese da rifare) e rende il flusso UI prevedibile |
| Operatori ammessi nel piano | Un turno richiede un record `saldo_ore` per (operatore, anno, mese): in pratica solo gli operatori "fotografati" alla creazione del piano. Operatori aggiunti/riattivati dopo NON entrano automaticamente nel piano corrente |
| Service vs Model | Il ricalcolo è in `App\Services\SaldoRicalcoloService` perché coordina TurnoModel + SaldoOreModel. I Model restano CRUD; il Service è la prima entità di "logica di dominio" |
| Propagazione progressivo | Solo `saldo_progressivo` viene propagato in catena ai mesi successivi (max 24 mesi di safety). Le ore degli altri mesi sono indipendenti |
| Transazione | Insert/update/delete del turno + ricalcolo in un'unica transazione. Se il ricalcolo fallisce rollback anche del turno |
| Vincoli operatori | Letti e mostrati come warning informativo nel form. Non bloccano il save. La validazione hard è rinviata alla sessione 5 dove introdurremo controlli espliciti (no_notti vs is_notte ecc.) |
| Routing | Sub-resource sotto piano: `/piani-turno/{id}/turni/...`. Verifica appartenenza letta dal record turno, non dal route param (defense in depth) |
| UI senza JS | Nessun JS nuovo: la selezione tipo turno è radio + CSS `:has(input:checked)`. Il delete usa un secondo `<form>` collegato via `form="..."` per disaccoppiare l'azione |
| Saldi mostrati | Eliminata l'etichetta "iniziali": ora i saldi mostrati sono quelli correnti, aggiornati ad ogni mutazione |

### Da fare prima della prossima sessione (lato utente)

1. `composer dump-autoload` per assicurare il classmap del nuovo namespace `App\Services` e dei nuovi controller/model/validator (in dev non strettamente necessario col PSR-4).
2. Riavviare `php -S localhost:8000 -t public/` e con utente **admin** o **caposala**:
   - Aprire un piano in **bozza** (crearne uno se non esiste). Verificare che ogni cella `operatore × giorno` mostri un `+` grigio cliccabile.
   - Cliccare una cella → si apre il form di assegnazione. Selezionare un tipo turno (es. `M`), salvare. Tornati al calendario la cella deve mostrare il codice colorato; nella tabella saldi le ore lavorate e il saldo mese dell'operatore devono aggiornarsi.
   - Ri-cliccare la stessa cella → form di modifica con il tipo turno pre-selezionato, possibilità di cambiare tipo o eliminare.
   - Provare a creare due piani consecutivi (es. maggio + giugno) per lo stesso operatore. Assegnare turni a maggio: il `saldo_progressivo` di giugno deve essersi aggiornato automaticamente (propagazione).
   - Eliminare un turno: il saldo deve tornare indietro e la propagazione deve aggiornare i mesi successivi.
   - **Pubblicare** il piano: le celle non devono più essere cliccabili e deve comparire un avviso "riporta in bozza per modificare". Le celle continuano a mostrare i turni esistenti.
   - **Unpublish** → tornano cliccabili.
   - Loggarsi con utente **visualizzatore**: deve vedere il calendario coi turni esistenti (codici colorati) ma nessun link, nessun bottone di scrittura, nessun accesso a `/piani-turno/{id}/turni/edit` (403).
   - Provare a salvare un turno via URL manipolato (cambiare `data` a un giorno fuori mese): deve essere rifiutato dal validator/controller.
   - Provare a creare un turno per un operatore esistente ma non incluso nel piano (es. operatore creato dopo la creazione del piano): oggi deve essere rifiutato con "L'operatore non è incluso in questo piano". **Da rivedere in sessione 4-ter**: l'assunzione infra-mese è gestione ordinaria e dovrà essere supportata tramite "Aggiungi operatore al piano" con `ore_dovute` pro-rata.

### Prossime sessioni (4-bis → 4-quater, poi 5)

Dalla conversazione di dominio del 2026-05-13 è emerso che il modello "un solo reparto" è incompleto: la struttura opera su **due setting paralleli** (Hospice e UCP-DOM) con operatori che si spostano tra i due (sostituzioni brevi e spostamenti lunghi), saldo ore unico cross-setting, e il planner come strumento di consenso pratico tra coordinatrici e operatori (non gestionale HR — la fonte di verità del saldo resta il cedolino del gestionale presenze esterno). La sessione 5 originale ("vincoli bloccanti") slitta perché ha senso costruirla sopra un modello completo. Vedi `memory/project-dominio.md` per il quadro applicativo.

**Sessione 4-bis — Introduzione del setting** (scritta 2026-05-14, vedi sotto)

**Sessione 4-ter — Operatori in itinere + saldi editabili**
- Azione "Aggiungi operatore al piano" (per operatori non presenti al momento della creazione: assunzioni infra-mese, spostamenti dall'altro setting) con `ore_dovute` e `saldo_progressivo_iniziale` editabili.
- Modifica `ore_dovute` su saldi esistenti (pro-rata di fine rapporto, recuperi espliciti "questo mese -18h").
- Modifica `saldo_progressivo` come "reset di verità" agganciato al numero del cedolino comunicato dall'operatore.
- Note libere **obbligatorie** sulle modifiche manuali per tracciabilità.
- A questo punto il test #8 della checklist sopra perde senso nella forma attuale e va riformulato: l'operatore non incluso si **aggiunge**, non si **rifiuta**.

**Sessione 4-quater — Cross-setting view**
- Nel calendario del proprio piano, per gli operatori del mio setting che hanno turni nell'altro piano dello stesso mese, mostrare quelle celle come overlay grigio non cliccabile (con tooltip che indica il setting di origine).
- Serve a evitare pianificazione cieca e a vedere i conflitti prima di sbatterci contro l'UNIQUE `(operatore, data)`.

**Sessione 5 (slittata) — Vincoli bloccanti + bozza generatore**
- I `tipo_vincolo` da info display passano a check di validazione (es. `no_notti` blocca tipi turno notturni; `no_weekend` blocca date di weekend).
- Check su ferie/assenze sovrapposte.
- Inizio bozza del generatore automatico, ora che il modello (setting + saldi cross-setting + vincoli) è completo.

---

## Sessione 4-bis — 2026-05-14 — Introduzione del concetto di setting (hospice / UCP-DOM)

### Cosa è stato fatto

- **Migrazione `0002_introduce_setting.sql`**:
  - Nuova tabella `setting` (id, codice UNIQUE, nome, descrizione, attivo, ordine_visualizzazione) con seed `hospice` e `ucp_dom`.
  - `operatori.id_setting` NOT NULL, FK RESTRICT verso `setting`. Backfill: tutti gli operatori esistenti → setting `hospice`.
  - `piano_turni.id_setting` NOT NULL, FK RESTRICT. Backfill → `hospice`. UNIQUE cambiata da `(anno, mese)` a `(anno, mese, id_setting)`: per lo stesso mese possono ora coesistere due piani.
  - `utenti.id_setting` NULL, FK SET NULL. Non backfilliamo: gli utenti esistenti restano "globali" (l'admin assegnerà a mano il setting di default delle caposala).
  - UNIQUE `(operatore, data)` su `turni` resta: impedisce doppio turno nello stesso giorno tra setting diversi (vincolo fisico, non logico).
- **`schema.sql`** aggiornato per i nuovi install con tabella `setting`, FK e seed.
- **`SettingModel`** (`App\Models\SettingModel`): `listAttivi()` + `findByCodice()`. Read-only: la tabella è "anagrafica di sistema", non c'è una UI CRUD.
- **Model esistenti**:
  - `OperatoreModel`: `id_setting` in fillable, `listWithCategoria` accetta `?idSetting` per filtrare, JOIN con `setting` per esporre `setting_codice` / `setting_nome` in lista.
  - `PianoTurnoModel`: `id_setting` in fillable, `listOrdered(?stato, ?idSetting)` con JOIN setting, nuovi `findByAnnoMeseSetting()` e `findWithSetting()` (usato dalla `show` per avere subito il nome del setting nella view).
  - `UtenteModel`: `id_setting` in fillable, `listAllOrdered` ora joina `setting` per mostrare il setting di default nella lista utenti.
  - `SaldoOreModel`: `listByAnnoMese(anno, mese, ?idSetting)` e `deleteByAnnoMese(anno, mese, ?idSetting)` filtrano per setting di casa dell'operatore. Necessario perché il saldo è unico (operatore, anno, mese) e cumula i turni di entrambi i setting, ma il calendario di un piano deve mostrare/eliminare solo i saldi degli operatori "di casa" in quel setting.
- **Validator**:
  - `OperatoreValidator`: secondo argomento `$settingIdValidi` al costruttore; nuovo blocco `id_setting` (required, intero, in lista).
  - `PianoTurnoValidator`: costruttore con `$settingIdValidi`; nuovo blocco `id_setting`.
  - `UtenteValidator`: terzo argomento opzionale `$settingIdValidi`; `id_setting` opzionale (vuoto → NULL = utente globale).
- **Controller**:
  - `OperatoriController`: passa setting al validator, alle view, filtro `?setting=hospice|ucp_dom` nell'index.
  - `PianiTurnoController`: store filtra operatori attivi per `id_setting` del piano; check duplicato per `(anno, mese, id_setting)` con messaggio user-friendly; show usa `findWithSetting` e passa l'id_setting a `listByAnnoMese`; destroy passa l'id_setting a `deleteByAnnoMese` (così non tocca i saldi dell'altro piano); index ha tabs "stato" + tabs "setting"; create pre-seleziona il setting di default dell'utente loggato se valorizzato.
  - `UtentiController`: setting opzionale per ogni utente (NULL = globale).
  - `TurniController`: la `validateContestoOperatoreData` e la `validateRiferimenti` rifiutano operatori il cui `id_setting` non coincide con quello del piano. Cross-setting "ammesso" arriverà in 4-ter ("Aggiungi operatore al piano").
- **Viste**: form operatore, form piano e form utente con la select `setting`; index operatori/piani con tabs di filtro per setting; index utenti con colonna setting; show piano con badge setting nell'intestazione; dashboard banner aggiornato.

### Decisioni di sessione

| Punto | Scelta |
|---|---|
| Tabella `setting` | Anagrafica di sistema seedata dalla migrazione, niente UI CRUD. Aggiungere un nuovo setting in futuro = nuova migrazione |
| `setting.codice` | Slug tecnico (`hospice`, `ucp_dom`) usato nelle querystring di filtro, per evitare di esporre ID interni in URL |
| `operatori.id_setting` NOT NULL | Ogni operatore appartiene sempre a UN setting "di casa". Lo spostamento lungo si fa cambiando questo campo; lo spostamento breve assegnando turni nel piano dell'altro setting senza toccarlo |
| `piano_turni.id_setting` NOT NULL + UNIQUE (anno, mese, id_setting) | Due piani per mese sono la nuova normalità. Mantengono indipendenti i saldi iniziali "fotografati" alla creazione |
| `utenti.id_setting` NULL | Admin e visualizzatori globali restano NULL. Per la caposala è un default UI, non un vincolo di scrittura: chi ha il permesso (admin/caposala) può scrivere su entrambi i piani per coprire sostituzioni in emergenza |
| Saldo cross-setting | Tabella `saldo_ore` invariata (UNIQUE su `id_operatore`, `anno`, `mese`). Le query `listByAnnoMese`/`deleteByAnnoMese` filtrano per setting di casa via JOIN su `operatori` quando serve |
| Cross-setting nei turni | Per ora vietato: un turno in piano X richiede operatore con `id_setting = X`. La gestione esplicita ("Aggiungi operatore al piano" con `ore_dovute` editabili) arriva in 4-ter |
| Backfill utenti | NON backfilliamo `utenti.id_setting`: gli utenti esistenti restano globali finché l'admin non li assegna esplicitamente. Evita di scegliere al posto suo |
| Filtri UI | Querystring `?setting=<codice>` su `/operatori` e `/piani-turno`. Combina con `?stato=` e `?inattivi=` quando già presenti |

### Da fare prima della prossima sessione (lato utente)

1. **Backup del DB** (la migrazione 0002 modifica colonne NOT NULL e ricostruisce la UNIQUE di `piano_turni`):
   ```bash
   mysqldump -u hospice_user -p hospice_turni > backup-pre-0002-$(date +%Y%m%d).sql
   ```
2. **Applicare la migrazione**:
   ```bash
   mysql -u hospice_user -p hospice_turni < database/migrations/0002_introduce_setting.sql
   ```
3. `composer dump-autoload` (per assicurare classmap dei nuovi `SettingModel`).
4. Riavviare `php -S localhost:8000 -t public/` e testare con **admin**:
   - `/operatori` → l'elenco mostra ora la colonna **Setting** (tutti gli esistenti = Hospice). Tabs `Hospice` / `UCP-DOM` filtrano correttamente.
   - Modifica un operatore: il setting è obbligatorio nel form. Cambia uno o due operatori a `UCP-DOM` per i test successivi.
   - Crea un nuovo operatore in `UCP-DOM`.
   - `/utenti` → la colonna **Setting** mostra «globale» per tutti gli utenti esistenti. Modifica la caposala assegnandole `Hospice` come setting di default; verifica che resti `globale` per l'admin.
   - `/piani-turno/create` → ora c'è la select **Setting**. Se l'utente loggato ha `id_setting` valorizzato è pre-selezionato.
   - Crea un piano `Hospice` per il mese corrente (deve includere solo i saldi degli operatori di casa Hospice).
   - Crea un secondo piano `UCP-DOM` per **lo stesso mese** → deve essere accettato (UNIQUE ora include il setting). Il piano UCP-DOM deve mostrare solo gli operatori del setting UCP-DOM nel calendario e nella tabella saldi.
   - Su un mese con entrambi i piani in bozza: elimina il piano Hospice → deve cancellare solo i saldi degli operatori Hospice; i saldi del piano UCP-DOM restano intatti (verifica nel calendario UCP-DOM).
   - Tab di filtro setting in `/piani-turno`: ogni tab restituisce solo i piani del setting.
   - Assegna un turno nel piano Hospice: verifica che il calendario non lasci cliccare/forzare un operatore di casa UCP-DOM (eventuale URL manipolato `?operatore=...` con operatore di altro setting → errore "L'operatore non appartiene al setting di questo piano").
5. Loggarsi con **caposala** assegnata al setting `Hospice`: l'UI è invariata, ma se entra in `/piani-turno/create` la select setting è pre-selezionata. Verificare che possa comunque creare un piano `UCP-DOM` (default è solo UX).
6. Loggarsi con **visualizzatore**: i nuovi filtri devono essere accessibili in lettura, nessun bottone di scrittura.

### Prossima sessione (4-ter)

Operatori in itinere + saldi editabili. Azione «Aggiungi operatore al piano» (con `ore_dovute` e `saldo_progressivo_iniziale` editabili); modifica `ore_dovute` su saldi esistenti (pro-rata, recuperi); aggancio del `saldo_progressivo` al numero del cedolino comunicato dall'operatore. Note libere obbligatorie sulle modifiche manuali. A quel punto il check "operatore non appartiene al setting" del calendario diventerà permissivo: l'operatore di un altro setting si potrà aggiungere esplicitamente al piano.

---

## Sessione 4-ter — 2026-05-17 — Operatori in itinere + saldi editabili

### Cosa è stato fatto

- **Migrazione `0003_operatori_dates_piano_operatori.sql`**:
  - `operatori.data_assunzione DATE NULL`, `operatori.data_cessazione DATE NULL` (informative, niente pro-rata automatico).
  - Nuova tabella `piano_operatori (id_piano, id_operatore, aggiunto_manualmente, aggiunto_da, note_aggiunta)` con UNIQUE `(id_piano, id_operatore)`, CASCADE da `piano_turni`. Materializza l'appartenenza di un operatore a un piano specifico (prima era implicita "di casa nel setting"). Backfill: ogni saldo esistente in (anno, mese) viene legato al piano di quello stesso (anno, mese) il cui setting coincide col setting di casa dell'operatore.
  - Nuova tabella `saldo_modifiche (id_saldo, id_utente, tipo_modifica, valore_precedente, valore_nuovo, note NOT NULL)`: storico delle modifiche manuali per ore_dovute, saldo_progressivo e aggiunta esplicita.
  - `schema.sql` aggiornato di conseguenza per nuovi install.
- **Operatori — date informative**: `OperatoreModel.fillable` esteso a `data_assunzione`/`data_cessazione`. Nuovi metodi `findInServizioNelMese(anno, mese, ?idSetting)` (filtro: attivi + non cessati pre-mese + non ancora da assumere post-mese) e `findCandidatiAggiunta(idPiano, anno, mese)` (in servizio nel mese, non già in `piano_operatori`, ammessi anche da altro setting). Form operatore con due input `date` + helper text. `OperatoreValidator` valida le date `Y-m-d` opzionali con coerenza `cessazione >= assunzione`. Nuova regola `Rules::date()` con round-trip per scartare le date "morbide" (es. 2026-02-31).
- **Nuovi Model**:
  - `PianoOperatoreModel`: fillable + `listInPiano(idPiano, anno, mese)` (JOIN operatori + categoria + setting + LEFT JOIN saldo del mese, ordinato per categoria → cognome → nome), `isInPiano`, `findInPiano`, `listOperatoriInAltriPianiDelMese(idPianoEscluso, anno, mese)`, `countTurniOperatoreInPiano`.
  - `SaldoModificaModel`: `listBySaldo(idSaldo)` joinato con utenti per la UI storico.
- **`PianiTurnoController.store`** ora include automaticamente gli operatori via `findInServizioNelMese` (filtro automatico per assunti/cessati) e popola `piano_operatori` per ognuno. Se il saldo (op, anno, mese) esiste già (perché l'op è in itinere nell'altro piano del mese: il saldo è unico cross-setting) NON viene ricreato.
- **`PianiTurnoController.show`** non filtra più per `id_setting`: usa `PianoOperatoreModel.listInPiano` come fonte di verità. Mostra badge "in itinere · <setting>" per gli operatori aggiunti manualmente che provengono da altro setting.
- **`PianiTurnoController.destroy`**: il saldo è cross-piano (unico per op/anno/mese), quindi va eliminato SOLO per gli operatori che non sono presenti in altri piani dello stesso mese (`SaldoOreModel.deleteByAnnoMeseEscludendoOperatori`). `deleteByAnnoMese` rimosso. CASCADE su `piano_operatori` pulisce la tabella di appartenenza in automatico.
- **Nuovo `SaldiController`** (admin + caposala, solo piani in bozza, ogni mutazione registra una riga in `saldo_modifiche` con nota motivazione obbligatoria a livello applicativo):
  - `addOperatoreForm` / `addOperatore`: seleziona un candidato (lista da `findCandidatiAggiunta`), imposta `ore_dovute` e `saldo_progressivo_iniziale`, salva nota. Crea saldo SOLO se non esiste già (rispetto saldo cross-setting); crea riga `piano_operatori` con `aggiunto_manualmente=1`; logga `tipo_modifica='aggiunta_operatore'`.
  - `editForm` / `update`: modifica `ore_dovute` e/o `saldo_progressivo` di un saldo esistente con nota obbligatoria. Se cambia `ore_dovute` rilancia `SaldoRicalcoloService::ricalcola` (le ore lavorate restano, cambia saldo_mese → propagazione progressivo). Se cambia `saldo_progressivo` lo scrive direttamente e poi `propagaDaQui` ricostruisce i mesi successivi. I check di no-op silenzioso (valori identici a quelli attuali) sono espliciti.
  - `removeOperatore`: rimuove un operatore solo se `aggiunto_manualmente=1` e non ha turni nel piano. Elimina il saldo solo se l'op non è in altri piani dello stesso mese (regola simmetrica alla destroy del piano).
- **`SaldoValidator`** dedicato con due flussi distinti (`validateAggiunta`, `validateModifica`), nota sempre obbligatoria, almeno uno tra ore_dovute/saldo_progressivo richiesto in update.
- **`TurniController`**: il veto "L'operatore non appartiene al setting di questo piano" è stato rimosso. Ora la verifica unica di appartenenza è `PianoOperatoreModel.isInPiano(idPiano, idOperatore)`, che copre sia gli operatori inclusi automaticamente che gli aggiunti in itinere — anche cross-setting. Messaggio di errore aggiornato: "Aggiungilo dal piano (azione «+ Aggiungi operatore»)".
- **`SaldoRicalcoloService`**: nuovo metodo pubblico `propagaDaQui(op, anno, mese, progressivoCorrente)` che espone la propagazione esistente per il caso "reset di verità" del progressivo.
- **Rotte** sotto `/piani-turno/{id}`:
  - `GET /aggiungi-operatore` + `POST /aggiungi-operatore`
  - `POST /operatori/{opid}/rimuovi`
  - `GET /saldi/{sid}/edit` + `POST /saldi/{sid}`
- **Viste**:
  - `views/saldi/add_operatore.twig`: select candidati con etichetta `cognome nome — categoria (setting)`, ore_dovute, saldo_progressivo iniziale, nota obbligatoria.
  - `views/saldi/edit.twig`: modifica ore_dovute / saldo_progressivo, nota obbligatoria, tabella storico modifiche con tipo, utente, valori prima/dopo, nota.
  - `views/piani_turno/show.twig`: bottone "+ Aggiungi operatore" sopra il calendario (solo se editabile); colonna "Azioni" nella tabella saldi (Modifica + Rimuovi per i `aggiunto_manualmente`); badge "in itinere" sulle righe.
  - `views/operatori/form.twig`: input date per assunzione/cessazione con form-text che chiarisce la semantica ("informativa, niente pro-rata automatico").
  - `views/dashboard/index.twig`: banner aggiornato a sessione 4-ter.

### Decisioni di sessione

| Punto | Scelta |
|---|---|
| Appartenenza op→piano | Materializzata in `piano_operatori`. Fotografata alla create per gli "di casa", aggiunta manualmente per gli in itinere. Il check di sicurezza dei turni passa da "stesso setting" a "deve essere in `piano_operatori`" |
| Saldo cross-setting | Tabella `saldo_ore` invariata: UNIQUE su `(op, anno, mese)`, valori unici cross-piano. La "appartenenza al piano" non cambia: cambia solo da quali piani il saldo è visibile |
| Date assunzione/cessazione | Solo informative + filtro automatico nell'inclusione operatori della create. Niente pro-rata automatico: la riduzione delle ore_dovute si fa a mano via "Modifica saldo" (coerente con il principio "numero giusto a mano, niente automatismi opachi") |
| Saldo già esistente in aggiunta | Quando si aggiunge in itinere un op che ha già un saldo (perché è anche nell'altro piano del mese) NON si sovrascrive: si crea solo la riga `piano_operatori`. I valori "iniziali" inseriti dall'utente vengono ignorati. Coerente col fatto che il saldo è unico cross-setting |
| Nota motivazione | Obbligatoria a livello applicativo (`NOT NULL` nel DB) per ogni modifica manuale. Sale in `saldo_modifiche` insieme a valori prima/dopo e id_utente |
| Reset progressivo | Va scritto direttamente (NON ricalcolato dalle ore mese), POI propagato ai mesi successivi via `propagaDaQui`. Caso d'uso: aggancio al numero del cedolino comunicato dall'operatore |
| Rimozione operatore | Permessa solo per `aggiunto_manualmente=1` e con zero turni nel piano. Gli operatori inclusi alla creazione restano legati al piano per coerenza con la composizione originale: se vanno tolti, eliminare il piano e ricreare |
| Destroy piano | Elimina saldi solo per op non presenti in altri piani dello stesso mese. CASCADE su `piano_operatori` (FK piano) pulisce automaticamente la tabella di appartenenza |
| Propagazione progressivo da update | `ricalcola` viene chiamata quando cambia `ore_dovute` (perché cambia saldo_mese); `propagaDaQui` quando cambia direttamente `saldo_progressivo`. Ordine in transazione: prima ricalcola (overwrite progressivo dal saldo_mese), poi imposta il nuovo `saldo_progressivo` (se richiesto) e propaga |
| FK `piano_op_operatore` RESTRICT | Eliminare un operatore con appartenenze attive in piani fallisce (preserva storico). Si forza prima `attivo=0` o si rimuove dai piani in bozza |
| PDO posizionali nel DELETE batch | `deleteByAnnoMeseEscludendoOperatori` usa `?` posizionali con `IN (?,?,?...)` per evitare riuso named placeholder (feedback memory PDO) |

### Da fare prima della prossima sessione (lato utente)

1. **Backup del DB** (la migrazione 0003 aggiunge colonne e tabelle ma fa anche un backfill):
   ```bash
   mysqldump -u hospice_user -p hospice_turni > backup-pre-0003-$(date +%Y%m%d).sql
   ```
2. **Applicare la migrazione**:
   ```bash
   mysql -u hospice_user -p hospice_turni < database/migrations/0003_operatori_dates_piano_operatori.sql
   ```
3. `composer dump-autoload` (per assicurare classmap dei nuovi `SaldiController`, `PianoOperatoreModel`, `SaldoModificaModel`, `SaldoValidator`).
4. Riavviare `php -S localhost:8000 -t public/` e con utente **admin** o **caposala**:
   - `/operatori` → modifica un paio di operatori valorizzando `data_assunzione`. Su uno valorizza anche `data_cessazione` con data del mese corrente e un altro con data del mese precedente.
   - Crea un nuovo piano `Hospice` per il **prossimo** mese: l'operatore cessato nel mese precedente NON deve comparire nella lista alla creazione. L'operatore assunto nel mese corrente sì.
   - Su un piano in bozza esistente: bottone **+ Aggiungi operatore** sopra il calendario. La lista candidati include operatori di entrambi i setting (purché non già in piano e in servizio nel mese). Aggiungine uno dall'altro setting con `ore_dovute = 80` e `saldo_progressivo = 0`, nota "spostamento per copertura ferie".
   - Sulla tabella saldi compare la nuova riga col badge **in itinere**. Cliccare **Modifica** sulla riga di un operatore qualsiasi: form con storico vuoto al primo accesso. Cambia `ore_dovute` da 165 a 132, nota "cessazione 15/05 — 132h dovute residue". Verificare che `saldo_mese` si aggiorni e che il `saldo_progressivo` dei mesi successivi (se ci sono) si propaghi.
   - Modifica `saldo_progressivo` con un valore arbitrario (es. da -8.00 a +4.00) e nota "aggancio cedolino aprile". Lo storico modifiche deve mostrare due righe. Se ci sono mesi successivi, i loro progressivi devono essere ricostruiti a partire dal nuovo valore.
   - Cliccare il calendario di una cella dell'operatore aggiunto in itinere → assegna un turno. Deve funzionare (il vecchio veto "non appartiene al setting" è andato).
   - Provare a **Rimuovere** l'operatore aggiunto in itinere DOPO avergli messo un turno: deve fallire con messaggio "rimuovi prima i turni assegnati". Eliminare il turno e ritentare: deve succedere.
   - Provare a Rimuovere un operatore incluso automaticamente (non `aggiunto_manualmente`): deve fallire con messaggio dedicato.
   - Pubblicare il piano: bottoni "+ Aggiungi operatore" e Modifica/Rimuovi devono sparire.
   - Caso cross-piano dello stesso mese: crea due piani (Hospice e UCP-DOM) dello stesso mese, aggiungi lo stesso operatore in itinere in entrambi (NB: la seconda aggiunta non ricrea il saldo, riusa quello esistente). Elimina uno dei due piani in bozza: il saldo deve rimanere intatto perché l'op è ancora nell'altro piano.
5. Loggarsi con **visualizzatore**: deve vedere la tabella saldi senza la colonna **Azioni** né i bottoni di aggiunta. URL manipolati (`/piani-turno/{id}/saldi/{sid}/edit`) devono dare 403.

### Prossima sessione (4-quater) — riveduta il 2026-05-18

Roadmap rivista: la sessione 4-quater non è più "overlay cross-setting" ma **refactoring del ricalcolo saldi + revisione di `removeOperatore`** — chiusura del debt emerso rileggendo il codice 4-ter. Quattro modifiche, niente migration, niente UI nuova. Spec autoesplicativa in `spec-sessione-4-quater.md` (a livello root del repo). Riassunto:

1. Split di `SaldoRicalcoloService::ricalcola()` in `ricalcolaMese` (solo mese, ritorna progressivo) + `propagaDaQui` (catena successiva), per evitare la doppia propagazione quando `SaldiController::update` cambia sia `ore_dovute` sia `saldo_progressivo`. `ricalcola()` resta come wrapper di comodo.
2. `SaldiController::update`: propagazione **unica** a fine transazione con il valore "vincitore" (manuale se presente, calcolato altrimenti). Rimosso il commento-pezza "deve essere DOPO altrimenti il ricalcolo lo sovrascriverebbe".
3. `SaldiController::removeOperatore`: rimosso il gate `aggiunto_manualmente=1`. Ora basta zero turni: si può rimuovere anche un operatore incluso automaticamente quando non ha turni (caso dimissione infra-mese). Il flag resta informazione storica nella tabella e nel log.
4. Nuovo helper `SaldoRicalcoloService::rimuoviSaldoSeOrfano(idOp, anno, mese, operatoriInAltriPianiDelMese)` che centralizza "delete saldo se non in altri piani del mese + propaga catena progressivo successiva" (prima della 4-quater i mesi successivi restavano sfasati dopo cancellazione). Usato sia da `SaldiController::removeOperatore` sia da `PianiTurnoController::destroy` (che ora inietta il service). Rimosso `SaldoOreModel::deleteByAnnoMeseEscludendoOperatori` (sostituito dal loop con helper).

Test manuali 1-7 in spec (entrambi i campi update, un solo campo update, rimozione operatore "auto" senza turni, rimozione operatore con turni ancora bloccata, catena progressivo post-cancellazione, destroy con operatore cross-setting, destroy con operatore solo in quel piano).

**Poi:** 4-quinquies (overlay cross-setting), 4-sexies (CRUD `assenze` + flag `tipi_turno.esclude_pianificazione` per maternità nascoste), 5 (vincoli bloccanti), 6 (generatore automatico).
