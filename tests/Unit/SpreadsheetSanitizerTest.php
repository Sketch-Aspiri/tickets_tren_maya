<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SpreadsheetSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpreadsheetSanitizerTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function values(): array
    {
        return [
            'formula' => ['=1+1', "'=1+1"],
            'formula con funcion' => ['=HYPERLINK("http://x","y")', "'=HYPERLINK(\"http://x\",\"y\")"],
            'suma' => ['+SUM(A1)', "'+SUM(A1)"],
            'resta' => ['-2+3', "'-2+3"],
            'arroba' => ['@SUM(1)', "'@SUM(1)"],
            'tabulador' => ["\t=1", "'\t=1"],
            'retorno de carro' => ["\r=1", "'\r=1"],
            'espacios y formula' => ['  =1+1', "'  =1+1"],
            'salto y formula' => ["\n=1+1", "'\n=1+1"],
            'texto normal' => ['Falla en la impresora', 'Falla en la impresora'],
            'signo en medio' => ['a=b', 'a=b'],
            'vacio' => ['', ''],
            'nulo' => [null, ''],
            'numero' => [42, '42'],
            'booleano' => [true, '1'],
            'acentos' => ['Número 1', 'Número 1'],
        ];
    }

    #[DataProvider('values')]
    public function test_cell_neutralizes_formula_triggers(mixed $input, string $expected): void
    {
        $this->assertSame($expected, SpreadsheetSanitizer::cell($input));
    }
}
