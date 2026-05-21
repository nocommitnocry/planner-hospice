<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Logger;
use App\Models\AssenzaModel;
use App\Models\OperatoreModel;
use App\Models\SettingModel;
use App\Models\TipoTurnoModel;
use App\Routing\Request;
use App\Routing\Response;
use App\Validators\AssenzaValidator;

final class AssenzeController extends BaseController
{
    private AssenzaModel $model;
    private OperatoreModel $operatori;
    private TipoTurnoModel $tipi;
    private SettingModel $settings;

    public function __construct()
    {
        parent::__construct();
        $this->model = new AssenzaModel();
        $this->operatori = new OperatoreModel();
        $this->tipi = new TipoTurnoModel();
        $this->settings = new SettingModel();
    }

    public function index(Request $request): Response
    {
        $settingFiltroRaw = (string) $request->query('setting', '');
        $idSettingFiltro = $this->risolviSettingFiltro($settingFiltroRaw);

        return $this->render('assenze/index.twig', [
            'assenze'         => $this->model->listJoined(idSetting: $idSettingFiltro),
            'settings'        => $this->settings->listAttivi(),
            'settingFiltro'   => $settingFiltroRaw,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('assenze/form.twig', [
            'assenza'   => null,
            'operatori' => $this->operatori->listWithCategoria(soloAttivi: true),
            'tipi'      => $this->tipi->listSoloAssenze(),
            'action'    => '/assenze',
            'titolo'    => 'Nuova assenza',
        ]);
    }

    public function store(Request $request): Response
    {
        $input = $this->collectInput($request);
        $validation = (new AssenzaValidator())->validate($input);
        if (!$validation['ok']) {
            return $this->redirectWithErrors('/assenze/create', $validation['errors'], $input);
        }

        $err = $this->verificaRiferimenti($validation['data']);
        if ($err !== []) {
            return $this->redirectWithErrors('/assenze/create', $err, $input);
        }

        $data = $validation['data'];
        $data['creato_da'] = $this->currentUserId();
        $id = $this->model->create($data);

        Logger::get()->info('Assenza creata', [
            'id' => $id,
            'id_operatore'  => $data['id_operatore'],
            'id_tipo_turno' => $data['id_tipo_turno'],
            'periodo'       => $data['data_inizio'] . '..' . $data['data_fine'],
            'user_id'       => $this->currentUserId(),
        ]);
        return $this->redirect('/assenze', 'success', 'Assenza registrata.');
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->param('id');
        $assenza = $this->model->find($id);
        if ($assenza === null) {
            return $this->redirect('/assenze', 'error', 'Assenza non trovata.');
        }
        return $this->render('assenze/form.twig', [
            'assenza'   => $assenza,
            'operatori' => $this->operatori->listWithCategoria(soloAttivi: true),
            'tipi'      => $this->tipi->listSoloAssenze(),
            'action'    => "/assenze/{$id}",
            'titolo'    => 'Modifica assenza',
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->param('id');
        $assenza = $this->model->find($id);
        if ($assenza === null) {
            return $this->redirect('/assenze', 'error', 'Assenza non trovata.');
        }

        $input = $this->collectInput($request);
        $validation = (new AssenzaValidator())->validate($input);
        if (!$validation['ok']) {
            return $this->redirectWithErrors("/assenze/{$id}/edit", $validation['errors'], $input);
        }

        $err = $this->verificaRiferimenti($validation['data']);
        if ($err !== []) {
            return $this->redirectWithErrors("/assenze/{$id}/edit", $err, $input);
        }

        // `creato_da` non viene toccato in update: resta l'autore originale.
        $this->model->update($id, $validation['data']);

        Logger::get()->info('Assenza aggiornata', [
            'id' => $id,
            'user_id' => $this->currentUserId(),
        ]);
        return $this->redirect('/assenze', 'success', 'Assenza aggiornata.');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('id');
        $assenza = $this->model->find($id);
        if ($assenza === null) {
            return $this->redirect('/assenze', 'error', 'Assenza non trovata.');
        }
        $this->model->delete($id);
        Logger::get()->info('Assenza eliminata', [
            'id' => $id,
            'user_id' => $this->currentUserId(),
        ]);
        return $this->redirect('/assenze', 'success', 'Assenza eliminata.');
    }

    /** @return array<string,mixed> */
    private function collectInput(Request $request): array
    {
        return [
            'id_operatore'  => $request->post('id_operatore'),
            'id_tipo_turno' => $request->post('id_tipo_turno'),
            'data_inizio'   => $request->post('data_inizio'),
            'data_fine'     => $request->post('data_fine'),
            'note'          => $request->post('note'),
        ];
    }

    /**
     * Verifica che id_operatore e id_tipo_turno puntino a record esistenti
     * e che il tipo turno sia effettivamente un'assenza (almeno uno tra
     * is_ferie / is_permesso / is_malattia / esclude_pianificazione).
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
        $tipo = $this->tipi->find((int) $data['id_tipo_turno']);
        if ($tipo === null) {
            $errors['id_tipo_turno'][] = 'Tipo di assenza non trovato.';
        } elseif (!((int) $tipo['is_ferie'] === 1
                || (int) $tipo['is_permesso'] === 1
                || (int) $tipo['is_malattia'] === 1
                || (int) $tipo['esclude_pianificazione'] === 1)) {
            $errors['id_tipo_turno'][] = 'Il tipo turno selezionato non è un\'assenza (occorre almeno uno tra ferie, permesso, malattia o "esclude pianificazione").';
        }
        return $errors;
    }

    private function risolviSettingFiltro(string $codice): ?int
    {
        if ($codice === '') {
            return null;
        }
        $s = $this->settings->findByCodice($codice);
        return $s !== null ? (int) $s['id'] : null;
    }
}
