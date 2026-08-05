<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsfe;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Wsfe\IvaCalculator;
use Rbbsoft\ArcaSdk\Wsfe\TiposComprobante;

final class IvaCalculatorTest extends TestCase
{
    public function test_factura_b_un_item_iva_21(): void
    {
        $r = IvaCalculator::calcular(
            [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
            TiposComprobante::discriminaIva(TiposComprobante::FACTURA_B),
        );
        $this->assertSame('100.00', $r->netoGravado);
        $this->assertSame(['21' => '21.00'], $r->iva);
        $this->assertSame(['21' => '100.00'], $r->gravadoPorAlicuota);
        $this->assertSame('21.00', $r->ivaTotal);
        $this->assertSame('121.00', $r->total);
    }

    public function test_factura_b_alicuotas_mixtas(): void
    {
        $r = IvaCalculator::calcular(
            [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
                ['importe_gravado' => '50.00',  'alicuota_iva' => '10.5'],
                ['importe_gravado' => '80.00',  'alicuota_iva' => '27'],
            ],
            true,
        );
        $this->assertSame('230.00', $r->netoGravado);
        $this->assertSame('21.00', $r->iva['21']);
        $this->assertSame('5.25', $r->iva['10.5']);
        $this->assertSame('21.60', $r->iva['27']);
        $this->assertSame('47.85', $r->ivaTotal);
        $this->assertSame('277.85', $r->total);

        // Per-alicuota gravado. ARCA exige sum(BaseImp) === ImpNeto,
        // asi que BaseImp no es el netoGravado completo sino el gravado
        // parcial correspondiente a cada alicuota.
        $this->assertSame('100.00', $r->gravadoPorAlicuota['21']);
        $this->assertSame('50.00',  $r->gravadoPorAlicuota['10.5']);
        $this->assertSame('80.00',  $r->gravadoPorAlicuota['27']);
        $sumaBases = bcadd(bcadd('100.00', '50.00', 2), '80.00', 2);
        $this->assertSame(0, bccomp($sumaBases, $r->netoGravado, 2),
            'sum(gravadoPorAlicuota) === netoGravado');
    }

    public function test_alicuota_cero_es_iva_cero(): void
    {
        $r = IvaCalculator::calcular(
            [
                ['importe_gravado' => '50.00', 'alicuota_iva' => '0'],
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            true,
        );
        $this->assertArrayHasKey('0', $r->iva);
        $this->assertSame('0.00', $r->iva['0']);
        $this->assertSame('150.00', $r->netoGravado);
        $this->assertSame('21.00', $r->ivaTotal);
        $this->assertSame('171.00', $r->total);
    }

    public function test_factura_c_no_discrimina_iva(): void
    {
        $r = IvaCalculator::calcular(
            [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
            false, // no discrimina
        );
        $this->assertSame('100.00', $r->netoGravado);
        $this->assertSame([], $r->iva);
        $this->assertSame('0.00', $r->ivaTotal);
        $this->assertSame('100.00', $r->total);
    }

    public function test_concepto_servicios_iva_105(): void
    {
        $r = IvaCalculator::calcular(
            [['importe_gravado' => '1000.00', 'alicuota_iva' => '10.5']],
            true,
        );
        $this->assertSame('105.00', $r->iva['10.5']);
        $this->assertSame('1105.00', $r->total);
    }

    public function test_33_33_por_3_suma_exacta(): void
    {
        $r = IvaCalculator::calcular(
            [
                ['importe_gravado' => '33.33', 'alicuota_iva' => '21'],
                ['importe_gravado' => '33.33', 'alicuota_iva' => '21'],
                ['importe_gravado' => '33.33', 'alicuota_iva' => '21'],
            ],
            true,
        );
        $this->assertSame('99.99', $r->netoGravado);
        // 21% de 99.99 = 20.9979 -> tercer decimal 7 >= 5 -> half-up sube a 21.00
        $this->assertSame('21.00', $r->ivaTotal);
        $this->assertSame('120.99', $r->total);
    }

    public function test_redondeo_iva_sobre_100_iva_21_exacto(): void
    {
        $r = IvaCalculator::calcular(
            [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
            true,
        );
        $this->assertSame('21.00', $r->ivaTotal);
        $this->assertSame('121.00', $r->total);
    }

    public function test_alicuota_normalizada(): void
    {
        $this->assertSame('21', IvaCalculator::normalizarAlicuota('21'));
        $this->assertSame('21', IvaCalculator::normalizarAlicuota('21.0'));
        $this->assertSame('21', IvaCalculator::normalizarAlicuota(21));
        $this->assertSame('10.5', IvaCalculator::normalizarAlicuota('10.5'));
        $this->assertSame('10.5', IvaCalculator::normalizarAlicuota('10,5'));
        $this->assertSame('0', IvaCalculator::normalizarAlicuota('0'));
        $this->assertSame('2.5', IvaCalculator::normalizarAlicuota('2.5'));
    }

    public function test_items_vacio_lanza_excepcion(): void
    {
        $this->expectException(ValidationException::class);
        IvaCalculator::calcular([], true);
    }

    public function test_gravado_negativo_lanza_excepcion(): void
    {
        $this->expectException(ValidationException::class);
        IvaCalculator::calcular(
            [['importe_gravado' => '-1.00', 'alicuota_iva' => '21']],
            true,
        );
    }

    public function test_alicuota_no_soportada_lanza_excepcion(): void
    {
        $this->expectException(ValidationException::class);
        IvaCalculator::calcular(
            [['importe_gravado' => '100.00', 'alicuota_iva' => '15']],
            true,
        );
    }

    public function test_importe_no_gravado_y_exento(): void
    {
        $r = IvaCalculator::calcular(
            [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
            true,
            '50.00', // no gravado
            '25.00', // exento
            '10.00', // otros tributos
        );
        $this->assertSame('100.00', $r->netoGravado);
        $this->assertSame('21.00', $r->ivaTotal);
        $this->assertSame('50.00', $r->importeNoGravado);
        $this->assertSame('25.00', $r->importeExento);
        $this->assertSame('10.00', $r->importeOtrosTrib);
        $this->assertSame('206.00', $r->total);
    }

    public function test_a_alic_iva(): void
    {
        $r = IvaCalculator::calcular(
            [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
                ['importe_gravado' => '50.00',  'alicuota_iva' => '10.5'],
            ],
            true,
        );
        $alic = $r->aAlicIva();
        $this->assertCount(2, $alic);
        // Ordenadas por alicuota canonica ascendente: 10.5, 21
        $this->assertSame('4', $alic[0]['Id']);   // 10.5% -> 4
        $this->assertSame('5.25', $alic[0]['Importe']);
        $this->assertSame('50.00', $alic[0]['BaseImp']); // gravado 10.5%
        $this->assertSame('5', $alic[1]['Id']);   // 21% -> 5
        $this->assertSame('21.00', $alic[1]['Importe']);
        $this->assertSame('100.00', $alic[1]['BaseImp']); // gravado 21%

        // El bug original emitia BaseImp = netoGravado (150.00) en
        // CADA AlicIva. Eso daba sum(BaseImp) = 300.00 != ImpNeto
        // (150.00) -> ARCA rechaza. Verificamos que la suma coincide.
        $sumaBases = bcadd($alic[0]['BaseImp'], $alic[1]['BaseImp'], 2);
        $this->assertSame(0, bccomp($sumaBases, $r->netoGravado, 2),
            'sum(BaseImp) === netoGravado, requerido por ARCA');
    }
}
