<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Container;
use App\Helpers\Database;
use App\Helpers\Logger;
use App\Models\AssenzaModel;
use App\Models\OperatoreModel;
use App\Models\SaldoOreModel;
use App\Models\SettingModel;
use App\Models\TipoTurnoModel;
use App\Models\TurnoModel;
use App\Routing\Request;
use App\Routing\Response;
use App\Services\SaldoRicalcoloService;
use App\Validators\AssenzaValidator;

final class AssenzeController extends BaseController
{
    private AssenzaModel $model;
    private OperatoreModel $operatori;
    private TipoTurnoModel $tipi;
    private SettingModel $settings;
    private TurnoModel $turni;
    private SaldoRicalcoloService $ricalcolo;
    private Database $db;

    public function __construct()
    {
        parent::__construct();
        $this->model = new AssenzaModel();
        $this->operatori = new OperatoreModel();
        $this->tipi = new TipoTurnoModel();
        $this->settings = new SettingModel();
        $this->turni = new TurnoModel();
        $this->ricalcolo = new SaldoRicalcoloService(new SaldoOreModel(), $this->turni);
        $this->db = Container::instance()->get(Database::class);
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

        // Crea l'assenza e svuota i turni che cadono nel suo periodo (cross-setting),
        // ricalcolando i saldi dei mesi toccati. Tutto in una transazione: se il
        // ricalcolo fallisce, l'assenza non resta orfana.
        $esito = $this->db->transaction(function () use ($data): array {
            $id = $this->model->create($data);
            $sync = $this->svuotaTurniERicalcola(
                (int) $data['id_operatore'],
                (string) $data['data_inizio'],
                (string) $data['data_fine'],
            );
            return ['id' => $id] + $sync;
        });

        Logger::get()->info('Assenza creata', [
            'id' => $esito['id'],
            'id_operatore'  => $data['id_operatore'],
            'id_tipo_turno' => $data['id_tipo_turno'],
            'periodo'       => $data['data_inizio'] . '..' . $data['data_fine'],
            'turni_rimossi' => $esito['turni'],
            'piani_pubblicati_toccati' => $esito['pubblicati'],
            'user_id'       => $this->currentUserId(),
        ]);
        [$tipo, $msg] = $this->messaggioEsito('Assenza registrata.', $esito);
        return $this->redirect('/assenze', $tipo, $msg);
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

        $data = $validation['data'];
        $vecchioOp     = (int) $assenza['id_operatore'];
        $vecchioInizio = (string) $assenza['data_inizio'];
        $vecchioFine   = (string) $assenza['data_fine'];
        $nuovoOp       = (int) $data['id_operatore'];

        // `creato_da` non viene toccato in update: resta l'autore originale.
        $esito = $this->db->transaction(function () use ($id, $data, $vecchioOp, $vecchioInizio, $vecchioFine, $nuovoOp): array {
            $this->model->update($id, $data);
            $mesiVecchi = $this->mesiNelPeriodo($vecchioInizio, $vecchioFine);
            // Svuota i turni nel NUOVO periodo e ricalcola; includi i mesi del
            // VECCHIO periodo (se l'op è lo stesso) per togliere le ore dove
            // l'assenza non c'è più.
            $sync = $this->svuotaTurniERicalcola(
                $nuovoOp,
                (string) $data['data_inizio'],
                (string) $data['data_fine'],
                $nuovoOp === $vecchioOp ? $mesiVecchi : [],
            );
            // Assenza spostata su un altro operatore: il vecchio non ce l'ha più,
            // ricalcoliamo i suoi vecchi mesi.
            if ($nuovoOp !== $vecchioOp) {
                foreach ($mesiVecchi as $m) {
                    $this->ricalcolo->ricalcola($vecchioOp, $m['anno'], $m['mese']);
                }
            }
            return $sync;
        });

        Logger::get()->info('Assenza aggiornata', [
            'id' => $id,
            'turni_rimossi' => $esito['turni'],
            'piani_pubblicati_toccati' => $esito['pubblicati'],
            'user_id' => $this->currentUserId(),
        ]);
        [$tipo, $msg] = $this->messaggioEsito('Assenza aggiornata.', $esito);
        return $this->redirect('/assenze', $tipo, $msg);
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('id');
        $assenza = $this->model->find($id);
        if ($assenza === null) {
            return $this->redirect('/assenze', 'error', 'Assenza non trovata.');
        }
        $esito = $this->db->transaction(function () use ($id, $assenza): array {
            $this->model->delete($id);
            // I turni del periodo erano già stati svuotati alla creazione e NON
            // si ripristinano. Qui ricalcoliamo i mesi coperti per togliere le
            // ore-assenza dal saldo (svuotaTurniERicalcola troverà 0 turni).
            return $this->svuotaTurniERicalcola(
                (int) $assenza['id_operatore'],
                (string) $assenza['data_inizio'],
                (string) $assenza['data_fine'],
            );
        });
        Logger::get()->info('Assenza eliminata', [
            'id' => $id,
            'piani_pubblicati_toccati' => $esito['pubblicati'],
            'user_id' => $this->currentUserId(),
        ]);
        [$tipo, $msg] = $this->messaggioEsito('Assenza eliminata.', $esito);
        return $this->redirect('/assenze', $tipo, $msg);
    }

    /**
     * Svuota i turni dell'operatore che cadono nel periodo [inizio, fine] su
     * TUTTI i piani (cross-setting: l'UNIQUE op-data è globale) e ricalcola i
     * saldi dei mesi toccati: quelli con turni rimossi, quelli coperti dal
     * periodo, più eventuali $mesiExtra. Da chiamare DENTRO una transazione,
     * DOPO aver scritto/aggiornato/eliminato l'assenza (così SchemaOreService
     * vede lo stato finale). Le celle svuotate restano vuote: l'overlay del
     * calendario mostra il codice dell'assenza. I mesi si ricalcolano in ordine
     * crescente perché la propagazione del progressivo va in avanti.
     *
     * @param list<array{anno:int,mese:int}> $mesiExtra
     * @return array{turni:int, pubblicati:list<string>}
     */
    private function svuotaTurniERicalcola(int $idOp, string $inizio, string $fine, array $mesiExtra = []): array
    {
        $ids = [];
        $mesi = [];
        $pubblicati = [];
        foreach ($this->turni->listByOperatoreInPeriodo($idOp, $inizio, $fine) as $t) {
            $ids[] = (int) $t['id'];
            $mesi[(int) $t['anno'] . '-' . (int) $t['mese']] = ['anno' => (int) $t['anno'], 'mese' => (int) $t['mese']];
            if ((string) $t['stato'] === 'pubblicato') {
                $pubblicati[sprintf('%s %02d/%d', $t['setting_nome'], (int) $t['mese'], (int) $t['anno'])] = true;
            }
        }

        if ($ids !== []) {
            $this->turni->deleteByIds($ids);
        }

        foreach ($this->mesiNelPeriodo($inizio, $fine) as $m) {
            $mesi[$m['anno'] . '-' . $m['mese']] = $m;
        }
        foreach ($mesiExtra as $m) {
            $mesi[$m['anno'] . '-' . $m['mese']] = $m;
        }

        $mesiList = array_values($mesi);
        usort($mesiList, static fn ($a, $b) => [$a['anno'], $a['mese']] <=> [$b['anno'], $b['mese']]);
        foreach ($mesiList as $m) {
            $this->ricalcolo->ricalcola($idOp, $m['anno'], $m['mese']);
        }

        return ['turni' => count($ids), 'pubblicati' => array_keys($pubblicati)];
    }

    /**
     * I mesi (anno, mese) toccati da un periodo [inizio, fine], estremi inclusi.
     *
     * @return list<array{anno:int,mese:int}>
     */
    private function mesiNelPeriodo(string $inizio, string $fine): array
    {
        $cur = (new \DateTimeImmutable($inizio))->modify('first day of this month');
        $end = new \DateTimeImmutable($fine);
        $out = [];
        while ($cur <= $end) {
            $out[] = ['anno' => (int) $cur->format('Y'), 'mese' => (int) $cur->format('n')];
            $cur = $cur->modify('+1 month');
        }
        return $out;
    }

    /**
     * [tipoFlash, messaggio] dall'esito dello svuotamento. Se sono stati toccati
     * piani PUBBLICATI lo segnala con un alert giallo (warning), così la
     * coordinatrice sa che l'assenza ha modificato anche un piano già condiviso.
     *
     * @param array{turni:int, pubblicati:list<string>} $esito
     * @return array{0:string, 1:string}
     */
    private function messaggioEsito(string $base, array $esito): array
    {
        $turniMsg = $esito['turni'] > 0
            ? sprintf(' Rimossi %d turni in conflitto.', $esito['turni'])
            : '';
        if ($esito['pubblicati'] === []) {
            return ['success', $base . $turniMsg];
        }
        return ['warning', sprintf(
            '%s%s Attenzione: ho aggiornato anche piani PUBBLICATI (%s).',
            $base,
            $turniMsg,
            implode(', ', $esito['pubblicati']),
        )];
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
