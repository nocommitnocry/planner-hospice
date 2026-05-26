<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Logger;
use App\Models\CategoriaOperatoreModel;
use App\Routing\Request;
use App\Routing\Response;
use App\Validators\CategoriaOperatoreValidator;
use PDOException;

/**
 * CRUD categorie operatori.
 *
 * Pattern (replicato per le altre anagrafiche della sessione 2):
 *  - index    → GET   /categorie-operatori
 *  - create   → GET   /categorie-operatori/create
 *  - store    → POST  /categorie-operatori
 *  - edit     → GET   /categorie-operatori/{id}/edit
 *  - update   → POST  /categorie-operatori/{id}
 *  - destroy  → POST  /categorie-operatori/{id}/delete
 *
 * Solo admin (impostato a livello di route nel router).
 */
final class CategorieOperatoriController extends BaseController
{
    private CategoriaOperatoreModel $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new CategoriaOperatoreModel();
    }

    public function index(Request $request): Response
    {
        return $this->render('categorie_operatori/index.twig', [
            'categorie'   => $this->model->listOrdered(),
            'gruppiLabel' => CategoriaOperatoreModel::GRUPPI_LABEL,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('categorie_operatori/form.twig', [
            'categoria' => null,
            'action'    => '/categorie-operatori',
            'titolo'    => 'Nuova categoria',
            'gruppi'    => CategoriaOperatoreModel::GRUPPI_LABEL,
        ]);
    }

    public function store(Request $request): Response
    {
        $input = [
            'nome'                   => $request->post('nome'),
            'descrizione'            => $request->post('descrizione'),
            'gruppo_pianificazione'  => $request->post('gruppo_pianificazione'),
            'ordine_visualizzazione' => $request->post('ordine_visualizzazione'),
        ];

        $validation = (new CategoriaOperatoreValidator())->validate($input);
        if (!$validation['ok']) {
            return $this->redirectWithErrors('/categorie-operatori/create', $validation['errors'], $input);
        }

        if ($this->model->existsByName($validation['data']['nome'])) {
            return $this->redirectWithErrors(
                '/categorie-operatori/create',
                ['nome' => ['Esiste già una categoria con questo nome.']],
                $input,
            );
        }

        $id = $this->model->create($validation['data']);
        Logger::get()->info('Categoria operatore creata', ['id' => $id, 'user_id' => $this->currentUserId()]);
        return $this->redirect('/categorie-operatori', 'success', 'Categoria creata.');
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->param('id');
        $categoria = $this->model->find($id);
        if ($categoria === null) {
            return $this->redirect('/categorie-operatori', 'error', 'Categoria non trovata.');
        }
        return $this->render('categorie_operatori/form.twig', [
            'categoria' => $categoria,
            'action'    => "/categorie-operatori/{$id}",
            'titolo'    => 'Modifica categoria',
            'gruppi'    => CategoriaOperatoreModel::GRUPPI_LABEL,
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->param('id');
        $categoria = $this->model->find($id);
        if ($categoria === null) {
            return $this->redirect('/categorie-operatori', 'error', 'Categoria non trovata.');
        }

        $input = [
            'nome'                   => $request->post('nome'),
            'descrizione'            => $request->post('descrizione'),
            'gruppo_pianificazione'  => $request->post('gruppo_pianificazione'),
            'ordine_visualizzazione' => $request->post('ordine_visualizzazione'),
        ];

        $validation = (new CategoriaOperatoreValidator())->validate($input);
        if (!$validation['ok']) {
            return $this->redirectWithErrors("/categorie-operatori/{$id}/edit", $validation['errors'], $input);
        }

        if ($this->model->existsByName($validation['data']['nome'], excludeId: $id)) {
            return $this->redirectWithErrors(
                "/categorie-operatori/{$id}/edit",
                ['nome' => ['Esiste già una categoria con questo nome.']],
                $input,
            );
        }

        $this->model->update($id, $validation['data']);
        Logger::get()->info('Categoria operatore aggiornata', ['id' => $id, 'user_id' => $this->currentUserId()]);
        return $this->redirect('/categorie-operatori', 'success', 'Categoria aggiornata.');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('id');
        $categoria = $this->model->find($id);
        if ($categoria === null) {
            return $this->redirect('/categorie-operatori', 'error', 'Categoria non trovata.');
        }

        try {
            $this->model->delete($id);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirect(
                    '/categorie-operatori',
                    'error',
                    'Impossibile eliminare: la categoria è assegnata a uno o più operatori.',
                );
            }
            throw $e;
        }

        Logger::get()->info('Categoria operatore eliminata', ['id' => $id, 'user_id' => $this->currentUserId()]);
        return $this->redirect('/categorie-operatori', 'success', 'Categoria eliminata.');
    }
}
