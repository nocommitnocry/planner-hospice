# ADR 0001 — Architettura del refactor di planner-hospice

- **Stato**: Accettato il 2026-05-10
- **Decisori**: Olga (manutentrice unica), in vista del passaggio alla caposala dell'Hospice di Abbiategrasso
- **Sostituisce**: il vecchio progetto `planner-turni-hospice` (PHP MVC fatto a mano, problemi di sicurezza e leggibilità documentati nell'analisi del 2026-05-10)

## 1. Contesto

Riscrittura di un applicativo per la gestione dei turni hospice (piani mensili, calendario di assegnazione, saldi ore, conflitti, generatore automatico, import/export Excel). L'app sarà usata principalmente da **una sola organizzazione** (Hospice di Abbiategrasso), ma deve restare **personalizzabile** da terzi (ipotesi: l'IT dell'Università di Padova) tramite configurazione, senza modifiche al codice.

Vincoli di progetto:
- Manutenzione individuale → minimizzare dipendenze e magia
- Bassa frequenza di modifica → preferire codice esplicito a code-gen / framework pesanti
- Dati sanitari del personale → sicurezza non negoziabile (escape automatico, CSRF, sessioni hardenate, password gestite correttamente)
- Distribuzione "scarica e configura" su LAMP standard → niente build-step JS complessi

## 2. Decisioni

### 2.1 Stack di base
- **PHP 8.2+** (Carbon eliminato a favore di `DateTimeImmutable` nativo)
- **MySQL/MariaDB** (manteniamo lo schema esistente come baseline)
- **Apache + mod_rewrite** (target deployment), compatibile con nginx via `try_files`
- **PHP vanilla MVC** custom — niente framework

### 2.2 Dipendenze Composer (minime e giustificate)
| Libreria | Scopo | Perché necessaria |
|---|---|---|
| `twig/twig` | Template engine view | Escape automatico = elimina l'XSS endemico del vecchio progetto |
| `vlucas/phpdotenv` | Lettura `.env` | Standard de-facto, leggera, no magia |
| `monolog/monolog` | Logging PSR-3 | Sostituisce le `error_log()` sparse, livelli configurabili |
| `phpoffice/phpspreadsheet` | Import/export Excel | Già usato e funzionante nel vecchio |
| `phpmailer/phpmailer` | Notifiche email (futuro) | Già usato; resta opzionale |
| `phpunit/phpunit` (dev) | Test | Test sui service di calcolo (saldi, conflitti) |

Eliminate: `nesbot/carbon` (sostituita da DateTimeImmutable nativo).

### 2.3 Struttura cartelle
```
planner-hospice/
├── public/                  # document root del web server
│   ├── index.php            # front controller unico
│   ├── .htaccess
│   ├── css/                 # asset statici, niente CDN
│   ├── js/
│   └── images/
├── src/
│   ├── Controllers/
│   ├── Models/
│   ├── Services/
│   ├── Helpers/
│   ├── Middleware/          # Auth, CSRF, ErrorHandler
│   ├── Validators/
│   └── Routing/             # Router + definizione rotte
├── views/                   # template Twig (.twig), fuori da src/
│   ├── layout/
│   ├── auth/
│   ├── turni/
│   └── ...
├── config/
│   ├── app.php              # config tecnica (debug, timezone, ...)
│   ├── organization.php     # personalizzazione ente: nome, ore, pattern, requisiti
│   └── routes.php
├── database/
│   ├── schema.sql           # schema iniziale
│   └── migrations/          # incrementali, numerate
├── storage/                 # NON committato, creato all'install
│   ├── logs/
│   ├── cache/               # cache Twig
│   └── uploads/
├── tests/
│   ├── Unit/
│   └── Integration/
├── docs/
│   └── adr/
├── .env.example
├── .gitignore
├── composer.json
└── README.md
```

Namespace radice: `App\` con sotto-namespace allineati alle cartelle di `src/`.

### 2.4 Sicurezza
- **Configurazione segreti**: solo via `.env`, mai in repo. `.env.example` committato come template.
- **Sessioni**: `session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Lax'])` esplicito; `session_regenerate_id(true)` al login e al cambio password.
- **CSRF**: middleware obbligatorio su tutte le richieste non-GET. Token per-session in input nascosto `_token`, validato lato server. Twig helper `{{ csrf_field() }}`.
- **Autorizzazione**: middleware centralizzato sul router. Mappa `controller@action → ruoli ammessi`. I controller non chiamano più `requireRole()` ovunque.
- **Hashing password**: `password_hash($pwd, PASSWORD_DEFAULT)` SEMPRE al SET, mai euristiche su `strlen()`.
- **Login**: lockout reale (≥5 tentativi falliti → blocco temporaneo per username **e** per IP, default 15 min). Nessuna distinzione di messaggio tra "username inesistente" e "password sbagliata".
- **Output**: zero `echo` di variabili nelle view; tutto via Twig (auto-escape attivo).
- **Errori**: `display_errors=0` di default; pagine 4xx/5xx custom; stack trace solo in `APP_ENV=local` e mai esposti in produzione.
- **Headers di sicurezza** via middleware: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, CSP base.

### 2.5 Database access
- Tutto via PDO con prepared statement.
- `BaseModel` con CRUD generici, ma **allow-list dei campi** (`protected array $fillable = [...]`) — i nomi delle colonne non vengono mai presi da input utente.
- Transazioni sui flussi multi-step: copia piano, ricalcolo saldi, generazione automatica.
- Per query complesse (join, aggregazioni) → metodo dedicato nel Model, non `rawQuery` sparso.

### 2.6 Frontend
- **Bootstrap 5** self-hosted in `public/css/` e `public/js/` (niente CDN, niente SRI da gestire).
- **jQuery rimosso**. Interattività con JS vanilla; per richieste AJAX l'API `fetch`.
- **Niente bundler** (no Vite/esbuild/webpack). File statici. Cache-busting via `?v=<filemtime>` calcolato da un helper.
- **Niente CSS/JS inline nelle view**. Tutto in file dedicati, anche se piccoli.
- **Dati PHP→JS** passati via `data-*` attributi o endpoint JSON dedicato — mai `<script>const X = <?= json_encode(...) ?></script>`.

### 2.7 Personalizzazione (multi-organizzazione leggera)
File `config/organization.php` (committato come `.example`, copiato e modificato dall'IT locale):
```php
return [
    'name' => 'Hospice di Abbiategrasso',
    'ore_contrattuali_default' => 165.0,
    'ore_giornaliere_standard' => 7.5,
    'pattern_turnazione' => [
        'standard' => ['M','M','P','N','S','R'],
        'no_notti' => ['M','M','P','P','R','R'],
        'coordinator' => ['G','G','G','G','G','R','R'],
    ],
    'requisiti_copertura' => [ /* per turno e categoria */ ],
    'soglia_riposo_minimo_ore' => 11,
];
```
Tipi turno e categorie operatori restano in DB (modificabili da UI admin).

### 2.8 Test
- PHPUnit, focus sui service ad alto valore di regressione: `SaldoService`, `ConflictService`, `TurniGeneratorService`, `PatternManager`.
- Niente target di coverage assoluto. Obiettivo: ogni regola di calcolo ha almeno un test.
- Niente test E2E browser (overkill per il contesto).

### 2.9 Logging
- Monolog scrive su `storage/logs/app.log` con rotazione giornaliera.
- Livelli: `debug` solo in dev, `info`/`warning`/`error` in produzione.
- Eventi tracciati: login (successo/fallimento), modifica turni, pubblicazione piano, errori non gestiti.

### 2.10 Convenzioni di codice
- **PSR-12**.
- **Strict types** (`declare(strict_types=1);`) in tutti i file `src/`.
- Naming italiano per concetti di dominio (Operatore, Piano, Turno, Saldo) e inglese per primitive tecniche (Controller, Service, Repository).
- Commenti solo dove il "perché" non è ovvio dal codice.

## 3. Conseguenze

**Pro**
- Stack semplice e auditabile da una persona sola.
- Sicurezza by-default su escape, CSRF, sessioni.
- Tre punti di personalizzazione chiari per terzi (`.env`, `config/organization.php`, DB tipi turno/categorie).
- Eliminazione di 2 dipendenze fragili (jQuery, Carbon) e dei CDN.

**Contro / Trade-off accettati**
- Niente ORM → un po' di boilerplate nei Model. Compensato da BaseModel con CRUD generici.
- Niente framework → manca un ecosystem di pacchetti pronti (es. autenticazione OAuth). Per ora non serve.
- Niente bundler → asset versioning manuale, ma file pochi e raramente modificati.
- Twig aggiunge una dipendenza, ma è lo scambio per l'eliminazione strutturale dell'XSS — accettato.

## 4. Alternative considerate

| Alternativa | Motivo dello scarto |
|---|---|
| **Slim 4 + Twig + Eloquent** | Più moderno ma 3-4 dipendenze in più da aggiornare; magia ORM nasconde le query reali (importanti per la caposala che vuole capire i calcoli) |
| **Laravel + Filament** | Overkill per scope e team di una persona. Upgrade major ogni anno costoso da seguire |
| **Mantenere PHP "vanilla" senza Twig** | XSS endemico è stato il problema #1 del vecchio progetto; risolverlo a mano in 38 view è meno robusto di un template engine con auto-escape |
| **Riscrittura in altro linguaggio** (Python/FastAPI, Node/Express) | Stack di hosting LAMP già disponibile, nessun beneficio funzionale |

## 5. Da rivedere quando

- Se in futuro l'app deve supportare più organizzazioni nello stesso deploy → introdurre un layer di tenant; oggi out-of-scope.
- Se compaiono webhook/integrazioni esterne → valutare allora un micro-framework di routing.
- Se la suite di test cresce oltre i service e include controller → valutare PHPUnit + un client HTTP di test.
