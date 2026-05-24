<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AssenzaModel;
use App\Models\PianoOperatoreModel;
use App\Models\PianoTurnoModel;
use App\Models\SchemaPassoModel;
use App\Models\SchemaTurnazioneModel;
use App\Models\TurnoModel;
use App\Models\VincoloOperatoreModel;
use DateTimeImmutable;

/**
 * Generatore automatico di una bozza di piano — Automatismo 2: continuazione
 * dal mese precedente pubblicato (sessione 6).
 *
 * NON gestisce la transazione: va chiamato DENTRO una transazione del
 * controller, così insert dei turni + ricalcolo saldi fanno rollback insieme.
 *
 * Per ogni operatore del piano:
 *  - si risolve lo schema di turnazione (da setting + categoria, con i vincoli
 *    che scelgono la variante Hospice);
 *  - schemi SETTIMANALI (coordinatrice, UCP-DOM): la posizione è il giorno della
 *    settimana, nessuna continuazione necessaria;
 *  - schemi CICLICI (Hospice regolare e varianti): si ricostruisce la posizione
 *    nel ciclo dall'ultimo turno regolare del mese precedente (stesso setting) e
 *    si prosegue dal giorno 1. Le assenze (e i weekend per `no_weekend`) CONGELANO
 *    il ciclo: non avanzano la posizione e non ricevono un turno.
 *
 * Casi limite → lista "da assegnare a mano" (mai scelta arbitraria): operatore
 * senza turni regolari nel mese precedente (assente tutto il mese, nuovo,
 * rientro), o categoria/setting senza schema.
 *
 * Imprecisioni accettate (la coordinatrice corregge a mano, vedi memoria
 * project-automazioni-popolamento): turni irregolari da sostituzione e rientri
 * da assenze lunghe possono produrre una posizione sfasata.
 */
final class GeneratoreService
{
    /** Mappa codice schema => [row, passi] già caricati. */
    private array $schemaCache = [];

    public function __construct(
        private readonly PianoTurnoModel $piani,
        private readonly PianoOperatoreModel $pianoOperatori,
        private readonly SchemaTurnazioneModel $schemi,
        private readonly SchemaPassoModel $passi,
        private readonly TurnoModel $turni,
        private readonly VincoloOperatoreModel $vincoli,
        private readonly AssenzaModel $assenze,
        private readonly SaldoRicalcoloService $saldi,
    ) {
    }

    /**
     * Genera la bozza per continuazione. Ritorna un riepilogo.
     *
     * @return array{
     *   ok: bool,
     *   errore: ?string,
     *   turni_creati: int,
     *   popolati: list<string>,
     *   manuali: list<array{operatore:string, motivo:string}>,
     *   mese_origine: ?string
     * }
     */
    public function generaDaMesePrecedente(int $idPiano): array
    {
        $vuoto = [
            'ok' => false, 'errore' => null, 'turni_creati' => 0,
            'popolati' => [], 'manuali' => [], 'mese_origine' => null,
        ];

        $piano = $this->piani->findWithSetting($idPiano);
        if ($piano === null) {
            return [...$vuoto, 'errore' => 'Piano non trovato.'];
        }
        if ($piano['stato'] !== 'bozza') {
            return [...$vuoto, 'errore' => 'Il popolamento è ammesso solo sui piani in bozza.'];
        }

        $anno = (int) $piano['anno'];
        $mese = (int) $piano['mese'];
        $idSetting = (int) $piano['id_setting'];
        $settingCodice = (string) $piano['setting_codice'];

        // Mese precedente, stesso setting, PUBBLICATO.
        $prevAnno = $mese === 1 ? $anno - 1 : $anno;
        $prevMese = $mese === 1 ? 12 : $mese - 1;
        $prev = $this->piani->findByAnnoMeseSetting($prevAnno, $prevMese, $idSetting);
        if ($prev === null || $prev['stato'] !== 'pubblicato') {
            return [...$vuoto, 'errore' => sprintf(
                'Nessun piano PUBBLICATO per %02d/%d in questo setting: riporta a mano il pregresso e pubblicalo, poi riprova.',
                $prevMese,
                $prevAnno,
            )];
        }

        // Turni del mese precedente raggruppati: [id_operatore][Y-m-d] => tipo_codice.
        $prevByOp = [];
        foreach ($this->turni->listByPiano((int) $prev['id']) as $t) {
            $prevByOp[(int) $t['id_operatore']][(string) $t['data']] = (string) $t['tipo_codice'];
        }

        $operatori = $this->pianoOperatori->listInPiano($idPiano, $anno, $mese);
        $idOperatori = array_map(static fn ($o) => (int) $o['id_operatore'], $operatori);

        $giorni = $this->giorniDelMese($anno, $mese);
        $primo = $giorni[0];
        $ultimo = $giorni[count($giorni) - 1];
        $mappaAssenze = $this->mappaAssenze($idOperatori, $primo, $ultimo);
        // Date già occupate (qualsiasi piano): l'UNIQUE (operatore, data) è
        // globale, quindi le saltiamo come fossero assenze (congelano il ciclo).
        $occupate = $this->turni->dateOccupateInMese($idOperatori, $anno, $mese);

        $turniCreati = 0;
        $popolati = [];
        $manuali = [];
        $opDaRicalcolare = [];

        foreach ($operatori as $op) {
            $idOp = (int) $op['id_operatore'];
            $nome = trim((string) $op['operatore_cognome'] . ' ' . (string) $op['operatore_nome']);

            $vincoliSet = $this->vincoliAttivi($idOp, $primo, $ultimo);
            [$schemaCodice, $skipWeekend] = $this->risolviSchema(
                $settingCodice,
                (string) $op['categoria_nome'],
                $vincoliSet,
            );
            if ($schemaCodice === null) {
                $manuali[] = ['operatore' => $nome, 'motivo' => 'categoria/setting senza schema automatico'];
                continue;
            }

            $schema = $this->schema($schemaCodice);
            if ($schema === null) {
                $manuali[] = ['operatore' => $nome, 'motivo' => "schema «{$schemaCodice}» non trovato"];
                continue;
            }
            $periodo = (int) $schema['row']['periodo_giorni'];
            $passi = $schema['passi'];

            $interrottoDa = null;
            if ($schema['row']['famiglia'] === 'settimanale') {
                // Gli schemi settimanali non hanno un ciclo da interrompere:
                // lavorano comunque il loro pattern per giorno-settimana.
                $creati = $this->popolaSettimanale($idPiano, $idOp, $giorni, $passi, $mappaAssenze, $occupate);
            } else {
                $posIniziale = $this->posizioneIniziale($prevByOp[$idOp] ?? [], $passi, $periodo);
                if ($posIniziale === null) {
                    $manuali[] = ['operatore' => $nome, 'motivo' => 'nessun turno regolare nel mese precedente'];
                    continue;
                }
                $res = $this->popolaCiclico($idPiano, $idOp, $giorni, $passi, $periodo, $posIniziale, $skipWeekend, $mappaAssenze, $occupate);
                $creati = $res['creati'];
                $interrottoDa = $res['interrottoDa'];
            }

            if ($creati > 0) {
                $turniCreati += $creati;
                $popolati[] = $nome;
                $opDaRicalcolare[] = $idOp;
            }
            // Assenza > 2 giorni: il ciclo si ferma lì, il resto è copertura
            // manuale (decisione 2026-05-24). L'operatore può comparire sia in
            // "popolati" (turni pre-assenza) sia qui con la nota di rientro.
            if ($interrottoDa !== null) {
                $manuali[] = [
                    'operatore' => $nome,
                    'motivo' => sprintf(
                        'ciclo interrotto da assenza > 2 giorni dal %s — completa a mano garantendo la copertura',
                        (new DateTimeImmutable($interrottoDa))->format('d/m/Y'),
                    ),
                ];
            } elseif ($creati === 0) {
                $manuali[] = ['operatore' => $nome, 'motivo' => 'nessuna cella disponibile (assenze/turni già presenti)'];
            }
        }

        // Ricalcolo saldi (dentro la stessa transazione del controller).
        foreach ($opDaRicalcolare as $idOp) {
            $this->saldi->ricalcola($idOp, $anno, $mese);
        }

        return [
            'ok' => true,
            'errore' => null,
            'turni_creati' => $turniCreati,
            'popolati' => $popolati,
            'manuali' => $manuali,
            'mese_origine' => sprintf('%02d/%d', $prevMese, $prevAnno),
        ];
    }

    /**
     * Riempie uno schema settimanale: posizione = giorno della settimana
     * (0=lun..6=dom). Salta i giorni di assenza.
     */
    private function popolaSettimanale(int $idPiano, int $idOp, array $giorni, array $passi, array $mappaAssenze, array $occupate): int
    {
        $creati = 0;
        foreach ($giorni as $data) {
            if ($this->isAssente($mappaAssenze, $idOp, $data) || $this->isOccupato($occupate, $idOp, $data)) {
                continue;
            }
            $dow = (int) (new DateTimeImmutable($data))->format('N') - 1; // 0=lun..6=dom
            $passo = $passi[$dow] ?? null;
            if ($passo === null || $passo['id_tipo_turno'] === null) {
                continue;
            }
            $this->creaTurno($idPiano, $idOp, $data, $passo);
            $creati++;
        }
        return $creati;
    }

    /**
     * Riempie uno schema ciclico avanzando la posizione giorno per giorno.
     *
     * - Assenza **breve (1-2 giorni)** e (se skipWeekend) weekend → CONGELANO:
     *   non avanzano la posizione e non ricevono turno, il ciclo riprende dopo.
     * - Assenza **> 2 giorni** → la generazione si INTERROMPE da lì in poi
     *   (decisione 2026-05-24): i giorni dell'assenza e quelli successivi restano
     *   vuoti, la coordinatrice riparte non-ciclico garantendo le presenze minime.
     * - Date già occupate (turno cross-setting, unique globale) → congelano.
     *
     * @return array{creati:int, interrottoDa:?string} interrottoDa = data Y-m-d
     *         dell'assenza lunga che ha fermato il ciclo, o null.
     */
    private function popolaCiclico(
        int $idPiano,
        int $idOp,
        array $giorni,
        array $passi,
        int $periodo,
        int $posIniziale,
        bool $skipWeekend,
        array $mappaAssenze,
        array $occupate,
    ): array {
        $creati = 0;
        $pos = $posIniziale;
        foreach ($giorni as $data) {
            $blocco = $this->bloccoAssenza($mappaAssenze, $idOp, $data);
            if ($blocco !== null) {
                if ($this->spanGiorni($blocco) > 2) {
                    return ['creati' => $creati, 'interrottoDa' => $data]; // stop, resto a mano
                }
                continue; // assenza breve: congela e prosegui
            }
            if ($this->isOccupato($occupate, $idOp, $data)) {
                continue; // congela (turno già presente in altro piano)
            }
            if ($skipWeekend && (int) (new DateTimeImmutable($data))->format('N') >= 6) {
                continue; // congela sul weekend
            }
            $passo = $passi[$pos] ?? null;
            if ($passo !== null && $passo['id_tipo_turno'] !== null) {
                $this->creaTurno($idPiano, $idOp, $data, $passo);
                $creati++;
            }
            $pos = ($pos + 1) % $periodo;
        }
        return ['creati' => $creati, 'interrottoDa' => null];
    }

    /** Crea un turno con le ore_effettive del passo (Opzione B). */
    private function creaTurno(int $idPiano, int $idOp, string $data, array $passo): void
    {
        $this->turni->create([
            'id_piano'      => $idPiano,
            'id_operatore'  => $idOp,
            'data'          => $data,
            'id_tipo_turno' => (int) $passo['id_tipo_turno'],
            'ore_effettive' => $passo['ore_lavorate'],
        ]);
    }

    /**
     * Ricostruisce la posizione di partenza nel ciclo per il giorno 1 del nuovo
     * mese: posizione dell'ultimo turno REGOLARE del mese precedente + 1
     * (le assenze dopo di esso hanno congelato il ciclo).
     *
     * @param array<string,string> $turniPrev  [Y-m-d] => tipo_codice
     * @param list<array<string,mixed>> $passi  passi ordinati per posizione
     * @return int|null  posizione 0..periodo-1, o null se indeterminabile
     */
    private function posizioneIniziale(array $turniPrev, array $passi, int $periodo): ?int
    {
        if ($turniPrev === []) {
            return null;
        }

        // tipo_codice => lista posizioni nello schema (M può stare a 0 e 1).
        $posByTipo = [];
        foreach ($passi as $p) {
            if ($p['tipo_codice'] !== null) {
                $posByTipo[(string) $p['tipo_codice']][] = (int) $p['posizione'];
            }
        }

        // Ultimo giorno (cronologico) con un turno regolare (tipo nello schema).
        $date = array_keys($turniPrev);
        rsort($date); // stringhe Y-m-d: ordine lessicografico == cronologico
        $lastDate = null;
        $lastTipo = null;
        foreach ($date as $d) {
            $codice = $turniPrev[$d];
            if (isset($posByTipo[$codice])) {
                $lastDate = $d;
                $lastTipo = $codice;
                break;
            }
        }
        if ($lastDate === null) {
            return null; // solo assenze / turni irregolari nel mese precedente
        }

        $candidati = $posByTipo[$lastTipo];
        if (count($candidati) === 1) {
            $pos = $candidati[0];
        } else {
            // Disambigua col turno regolare del giorno di calendario precedente:
            // il suo tipo deve combaciare col predecessore ciclico della posizione.
            $giornoPrima = (new DateTimeImmutable($lastDate))->modify('-1 day')->format('Y-m-d');
            $tipoPrima = $turniPrev[$giornoPrima] ?? null;
            $pos = $candidati[0];
            if ($tipoPrima !== null) {
                foreach ($candidati as $c) {
                    $predPos = ($c - 1 + $periodo) % $periodo;
                    $predTipo = $passi[$predPos]['tipo_codice'] ?? null;
                    if ($predTipo !== null && (string) $predTipo === $tipoPrima) {
                        $pos = $c;
                        break;
                    }
                }
            }
        }

        return ($pos + 1) % $periodo;
    }

    /**
     * Mappa lo schema da setting + categoria, con i vincoli che scelgono la
     * variante Hospice. Ritorna [codiceSchema|null, skipWeekend].
     *
     * @param list<string> $vincoliSet codici dei vincoli attivi nel mese
     * @return array{0: ?string, 1: bool}
     */
    private function risolviSchema(string $settingCodice, string $categoriaNome, array $vincoliSet): array
    {
        $cat = strtoupper(trim($categoriaNome));
        $isOss = str_contains($cat, 'OSS');
        $isCoord = str_contains($cat, 'COORD');

        if ($settingCodice === 'ucp_dom') {
            // UCP-DOM: OSS ha il suo schema; inf e coord. condividono ucpdom_infermieri.
            return [$isOss ? 'ucpdom_oss' : 'ucpdom_infermieri', false];
        }

        if ($settingCodice === 'hospice') {
            if ($isCoord) {
                return ['hospice_coordinatrice', false];
            }
            // Infermieri/OSS: ciclo regolare, con variante da vincolo.
            if (in_array('solo_mattine', $vincoliSet, true)) {
                return ['hospice_solo_mattine', false];
            }
            if (in_array('no_notti', $vincoliSet, true)) {
                return ['hospice_no_notti', false];
            }
            $skipWeekend = in_array('no_weekend', $vincoliSet, true);
            return ['hospice_regolare', $skipWeekend];
        }

        return [null, false];
    }

    /** @return array{row: array<string,mixed>, passi: list<array<string,mixed>>}|null */
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
            // Overlap col mese (confronto lessicografico Y-m-d).
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

    /**
     * @param list<int> $idOperatori
     * @return array<int, list<array{inizio:string, fine:string}>>
     */
    private function mappaAssenze(array $idOperatori, string $primo, string $ultimo): array
    {
        $mappa = [];
        foreach ($this->assenze->listAttiveInPeriodo($idOperatori, $primo, $ultimo) as $a) {
            $mappa[(int) $a['id_operatore']][] = [
                'inizio' => (string) $a['data_inizio'],
                'fine'   => (string) $a['data_fine'],
            ];
        }
        return $mappa;
    }

    /** @param array<int, list<array{inizio:string, fine:string}>> $mappa */
    private function isAssente(array $mappa, int $idOp, string $data): bool
    {
        return $this->bloccoAssenza($mappa, $idOp, $data) !== null;
    }

    /**
     * Il blocco di assenza che copre la data (o null). Confronto lessicografico
     * Y-m-d. `inizio`/`fine` sono le date PIENE del record (anche fuori dal mese).
     *
     * @param array<int, list<array{inizio:string, fine:string}>> $mappa
     * @return array{inizio:string, fine:string}|null
     */
    private function bloccoAssenza(array $mappa, int $idOp, string $data): ?array
    {
        foreach ($mappa[$idOp] ?? [] as $r) {
            if ($r['inizio'] <= $data && $data <= $r['fine']) {
                return $r;
            }
        }
        return null;
    }

    /** Durata in giorni di calendario del blocco di assenza (estremi inclusi). */
    private function spanGiorni(array $blocco): int
    {
        $i = new DateTimeImmutable($blocco['inizio']);
        $f = new DateTimeImmutable($blocco['fine']);
        return (int) $i->diff($f)->days + 1;
    }

    /** @param array<int, list<string>> $occupate */
    private function isOccupato(array $occupate, int $idOp, string $data): bool
    {
        return in_array($data, $occupate[$idOp] ?? [], true);
    }

    /** @return list<string> giorni del mese in Y-m-d */
    private function giorniDelMese(int $anno, int $mese): array
    {
        $primo = new DateTimeImmutable(sprintf('%04d-%02d-01', $anno, $mese));
        $n = (int) $primo->format('t');
        $giorni = [];
        for ($d = 0; $d < $n; $d++) {
            $giorni[] = $primo->modify("+{$d} day")->format('Y-m-d');
        }
        return $giorni;
    }
}
