<?php
declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\PianoNonStampabileException;
use App\Services\PianoPdfService;
use PHPUnit\Framework\TestCase;

/**
 * Test della logica pura di PianoPdfService (sessione 8, spec-pdf-piano-turni §9.1).
 *
 * Coprono guardia di stato, raggruppamento operatori e rendering HTML->PDF SENZA
 * toccare il DB: i metodi sotto test sono statici, così non serve costruire i
 * Model (che aprirebbero la connessione). `genera(int)` — l'orchestrazione che
 * legge il DB — e i test funzionali HTTP (§9.2) richiedono un harness di
 * integrazione non ancora presente nel progetto.
 */
final class PianoPdfServiceTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Guardia di stato (§9.1: piano inesistente / bozza / archiviato)
    // ---------------------------------------------------------------------

    public function test_assertStampabile_lancia_se_piano_inesistente(): void
    {
        $this->expectException(PianoNonStampabileException::class);
        PianoPdfService::assertStampabile(null);
    }

    public function test_assertStampabile_lancia_se_bozza(): void
    {
        $this->expectException(PianoNonStampabileException::class);
        PianoPdfService::assertStampabile(['stato' => 'bozza']);
    }

    public function test_assertStampabile_lancia_se_archiviato(): void
    {
        $this->expectException(PianoNonStampabileException::class);
        PianoPdfService::assertStampabile(['stato' => 'archiviato']);
    }

    public function test_assertStampabile_passa_se_pubblicato(): void
    {
        $this->expectNotToPerformAssertions();
        PianoPdfService::assertStampabile(['stato' => 'pubblicato']);
    }

    // ---------------------------------------------------------------------
    // Raggruppamento (§9.1: ordine gruppi, ordine alfabetico, nascosti, Altri)
    // ---------------------------------------------------------------------

    public function test_raggruppa_ordine_infermieri_oss_coordinatrice(): void
    {
        $ops = [
            $this->op(1, 'Galli', 'Eva', 'coordinatore'),
            $this->op(2, 'Neri', 'Ugo', 'oss'),
            $this->op(3, 'Verdi', 'Anna', 'infermiere'),
        ];
        $chiavi = array_map(static fn ($g) => $g['chiave'], PianoPdfService::raggruppa($ops, []));
        self::assertSame(['infermiere', 'oss', 'coordinatore'], $chiavi);
    }

    public function test_raggruppa_ordine_alfabetico_per_cognome_poi_nome(): void
    {
        $ops = [
            $this->op(1, 'Rossi', 'Maria', 'infermiere'),
            $this->op(2, 'Bianchi', 'Lia', 'infermiere'),
            $this->op(3, 'Rossi', 'Anna', 'infermiere'),
        ];
        $gruppi = PianoPdfService::raggruppa($ops, []);
        $cognomiNomi = array_map(
            static fn ($o) => $o['operatore_cognome'] . ' ' . $o['operatore_nome'],
            $gruppi[0]['operatori'],
        );
        self::assertSame(['Bianchi Lia', 'Rossi Anna', 'Rossi Maria'], $cognomiNomi);
    }

    public function test_raggruppa_esclude_operatori_nascosti(): void
    {
        $ops = [
            $this->op(1, 'Verdi', 'Anna', 'infermiere'),
            $this->op(2, 'Rossi', 'Mara', 'infermiere'), // nascosta: maternità intero mese
        ];
        $gruppi = PianoPdfService::raggruppa($ops, [2]);
        $ids = array_map(static fn ($o) => $o['id_operatore'], $gruppi[0]['operatori']);
        self::assertSame([1], $ids);
    }

    public function test_raggruppa_categoria_non_riconosciuta_finisce_in_altri(): void
    {
        $ops = [
            $this->op(1, 'Verdi', 'Anna', 'infermiere'),
            $this->op(2, 'Mah', 'Ziad', ''),          // categoria_gruppo vuoto
            $this->op(3, 'Blu', 'Ivo', 'volontario'), // valore fuori enum
        ];
        $gruppi = PianoPdfService::raggruppa($ops, []);
        $altri = array_values(array_filter($gruppi, static fn ($g) => $g['chiave'] === 'altro'));
        self::assertCount(1, $altri);
        // Dentro "Altri" vale comunque l'ordine alfabetico per cognome: Blu (3) < Mah (2).
        $ids = array_map(static fn ($o) => $o['id_operatore'], $altri[0]['operatori']);
        self::assertSame([3, 2], $ids);
    }

    public function test_raggruppa_non_emette_gruppi_vuoti(): void
    {
        $ops = [$this->op(1, 'Neri', 'Ugo', 'oss')];
        $gruppi = PianoPdfService::raggruppa($ops, []);
        self::assertCount(1, $gruppi);
        self::assertSame('oss', $gruppi[0]['chiave']);
    }

    // ---------------------------------------------------------------------
    // Rendering (§9.1: bytes non vuoti che iniziano con %PDF-)
    // ---------------------------------------------------------------------

    public function test_render_produce_pdf_valido(): void
    {
        $giorni = [
            ['numero' => 1, 'nome' => 'lun', 'weekend' => false, 'date' => '2026-05-01'],
            ['numero' => 2, 'nome' => 'mar', 'weekend' => false, 'date' => '2026-05-02'],
            ['numero' => 3, 'nome' => 'sab', 'weekend' => true,  'date' => '2026-05-03'],
        ];
        $gruppi = PianoPdfService::raggruppa([
            $this->op(1, 'Verdi', 'Anna', 'infermiere'),
            $this->op(2, 'Neri', 'Ugo', 'oss'),
        ], []);

        $vm = [
            'piano'                => ['setting_nome' => 'Hospice', 'anno' => 2026, 'mese' => 5, 'pubblicato_il' => '2026-05-24 10:00:00'],
            'labelMese'            => 'Maggio 2026',
            'giorni'               => $giorni,
            'gruppi'               => $gruppi,
            'turniByOpData'        => [1 => ['2026-05-01' => ['id_tipo_turno' => 1, 'tipo_codice' => 'M', 'tipo_is_assenza' => 0]]],
            'crossSettingByOpData' => [2 => ['2026-05-02' => ['id_tipo_turno' => 3, 'tipo_codice' => 'N']]],
            'assenzeByOp'          => [],
            'coloriCss'            => ".tt-bg-1{background-color:#8FF0A4;}\n.tt-bg-3{background-color:#FFBE6F;}\n",
            'headerTitolo'         => 'Piano turni — Hospice — Maggio 2026',
        ];

        $pdf = PianoPdfService::render($vm);
        self::assertNotSame('', $pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * @return array<string,mixed>
     */
    private function op(int $id, string $cognome, string $nome, string $gruppo): array
    {
        return [
            'id_operatore'      => $id,
            'operatore_cognome' => $cognome,
            'operatore_nome'    => $nome,
            'categoria_nome'    => strtoupper($gruppo),
            'categoria_gruppo'  => $gruppo,
        ];
    }
}
