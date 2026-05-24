<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AssenzaModel;
use App\Models\OperatoreModel;
use App\Models\SchemaPassoModel;
use App\Models\SchemaTurnazioneModel;
use App\Models\VincoloOperatoreModel;
use DateTimeImmutable;

/**
 * Conteggio delle ore di ASSENZA nel saldo (sessione 6, "presenza statistica").
 *
 * Le assenze non sono turni: vivono in `assenze`. `SaldoRicalcoloService` somma
 * le ore lavorate dai turni e DELEGA a questo service le ore delle assenze del
 * mese, già divise per bucket del saldo.
 *
 * Regole (decise 2026-05-24, vedi memoria project-conteggio-ore-assenze):
 *  - `schema_ore = zero`  (aspettativa) → 0 ore.
 *  - `schema_ore = maternita_8_6_0`     → 8h lun-gio, 6h ven, 0 weekend.
 *  - `schema_ore = da_schema` (regola unica "quanto la posizione di schema"):
 *      · schema SETTIMANALE (coord, UCP) → `ore_assenza` per giorno-settimana;
 *      · schema CICLICO (Hospice)        → il BLOCCO di assenza riparte da M
 *        (posizione 0 al `data_inizio` del record) e segue lo schema; le posizioni
 *        proseguono attraverso il confine di mese. È un template "settimana tipo",
 *        NON la posizione reale del ciclo dell'operatore.
 *
 * Bucket: la categoria del tipo (is_ferie/permesso/malattia/formazione) decide
 * dove finiscono le ore. Maternità/aspettativa vanno in `maternita`, bucket NON
 * ancora agganciato al saldo (manca la colonna — revisione 4-sexies).
 *
 * NB: `codiceSchema`/`vincoliAttivi`/`schema` duplicano la logica di
 * `GeneratoreService` — candidate a estrazione in un futuro `SchemaResolver`.
 */
final class SchemaOreService
{
    /** @var array<string, array{row:array<string,mixed>, passi:list<array<string,mixed>>}|null> */
    private array $schemaCache = [];

    public function __construct(
        private readonly AssenzaModel $assenze,
        private readonly OperatoreModel $operatori,
        private readonly VincoloOperatoreModel $vincoli,
        private readonly SchemaTurnazioneModel $schemi,
        private readonly SchemaPassoModel $passi,
    ) {
    }

    /**
     * Ore di assenza conteggiate per (operatore, mese), raggruppate per bucket.
     *
     * @param list<string> $dateConTurno date Y-m-d già coperte da un turno: si
     *        saltano (il turno vince, niente doppio conteggio).
     * @return array{ferie:float, permessi:float, malattia:float, formazione:float, maternita:float}
     */
    public function oreAssenzePerMese(int $idOperatore, int $anno, int $mese, array $dateConTurno = []): array
    {
        $out = ['ferie' => 0.0, 'permessi' => 0.0, 'malattia' => 0.0, 'formazione' => 0.0, 'maternita' => 0.0];

        $assenze = $this->assenze->listConTipoPerOperatoreMese($idOperatore, $anno, $mese);
        if ($assenze === []) {
            return $out;
        }

        $skip   = array_flip($dateConTurno);
        $primo  = sprintf('%04d-%02d-01', $anno, $mese);
        $ultimo = (new DateTimeImmutable($primo))->modify('last day of this month')->format('Y-m-d');

        [$isWeekly, $periodo, $oreAssByPos] = $this->risolviSchemaOperatore($idOperatore, $primo, $ultimo);

        foreach ($assenze as $a) {
            $inizioBlocco = (string) $a['data_inizio'];          // PIENO (può precedere il mese)
            $da    = max($inizioBlocco, $primo);                 // confronto lessicografico Y-m-d
            $aFine = min((string) $a['data_fine'], $ultimo);
            $schemaOre = (string) $a['schema_ore'];
            $bucket    = $this->bucketDi($a);

            $cur = new DateTimeImmutable($da);
            $end = new DateTimeImmutable($aFine);
            while ($cur <= $end) {
                $d = $cur->format('Y-m-d');
                if (!isset($skip[$d])) {
                    $out[$bucket] += $this->orePerGiorno($schemaOre, $cur, $inizioBlocco, $isWeekly, $periodo, $oreAssByPos);
                }
                $cur = $cur->modify('+1 day');
            }
        }

        return $out;
    }

    /**
     * Ore conteggiate per un singolo giorno di assenza.
     *
     * @param array<int,float> $oreAssByPos posizione => ore_assenza
     */
    private function orePerGiorno(
        string $schemaOre,
        DateTimeImmutable $giorno,
        string $inizioBlocco,
        bool $isWeekly,
        int $periodo,
        array $oreAssByPos,
    ): float {
        if ($schemaOre === 'zero') {
            return 0.0;
        }

        $dow = (int) $giorno->format('N'); // 1=lun .. 7=dom

        if ($schemaOre === 'maternita_8_6_0') {
            if ($dow <= 4) {
                return 8.0;   // lun-gio
            }
            if ($dow === 5) {
                return 6.0;   // ven
            }
            return 0.0;       // sab/dom
        }

        // da_schema (default)
        if ($oreAssByPos === []) {
            return 0.0;       // categoria/setting senza schema risolvibile: non conteggio
        }
        if ($isWeekly) {
            $pos = $dow - 1;  // 0=lun .. 6=dom
        } else {
            // Restart da M: posizione 0 al data_inizio del blocco, avanza ogni giorno.
            $diffGiorni = (int) $giorno->diff(new DateTimeImmutable($inizioBlocco))->days;
            $pos = $diffGiorni % $periodo;
        }
        return $oreAssByPos[$pos] ?? 0.0;
    }

    /**
     * Risolve lo schema dell'operatore e ne estrae le ore_assenza per posizione.
     *
     * @return array{0:bool, 1:int, 2:array<int,float>}  [isWeekly, periodo, oreAssByPos]
     */
    private function risolviSchemaOperatore(int $idOp, string $primo, string $ultimo): array
    {
        $op = $this->operatori->findConSettingCategoria($idOp);
        if ($op === null) {
            return [false, 0, []];
        }
        $vincoliSet = $this->vincoliAttivi($idOp, $primo, $ultimo);
        $codice = $this->codiceSchema((string) $op['setting_codice'], (string) $op['categoria_nome'], $vincoliSet);
        if ($codice === null) {
            return [false, 0, []];
        }
        $schema = $this->schema($codice);
        if ($schema === null) {
            return [false, 0, []];
        }
        $oreAssByPos = [];
        foreach ($schema['passi'] as $p) {
            $oreAssByPos[(int) $p['posizione']] = (float) $p['ore_assenza'];
        }
        return [
            $schema['row']['famiglia'] === 'settimanale',
            (int) $schema['row']['periodo_giorni'],
            $oreAssByPos,
        ];
    }

    /**
     * Codice schema da setting + categoria + vincoli (per il conteggio non
     * serve `skipWeekend`: il `no_weekend` usa comunque lo schema regolare).
     *
     * @param list<string> $vincoliSet
     */
    private function codiceSchema(string $settingCodice, string $categoriaNome, array $vincoliSet): ?string
    {
        $cat = strtoupper(trim($categoriaNome));
        $isOss = str_contains($cat, 'OSS');
        $isCoord = str_contains($cat, 'COORD');

        if ($settingCodice === 'ucp_dom') {
            return $isOss ? 'ucpdom_oss' : 'ucpdom_infermieri';
        }
        if ($settingCodice === 'hospice') {
            if ($isCoord) {
                return 'hospice_coordinatrice';
            }
            if (in_array('solo_mattine', $vincoliSet, true)) {
                return 'hospice_solo_mattine';
            }
            if (in_array('no_notti', $vincoliSet, true)) {
                return 'hospice_no_notti';
            }
            return 'hospice_regolare';
        }
        return null;
    }

    /** @return list<string> codici dei vincoli attivi nel periodo */
    private function vincoliAttivi(int $idOp, string $primo, string $ultimo): array
    {
        $set = [];
        foreach ($this->vincoli->listJoined(null, $idOp) as $v) {
            if ((int) $v['attivo'] !== 1) {
                continue;
            }
            $inizio = $v['data_inizio'] !== null ? (string) $v['data_inizio'] : null;
            $fine = $v['data_fine'] !== null ? (string) $v['data_fine'] : null;
            if ($inizio !== null && $inizio > $ultimo) {
                continue;
            }
            if ($fine !== null && $fine < $primo) {
                continue;
            }
            $set[] = (string) $v['tipo_vincolo'];
        }
        return $set;
    }

    /** @return array{row:array<string,mixed>, passi:list<array<string,mixed>>}|null */
    private function schema(string $codice): ?array
    {
        if (array_key_exists($codice, $this->schemaCache)) {
            return $this->schemaCache[$codice];
        }
        $row = $this->schemi->findByCodice($codice);
        if ($row === null) {
            return $this->schemaCache[$codice] = null;
        }
        return $this->schemaCache[$codice] = [
            'row' => $row,
            'passi' => $this->passi->listBySchema((int) $row['id']),
        ];
    }

    /** Bucket del saldo in cui finiscono le ore dell'assenza. */
    private function bucketDi(array $a): string
    {
        if ((int) $a['is_ferie'] === 1) {
            return 'ferie';
        }
        if ((int) $a['is_permesso'] === 1) {
            return 'permessi';
        }
        if ((int) $a['is_malattia'] === 1) {
            return 'malattia';
        }
        if ((int) $a['is_formazione'] === 1) {
            return 'formazione';
        }
        // MAT/ASP (esclude_pianificazione) o tipi senza categoria → maternita
        // (non ancora agganciato al saldo: vedi revisione 4-sexies).
        return 'maternita';
    }
}
