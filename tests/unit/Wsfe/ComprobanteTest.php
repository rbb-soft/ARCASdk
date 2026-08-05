<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsfe;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Wsfe\Comprobante;
use Rbbsoft\ArcaSdk\Wsfe\TiposComprobante;

final class ComprobanteTest extends TestCase
{
    public function test_factura_b_minima_valida(): void
    {
        $c = Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);

        $this->assertSame(TiposComprobante::FACTURA_B, $c->cbteTipo);
        $this->assertSame(1, $c->puntoVenta);
        $this->assertSame('PES', $c->monId);
        $this->assertSame('1.0000', $c->monCotiz);
    }

    public function test_punto_venta_default_se_aplica_si_no_viene_en_data(): void
    {
        $c = Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 5, cbteTipo: TiposComprobante::FACTURA_B);

        $this->assertSame(5, $c->puntoVenta);
    }

    public function test_punto_venta_en_data_gana_sobre_default(): void
    {
        $c = Comprobante::fromArray([
            'punto_venta' => 3,
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 5, cbteTipo: TiposComprobante::FACTURA_B);

        $this->assertSame(3, $c->puntoVenta);
    }

    public function test_factura_a_requiere_cuit(): void
    {
        $this->expectException(ValidationException::class);
        Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 96, // DNI
            'receptor_documento_nro'  => '12345678',
            'receptor_condicion_iva'  => 'CF',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_A);
    }

    public function test_factura_b_acepta_dni(): void
    {
        $c = Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 96,
            'receptor_documento_nro'  => '12345678',
            'receptor_condicion_iva'  => 'CF',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);

        $this->assertSame(96, $c->receptorDocumentoTipo);
    }

    public function test_claves_desconocidas_rechazadas(): void
    {
        $this->expectException(ValidationException::class);
        Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
            'campo_inventado' => 'boom',
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
    }

    public function test_concepto_servicios_requiere_fechas(): void
    {
        $this->expectException(ValidationException::class);
        Comprobante::fromArray([
            'concepto'    => 2,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
    }

    public function test_concepto_servicios_valido(): void
    {
        $c = Comprobante::fromArray([
            'concepto'    => 2,
            'servicio_desde' => '20260101',
            'servicio_hasta' => '20260131',
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);

        $this->assertSame(20260101, $c->servicioDesde);
        $this->assertSame(20260131, $c->servicioHasta);
    }

    public function test_nota_credito_b_sin_asoc_rechaza(): void
    {
        $this->expectException(ValidationException::class);
        Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::NOTA_CREDITO_B);
    }

    public function test_nota_credito_b_con_asoc_incompatible_rechaza(): void
    {
        $this->expectException(ValidationException::class);
        Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
            'cbtes_asoc' => [
                ['tipo' => TiposComprobante::FACTURA_A, 'punto_venta' => 1, 'nro' => 100], // A != B
            ],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::NOTA_CREDITO_B);
    }

    public function test_nota_credito_b_con_asoc_compatible(): void
    {
        $c = Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
            'cbtes_asoc' => [
                ['tipo' => TiposComprobante::FACTURA_B, 'punto_venta' => 1, 'nro' => 100],
            ],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::NOTA_CREDITO_B);

        $this->assertCount(1, $c->cbtesAsoc);
        $this->assertSame(6, $c->cbtesAsoc[0]['Tipo']);
    }

    public function test_moneda_extranjera_cotiz_positiva(): void
    {
        $c = Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'mon_id'      => 'USD',
            'mon_cotiz'   => '1234.56',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);

        $this->assertSame('USD', $c->monId);
        $this->assertSame('1234.5600', $c->monCotiz);
    }

    public function test_mon_cotiz_cero_o_negativo_rechaza(): void
    {
        $this->expectException(ValidationException::class);
        Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'mon_id'      => 'USD',
            'mon_cotiz'   => '0',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
    }

    public function test_fingerprint_mismo_payload_mismo_hash(): void
    {
        $a = Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [
                ['importe_gravado' => '50.00', 'alicuota_iva' => '21'],
                ['importe_gravado' => '50.00', 'alicuota_iva' => '10.5'],
            ],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);

        $b = Comprobante::fromArray([
            'items' => [
                ['importe_gravado' => '50.00', 'alicuota_iva' => '10.5'],
                ['importe_gravado' => '50.00', 'alicuota_iva' => '21'],
            ],
            'receptor_condicion_iva'  => 'RI',
            'receptor_documento_nro'  => '20123456786',
            'receptor_documento_tipo' => 80,
            'concepto'    => 1,
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);

        $this->assertSame($a->fingerprint(), $b->fingerprint(), 'orden de claves distinto debe dar mismo fingerprint');
        $this->assertSame(strlen($a->fingerprint()), 64, 'fingerprint es SHA-256 hex (64 chars)');
    }

    public function test_fingerprint_payload_distinto_hash_distinto(): void
    {
        $a = Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);

        $b = Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '200.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_canonical_json_no_incluye_cbte_nro_ni_cbte_fch(): void
    {
        $c = Comprobante::fromArray([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);

        $json = $c->canonicalJson();
        $decoded = json_decode($json, true);
        $this->assertArrayNotHasKey('cbte_nro', $decoded);
        $this->assertArrayNotHasKey('CbteNro', $decoded);
        $this->assertArrayNotHasKey('cbte_fch', $decoded);
        $this->assertArrayNotHasKey('CbteFch', $decoded);
    }
}
