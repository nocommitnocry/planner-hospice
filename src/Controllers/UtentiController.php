<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Config;
use App\Helpers\Logger;
use App\Models\SettingModel;
use App\Models\UtenteModel;
use App\Routing\Request;
use App\Routing\Response;
use App\Validators\UtenteValidator;

/**
 * CRUD utenti applicativi (chi può accedere al sistema).
 *
 * Auto-protezioni dell'admin loggato:
 *  - non può eliminarsi
 *  - non può degradare il proprio ruolo (resta admin)
 *  - non può disattivarsi
 * Queste regole prevengono il classico lockout in cui l'unico admin si
 * rimuove i privilegi e poi nessuno può più amministrare.
 *
 * Sicurezza dati:
 *  - La view utenti NON espone mai l'hash password.
 *  - In create la password è obbligatoria, in edit è opzionale (vuoto = invariata).
 *  - L'hash è prodotto QUI con password_hash(); il Model non sa nulla di policy.
 */
final class UtentiController extends BaseController
{
    private UtenteModel $model;
    private SettingModel $settings;

    public function __construct()
    {
        parent::__construct();
        $this->model = new UtenteModel();
        $this->settings = new SettingModel();
    }

    public function index(Request $request): Response
    {
        $rows = $this->model->listAllOrdered();
        // Defense in depth: rimuoviamo l'hash anche se la view non lo userebbe.
        foreach ($rows as &$r) {
            unset($r['password']);
        }
        unset($r);

        return $this->render('utenti/index.twig', [
            'utenti'         => $rows,
            'currentUserId'  => $this->currentUserId(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('utenti/form.twig', [
            'utente'   => null,
            'action'   => '/utenti',
            'titolo'   => 'Nuovo utente',
            'isSelf'   => false,
            'settings' => $this->settings->listAttivi(),
        ]);
    }

    public function store(Request $request): Response
    {
        $input = $this->collectInput($request);
        $settings = $this->settings->listAttivi();
        $idsSet = array_map(static fn ($s) => (int) $s['id'], $settings);

        $validator = new UtenteValidator(
            passwordRequired: true,
            minPasswordLength: $this->minPasswordLength(),
            settingIdValidi: $idsSet,
        );
        $validation = $validator->validate($input);

        if (!$validation['ok']) {
            return $this->redirectWithErrors('/utenti/create', $validation['errors'], $this->safeOldInput($input));
        }

        if ($this->model->existsByUsername($validation['data']['username'])) {
            return $this->redirectWithErrors(
                '/utenti/create',
                ['username' => ['Esiste già un utente con questo username.']],
                $this->safeOldInput($input),
            );
        }

        $payload = $validation['data'];
        $plain = $payload['_password_plain'];
        unset($payload['_password_plain']);
        $payload['password'] = password_hash($plain, PASSWORD_DEFAULT);

        $id = $this->model->create($payload);
        Logger::get()->info('Utente creato', [
            'id' => $id, 'username' => $payload['username'], 'by_user_id' => $this->currentUserId(),
        ]);
        return $this->redirect('/utenti', 'success', 'Utente creato.');
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->param('id');
        $utente = $this->model->find($id);
        if ($utente === null) {
            return $this->redirect('/utenti', 'error', 'Utente non trovato.');
        }
        unset($utente['password']);

        return $this->render('utenti/form.twig', [
            'utente'   => $utente,
            'action'   => "/utenti/{$id}",
            'titolo'   => 'Modifica utente',
            'isSelf'   => $id === $this->currentUserId(),
            'settings' => $this->settings->listAttivi(),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->param('id');
        $utente = $this->model->find($id);
        if ($utente === null) {
            return $this->redirect('/utenti', 'error', 'Utente non trovato.');
        }

        $isSelf = $id === $this->currentUserId();
        $input = $this->collectInput($request);

        // Auto-protezione: se l'admin modifica se stesso, forziamo ruolo=admin
        // e attivo=1 a prescindere da cosa è stato submitato (defense in depth).
        if ($isSelf) {
            $input['ruolo']  = 'admin';
            $input['attivo'] = 1;
        }

        $settings = $this->settings->listAttivi();
        $idsSet = array_map(static fn ($s) => (int) $s['id'], $settings);

        $validator = new UtenteValidator(
            passwordRequired: false,
            minPasswordLength: $this->minPasswordLength(),
            settingIdValidi: $idsSet,
        );
        $validation = $validator->validate($input);

        if (!$validation['ok']) {
            return $this->redirectWithErrors("/utenti/{$id}/edit", $validation['errors'], $this->safeOldInput($input));
        }

        if ($this->model->existsByUsername($validation['data']['username'], excludeId: $id)) {
            return $this->redirectWithErrors(
                "/utenti/{$id}/edit",
                ['username' => ['Esiste già un utente con questo username.']],
                $this->safeOldInput($input),
            );
        }

        $payload = $validation['data'];
        if (isset($payload['_password_plain'])) {
            $payload['password'] = password_hash($payload['_password_plain'], PASSWORD_DEFAULT);
        }
        unset($payload['_password_plain']);

        $this->model->update($id, $payload);
        Logger::get()->info('Utente aggiornato', [
            'id' => $id, 'by_user_id' => $this->currentUserId(),
        ]);
        return $this->redirect('/utenti', 'success', 'Utente aggiornato.');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('id');
        if ($id === $this->currentUserId()) {
            return $this->redirect('/utenti', 'error', 'Non puoi eliminare l\'utente con cui sei loggato.');
        }
        $utente = $this->model->find($id);
        if ($utente === null) {
            return $this->redirect('/utenti', 'error', 'Utente non trovato.');
        }

        $this->model->delete($id);
        Logger::get()->info('Utente eliminato', [
            'id' => $id, 'by_user_id' => $this->currentUserId(),
        ]);
        return $this->redirect('/utenti', 'success', 'Utente eliminato.');
    }

    /** @return array<string,mixed> */
    private function collectInput(Request $request): array
    {
        return [
            'username'         => $request->post('username'),
            'nome'             => $request->post('nome'),
            'cognome'          => $request->post('cognome'),
            'email'            => $request->post('email'),
            'ruolo'            => $request->post('ruolo'),
            'id_setting'       => $request->post('id_setting'),
            'attivo'           => $request->post('attivo'),
            'password'         => $request->post('password'),
            'password_confirm' => $request->post('password_confirm'),
        ];
    }

    /**
     * Rimuove i campi password dall'input prima di salvarlo come "old_input"
     * in sessione: non vogliamo password (anche se in chiaro temporaneo) in
     * un cookie/sessione persistente.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function safeOldInput(array $input): array
    {
        unset($input['password'], $input['password_confirm']);
        return $input;
    }

    private function minPasswordLength(): int
    {
        return (int) Config::get('app.auth.min_password_length', 10);
    }
}
