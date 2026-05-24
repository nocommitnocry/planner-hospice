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

## Sessione 4-quater — 2026-05-18 — Refactor ricalcolo saldi + revisione removeOperatore

### Cosa è stato fatto

- **`SaldoRicalcoloService`** — split di `ricalcola()`:
  - Nuovo `ricalcolaMese(idOp, anno, mese): ?float` che ricalcola **solo** il mese dai turni effettivi e ritorna il nuovo `saldo_progressivo` (o `null` se il saldo non esiste). NON propaga.
  - `ricalcola()` resta come wrapper di comodo (uso primario `TurniController`): chiama `ricalcolaMese` + `propagaProgressivo`.
  - `propagaDaQui()` invariato.
  - Nuovo `rimuoviSaldoSeOrfano(idOp, anno, mese, opInAltriPianiDelMese)`: se l'op non è in altri piani del mese, cancella il saldo e propaga la catena dei progressivi successivi a partire dal progressivo del mese **precedente** (il mese cancellato sparisce sia come record sia come contributo alla catena).
- **`SaldiController::update`** — propagazione **unica** a fine transazione con il valore "vincitore":
  - Se cambia `ore_dovute`: `ricalcolaMese` (riscrive ore_*, saldo_mese, saldo_progressivo dal calcolo).
  - Se cambia `saldo_progressivo`: UPDATE diretto del valore manuale (sovrascrive eventualmente quello appena calcolato).
  - Una sola `propagaDaQui` a fine transazione con il valore vincente. Rimosso il commento-pezza "DOPO il ricalcolo".
- **`SaldiController::removeOperatore`** — gate unico **zero turni** (rimosso il check `aggiunto_manualmente=1`). Caso d'uso sbloccato: dimissione infra-mese di un operatore di casa con turni già spostati su un altro op. Il flag `aggiunto_manualmente` resta informazione storica nella tabella e ora viene loggato anche al momento della rimozione. Label log aggiornata a `(4-quater)`.
- **`PianoOperatoreModel::listIdOperatoriByPiano(idPiano)`** — nuova: ritorna `list<int>` di id_operatore del piano. Usata dalla `destroy` per fare lo snapshot prima del CASCADE.
- **`PianiTurnoController::destroy`** — refactor:
  - Iniettato `SaldoRicalcoloService` nel controller.
  - Snapshot di `opInAltriPiani` e `operatoriDelPiano` PRIMA del delete.
  - **Delete del piano PRIMA del loop** (così il ricalcolo del ramo "in altri piani" vede solo i turni residui dopo il CASCADE su `fk_turni_piano`).
  - Loop per operatore con due rami:
    - Op in altri piani del mese → `ricalcola(op, anno, mese)` (le ore lavorate vanno rifatte dai turni rimasti, altrimenti restano gonfiate dei turni del piano cancellato).
    - Op solo nel piano cancellato → `rimuoviSaldoSeOrfano` (delete + propaga catena).
- **`SaldoOreModel::deleteByAnnoMeseEscludendoOperatori`** rimosso. Sostituito dal loop con helper.
- **`views/piani_turno/show.twig`** — rimossa la condizione `s.aggiunto_manualmente` sul bottone **Rimuovi**: ora il bottone appare per ogni operatore con saldo, e il gate "zero turni" viene applicato lato controller. Il badge "in itinere" resta per gli aggiunti manualmente (informativo).

### Bug catturato durante i test (Test 6 — operatore cross-setting)

Prima del fix di second-pass: cancellando il piano A in cui l'operatore X aveva turni, il saldo cross-setting non veniva cancellato (corretto, perché X è anche in B), MA le `ore_lavorate` del saldo **non venivano ricalcolate** e restavano gonfiate dei turni del piano A appena rimossi dal CASCADE. La spec originale prevedeva solo `rimuoviSaldoSeOrfano` nel loop, che per gli op cross-setting fa no-op. Aggiunto il ramo `ricalcola` esplicito.

### Decisioni di sessione

| Punto | Scelta |
|---|---|
| Split ricalcola | `ricalcolaMese` (solo mese, ritorna progressivo) + `propagaDaQui` (solo catena). `ricalcola` resta come wrapper di comodo |
| Update saldo doppio | Propagazione unica a fine transazione con il valore "vincitore" (manuale se presente, calcolato altrimenti). Niente doppia propagazione |
| Rimozione operatore | Gate unico: zero turni nel piano. Il flag `aggiunto_manualmente` non è più condizione di rimovibilità, resta informazione storica nella tabella e nel log |
| Helper saldo orfano | `SaldoRicalcoloService::rimuoviSaldoSeOrfano` centralizza "delete se non in altri piani del mese + propaga catena". Usato sia da `SaldiController::removeOperatore` sia da `PianiTurnoController::destroy` |
| Destroy cross-setting (Test 6) | Due rami nel loop di destroy: op cross-setting → `ricalcola` (rifa ore dai turni residui); op solo nel piano → `rimuoviSaldoSeOrfano`. Il delete del piano va PRIMA del loop perché il ricalcolo deve vedere SOLO i turni dopo il CASCADE |
| Catena progressivo post-cancellazione | Dopo `rimuoviSaldoSeOrfano` i progressivi dei mesi successivi vengono ricostruiti partendo dal progressivo del mese precedente al cancellato. Prima della 4-quater restavano sfasati |
| `deleteByAnnoMeseEscludendoOperatori` | Rimosso. Sostituito dal loop con helper in `destroy` |

### Da fare prima della prossima sessione (lato utente)

1. `composer dump-autoload` (già eseguito durante la sessione, ma non guasta rifarlo dopo il pull).
2. Riavviare il server e testare il Test 6 della spec (operatore cross-setting): cancellando il piano A le ore lavorate del saldo devono scendere a quelle dei turni del solo piano B.
3. Spot-check sugli altri test 1-7 della spec.

### Prossima sessione (4-quinquies) — overlay cross-setting

Nel calendario del piano corrente, per gli operatori del piano che hanno turni nell'altro piano dello stesso mese (cross-setting), mostrarli come overlay grigio non cliccabile con tooltip che indica il setting di origine. Serve a rendere visibili i conflitti potenziali prima di sbatterci contro l'unique `(operatore, data)` su `turni`. Il modello è già in posto (4-bis introduce setting, 4-ter materializza `piano_operatori`), serve solo aggiungere una query "turni dell'op in altri piani del mese" e renderizzare il calendario di conseguenza.

**Poi:** 4-sexies (CRUD `assenze` + flag `tipi_turno.esclude_pianificazione`), 5 (vincoli bloccanti), 6 (generatore automatico).

---

## Sessione 4-quinquies — 2026-05-19 — Overlay cross-setting nel calendario

### Cosa è stato fatto

- **`TurnoModel::listCrossSettingPerPiano(idPiano, anno, mese)`** — nuova query. Ritorna i turni assegnati nello stesso (anno, mese) in piani DIVERSI da quello dato, limitati agli operatori che sono in `piano_operatori` del piano corrente. Ogni riga include `id_operatore`, `data`, `note`, `tipo_codice`/`tipo_descrizione`/`tipo_colore`, `piano_origine_id`, `setting_codice`/`setting_nome`. Due placeholder distinti (`:id_piano_corrente`, `:id_piano_escluso`) con lo stesso valore per rispettare la regola "niente named placeholder riusati".
- **`PianiTurnoController::show`** — costruisce `crossSettingByOpData = [id_operatore][YYYY-MM-DD] => turnoCross` accanto a `turniByOpData` e lo passa alla view. Commento esplicito che l'UNIQUE `(operatore, data)` su `turni` garantisce mutua esclusione: una cella non può avere contemporaneamente un turno del piano corrente e uno cross-setting.
- **`views/piani_turno/show.twig`** — nuovo ramo della cella PRIMA dei due esistenti: se `not turno and cross`, renderizza `<td class="cella-cross-setting">` con `cross-banda` (banda laterale 4px col `tipo_colore` originale), `cross-codice` (codice attenuato), e `title` "{codice} · {descrizione} — nel piano {setting_nome} ({note})". Vale sia in modalità editabile sia read-only (lo stato del piano corrente non cambia il fatto che la cella è occupata altrove).
- **`public/css/app.css`** — classe `.cella-cross-setting`: background `#f1f3f5`, `cursor: not-allowed`, font italico, padding asimmetrico per fare spazio alla banda. `.cross-banda` posizionata in absolute sul lato sinistro, `.cross-codice` con `opacity: 0.7` e font-weight 600. Niente outline hover (la cella non è interattiva).
- **`views/dashboard/index.twig`** — banner aggiornato a sessione 4-quinquies.

### Bug catturato durante i test — turni fuori dal periodo di servizio

Olga ha provato a inserire un turno il 30/05 a un operatore con `data_cessazione = 2026-05-29`. Il sistema lo accettava (sia in bozza sia post-`unpublish`). Il buco era esistente dalla 4-ter: le date di assunzione/cessazione filtrano la `findInServizioNelMese` alla creazione del piano (l'op non entra se cessato pre-mese o non ancora assunto post-mese), ma una volta che l'op è in `piano_operatori` nessuno controlla più la finestra a livello di singolo turno. Fix integrata nella 4-quinquies:

- **`TurniController`** — nuovo helper privato `messaggioFuoriFinestra($operatore, $dataTurno): ?string` che ritorna un messaggio user-friendly se `dataTurno < data_assunzione` o `dataTurno > data_cessazione` (confronto lessicografico su stringa `Y-m-d`, identico al confronto cronologico). `validateRiferimenti` lo invoca dopo aver verificato l'appartenenza al piano: in caso di violazione aggiunge l'errore alla chiave `data` e il flusso `store` lo restituisce al form via `redirectAlForm` con old input e messaggi standard. `update` e `destroy` non sono toccati: l'utente deve poter modificare il tipo o cancellare un turno esistente anche se "fuori finestra" (caso: data_cessazione aggiunta retroattivamente).
- **`TurniController::edit`** — calcola `fuoriFinestra` (lo stesso helper) e lo passa al form: alert rosso informativo prima del submit. Non blocca l'apertura del form: serve a permettere edit/delete di turni esistenti.
- **`views/turni/form.twig`** — alert `alert-danger` se `fuoriFinestra` è valorizzato, con testo specifico per "turno esistente" (modificabile/eliminabile) vs "nuovo turno" (rifiutato al submit).
- **`PianoOperatoreModel::listInPiano`** — esposti due nuovi alias `operatore_data_assunzione` e `operatore_data_cessazione` (presi dall'op joinato). Servono al calendario.
- **`views/piani_turno/show.twig`** — nel ciclo cella, prima del ramo `celleEditabili`: se non c'è `turno` né `cross`, e `g.date < data_assunzione` o `g.date > data_cessazione`, render `<td class="cella-fuori-finestra">` con tooltip "Operatore assunto solo dal …" / "Operatore cessato il …". Le celle con turno esistente restano cliccabili (per modifica/elimina) — coerente con la regola server-side.
- **`public/css/app.css`** — classe `.cella-fuori-finestra` con retino diagonale tratteggiato (repeating-linear-gradient 135°, grigio chiaro). Distinta visivamente sia dalla cella vuota normale sia dal `.cella-cross-setting`.

### Decisioni di sessione

| Punto | Scelta |
|---|---|
| Mutua esclusione cella corrente vs cross | Garantita dall'UNIQUE `(operatore, data)` su `turni`. In Twig il check è `not turno and cross`: se c'è un turno nel piano corrente quella ha la precedenza, l'altro è semplicemente impossibile |
| Overlay informativo (variante A) | Codice del tipo turno visibile ma attenuato + banda laterale 4px col colore originale + tooltip con descrizione e setting di origine. Variante B (retino grigio uniforme) scartata per evitare di rendere la cella "cieca": vedere a colpo d'occhio se l'op è già in M, F o N nell'altro piano è il punto della feature |
| Filtro operatori cross | La query joina `piano_operatori` del piano corrente per limitare il sottoinsieme. Un operatore presente solo nell'altro piano (mai aggiunto in itinere al mio) non genera celle: non lo mostro neanche nella tabella saldi, quindi non avrebbe una riga su cui dipingere l'overlay |
| Stati del piano corrente | L'overlay si vede in qualunque stato (bozza/pubblicato/archiviato): è informazione di realtà su un altro piano, non un'azione del mio |
| Placeholder duplicato | `:id_piano_corrente` + `:id_piano_escluso` con lo stesso valore. Coerente con la regola PDO già stabilita (no named placeholder riusati). Niente posizionali perché qui non c'è `IN (...)` variabile |
| Niente migration, niente nuova rotta | Sessione di sola lettura: la query usa solo tabelle esistenti, il rendering è additivo, nessun cambio di flusso |
| Finestra di servizio (fix bug) | Check server-side in `validateRiferimenti` (`store` rifiuta turni fuori finestra). `update`/`destroy` non bloccati: servono per pulire turni esistenti dopo aver inserito retroattivamente `data_cessazione`. UI: cella vuota fuori finestra diventa overlay tratteggiato non cliccabile; cella con turno esistente resta cliccabile per consentire modifica/elimina |
| Confronto date | Lessicografico su stringa `Y-m-d` sia in PHP sia in Twig: coincide con quello cronologico, evita conversioni `DateTime` per ogni cella del calendario |

### Da fare prima della prossima sessione (lato utente)

1. `composer dump-autoload` (la modifica al `TurnoModel` aggiunge solo un metodo, l'autoload classmap esistente è già a posto in dev — ma non guasta).
2. Riavviare `php -S localhost:8000 -t public/` e con utente **admin** o **caposala**:
   - **Setup**: avere due piani in bozza per lo **stesso mese** (uno Hospice, uno UCP-DOM). Aggiungere in itinere lo stesso operatore X in entrambi (azione 4-ter "+ Aggiungi operatore al piano").
   - **Test 1 (overlay base)**: nel piano Hospice assegnare un turno `M` a X il giorno 5. Aprire il piano UCP-DOM: la cella `X × giorno 5` deve essere grigia con banda laterale rossa (o del colore di `M`), codice `M` in italico attenuato, tooltip "M · Mattina — nel piano Hospice". Non cliccabile.
   - **Test 2 (rimozione turno)**: tornare al piano Hospice, eliminare il turno di X il giorno 5. Aprire UCP-DOM: la cella deve essere tornata `+` cliccabile (overlay sparito).
   - **Test 3 (op solo nel mio piano)**: un operatore Y di casa Hospice, NON in itinere su UCP-DOM. Assegnargli un turno qualsiasi nel piano Hospice. Aprire UCP-DOM: nessuna riga per Y (atteso: non è nei suoi `piano_operatori`).
   - **Test 4 (piano pubblicato)**: pubblicare il piano UCP-DOM. La cella cross di X il giorno 5 (se c'è ancora un turno in Hospice) deve restare visibile come overlay grigio anche in read-only. Le altre celle senza turno mostrano spazio vuoto.
   - **Test 5 (cross verso archiviato)**: archiviare il piano Hospice. Aprire UCP-DOM: l'overlay deve restare visibile (la query non filtra per stato del piano d'origine — un turno archiviato è comunque un fatto storico per l'op).
   - **Test 6 (UNIQUE su turni)**: nel piano UCP-DOM provare a cliccare una cella diversa di X (non in overlay) e assegnare un turno. Deve funzionare. Tentare manualmente via URL di forzare un turno su una cella in overlay sarebbe comunque rifiutato dall'UNIQUE DB (SQLSTATE 23000), ma il workflow normale non lo permette perché la cella non è un link.
   - **Test 7 (fuori finestra — cessazione)**: a un operatore del piano impostare `data_cessazione = 2026-05-29`. Aprire il piano del mese: le celle dal 30 al 31 dell'op devono avere il retino tratteggiato grigio e tooltip "Operatore cessato il 29/05/2026". Cliccarci non deve fare nulla (non è un link). Tentare via URL `/piani-turno/{id}/turni/edit?operatore=X&data=2026-05-30` apre il form ma mostra alert rosso e il submit deve fallire con messaggio specifico.
   - **Test 8 (fuori finestra — assunzione)**: simmetrico col `data_assunzione = 2026-05-15`. Celle 01-14 in overlay tratteggiato, tooltip "Operatore assunto solo dal 15/05/2026".
   - **Test 9 (turno esistente in finestra retro-aggiunta)**: assegnare turni al 30 e 31 a un op, POI inserire `data_cessazione = 2026-05-29`. I turni esistenti restano visibili nelle loro celle e cliccabili. Aprendoli si vede l'alert rosso. Si possono modificare (cambio tipo a `F`) o eliminare. Tentare di crearne di nuovi in altre celle fuori finestra fallisce.
3. Loggarsi con **visualizzatore** su un piano pubblicato: deve vedere l'overlay grigio come gli altri ruoli, tooltip funzionante, nessun link. Anche le celle fuori finestra dell'operatore devono apparire tratteggiate.

### Prossima sessione (4-sexies) — CRUD assenze + maternità nascosta

Anagrafica `assenze` (ferie/malattia/permesso/maternità con `data_inizio`/`data_fine`), CRUD admin+caposala, e flag `tipi_turno.esclude_pianificazione` per i tipi turno che rappresentano interdizioni dalla pianificazione (es. maternità). Esclusione automatica dal piano degli operatori in interdizione che copre l'intero mese (può riusare `SaldoRicalcoloService::rimuoviSaldoSeOrfano` introdotto in 4-quater quando un'assenza maternità arriva DOPO la creazione del piano e copre tutto il mese). Vedi [[project-operatori-stati-assenze]].

**Poi:** 5 (vincoli bloccanti su tipi_vincolo + check assenze sovrapposte), 6 (generatore automatico schema M-M-P-N-S-R con continuazione dal mese precedente).

---

## Sessione 4-sexies — 2026-05-20 — CRUD assenze + maternità nascosta

### Cosa è stato fatto

- **Migrazione `0004_tipi_turno_esclude_pianificazione.sql`** — aggiunge `tipi_turno.esclude_pianificazione BOOLEAN NOT NULL DEFAULT FALSE` dopo `is_formazione`. Niente seed forzato di un tipo `MAT`: Olga lo crea da `/tipi-turno` con il flag attivo (coerente col principio "niente automatismi opachi"). `schema.sql` aggiornato per nuovi install.
- **Tipi turno — flag**: `TipoTurnoModel.fillable` esteso, `TipoTurnoValidator` aggiunge `esclude_pianificazione` al ciclo dei bool flags, `TipiTurnoController.collectInput` lo include (cf. feedback `collect-input-completeness`). Form `views/tipi_turno/form.twig` con fieldset dedicato "Interdizione dalla pianificazione" (separato dalla categoria del turno, che è mutuamente esclusiva); index aggiunge il badge nero "esclude pianif." quando il flag è attivo.
- **`AssenzaModel`** (nuovo): fillable `[id_operatore, id_tipo_turno, data_inizio, data_fine, note, creato_da]`.
  - `listJoined(idSetting?, idOperatore?)`: JOIN operatori + setting + tipi_turno + utenti, ordinato `data_inizio DESC, cognome, nome`. Espone `tipo_esclude_pianificazione` per il badge in index.
  - `listIdOperatoriEsclusiNelMese(anno, mese)`: ritorna `list<int>` di id_operatore con almeno un'assenza su tipo `esclude_pianificazione=1` che copre INTERAMENTE il mese (`data_inizio <= primo_del_mese AND data_fine >= ultimo_del_mese`). Usata dal fotografa-operatori di `PianiTurnoController::store`.
- **`AssenzaValidator`** (nuovo): valida `id_operatore`/`id_tipo_turno` come interi, `data_inizio`/`data_fine` come `Y-m-d` round-trip (riusa `Rules::date`), `note` opzionale max 1000 char. Coerenza interna: `data_fine >= data_inizio` (confronto lessicografico su stringa Y-m-d). NON verifica esistenza FK — quello lo fa il controller.
- **`AssenzeController`** (nuovo, admin+caposala): CRUD standard (`index/create/store/edit/update/destroy`). `collectInput` separato per dichiarazione esplicita dei campi. `verificaRiferimenti` checka esistenza op+tipo dopo la validazione. `creato_da` valorizzato solo allo store con `currentUserId()`; in update non si tocca (resta l'autore originale). Logger su create/update/destroy. Filtro index `?setting=hospice|ucp_dom`.
- **`PianiTurnoController::store`** — fotografa-operatori esteso col filtro 4-sexies:
  1. `OperatoreModel::findInServizioNelMese($anno, $mese, $idSetting)` (invariata: data anagrafica "chi è in servizio").
  2. `AssenzaModel::listIdOperatoriEsclusiNelMese($anno, $mese)` → id_operatore con maternità intero-mese.
  3. `array_filter` per rimuoverli dalla lista prima della creazione di `piano_operatori`/`saldo_ore`. Se la lista diventa vuota, redirect con messaggio "non ci sono operatori attivi in servizio". `AssenzaModel` iniettato come campo `$this->assenze`.
- **Routing**: 6 rotte sotto `/assenze` (index/create/store/edit/update/destroy), tutte admin+caposala. CSRF on di default sui POST.
- **Viste**: `views/assenze/index.twig` con tabella + pills setting + badge per `tipo_esclude_pianificazione`; `views/assenze/form.twig` con select operatore (cognome+nome+categoria+setting) e select tipo turno (codice+descrizione+nota "esclude pianif." accanto a quelli flaggati).
- **Navbar**: voce «Assenze» top-level per admin/caposala (subito dopo «Piani turno», prima del dropdown «Anagrafiche»). **Dashboard**: tile «Assenze» + banner aggiornato a sessione 4-sexies.

### Decisioni di sessione

| Punto | Scelta |
|---|---|
| Flag su `tipi_turno` vs stato su `operatori` | Flag su tipi_turno: la verità di "operatore in interdizione" sta nella tabella `assenze`, un solo modello da aggiornare quando la situazione cambia (coerente con [[project-operatori-stati-assenze]]). Aggiungere altre interdizioni in futuro (es. aspettativa) = nuovo tipo turno marcato, niente nuove migrazioni |
| Niente seed di un tipo `MAT` | La migrazione aggiunge solo il flag. Il codice del tipo turno (`MAT`, `INT`, ecc.) viene scelto da Olga in `/tipi-turno`. Coerente con la libertà di codifica esistente |
| Filtro intero-mese | Esclusione solo quando l'assenza copre **interamente** il mese (`data_inizio <= primo` AND `data_fine >= ultimo`). Le assenze parziali (es. maternità che inizia il 15) lasciano l'operatore nel piano: la riduzione delle ore_dovute si fa a mano (principio "numero giusto a mano") |
| Posizione del filtro | Nel `PianiTurnoController::store`, non in `OperatoreModel::findInServizioNelMese`. `findInServizioNelMese` resta una funzione anagrafica "chi è in servizio nel mese": è usata anche da `findCandidatiAggiunta` (4-ter), che NON deve escludere le maternità — un'op in maternità può essere aggiunta in itinere a un piano se necessario |
| Tipo di assenza nel form | Dropdown con TUTTI i tipi turno (non filtrato a "is_ferie OR is_malattia OR esclude_pianificazione"). Olga sceglie. Etichetta dell'option include " · esclude pianif." se il flag è attivo, per disambiguazione |
| Coerenza date | `data_fine >= data_inizio` validata dal validator; confronto lessicografico su stringa `Y-m-d` (== cronologico), coerente con la 4-quinquies |
| Lettura | Admin+caposala (no visualizzatore): le assenze sono informazione operativa, non risultato pubblico come un piano pubblicato. Mantiene parallelo con `/operatori` |
| `creato_da` | Settato allo store con `currentUserId`. In update NON viene toccato (resta l'autore originale del record) |
| Note | Opzionali su `assenze` (a differenza di `saldo_modifiche` dove sono obbligatorie). L'assenza è un fatto registrato, non una modifica di valore che richiede motivazione |
| Inserimento retroattivo di maternità intero-mese (assenza creata DOPO un piano già esistente) | Rinviato. Casistica complessa: se l'operatore ha già turni nel piano, la rimozione automatica via `rimuoviSaldoSeOrfano` violerebbe il gate "zero turni" del removeOperatore. La coordinatrice usa il flusso 4-ter "+ Rimuovi operatore dal piano" (dopo aver tolto i turni) — visibile e controllato. Documentato come **punto aperto**: eventuale automazione futura |
| Sovrapposizione assenza ↔ turni esistenti | Non controllata nella 4-sexies. Materia della sessione 5 (vincoli bloccanti): in quella sessione si decide se warning o block, e se invalidare turni esistenti che cadono in un'assenza creata dopo |

### Da fare prima della prossima sessione (lato utente)

1. **Backup del DB** (la migrazione 0004 aggiunge una colonna, è veloce ma vale lo standard):
   ```bash
   mysqldump -u hospice_user -p hospice_turni > backup-pre-0004-$(date +%Y%m%d).sql
   ```
2. **Applicare la migrazione**:
   ```bash
   mysql -u hospice_user -p hospice_turni < database/migrations/0004_tipi_turno_esclude_pianificazione.sql
   ```
3. `composer dump-autoload` (per il nuovo namespace `AssenzeController`/`AssenzaModel`/`AssenzaValidator`).
4. Riavviare `php -S localhost:8000 -t public/` e con utente **admin** o **caposala**:

   **Setup tipi turno**
   - `/tipi-turno` → crea un nuovo tipo: codice `MAT`, descrizione "Maternità / interdizione", colore a piacere, ore conteggiate 0, **flag "Esclude dalla pianificazione" attivo**. Salva.
   - In lista deve comparire con il badge nero "esclude pianif.".
   - Modifica un tipo esistente (es. `F` ferie) lasciando il flag spento: il badge non deve apparire.

   **CRUD assenze base**
   - `/assenze` → bottone «+ Nuova assenza». Crea un'assenza per un operatore Hospice, tipo `F`, dal 2026-06-10 al 2026-06-14, nota "Settimana al mare". Salva.
   - In lista deve comparire ordinata per data_inizio DESC. Tabs setting "Hospice" / "UCP-DOM" filtrano correttamente per setting di casa dell'operatore.
   - Modifica l'assenza cambiando il tipo a `PE` (permesso): salvataggio OK, `creato_da` resta lo stesso utente.
   - Elimina l'assenza: deve scomparire dalla lista.

   **Validazione**
   - Crea un'assenza con `data_fine < data_inizio`: deve mostrare errore "La data di fine non può essere precedente alla data di inizio." con re-display del form.
   - Salva un'assenza senza tipo o senza operatore: errori espliciti, old input ripopolato.

   **Filtro maternità intero-mese (cuore della sessione)**
   - Crea un operatore di prova (es. "Rossi Maria") setting Hospice, attiva = sì.
   - Crea per lei un'assenza tipo `MAT` (quello con `esclude_pianificazione=1`), dal **2026-07-01 al 2026-12-31**.
   - Vai a `/piani-turno/create` e crea un piano Hospice per **luglio 2026**. Apri il piano: nella tabella saldi **Rossi Maria NON deve comparire**. Anche la riga del calendario non deve esistere.
   - Crea un piano Hospice per **giugno 2026**: Rossi Maria **deve** comparire (l'assenza inizia il 1 luglio, giugno non è coperto).
   - Crea un'assenza tipo `MAT` dal **2026-07-15 al 2026-12-31** per un altro operatore: nel piano luglio quell'operatore **deve** comparire (l'assenza non copre l'intero mese — inizia il 15).
   - Caso edge: assenza tipo `F` (ferie, flag spento) che copre tutto luglio 2026: l'operatore deve comparire normalmente nel piano (il filtro guarda solo i tipi con `esclude_pianificazione=1`).

   **Non-interferenza con flussi esistenti**
   - Un operatore in maternità intero-mese deve poter comunque essere aggiunto in itinere a un piano via "+ Aggiungi operatore" (la `findCandidatiAggiunta` non applica il filtro). Caso teorico — verificare che il dropdown lo mostri.
   - Cancellare un'assenza maternità prima di creare il piano fa rientrare l'operatore nel fotografa-operatori.

5. Loggarsi con **visualizzatore**: nessuna voce «Assenze» in navbar, `/assenze` deve dare 403. I piani pubblicati creati dopo l'inserimento delle maternità mostrano correttamente la lista filtrata (la verità è stata fotografata al momento dello `store`).

### Punto aperto rinviato

- **Maternità inserita DOPO la creazione del piano** che copre l'intero mese: non scatta nessuna rimozione automatica dal piano esistente. La coordinatrice deve passare per il flusso 4-ter ("+ Rimuovi operatore dal piano") dopo aver tolto eventuali turni. Da rivedere se la casistica si rivela frequente — eventualmente automazione con `SaldoRicalcoloService::rimuoviSaldoSeOrfano` quando l'operatore ha zero turni nel piano.

### Prossima sessione (5) — Vincoli bloccanti + check assenze sovrapposte

I `tipi_vincolo` da info display passano a check di validazione (`no_notti` blocca tipi turno notturni, `no_weekend` blocca date di weekend, ecc.). Aggiunta del check "data turno cade dentro un'assenza dell'operatore" — ora che le assenze hanno CRUD si può consumarle in `TurniController::validateRiferimenti`. Da decidere all'apertura: warning o block, e se invalidare turni esistenti che cadono in un'assenza creata dopo.

**Poi:** 6 (generatore automatico schema M-M-P-N-S-R con continuazione dal mese precedente). Vedi [[project-automazioni-popolamento]].

---

## Sessione 5 — 2026-05-21 — Check assenze sovrapposte ai turni

### Scope ridefinito all'apertura

La sessione 5 originale prevedeva "vincoli bloccanti + check assenze sovrapposte". Conversazione di dominio con Olga a inizio sessione: i `operatori_vincoli` (`no_notti`, `no_weekend`, `solo_mattine`) **non sono mai bloccanti runtime** — sono parametri del generatore automatico (sessione 6). Casi d'uso: lavoratrici in allattamento o post-maternità, ma con eccezioni quando c'è accordo per carenza drammatica di personale. Conseguenza: la coordinatrice deve poter cambiare la bozza generata come le pare.

Lo scope della 5 si riduce quindi al solo check assenze. La CRUD `operatori_vincoli` (mai esistita: la tabella si riempiva a mano nel DB) + il warning leggibile nel form turno vanno in **sessione 5-bis**. Vedi `spec-sessione-5.md` e memoria `project-vincoli-operatori`.

### Cosa è stato fatto

- **`AssenzaModel`** — due nuovi metodi:
  - `findAttivaPerOperatoreData(idOp, data): ?array` — ritorna l'assenza che copre quella data per quell'operatore (JOIN con `tipi_turno` per `tipo_codice`/`tipo_descrizione` utili al messaggio). Due placeholder named distinti `:data_lo`/`:data_hi` per la stessa data (regola PDO: no named riusati).
  - `listAttiveInPeriodo(idOperatori, dataInizio, dataFine): list` — assenze degli operatori indicati che si sovrappongono al periodo, una sola query con `IN (?,?,?…)` posizionale + due `?` finali per le date. Usato dal calendario per evitare N query nel loop Twig.
- **`TurniController`** — iniettato `AssenzaModel` come campo. Nuovo helper privato `messaggioAssenza(idOp, dataTurno): ?string` gemello di `messaggioFuoriFinestra`: chiama `findAttivaPerOperatoreData` e formatta "L'operatore è in assenza dal {labelData} al {labelData} ({codice} {descrizione}). Modifica il periodo di assenza se è sbagliato, o scegli un altro giorno."
- **`TurniController::validateRiferimenti`** — dopo il check `messaggioFuoriFinestra`, e solo se la finestra è ok, aggiunge il check `messaggioAssenza`. Block dello `store`. `update`/`destroy` non passano per `validateRiferimenti` quindi restano permessi (simmetria col pattern fuori-finestra della 4-quinquies: permettono cleanup retroattivo dopo creazione di un'assenza che copre turni preesistenti).
- **`TurniController::edit`** — calcola `inAssenza = $this->messaggioAssenza(...)` e lo passa alla view, parallelo a `fuoriFinestra`.
- **`views/turni/form.twig`** — nuovo alert `{% if inAssenza %}` dopo l'alert `fuoriFinestra`. Stile: `alert-warning` (giallo) se il turno esiste già (l'utente può cambiare tipo o eliminare), `alert-danger` (rosso) se sta creando un nuovo turno (il submit fallirà). Link a `/assenze` nel caso "danger" per la rimediazione.
- **`PianiTurnoController::show`** — costruisce `assenzeByOp[id_operatore] = list<assenza>` via `listAttiveInPeriodo` per gli operatori del piano sull'intervallo `[primo_del_mese, ultimo_del_mese]`. Passato alla view. Riusa il campo `$this->assenze` già presente dalla 4-sexies.
- **`views/piani_turno/show.twig`** — nuovo ramo cella `.cella-in-assenza` (dopo `cross-setting` e `fuori-finestra`, prima del ramo editabile) per celle vuote in periodo di assenza: codice attenuato + tooltip "{codice} · {descrizione} — dal dd/mm/yyyy al dd/mm/yyyy". Classe aggiuntiva `.cella-conflitto-assenza` applicata sia al ramo editabile sia al display quando la cella contiene un turno che cade in un'assenza — bordo rosso interno, resta cliccabile (modifica/elimina). Iterazione O(k) su `assenzeByOp[op]` per cella (k = 0-2 nella pratica).
- **`public/css/app.css`** — classe `.cella-in-assenza` con retino orizzontale (`repeating-linear-gradient(0deg, …)`), distinto dal retino diagonale 135° di `.cella-fuori-finestra` e dalla banda laterale colorata di `.cella-cross-setting`. Classe `.cella-conflitto-assenza` con `box-shadow: inset 0 0 0 2px #dc3545` per il bordo rosso interno sulle celle dei turni esistenti in conflitto.
- **`views/dashboard/index.twig`** — banner aggiornato a sessione 5.

### Decisioni di sessione

| Punto | Scelta |
|---|---|
| Assenze vs nuovo turno | **Block** in `TurniController::store`. Le assenze programmate vincono sempre. Messaggio user-friendly con periodo + tipo + suggerimento "modifica il periodo di assenza o scegli un altro giorno" |
| Assenze vs turno esistente | **Non block** in `update`/`destroy`. Simmetria col pattern fuori-finestra (4-quinquies): permette cleanup retroattivo dopo creazione di un'assenza che copre turni preesistenti |
| Visualizzazione in calendario | Cella vuota dentro periodo di assenza → overlay `.cella-in-assenza` (retino orizzontale, distinto da `.cella-fuori-finestra` diagonale e `.cella-cross-setting` con banda colorata) con codice del tipo assenza attenuato + tooltip. Cella con turno esistente in conflitto → bordo rosso `.cella-conflitto-assenza`, cliccabile come gli altri turni per consentire modifica/elimina |
| Helper assenza | `TurniController::messaggioAssenza` come `messaggioFuoriFinestra` — privato, ritorna `?string`, riusato da `validateRiferimenti` e `edit` |
| Una sola query per il calendario | `AssenzaModel::listAttiveInPeriodo($idOperatori, $dataInizio, $dataFine)` per evitare N query nel ciclo Twig. Tutta l'iterazione cella-per-cella legge dalla mappa in memoria; il loop su `assenzeByOp[op]` è O(k) con k tipicamente 0-2 |
| Precedenza nei rami della cella | Ordine: `turno` (eventualmente con `.cella-conflitto-assenza`) → `cross-setting` → `fuori-finestra` → `in-assenza` → `+ editabile` → display vuoto. Coerente col fatto che mutue esclusioni reali (assenza dentro fuori-finestra, cross-setting durante una cessazione) sono casi degeneri |
| Confronto date | Lessicografico su stringa `Y-m-d` (== cronologico), coerente con 4-quinquies/4-sexies sia in PHP sia in Twig |
| Niente cleanup automatico turni→assenza retroattiva | Quando un'assenza creata dopo copre turni esistenti, i turni restano e vengono segnalati con bordo rosso nel calendario e alert giallo nel form `edit`. La coordinatrice decide caso per caso (cambia tipo, elimina, oppure modifica/elimina l'assenza). Coerente con la 4-sexies (non rimuoviamo automaticamente operatori per maternità retroattiva) |
| `AssenzeController::store` | **Nessun check di turni in conflitto** alla creazione di un'assenza retroattiva. Il conflitto è visibile dal piano (bordo rosso). Eventuale flash post-store "trovati N turni in conflitto" rinviato a 5-bis se necessario |
| Vincoli operatori | Restano come oggi: warning informativo in `form.twig`, niente check bloccante. CRUD + warning testuale leggibile scorporati in 5-bis. Motivo: non sono bloccanti per design (input del generatore della 6, derogabili dalla coordinatrice in caso di carenza personale). Vedi memoria `project-vincoli-operatori` |
| PDO posizionali in `listAttiveInPeriodo` | `IN (?,?,?…)` variabile + due `?` finali per le date. Pattern coerente con `deleteByAnnoMeseEscludendoOperatori` della 4-ter (poi rimosso) e con la regola "no named placeholder riusati" |

### Da fare prima della prossima sessione (lato utente)

1. `composer dump-autoload` (non strettamente necessario in dev col PSR-4, ma utile).
2. Riavviare `php -S localhost:8000 -t public/` e con utente **admin** o **caposala**:

   **Test 1 — Block su nuovo turno in periodo di assenza**
   - Op X attivo, nessuna assenza. Piano in bozza per maggio 2026.
   - Crea un'assenza per X dal 2026-05-10 al 2026-05-15 tipo `F` (ferie).
   - Apri il piano: la cella di X il giorno 12 deve essere overlay retino orizzontale grigio, codice `F` italico attenuato, tooltip "F · Ferie — dal 10/05/2026 al 15/05/2026", cursor not-allowed.
   - Tenta via URL `/piani-turno/{id}/turni/edit?operatore={X}&data=2026-05-12`: il form si apre con alert rosso "Operatore in assenza" e indicazione del periodo + link a `/assenze`.
   - Submit del POST con un tipo turno selezionato: deve fallire con messaggio "L'operatore è in assenza dal lun 10/05/2026 al ven 15/05/2026 (F Ferie). Modifica il periodo di assenza se è sbagliato, o scegli un altro giorno." e old input ripopolato.

   **Test 2 — Edit/elimina di turno esistente che diventa in conflitto**
   - Op X, nessuna assenza. Assegna un turno `M` il 2026-05-12.
   - Crea un'assenza per X dal 2026-05-10 al 2026-05-15.
   - Torna al piano: la cella del giorno 12 mostra `M` colorato come prima, ma con **bordo rosso** interno (`.cella-conflitto-assenza`). Tooltip esteso include "in conflitto con assenza F dal 10/05/2026 al 15/05/2026".
   - Cliccala: il form si apre con alert giallo "Turno in periodo di assenza" + indicazione del periodo.
   - Cambia tipo a `F` e salva: deve **riuscire** (update non bloccato).
   - Elimina il turno dal form: deve **riuscire** (destroy non bloccato).
   - Riapri la cella ora vuota: il form si apre con alert rosso "Operatore in assenza" e il submit fallisce come Test 1.

   **Test 3 — Boundary date**
   - Assenza per X dal 2026-05-10 al 2026-05-15.
   - Nuovo turno il 9: OK. Il 10: KO. Il 15: KO. Il 16: OK.

   **Test 4 — Assenze sovrapposte (caso teorico)**
   - Due assenze per X: `F` dal 1 al 10 e `PE` dal 8 al 20.
   - Nuovo turno il 9 deve essere bloccato. Il messaggio cita una delle due (non importa quale).

   **Test 5 — Assenza in altro mese non interferisce**
   - Op X con assenza giugno 2026 intero. Piano maggio 2026 in bozza.
   - Calendario di maggio: nessuna cella in overlay assenza; tutti i giorni assegnabili.
   - Piano giugno 2026 (se esiste): tutto il mese in overlay.

   **Test 6 — Combinazione con fuori-finestra**
   - Op X con `data_cessazione = 2026-05-25` e assenza `F` dal 2026-05-20 al 2026-05-24.
   - Calendario maggio:
     - 20-24: overlay `.cella-in-assenza` (priorità: la cella non è ancora fuori finestra).
     - 25: cella vuota cliccabile `+`.
     - 26-31: overlay `.cella-fuori-finestra`.

   **Test 7 — Read-only / visualizzatore**
   - Pubblica un piano con celle in overlay assenza.
   - Loggati come visualizzatore: overlay visibile, tooltip funzionante, nessuna cella cliccabile, turni esistenti in conflitto restano col bordo rosso ma read-only.

   **Test 8 — URL manipolato per forzare il submit**
   - Op X con assenza dal 10 al 15. Piano in bozza.
   - URL `/piani-turno/{id}/turni/edit?operatore={X}&data=2026-05-12`: il form si apre con alert rosso (preview lato edit).
   - Submit POST con tipo turno: deve fallire con il check server-side in `validateRiferimenti`.

3. Loggati con **visualizzatore**: nessuna nuova azione da fare ma verifica i punti 7-Test 7 sopra.

### Iterazione post-test — Tipi turno "assenza" derivati

Olga durante il Test 2 ha sollevato due incoerenze legate alla tassonomia dei tipi turno:

1. Il dropdown «Tipo di assenza» in `/assenze/create` mostrava **tutti** i tipi turno, inclusi quelli di lavoro (M, P, N, S, R) — incoerente con la semantica del form.
2. Dopo aver modificato un turno `M` (lavoro) in `F` (ferie) nel Test 2, il bordo rosso `.cella-conflitto-assenza` persisteva. Concettualmente sbagliato: se il turno è esso stesso un'assenza coincidente con l'assenza programmata, è ridondanza coerente, non conflitto.

**Decisione di design**: invece di rifare il form `/tipi-turno` con radio "Lavoro / Assenza" (refactor più ampio che tocca il `SaldoRicalcoloService`, la 6 e il calcolo ore), **deriviamo** "tipo è assenza" dai flag esistenti `is_ferie OR is_permesso OR is_malattia OR esclude_pianificazione`. Niente nuova colonna, niente migration.

**Modifiche**:
- `TipoTurnoModel::listSoloAssenze()` — nuova: ritorna i tipi turno con almeno uno dei quattro flag attivi, ordinati per `priorita`.
- `AssenzeController::create`/`edit` — passa `listSoloAssenze()` al form. URL manipolato che forza un tipo "lavoro" come assenza rifiutato in `verificaRiferimenti` (controllo applicativo aggiunto: il tipo deve avere almeno uno dei quattro flag, altrimenti errore esplicito sul campo `id_tipo_turno`).
- `TurnoModel::listByPiano` + `findInPianoByOperatoreData` — espongono `tipo_is_assenza` come campo derivato (espressione SQL `(is_ferie=1 OR is_permesso=1 OR is_malattia=1 OR esclude_pianificazione=1)`).
- `views/piani_turno/show.twig` — `conflittoAssenza` diventa `turno and assenzaCorrente and not turno.tipo_is_assenza`. Niente bordo rosso per turni `F`/`PE`/`MAL`/`MAT` in periodo di assenza.
- `TurniController::edit` — sopprime l'alert `inAssenza` se il turno esistente ha `tipo_is_assenza=1` (la cella mostra già il tipo assenza, l'alert sarebbe rumore).

**Casi coerenti dopo il fix**:
- Cambio `M → F` su cella in assenza: bordo rosso sparisce, alert nel form non compare. ✓
- Cella vuota in periodo di assenza dopo eliminazione del turno: resta overlay `.cella-in-assenza`, non cliccabile per nuovo turno. Stato finale già osservato come corretto da Olga. ✓
- URL manipolato `/assenze/store` con tipo `M`: rifiutato dal nuovo check in `verificaRiferimenti`. ✓

### Iterazione 2 post-test — Update del turno bloccato in conflitto

Olga dopo il fix tipi-assenza ha proposto una restrizione ulteriore: **l'unica azione lecita su un turno che cade in un'assenza è l'eliminazione**. La modifica non è una funzionalità necessaria — se la coordinatrice è sicura che si cambia, corregge l'assenza (restringe il periodo) o elimina il turno.

**Decisione**: le assenze vincono sempre, principio rafforzato. `update` ora rifiuta qualsiasi modifica al turno se la sua data cade in un'assenza dell'operatore (sia caso "conflitto" che "ridondanza coerente"). `destroy` resta permesso.

**Modifiche**:
- `TurniController::update` — check `messaggioAssenza` sulla `(id_operatore, data)` del turno PRIMA della validazione del nuovo input. Se trovato, redirect a `show` con flash error: "Modifica non consentita: {messaggio}. Per modificare il tipo turno, restringi prima il periodo dell'assenza; per rimuovere il turno usa Elimina."
- `TurniController::edit` — calcola sempre `inAssenza` (ripristinato rispetto all'iterazione precedente). Nuova variabile `assenzaRidondante = inAssenza !== null && turnoIsAssenza` per differenziare il tono nell'alert.
- `views/turni/form.twig` — tre rami distinti nell'alert assenza:
  1. nuovo turno → `alert-danger` "Operatore in assenza. L'assegnazione di un nuovo turno verrà rifiutata."
  2. turno esistente lavoro in periodo di assenza → `alert-danger` "Turno in conflitto con un'assenza. L'unica azione consentita è l'eliminazione."
  3. turno esistente assenza coincidente (`assenzaRidondante`) → `alert-info` "Turno ridondante con un'assenza programmata. Ti consigliamo di eliminarlo."
- `views/turni/form.twig` — bottone "Salva modifiche" / "Assegna turno" con attributo `disabled` quando `inAssenza` (sempre) o `not turno and fuoriFinestra` (nuovo turno fuori finestra). Defense-in-depth: il controllo vero resta server-side; il `disabled` HTML evita all'utente di provare senza esito.

**Nota su `fuoriFinestra`**: invariato. Su turno esistente fuori-finestra (caso "data_cessazione retroattiva") la modifica resta permessa, perché serve a pulire turni preesistenti. Il bottone "Salva modifiche" resta abilitato in quel caso.

**Casi finali**:
- Cella M in periodo F → bordo rosso, click apre form con alert danger "conflitto", bottone "Salva modifiche" disabled, "Elimina turno" attivo. Elimina riporta la cella a overlay assenza. ✓
- Cella F (era M, cambiata) in periodo F coincidente → niente bordo (ridondanza coerente, non conflitto), click apre form con alert info "ridondante", bottone disabilitato, elimina attivo. ✓
- Tentativo di forzare modifica via URL → `update` ritorna errore con messaggio chiaro.

### Punto aperto rinviato — Tassonomia tipi turno

Il form `/tipi-turno/edit` continua a esporre `is_ferie`/`is_permesso`/`is_malattia`/`esclude_pianificazione` come flag separati nella sezione «Categoria del turno». Olga ha proposto una rivisitazione in due radio top-level «Lavoro / Assenza» con il dettaglio derivato dal `codice`. Non implementato qui: tocca anche il calcolo ore nel `SaldoRicalcoloService` (che oggi partiziona via i flag) e gli schemi di conteggio della sessione 6 (`project-conteggio-ore-assenze`). Sarà rivisto contestualmente alla 6.

### Prossima sessione (5-bis) — CRUD `operatori_vincoli` + warning leggibile

Aggiungere il CRUD per i vincoli operatori (pattern `AssenzeController`), con `<select>` chiuso dei tre codici riconosciuti (`no_notti`, `no_weekend`, `solo_mattine`) al posto della stringa libera attuale. Sostituire l'alert `<code>no_notti</code>` + "Verifica manualmente" nel form turno con frase leggibile ("L'operatrice non dovrebbe fare turni notturni — accordo per carenza personale?"), non bloccante. Da decidere all'apertura: posizione UI (top-level `/vincoli` come `/assenze` o nidificato `/operatori/{id}/vincoli`), eventuale flash post-`AssenzeController::store` se l'assenza appena creata copre turni esistenti (numero + link al piano). Vedi memoria `project-vincoli-operatori`.

**Poi:** 6 (generatore automatico schema M-M-P-N-S-R con continuazione dal mese precedente). Vedi [[project-automazioni-popolamento]].

---

## Sessione 5-bis — 2026-05-23 — CRUD `operatori_vincoli` + warning leggibile

### Decisioni risolte prima dell'apertura

- **Posizione UI**: top-level `/vincoli` come `/assenze` (non nidificato sotto `/operatori/{id}/vincoli`). Motivo: la lista è già piccola, il pattern `AssenzeController` con colonna "Operatore" è rodato e copre la gestione massiva. Replicare quel pattern minimizza il lavoro per liberare tempo alla 6 (demo 28 maggio).
- **Migration `0005_operatori_vincoli_creato_da.sql`**: sì, aggiungere `creato_da INT NULL FK utenti(id) ON DELETE SET NULL` per coerenza col pattern `assenze.creato_da` (4-sexies).
- **`solo_mattine`** (plurale): aggiornato il commento `solo_mattina` (singolare) nello schema iniziale per allinearlo a memoria e UX.
- **Warning mirato `no_notti` × `is_notte=1`** nel form turno: rinviato a 6 (lo gestirà il generatore). Qui solo la frase parlata generica.
- **Flash post-store in `AssenzeController`** (punto aperto della spec-5): rinviato. Il bordo rosso sul piano è già sufficiente.

### Cosa è stato fatto

- **Migrazione `0005_operatori_vincoli_creato_da.sql`** — aggiunge `creato_da INT NULL AFTER creato_il` con FK verso `utenti(id) ON DELETE SET NULL`. `schema.sql` aggiornato per nuovi install + commento `tipo_vincolo` ora riporta `no_notti | no_weekend | solo_mattine — set chiuso lato applicativo`.
- **`VincoloOperatoreModel`** (nuovo): fillable + `listJoined(idSetting?, idOperatore?)`. JOIN operatori + setting (per cognome/nome/setting_codice/setting_nome) + LEFT JOIN utenti (`creato_da_username`). Ordinamento `o.cognome, o.nome, v.tipo_vincolo`. Niente metodo `findAttiviPerOperatoreData`: `TurniController::vincoliAttiviPerOperatore` resta invariato (privato, usa SQL inline con due placeholder distinti per data).
- **`VincoloValidator`** (nuovo): costante pubblica `TIPI = ['no_notti'=>'Niente notti', 'no_weekend'=>'Niente weekend', 'solo_mattine'=>'Solo mattine']` (riusata dal controller per il dropdown e dal validator per `Rules::inSet`). Date opzionali (NULL = "sempre"), coerenza `data_fine >= data_inizio` lessicografica su Y-m-d. Checkbox `attivo` via `Rules::toBool($input['attivo'] ?? false) ? 1 : 0` come `OperatoreValidator`. Note opzionali max 1000.
- **`VincoliController`** (nuovo, admin+caposala): CRUD `index/create/store/edit/update/destroy` copia di `AssenzeController`. `verificaRiferimenti` controlla solo l'operatore (il tipo è già nel set chiuso applicativo). `creato_da` valorizzato in `store`, intoccato in `update` (resta autore originale). Niente filtro setting nella lista (lista piccola); setting visibile come colonna. Log su create/update/destroy.
- **Routing** (`config/routes.php`): 6 rotte sotto `/vincoli` (gruppo `$adminCaposala`) dopo il blocco `/assenze`. Pattern identico (index/create/store/edit/update/destroy).
- **Viste**:
  - `views/vincoli/index.twig`: tabella Operatore | Setting | Tipo (badge codice + etichetta leggibile) | Attivo (badge sì/disattivato) | Dal | Al | Note | Inserito da | Azioni. Niente pills di filtro setting.
  - `views/vincoli/form.twig`: select operatore (cognome+nome+categoria+setting), `<select>` chiuso dei 3 codici con label leggibile + codice tra parentesi, date opzionali con form-text "Lascia vuoto per un vincolo permanente", checkbox `attivo` con default `checked` per i nuovi vincoli (pattern `attivo_default` copiato da `operatori/form.twig` per coerenza), textarea note.
- **Navbar** (`views/layout/navbar.twig`): voce "Vincoli" tra "Assenze" e il dropdown "Anagrafiche", visibile a admin/caposala.
- **`views/turni/form.twig`** — riscritto il blocco `{% if vincoli|length > 0 %}` (righe 75-92): la mappa `codice => frase parlata` è inline in Twig come `{ 'no_notti': 'Non dovrebbe fare turni notturni', 'no_weekend': 'Non dovrebbe lavorare nei weekend (sabato/domenica)', 'solo_mattine': 'Preferenza forte per turni del mattino' }`. Periodi resi come "dal dd/mm/yyyy al dd/mm/yyyy" o "da sempre, senza fine". Footer "Non bloccante: procedi solo se esiste un accordo per copertura." Niente più `<code>no_notti</code>`.
- **Dashboard** (`views/dashboard/index.twig`): banner aggiornato a sessione 5-bis (ricorda che i vincoli restano non bloccanti) + tile «Vincoli» accanto a «Assenze».

### Decisioni di sessione

| Punto | Scelta |
|---|---|
| Posizione UI | Top-level `/vincoli` come `/assenze`. Pattern `AssenzeController` riusato per minimizzare codice nuovo |
| Set chiuso dei codici | `no_notti`, `no_weekend`, `solo_mattine` come `<select>` nel form. `VincoloValidator::TIPI` (costante pubblica) è la fonte di verità: codici → etichette. Riusata da controller (dropdown + lista) e validator (`Rules::inSet`). Il campo `tipo_vincolo` resta VARCHAR(50) nel DB per future estensioni (`no_festivi` ecc.): basta aggiungere un'entry alla costante, niente migration |
| Mappa frase parlata | Inline in Twig in `views/turni/form.twig` (set chiuso e piccolo). Se in futuro cresce, spostarla in un helper Twig globale |
| Bloccante vs informativo | **Informativo**. Niente check in `TurniController::validateRiferimenti`. Motivo: input del generatore (sessione 6), derogabile dalla coordinatrice. Vedi [[project-vincoli-operatori]] |
| Filtro setting nella lista | **No** per la prima versione. Lista piccola, setting visibile come colonna |
| Toggle "attivo" | Gestito dal form di edit (checkbox), senza endpoint `toggle-attivo` dedicato. Default `attivo=true` per i nuovi vincoli (UI). Pattern `attivo_default` allineato a `operatori/form.twig` |
| Periodi NULL | `data_inizio` e `data_fine` opzionali. NULL = "sempre". Validator: se entrambe presenti, `data_fine >= data_inizio` |
| Migration `creato_da` | Sì: coerenza col pattern `assenze` (4-sexies). FK `ON DELETE SET NULL` |
| `verificaRiferimenti` | Solo controllo esistenza operatore. Il `tipo_vincolo` è già in set chiuso applicativo, niente tabella di riferimento da consultare |
| Warning mirato no_notti × is_notte=1 | Rinviato a 6 (gestito dal generatore). Qui solo frase parlata generica |

### Da fare prima della prossima sessione (lato utente)

Già eseguito durante questa sessione (test 1-10 OK):
1. Backup DB pre-0005.
2. `mysql -u hospice_user -p hospice_turni < database/migrations/0005_operatori_vincoli_creato_da.sql`.
3. `composer dump-autoload`.
4. Riavvio server, test 1-10 della spec — tutti verdi (Olga, 2026-05-23).

### Prossima sessione (6) — Generatore automatico

Schema ciclico fisso M-M-P-N-S-R con continuazione dal piano del mese precedente pubblicato. Il generatore consuma `operatori_vincoli` come constraint sulla proposta (genera mattine per `solo_mattine`, evita date di weekend per `no_weekend`, evita tipi `is_notte=1` per `no_notti`). La coordinatrice modifica liberamente la bozza generata: mai bloccante. Vedi [[project-automazioni-popolamento]] per i due automatismi richiesti dal vecchio gestionale e [[project-deadline-28maggio]] per la demo competitiva.

Punti aperti già identificati per la 6:
- Rivisitazione `/tipi-turno` con radio Lavoro/Assenza top-level (rinviata dalla 5): tocca il calcolo ore in `SaldoRicalcoloService` e gli schemi di conteggio della 6 stessa.
- Maternità retroattiva intero-mese che copre piano già esistente (rinviata dalla 4-sexies): valutare automazione con `rimuoviSaldoSeOrfano` quando zero turni.
- Warning mirato `no_notti × is_notte=1` nel form turno (rinviato dalla 5-bis): valutare se gestirlo nel generatore o come hint nel form.

---

## Sessione 6 — 2026-05-23 (build) + 2026-05-24 (verifica) — Generatore automatico + schemi di turnazione

> Questa sezione è stata scritta il 2026-05-24 consolidando la chat di build del 23/05 (sessione `e810f801`, commit `6b19bab`) — che non aveva lasciato voce nel diario — più la verifica del 24/05. Fonte autorevole dei requisiti: `spec-sessione-6.md` (l'xlsx `spec ore e turni sessione 6.xlsx` è il materiale grezzo, ormai superato dalla spec).

### Decisioni risolte il 2026-05-23 (chat di build)

| Punto | Scelta |
|---|---|
| **Modello dominio** | NON un ciclo unico, ma **due famiglie di schema** come *dati* (no `if` hardcoded): **ciclico** (periodo 6, posizione-based → Hospice inf/OSS) e **settimanale** (periodo 7, giorno-settimana → coordinatrice Hospice + tutto UCP-DOM). Tabelle `schemi_turnazione` + `schema_passi` |
| **Dove vivono le ore lavorate** | **Opzione B**: `turni.ore_effettive` (DECIMAL NULL). Il generatore/inserimento salva le ore del turno specifico; `SaldoRicalcoloService` somma quel campo con fallback su `tipo.ore_conteggiate` per i turni pre-6/manuali |
| **Notte** | **Soluzione 2**: "la notte è un intervallo, le ore seguono il calendario". Rappresentazione in griglia N (+ S di smonto a 0h). *(Lo split delle ore di N tra i mesi NON è ancora implementato nel service — vedi gap.)* |
| **Vestizione** | +0,25h (15 min) su **M, P, N, G**, **solo se lavorato** (non in assenza). Introdotta quest'anno → applicata nel seed (M 7,75 · P 8,00 · N 10,75 · G 7,75). **Da confermare alla coordinatrice in demo**: niente controllo separato in UI, è automatica. Olga: "facciamo così e lo chiedo durante la demo" |
| **Generatore: ferie/assenze** | **Congela** il ciclo (non avanza la posizione), riprende dopo l'assenza — identico al modificatore `no_weekend`. Risposta di Olga del 23/05: *"Si congela, giusto — eureca!"*. ⚠️ **Questa decisione riguarda SOLO il comportamento del GENERATORE** (quale turno assegnare al rientro), **non** la regola di conteggio ore nel saldo (vedi nodo aperto) |
| **Refusi xlsx corretti** | OSS UCP-DOM 08:00–**15:15** (= 7,25h, non 14:15) · Coordinatrice `G` 08:00–**15:30** · `D` Hospice 07:00–**14:30** (manuale, no vestizione) |
| **Catalogo tipi turno** | Confermati e seedati i nuovi: `UI`/`UO` (UCP), `Rec` (recupero weekend), `MS`/`PS`/`NS` (straordinari Hospice, solo manuali), `L` (lutto), `CM` (congedo matrimoniale), `CP` (congedo paternità), `104`, `INF` (infortunio), `ASP` (aspettativa), `PST` (permesso studio), `DS` (donazione sangue), `EL` (permesso elettorale/scrutatori). 28 tipi totali |
| **Conteggio per-tipo** | Colonna `tipi_turno.schema_ore` ∈ `{da_schema, maternita_8_6_0, zero}`. Default `da_schema` (regola unica "quanto la posizione di schema"); MAT → `maternita_8_6_0`; ASP → `zero` |
| **4-sexies rivista** (decisa, NON ancora implementata) | maternità/aspettativa intero mese → **nascosto dalla griglia ma riga saldo preservata** (maternità ≈ neutro 8/6/0, aspettativa = `-ore_dovute` deficit visibile) |

### Cosa è stato costruito e committato (`6b19bab`, 23/05 22:09)

- **Migration `0006`** — tabelle `schemi_turnazione` + `schema_passi`; `turni.ore_effettive`; `tipi_turno.schema_ore`; 15 tipi turno nuovi; fix orari D/G; vestizione su M/P/N/G; seed dei **6 schemi concreti** (`hospice_regolare`/`_solo_mattine`/`_no_notti`/`_coordinatrice`, `ucpdom_infermieri`/`_oss`). `schema.sql` allineato.
- **Model** `SchemaTurnazioneModel`, `SchemaPassoModel`; `TipoTurnoModel` esteso (`schema_ore`).
- **`GeneratoreService`** — **Automatismo 2** (continuazione dal mese precedente pubblicato): risolve lo schema da setting+categoria+vincoli, ricostruisce la posizione dall'ultimo turno regolare del mese prima, congela su assenze/weekend, lista "da assegnare a mano" per i casi limite. Guardia sull'`UNIQUE (operatore, data)` globale (salta date già occupate cross-setting). Da chiamare DENTRO la transazione del controller.
- **`SaldoRicalcoloService`** → somma `ore_effettive` (Opzione B), fallback `ore_conteggiate`.
- **UI** — bottone "Continua dal mese precedente" nella `show` del piano in bozza (`PianiTurnoController::genera`, admin+caposala, in transazione).

### Verifica del 2026-05-24 (statica su dati reali + scenari in transazione con rollback)

Catena reale Hospice presente: maggio (seed manuale, pubbl.) → giugno (generato, pubbl.) → luglio (generato, pubbl.). Verificati anche i percorsi non coperti dai dati reali con uno script di scenario non distruttivo (transazione + rollback).

| Comportamento | Esito |
|---|---|
| Continuazione ciclica mese→mese (Neri, Bruni F. giu→lug) | ✅ |
| **Congelamento su assenze a cavallo di mese** (Rossi ferie 28/6→2/7: pos2 P … freeze … pos3 N il 3/7) | ✅ |
| Schema settimanale coordinatrice (Azzurri G lun-ven) | ✅ |
| Schemi settimanali UCP-DOM (INF→UI ven 6h, OSS→UO sab 4,25h) — scenario | ✅ |
| Varianti da vincolo: `no_notti`→0 N, `solo_mattine`→solo M, `no_weekend`→0 turni weekend+freeze — scenario | ✅ |
| Esclusione maternità intero mese a `store()` (Bruni Francesca UCP fuori dal piano) — scenario | ✅ |
| Lista "da assegnare a mano" (neoassunto senza mese precedente) — scenario | ✅ |
| `ore_effettive`+vestizione, somma turni = `ore_lavorate`, `saldo_mese`=lavorate−dovute | ✅ |

Conclusione: **il cuore del generatore è solido** (continuazione, freeze, schemi, varianti, casi limite, saldi delle ore lavorate). Nessuna regressione trovata.

### Implementazione del 2026-05-24 (continuazione sessione 6)

- **`SchemaOreService`** (nuovo, step 3) — conteggio ore-assenza nel saldo ("presenza statistica"). `oreAssenzePerMese(idOp, anno, mese, dateConTurno)` → bucket `{ferie, permessi, malattia, formazione, maternita}`. Regole: `zero`→0; `maternita_8_6_0`→8/6/0; `da_schema`→ settimanale per giorno-settimana, **ciclico = blocco riparte da M** (decisione (a) del 24/05). `OperatoreModel::findConSettingCategoria` e `AssenzaModel::listConTipoPerOperatoreMese` aggiunti a supporto. NB: la risoluzione schema duplica `GeneratoreService` — candidata a estrazione in un `SchemaResolver`.
- **`SaldoRicalcoloService`** — `ricalcolaMese` ora somma anche le ore delle assenze (via `SchemaOreService`), saltando i giorni già coperti da un turno (niente doppio conteggio). `SchemaOreService` iniettato come dipendenza opzionale costruita internamente (i 3 siti di `new SaldoRicalcoloService` restano intatti). Il bucket `maternita`/`aspettativa` è calcolato ma **non ancora agganciato** al saldo (manca la colonna → revisione 4-sexies).
- **`GeneratoreService`** — assenze cicliche **> 2 giorni** interrompono la generazione da lì in poi (operatore in lista "ciclo interrotto … completa a mano"); assenze **1-2 giorni** mantengono il freeze-resume. Vale solo per schemi ciclici. `popolaCiclico` ritorna ora `{creati, interrottoDa}`.
- **Verifica (transazione+rollback, 24/05)**: ✅ conteggio ciclico restart-da-M (Rossi 28/6→2/7: giu 22,75h + lug 10,50h = 33,25h); ✅ saldo accredita le ferie (Rossi lug: `ore_ferie` 0→10,50, `saldo_mese` −1,75→+8,75); ✅ stop su assenza >2gg (Neri ferie 10gg: 0 turni dopo) + prosegui su ≤2gg (Rossi permesso 2gg).

### Revisione 4-sexies (step 4) — FATTA il 2026-05-24

Maternità/aspettativa che copre l'**intero mese**: non più escluse del tutto. Ora incluse con la riga di saldo (le "ore perdute" non spariscono, il `saldo_progressivo` non salta il buco) ma **nascoste dalla griglia** assegnabile.

- **Migration `0007`** — `saldo_ore.ore_maternita DECIMAL(6,2) DEFAULT 0.00`; `saldo_mese` ora include anche `+ ore_maternita`. `schema.sql` allineato. `SaldoOreModel` fillable aggiornato.
- **`SaldoRicalcoloService`** — scrive `ore_maternita` (= bucket `maternita` di `SchemaOreService`) e lo somma nel `saldo_mese`. Effetto: maternità 8/6/0 → saldo ≈ neutro; aspettativa 0 → resta `-ore_dovute` (deficit visibile).
- **`PianiTurnoController::store`** — non esclude più gli operatori con assenza `esclude_pianificazione` a mese intero: li include e fa `ricalcola` sui soli esclusi (saldo riflette subito 8/6/0 o 0). `show()` calcola `nascostiGriglia` (esclusi ∩ operatori del piano) e lo passa alla vista.
- **`GeneratoreService`** — salta silenziosamente gli operatori esclusi-mese-intero (niente turni, niente flag manuale).
- **`views/piani_turno/show.twig`** — la **griglia** salta i nascosti (`saldi|filter(...)` — NB: `{% for ... if %}` è stato rimosso in Twig 3); la **tabella saldo** li tiene con badge "fuori griglia".
- **Verifica (24/05)**: ✅ op4 (MAT intero mese) incluso nel piano, `ore_maternita=168`, `saldo_mese=+3` (≈ neutro, non −165); ✅ generatore lo salta; ✅ `nascostiGriglia=[4]`; ✅ aspettativa → bucket 0 (deficit preservato); ✅ `show.twig` compila col View reale.

### Bug colore celle — RISOLTO il 2026-05-24 (era la CSP, non il rendering)

Lunga caccia (vedi anche [[project-csp-no-inline]] in memoria). Sintomo: celle del calendario tutte bianche nonostante `tipo_colore` valorizzato e lo `style="background-color:.."` presente nell'HTML. **Causa vera: la CSP** (`SecurityHeaders`: `style-src 'self'`, `script-src 'self'`) **blocca TUTTI gli attributi inline** — `style=` (colori) e `on*=` (conferme). Gli sfondi da classe (app.css) passavano, gli inline no: è ciò che alla fine ha smascherato il bug (console F12). Olga ha scelto di tenere la CSP stretta e spostare tutto su classi/JS esterno (no `'unsafe-inline'`).

Fix (commit `554bcab`):
- **Colori → classi.** `AssetController::tipiTurnoCss` serve `/assets/tipi-turno-colori` (path **senza `.css`**: `php -S` intercetta i `*.css` non esistenti → 404 prima del router) con `.tt-bg-{id}{ --bs-table-bg:#col; background-color:#col }`. `<link>` in `base.twig`. Celle (bozza+pubblicato), banda cross-setting, swatch/badge (turni/form, tipi-turno, assenze) usano `class="tt-bg-{id}"`. `TurnoModel::listCrossSettingPerPiano` espone `id_tipo_turno`.
- **Conferme → `data-confirm` + `public/js/app.js`** (listener delegato). 12 `onsubmit="return confirm()"` sostituiti.
- **Larghezze colonne → classi** `.w-*` in `app.css`. **Zero `style=` inline** rimasti nei template.
- **Migration `0008` + `schema.sql`**: palette canonica. `G` (coordinatrice) e `DV` erano `#FFFFFF` (invisibili) → oro/celeste; M/P/N/S/F allineati (il seed originale aveva M/P bianchi).

### Gap residui (sequenza spec §8, da fare)

1. **Soluzione 2 (split notte tra i mesi)** non implementata nel `SaldoRicalcoloService` — le ore di N vanno tutte alla data di inizio. Impatta solo le notti a cavallo di fine mese (marginale per la demo).
2. **`log_modifiche`** — `genera()` logga solo nel file applicativo, non in `log_modifiche` con metadata come da spec §5.
3. **Tassonomia `/tipi-turno`** radio Lavoro/Assenza (rinviata dalla 5) — ancora aperta.
4. **Seed `schema.sql` incompleto**: `DV` e `MAT` esistono nel DB ma non nel seed (un install nuovo non li avrebbe). `Ms/Ps/Ns` nel seed vs `MS/PS/NS` nel DB (case). Da riconciliare.
5. Cosmetico: 404 source-map `bootstrap.min.css.map` in console (solo dev-tools, innocuo).

### ✅ Nodo risolto il 2026-05-24 — regola di conteggio ore-assenza per schemi CICLICI

> **DECISO (Olga, 24/05):** (a) il blocco di assenza ciclico **riparte da M e segue lo schema** (5 gg ferie = M M P N S = 33,25h). Il generatore **interrompe** la generazione dopo un'assenza **> 2 giorni** (≤2 gg: prosegue). Implementato e verificato — vedi sopra. Cronaca del nodo qui sotto per memoria.

Le parti **decise/non ambigue**:
- Settimanali (coord, UCP): `ore_assenza` per giorno-settimana, lette dalla tabella → univoco.
- Maternità → 8/6/0; Aspettativa → 0. Univoci.

La parte **aperta**: per un'assenza pluri-giorno su schema **ciclico** (Hospice regolare), che posizione prende ogni giorno per il conteggio?
- Il **freeze del generatore** è deciso (eureca), ma è una decisione di *assegnazione turni*, non di conteggio.
- La spec §3 dice "ogni assenza conta quanto la posizione di schema di quel giorno" + "la posizione si congela": applicato alla lettera, tutti i giorni del blocco cadrebbero sulla posizione congelata (es. Rossi: 5 gg ferie tutti contati come N = 52,5h → sovrastima).
- Alternative emerse: (A) la posizione **avanza** nel conteggio (mix realistico, ~25,5h per 5 gg); (B) **schema ferie fisso** = template `M M P N S R` dal 1° giorno del blocco (ciclo pieno = 33,25h).
- **Da decidere con Olga** prima di scrivere `SchemaOreService`. Trascrivere la decisione qui e in `project-conteggio-ore-assenze`.
