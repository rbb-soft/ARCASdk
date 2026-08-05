<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Support\Money;

final class MoneyTest extends TestCase
{
    #[DataProvider('casosRound')]
    public function test_round(string $input, int $scale, string $esperado): void
    {
        $this->assertSame($esperado, Money::round($input, $scale), "round({$input}, {$scale})");
    }

    /** @return array<string, array{string, int, string}> */
    public static function casosRound(): array
    {
        return [
            // identidad
            '0 escala 2'           => ['0', 2, '0.00'],
            '0 escala 0'           => ['0', 0, '0'],
            'entero chico'         => ['1', 2, '1.00'],
            'entero negativo'      => ['-1', 2, '-1.00'],
            'decimal corto'        => ['1.5', 2, '1.50'],
            'decimal exacto'       => ['1.50', 2, '1.50'],

            // half-up canonico
            'half-up .005'         => ['0.005', 2, '0.01'],
            'half-up .015'         => ['0.015', 2, '0.02'],
            'half-up .025'         => ['0.025', 2, '0.03'],
            'half-up .035'         => ['0.035', 2, '0.04'],
            'half-up .045'         => ['0.045', 2, '0.05'],
            'half-up .004'         => ['0.004', 2, '0.00'],
            'half-up .006'         => ['0.006', 2, '0.01'],
            'half-up negativo'     => ['-0.005', 2, '-0.01'],
            'half-up neg .004'     => ['-0.004', 2, '0.00'],
            'half-up neg .006'     => ['-0.006', 2, '-0.01'],

            // sin -0.00
            'no -0.00 desde neg'   => ['-0.001', 2, '0.00'],
            'no -0.00 desde 0.001' => ['0.001', 2, '0.00'],

            // sumas con acarreo
            '0.99 + 0.01'          => ['0.99', 2, '0.99'],
            '0.999'                => ['0.999', 2, '1.00'],
            '0.995'                => ['0.995', 2, '1.00'],
            '1.999'                => ['1.999', 2, '2.00'],
            '99.999'               => ['99.999', 2, '100.00'],

            // escala 0
            'escala 0 .5'          => ['0.5', 0, '1'],
            'escala 0 .4'          => ['0.4', 0, '0'],
            'escala 0 neg .5'      => ['-0.5', 0, '-1'],

            // decimales largos
            '121.005'              => ['121.005', 2, '121.01'],
            '121.004'              => ['121.004', 2, '121.00'],
            '121.006'              => ['121.006', 2, '121.01'],

            // formatos con coma
            'coma decimal'         => ['1,50', 2, '1.50'],
            'punto miles + coma'   => ['1.234,56', 2, '1234.56'],
        ];
    }

    public function test_sum_varios_importes(): void
    {
        $this->assertSame('0.30', Money::sum(['0.1', '0.2']));
        $this->assertSame('100.00', Money::sum(['33.33', '33.33', '33.34']));
        $this->assertSame('0.00', Money::sum([]));
    }

    public function test_normalize_no_convierte_a_float(): void
    {
        // Sin tocar la representacion, normalize debe devolver string.
        $this->assertSame('1.50', Money::normalize('1.50'));
        $this->assertSame('0', Money::normalize(''));
        $this->assertSame('0', Money::normalize('-'));
    }

    public function test_scale_negativo_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::round('1.00', -1);
    }
}
