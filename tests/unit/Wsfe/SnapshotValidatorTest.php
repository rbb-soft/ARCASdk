<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsfe;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Wsfe\Comprobante;
use Rbbsoft\ArcaSdk\Wsfe\SnapshotValidator;
use Rbbsoft\ArcaSdk\Wsfe\TiposComprobante;

/**
 * Tests unitarios del SnapshotValidator (Phase 7).
 *
 * Verifica:
 *  1. Snapshot valido -> Comprobante reconstruido.
 *  2. request_json corrupto -> IdempotencyStateException.
 *  3. Schema version invalida -> IdempotencyStateException.
 *  4. Cross-check punto_venta inconsistente -> IdempotencyStateException.
 *  5. Cross-check cbte_tipo inconsistente -> IdempotencyStateException.
 *  6. Snapshot sin campos requeridos -> IdempotencyStateException.
 *  7. NC con cbtes_asoc de tipo incompatible -> IdempotencyStateException.
 *  8. cbteFch en formato inesperado -> IdempotencyStateException.
 *  9. cbte_nro_enviado <= 0 -> IdempotencyStateException.
 *  10. Concepto 2/3 sin servicio -> IdempotencyStateException.
 */
final class SnapshotValidatorTest extends TestCase
{
    /**
     * Construye un snapshot canonico valido para Factura C, pv=1, $100.
     * Devuelve el JSON y la "fila" minima.
     *
     * @return array{json:string, row:array<string, mixed>}
     */
    private function makeValidSnapshotFacturaC(string $cbteFchYmd = '2025-06-15', int $cbteNro = 50): array
    {
        $snapshot = [
            'cbte_tipo'   => TiposComprobante::FACTURA_C,
            'punto_venta' => 1,
            'concepto'    => 1,
            'receptor'    => [
                'documento_tipo' => 80,
                'documento_nro'  => '20999999999',
                'condicion_iva'  => 'RI',
            ],
            'moneda' => [
                'id'    => 'PES',
                'cotiz' => '1.0000',
            ],
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            'importe_no_gravado'    => '0.00',
            'importe_exento'        => '0.00',
            'importe_otros_tributos' => '0.00',
        ];
        $row = [
            'punto_venta'      => 1,
            'cbte_tipo'        => TiposComprobante::FACTURA_C,
            'cbte_nro_enviado' => $cbteNro,
            'cbte_fch_enviado' => $cbteFchYmd,
        ];
        return ['json' => json_encode($snapshot), 'row' => $row];
    }

    public function test_snapshot_valido_retorna_comprobante_reconstruido(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $comp = SnapshotValidator::validateAndReconstruct($s['json'], $s['row']);

        $this->assertInstanceOf(Comprobante::class, $comp);
        $this->assertSame(TiposComprobante::FACTURA_C, $comp->cbteTipo);
        $this->assertSame(1, $comp->puntoVenta);
        $this->assertSame(1, $comp->concepto);
        $this->assertSame(80, $comp->receptorDocumentoTipo);
        $this->assertSame('20999999999', $comp->receptorDocumentoNro);
        $this->assertSame('PES', $comp->monId);
        $this->assertCount(1, $comp->items);
        $this->assertSame('100.00', $comp->items[0]['importe_gravado']);
    }

    public function test_json_invalido_lanza_IdempotencyStateException(): void
    {
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/JSON invalido|snapshot corrupto/');
        SnapshotValidator::validateAndReconstruct(
            '{esto no es json valido',
            ['punto_venta' => 1, 'cbte_tipo' => 11, 'cbte_nro_enviado' => 50, 'cbte_fch_enviado' => '2025-06-15']
        );
    }

    public function test_json_no_objeto_lanza_IdempotencyStateException(): void
    {
        $this->expectException(IdempotencyStateException::class);
        SnapshotValidator::validateAndReconstruct(
            '[1,2,3]',
            ['punto_venta' => 1, 'cbte_tipo' => 11, 'cbte_nro_enviado' => 50, 'cbte_fch_enviado' => '2025-06-15']
        );
    }

    public function test_schema_version_invalida_lanza_IdempotencyStateException(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $data = json_decode($s['json'], true);
        $data['schema_version'] = 99;
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/schema_version/');
        SnapshotValidator::validateAndReconstruct(
            json_encode($data),
            $s['row']
        );
    }

    public function test_schema_version_1_es_aceptada(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $data = json_decode($s['json'], true);
        $data['schema_version'] = 1;
        $comp = SnapshotValidator::validateAndReconstruct(
            json_encode($data),
            $s['row']
        );
        $this->assertSame(TiposComprobante::FACTURA_C, $comp->cbteTipo);
    }

    public function test_punto_venta_inconsistente_lanza_IdempotencyStateException(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $s['row']['punto_venta'] = 2; // distinto al del JSON
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/punto_venta.*!=.*columna/');
        SnapshotValidator::validateAndReconstruct($s['json'], $s['row']);
    }

    public function test_cbte_tipo_inconsistente_lanza_IdempotencyStateException(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $s['row']['cbte_tipo'] = 6; // distinto al del JSON (que es 11)
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/cbte_tipo.*!=.*columna/');
        SnapshotValidator::validateAndReconstruct($s['json'], $s['row']);
    }

    public function test_falta_receptor_lanza_IdempotencyStateException(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $data = json_decode($s['json'], true);
        unset($data['receptor']);
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/receptor/');
        SnapshotValidator::validateAndReconstruct(
            json_encode($data),
            $s['row']
        );
    }

    public function test_falta_items_lanza_IdempotencyStateException(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $data = json_decode($s['json'], true);
        unset($data['items']);
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/items/');
        SnapshotValidator::validateAndReconstruct(
            json_encode($data),
            $s['row']
        );
    }

    public function test_cbteFch_formato_inesperado_lanza_IdempotencyStateException(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $s['row']['cbte_fch_enviado'] = '2026-13-45';
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/cbteFch|2026-13-45/');
        SnapshotValidator::validateAndReconstruct($s['json'], $s['row']);
    }

    public function test_cbteFch_null_lanza_IdempotencyStateException(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $s['row']['cbte_fch_enviado'] = null;
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/cbte_fch_enviado.*NULL|cbteFch/');
        SnapshotValidator::validateAndReconstruct($s['json'], $s['row']);
    }

    public function test_cbteFch_invalido_como_date_lanza_IdempotencyStateException(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $s['row']['cbte_fch_enviado'] = '2025-02-30'; // Feb 30 no existe
        $this->expectException(IdempotencyStateException::class);
        SnapshotValidator::validateAndReconstruct($s['json'], $s['row']);
    }

    public function test_cbte_nro_invalido_lanza_IdempotencyStateException(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $s['row']['cbte_nro_enviado'] = 0;
        $this->expectException(IdempotencyStateException::class);
        SnapshotValidator::validateAndReconstruct($s['json'], $s['row']);

        $s['row']['cbte_nro_enviado'] = -5;
        $this->expectException(IdempotencyStateException::class);
        SnapshotValidator::validateAndReconstruct($s['json'], $s['row']);

        $s['row']['cbte_nro_enviado'] = null;
        $this->expectException(IdempotencyStateException::class);
        SnapshotValidator::validateAndReconstruct($s['json'], $s['row']);
    }

    public function test_concepto_2_sin_servicio_lanza_IdempotencyStateException(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $data = json_decode($s['json'], true);
        $data['concepto'] = 2;
        // No seteamos servicio.
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/servicio/');
        SnapshotValidator::validateAndReconstruct(
            json_encode($data),
            $s['row']
        );
    }

    public function test_NC_sin_cbtes_asoc_lanza_IdempotencyStateException(): void
    {
        // Construimos un snapshot de NC_C (tipo 13).
        $snapshot = [
            'cbte_tipo'   => TiposComprobante::NOTA_CREDITO_C,
            'punto_venta' => 1,
            'concepto'    => 1,
            'receptor'    => [
                'documento_tipo' => 80,
                'documento_nro'  => '20999999999',
                'condicion_iva'  => 'RI',
            ],
            'moneda' => [
                'id'    => 'PES',
                'cotiz' => '1.0000',
            ],
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            // Falta cbtes_asoc.
        ];
        $row = [
            'punto_venta'      => 1,
            'cbte_tipo'        => TiposComprobante::NOTA_CREDITO_C,
            'cbte_nro_enviado' => 50,
            'cbte_fch_enviado' => '2025-06-15',
        ];
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/cbtes_asoc/');
        SnapshotValidator::validateAndReconstruct(json_encode($snapshot), $row);
    }

    public function test_NC_con_cbtes_asoc_incompatible_lanza_IdempotencyStateException(): void
    {
        // NC_B (tipo 8) requiere cbtes_asoc.tipo = 6 (Factura B).
        // Le pasamos tipo = 1 (Factura A), incompatible.
        $snapshot = [
            'cbte_tipo'   => TiposComprobante::NOTA_CREDITO_B,
            'punto_venta' => 1,
            'concepto'    => 1,
            'receptor'    => [
                'documento_tipo' => 80,
                'documento_nro'  => '20999999999',
                'condicion_iva'  => 'RI',
            ],
            'moneda' => [
                'id'    => 'PES',
                'cotiz' => '1.0000',
            ],
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            'cbtes_asoc' => [
                ['Tipo' => 1, 'PtoVta' => 1, 'Nro' => 100], // Incompatible
            ],
        ];
        $row = [
            'punto_venta'      => 1,
            'cbte_tipo'        => TiposComprobante::NOTA_CREDITO_B,
            'cbte_nro_enviado' => 50,
            'cbte_fch_enviado' => '2025-06-15',
        ];
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/cbtes_asoc.*incompatible/');
        SnapshotValidator::validateAndReconstruct(json_encode($snapshot), $row);
    }

    public function test_NC_con_cbtes_asoc_compatible_pasa(): void
    {
        $snapshot = [
            'cbte_tipo'   => TiposComprobante::NOTA_CREDITO_B,
            'punto_venta' => 1,
            'concepto'    => 1,
            'receptor'    => [
                'documento_tipo' => 80,
                'documento_nro'  => '20999999999',
                'condicion_iva'  => 'RI',
            ],
            'moneda' => [
                'id'    => 'PES',
                'cotiz' => '1.0000',
            ],
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            'cbtes_asoc' => [
                ['Tipo' => 6, 'PtoVta' => 1, 'Nro' => 100], // Compatible
            ],
        ];
        $row = [
            'punto_venta'      => 1,
            'cbte_tipo'        => TiposComprobante::NOTA_CREDITO_B,
            'cbte_nro_enviado' => 50,
            'cbte_fch_enviado' => '2025-06-15',
        ];
        $comp = SnapshotValidator::validateAndReconstruct(json_encode($snapshot), $row);
        $this->assertCount(1, $comp->cbtesAsoc);
        $this->assertSame(6, $comp->cbtesAsoc[0]['Tipo']);
        $this->assertSame(1, $comp->cbtesAsoc[0]['PtoVta']);
        $this->assertSame(100, $comp->cbtesAsoc[0]['Nro']);
    }

    public function test_cbteFch_en_formato_DateTime_se_acepta(): void
    {
        $s = $this->makeValidSnapshotFacturaC();
        $s['row']['cbte_fch_enviado'] = new DateTimeImmutable('2025-06-15');
        $comp = SnapshotValidator::validateAndReconstruct($s['json'], $s['row']);
        $this->assertInstanceOf(Comprobante::class, $comp);
    }
}
