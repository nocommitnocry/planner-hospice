<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AssenzaModel;
use App\Models\PianoOperatoreModel;
use App\Models\PianoTurnoModel;
use App\Models\TurnoModel;

/**
 * Caricamento dei dati di vista di un piano turno (griglia calendario).
 *
 * Estratto da PianiTurnoController::show() (sessione 8) per condividere la stessa
 * logica con la stampa PDF (PianoPdfService) senza duplicarla — vedi spec
 * spec-pdf-piano-turni §7.1/§10. Il service NON conosce ruoli/permessi: restituisce
 * solo i dati. La modalità editabile (`puoModificare`/`celleEditabili`) resta
 * responsabilità del controller, perché dipende dall'utente corrente.
 *
 * Niente side-effect: legge e ritorna.
 */
final class PianoVistaService
{
    private const MESI_IT = [
        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
        5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
        9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
    ];

    public function __construct(
        private PianoTurnoModel $piani,
        private PianoOperatoreModel $pianoOperatori,
        private TurnoModel $turni,
        private AssenzaModel $assenze,
    ) {
    }

    /**
     * Carica tutti i dati della griglia del piano `$id`.
     *
     * @return array<string,mixed>|null  null se il piano non esiste. Chiavi:
     *   piano, anno, mese, labelMese, giorni, numTurni, saldi,
     *   turniByOpData, crossSettingByOpData, assenzeByOp,
     *   nascostiGriglia, nascostiMotivo.
     */
    public function carica(int $id): ?array
    {
        $piano = $this->piani->findWithSetting($id);
        if ($piano === null) {
            return null;
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

        // Matrice cross-setting: turni dei miei operatori in altri piani dello
        // stesso mese (sessione 4-quinquies). L'UNIQUE (operatore, data) su
        // `turni` garantisce che una stessa cella non possa avere sia un turno
        // del piano corrente sia uno cross-setting.
        $crossSettingByOpData = [];
        foreach ($this->turni->listCrossSettingPerPiano($id, $anno, $mese) as $t) {
            $crossSettingByOpData[(int) $t['id_operatore']][(string) $t['data']] = $t;
        }

        // Assenze attive sul mese per gli operatori del piano (sessione 5).
        $idOperatori = array_map(static fn ($r) => (int) $r['id_operatore'], $operatoriDelPiano);
        $primoDelMese  = sprintf('%04d-%02d-01', $anno, $mese);
        $ultimoDelMese = (new \DateTimeImmutable($primoDelMese))->modify('last day of this month')->format('Y-m-d');
        $assenzeByOp = [];
        foreach ($this->assenze->listAttiveInPeriodo($idOperatori, $primoDelMese, $ultimoDelMese) as $a) {
            $assenzeByOp[(int) $a['id_operatore']][] = $a;
        }

        // Operatori nascosti dalla griglia (4-sexies rivista): maternità/aspettativa
        // che copre l'intero mese. Restano nella tabella saldi ma non hanno righe
        // assegnabili nel calendario. Il motivo (descrizione del tipo) etichetta la riga.
        $motivoByOp = [];
        foreach ($this->assenze->listEsclusioniConTipoNelMese($anno, $mese) as $r) {
            $motivoByOp[(int) $r['id_operatore']] = (string) $r['tipo_descrizione'];
        }
        $nascostiGriglia = [];
        $nascostiMotivo = [];
        foreach ($idOperatori as $idOp) {
            if (isset($motivoByOp[$idOp])) {
                $nascostiGriglia[] = $idOp;
                $nascostiMotivo[$idOp] = $motivoByOp[$idOp];
            }
        }

        return [
            'piano'                => $piano,
            'anno'                 => $anno,
            'mese'                 => $mese,
            'labelMese'            => $this->labelMese($mese, $anno),
            'giorni'               => $this->giorniDelMese($anno, $mese),
            'numTurni'             => $this->piani->countTurni($id),
            'saldi'                => $operatoriDelPiano,
            'turniByOpData'        => $turniByOpData,
            'crossSettingByOpData' => $crossSettingByOpData,
            'assenzeByOp'          => $assenzeByOp,
            'nascostiGriglia'      => $nascostiGriglia,
            'nascostiMotivo'       => $nascostiMotivo,
        ];
    }

    public function labelMese(int $mese, int $anno): string
    {
        return (self::MESI_IT[$mese] ?? (string) $mese) . ' ' . $anno;
    }

    /**
     * Lista dei giorni del mese con nome breve (lun, mar, ...) e flag weekend.
     *
     * @return list<array{numero:int,nome:string,weekend:bool,date:string}>
     */
    public function giorniDelMese(int $anno, int $mese): array
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
