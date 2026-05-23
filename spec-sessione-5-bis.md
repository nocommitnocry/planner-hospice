# Specifica sessione 5-bis — CRUD `operatori_vincoli` + warning leggibile

## Contesto

La tabella `operatori_vincoli` esiste dallo schema iniziale (sessione 1) ma non ha mai avuto un CRUD: oggi i record si inseriscono a mano nel DB e il `TurniController` li legge come warning informativo nel form turno con label "tecnica" (`<code>no_notti</code>` + "Verifica manualmente la compatibilità").

**Decisione di dominio (2026-05-21):** i vincoli operatori **non sono mai bloccanti runtime**. Sono parametri del generatore automatico (sessione 6) — la coordinatrice deve poter cambiare la bozza generata come le pare (casi d'uso: allattamento, post-maternità, deroghe per carenza personale). Vedi memoria `project-vincoli-operatori`.

**Decisione UI (2026-05-22):** posizione **top-level `/vincoli`**, pattern `AssenzeController`. Motivo: gestione massiva + colonna "Operatore" già rodata in `/assenze`. Il riuso del pattern minimizza il lavoro per liberare tempo alla 6.

## Scope

- CRUD `operatori_vincoli` come `/assenze`: lista, create, edit, update, destroy. Admin + caposala.
- `<select>` chiuso dei 3 codici riconosciuti (`no_notti`, `no_weekend`, `solo_mattine`) al posto del `tipo_vincolo VARCHAR(50)` libero.
- Riscrittura dell'alert vincoli nel form turno: frase parlata leggibile invece di `<code>no_notti</code>`.
- Niente nuove dipendenze, niente cambi al `TurniController` se non nel rendering dell'alert.

**Cosa NON entra**

- Niente check bloccanti in `TurniController` (per design — vedi memoria).
- Niente endpoint dedicato `toggle-attivo`: il campo `attivo` si gestisce dal form di edit (checkbox).
- Niente integrazione col generatore (sessione 6): qui ci preoccupiamo solo del CRUD.
- Niente filtro per setting: i vincoli sono per-operatore, l'operatore ha il suo setting. Sezione UI può mostrare il setting in colonna per leggibilità, ma niente tab di filtro come in `/assenze` (semplificazione: la lista è già piccola — N operatori con vincolo, tipicamente < 10).

## Decisioni risolte prima dell'apertura (2026-05-22)

1. **Migration `0005_operatori_vincoli_creato_da.sql`**: **sì**, aggiungere la colonna `creato_da INT NULL` con FK `utenti(id) ON DELETE SET NULL` per coerenza col pattern `assenze`. Tracciabilità nella lista.
2. **`solo_mattine` (plurale)** — allineato a memoria `project-vincoli-operatori`. Lo schema iniziale ha commento `solo_mattina` (singolare): **aggiornare il commento** in `database/schema.sql` quando lo si tocca per la migration (vedi §10).
3. **Warning mirato `no_notti` × `is_notte=1` nel form turno**: **rinviato a sessione 6** (lo gestirà il generatore). Qui solo la frase parlata generica.
4. **Flash post-store in `AssenzeController`** (punto aperto della spec-5): **rinviato**. Il bordo rosso sul piano è già sufficiente.

## Modifiche richieste

### 1. Migration `0005_operatori_vincoli_creato_da.sql`

**File**: `database/migrations/0005_operatori_vincoli_creato_da.sql`

```sql
ALTER TABLE operatori_vincoli
    ADD COLUMN creato_da INT NULL AFTER creato_il,
    ADD CONSTRAINT fk_vincoli_creato_da
        FOREIGN KEY (creato_da) REFERENCES utenti(id) ON DELETE SET NULL;
```

Vedi pattern in `0001_initial_schema.sql` per `assenze.creato_da`.

### 2. `VincoloOperatoreModel`

**File**: `src/Models/VincoloOperatoreModel.php` (nuovo)

Sulla scia di `AssenzaModel`. Tabella `operatori_vincoli`. `fillable`:

```php
protected array $fillable = [
    'id_operatore',
    'tipo_vincolo',
    'attivo',
    'data_inizio',
    'data_fine',
    'note',
    'creato_da',
];
```

Metodi:

- `find(int $id): ?array` — eredita da BaseModel (verificare: forse va sovrascritto se BaseModel non lo prevede generico).
- `listJoined(?int $idSetting = null, ?int $idOperatore = null): array` — JOIN con `operatori` + `setting` per esporre `operatore_cognome`/`operatore_nome`/`setting_codice`/`setting_nome`, `LEFT JOIN utenti` per `creato_da_username`. Ordinamento: `o.cognome, o.nome, v.tipo_vincolo`.
- `create(array $data): int` — eredita.
- `update(int $id, array $data): void` — eredita.
- `delete(int $id): void` — eredita.

**Non serve** un metodo `findAttiviPerOperatoreData`: c'è già in `TurniController::vincoliAttiviPerOperatore` (privato). In 5-bis riusiamo quel metodo invariato.

### 3. `VincoloValidator`

**File**: `src/Validators/VincoloValidator.php` (nuovo)

Sulla scia di `AssenzaValidator`. Regole:

- `id_operatore`: required + integer.
- `tipo_vincolo`: required + **in_array dei 3 codici** (`no_notti`, `no_weekend`, `solo_mattine`). Aggiungere `Rules::inSet` se non esiste già; altrimenti check inline.
- `attivo`: opzionale, default `true` (checkbox del form: se assente nel POST → false; se presente → true). Per consistenza col pattern `operatori.attivo` controllare come è gestito lì.
- `data_inizio`, `data_fine`: **opzionali** (NULL = "sempre"). Se entrambe presenti: `data_fine >= data_inizio`.
- `note`: opzionale, maxLen 1000.

Confronto date: lessicografico su Y-m-d (== cronologico), coerente con 4-quinquies/4-sexies/5.

### 4. `VincoliController`

**File**: `src/Controllers/VincoliController.php` (nuovo)

Copia letterale di `AssenzeController` con sostituzioni:

- `$model = new VincoloOperatoreModel()`.
- Dropdown tipo: array fisso dei 3 codici con etichetta leggibile (vedi §6).
- `verificaRiferimenti`: solo `operatori->find()` (niente tipo turno).
- `index`: per ora **senza filtro setting** (vedi scope) — quindi niente `$settings` né `$settingFiltro` nei view-data. Aggiungerli più avanti se la lista cresce.
- `create`/`edit`: passa `tipiVincolo` (mappa codice → etichetta) alla view; passa `operatori = $this->operatori->listWithCategoria(soloAttivi: true)`.
- `store`/`update`: pattern identico ad `AssenzeController`. Log `Logger::get()->info('Vincolo creato/aggiornato/eliminato', ...)`.

Metodi pubblici: `index`, `create`, `store`, `edit`, `update`, `destroy`. Nessun `toggleAttivo` (gestito nel form di edit).

### 5. Routing

**File**: `config/routes.php` — gruppo `$adminCaposala`, dopo le route di `/assenze`:

```php
$r->get('/vincoli',              [VincoliController::class, 'index'],   $adminCaposala, name: 'vincoli.index');
$r->get('/vincoli/create',       [VincoliController::class, 'create'],  $adminCaposala, name: 'vincoli.create');
$r->post('/vincoli',             [VincoliController::class, 'store'],   $adminCaposala, name: 'vincoli.store');
$r->get('/vincoli/{id}/edit',    [VincoliController::class, 'edit'],    $adminCaposala, name: 'vincoli.edit');
$r->post('/vincoli/{id}',        [VincoliController::class, 'update'],  $adminCaposala, name: 'vincoli.update');
$r->post('/vincoli/{id}/delete', [VincoliController::class, 'destroy'], $adminCaposala, name: 'vincoli.destroy');
```

Voce in menu (navbar): "Vincoli" accanto a "Assenze" — verificare il file di layout (probabilmente `views/layout/base.twig` o `views/layout/nav.twig`).

### 6. Vista `vincoli/index.twig`

**File**: `views/vincoli/index.twig` (nuovo)

Pattern identico a `views/assenze/index.twig`, ma senza pills di filtro setting. Colonne: Operatore | Setting | Tipo (badge codice + etichetta leggibile) | Attivo (badge sì/no) | Dal | Al | Note | Inserito da | Azioni.

Etichette del badge tipo:

| Codice | Etichetta |
|---|---|
| `no_notti` | "Niente notti" |
| `no_weekend` | "Niente weekend" |
| `solo_mattine` | "Solo mattine" |

Bottone "+ Nuovo vincolo" in alto a destra.


### 7. Vista `vincoli/form.twig`

**File**: `views/vincoli/form.twig` (nuovo)

Pattern identico a `views/assenze/form.twig`:

- `<select id="id_operatore">` come in `/assenze`: `operatori|listWithCategoria(soloAttivi: true)`. Mostra cognome/nome + categoria + setting.
- `<select id="tipo_vincolo">` chiuso dei 3 codici:

  ```twig
  <option value="">— Seleziona —</option>
  {% set sel_t = old_input.tipo_vincolo ?? vincolo.tipo_vincolo ?? null %}
  {% for codice, etichetta in tipiVincolo %}
      <option value="{{ codice }}" {% if sel_t == codice %}selected{% endif %}>
          {{ etichetta }} ({{ codice }})
      </option>
  {% endfor %}
  ```
- Checkbox `attivo` con default `checked` per i nuovi vincoli.
- Date opzionali (no `required`). Form-text: "Lascia vuoto per un vincolo permanente."
- Textarea note (1000 char).
- Tasto Salva + Annulla → `/vincoli`.

### 8. Warning leggibile nel form turno

**File**: `views/turni/form.twig` — sostituire il blocco `{% if vincoli|length > 0 %}` (righe 75-92) con:

```twig
{% if vincoli|length > 0 %}
    <div class="alert alert-warning small">
        <strong>L'operatore ha {{ vincoli|length > 1 ? 'vincoli attivi' : 'un vincolo attivo' }}:</strong>
        <ul class="mb-0">
            {% for v in vincoli %}
                <li>
                    {{ {
                        'no_notti':     'Non dovrebbe fare turni notturni',
                        'no_weekend':   'Non dovrebbe lavorare nei weekend (sabato/domenica)',
                        'solo_mattine': 'Preferenza forte per turni del mattino',
                    }[v.tipo_vincolo]|default('Vincolo: ' ~ v.tipo_vincolo) }}
                    {% if v.data_inizio or v.data_fine %}
                        ({{ v.data_inizio ? 'dal ' ~ format_date(v.data_inizio) : 'da sempre' }}
                         {{ v.data_fine ? ' al ' ~ format_date(v.data_fine) : ', senza fine' }})
                    {% endif %}
                    {% if v.note %} — <span class="text-muted">{{ v.note }}</span>{% endif %}
                </li>
            {% endfor %}
        </ul>
        <div class="text-muted mt-1">
            Non bloccante: procedi solo se esiste un accordo per copertura.
        </div>
    </div>
{% endif %}
```

Nota: la mappa di traduzione è inline nel Twig per evitare di doverla esporre come variabile dal controller (è già un set chiuso e piccolo). Se in futuro cresce, spostarla in un helper Twig globale o nel `BaseController::commonViewData`.

### 9. Banner dashboard

**File**: `views/dashboard/index.twig` — aggiornare a "Sessione 5-bis".

### 10. Documentazione

**File**: `database/schema.sql` — aggiornare il commento della colonna `tipo_vincolo` da `'es. no_notti, no_weekend, solo_mattina'` a `'no_notti | no_weekend | solo_mattine — set chiuso lato applicativo'`. Allinea schema iniziale a memoria/UX (plurale `solo_mattine`).

## Decisioni di sessione (da trascrivere in `SESSION_NOTES.md` a fine sessione)

| Punto | Scelta |
|---|---|
| Posizione UI | Top-level `/vincoli` come `/assenze`. Pattern `AssenzeController` riusato per minimizzare codice nuovo |
| Set chiuso dei codici | `no_notti`, `no_weekend`, `solo_mattine` come `<select>` nel form. Validator applicativo li impone (niente DB constraint: `tipo_vincolo` resta VARCHAR(50) per future estensioni come `no_festivi`) |
| Bloccante vs informativo | **Informativo**. Frase parlata nel form turno, niente check in `TurniController::validateRiferimenti`. Motivo: input del generatore (sessione 6), derogabile dalla coordinatrice |
| Filtro setting nella lista | **No** per la prima versione. La lista è piccola (~N operatori con vincolo). Setting visibile come colonna |
| Toggle "attivo" | Gestito dal form di edit (checkbox), senza endpoint `toggle-attivo` dedicato. Default `attivo=true` per i nuovi vincoli |
| Migration `creato_da` | **Sì** (confermata 2026-05-22): `0005_operatori_vincoli_creato_da.sql` aggiunge `creato_da INT NULL FK utenti(id) ON DELETE SET NULL` per coerenza col pattern `assenze` |
| Periodi NULL | `data_inizio` e `data_fine` opzionali. NULL = "sempre". Validator: se entrambe presenti, `data_fine >= data_inizio` |
| Warning mirato no_notti vs is_notte | **Rinviato a 6**. Qui solo la frase parlata generica |

## Test manuali

Prerequisito: `composer dump-autoload` se aggiunti file, riavvio del server, login admin o caposala.

### Test 1 — Create + lista

1. Apri `/vincoli`: lista vuota o con i record manualmente inseriti dal DB. Bottone "+ Nuovo vincolo" presente.
2. Clicca "+ Nuovo vincolo": form si apre con select operatore popolato dagli attivi, select tipo coi 3 codici, checkbox "Attivo" già spuntata, date vuote, note vuote.
3. Crea un vincolo: operatore X, tipo `no_notti`, attivo, dal `2026-05-01` al `2026-12-31`, note "Allattamento — accordo con coordinatrice".
4. Lista: la riga compare con badge "Niente notti" + colonne corrette. Setting visibile.

### Test 2 — Edit + update

1. Modifica il vincolo del Test 1: cambia tipo a `no_weekend`, salva.
2. Riapri edit: i campi sono ripopolati col nuovo tipo. Lista aggiornata.
3. Modifica di nuovo: disattiva il checkbox "Attivo", salva.
4. Lista: il vincolo mostra badge "Disattivato" (o equivalente). Non viene letto dal form turno (test 5 sotto).

### Test 3 — Destroy

1. Elimina il vincolo. Conferma JavaScript come in `/assenze`.
2. Lista: la riga è sparita.

### Test 4 — Set chiuso

1. Crea vincolo: prova a forzare via DevTools un `<option>` con `value="qualcosa_inventato"` e submit. Deve fallire col messaggio validator del set chiuso, con old input ripopolato.

### Test 5 — Warning nel form turno

1. Crea per operatore Y un vincolo `no_notti` attivo dal `2026-05-01` al `2026-06-30`.
2. Apri il piano maggio 2026 in bozza, clicca una cella di Y il 15 maggio. Il form turno si apre.
3. Sezione "vincoli": deve mostrare alert giallo con frase parlata "Non dovrebbe fare turni notturni (dal 01/05/2026 al 30/06/2026) — Allattamento ...". Niente `<code>no_notti</code>`. Footer "Non bloccante: procedi solo se esiste un accordo per copertura."
4. Assegnare un turno notturno deve **riuscire** (non bloccante). L'assegnazione viene salvata regolarmente.

### Test 6 — Vincolo disattivato non appare nel form

1. Disattiva il vincolo del Test 5.
2. Apri di nuovo la stessa cella nel form turno: il blocco "vincoli" non deve apparire (la query `vincoliAttiviPerOperatore` filtra `attivo = 1`).

### Test 7 — Vincolo scaduto non appare nel form

1. Vincolo `no_notti` dal `2026-01-01` al `2026-04-30` per operatore Z (scaduto a maggio).
2. Apri cella di Z il 15 maggio nel piano maggio: blocco "vincoli" assente. (Query filtra per data_fine >= data turno.)

### Test 8 — Vincolo senza date appare sempre

1. Vincolo `solo_mattine` per operatore W, date NULL (form lasciato vuoto).
2. Apri qualsiasi cella di W: alert "Preferenza forte per turni del mattino (da sempre, senza fine)".

### Test 9 — Read-only / visualizzatore

1. Loggati come visualizzatore: `/vincoli` deve essere accessibile in sola lettura? **Decisione**: no — coerente con `/assenze` che è solo admin+caposala. Verifica che il visualizzatore tentando `/vincoli` riceva 403/redirect dal middleware.
2. Form turno aperto come visualizzatore (se permesso): l'alert vincoli appare normalmente come info di contesto (la decisione di permissioning sta a livello di rotta, non di rendering vincoli).

### Test 10 — URL manipolato

1. Tentare DELETE su `/vincoli/{id}/delete` di un id valido senza CSRF token: deve fallire.
2. Tentare update con `id_operatore` di un operatore inesistente: il validator passa (integer ok), ma `verificaRiferimenti` rifiuta con "Operatore non trovato".

## File toccati

Creati:
- `src/Models/VincoloOperatoreModel.php`
- `src/Controllers/VincoliController.php`
- `src/Validators/VincoloValidator.php`
- `views/vincoli/index.twig`
- `views/vincoli/form.twig`
- `database/migrations/0005_operatori_vincoli_creato_da.sql`

Modificati:
- `config/routes.php` (6 route)
- `views/turni/form.twig` (riscrittura blocco vincoli righe 75-92)
- `views/layout/...` (voce menu "Vincoli" — file da identificare in apertura)
- `views/dashboard/index.twig` (banner sessione 5-bis)
- `database/schema.sql` (commento `tipo_vincolo` aggiornato a `solo_mattine`)

Non toccati:
- `src/Controllers/TurniController.php` — il metodo `vincoliAttiviPerOperatore` resta invariato.
- `src/Models/OperatoreModel.php` — riusiamo `listWithCategoria(soloAttivi: true)`.

## Ordine di esecuzione consigliato

1. Migration `0005_operatori_vincoli_creato_da.sql` (eseguirla sul DB di dev).
2. `VincoloOperatoreModel`.
3. `VincoloValidator` (con regola `inSet` se non esiste in `Rules`).
4. `VincoliController` (copia adattata di `AssenzeController`).
5. Routing in `config/routes.php`.
6. Viste `vincoli/index.twig` + `vincoli/form.twig`.
7. Voce navbar "Vincoli".
8. Riscrittura blocco vincoli in `views/turni/form.twig`.
9. Banner dashboard + commento `solo_mattine` in `database/schema.sql`.
10. Test manuali 1-10.
11. Aggiornare `docs/SESSION_NOTES.md` (sezione "Sessione 5-bis") + memorie `project-roadmap`, `project-vincoli-operatori` a fine sessione.

## Note operative per la sessione

- Vincolo del tempo: una sessione (oggi). Pattern noto + nessuna logica di dominio nuova — bassa probabilità di sorprese.
- Sub-sessione successiva: **6** (generatore), che è il differenziatore competitivo per la demo del 28. Cf. memoria `project-deadline-28maggio`.
- Punto da non perdere durante l'implementazione: il `tipo_vincolo` resta VARCHAR(50) nel DB perché in futuro potremmo aggiungere codici (`no_festivi`, `no_doppi_consecutivi`). Il set chiuso lato applicativo si estende aggiungendo un'entry alla mappa, senza migration.
