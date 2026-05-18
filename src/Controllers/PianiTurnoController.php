<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Container;
use App\Helpers\Database;
use App\Helpers\Logger;
use App\Models\OperatoreModel;
use App\Models\PianoOperatoreModel;
use App\Models\PianoTurnoModel;
use App\Models\SaldoOreModel;
use App\Models\SettingModel;
use App\Models\TurnoModel;
use App\Routing\Request;
use App\Routing\Response;
use App\Services\SaldoRicalcoloService;
use App\Validators\PianoTurnoValidator;
use PDOException;

/**
 * Gestione piani turno mensili.
 *
 * Stati del piano: bozza → pubblicato → archiviato.
 * - bozza:      modificabile, eliminabile se senza turni.
 * - pubblicato: visibile a tutti come piano corrente; può tornare in bozza per correzioni.
 * - archiviato: storico, sola lettura, irreversibile.
 *
 * Sicurezza:
 * - Index/show accessibili a tutti gli utenti autenticati (visualizzatore incluso).
 * - Create/store/destroy/transizioni di stato: admin + caposala (a livello di route).
 * - Eliminazione consentita solo su piani in bozza senza turni associati.
 *
 * Transazione store: l'inserimento del piano e dei saldi_ore iniziali per ogni
 * operatore attivo viene fatto in un'unica transazione DB.
 */
final class PianiTurnoController extends BaseController
{
    private PianoTurnoModel $piani;
    private SaldoOreModel $saldi;
    private OperatoreModel $operatori;
    private TurnoModel $turni;
    private SettingModel $settings;
    private PianoOperatoreModel $pianoOperatori;
    private SaldoRicalcoloService $ricalcolo;
    private Database $db;

    private const MESI_IT = [
        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
        5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
        9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->piani = new PianoTurnoModel();
        $this->saldi = new SaldoOreModel();
        $this->operatori = new OperatoreModel();
        $this->turni = new TurnoModel();
        $this->settings = new SettingModel();
        $this->pianoOperatori = new PianoOperatoreModel();
        $this->ricalcolo = new SaldoRicalcoloService($this->saldi, $this->turni);
        $this->db = Container::instance()->get(Database::class);
    }

    public function index(Request $request): Response
    {
        $stato = (string) $request->query('stato', '');
        $statoFiltro = in_array($stato, PianoTurnoModel::STATI, true) ? $stato : null;

        $settingFiltroRaw = (string) $request->query('setting', '');
        $idSettingFiltro = $this->risolviSettingFiltro($settingFiltroRaw);

        return $this->render('piani_turno/index.twig', [
            'piani'         => $this->piani->listOrdered($statoFiltro, $idSettingFiltro),
            'statoFiltro'   => $statoFiltro,
            'stati'         => PianoTurnoModel::STATI,
            'mesi'          => self::MESI_IT,
            'settings'      => $this->settings->listAttivi(),
            'settingFiltro' => $settingFiltroRaw,
        ]);
    }

    public function create(Request $request): Response
    {
        $oggi = new \DateTimeImmutable('today');
        $settings = $this->settings->listAttivi();
        $settingDefault = $this->settingDefaultUtente($settings);

        return $this->render('piani_turno/form.twig', [
            'piano'           => null,
            'mesi'            => self::MESI_IT,
            'annoCorrente'    => (int) $oggi->format('Y'),
            'meseCorrente'    => (int) $oggi->format('n'),
            'settings'        => $settings,
            'settingDefault'  => $settingDefault,
        ]);
    }

    public function store(Request $request): Response
    {
        $input = [
            'anno'       => $request->post('anno'),
            'mese'       => $request->post('mese'),
            'id_setting' => $request->post('id_setting'),
            'nome'       => $request->post('nome'),
        ];

        $settings = $this->settings->listAttivi();
        $idsSet = array_map(static fn ($s) => (int) $s['id'], $settings);

        $validation = (new PianoTurnoValidator($idsSet))->validate($input);
        if (!$validation['ok']) {
            return $this->redirectWithErrors('/piani-turno/create', $validation['errors'], $input);
        }

        $anno = (int) $validation['data']['anno'];
        $mese = (int) $validation['data']['mese'];
        $idSetting = (int) $validation['data']['id_setting'];
        $settingNome = $this->nomeSetting($idSetting, $settings);

        if ($this->piani->findByAnnoMeseSetting($anno, $mese, $idSetting) !== null) {
            return $this->redirectWithErrors(
                '/piani-turno/create',
                ['anno' => ["Esiste già un piano «{$settingNome}» per {$this->labelMese($mese, $anno)}."]],
                $input,
            );
        }

        // Fotografa-operatori: attivi, di casa nel setting del piano,
        // assunti entro l'ultimo del mese, non cessati prima del primo.
        // Le date sono informative (vedi 4-ter): cessati post-mese restano,
        // le ore residue vanno regolate a mano dal saldo del piano.
        $operatoriDelPiano = $this->operatori->findInServizioNelMese($anno, $mese, $idSetting);
        if ($operatoriDelPiano === []) {
            return $this->redirect(
                '/piani-turno',
                'error',
                "Impossibile creare il piano «{$settingNome}»: non ci sono operatori attivi in servizio per {$this->labelMese($mese, $anno)} in questo setting.",
            );
        }

        $userId = $this->currentUserId();

        try {
            $idPiano = $this->db->transaction(function () use ($validation, $anno, $mese, $idSetting, $operatoriDelPiano, $userId): int {
                $idPiano = $this->piani->create([
                    'anno'       => $anno,
                    'mese'       => $mese,
                    'id_setting' => $idSetting,
                    'nome'       => $validation['data']['nome'],
                    'stato'      => 'bozza',
                    'creato_da'  => $userId,
                ]);

                foreach ($operatoriDelPiano as $op) {
                    $idOp = (int) $op['id'];
                    $oreDovute = (float) $op['ore_contrattuali_mensili'];

                    // Il saldo (op, anno, mese) può già esistere se l'altro
                    // piano del mese (setting opposto) è stato creato prima e
                    // l'op è "in itinere" lì. In quel caso non lo ricreiamo
                    // — il saldo è unico cross-setting (vedi schema).
                    $saldoEsistente = $this->saldi->findOneBy([
                        'id_operatore' => $idOp,
                        'anno'         => $anno,
                        'mese'         => $mese,
                    ]);
                    if ($saldoEsistente === null) {
                        $saldoMese = -$oreDovute;
                        $progPrev  = (float) $this->saldi->getProgressivoPrevious($idOp, $anno, $mese);
                        $saldoProg = $progPrev + $saldoMese;
                        $this->saldi->create([
                            'id_operatore'      => $idOp,
                            'anno'              => $anno,
                            'mese'              => $mese,
                            'ore_dovute'        => number_format($oreDovute, 2, '.', ''),
                            'ore_lavorate'      => '0.00',
                            'ore_ferie'         => '0.00',
                            'ore_permessi'      => '0.00',
                            'ore_malattia'      => '0.00',
                            'ore_formazione'    => '0.00',
                            'saldo_mese'        => number_format($saldoMese, 2, '.', ''),
                            'saldo_progressivo' => number_format($saldoProg, 2, '.', ''),
                        ]);
                    }

                    $this->pianoOperatori->create([
                        'id_piano'             => $idPiano,
                        'id_operatore'         => $idOp,
                        'aggiunto_manualmente' => 0,
                        'aggiunto_da'          => null,
                        'note_aggiunta'        => null,
                    ]);
                }

                return $idPiano;
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirectWithErrors(
                    '/piani-turno/create',
                    ['anno' => ["Esiste già un piano (o un saldo) per {$this->labelMese($mese, $anno)} in questo setting. Verifica nell'elenco."]],
                    $input,
                );
            }
            throw $e;
        }

        Logger::get()->info('Piano turni creato', [
            'id'        => $idPiano,
            'anno'      => $anno,
            'mese'      => $mese,
            'setting'   => $idSetting,
            'user_id'   => $userId,
            'operatori' => count($operatoriDelPiano),
        ]);
        return $this->redirect("/piani-turno/{$idPiano}", 'success', 'Piano creato in bozza con i saldi iniziali.');
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');
        $piano = $this->piani->findWithSetting($id);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }

        $anno = (int) $piano['anno'];
        $mese = (int) $piano['mese'];
        // Operatori del piano: presa esplicita da `piano_operatori`, joinata
        // col saldo del mese (che è unico cross-setting). Include gli operatori
        // aggiunti manualmente in itinere — anche dall'altro setting.
        $operatoriDelPiano = $this->pianoOperatori->listInPiano($id, $anno, $mese);

        // Matrice [id_operatore][YYYY-MM-DD] => turno (per cella calendario).
        $turniByOpData = [];
        foreach ($this->turni->listByPiano($id) as $t) {
            $turniByOpData[(int) $t['id_operatore']][(string) $t['data']] = $t;
        }

        $puoModificare = $this->ruoloPuoModificare();
        $bozza = $piano['stato'] === 'bozza';

        return $this->render('piani_turno/show.twig', [
            'piano'              => $piano,
            'saldi'              => $operatoriDelPiano,
            'mesiIt'             => self::MESI_IT,
            'labelMese'          => $this->labelMese($mese, $anno),
            'giorni'             => $this->giorniDelMese($anno, $mese),
            'numTurni'           => $this->piani->countTurni($id),
            'puoModificare'      => $puoModificare,
            'celleEditabili'     => $puoModificare && $bozza,
            'turniByOpData'      => $turniByOpData,
        ]);
    }

    /**
     * Pagina intermedia di conferma eliminazione di un piano in bozza.
     *
     * Scelta UX: niente `confirm()` JS (troppo facile da cliccare via). L'utente
     * arriva qui da un link normale, vede in chiaro cosa verrà cancellato e deve
     * fare un'azione esplicita (form POST con CSRF) per procedere.
     */
    public function deleteConfirm(Request $request): Response
    {
        $id = (int) $request->param('id');
        $piano = $this->piani->findWithSetting($id);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }
        if ($piano['stato'] !== 'bozza') {
            return $this->redirect(
                "/piani-turno/{$id}",
                'error',
                'Solo i piani in bozza possono essere eliminati. Riportalo in bozza prima di eliminare.',
            );
        }

        $anno = (int) $piano['anno'];
        $mese = (int) $piano['mese'];

        return $this->render('piani_turno/delete_confirm.twig', [
            'piano'      => $piano,
            'labelMese'  => $this->labelMese($mese, $anno),
            'numTurni'   => $this->piani->countTurni($id),
            'numSaldi'   => count($this->pianoOperatori->listInPiano($id, $anno, $mese)),
        ]);
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('id');
        $piano = $this->piani->find($id);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }
        if ($piano['stato'] !== 'bozza') {
            return $this->redirect(
                '/piani-turno',
                'error',
                'Solo i piani in bozza possono essere eliminati. Riportalo in bozza prima di eliminare.',
            );
        }

        $anno = (int) $piano['anno'];
        $mese = (int) $piano['mese'];
        $idSetting = (int) $piano['id_setting'];
        $numTurni = $this->piani->countTurni($id);

        // Il piano è in bozza: lavoro in corso, niente blocchi rigidi.
        // - CASCADE su `fk_turni_piano` pulisce i turni del piano.
        // - CASCADE su `fk_piano_op_piano` pulisce piano_operatori.
        // - I saldi sono cross-piano (unici per op/anno/mese):
        //     * op non in altri piani del mese → saldo cancellato e catena
        //       progressivo successiva ricalcolata (helper 4-quater).
        //     * op anche in altri piani del mese → saldo tenuto, MA ore e
        //       saldo_mese vanno rifatti dai turni residui (quelli del piano
        //       appena cancellato sono spariti via CASCADE: senza ricalcolo
        //       resterebbero gonfiati). Poi si propaga la catena.
        $this->db->transaction(function () use ($id, $anno, $mese): void {
            $opInAltriPiani = $this->pianoOperatori->listOperatoriInAltriPianiDelMese($id, $anno, $mese);
            // Snapshot degli op del piano PRIMA del CASCADE provocato dal delete.
            $operatoriDelPiano = $this->pianoOperatori->listIdOperatoriByPiano($id);
            // Delete prima del loop: il ricalcolo del ramo "in altri piani" deve
            // vedere SOLO i turni residui (il CASCADE su `fk_turni_piano` li
            // ha già rimossi a questo punto).
            $this->piani->delete($id);
            foreach ($operatoriDelPiano as $idOp) {
                if (in_array($idOp, $opInAltriPiani, true)) {
                    $this->ricalcolo->ricalcola($idOp, $anno, $mese);
                } else {
                    $this->ricalcolo->rimuoviSaldoSeOrfano($idOp, $anno, $mese, $opInAltriPiani);
                }
            }
        });

        Logger::get()->info('Piano turni eliminato', [
            'id' => $id, 'anno' => $anno, 'mese' => $mese, 'setting' => $idSetting,
            'turni_eliminati' => $numTurni,
            'user_id' => $this->currentUserId(),
        ]);
        $messaggio = $numTurni > 0
            ? "Piano eliminato (rimossi {$numTurni} turni e i saldi del mese)."
            : 'Piano eliminato.';
        return $this->redirect('/piani-turno', 'success', $messaggio);
    }

    public function publish(Request $request): Response
    {
        return $this->cambioStato($request, 'bozza', 'pubblicato', 'Piano pubblicato.');
    }

    public function unpublish(Request $request): Response
    {
        return $this->cambioStato($request, 'pubblicato', 'bozza', 'Piano riportato in bozza.');
    }

    public function archive(Request $request): Response
    {
        return $this->cambioStato($request, 'pubblicato', 'archiviato', 'Piano archiviato.');
    }

    private function cambioStato(Request $request, string $da, string $a, string $successMessage): Response
    {
        $id = (int) $request->param('id');
        $piano = $this->piani->find($id);
        if ($piano === null) {
            return $this->redirect('/piani-turno', 'error', 'Piano non trovato.');
        }
        if ($piano['stato'] !== $da) {
            return $this->redirect(
                "/piani-turno/{$id}",
                'error',
                "Transizione di stato non consentita (atteso «{$da}», attuale «{$piano['stato']}»).",
            );
        }

        $update = ['stato' => $a];
        if ($a === 'pubblicato') {
            $update['pubblicato_il'] = date('Y-m-d H:i:s');
        } elseif ($da === 'pubblicato' && $a === 'bozza') {
            $update['pubblicato_il'] = null;
        }

        $this->piani->update($id, $update);

        Logger::get()->info('Piano turni cambio stato', [
            'id' => $id, 'da' => $da, 'a' => $a, 'user_id' => $this->currentUserId(),
        ]);
        return $this->redirect("/piani-turno/{$id}", 'success', $successMessage);
    }

    private function ruoloPuoModificare(): bool
    {
        $u = $this->currentUser();
        return $u !== null && in_array($u['ruolo'] ?? '', ['admin', 'caposala'], true);
    }

    /**
     * @param list<array<string,mixed>> $settings
     */
    private function settingDefaultUtente(array $settings): ?int
    {
        $u = $this->currentUser();
        if ($u === null) {
            return null;
        }
        $idDefault = isset($u['id_setting']) && $u['id_setting'] !== null ? (int) $u['id_setting'] : null;
        if ($idDefault === null) {
            return null;
        }
        foreach ($settings as $s) {
            if ((int) $s['id'] === $idDefault) {
                return $idDefault;
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $settings
     */
    private function nomeSetting(int $id, array $settings): string
    {
        foreach ($settings as $s) {
            if ((int) $s['id'] === $id) {
                return (string) $s['nome'];
            }
        }
        return (string) $id;
    }

    private function risolviSettingFiltro(string $codice): ?int
    {
        if ($codice === '') {
            return null;
        }
        $s = $this->settings->findByCodice($codice);
        return $s !== null ? (int) $s['id'] : null;
    }

    private function labelMese(int $mese, int $anno): string
    {
        return (self::MESI_IT[$mese] ?? (string) $mese) . ' ' . $anno;
    }

    /**
     * Lista dei giorni del mese con nome breve (lun, mar, ...) e flag weekend.
     *
     * @return list<array{numero:int,nome:string,weekend:bool,date:string}>
     */
    private function giorniDelMese(int $anno, int $mese): array
    {
        $nomiGiorni = ['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'];
        $primo = new \DateTimeImmutable(sprintf('%04d-%02d-01', $anno, $mese));
        $numGiorni = (int) $primo->format('t');

        $out = [];
        for ($g = 1; $g <= $numGiorni; $g++) {
            $d = $primo->setDate($anno, $mese, $g);
            $dow = (int) $d->format('N'); // 1 = lun, 7 = dom
            $out[] = [
                'numero'  => $g,
                'nome'    => $nomiGiorni[$dow - 1],
                'weekend' => $dow >= 6,
                'date'    => $d->format('Y-m-d'),
            ];
        }
        return $out;
    }
}
