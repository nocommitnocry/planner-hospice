# Spec — Generazione PDF del piano turni

> **Audience:** Claude Code. Questo documento descrive **cosa** fare e **perché**, lasciando a Claude Code i dettagli di implementazione (nomi file, namespace, layout HTML/CSS interni). Le decisioni già prese sono evidenziate come **Deciso**; quello che è esplicitamente lasciato aperto è marcato **A discrezione**.
>
> **Pre-lettura obbligatoria prima di scrivere codice:**
> - `docs/adr/0001-architettura-refactor.md` (stack, vincoli, principi)
> - `views/piani_turno/show.twig` (la griglia HTML attuale è il modello visivo)
> - `public/css/app.css` sezione "Calendario piano turno" (regole weekend, `.tt-bg-*`, `op-col` sticky)
> - `src/Controllers/PianiTurnoController.php` metodo `show()` (struttura dei dati `giorni`, `saldi`, `turniByOpData`, `crossSettingByOpData`, `nascostiGriglia`)
>
> **Principio guida (ADR 0001 + sessioni precedenti):** codice esplicito, niente magia, niente automatismi opachi, riusare il più possibile la logica già scritta nel controller `show`.

---

## 1. Obiettivo

Aggiungere al piano turni un'azione **«Stampa PDF»** che produce un PDF della griglia mensile pronto per stampa cartacea su **A3 orizzontale**, da affiggere o distribuire alla squadra.

Il PDF è uno strumento operativo della coordinatrice: deve essere **leggibile a colpo d'occhio sul cartaceo**, non un export tecnico. Niente saldi ore, niente legenda, niente intestazione di servizio: solo la griglia con le informazioni indispensabili per orientarsi.

---

## 2. Ambito e vincoli

### Decisioni di prodotto (dall'utente)

| Punto | Scelta |
|---|---|
| **Destinatario** | Coordinatrice → stampa cartacea da affiggere/distribuire |
| **Contenuto** | **Solo griglia calendario**. Nessuna tabella saldi, nessuna legenda tipi turno, nessun blocco intestazione/firma |
| **Stato del piano** | **Solo `pubblicato`**. Bozza e archiviato non espongono il bottone |
| **Formato pagina** | **A3 orizzontale**, sempre. Niente scelta A4/A3 al momento |
| **Colori** | **Colori pieni** identici a schermo (le classi `.tt-bg-*`). Pastello e B/N sono fuori scope |
| **Prima colonna operatori** | **Cognome + Nome per esteso** (servono entrambi per evitare omonimie). Categoria visibile come sotto-riga piccola, come a schermo |
| **Raggruppamento operatori** | Tre blocchi, in ordine: **Infermieri** (alfabetico per cognome) → **OSS** (alfabetico per cognome) → **Coordinatrice** (in coda). Ogni blocco ha **una propria intestazione di gruppo e ripete la riga delle date** sopra le righe operatore del gruppo, così la coordinatrice si orienta sul foglio senza dover risalire all'header della tabella |
| **Tecnologia rendering** | **A discrezione di Claude Code**, vedi §6 — vincolo: deve gestire bene tabella larga A3, UTF-8, colori CSS, e non aggiungere magia (rispetto ADR 0001) |

### Non in scope (esplicito)

- ❌ Saldi ore (la tabella sotto la griglia in `show.twig`)
- ❌ Legenda tipi turno
- ❌ Watermark "BOZZA" (non si stampa la bozza)
- ❌ Export del piano archiviato (per ora; eventuale estensione futura)
- ❌ Stampa di più piani in batch / mese su mese
- ❌ Personalizzazione runtime (formato, colori, gruppi): la coordinatrice clicca, esce il PDF
- ❌ Firma digitale, PDF/A, metadati estesi: PDF semplice basta

---

## 3. Sorgente dati: riusare `PianiTurnoController::show()`

La vista `show.twig` calcola già tutto quello che serve. La spec **non** introduce nuove query: prepara gli stessi dati e li passa a un template gemello pensato per il PDF.

Dati riusati così come sono:

- `$piano` (intestazione: anno/mese, setting, stato)
- `$giorni` (array `[{date: 'Y-m-d', numero: 1..31, nome: 'Lun', weekend: bool}]`)
- `$saldi` (operatori del piano, già joinati con categoria, setting, flag `aggiunto_manualmente`)
- `$turniByOpData[id_operatore][Y-m-d] → turno` (con `id_tipo_turno`, `tipo_codice`, `tipo_descrizione`, `tipo_colore`)
- `$crossSettingByOpData[id_operatore][Y-m-d] → turno` (operatore prestato a un altro setting nello stesso mese — va mostrato attenuato come a schermo, vedi §5)
- `$nascostiGriglia` (operatori da non disegnare nella griglia: maternità/aspettativa a mese intero)

L'unica trasformazione **aggiuntiva** richiesta è il raggruppamento (§4).

---

## 4. Raggruppamento operatori

### Regola

Dato l'elenco `$saldi` (già filtrato `s not in nascostiGriglia`), produrre **tre gruppi ordinati**:

1. **Infermieri** — operatori la cui `categoria` è quella infermieristica del setting (codice tipico: `INF`, ma **non hardcodare la stringa**: leggi dalla tabella `categorie_operatore` il flag che già distingue infermieri da OSS, o se il flag non esiste, riusa l'eventuale convenzione di codice già in uso nel progetto — Claude Code: ispeziona `categorie_operatore` e i seed prima di scegliere).
2. **OSS** — operatori OSS, stesso criterio.
3. **Coordinatrice** — operatore/i con categoria coordinatrice. In coda. Tipicamente una sola persona; se sono più di una, ordinate alfabeticamente.

Dentro ogni gruppo: **ordine alfabetico per cognome, poi nome**.

### Cosa fare se una categoria non rientra in nessuno dei tre gruppi?

Mettere quegli operatori in un **quarto gruppo «Altri»** alla fine, in modo che nessuno scompaia silenziosamente. Loggare a livello `INFO` la lista degli id finiti in «Altri» (sintomo di seed da rivedere).

### Rendering di ogni gruppo nel PDF

Per ciascun gruppo:

```
┌──────────────────────────────────────────────────────────────────┐
│ INFERMIERI                                                       │  ← banda titolo gruppo
├──────────────────────────────────────────────────────────────────┤
│       │ Lun │ Mar │ Mer │ Gio │ Ven │ Sab │ Dom │ Lun │ ...      │  ← riga date RIPETUTA
│       │  1  │  2  │  3  │  4  │  5  │  6  │  7  │  8  │ ...      │
├──────────────────────────────────────────────────────────────────┤
│ Rossi Maria   │  M  │  P  │  N  │  S  │  R  │  M  │  M  │ ...    │
│ Infermiere    │     │     │     │     │     │     │     │        │
├──────────────────────────────────────────────────────────────────┤
│ Verdi Anna    │  P  │  R  │  M  │  M  │  P  │  F  │  F  │ ...    │
│ Infermiere    │     │     │     │     │     │     │     │        │
└──────────────────────────────────────────────────────────────────┘
```

Note:
- La **riga date** (giorno-settimana + numero) è ripetuta in cima a ciascun gruppo, **non solo all'inizio della tabella**. Decisione esplicita dell'utente per facilitare l'orientamento sul cartaceo.
- La **banda titolo gruppo** è in maiuscoletto/bold, sfondo grigio chiaro, occupa tutta la larghezza della tabella.
- Tra un gruppo e l'altro: piccolo spazio di separazione (non una pagina nuova: tutto deve stare sullo stesso foglio se possibile, vedi §6).

---

## 5. Resa visiva delle celle (parità con `show.twig`)

Il PDF deve **assomigliare alla griglia a schermo**, perché è il riferimento visivo che la coordinatrice già conosce.

### Da preservare identico

- **Colori delle celle con turno**: classe `.tt-bg-{id_tipo_turno}` con lo stesso colore servito da `/assets/tipi-turno-colori`. Il PDF deve risolvere questi colori in `background-color` inline (la maggior parte dei renderer PDF non segue stylesheet servite dinamicamente). Suggerimento di implementazione: il controller PDF rilegge i colori da `tipi_turno` e li inietta come stili inline sulle celle. **Non riusare il CSS HTML così com'è**: deve essere un CSS dedicato al PDF (vedi §6).
- **Codice tipo turno** al centro della cella (es. `M`, `P`, `N`, `F`).
- **Weekend**: bordi verticali laterali marcati come a schermo (l'enfasi "a penna" di Olga, vedi `app.css`).
- **Conflitto assenza**: bordo rosso interno (classe `cella-conflitto-assenza`).
- **Cross-setting**: cella attenuata con codice in colore tenue (l'operatore è in prestito su un altro piano dello stesso mese). Riusare la logica di `show.twig` per riconoscerlo da `$crossSettingByOpData`.

### Da NON portare nel PDF

- Tooltip e link `<a>` (è carta).
- Bottoni, azioni, badge "in itinere" sulla colonna operatore (è informazione di gestione, non operativa per chi legge in reparto).
- Le righe degli operatori in `$nascostiGriglia` (maternità intero mese): già escluse dalla griglia di `show.twig`, stessa esclusione qui.

---

## 6. Tecnologia di rendering

**Scelta consigliata: `mpdf/mpdf`**.

Motivazione:
- `dompdf` ha noti limiti con tabelle molto larghe (31 colonne giorno × prima colonna larga) e supporto incompleto di CSS3.
- `mpdf` gestisce nativamente A3 landscape, tabelle ripetute, intestazioni di gruppo, e ha buon supporto UTF-8 (cognomi italiani con accenti).
- `mpdf` è già fra le dipendenze suggerite di `phpoffice/phpspreadsheet` (cfr. `composer.lock`) → installarlo non aggiunge un universo nuovo, è coerente con lo stack.
- L'alternativa `@media print` lato browser è scartata: la coordinatrice deve ottenere un file da archiviare/inviare, non dipendere dalle impostazioni di stampa del singolo browser.

**Aggiungere a `composer.json`** (sezione `require`, non `require-dev`, perché è runtime):

```
"mpdf/mpdf": "^8.2"
```

Se `composer require` introduce conflitti, Claude Code: **fermati e segnala**, non forzare versioni.

### Configurazione mpdf consigliata

- Formato: `'A3-L'` (A3 landscape)
- Margini: `10mm` per lato (più stretti del default per massimizzare la griglia)
- Font: default DejaVuSans (UTF-8 OK)
- `tempDir`: directory writable applicativa (es. `storage/tmp/mpdf` — Claude Code: creala se non c'è, verifica permessi)
- **Importante:** non lasciare la temp dir nella default `vendor/mpdf/mpdf/tmp` (read-only in molti deploy)

---

## 7. Architettura della modifica

Tre tocchi al codice, niente di più.

### 7.1 Service dedicato

Nuovo `src/Services/PianoPdfService.php`. Responsabilità:

- Ricevere `$idPiano`
- Verificare che il piano esista e sia `pubblicato` (vincolo §2). Lanciare eccezione applicativa altrimenti.
- Riusare la **stessa logica di caricamento dati** di `PianiTurnoController::show()`. Se necessario, **estrarre quella logica in un metodo privato/condiviso** del controller o, meglio, in un nuovo `PianoVistaService` che entrambi (show + pdf) consumano. Evitare il copia-incolla — è la classica occasione per il refactor che la sessione 4-sexies ha già menzionato per `SchemaResolver`.
- Calcolare il **raggruppamento operatori** (§4).
- Renderizzare via Twig un template dedicato `views/piani_turno/pdf.twig`.
- Passare l'HTML risultante a mpdf e restituire il binario PDF (o stream).

Firma proposta:

```php
public function genera(int $idPiano): string  // ritorna il binario PDF
```

Niente side-effect: il service produce e ritorna. Lo streaming/download è del controller.

### 7.2 Controller

Aggiungere a `PianiTurnoController` un metodo `pdf(Request $request): Response`:

- Solo admin + caposala (coerente con tutte le azioni operative del piano)
- `$id = (int) $request->param('id')`
- Carica il piano, 404 se non esiste
- Se `stato !== 'pubblicato'` → redirect con flash error "Il PDF è disponibile solo per piani pubblicati"
- Chiama `PianoPdfService::genera($id)`
- Risposta `application/pdf` con `Content-Disposition: attachment; filename="piano-{setting}-{YYYY-MM}.pdf"` (es. `piano-hospice-2026-05.pdf`)
- Log `Logger::get()->info('PDF piano generato', [...])`

**Routing** (coerente con il pattern esistente): `GET /piani-turno/{id}/pdf`.

### 7.3 UI

In `views/piani_turno/show.twig`, nella barra azioni in alto, **solo quando `piano.stato == 'pubblicato'` E utente è admin/caposala**, aggiungere un bottone:

```twig
<a href="{{ url('/piani-turno/' ~ piano.id ~ '/pdf') }}"
   class="btn btn-sm btn-outline-dark"
   target="_blank" rel="noopener">
    &#x1F5A8; Stampa PDF
</a>
```

Posizionarlo accanto agli altri bottoni di stato (publish/unpublish/archive), non dentro la barra "Calendario" che è riservata alle azioni di editing.

---

## 8. Template `views/piani_turno/pdf.twig`

Nuovo template Twig **separato da `show.twig`**. Non estende `layout/base.twig` (che porta navbar, Bootstrap, footer): è un documento standalone con `<html><head><style>...</style></head><body>...`.

Vincoli del template:

- Tutto il CSS **inline nel `<style>`** del documento (mpdf non scarica stylesheet esterni in modo affidabile).
- Larghezze fisse in `mm` per la prima colonna (operatore) e larghezze in `%` o `mm` uniformi per le colonne giorno. Calcola: A3 landscape ≈ 420mm × 297mm, margine 10mm per lato → larghezza utile ~400mm. Prima colonna ~60mm, restanti 340mm divisi per il numero di giorni (28-31).
- `font-size` ridotto rispetto a schermo (suggerito 8-9pt sui codici, 6-7pt sui nomi giorno) — A3 è grande, ma 31 colonne sono tante.
- `page-break-inside: avoid` sulle righe operatore, sui titoli di gruppo e sulla coppia "titolo gruppo + riga date".
- Header pagina mpdf (`SetHTMLHeader`): titolo breve es. `Piano turni — Hospice — Maggio 2026 — pubblicato il 24/05/2026`. Footer con numero pagina (`{PAGENO} / {nbpg}`).

Suggerimento struttura del corpo:

```twig
{% for gruppo in gruppi %}
    <table class="griglia">
        <thead>
            <tr><th colspan="{{ giorni|length + 1 }}" class="gruppo-titolo">{{ gruppo.nome|upper }}</th></tr>
            <tr class="riga-date">
                <th class="op-col">&nbsp;</th>
                {% for g in giorni %}
                    <th class="{% if g.weekend %}weekend{% endif %}">
                        <div class="dow">{{ g.nome }}</div>
                        <div class="dom">{{ g.numero }}</div>
                    </th>
                {% endfor %}
            </tr>
        </thead>
        <tbody>
            {% for op in gruppo.operatori %}
                {# riga operatore identica nello spirito a show.twig, senza link/badge #}
            {% endfor %}
        </tbody>
    </table>
{% endfor %}
```

Una `<table>` separata per gruppo è più semplice da gestire dei `<tbody>` multipli per le regole di page-break.

---

## 9. Test

Aggiungere a `tests/` (PHPUnit, coerente con setup esistente):

### 9.1 Unit — `PianoPdfServiceTest`

- ✅ Lancia eccezione se piano non esiste
- ✅ Lancia eccezione se piano è in `bozza`
- ✅ Lancia eccezione se piano è `archiviato`
- ✅ Ritorna bytes non vuoti che iniziano con `%PDF-` per piano pubblicato valido
- ✅ Il raggruppamento mette infermieri prima di OSS prima di coordinatrice
- ✅ All'interno del gruppo, l'ordine è alfabetico per cognome
- ✅ Operatori in `nascostiGriglia` (maternità intero mese) NON compaiono nella griglia
- ✅ Categoria non riconosciuta → finisce nel gruppo "Altri" + log INFO

### 9.2 Funzionale (smoke) — `PianiTurnoControllerPdfTest`

- ✅ `GET /piani-turno/{id}/pdf` di un piano pubblicato risponde 200 + `Content-Type: application/pdf`
- ✅ `GET /piani-turno/{id}/pdf` di una bozza redirect 302 con flash
- ✅ Utente visualizzatore → 403 (no permesso)
- ✅ Nome file scaricato matcha `piano-{setting}-{YYYY-MM}.pdf`

### 9.3 Verifica manuale (da spuntare prima di chiudere la sessione)

Documentare nelle session notes:

- [ ] Stampa di prova A3 su un piano Hospice di un mese da 31 giorni → leggibile, colori OK
- [ ] Stampa di prova A3 su un piano UCP-DOM di un mese da 28 giorni → spazio bianco a destra accettabile
- [ ] Cross-setting visibile e distinguibile dai turni "veri"
- [ ] Conflitto assenza (bordo rosso) visibile in stampa
- [ ] La riga date appare in cima a ogni gruppo (non solo all'inizio)
- [ ] Coordinatrice in fondo
- [ ] Cognome + nome leggibili anche per omonimie ("Rossi Maria" vs "Rossi Anna")

---

## 10. Cosa NON fare (anti-pattern già visti nel progetto)

- ❌ **Non** mettere CSS inline negli elementi che la CSP `style-src 'self'` blocca a runtime: questo è un documento PDF stand-alone servito off-band, non passa dalla CSP del browser, ma **se** in futuro qualcuno aprisse l'HTML intermedio in iframe, fallirebbe. Mantenere lo stile nel `<style>` del documento.
- ❌ **Non** duplicare la logica di `show()`: estrarre in service condiviso (vedi §7.1). Se il refactor sembra grosso, fermati e chiedi prima di partire.
- ❌ **Non** hardcodare i codici categoria (`INF`, `OSS`, `COORD`): leggi dal DB. Il progetto è multi-organizzazione (ADR 0001).
- ❌ **Non** generare il PDF in modo asincrono / con job queue: è sincrono, click → download. Un piano mensile è max ~40 operatori × 31 giorni, mpdf lo digerisce in 1-2 secondi.
- ❌ **Non** salvare il PDF su disco a meno che serva per cache (e per ora **non** serve: i piani pubblicati sono mutabili via unpublish, una cache invaliderebbe troppo facilmente).

---

## 11. Estensioni future (fuori scope, segnalate)

Le seguenti sono **idee per dopo**, da NON implementare in questa sessione ma utili da tenere a mente nel design:

- Variante "B/N risparmio toner" (toggle nel bottone)
- Variante "PDF singolo operatore" (una pagina per ciascun operatore con il proprio mese)
- Aggiunta della tabella saldi su seconda pagina (opzionale via query string)
- Watermark "BOZZA" se in futuro si decidesse di esporre il PDF anche per bozze
- Stampa di range multi-mese

Se Claude Code nota che una decisione di design **chiuderebbe la porta** a una di queste estensioni, segnalarlo nel commit message o nelle note di sessione.

---

## 12. Definition of done

- [ ] `mpdf/mpdf` aggiunto a `composer.json` e installato
- [ ] `src/Services/PianoPdfService.php` creato, testato
- [ ] Refactor (se intrapreso) di `PianiTurnoController::show()` per condividere il caricamento dati
- [ ] Metodo `pdf()` nel controller + rotta `GET /piani-turno/{id}/pdf`
- [ ] Template `views/piani_turno/pdf.twig`
- [ ] Bottone «Stampa PDF» in `show.twig` (solo pubblicato, solo admin/caposala)
- [ ] Test unit + funzionali verdi
- [ ] Verifica manuale §9.3 spuntata
- [ ] Nota di sessione aggiornata in `docs/SESSION_NOTES.md` col blocco "Cosa è stato fatto" + "Decisioni di sessione" nel formato esistente
- [ ] Banner sessione corrente aggiornato in `views/dashboard/index.twig` (se segui la convenzione delle sessioni precedenti)
