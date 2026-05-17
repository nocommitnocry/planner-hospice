# Planner Hospice

Applicativo di gestione turni per personale sanitario in struttura hospice.
Riscrittura completa del precedente `planner-turni-hospice` con focus su
sicurezza, leggibilità e manutenibilità da parte di un'unica manutentrice.

> Stato attuale: **sessione 3 completata** (fondamenta + autenticazione +
> CRUD anagrafiche + piani turno mensili). La compilazione del calendario,
> i saldi ore aggiornati dai turni, i conflitti e il generatore automatico
> arriveranno nelle sessioni successive.

## Caratteristiche già attive

- Autenticazione con lockout per username + IP
- Sessioni con cookie `HttpOnly`/`SameSite=Lax` e `session_regenerate_id` su login/cambio password
- Protezione CSRF su tutte le richieste mutanti
- Header di sicurezza standard + CSP self-only
- Template Twig con escape automatico (XSS protection by-default)
- Logging strutturato con Monolog su file rotanti
- Configurazione separata per ente (file unico `config/organization.php`)

## Requisiti

- **PHP 8.2+** con estensioni: `pdo_mysql`, `mbstring`, `intl`, `json`, `xml`, `zip`, `gd`, `curl`
- **MySQL 5.7+** o **MariaDB 10.3+**
- **Apache** con `mod_rewrite` (oppure nginx con `try_files`)
- **Composer 2.x**
- `curl` (solo per lo script di installazione asset)

### Installazione PHP su Debian 13 (Trixie)

Debian 13 distribuisce PHP 8.4 (compatibile con il vincolo `^8.2`):

```bash
sudo apt install php8.4-cli php8.4-mysql php8.4-mbstring php8.4-intl \
                 php8.4-xml php8.4-curl php8.4-zip php8.4-gd composer
```

### Installazione PHP su Debian 12 / Ubuntu 22.04+

```bash
sudo apt install php8.2-cli php8.2-mysql php8.2-mbstring php8.2-intl \
                 php8.2-xml php8.2-curl php8.2-zip php8.2-gd composer
```

## Installazione

```bash
# 1. Clone della repo
git clone <url-repo> planner-hospice
cd planner-hospice

# 2. Dipendenze PHP
composer install

# 3. Asset frontend (Bootstrap 5 self-hosted)
chmod +x bin/install-assets.sh
./bin/install-assets.sh

# 4. Configurazione
cp .env.example .env
# Modificare .env con i parametri reali (DB, APP_URL, APP_KEY)
# Generare APP_KEY:
php -r 'echo bin2hex(random_bytes(32)) . PHP_EOL;'

# 5. Database
mysql -u root -p -e "CREATE DATABASE hospice_turni CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'hospice_user'@'localhost' IDENTIFIED BY 'PASSWORD_QUI';"
mysql -u root -p -e "GRANT ALL ON hospice_turni.* TO 'hospice_user'@'localhost';"
mysql -u hospice_user -p hospice_turni < database/schema.sql

# 6. Utente amministratore (interattivo)
chmod +x bin/create-admin.php
php bin/create-admin.php

# 7. Permessi su cartelle scrivibili
chmod -R 775 storage/
```

## Server web

### Apache

Document root: `public/`. Il file `public/.htaccess` è già configurato per
`mod_rewrite`. Assicurarsi che la direttiva `AllowOverride All` sia attiva
nel VirtualHost.

### Nginx

```nginx
server {
    listen 80;
    server_name plannerhospice.example.org;
    root /var/www/planner-hospice/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Blocca accesso ai file dot
    location ~ /\. { deny all; }
}
```

### Server PHP integrato (solo sviluppo)

```bash
php -S localhost:8000 -t public/
```

## Configurazione

| File | Cosa contiene | Quando modificarlo |
|---|---|---|
| `.env` | Segreti: credenziali DB, chiavi, flag debug | A ogni deploy |
| `config/app.php` | Costanti tecniche (lockout, session, log) | Raramente |
| `config/organization.php` | Personalizzazione ente: nome, ore, pattern, requisiti copertura | Per adattare l'app a una nuova organizzazione |
| `database/schema.sql` | Schema DB iniziale | Solo per installazione pulita |
| `database/migrations/*.sql` | Migrazioni incrementali | A ogni cambio schema |

## Struttura cartelle

```
planner-hospice/
├── bin/                     Script CLI (install-assets, create-admin)
├── config/                  Configurazione applicativa
├── database/
│   ├── schema.sql
│   └── migrations/
├── docs/
│   └── adr/                 Architecture Decision Records
├── public/                  Document root del web server
│   ├── index.php            Front controller unico
│   ├── .htaccess
│   ├── css/                 Bootstrap + app.css
│   ├── js/                  Bootstrap bundle
│   └── images/
├── src/
│   ├── Controllers/
│   ├── Helpers/             (Container, Config, Database, Session, Csrf, ...)
│   ├── Middleware/          (ErrorHandler, SecurityHeaders, Auth*, Csrf)
│   ├── Models/
│   ├── Routing/             (Request, Response, Route, Router)
│   ├── Services/
│   └── Validators/
├── views/                   Template Twig
├── storage/                 NON committato
│   ├── cache/
│   ├── logs/
│   └── uploads/
├── tests/
├── composer.json
└── .env.example
```

## Sicurezza

| Aspetto | Implementazione |
|---|---|
| Password | `password_hash()` con `PASSWORD_DEFAULT` (bcrypt). `password_needs_rehash` automatico al login |
| Sessione | Cookie `HttpOnly`, `SameSite=Lax`, `Secure` (se HTTPS). `session_regenerate_id(true)` su login e cambio password |
| CSRF | Token per-session validato su POST/PUT/PATCH/DELETE. Helper Twig `{{ csrf_field() }}` |
| XSS | Twig con auto-escape attivo. Niente `echo` di variabili lato server |
| Lockout | 5 tentativi falliti per username, 15 per IP, blocco 15 minuti (configurabile in `.env`) |
| SQL | Solo prepared statement via PDO. BaseModel con allow-list dei campi |
| Header | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, CSP self-only |
| Segreti | Solo in `.env`, mai in repo |

## Logging

Tutti i log vanno in `storage/logs/app-YYYY-MM-DD.log` (rotazione 14 giorni).
Livello configurabile in `.env` con `LOG_LEVEL`.

Eventi tracciati: login successo/fallimento, cambio password, errori non gestiti, CSRF non valido.

## Sviluppo

```bash
# Server di sviluppo
php -S localhost:8000 -t public/

# Test
composer test

# Coverage
composer test-coverage
```

## Adattamento per altra organizzazione

Per riusare l'app in un contesto diverso (es. laboratorio universitario):

1. Modificare `config/organization.php` (nome ente, pattern, requisiti).
2. Personalizzare categorie e tipi turno via UI admin (sessioni 2-3).
3. Eventualmente sostituire il logo in `public/images/`.

Nessuna modifica al codice è necessaria.

## Documentazione tecnica

- ADR architetturali: `docs/adr/`
- Schema DB: `database/schema.sql`

## Licenza

GPL-2.0-or-later (come la versione precedente).
