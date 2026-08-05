<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsfe;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteResponse;

final class ComprobanteResponseTest extends TestCase
{
    public function test_aprobado_con_cae_y_fchvto(): void
    {
        $r = new ComprobanteResponse(
            resultado: 'A',
            cae: '74001234567890',
            caeFchVto: '20260210',
            cbteNro: 1,
        );
        $this->assertSame('A', $r->resultado);
        $this->assertSame('74001234567890', $r->cae);
        $this->assertSame('20260210', $r->caeFchVto);
        $this->assertSame(1, $r->cbteNro);
        $this->assertTrue($r->isAprobado());
        $this->assertFalse($r->isRechazado());
        $this->assertSame([], $r->observaciones);
    }

    public function test_rechazado_con_observaciones(): void
    {
        $r = new ComprobanteResponse(
            resultado: 'R',
            cae: null,
            caeFchVto: null,
            cbteNro: 42,
            observaciones: [
                ['codigo' => 10016, 'mensaje' => 'balsa de prueba'],
            ],
        );
        $this->assertSame('R', $r->resultado);
        $this->assertNull($r->cae);
        $this->assertNull($r->caeFchVto);
        $this->assertSame(42, $r->cbteNro);
        $this->assertTrue($r->isRechazado());
        $this->assertFalse($r->isAprobado());
        $this->assertCount(1, $r->observaciones);
        $this->assertSame(10016, $r->observaciones[0]['codigo']);
    }

    public function test_aprobado_sin_cae_lanza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ComprobanteResponse(
            resultado: 'A',
            cae: null,
            caeFchVto: '20260210',
            cbteNro: 1,
        );
    }

    public function test_aprobado_sin_cae_fchvto_lanza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ComprobanteResponse(
            resultado: 'A',
            cae: '74001234567890',
            caeFchVto: null,
            cbteNro: 1,
        );
    }

    public function test_aprobado_con_cae_fchvto_malformado_lanza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ComprobanteResponse(
            resultado: 'A',
            cae: '74001234567890',
            caeFchVto: '2026-02-10',
            cbteNro: 1,
        );
    }

    public function test_resultado_invalido_lanza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ComprobanteResponse(
            resultado: 'X',
            cae: null,
            caeFchVto: null,
            cbteNro: 1,
        );
    }

    public function test_rechazado_puede_aceptar_cae_null_sin_validar_formato(): void
    {
        $r = new ComprobanteResponse(
            resultado: 'R',
            cae: null,
            caeFchVto: null,
            cbteNro: 1,
            observaciones: [
                ['codigo' => 10016, 'mensaje' => 'a'],
                ['codigo' => 10017, 'mensaje' => 'b'],
            ],
        );
        $this->assertCount(2, $r->observaciones);
    }

    public function test_observaciones_por_defecto_array_vacio(): void
    {
        $r = new ComprobanteResponse(
            resultado: 'A',
            cae: '74001234567890',
            caeFchVto: '20260210',
            cbteNro: 1,
        );
        $this->assertSame([], $r->observaciones);
    }
}
