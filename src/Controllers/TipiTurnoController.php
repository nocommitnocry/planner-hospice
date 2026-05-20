<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Logger;
use App\Models\TipoTurnoModel;
use App\Routing\Request;
use App\Routing\Response;
use App\Validators\TipoTurnoValidator;
use PDOException;

final class TipiTurnoController extends BaseController
{
    private TipoTurnoModel $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new TipoTurnoModel();
    }

    public function index(Request $request): Response
    {
        return $this->render('tipi_turno/index.twig', [
            'tipi' => $this->model->listOrdered(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('tipi_turno/form.twig', [
            'tipo'   => null,
            'action' => '/tipi-turno',
            'titolo' => 'Nuovo tipo turno',
        ]);
    }

    public function store(Request $request): Response
    {
        $input = $this->collectInput($request);
        $validation = (new TipoTurnoValidator())->validate($input);
        if (!$validation['ok']) {
            return $this->redirectWithErrors('/tipi-turno/create', $validation['errors'], $input);
        }
        if ($this->model->existsByCodice($validation['data']['codice'])) {
            return $this->redirectWithErrors(
                '/tipi-turno/create',
                ['codice' => ['Esiste già un tipo turno con questo codice.']],
                $input,
            );
        }

        $id = $this->model->create($validation['data']);
        Logger::get()->info('Tipo turno creato', ['id' => $id, 'user_id' => $this->currentUserId()]);
        return $this->redirect('/tipi-turno', 'success', 'Tipo turno creato.');
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->param('id');
        $tipo = $this->model->find($id);
        if ($tipo === null) {
            return $this->redirect('/tipi-turno', 'error', 'Tipo turno non trovato.');
        }
        return $this->render('tipi_turno/form.twig', [
            'tipo'   => $tipo,
            'action' => "/tipi-turno/{$id}",
            'titolo' => 'Modifica tipo turno',
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->param('id');
        $tipo = $this->model->find($id);
        if ($tipo === null) {
            return $this->redirect('/tipi-turno', 'error', 'Tipo turno non trovato.');
        }

        $input = $this->collectInput($request);
        $validation = (new TipoTurnoValidator())->validate($input);
        if (!$validation['ok']) {
            return $this->redirectWithErrors("/tipi-turno/{$id}/edit", $validation['errors'], $input);
        }
        if ($this->model->existsByCodice($validation['data']['codice'], excludeId: $id)) {
            return $this->redirectWithErrors(
                "/tipi-turno/{$id}/edit",
                ['codice' => ['Esiste già un tipo turno con questo codice.']],
                $input,
            );
        }

        $this->model->update($id, $validation['data']);
        Logger::get()->info('Tipo turno aggiornato', ['id' => $id, 'user_id' => $this->currentUserId()]);
        return $this->redirect('/tipi-turno', 'success', 'Tipo turno aggiornato.');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('id');
        $tipo = $this->model->find($id);
        if ($tipo === null) {
            return $this->redirect('/tipi-turno', 'error', 'Tipo turno non trovato.');
        }

        try {
            $this->model->delete($id);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirect(
                    '/tipi-turno',
                    'error',
                    'Impossibile eliminare: il tipo turno è già usato in turni o assenze.',
                );
            }
            throw $e;
        }

        Logger::get()->info('Tipo turno eliminato', ['id' => $id, 'user_id' => $this->currentUserId()]);
        return $this->redirect('/tipi-turno', 'success', 'Tipo turno eliminato.');
    }

    /** @return array<string,mixed> */
    private function collectInput(Request $request): array
    {
        return [
            'codice'          => $request->post('codice'),
            'descrizione'     => $request->post('descrizione'),
            'ora_inizio'      => $request->post('ora_inizio'),
            'ora_fine'        => $request->post('ora_fine'),
            'colore'          => $request->post('colore'),
            'ore_conteggiate' => $request->post('ore_conteggiate'),
            'priorita'        => $request->post('priorita'),
            'is_riposo'              => $request->post('is_riposo'),
            'is_ferie'               => $request->post('is_ferie'),
            'is_permesso'            => $request->post('is_permesso'),
            'is_malattia'            => $request->post('is_malattia'),
            'is_formazione'          => $request->post('is_formazione'),
            'esclude_pianificazione' => $request->post('esclude_pianificazione'),
        ];
    }
}
