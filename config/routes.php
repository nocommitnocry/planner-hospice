<?php
declare(strict_types=1);

use App\Controllers\AssenzeController;
use App\Controllers\AuthController;
use App\Controllers\CategorieOperatoriController;
use App\Controllers\DashboardController;
use App\Controllers\OperatoriController;
use App\Controllers\PianiTurnoController;
use App\Controllers\SaldiController;
use App\Controllers\TipiTurnoController;
use App\Controllers\TurniController;
use App\Controllers\UtentiController;
use App\Controllers\VincoliController;
use App\Routing\Router;

/**
 * Registrazione delle rotte applicative.
 *
 * Convenzioni:
 * - Le rotte pubbliche (no auth) usano `public: true`.
 * - Le rotte con `roles: null` richiedono solo autenticazione.
 * - Le rotte con `roles: ['admin', 'caposala']` richiedono uno di quei ruoli.
 * - POST/PUT/DELETE hanno CSRF on di default; passare csrf:false solo per
 *   endpoint API consumati da contesti dove il token è gestito diversamente.
 *
 * Schema CRUD (sessione 2): per ogni risorsa
 *   GET    /risorsa
 *   GET    /risorsa/create
 *   POST   /risorsa                (store)
 *   GET    /risorsa/{id}/edit
 *   POST   /risorsa/{id}           (update)
 *   POST   /risorsa/{id}/delete    (destroy)
 *
 * Manteniamo POST anche per update/destroy (no PUT/DELETE) per compatibilità
 * con form HTML senza JS; il router supporta `_method` se in futuro servisse.
 */
return function (Router $r): void {

    // -------------------------------------------------------------------------
    // Pubbliche (no auth)
    // -------------------------------------------------------------------------
    $r->get('/login', [AuthController::class, 'showLogin'], public: true, name: 'login.show');
    $r->post('/login', [AuthController::class, 'doLogin'], public: true, name: 'login.do');

    // -------------------------------------------------------------------------
    // Sessione (richiede auth)
    // -------------------------------------------------------------------------
    $r->post('/logout', [AuthController::class, 'logout'], name: 'logout');

    $r->get('/change-password', [AuthController::class, 'showChangePassword'], name: 'password.change');
    $r->post('/change-password', [AuthController::class, 'doChangePassword'], name: 'password.change.do');

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------
    $r->get('/', [DashboardController::class, 'index'], name: 'home');
    $r->get('/dashboard', [DashboardController::class, 'index'], name: 'dashboard');

    // -------------------------------------------------------------------------
    // Anagrafiche: categorie operatori (solo admin)
    // -------------------------------------------------------------------------
    $admin = ['admin'];
    $r->get('/categorie-operatori',                [CategorieOperatoriController::class, 'index'],   $admin, name: 'categorie.index');
    $r->get('/categorie-operatori/create',         [CategorieOperatoriController::class, 'create'],  $admin, name: 'categorie.create');
    $r->post('/categorie-operatori',               [CategorieOperatoriController::class, 'store'],   $admin, name: 'categorie.store');
    $r->get('/categorie-operatori/{id}/edit',      [CategorieOperatoriController::class, 'edit'],    $admin, name: 'categorie.edit');
    $r->post('/categorie-operatori/{id}',          [CategorieOperatoriController::class, 'update'],  $admin, name: 'categorie.update');
    $r->post('/categorie-operatori/{id}/delete',   [CategorieOperatoriController::class, 'destroy'], $admin, name: 'categorie.destroy');

    // -------------------------------------------------------------------------
    // Anagrafiche: tipi turno (solo admin)
    // -------------------------------------------------------------------------
    $r->get('/tipi-turno',              [TipiTurnoController::class, 'index'],   $admin, name: 'tipi-turno.index');
    $r->get('/tipi-turno/create',       [TipiTurnoController::class, 'create'],  $admin, name: 'tipi-turno.create');
    $r->post('/tipi-turno',             [TipiTurnoController::class, 'store'],   $admin, name: 'tipi-turno.store');
    $r->get('/tipi-turno/{id}/edit',    [TipiTurnoController::class, 'edit'],    $admin, name: 'tipi-turno.edit');
    $r->post('/tipi-turno/{id}',        [TipiTurnoController::class, 'update'],  $admin, name: 'tipi-turno.update');
    $r->post('/tipi-turno/{id}/delete', [TipiTurnoController::class, 'destroy'], $admin, name: 'tipi-turno.destroy');

    // -------------------------------------------------------------------------
    // Anagrafiche: operatori (admin + caposala)
    // -------------------------------------------------------------------------
    $adminCaposala = ['admin', 'caposala'];
    $r->get('/operatori',                    [OperatoriController::class, 'index'],         $adminCaposala, name: 'operatori.index');
    $r->get('/operatori/create',             [OperatoriController::class, 'create'],        $adminCaposala, name: 'operatori.create');
    $r->post('/operatori',                   [OperatoriController::class, 'store'],         $adminCaposala, name: 'operatori.store');
    $r->get('/operatori/{id}/edit',          [OperatoriController::class, 'edit'],          $adminCaposala, name: 'operatori.edit');
    $r->post('/operatori/{id}',              [OperatoriController::class, 'update'],        $adminCaposala, name: 'operatori.update');
    $r->post('/operatori/{id}/delete',       [OperatoriController::class, 'destroy'],       $adminCaposala, name: 'operatori.destroy');
    $r->post('/operatori/{id}/toggle-attivo',[OperatoriController::class, 'toggleAttivo'],  $adminCaposala, name: 'operatori.toggle-attivo');

    // -------------------------------------------------------------------------
    // Anagrafiche: utenti applicativi (solo admin)
    // -------------------------------------------------------------------------
    $r->get('/utenti',                [UtentiController::class, 'index'],   $admin, name: 'utenti.index');
    $r->get('/utenti/create',         [UtentiController::class, 'create'],  $admin, name: 'utenti.create');
    $r->post('/utenti',               [UtentiController::class, 'store'],   $admin, name: 'utenti.store');
    $r->get('/utenti/{id}/edit',      [UtentiController::class, 'edit'],    $admin, name: 'utenti.edit');
    $r->post('/utenti/{id}',          [UtentiController::class, 'update'],  $admin, name: 'utenti.update');
    $r->post('/utenti/{id}/delete',   [UtentiController::class, 'destroy'], $admin, name: 'utenti.destroy');

    // -------------------------------------------------------------------------
    // Piani turno mensili (sessione 3)
    //
    // Lettura: tutti gli utenti autenticati (anche visualizzatore).
    // Scrittura e transizioni di stato: admin + caposala.
    // -------------------------------------------------------------------------
    $r->get('/piani-turno',                  [PianiTurnoController::class, 'index'],     name: 'piani-turno.index');
    $r->get('/piani-turno/create',           [PianiTurnoController::class, 'create'],    $adminCaposala, name: 'piani-turno.create');
    $r->post('/piani-turno',                 [PianiTurnoController::class, 'store'],     $adminCaposala, name: 'piani-turno.store');
    $r->get('/piani-turno/{id}',             [PianiTurnoController::class, 'show'],      name: 'piani-turno.show');
    $r->get('/piani-turno/{id}/delete-confirm', [PianiTurnoController::class, 'deleteConfirm'], $adminCaposala, name: 'piani-turno.delete-confirm');
    $r->post('/piani-turno/{id}/delete',     [PianiTurnoController::class, 'destroy'],   $adminCaposala, name: 'piani-turno.destroy');
    $r->post('/piani-turno/{id}/publish',    [PianiTurnoController::class, 'publish'],   $adminCaposala, name: 'piani-turno.publish');
    $r->post('/piani-turno/{id}/unpublish',  [PianiTurnoController::class, 'unpublish'], $adminCaposala, name: 'piani-turno.unpublish');
    $r->post('/piani-turno/{id}/archive',    [PianiTurnoController::class, 'archive'],   $adminCaposala, name: 'piani-turno.archive');
    $r->post('/piani-turno/{id}/genera',     [PianiTurnoController::class, 'genera'],    $adminCaposala, name: 'piani-turno.genera');

    // -------------------------------------------------------------------------
    // Turni nel calendario di un piano (sessione 4)
    //
    // Tutto in scrittura riservato a admin + caposala e ammesso solo se il
    // piano è in stato 'bozza' (il controller fa il check).
    // -------------------------------------------------------------------------
    $r->get('/piani-turno/{id}/turni/edit',          [TurniController::class, 'edit'],    $adminCaposala, name: 'turni.edit');
    $r->post('/piani-turno/{id}/turni',              [TurniController::class, 'store'],   $adminCaposala, name: 'turni.store');
    $r->post('/piani-turno/{id}/turni/{tid}',        [TurniController::class, 'update'],  $adminCaposala, name: 'turni.update');
    $r->post('/piani-turno/{id}/turni/{tid}/delete', [TurniController::class, 'destroy'], $adminCaposala, name: 'turni.destroy');

    // -------------------------------------------------------------------------
    // Saldi: aggiunta operatore in itinere e modifica manuale (sessione 4-ter)
    //
    // Tutte le azioni richiedono piano in stato 'bozza' (lo verifica il
    // controller). Le modifiche generano una riga in `saldo_modifiche` con
    // nota obbligatoria.
    // -------------------------------------------------------------------------
    $r->get('/piani-turno/{id}/aggiungi-operatore',          [SaldiController::class, 'addOperatoreForm'], $adminCaposala, name: 'saldi.add-form');
    $r->post('/piani-turno/{id}/aggiungi-operatore',         [SaldiController::class, 'addOperatore'],     $adminCaposala, name: 'saldi.add');
    $r->post('/piani-turno/{id}/operatori/{opid}/rimuovi',   [SaldiController::class, 'removeOperatore'],  $adminCaposala, name: 'saldi.remove');
    $r->get('/piani-turno/{id}/saldi/{sid}/edit',            [SaldiController::class, 'editForm'],         $adminCaposala, name: 'saldi.edit-form');
    $r->post('/piani-turno/{id}/saldi/{sid}',                [SaldiController::class, 'update'],           $adminCaposala, name: 'saldi.update');

    // -------------------------------------------------------------------------
    // Assenze operatori (sessione 4-sexies)
    //
    // CRUD ferie/permessi/malattia/maternita'. Scrittura admin+caposala,
    // lettura admin+caposala (le coordinatrici devono sapere chi è assente).
    // Il filtro automatico "maternita' intero mese" sul fotografa-operatori
    // del piano legge da questa tabella.
    // -------------------------------------------------------------------------
    $r->get('/assenze',              [AssenzeController::class, 'index'],   $adminCaposala, name: 'assenze.index');
    $r->get('/assenze/create',       [AssenzeController::class, 'create'],  $adminCaposala, name: 'assenze.create');
    $r->post('/assenze',             [AssenzeController::class, 'store'],   $adminCaposala, name: 'assenze.store');
    $r->get('/assenze/{id}/edit',    [AssenzeController::class, 'edit'],    $adminCaposala, name: 'assenze.edit');
    $r->post('/assenze/{id}',        [AssenzeController::class, 'update'],  $adminCaposala, name: 'assenze.update');
    $r->post('/assenze/{id}/delete', [AssenzeController::class, 'destroy'], $adminCaposala, name: 'assenze.destroy');

    // -------------------------------------------------------------------------
    // Vincoli operatori (sessione 5-bis)
    //
    // CRUD `operatori_vincoli` (no_notti / no_weekend / solo_mattine). Scrittura
    // e lettura admin+caposala. I vincoli NON sono bloccanti runtime: sono
    // input del generatore (sessione 6) e warning informativo nel form turno.
    // Vedi memoria `project-vincoli-operatori`.
    // -------------------------------------------------------------------------
    $r->get('/vincoli',              [VincoliController::class, 'index'],   $adminCaposala, name: 'vincoli.index');
    $r->get('/vincoli/create',       [VincoliController::class, 'create'],  $adminCaposala, name: 'vincoli.create');
    $r->post('/vincoli',             [VincoliController::class, 'store'],   $adminCaposala, name: 'vincoli.store');
    $r->get('/vincoli/{id}/edit',    [VincoliController::class, 'edit'],    $adminCaposala, name: 'vincoli.edit');
    $r->post('/vincoli/{id}',        [VincoliController::class, 'update'],  $adminCaposala, name: 'vincoli.update');
    $r->post('/vincoli/{id}/delete', [VincoliController::class, 'destroy'], $adminCaposala, name: 'vincoli.destroy');
};
