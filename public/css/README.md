# Asset CSS/JS

Bootstrap 5 è **self-hosted** in questa cartella e in `public/js/`. I file vengono
scaricati una volta sola tramite lo script `bin/install-assets.sh`:

```bash
./bin/install-assets.sh
```

Lo script scarica:
- `public/css/bootstrap.min.css` (Bootstrap 5.3.x)
- `public/js/bootstrap.bundle.min.js` (Bootstrap 5.3.x con Popper)

Niente CDN, niente SRI da gestire, niente dipendenze runtime esterne.

Per aggiornare a una versione successiva: modificare la variabile `BS_VERSION`
in `bin/install-assets.sh` e rilanciarlo.
