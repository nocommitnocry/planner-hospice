<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Container;
use App\Helpers\Database;
use App\Helpers\Logger;
use App\Models\OperatoreModel;
use App\Models\PianoOperatoreModel;
use App\Models\PianoTurnoModel;
use App\Models\SaldoModificaModel;
use App\Models\SaldoOreModel;
use App\Routing\Request;
use App\Routing\Response;
use App\Services\SaldoRicalcoloService;
use App\Validators\SaldoValidator;
use PDOException;

/**
 * Mutazioni manuali sui saldi (sessione 4-ter).
 *
 * Tre azioni utente:
 *  1. Aggiungere al piano un operatore non incluso alla creazione (assunto infra-mese,
 *     in itinere dall'altro setting, doppio ruolo): scelta operatore, ore_dovute,
 *     saldo_progressivo iniziale, nota obbligatoria.
 *  2. Modificare il saldo di un operatore già nel piano (ore_dovute per
 *     pro-rata di cessazione o recuperi, saldo_progressivo come "reset di
 *     verità" agganciato al cedolino): nota obbligatoria.
 *  3. Rimuovere un operatore dal piano (solo se non ha turni assegnati nel
 *     piano). Vale sia per gli inclusi alla creazione che per gli aggiunti
 *     in itinere (4-quater: il flag `aggiunto_manualmente` resta come
 *     informazione storica nel log, non è più gate di rimovibilità). Caso
 *     d'uso tipico: dimissione infra-mese di un operatore "di casa".
 *
 * Sicurezza:
 *  - Tutte le mutazioni richiedono admin o caposala (a livello di route).
 *  - Tutte le mutazioni esigono che il piano sia in stato `bozza` (consistente
 *    con TurniController). Per modificare un piano pubblicato si fa prima
 *    unpublish.
 *  - Ogni mutazione genera una riga in `saldo_modifiche` con la nota
 *    obbligatoria e i valori prima/dopo (audit trail).
 *
 * Note di design:
 *  - Quando si modifica `ore_dovute` viene rilanciato il ricalcolo del saldo
 *    (saldo_mese e propagazione del progressivo): le ore lavorate non cambiano
 *    ma cambia ore_dovute, quindi saldo_mese e progressivo vanno rifatti.
 *  - Quando si modifica `saldo_progressivo` (reset di verità), il valore va
 *    salvato così com'è e POI la propagazione ricostruisce solo i progressivi
 *    dei mesi successivi a partire dal nuovo valore.
 *  - Quando si aggiunge un operatore al piano, se manca il record saldo per
 *    (op, anno, mese) lo creiamo con i valori passati; se esiste già (caso:
 *    è anche in piano dell'altro setting) ne preserviamo i valori correnti
 *    e ignoriamo l'input ore_dovute/saldo_progressivo iniziale: il saldo è
 *    unico cross-setting e non vogliamo sovrascriverlo dall'altro piano.
 */
final class SaldiController extends BaseController
{
    private PianoTurnoModel $piani;
    private PianoOperatoreModel $pianoOperatori;
    private SaldoOreModel $saldi;
    private SaldoModificaModel $modifiche;
    private OperatoreModel $operatori;
    private SaldoRicalcoloService $ricalcolo;
    private Database $db;

    public function __construct()
    {
        parent::__construct();
        $this->piani = new PianoTurnoModel();
        $this->pianoOperatori = new PianoOperatoreModel();
        $this->saldi = new SaldoOreModel();
        $this->modifiche = new SaldoModificaModel();
        $this->operatori = new OperatoreModel();
        $this->ricalcolo = new SaldoRicalcoloService($this->saldi, new \App\Models\TurnoModel());
        $this->db = Container::instance()->get(Database::class);
    }

    // -------------------------------------------------------------------------
    // Aggiunta operatore in itinere
    // -------------------------------------------------------------------------

    public function addOperatoreForm(Request $request): Response
    {
        $idPiano = (int) $request->param('id');
        $piano = $this->piani->findWithSetting($idPiano);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }
        if ($piano['stato'] !== 'bozza') {
            return $this->redirect("/piani-turno/{$idPiano}", 'error', 'Solo i piani in bozza sono modificabili.');
        }
        $candidati = $this->operatori->findCandidatiAggiunta(
            $idPiano,
            (int) $piano['anno'],
            (int) $piano['mese'],
        );
        return $this->render('saldi/add_operatore.twig', [
            'piano'      => $piano,
            'candidati'  => $candidati,
            'labelMese'  => $this->labelMese((int) $piano['mese'], (int) $piano['anno']),
        ]);
    }

    public function addOperatore(Request $request): Response
    {
        $idPiano = (int) $request->param('id');
        $piano = $this->piani->find($idPiano);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }
        if ($piano['stato'] !== 'bozza') {
            return $this->redirect("/piani-turno/{$idPiano}", 'error', 'Solo i piani in bozza sono modificabili.');
        }
        $anno = (int) $piano['anno'];
        $mese = (int) $piano['mese'];

        $candidati = $this->operatori->findCandidatiAggiunta($idPiano, $anno, $mese);
        $idsCandidati = array_map(static fn ($o) => (int) $o['id'], $candidati);

        $input = [
            'id_operatore'      => $request->post('id_operatore'),
            'ore_dovute'        => $request->post('ore_dovute'),
            'saldo_progressivo' => $request->post('saldo_progressivo'),
            'note'              => $request->post('note'),
        ];

        $validation = (new SaldoValidator())->validateAggiunta($input, $idsCandidati);
        if (!$validation['ok']) {
            return $this->redirectWithErrors(
                "/piani-turno/{$idPiano}/aggiungi-operatore",
                $validation['errors'],
                $input,
            );
        }

        $idOp = (int) $validation['data']['id_operatore'];
        $oreDovuteNuove = (float) $validation['data']['ore_dovute'];
        $progNuovo = (float) $validation['data']['saldo_progressivo'];
        $note = (string) $validation['data']['note'];
        $userId = $this->currentUserId();

        try {
            $this->db->transaction(function () use ($idPiano, $idOp, $anno, $mese, $oreDovuteNuove, $progNuovo, $note, $userId): void {
                // Se esiste già il saldo (l'op è in piano nell'altro setting),
                // NON sovrascriviamo: il saldo è unico cross-setting.
                $saldo = $this->saldi->findOneBy([
                    'id_operatore' => $idOp,
                    'anno'         => $anno,
                    'mese'         => $mese,
                ]);
                if ($saldo === null) {
                    $idSaldo = $this->saldi->create([
                        'id_operatore'      => $idOp,
                        'anno'              => $anno,
                        'mese'              => $mese,
                        'ore_dovute'        => number_format($oreDovuteNuove, 2, '.', ''),
                        'ore_lavorate'      => '0.00',
                        'ore_ferie'         => '0.00',
                        'ore_permessi'      => '0.00',
                        'ore_malattia'      => '0.00',
                        'ore_formazione'    => '0.00',
                        'saldo_mese'        => number_format(-$oreDovuteNuove, 2, '.', ''),
                        'saldo_progressivo' => number_format($progNuovo, 2, '.', ''),
                    ]);
                } else {
                    $idSaldo = (int) $saldo['id'];
                }

                $this->pianoOperatori->create([
                    'id_piano'             => $idPiano,
                    'id_operatore'         => $idOp,
                    'aggiunto_manualmente' => 1,
                    'aggiunto_da'          => $userId,
                    'note_aggiunta'        => $note,
                ]);

                $this->modifiche->create([
                    'id_saldo'          => $idSaldo,
                    'id_utente'         => $userId,
                    'tipo_modifica'     => 'aggiunta_operatore',
                    'valore_precedente' => null,
                    'valore_nuovo'      => number_format($oreDovuteNuove, 2, '.', ''),
                    'note'              => $note,
                ]);
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirectWithErrors(
                    "/piani-turno/{$idPiano}/aggiungi-operatore",
                    ['id_operatore' => ['L\'operatore risulta già nel piano (race condition).']],
                    $input,
                );
            }
            throw $e;
        }

        Logger::get()->info('Operatore aggiunto al piano (4-ter)', [
            'piano'     => $idPiano,
            'operatore' => $idOp,
            'user_id'   => $userId,
        ]);
        return $this->redirect("/piani-turno/{$idPiano}", 'success', 'Operatore aggiunto al piano.');
    }

    // -------------------------------------------------------------------------
    // Modifica saldo esistente
    // -------------------------------------------------------------------------

    public function editForm(Request $request): Response
    {
        $idPiano = (int) $request->param('id');
        $idSaldo = (int) $request->param('sid');

        [$piano, $saldo, $err] = $this->loadPianoSaldoBozza($idPiano, $idSaldo);
        if ($err !== null) {
            return $err;
        }
        $operatore = $this->operatori->find((int) $saldo['id_operatore']);

        return $this->render('saldi/edit.twig', [
            'piano'      => $piano,
            'saldo'      => $saldo,
            'operatore'  => $operatore,
            'storico'    => $this->modifiche->listBySaldo($idSaldo),
            'labelMese'  => $this->labelMese((int) $piano['mese'], (int) $piano['anno']),
        ]);
    }

    public function update(Request $request): Response
    {
        $idPiano = (int) $request->param('id');
        $idSaldo = (int) $request->param('sid');

        [$piano, $saldo, $err] = $this->loadPianoSaldoBozza($idPiano, $idSaldo);
        if ($err !== null) {
            return $err;
        }

        $input = [
            'ore_dovute'        => $request->post('ore_dovute'),
            'saldo_progressivo' => $request->post('saldo_progressivo'),
            'note'              => $request->post('note'),
        ];

        $validation = (new SaldoValidator())->validateModifica($input);
        if (!$validation['ok']) {
            return $this->redirectWithErrors(
                "/piani-turno/{$idPiano}/saldi/{$idSaldo}/edit",
                $validation['errors'],
                $input,
            );
        }

        $data = $validation['data'];
        $note = (string) $data['note'];
        $userId = $this->currentUserId();

        $oreDovutePrev = (string) $saldo['ore_dovute'];
        $progPrev = (string) $saldo['saldo_progressivo'];
        $cambiaOreDovute = isset($data['ore_dovute']) && (string) $data['ore_dovute'] !== $oreDovutePrev;
        $cambiaProg = isset($data['saldo_progressivo']) && (string) $data['saldo_progressivo'] !== $progPrev;

        if (!$cambiaOreDovute && !$cambiaProg) {
            return $this->redirectWithErrors(
                "/piani-turno/{$idPiano}/saldi/{$idSaldo}/edit",
                ['ore_dovute' => ['Nessuna modifica rispetto ai valori attuali.']],
                $input,
            );
        }

        try {
            $this->db->transaction(function () use ($idSaldo, $saldo, $data, $note, $userId, $cambiaOreDovute, $cambiaProg, $oreDovutePrev, $progPrev): void {
                $idOp = (int) $saldo['id_operatore'];
                $anno = (int) $saldo['anno'];
                $mese = (int) $saldo['mese'];

                if ($cambiaOreDovute) {
                    $this->saldi->update($idSaldo, ['ore_dovute' => (string) $data['ore_dovute']]);
                    $this->modifiche->create([
                        'id_saldo'          => $idSaldo,
                        'id_utente'         => $userId,
                        'tipo_modifica'     => 'ore_dovute',
                        'valore_precedente' => $oreDovutePrev,
                        'valore_nuovo'      => (string) $data['ore_dovute'],
                        'note'              => $note,
                    ]);
                    // Ricalcolo saldo_mese del mese corrente dai turni effettivi.
                    // NON propaga: la propagazione finale unica avviene sotto.
                    $progressivoCorrente = $this->ricalcolo->ricalcolaMese($idOp, $anno, $mese);
                } else {
                    $progressivoCorrente = (float) $saldo['saldo_progressivo'];
                }

                if ($cambiaProg) {
                    // Reset di verità del progressivo (cedolino). Sovrascrive
                    // l'eventuale valore appena calcolato da ricalcolaMese.
                    $this->saldi->update($idSaldo, ['saldo_progressivo' => (string) $data['saldo_progressivo']]);
                    $this->modifiche->create([
                        'id_saldo'          => $idSaldo,
                        'id_utente'         => $userId,
                        'tipo_modifica'     => 'saldo_progressivo',
                        'valore_precedente' => $progPrev,
                        'valore_nuovo'      => (string) $data['saldo_progressivo'],
                        'note'              => $note,
                    ]);
                    $progressivoCorrente = (float) $data['saldo_progressivo'];
                }

                // Propagazione unica a fine transazione con il valore "vincitore":
                // manuale se presente, calcolato altrimenti. Se cambiano entrambi,
                // ricalcolaMese ha già scritto saldo_mese coerente con le ore_dovute
                // nuove e il progressivo manuale ha priorità.
                if ($progressivoCorrente !== null) {
                    $this->ricalcolo->propagaDaQui($idOp, $anno, $mese, $progressivoCorrente);
                }
            });
        } catch (PDOException $e) {
            Logger::get()->error('Modifica saldo fallita', [
                'saldo'   => $idSaldo,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        Logger::get()->info('Saldo modificato manualmente (4-ter)', [
            'piano'             => $idPiano,
            'saldo'             => $idSaldo,
            'cambia_ore_dovute' => $cambiaOreDovute,
            'cambia_progressivo'=> $cambiaProg,
            'user_id'           => $userId,
        ]);
        return $this->redirect("/piani-turno/{$idPiano}", 'success', 'Saldo aggiornato.');
    }

    // -------------------------------------------------------------------------
    // Rimozione di un operatore dal piano (gate unico: zero turni)
    // -------------------------------------------------------------------------

    public function removeOperatore(Request $request): Response
    {
        $idPiano = (int) $request->param('id');
        $idOp = (int) $request->param('opid');

        $piano = $this->piani->find($idPiano);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }
        if ($piano['stato'] !== 'bozza') {
            return $this->redirect("/piani-turno/{$idPiano}", 'error', 'Solo i piani in bozza sono modificabili.');
        }

        $appartenenza = $this->pianoOperatori->findInPiano($idPiano, $idOp);
        if ($appartenenza === null) {
            return $this->redirect("/piani-turno/{$idPiano}", 'error', 'Operatore non presente in questo piano.');
        }
        if ($this->pianoOperatori->countTurniOperatoreInPiano($idPiano, $idOp) > 0) {
            return $this->redirect(
                "/piani-turno/{$idPiano}",
                'error',
                'Rimuovi prima i turni assegnati a questo operatore in questo piano.',
            );
        }

        $anno = (int) $piano['anno'];
        $mese = (int) $piano['mese'];

        $idAppartenenza = (int) $appartenenza['id'];
        $this->db->transaction(function () use ($idAppartenenza, $idPiano, $idOp, $anno, $mese): void {
            // 1. Leggiamo PRIMA chi è in altri piani del mese (ordine simmetrico
            //    a PianiTurnoController::destroy).
            $opInAltri = $this->pianoOperatori->listOperatoriInAltriPianiDelMese($idPiano, $anno, $mese);
            // 2. Rimuoviamo l'appartenenza al piano corrente.
            $this->pianoOperatori->delete($idAppartenenza);
            // 3. Se il saldo non serve a nessun altro piano del mese, lo cancelliamo
            //    e ricalcoliamo la catena dei progressivi futuri.
            $this->ricalcolo->rimuoviSaldoSeOrfano($idOp, $anno, $mese, $opInAltri);
        });

        Logger::get()->info('Operatore rimosso dal piano (4-quater)', [
            'piano'                => $idPiano,
            'operatore'            => $idOp,
            'aggiunto_manualmente' => (int) $appartenenza['aggiunto_manualmente'],
            'user_id'              => $this->currentUserId(),
        ]);
        return $this->redirect("/piani-turno/{$idPiano}", 'success', 'Operatore rimosso dal piano.');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0:?array<string,mixed>, 1:?array<string,mixed>, 2:?Response}
     */
    private function loadPianoSaldoBozza(int $idPiano, int $idSaldo): array
    {
        $piano = $this->piani->find($idPiano);
        if ($piano === null) {
            return [null, null, $this->redirect('/piani-turno', 'error', 'Piano non trovato.')];
        }
        if ($piano['stato'] !== 'bozza') {
            return [null, null, $this->redirect("/piani-turno/{$idPiano}", 'error', 'Solo i piani in bozza sono modificabili.')];
        }
        $saldo = $this->saldi->find($idSaldo);
        if ($saldo === null) {
            return [null, null, $this->redirect("/piani-turno/{$idPiano}", 'error', 'Saldo non trovato.')];
        }
        if ((int) $saldo['anno'] !== (int) $piano['anno'] || (int) $saldo['mese'] !== (int) $piano['mese']) {
            return [null, null, $this->redirect("/piani-turno/{$idPiano}", 'error', 'Il saldo non appartiene al mese del piano.')];
        }
        if (!$this->pianoOperatori->isInPiano($idPiano, (int) $saldo['id_operatore'])) {
            return [null, null, $this->redirect("/piani-turno/{$idPiano}", 'error', 'L\'operatore di questo saldo non è incluso nel piano.')];
        }
        return [$piano, $saldo, null];
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
}
