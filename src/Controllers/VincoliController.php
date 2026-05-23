<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Logger;
use App\Models\OperatoreModel;
use App\Models\SettingModel;
use App\Models\VincoloOperatoreModel;
use App\Routing\Request;
use App\Routing\Response;
use App\Validators\VincoloValidator;

/**
 * CRUD vincoli operatori (sessione 5-bis, 2026-05-23).
 *
 * Pattern: copia adattata di AssenzeController. I vincoli sono informativi
 * (non bloccano runtime): input del generatore (sessione 6) e warning leggibile
 * nel form turno. Vedi memoria `project-vincoli-operatori`.
 *
 * Niente filtro setting nella lista per la prima versione (lista piccola,
 * tipicamente < 10 record).
 */
final class VincoliController extends BaseController
{
    private VincoloOperatoreModel $model;
    private OperatoreModel $operatori;
    private SettingModel $settings;

    public function __construct()
    {
        parent::__construct();
        $this->model = new VincoloOperatoreModel();
        $this->operatori = new OperatoreModel();
        $this->settings = new SettingModel();
    }

    public function index(Request $request): Response
    {
        return $this->render('vincoli/index.twig', [
            'vincoli'      => $this->model->listJoined(),
            'tipiVincolo'  => VincoloValidator::TIPI,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('vincoli/form.twig', [
            'vincolo'     => null,
            'operatori'   => $this->operatori->listWithCategoria(soloAttivi: true),
            'tipiVincolo' => VincoloValidator::TIPI,
            'action'      => '/vincoli',
            'titolo'      => 'Nuovo vincolo',
        ]);
    }

    public function store(Request $request): Response
    {
        $input = $this->collectInput($request);
        $validation = (new VincoloValidator())->validate($input);
        if (!$validation['ok']) {
            return $this->redirectWithErrors('/vincoli/create', $validation['errors'], $input);
        }

        $err = $this->verificaRiferimenti($validation['data']);
        if ($err !== []) {
            return $this->redirectWithErrors('/vincoli/create', $err, $input);
        }

        $data = $validation['data'];
        $data['creato_da'] = $this->currentUserId();
        $id = $this->model->create($data);

        Logger::get()->info('Vincolo creato', [
            'id'           => $id,
            'id_operatore' => $data['id_operatore'],
            'tipo_vincolo' => $data['tipo_vincolo'],
            'attivo'       => $data['attivo'],
            'periodo'      => ($data['data_inizio'] ?? 'sempre') . '..' . ($data['data_fine'] ?? 'senza fine'),
            'user_id'      => $this->currentUserId(),
        ]);
        return $this->redirect('/vincoli', 'success', 'Vincolo registrato.');
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->param('id');
        $vincolo = $this->model->find($id);
        if ($vincolo === null) {
            return $this->redirect('/vincoli', 'error', 'Vincolo non trovato.');
        }
        return $this->render('vincoli/form.twig', [
            'vincolo'     => $vincolo,
            'operatori'   => $this->operatori->listWithCategoria(soloAttivi: true),
            'tipiVincolo' => VincoloValidator::TIPI,
            'action'      => "/vincoli/{$id}",
            'titolo'      => 'Modifica vincolo',
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->param('id');
        $vincolo = $this->model->find($id);
        if ($vincolo === null) {
            return $this->redirect('/vincoli', 'error', 'Vincolo non trovato.');
        }

        $input = $this->collectInput($request);
        $validation = (new VincoloValidator())->validate($input);
        if (!$validation['ok']) {
            return $this->redirectWithErrors("/vincoli/{$id}/edit", $validation['errors'], $input);
        }

        $err = $this->verificaRiferimenti($validation['data']);
        if ($err !== []) {
            return $this->redirectWithErrors("/vincoli/{$id}/edit", $err, $input);
        }

        // `creato_da` non viene toccato in update: resta l'autore originale.
        $this->model->update($id, $validation['data']);

        Logger::get()->info('Vincolo aggiornato', [
            'id'      => $id,
            'user_id' => $this->currentUserId(),
        ]);
        return $this->redirect('/vincoli', 'success', 'Vincolo aggiornato.');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('id');
        $vincolo = $this->model->find($id);
        if ($vincolo === null) {
            return $this->redirect('/vincoli', 'error', 'Vincolo non trovato.');
        }
        $this->model->delete($id);
        Logger::get()->info('Vincolo eliminato', [
            'id'      => $id,
            'user_id' => $this->currentUserId(),
        ]);
        return $this->redirect('/vincoli', 'success', 'Vincolo eliminato.');
    }

    /** @return array<string,mixed> */
    private function collectInput(Request $request): array
    {
        return [
            'id_operatore' => $request->post('id_operatore'),
            'tipo_vincolo' => $request->post('tipo_vincolo'),
            'attivo'       => $request->post('attivo'),
            'data_inizio'  => $request->post('data_inizio'),
            'data_fine'    => $request->post('data_fine'),
            'note'         => $request->post('note'),
        ];
    }

    /**
     * Verifica che id_operatore punti a un record esistente.
     * Il set chiuso di `tipo_vincolo` e' gia' validato applicativamente dal
     * VincoloValidator (non c'e' una tabella di riferimento da consultare).
     *
     * @param array<string,mixed> $data
     * @return array<string,list<string>>
     */
    private function verificaRiferimenti(array $data): array
    {
        $errors = [];
        if ($this->operatori->find((int) $data['id_operatore']) === null) {
            $errors['id_operatore'][] = 'Operatore non trovato.';
        }
        return $errors;
    }
}
