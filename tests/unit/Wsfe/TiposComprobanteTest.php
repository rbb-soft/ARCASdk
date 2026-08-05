<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsfe;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Wsfe\TiposComprobante;

final class TiposComprobanteTest extends TestCase
{
    public function test_tipos_soportados(): void
    {
        $this->assertTrue(TiposComprobante::esValido(TiposComprobante::FACTURA_A));
        $this->assertTrue(TiposComprobante::esValido(TiposComprobante::FACTURA_B));
        $this->assertTrue(TiposComprobante::esValido(TiposComprobante::FACTURA_C));
        $this->assertTrue(TiposComprobante::esValido(TiposComprobante::NOTA_CREDITO_C));
        $this->assertFalse(TiposComprobante::esValido(99));
        $this->assertFalse(TiposComprobante::esValido(0));
    }

    public function test_discrimina_iva(): void
    {
        $this->assertTrue(TiposComprobante::discriminaIva(TiposComprobante::FACTURA_A));
        $this->assertTrue(TiposComprobante::discriminaIva(TiposComprobante::FACTURA_B));
        $this->assertTrue(TiposComprobante::discriminaIva(TiposComprobante::NOTA_CREDITO_A));
        $this->assertFalse(TiposComprobante::discriminaIva(TiposComprobante::FACTURA_C));
        $this->assertFalse(TiposComprobante::discriminaIva(TiposComprobante::FACTURA_M));
        $this->assertFalse(TiposComprobante::discriminaIva(TiposComprobante::NOTA_CREDITO_C));
    }

    public function test_es_nota_credito(): void
    {
        $this->assertTrue(TiposComprobante::esNotaCredito(TiposComprobante::NOTA_CREDITO_A));
        $this->assertTrue(TiposComprobante::esNotaCredito(TiposComprobante::NOTA_CREDITO_B));
        $this->assertTrue(TiposComprobante::esNotaCredito(TiposComprobante::NOTA_CREDITO_C));
        $this->assertTrue(TiposComprobante::esNotaCredito(TiposComprobante::NOTA_CREDITO_M));
        $this->assertFalse(TiposComprobante::esNotaCredito(TiposComprobante::FACTURA_A));
        $this->assertFalse(TiposComprobante::esNotaCredito(TiposComprobante::FACTURA_C));
        $this->assertFalse(TiposComprobante::esNotaCredito(TiposComprobante::NOTA_DEBITO_B));
    }

    public function test_requiere_cuit(): void
    {
        $this->assertTrue(TiposComprobante::requiereCuit(TiposComprobante::FACTURA_A));
        $this->assertTrue(TiposComprobante::requiereCuit(TiposComprobante::FACTURA_M));
        $this->assertFalse(TiposComprobante::requiereCuit(TiposComprobante::FACTURA_B));
        $this->assertFalse(TiposComprobante::requiereCuit(TiposComprobante::FACTURA_C));
    }

    public function test_tipo_asoc_esperado_para_nc(): void
    {
        $this->assertSame(TiposComprobante::FACTURA_A, TiposComprobante::tipoAsocEsperadoParaNotaCredito(TiposComprobante::NOTA_CREDITO_A));
        $this->assertSame(TiposComprobante::FACTURA_B, TiposComprobante::tipoAsocEsperadoParaNotaCredito(TiposComprobante::NOTA_CREDITO_B));
        $this->assertSame(TiposComprobante::FACTURA_C, TiposComprobante::tipoAsocEsperadoParaNotaCredito(TiposComprobante::NOTA_CREDITO_C));
        $this->assertNull(TiposComprobante::tipoAsocEsperadoParaNotaCredito(TiposComprobante::FACTURA_B));
    }
}
