<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Container;
use App\Helpers\Database;
use App\Helpers\Logger;
use App\Models\AssenzaModel;
use App\Models\OperatoreModel;
use App\Models\PianoOperatoreModel;
use App\Models\PianoTurnoModel;
use App\Models\SaldoOreModel;
use App\Models\SchemaPassoModel;
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
 * - L'operatore deve essere incluso nel piano: deve esistere una riga in
 *   `piano_operatori` per (id_piano, id_operatore). Comprende sia gli operatori
 *   inclusi automaticamente alla creazione (di casa nel setting, in servizio nel
 *   mese) sia gli aggiunti in itinere — anche cross-setting (sessione 4-ter).
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
    private PianoOperatoreModel $pianoOperatori;
    private AssenzaModel $assenze;
    private SchemaPassoModel $passi;
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
        $this->pianoOperatori = new PianoOperatoreModel();
        $this->assenze = new AssenzaModel();
        $this->passi = new SchemaPassoModel();
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
        // Se la data e' fuori dal periodo di servizio dell'operatore mostriamo
        // un alert nel form. Il blocco vero arriva al submit (vedi store +
        // validateRiferimenti); qui informiamo l'utente prima che ci provi.
        $fuoriFinestra = $operatore !== null
            ? $this->messaggioFuoriFinestra($operatore, $dataTurno)
            : null;
        // Sessione 5: se la data cade dentro un'assenza programmata dell'op
        // calcoliamo il messaggio e lo passiamo alla view. Per i turni
        // esistenti (sia di lavoro sia di tipo assenza coincidente) il
        // submit è bloccato lato server (vedi `update`): l'unica azione
        // lecita è l'eliminazione. La view differenzia il tono dell'alert
        // tra "conflitto" (turno lavoro) e "ridondanza" (turno assenza
        // coincidente) — visivamente diverso, comportamento server identico.
        $turnoIsAssenza = $turnoEsistente !== null && (int) ($turnoEsistente['tipo_is_assenza'] ?? 0) === 1;
        $inAssenza = $this->messaggioAssenza($idOperatore, $dataTurno);
        $assenzaRidondante = $inAssenza !== null && $turnoIsAssenza;

        return $this->render('turni/form.twig', [
            'piano'             => $piano,
            'operatore'         => $operatore,
            'data'              => $dataTurno,
            'turno'             => $turnoEsistente,
            'tipi'              => $this->tipi->listSoloLavoro((int) $piano['id_setting']),
            'vincoli'           => $vincoli,
            'fuoriFinestra'     => $fuoriFinestra,
            'inAssenza'         => $inAssenza,
            'assenzaRidondante' => $assenzaRidondante,
            'labelMese'         => $this->labelMese((int) $piano['mese'], (int) $piano['anno']),
            'labelData'         => $this->labelData($dataTurno),
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
                    'ore_effettive' => $this->oreEffettivePerTurno((int) $data['id_tipo_turno'], (string) $data['data']),
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

        // Sessione 5 (iterazione 2026-05-21): le assenze vincono sempre.
        // Su un turno esistente che cade in un periodo di assenza l'unica
        // azione lecita è l'eliminazione (destroy resta permesso). Per
        // modificarlo, la coordinatrice deve prima correggere l'assenza.
        $msgAssenza = $this->messaggioAssenza((int) $turno['id_operatore'], (string) $turno['data']);
        if ($msgAssenza !== null) {
            return $this->redirect(
                "/piani-turno/{$idPiano}",
                'error',
                'Modifica non consentita: ' . $msgAssenza . ' Per modificare il tipo turno, restringi prima il periodo dell\'assenza; per rimuovere il turno usa Elimina.',
            );
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

        $tipo = $this->tipi->find((int) $data['id_tipo_turno']);
        if ($tipo === null || !$this->tipoAssegnabileNelPiano($tipo, $piano)) {
            return $this->redirectAlFormEdit(
                $idPiano,
                (int) $turno['id_operatore'],
                (string) $turno['data'],
                $input,
                ['id_tipo_turno' => ['Questo tipo turno non è assegnabile in questo piano (assenza, tipo ritirato o di un altro setting).']],
            );
        }

        $this->db->transaction(function () use ($idTurno, $turno, $piano, $data): void {
            $this->turni->update($idTurno, [
                'id_tipo_turno' => (int) $data['id_tipo_turno'],
                'ore_effettive' => $this->oreEffettivePerTurno((int) $data['id_tipo_turno'], (string) $turno['data']),
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
     * la data cade nel mese, l'operatore è incluso nel piano.
     * Ritorna null se ok, altrimenti il messaggio di errore.
     *
     * Dalla 4-ter l'appartenenza è tracciata in `piano_operatori`: include
     * sia gli inclusi automaticamente dalla create che gli aggiunti in itinere
     * (anche cross-setting).
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
        if ($this->operatori->find($idOperatore) === null) {
            return 'Operatore non trovato.';
        }
        if (!$this->pianoOperatori->isInPiano((int) $piano['id'], $idOperatore)) {
            return 'L\'operatore non è incluso in questo piano. Aggiungilo dal piano (azione «+ Aggiungi operatore»).';
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

        $operatore = $this->operatori->find($idOperatore);
        if ($operatore === null) {
            $errors['id_operatore'][] = 'Operatore non trovato.';
        } elseif (!$this->pianoOperatori->isInPiano((int) $piano['id'], $idOperatore)) {
            $errors['id_operatore'][] = 'L\'operatore non è incluso in questo piano.';
        } else {
            $fuoriFinestra = $this->messaggioFuoriFinestra($operatore, $dataTurno);
            if ($fuoriFinestra !== null) {
                $errors['data'][] = $fuoriFinestra;
            } else {
                // Sessione 5: le assenze programmate vincono sempre su un
                // nuovo turno. Su turni esistenti (update/destroy) non passa
                // di qui: lì il caller è update/destroy che NON chiamano
                // validateRiferimenti, quindi non c'è rischio di bloccare
                // un cleanup retroattivo.
                $msgAssenza = $this->messaggioAssenza($idOperatore, $dataTurno);
                if ($msgAssenza !== null) {
                    $errors['data'][] = $msgAssenza;
                }
            }
        }

        $tipo = $this->tipi->find($idTipoTurno);
        if ($tipo === null) {
            $errors['id_tipo_turno'][] = 'Tipo turno non valido.';
        } elseif (!$this->tipoAssegnabileNelPiano($tipo, $piano)) {
            $errors['id_tipo_turno'][] = 'Questo tipo turno non è assegnabile in questo piano (assenza, tipo ritirato o di un altro setting).';
        }

        return $errors;
    }

    /**
     * Un tipo turno è assegnabile da "assegna turno" in questo piano se è
     * attivo, NON è un'assenza (quelle si gestiscono da /assenze) e appartiene
     * al setting del piano oppure è condiviso (`id_setting` NULL). Difesa in
     * profondità: il form mostra già solo i tipi giusti (listSoloLavoro), ma un
     * submit via URL manipolato deve essere rifiutato comunque.
     *
     * @param array<string,mixed> $tipo
     * @param array<string,mixed> $piano
     */
    private function tipoAssegnabileNelPiano(array $tipo, array $piano): bool
    {
        if ((int) $tipo['attivo'] !== 1) {
            return false;
        }
        $isAssenza = (int) $tipo['is_ferie'] === 1
            || (int) $tipo['is_permesso'] === 1
            || (int) $tipo['is_malattia'] === 1
            || (int) $tipo['esclude_pianificazione'] === 1;
        if ($isAssenza) {
            return false;
        }
        if ($tipo['id_setting'] === null) {
            return true; // condiviso (R, Rec, Corso)
        }
        return (int) $tipo['id_setting'] === (int) $piano['id_setting'];
    }

    /**
     * Ore effettive da scrivere su un turno assegnato a mano, per i tipi le cui
     * ore variano per giorno-settimana (UCP-DOM: UI venerdì 6h, UO sabato
     * 4,25h). Allinea l'assegnazione manuale al generatore, che già scrive
     * `ore_effettive` dal passo dello schema. Ritorna null per i tipi a ore
     * costanti (M/P/N/G…): in quel caso il conteggio usa `ore_conteggiate`.
     */
    private function oreEffettivePerTurno(int $idTipoTurno, string $data): ?float
    {
        $dow = (int) (new \DateTimeImmutable($data))->format('N') - 1; // 0=lun..6=dom
        return $this->passi->oreLavorateSettimanale($idTipoTurno, $dow);
    }

    /**
     * Ritorna un messaggio user-friendly se la data del turno cade fuori dal
     * periodo di servizio dell'operatore (prima di data_assunzione o dopo
     * data_cessazione). Null se la data e' in finestra o se le date sono
     * entrambe NULL.
     *
     * Confronto su stringa `Y-m-d`: lessicograficamente coincide col confronto
     * cronologico, quindi nessun bisogno di DateTime qui.
     *
     * @param array<string,mixed> $operatore
     */
    private function messaggioFuoriFinestra(array $operatore, string $dataTurno): ?string
    {
        $dataAss = $operatore['data_assunzione'] ?? null;
        $dataCess = $operatore['data_cessazione'] ?? null;

        if ($dataAss !== null && $dataAss !== '' && $dataTurno < (string) $dataAss) {
            return sprintf(
                'Operatore %s %s assunto solo dal %s: non si possono assegnare turni prima di questa data.',
                (string) $operatore['cognome'],
                (string) $operatore['nome'],
                $this->labelData((string) $dataAss),
            );
        }
        if ($dataCess !== null && $dataCess !== '' && $dataTurno > (string) $dataCess) {
            return sprintf(
                'Operatore %s %s cessato il %s: non si possono assegnare turni dopo questa data.',
                (string) $operatore['cognome'],
                (string) $operatore['nome'],
                $this->labelData((string) $dataCess),
            );
        }
        return null;
    }

    /**
     * Ritorna un messaggio user-friendly se la data del turno cade dentro
     * un'assenza programmata dell'operatore (ferie, permessi, malattia,
     * maternità, ecc.). Null altrimenti.
     *
     * Le assenze vincono sempre sul nuovo turno (sessione 5): il messaggio
     * indica il periodo bloccato e suggerisce la rimediazione.
     */
    private function messaggioAssenza(int $idOperatore, string $dataTurno): ?string
    {
        $a = $this->assenze->findAttivaPerOperatoreData($idOperatore, $dataTurno);
        if ($a === null) {
            return null;
        }
        return sprintf(
            'L\'operatore è in assenza dal %s al %s (%s %s). Modifica il periodo di assenza se è sbagliato, o scegli un altro giorno.',
            $this->labelData($a['data_inizio']),
            $this->labelData($a['data_fine']),
            $a['tipo_codice'],
            $a['tipo_descrizione'],
        );
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
