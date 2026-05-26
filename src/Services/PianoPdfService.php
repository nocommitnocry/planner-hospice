<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;
use App\Models\PianoTurnoModel;
use App\Models\TipoTurnoModel;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Generazione del PDF (A3 orizzontale) della griglia di un piano turno
 * pubblicato. Vedi spec-pdf-piano-turni.
 *
 * Riusa il caricamento dati di PianoVistaService (stessa griglia di show.twig);
 * l'unica trasformazione propria è il raggruppamento operatori in
 * Infermieri -> OSS -> Coordinatrice -> Altri (§4), letto dalla colonna
 * `categorie_operatori.gruppo_pianificazione` (migrazione 0011).
 *
 * Niente side-effect (oltre al log INFO degli operatori non classificati):
 * il service produce e ritorna i byte del PDF; lo streaming è del controller.
 *
 * Testabilità: la logica pura (raggruppamento, guardia di stato, rendering
 * HTML->PDF) è esposta come metodi statici, così i test non devono costruire i
 * Model (che aprirebbero la connessione DB). Solo `genera()` tocca il DB.
 */
final class PianoPdfService
{
    /** Ordine fisso dei gruppi nella stampa (§4). */
    private const GRUPPI_ORDINE = ['infermiere', 'oss', 'coordinatore', 'altro'];

    /** Etichette delle bande di gruppo nel PDF (plurali, maiuscolate dal template). */
    private const GRUPPI_TITOLO = [
        'infermiere'   => 'Infermieri',
        'oss'          => 'OSS',
        'coordinatore' => 'Coordinatrice',
        'altro'        => 'Altri',
    ];

    private TipoTurnoModel $tipiTurno;

    public function __construct(
        private PianoTurnoModel $piani,
        private PianoVistaService $vista,
        ?TipoTurnoModel $tipiTurno = null,
    ) {
        $this->tipiTurno = $tipiTurno ?? new TipoTurnoModel();
    }

    /**
     * Genera il PDF del piano `$id`. Ritorna il binario PDF.
     *
     * @throws PianoNonStampabileException se il piano non esiste o non è pubblicato.
     */
    public function genera(int $id): string
    {
        $piano = $this->piani->findWithSetting($id);
        self::assertStampabile($piano);

        $vista = $this->vista->carica($id);
        if ($vista === null) {
            // Difensivo: assertStampabile ha già escluso il null, ma findWithSetting
            // e carica() leggono lo stesso record — coerenza garantita.
            throw new PianoNonStampabileException('Piano non trovato.');
        }

        $gruppi = self::raggruppa($vista['saldi'], $vista['nascostiGriglia']);

        // §4: gli operatori finiti in "Altri" sono il sintomo di una categoria
        // senza `gruppo_pianificazione` impostato (seed da rivedere nel CRUD).
        foreach ($gruppi as $g) {
            if ($g['chiave'] === 'altro' && $g['operatori'] !== []) {
                Logger::get()->info('PDF piano: operatori nel gruppo "Altri" (categoria senza gruppo_pianificazione)', [
                    'id_piano'     => $id,
                    'id_operatori' => array_map(static fn ($o) => (int) $o['id_operatore'], $g['operatori']),
                ]);
            }
        }

        $vm = [
            'piano'                => $vista['piano'],
            'labelMese'            => $vista['labelMese'],
            'giorni'               => $vista['giorni'],
            'gruppi'               => $gruppi,
            'turniByOpData'        => $vista['turniByOpData'],
            'crossSettingByOpData' => $vista['crossSettingByOpData'],
            'assenzeByOp'          => $vista['assenzeByOp'],
            'coloriCss'            => $this->coloriCss(),
            'headerTitolo'         => $this->headerTitolo($vista['piano'], $vista['labelMese']),
        ];

        return self::render($vm);
    }

    /**
     * Guardia di stato: la stampa è ammessa solo per i piani pubblicati (§2).
     *
     * @param array<string,mixed>|null $piano
     * @throws PianoNonStampabileException
     */
    public static function assertStampabile(?array $piano): void
    {
        if ($piano === null) {
            throw new PianoNonStampabileException('Piano non trovato.');
        }
        if (($piano['stato'] ?? null) !== 'pubblicato') {
            throw new PianoNonStampabileException('Il PDF è disponibile solo per i piani pubblicati.');
        }
    }

    /**
     * Raggruppa gli operatori del piano in Infermieri -> OSS -> Coordinatrice ->
     * Altri (§4), escludendo i nascosti dalla griglia (maternità/aspettativa
     * intero mese). Dentro ogni gruppo: ordine alfabetico per cognome, poi nome.
     * I gruppi vuoti non vengono emessi.
     *
     * @param list<array<string,mixed>> $operatori       righe di PianoOperatoreModel::listInPiano
     * @param list<int>                 $nascostiGriglia  id operatore da non disegnare
     * @return list<array{chiave:string,titolo:string,operatori:list<array<string,mixed>>}>
     */
    public static function raggruppa(array $operatori, array $nascostiGriglia): array
    {
        $nascosti = array_fill_keys(array_map('intval', $nascostiGriglia), true);

        $buckets = ['infermiere' => [], 'oss' => [], 'coordinatore' => [], 'altro' => []];
        foreach ($operatori as $op) {
            $idOp = (int) ($op['id_operatore'] ?? 0);
            if (isset($nascosti[$idOp])) {
                continue;
            }
            $gruppo = (string) ($op['categoria_gruppo'] ?? '');
            if (!isset($buckets[$gruppo])) {
                $gruppo = 'altro'; // categoria senza/oltre la classificazione nota
            }
            $buckets[$gruppo][] = $op;
        }

        $coll = \Collator::create('it_IT');
        $cmp = static function (array $a, array $b) use ($coll): int {
            $ka = trim(((string) ($a['operatore_cognome'] ?? '')) . ' ' . ((string) ($a['operatore_nome'] ?? '')));
            $kb = trim(((string) ($b['operatore_cognome'] ?? '')) . ' ' . ((string) ($b['operatore_nome'] ?? '')));
            if ($coll instanceof \Collator) {
                return (int) $coll->compare($ka, $kb);
            }
            return strcasecmp($ka, $kb);
        };

        $gruppi = [];
        foreach (self::GRUPPI_ORDINE as $chiave) {
            if ($buckets[$chiave] === []) {
                continue;
            }
            usort($buckets[$chiave], $cmp);
            $gruppi[] = [
                'chiave'    => $chiave,
                'titolo'    => self::GRUPPI_TITOLO[$chiave],
                'operatori' => $buckets[$chiave],
            ];
        }
        return $gruppi;
    }

    /**
     * Renderizza il view-model in PDF (HTML via Twig -> mpdf). Nessun accesso DB:
     * tutto ciò che serve è nel `$viewModel`. A3 landscape, margini 10mm.
     *
     * @param array<string,mixed> $viewModel
     */
    public static function render(array $viewModel): string
    {
        $loader = new FilesystemLoader(APP_ROOT . '/views');
        $twig = new Environment($loader, [
            'charset'    => 'utf-8',
            'autoescape' => 'html',
            'cache'      => false,
        ]);
        $html = $twig->render('piani_turno/pdf.twig', $viewModel);

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A3-L',
            'margin_left'   => 10,
            'margin_right'  => 10,
            'margin_top'    => 18,
            'margin_bottom' => 14,
            'margin_header' => 9,
            'margin_footer' => 8,
            'tempDir'       => APP_ROOT . '/storage/tmp/mpdf',
        ]);

        $titolo = (string) ($viewModel['headerTitolo'] ?? 'Piano turni');
        $mpdf->SetHTMLHeader(
            '<div style="font-size:9pt;color:#333;border-bottom:0.5pt solid #999;padding-bottom:2pt;">'
            . htmlspecialchars($titolo, ENT_QUOTES, 'UTF-8')
            . '</div>'
        );
        $mpdf->SetHTMLFooter(
            '<div style="font-size:7pt;color:#777;text-align:right;border-top:0.5pt solid #ccc;padding-top:2pt;">'
            . '{PAGENO} / {nbpg}</div>'
        );

        $mpdf->WriteHTML($html);
        return (string) $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * Regole CSS `.tt-bg-{id}{background-color:#hex}` per i colori dei tipi turno,
     * da incorporare nel <style> del documento (non come `style=` inline sulle
     * celle, vedi §10). Solo colori #RRGGBB validi (no injection), come AssetController.
     */
    private function coloriCss(): string
    {
        $css = '';
        foreach ($this->tipiTurno->listOrdered() as $t) {
            $colore = (string) ($t['colore'] ?? '');
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $colore) !== 1) {
                continue;
            }
            $id = (int) $t['id'];
            $css .= ".tt-bg-{$id}{background-color:{$colore};}\n";
        }
        return $css;
    }

    /**
     * @param array<string,mixed> $piano
     */
    private function headerTitolo(array $piano, string $labelMese): string
    {
        $titolo = 'Piano turni — ' . (string) ($piano['setting_nome'] ?? '') . ' — ' . $labelMese;
        if (!empty($piano['pubblicato_il'])) {
            $titolo .= ' — pubblicato il '
                . (new \DateTimeImmutable((string) $piano['pubblicato_il']))->format('d/m/Y');
        }
        return $titolo;
    }
}
