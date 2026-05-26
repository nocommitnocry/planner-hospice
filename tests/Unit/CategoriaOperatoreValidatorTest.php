<?php
declare(strict_types=1);

namespace App\Tests\Unit;

use App\Validators\CategoriaOperatoreValidator;
use PHPUnit\Framework\TestCase;

/**
 * Test del campo `gruppo_pianificazione` aggiunto al CRUD categorie (sessione 8,
 * migrazione 0011). La validazione è pura: nessun accesso DB.
 */
final class CategoriaOperatoreValidatorTest extends TestCase
{
    public function test_gruppo_valido_accettato(): void
    {
        $res = (new CategoriaOperatoreValidator())->validate([
            'nome'                  => 'Infermiere',
            'gruppo_pianificazione' => 'infermiere',
        ]);
        self::assertTrue($res['ok']);
        self::assertSame('infermiere', $res['data']['gruppo_pianificazione']);
    }

    public function test_gruppo_vuoto_default_altro(): void
    {
        $res = (new CategoriaOperatoreValidator())->validate([
            'nome'                  => 'Volontari',
            'gruppo_pianificazione' => '',
        ]);
        self::assertTrue($res['ok']);
        self::assertSame('altro', $res['data']['gruppo_pianificazione']);
    }

    public function test_gruppo_fuori_enum_rifiutato(): void
    {
        $res = (new CategoriaOperatoreValidator())->validate([
            'nome'                  => 'Strana',
            'gruppo_pianificazione' => 'medico',
        ]);
        self::assertFalse($res['ok']);
        self::assertArrayHasKey('gruppo_pianificazione', $res['errors']);
    }
}
