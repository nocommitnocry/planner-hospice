<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Container;
use App\Helpers\Database;
use App\Helpers\Logger;
use App\Models\OperatoreModel;
use App\Models\PianoTurnoModel;
use App\Models\SaldoOreModel;
use App\Models\TipoTurnoModel;
use App\Models\TurnoModel;
use App\Routing\Request;
use App\Routing\Response;
use App\Services\SaldoRicalcoloService;
use App\Validators\TurnoValidator;
use PDOException;

/**
 * Assegnazione turni nel calendario di un piano.
 *
 * Regole:
 * - Tutte le mutazioni richiedono che il piano sia in stato 'bozza'. Per
 *   modificare un piano pubblicato l'utente lo riporta prima in bozza
 *   (vedi PianiTurnoController::unpublish).
 * - L'operatore deve essere uno di quelli "fotografati" alla creazione del
 *   piano: in pratica deve esistere un record `saldo_ore` per (operatore,
 *   anno_piano, mese_piano). Così non si introducono nel piano operatori
 *   aggiunti dopo, senza saldo iniziale.
 * - La data del turno deve cadere nel mese del piano.
 * - Operatore + data sono univoci a livello DB (UNIQUE su `turni`). In edit
 *   forniamo solo modifica di tipo turno e note: per cambiare giorno o
 *   operatore l'utente elimina e ricrea.
 * - Ogni insert/update/delete è in transazione con il ricalcolo del saldo
 *   dell'operatore e la propagazione del saldo_progressivo ai mesi successivi.
 */
final class TurniController extends BaseController
{
    private PianoTurnoModel $piani;
    private TurnoModel $turni;
    private TipoTurnoModel $tipi;
    private OperatoreModel $operatori;
    private SaldoOreModel $saldi;
    private SaldoRicalcoloService $ricalcolo;
    private Database $db;

    public function __construct()
    {
        parent::__construct();
        $this->piani = new PianoTurnoModel();
        $this->turni = new TurnoModel();
        $this->tipi = new TipoTurnoModel();
        $this->operatori = new OperatoreModel();
        $this->saldi = new SaldoOreModel();
        $this->ricalcolo = new SaldoRicalcoloService($this->saldi, $this->turni);
        $this->db = Container::instance()->get(Database::class);
    }

    /**
     * Form di assegnazione/modifica turno per (operatore, data) di un piano.
     * Se esiste già un turno mostra i campi compilati e abilita l'elimina;
     * altrimenti mostra il form di nuova assegnazione.
     */
    public function edit(Request $request): Response
    {
        $idPiano = (int) $request->param('id');
        $piano = $this->piani->find($idPiano);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }
        if ($piano['stato'] !== 'bozza') {
            return $this->redirect(
                "/piani-turno/{$idPiano}",
                'error',
                'Solo i piani in bozza sono modificabili. Riporta il piano in bozza per assegnare turni.',
            );
        }

        $idOperatore = (int) ($request->query('operatore') ?? 0);
        $dataTurno = (string) ($request->query('data') ?? '');

        $errCtx = $this->validateContestoOperatoreData($piano, $idOperatore, $dataTurno);
        if ($errCtx !== null) {
            return $this->redirect("/piani-turno/{$idPiano}", 'error', $errCtx);
        }

        $operatore = $this->operatori->find($idOperatore);
        $turnoEsistente = $this->turni->findInPianoByOperatoreData($idPiano, $idOperatore, $dataTurno);
        $vincoli = $this->vincoliAttiviPerOperatore($idOperatore, $dataTurno);

        return $this->render('turni/form.twig', [
            'piano'         => $piano,
            'operatore'     => $operatore,
            'data'          => $dataTurno,
            'turno'         => $turnoEsistente,
            'tipi'          => $this->tipi->listOrdered(),
            'vincoli'       => $vincoli,
            'labelMese'     => $this->labelMese((int) $piano['mese'], (int) $piano['anno']),
            'labelData'     => $this->labelData($dataTurno),
        ]);
    }

    public function store(Request $request): Response
    {
        $idPiano = (int) $request->param('id');
        $piano = $this->piani->find($idPiano);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }
        if ($piano['stato'] !== 'bozza') {
            return $this->redirect("/piani-turno/{$idPiano}", 'error', 'Piano non modificabile in questo stato.');
        }

        $input = [
            'id_operatore'  => $request->post('id_operatore'),
            'id_tipo_turno' => $request->post('id_tipo_turno'),
            'data'          => $request->post('data'),
            'note'          => $request->post('note'),
        ];

        $validation = (new TurnoValidator())->validate($input);
        if (!$validation['ok']) {
            return $this->redirectAlForm($idPiano, $input, $validation['errors']);
        }

        $data = $validation['data'];
        $errors = $this->validateRiferimenti($piano, (int) $data['id_operatore'], (int) $data['id_tipo_turno'], (string) $data['data']);
        if ($errors !== []) {
            return $this->redirectAlForm($idPiano, $input, $errors);
        }

        $duplicato = $this->turni->findInPianoByOperatoreData(
            $idPiano,
            (int) $data['id_operatore'],
            (string) $data['data'],
        );
        if ($duplicato !== null) {
            return $this->redirectAlForm(
                $idPiano,
                $input,
                ['data' => ['Esiste già un turno per questo operatore in questa data. Modificalo o eliminalo.']],
            );
        }

        try {
            $this->db->transaction(function () use ($idPiano, $piano, $data): void {
                $this->turni->create([
                    'id_piano'      => $idPiano,
                    'id_operatore'  => (int) $data['id_operatore'],
                    'data'          => (string) $data['data'],
                    'id_tipo_turno' => (int) $data['id_tipo_turno'],
                    'note'          => $data['note'],
                ]);
                $this->ricalcolo->ricalcola(
                    (int) $data['id_operatore'],
                    (int) $piano['anno'],
                    (int) $piano['mese'],
                );
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirectAlForm(
                    $idPiano,
                    $input,
                    ['data' => ['Esiste già un turno per questo operatore in questa data.']],
                );
            }
            throw $e;
        }

        Logger::get()->info('Turno creato', [
            'piano'     => $idPiano,
            'operatore' => $data['id_operatore'],
            'data'      => $data['data'],
            'tipo'      => $data['id_tipo_turno'],
            'user_id'   => $this->currentUserId(),
        ]);
        return $this->redirect("/piani-turno/{$idPiano}", 'success', 'Turno assegnato.');
    }

    public function update(Request $request): Response
    {
        $idPiano = (int) $request->param('id');
        $idTurno = (int) $request->param('tid');

        $piano = $this->piani->find($idPiano);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }
        if ($piano['stato'] !== 'bozza') {
            return $this->redirect("/piani-turno/{$idPiano}", 'error', 'Piano non modificabile in questo stato.');
        }

        $turno = $this->turni->find($idTurno);
        if ($turno === null || (int) $turno['id_piano'] !== $idPiano) {
            return $this->redirect("/piani-turno/{$idPiano}", 'error', 'Turno non trovato in questo piano.');
        }

        // In update si modificano solo tipo turno e note. Operatore e data
        // rimangono quelli del turno esistente: per spostarlo si elimina e
        // si ricrea sulla nuova cella.
        $input = [
            'id_operatore'  => (int) $turno['id_operatore'],
            'id_tipo_turno' => $request->post('id_tipo_turno'),
            'data'          => (string) $turno['data'],
            'note'          => $request->post('note'),
        ];

        $validation = (new TurnoValidator())->validate($input);
        if (!$validation['ok']) {
            return $this->redirectAlFormEdit($idPiano, (int) $turno['id_operatore'], (string) $turno['data'], $input, $validation['errors']);
        }
        $data = $validation['data'];

        if ($this->tipi->find((int) $data['id_tipo_turno']) === null) {
            return $this->redirectAlFormEdit(
                $idPiano,
                (int) $turno['id_operatore'],
                (string) $turno['data'],
                $input,
                ['id_tipo_turno' => ['Tipo turno non valido.']],
            );
        }

        $this->db->transaction(function () use ($idTurno, $turno, $piano, $data): void {
            $this->turni->update($idTurno, [
                'id_tipo_turno' => (int) $data['id_tipo_turno'],
                'note'          => $data['note'],
            ]);
            $this->ricalcolo->ricalcola(
                (int) $turno['id_operatore'],
                (int) $piano['anno'],
                (int) $piano['mese'],
            );
        });

        Logger::get()->info('Turno aggiornato', [
            'piano'   => $idPiano,
            'turno'   => $idTurno,
            'tipo'    => $data['id_tipo_turno'],
            'user_id' => $this->currentUserId(),
        ]);
        return $this->redirect("/piani-turno/{$idPiano}", 'success', 'Turno aggiornato.');
    }

    public function destroy(Request $request): Response
    {
        $idPiano = (int) $request->param('id');
        $idTurno = (int) $request->param('tid');

        $piano = $this->piani->find($idPiano);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }
        if ($piano['stato'] !== 'bozza') {
            return $this->redirect("/piani-turno/{$idPiano}", 'error', 'Piano non modificabile in questo stato.');
        }

        $turno = $this->turni->find($idTurno);
        if ($turno === null || (int) $turno['id_piano'] !== $idPiano) {
            return $this->redirect("/piani-turno/{$idPiano}", 'error', 'Turno non trovato in questo piano.');
        }

        $this->db->transaction(function () use ($idTurno, $turno, $piano): void {
            $this->turni->delete($idTurno);
            $this->ricalcolo->ricalcola(
                (int) $turno['id_operatore'],
                (int) $piano['anno'],
                (int) $piano['mese'],
            );
        });

        Logger::get()->info('Turno eliminato', [
            'piano'   => $idPiano,
            'turno'   => $idTurno,
            'user_id' => $this->currentUserId(),
        ]);
        return $this->redirect("/piani-turno/{$idPiano}", 'success', 'Turno rimosso.');
    }

    /**
     * Verifica che (operatore, data) siano coerenti col piano: esistono,
     * la data cade nel mese, l'operatore ha un saldo iniziale per il piano.
     * Ritorna null se ok, altrimenti il messaggio di errore.
     *
     * @param array<string,mixed> $piano
     */
    private function validateContestoOperatoreData(array $piano, int $idOperatore, string $dataTurno): ?string
    {
        if ($idOperatore <= 0 || $dataTurno === '') {
            return 'Operatore o data mancanti.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataTurno)) {
            return 'Data non valida.';
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $dataTurno);
        if ($dt === false || $dt->format('Y-m-d') !== $dataTurno) {
            return 'Data non valida.';
        }
        if ((int) $dt->format('Y') !== (int) $piano['anno'] || (int) $dt->format('n') !== (int) $piano['mese']) {
            return 'La data non appartiene al mese del piano.';
        }
        $op = $this->operatori->find($idOperatore);
        if ($op === null) {
            return 'Operatore non trovato.';
        }
        if ((int) $op['id_setting'] !== (int) $piano['id_setting']) {
            // Cross-setting non ancora gestito: il flusso "aggiungi operatore al piano"
            // arriverà nella sessione 4-ter.
            return 'L\'operatore non appartiene al setting di questo piano.';
        }
        $saldoOp = $this->saldi->findOneBy([
            'id_operatore' => $idOperatore,
            'anno'         => (int) $piano['anno'],
            'mese'         => (int) $piano['mese'],
        ]);
        if ($saldoOp === null) {
            return 'L\'operatore non risulta nel piano (nessun saldo iniziale).';
        }
        return null;
    }

    /**
     * @param array<string,mixed> $piano
     * @return array<string,list<string>>
     */
    private function validateRiferimenti(array $piano, int $idOperatore, int $idTipoTurno, string $dataTurno): array
    {
        $errors = [];

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $dataTurno);
        if ($dt === false
            || (int) $dt->format('Y') !== (int) $piano['anno']
            || (int) $dt->format('n') !== (int) $piano['mese']
        ) {
            $errors['data'][] = 'La data non appartiene al mese del piano.';
        }

        $op = $this->operatori->find($idOperatore);
        if ($op === null) {
            $errors['id_operatore'][] = 'Operatore non trovato.';
        } elseif ((int) $op['id_setting'] !== (int) $piano['id_setting']) {
            $errors['id_operatore'][] = 'L\'operatore non appartiene al setting di questo piano.';
        } else {
            $saldoOp = $this->saldi->findOneBy([
                'id_operatore' => $idOperatore,
                'anno'         => (int) $piano['anno'],
                'mese'         => (int) $piano['mese'],
            ]);
            if ($saldoOp === null) {
                $errors['id_operatore'][] = 'L\'operatore non è incluso in questo piano.';
            }
        }

        if ($this->tipi->find($idTipoTurno) === null) {
            $errors['id_tipo_turno'][] = 'Tipo turno non valido.';
        }

        return $errors;
    }

    /**
     * Vincoli attivi per un operatore alla data indicata.
     *
     * @return list<array<string,mixed>>
     */
    private function vincoliAttiviPerOperatore(int $idOperatore, string $dataTurno): array
    {
        // Con PDO::ATTR_EMULATE_PREPARES=false non si può riusare lo stesso named
        // placeholder due volte: per "data" servono due binding distinti.
        return $this->db->query(
            "SELECT tipo_vincolo, data_inizio, data_fine, note
             FROM operatori_vincoli
             WHERE id_operatore = :id_op
               AND attivo = 1
               AND (data_inizio IS NULL OR data_inizio <= :data_lo)
               AND (data_fine   IS NULL OR data_fine   >= :data_hi)
             ORDER BY tipo_vincolo ASC",
            ['id_op' => $idOperatore, 'data_lo' => $dataTurno, 'data_hi' => $dataTurno],
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,list<string>> $errors
     */
    private function redirectAlForm(int $idPiano, array $input, array $errors): Response
    {
        $idOp = (int) ($input['id_operatore'] ?? 0);
        $data = (string) ($input['data'] ?? '');
        return $this->redirectAlFormEdit($idPiano, $idOp, $data, $input, $errors);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,list<string>> $errors
     */
    private function redirectAlFormEdit(int $idPiano, int $idOperatore, string $dataTurno, array $input, array $errors): Response
    {
        $qs = http_build_query(['operatore' => $idOperatore, 'data' => $dataTurno]);
        return $this->redirectWithErrors(
            "/piani-turno/{$idPiano}/turni/edit?{$qs}",
            $errors,
            $input,
        );
    }

    private function labelMese(int $mese, int $anno): string
    {
        $mesi = [
            1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
            5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
            9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
        ];
        return ($mesi[$mese] ?? (string) $mese) . ' ' . $anno;
    }

    private function labelData(string $data): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $data);
        if ($dt === false) {
            return $data;
        }
        $giorni = ['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'];
        $dow = (int) $dt->format('N');
        return $giorni[$dow - 1] . ' ' . $dt->format('d/m/Y');
    }
}
