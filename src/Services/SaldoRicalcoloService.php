<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\SaldoOreModel;
use App\Models\TurnoModel;

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
 * saldo_mese        = (lavorate + ferie + permessi + malattia + formazione) - ore_dovute
 * saldo_progressivo = saldo_progressivo del mese precedente + saldo_mese
 *
 * I mesi successivi (se esistono) vengono propagati ricalcolando solo
 * saldo_progressivo (le loro ore non cambiano).
 */
final class SaldoRicalcoloService
{
    private const MAX_PROPAGAZIONE_MESI = 24;

    public function __construct(
        private readonly SaldoOreModel $saldi,
        private readonly TurnoModel $turni,
    ) {
    }

    public function ricalcola(int $idOperatore, int $anno, int $mese): void
    {
        $saldo = $this->saldi->findOneBy([
            'id_operatore' => $idOperatore,
            'anno'         => $anno,
            'mese'         => $mese,
        ]);
        if ($saldo === null) {
            return;
        }

        $oreLavorate = 0.0;
        $oreFerie = 0.0;
        $orePermessi = 0.0;
        $oreMalattia = 0.0;
        $oreFormazione = 0.0;

        foreach ($this->turni->listByOperatoreInMese($idOperatore, $anno, $mese) as $t) {
            $ore = (float) $t['ore_conteggiate'];
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
                $oreLavorate += $ore;
            }
        }

        $oreDovute = (float) $saldo['ore_dovute'];
        $saldoMese = ($oreLavorate + $oreFerie + $orePermessi + $oreMalattia + $oreFormazione) - $oreDovute;
        $progPrev = (float) $this->saldi->getProgressivoPrevious($idOperatore, $anno, $mese);
        $saldoProg = $progPrev + $saldoMese;

        $this->saldi->update((int) $saldo['id'], [
            'ore_lavorate'      => $this->fmt($oreLavorate),
            'ore_ferie'         => $this->fmt($oreFerie),
            'ore_permessi'      => $this->fmt($orePermessi),
            'ore_malattia'      => $this->fmt($oreMalattia),
            'ore_formazione'    => $this->fmt($oreFormazione),
            'saldo_mese'        => $this->fmt($saldoMese),
            'saldo_progressivo' => $this->fmt($saldoProg),
        ]);

        $this->propagaProgressivo($idOperatore, $anno, $mese, $saldoProg);
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

    private function fmt(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
