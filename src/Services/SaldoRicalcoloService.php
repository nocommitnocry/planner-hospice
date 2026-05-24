<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AssenzaModel;
use App\Models\OperatoreModel;
use App\Models\SaldoOreModel;
use App\Models\SchemaPassoModel;
use App\Models\SchemaTurnazioneModel;
use App\Models\TurnoModel;
use App\Models\VincoloOperatoreModel;

/**
 * Ricalcolo del saldo ore di un operatore per un mese, con propagazione del
 * saldo progressivo ai mesi successivi.
 *
 * Va chiamato dentro la stessa transazione DB dell'operazione che ha modificato
 * i turni (insert/update/delete in `turni`), così se qualcosa fallisce viene
 * fatto rollback insieme alla mutazione.
 *
 * Regole di conteggio (priorità ordine):
 *  - is_riposo     → non contribuisce
 *  - is_ferie      → ore_ferie
 *  - is_permesso   → ore_permessi
 *  - is_malattia   → ore_malattia
 *  - is_formazione → ore_formazione
 *  - altrimenti    → ore_lavorate
 *
 * Le ore delle ASSENZE (non sono turni: vivono in `assenze`) sono aggiunte ai
 * bucket via SchemaOreService; `maternita` raccoglie le esclude_pianificazione.
 *
 * saldo_mese        = (lavorate + ferie + permessi + malattia + formazione + maternita) - ore_dovute
 * saldo_progressivo = saldo_progressivo del mese precedente + saldo_mese
 *
 * API pubblica (sessione 4-quater: split di `ricalcola`):
 *  - `ricalcolaMese`: ricalcola SOLO il mese corrente (ore e saldi) dai turni
 *    effettivi e ritorna il nuovo `saldo_progressivo`. Non propaga.
 *  - `propagaDaQui`: propaga ai mesi successivi a partire da un valore dato
 *    (uso: reset di verità manuale del progressivo).
 *  - `ricalcola`: wrapper di comodo (uso primario da `TurniController`) che
 *    combina i due.
 *  - `rimuoviSaldoSeOrfano`: cancella il saldo se l'operatore non è in altri
 *    piani dello stesso mese e ricalcola la catena dei progressivi successivi.
 */
final class SaldoRicalcoloService
{
    private const MAX_PROPAGAZIONE_MESI = 24;

    private readonly SchemaOreService $schemaOre;

    public function __construct(
        private readonly SaldoOreModel $saldi,
        private readonly TurnoModel $turni,
        ?SchemaOreService $schemaOre = null,
    ) {
        // Costruito internamente per non toccare i siti di `new SaldoRicalcoloService`
        // (i Model prendono il DB dal Container). Iniettabile per i test.
        $this->schemaOre = $schemaOre ?? new SchemaOreService(
            new AssenzaModel(),
            new OperatoreModel(),
            new VincoloOperatoreModel(),
            new SchemaTurnazioneModel(),
            new SchemaPassoModel(),
        );
    }

    /**
     * Ricalcola le ore e il saldo_mese/saldo_progressivo del mese indicato
     * dai turni effettivi. NON propaga ai mesi successivi.
     *
     * Ritorna il nuovo saldo_progressivo (utile per chi vuole poi propagare).
     * Ritorna null se il saldo del mese non esiste.
     */
    public function ricalcolaMese(int $idOperatore, int $anno, int $mese): ?float
    {
        $saldo = $this->saldi->findOneBy([
            'id_operatore' => $idOperatore,
            'anno'         => $anno,
            'mese'         => $mese,
        ]);
        if ($saldo === null) {
            return null;
        }

        $oreLavorate = 0.0;
        $oreFerie = 0.0;
        $orePermessi = 0.0;
        $oreMalattia = 0.0;
        $oreFormazione = 0.0;
        $oreMaternita = 0.0;

        // Ultimo giorno del mese (Y-m-d): una notte che cade qui ha la coda
        // post-mezzanotte nel mese successivo (vedi split sotto).
        $ultimoGiornoMese = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $anno, $mese)))->format('Y-m-t');

        $dateConTurno = [];
        foreach ($this->turni->listByOperatoreInMese($idOperatore, $anno, $mese) as $t) {
            $dateConTurno[] = (string) $t['data']; // giorni coperti: l'assenza non li riconta
            // Opzione B (sessione 6): le ore effettive del turno vincono sul
            // default del tipo turno. NULL (turni pre-6 o manuali) => fallback.
            $ore = $t['ore_effettive'] !== null
                ? (float) $t['ore_effettive']
                : (float) $t['ore_conteggiate'];
            if ((int) $t['is_riposo'] === 1) {
                continue;
            }
            if ((int) $t['is_ferie'] === 1) {
                $oreFerie += $ore;
            } elseif ((int) $t['is_permesso'] === 1) {
                $orePermessi += $ore;
            } elseif ((int) $t['is_malattia'] === 1) {
                $oreMalattia += $ore;
            } elseif ((int) $t['is_formazione'] === 1) {
                $oreFormazione += $ore;
            } else {
                // Lavorato. "Le ore seguono il calendario" (Soluzione 2): una
                // notte attraversa la mezzanotte. Se inizia sull'ULTIMO giorno
                // del mese, la coda post-mezzanotte appartiene al mese dopo
                // (che la recupera nel suo lookback). Le notti interne al mese
                // restano intere (i due giorni cadono nello stesso mese, il
                // totale non cambia).
                $orePost = $this->orePostMezzanotte($t['ora_inizio'] ?? null, $t['ora_fine'] ?? null, $ore);
                if ($orePost !== null && (string) $t['data'] === $ultimoGiornoMese) {
                    $oreLavorate += $ore - $orePost;
                } else {
                    $oreLavorate += $ore;
                }
            }
        }

        // Coda di una notte iniziata l'ULTIMO giorno del mese PRECEDENTE: le sue
        // ore post-mezzanotte ricadono in questo mese. Lo smonto `S` del giorno 1
        // è a 0h, quindi le ore vanno recuperate da qui. Simmetrico allo split
        // sopra: insieme conservano l'intera notte tra i due mesi.
        $ultimoMesePrec = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $anno, $mese)))
            ->modify('-1 day')->format('Y-m-d');
        $nottePrec = $this->turni->findByOperatoreData($idOperatore, $ultimoMesePrec);
        if ($nottePrec !== null && $this->eLavorato($nottePrec)) {
            $orePrec = $nottePrec['ore_effettive'] !== null
                ? (float) $nottePrec['ore_effettive']
                : (float) $nottePrec['ore_conteggiate'];
            $orePost = $this->orePostMezzanotte($nottePrec['ora_inizio'] ?? null, $nottePrec['ora_fine'] ?? null, $orePrec);
            if ($orePost !== null) {
                $oreLavorate += $orePost;
            }
        }

        // Ore delle ASSENZE (sessione 6): non sono turni, vivono in `assenze`.
        // SchemaOreService le conta "quanto la posizione di schema" e le divide
        // per bucket. I giorni già coperti da un turno vengono saltati.
        // Il bucket `maternita` raccoglie le assenze esclude_pianificazione
        // (maternità 8/6/0 -> saldo ~ neutro; aspettativa 0 -> resta il deficit).
        $assenzeOre = $this->schemaOre->oreAssenzePerMese($idOperatore, $anno, $mese, $dateConTurno);
        $oreFerie      += $assenzeOre['ferie'];
        $orePermessi   += $assenzeOre['permessi'];
        $oreMalattia   += $assenzeOre['malattia'];
        $oreFormazione += $assenzeOre['formazione'];
        $oreMaternita  += $assenzeOre['maternita'];

        $oreDovute = (float) $saldo['ore_dovute'];
        $saldoMese = ($oreLavorate + $oreFerie + $orePermessi + $oreMalattia + $oreFormazione + $oreMaternita) - $oreDovute;
        $progPrev = (float) $this->saldi->getProgressivoPrevious($idOperatore, $anno, $mese);
        $saldoProg = $progPrev + $saldoMese;

        $this->saldi->update((int) $saldo['id'], [
            'ore_lavorate'      => $this->fmt($oreLavorate),
            'ore_ferie'         => $this->fmt($oreFerie),
            'ore_permessi'      => $this->fmt($orePermessi),
            'ore_malattia'      => $this->fmt($oreMalattia),
            'ore_formazione'    => $this->fmt($oreFormazione),
            'ore_maternita'     => $this->fmt($oreMaternita),
            'saldo_mese'        => $this->fmt($saldoMese),
            'saldo_progressivo' => $this->fmt($saldoProg),
        ]);

        return $saldoProg;
    }

    public function ricalcola(int $idOperatore, int $anno, int $mese): void
    {
        $progressivo = $this->ricalcolaMese($idOperatore, $anno, $mese);
        if ($progressivo === null) {
            return;
        }
        // Le ore di una notte sull'ultimo giorno di QUESTO mese ricadono in parte
        // sul mese successivo: il suo `ore_lavorate` dipende da qui. Quindi
        // ri-sommiamo anche il mese dopo (ore + saldo) PRIMA di propagare i
        // progressivi. Idempotente per i mesi senza notti a cavallo. Il mese+2 in
        // avanti dipende solo dalla notte di fine del mese+1 (non toccata da qui),
        // quindi alla catena successiva basta la propagazione del progressivo.
        $succ = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $anno, $mese)))->modify('+1 month');
        $annoSucc = (int) $succ->format('Y');
        $meseSucc = (int) $succ->format('n');
        $progSucc = $this->ricalcolaMese($idOperatore, $annoSucc, $meseSucc);
        if ($progSucc === null) {
            // Nessun saldo per il mese successivo: comportamento invariato.
            $this->propagaProgressivo($idOperatore, $anno, $mese, $progressivo);
            return;
        }
        $this->propagaProgressivo($idOperatore, $annoSucc, $meseSucc, $progSucc);
    }

    /**
     * Propaga il saldo_progressivo ai mesi successivi a partire da un valore
     * imposto manualmente (4-ter: "reset di verità" da cedolino).
     *
     * Va chiamato DOPO aver scritto il nuovo `saldo_progressivo` nel mese
     * indicato; questo metodo ricostruisce solo i progressivi dei mesi
     * successivi (le ore degli altri mesi non vengono toccate).
     */
    public function propagaDaQui(int $idOperatore, int $anno, int $mese, float $progressivoCorrente): void
    {
        $this->propagaProgressivo($idOperatore, $anno, $mese, $progressivoCorrente);
    }

    /**
     * Cancella il saldo (op, anno, mese) SE l'operatore non è presente in altri
     * piani dello stesso mese, e in tal caso ricalcola la catena dei progressivi
     * dei mesi successivi (sottraendo di fatto il saldo_mese che è sparito).
     *
     * Va chiamato dentro la stessa transazione del flusso chiamante.
     *
     * @param list<int> $operatoriInAltriPianiDelMese Lista degli id_operatore
     *        che compaiono in piani del mese diversi da quello in cui stiamo
     *        operando. Se $idOperatore è in questa lista, il saldo NON viene
     *        toccato (resta valido per gli altri piani).
     */
    public function rimuoviSaldoSeOrfano(
        int $idOperatore,
        int $anno,
        int $mese,
        array $operatoriInAltriPianiDelMese,
    ): void {
        if (in_array($idOperatore, $operatoriInAltriPianiDelMese, true)) {
            return;
        }

        $saldo = $this->saldi->findOneBy([
            'id_operatore' => $idOperatore,
            'anno'         => $anno,
            'mese'         => $mese,
        ]);
        if ($saldo === null) {
            return;
        }

        // Progressivo "di partenza" per la catena successiva = quello del mese
        // PRECEDENTE a quello che sto eliminando. Così i mesi successivi non
        // includono più il saldo_mese del mese cancellato.
        $progPrev = (float) $this->saldi->getProgressivoPrevious($idOperatore, $anno, $mese);

        $this->saldi->delete((int) $saldo['id']);

        $this->propagaDaQui($idOperatore, $anno, $mese, $progPrev);
    }

    private function propagaProgressivo(int $idOperatore, int $anno, int $mese, float $progressivoCorrente): void
    {
        $progPrec = $progressivoCorrente;
        $a = $anno;
        $m = $mese;

        for ($i = 0; $i < self::MAX_PROPAGAZIONE_MESI; $i++) {
            if ($m === 12) {
                $m = 1;
                $a++;
            } else {
                $m++;
            }
            $next = $this->saldi->findOneBy([
                'id_operatore' => $idOperatore,
                'anno'         => $a,
                'mese'         => $m,
            ]);
            if ($next === null) {
                return;
            }
            $nuovoProg = $progPrec + (float) $next['saldo_mese'];
            $this->saldi->update((int) $next['id'], [
                'saldo_progressivo' => $this->fmt($nuovoProg),
            ]);
            $progPrec = $nuovoProg;
        }
    }

    /** True se il turno è di lavoro (nessun flag riposo/assenza). */
    private function eLavorato(array $turno): bool
    {
        return (int) $turno['is_riposo'] === 0
            && (int) $turno['is_ferie'] === 0
            && (int) $turno['is_permesso'] === 0
            && (int) $turno['is_malattia'] === 0
            && (int) $turno['is_formazione'] === 0;
    }

    /**
     * Ore di un turno che cadono DOPO la mezzanotte (cioè nel giorno solare
     * successivo a quello d'inizio). Ritorna null se il turno NON attraversa la
     * mezzanotte — orari mancanti (ferie, permessi: TIME NULL) o `ora_fine`
     * non precedente a `ora_inizio` — nel qual caso non c'è nulla da spostare
     * tra i mesi.
     *
     * La coda = `ora_fine` espressa in ore (es. 07:30 → 7,5). L'eventuale extra
     * (vestizione: `ore` > durata oraria) resta implicitamente sul giorno
     * d'inizio, perché lo split di chi chiama fa `ore - coda`. Limitata a `$ore`
     * per non andare mai in negativo su dati incoerenti.
     */
    private function orePostMezzanotte(?string $oraInizio, ?string $oraFine, float $ore): ?float
    {
        if ($oraInizio === null || $oraFine === null) {
            return null;
        }
        if (strcmp((string) $oraInizio, (string) $oraFine) <= 0) {
            return null; // ora_fine >= ora_inizio: turno nello stesso giorno
        }
        return min($this->oreDaTime((string) $oraFine), $ore);
    }

    /** "HH:MM:SS" → ore decimali (07:30:00 → 7.5). */
    private function oreDaTime(string $time): float
    {
        [$h, $m, $s] = array_pad(explode(':', $time), 3, '0');
        return (int) $h + ((int) $m) / 60.0 + ((int) $s) / 3600.0;
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
