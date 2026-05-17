<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Logger;
use App\Models\CategoriaOperatoreModel;
use App\Models\OperatoreModel;
use App\Models\SettingModel;
use App\Routing\Request;
use App\Routing\Response;
use App\Validators\OperatoreValidator;
use PDOException;

final class OperatoriController extends BaseController
{
    private OperatoreModel $model;
    private CategoriaOperatoreModel $categorie;
    private SettingModel $settings;

    public function __construct()
    {
        parent::__construct();
        $this->model = new OperatoreModel();
        $this->categorie = new CategoriaOperatoreModel();
        $this->settings = new SettingModel();
    }

    public function index(Request $request): Response
    {
        $mostraInattivi = (string) $request->query('inattivi', '0') === '1';
        $settingFiltroRaw = (string) $request->query('setting', '');
        $idSettingFiltro = $this->risolviSettingFiltro($settingFiltroRaw);

        return $this->render('operatori/index.twig', [
            'operatori'       => $this->model->listWithCategoria(
                soloAttivi: !$mostraInattivi,
                idSetting:  $idSettingFiltro,
            ),
            'mostraInattivi'  => $mostraInattivi,
            'settings'        => $this->settings->listAttivi(),
            'settingFiltro'   => $settingFiltroRaw,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('operatori/form.twig', [
            'operatore' => null,
            'categorie' => $this->categorie->listOrdered(),
            'settings'  => $this->settings->listAttivi(),
            'action'    => '/operatori',
            'titolo'    => 'Nuovo operatore',
        ]);
    }

    public function store(Request $request): Response
    {
        $input = $this->collectInput($request);
        $categorie = $this->categorie->listOrdered();
        $settings = $this->settings->listAttivi();
        $validation = $this->validate($input, $categorie, $settings);

        if (!$validation['ok']) {
            return $this->redirectWithErrors('/operatori/create', $validation['errors'], $input);
        }

        $id = $this->model->create($validation['data']);
        Logger::get()->info('Operatore creato', ['id' => $id, 'user_id' => $this->currentUserId()]);
        return $this->redirect('/operatori', 'success', 'Operatore creato.');
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->param('id');
        $operatore = $this->model->find($id);
        if ($operatore === null) {
            return $this->redirect('/operatori', 'error', 'Operatore non trovato.');
        }
        return $this->render('operatori/form.twig', [
            'operatore' => $operatore,
            'categorie' => $this->categorie->listOrdered(),
            'settings'  => $this->settings->listAttivi(),
            'action'    => "/operatori/{$id}",
            'titolo'    => 'Modifica operatore',
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->param('id');
        $operatore = $this->model->find($id);
        if ($operatore === null) {
            return $this->redirect('/operatori', 'error', 'Operatore non trovato.');
        }

        $input = $this->collectInput($request);
        $categorie = $this->categorie->listOrdered();
        $settings = $this->settings->listAttivi();
        $validation = $this->validate($input, $categorie, $settings);

        if (!$validation['ok']) {
            return $this->redirectWithErrors("/operatori/{$id}/edit", $validation['errors'], $input);
        }

        $this->model->update($id, $validation['data']);
        Logger::get()->info('Operatore aggiornato', ['id' => $id, 'user_id' => $this->currentUserId()]);
        return $this->redirect('/operatori', 'success', 'Operatore aggiornato.');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('id');
        $operatore = $this->model->find($id);
        if ($operatore === null) {
            return $this->redirect('/operatori', 'error', 'Operatore non trovato.');
        }

        try {
            $this->model->delete($id);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirect(
                    '/operatori',
                    'error',
                    'Impossibile eliminare: l\'operatore ha turni o assenze registrati. Usa "Disattiva" per nasconderlo dalle liste mantenendo lo storico.',
                );
            }
            throw $e;
        }

        Logger::get()->info('Operatore eliminato', ['id' => $id, 'user_id' => $this->currentUserId()]);
        return $this->redirect('/operatori', 'success', 'Operatore eliminato.');
    }

    /**
     * Toggle attivo/non attivo: alternativa al delete quando ci sono dati storici.
     */
    public function toggleAttivo(Request $request): Response
    {
        $id = (int) $request->param('id');
        $operatore = $this->model->find($id);
        if ($operatore === null) {
            return $this->redirect('/operatori', 'error', 'Operatore non trovato.');
        }
        $nuovoStato = ((int) $operatore['attivo']) === 1 ? 0 : 1;
        $this->model->update($id, ['attivo' => $nuovoStato]);
        Logger::get()->info('Operatore stato attivo cambiato', [
            'id' => $id, 'attivo' => $nuovoStato, 'user_id' => $this->currentUserId(),
        ]);
        return $this->redirect(
            '/operatori',
            'success',
            $nuovoStato === 1 ? 'Operatore riattivato.' : 'Operatore disattivato.',
        );
    }

    /** @return array<string,mixed> */
    private function collectInput(Request $request): array
    {
        return [
            'nome'                     => $request->post('nome'),
            'cognome'                  => $request->post('cognome'),
            'id_categoria'             => $request->post('id_categoria'),
            'id_setting'               => $request->post('id_setting'),
            'ore_contrattuali_mensili' => $request->post('ore_contrattuali_mensili'),
            'data_assunzione'          => $request->post('data_assunzione'),
            'data_cessazione'          => $request->post('data_cessazione'),
            'email'                    => $request->post('email'),
            'telefono'                 => $request->post('telefono'),
            'note'                     => $request->post('note'),
            'attivo'                   => $request->post('attivo'),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param list<array<string,mixed>> $categorie
     * @param list<array<string,mixed>> $settings
     * @return array{ok:bool,data:array<string,mixed>,errors:array<string,list<string>>}
     */
    private function validate(array $input, array $categorie, array $settings): array
    {
        $idsCat = array_map(static fn ($c) => (int) $c['id'], $categorie);
        $idsSet = array_map(static fn ($s) => (int) $s['id'], $settings);
        return (new OperatoreValidator($idsCat, $idsSet))->validate($input);
    }

    /**
     * Converte un codice setting (es. "hospice") in id, oppure ritorna null
     * se non valido / non passato. Usato per il filtro index.
     */
    private function risolviSettingFiltro(string $codice): ?int
    {
        if ($codice === '') {
            return null;
        }
        $s = $this->settings->findByCodice($codice);
        return $s !== null ? (int) $s['id'] : null;
    }
}
