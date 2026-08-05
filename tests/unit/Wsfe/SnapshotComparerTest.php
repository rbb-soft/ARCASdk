<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsfe;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Exceptions\CaeSecuestradoException;
use Rbbsoft\ArcaSdk\Wsfe\Comprobante;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteConsultado;
use Rbbsoft\ArcaSdk\Wsfe\SnapshotComparer;
use Rbbsoft\ArcaSdk\Wsfe\TiposComprobante;

/**
 * Tests unitarios del SnapshotComparer (Phase 7).
 *
 * Cubre la matriz de comparacion semantica entre el snapshot
 * reconstruido y la respuesta de FECompConsultar.
 */
final class SnapshotComparerTest extends TestCase
{
    /**
     * Snapshot canonico: Factura C, $100, receptor CUIT 20999999999,
     * 21% IVA, PES, $1.0000 cotiz. Sin AlicIva (Factura C no discrimina).
     */
    private function makeSnapshotFacturaC(int $cbteTipo = 11, int $pv = 1): Comprobante
    {
        return new Comprobante(
            cbteTipo: $cbteTipo,
            puntoVenta: $pv,
            concepto: 1,
            receptorDocumentoTipo: 80,
            receptorDocumentoNro: '20999999999',
            receptorCondicionIva: 'RI',
            monId: 'PES',
            monCotiz: '1.0000',
            items: [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            importeNoGravado: '0.00',
            importeExento: '0.00',
            importeOtrosTributos: '0.00',
        );
    }

    /**
     * Snapshot Factura B con AlicIva discriminado: $100 gravado a 21%
     * (21.00 IVA), receptor 20123456786.
     */
    private function makeSnapshotFacturaB(): Comprobante
    {
        return new Comprobante(
            cbteTipo: TiposComprobante::FACTURA_B,
            puntoVenta: 1,
            concepto: 1,
            receptorDocumentoTipo: 80,
            receptorDocumentoNro: '20123456786',
            receptorCondicionIva: 'RI',
            monId: 'PES',
            monCotiz: '1.0000',
            items: [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            importeNoGravado: '0.00',
            importeExento: '0.00',
            importeOtrosTributos: '0.00',
        );
    }

    /**
     * @param array<int, array{Id:string, BaseImp:string, Importe:string}> $alicIva
     * @param array<int, array{Tipo:int, PtoVta:int, Nro:int}>             $cbtesAsoc
     */
    private function makeConsultado(
        int $cbteTipo = 11,
        int $pv = 1,
        int $cbteNro = 50,
        string $cbteFch = '20250615',
        string $cae = '12345678901250',
        string $caeFchVto = '20250715',
        int $docTipo = 80,
        string $docNro = '20999999999',
        string $impTotal = '100.00',
        string $impNeto = '100.00',
        string $impIva = '0.00',
        string $impTrib = '0.00',
        string $impOpEx = '0.00',
        string $impTotConc = '0.00',
        string $monId = 'PES',
        string $monCotiz = '1.0000',
        array $alicIva = [],
        array $cbtesAsoc = [],
    ): ComprobanteConsultado {
        return new ComprobanteConsultado(
            cbteTipo: $cbteTipo,
            puntoVenta: $pv,
            cbteNro: $cbteNro,
            cbteFch: $cbteFch,
            resultado: 'A',
            cae: $cae,
            caeFchVto: $caeFchVto,
            concepto: 1,
            receptorDocumentoTipo: $docTipo,
            receptorDocumentoNro: $docNro,
            impTotal: $impTotal,
            impNeto: $impNeto,
            impIva: $impIva,
            impTrib: $impTrib,
            impOpEx: $impOpEx,
            impTotConc: $impTotConc,
            monId: $monId,
            monCotiz: $monCotiz,
            alicIva: $alicIva,
            cbtesAsoc: $cbtesAsoc,
        );
    }

    // =================================================================
    // 8: Comparaciones semanticas equivalentes
    // =================================================================

    public function test_datos_equivalentes_con_representaciones_distintas_matchean(): void
    {
        $snap = $this->makeSnapshotFacturaC();
        // ARCA responde con: impTotal "100.00" == "100" (normalizado), 0
        // vs "0.00" son iguales, fechas "20250615" == "2025-06-15".
        $actual = $this->makeConsultado(
            impTotal: '100',
            impNeto: '100',
            impIva: '0.00',
            cbteFch: '2025-06-15', // vs snapshot YYYYMMDD - se normaliza
        );
        SnapshotComparer::compare($snap, $actual, '20250615');
        $this->expectNotToPerformAssertions();
    }

    public function test_cuit_con_y_sin_guiones_matchea(): void
    {
        $snap = new Comprobante(
            cbteTipo: 11, puntoVenta: 1, concepto: 1,
            receptorDocumentoTipo: 80,
            receptorDocumentoNro: '20-12345678-6',
            receptorCondicionIva: 'RI',
            monId: 'PES', monCotiz: '1.0000',
            items: [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        );
        $actual = $this->makeConsultado(docNro: '20123456786');
        SnapshotComparer::compare($snap, $actual, '20250615');
        $this->expectNotToPerformAssertions();
    }

    // =================================================================
    // 9: Diferencias en total
    // =================================================================

    public function test_total_diferente_lanza_CaeSecuestradoException(): void
    {
        $snap = $this->makeSnapshotFacturaC();
        $actual = $this->makeConsultado(impTotal: '121.50');
        $this->expectException(CaeSecuestradoException::class);
        $this->expectExceptionMessageMatches('/snapshot mismatch.*total/');
        SnapshotComparer::compare($snap, $actual, '20250615');
    }

    // =================================================================
    // 10: Diferencia en receptor documento
    // =================================================================

    public function test_receptor_documento_diferente_lanza_CaeSecuestradoException(): void
    {
        $snap = $this->makeSnapshotFacturaC();
        $actual = $this->makeConsultado(docNro: '20111111112');
        $this->expectException(CaeSecuestradoException::class);
        $this->expectExceptionMessageMatches('/receptor_documento_nro/');
        SnapshotComparer::compare($snap, $actual, '20250615');
    }

    // =================================================================
    // 11: Diferencia en AlicIva (Factura B con discrimina IVA)
    // =================================================================

    public function test_alicuota_diferente_lanza_CaeSecuestradoException(): void
    {
        $snap = $this->makeSnapshotFacturaB();
        $actual = $this->makeConsultado(
            cbteTipo: TiposComprobante::FACTURA_B,
            docNro: '20123456786',
            impTotal: '121.00',
            impNeto: '100.00',
            impIva: '21.00',
            alicIva: [
                ['Id' => '4', 'BaseImp' => '100.00', 'Importe' => '10.50'], // 10.5% en vez de 21%
            ],
        );
        $this->expectException(CaeSecuestradoException::class);
        $this->expectExceptionMessageMatches('/AlicIva|alicuota|protocol error|snapshot mismatch/');
        SnapshotComparer::compare($snap, $actual, '20250615');
    }

    public function test_alicuota_21_por_ciento_matchea(): void
    {
        $snap = $this->makeSnapshotFacturaB();
        $actual = $this->makeConsultado(
            cbteTipo: TiposComprobante::FACTURA_B,
            docNro: '20123456786',
            impTotal: '121.00',
            impNeto: '100.00',
            impIva: '21.00',
            alicIva: [
                ['Id' => '5', 'BaseImp' => '100.00', 'Importe' => '21.00'], // 21% Id=5
            ],
        );
        SnapshotComparer::compare($snap, $actual, '20250615');
        $this->expectNotToPerformAssertions();
    }

    public function test_alicuota_10_5_por_ciento_matchea(): void
    {
        $snap = new Comprobante(
            cbteTipo: TiposComprobante::FACTURA_B,
            puntoVenta: 1,
            concepto: 1,
            receptorDocumentoTipo: 80,
            receptorDocumentoNro: '20123456786',
            receptorCondicionIva: 'RI',
            monId: 'PES', monCotiz: '1.0000',
            items: [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '10.5'],
            ],
        );
        $actual = $this->makeConsultado(
            cbteTipo: TiposComprobante::FACTURA_B,
            docNro: '20123456786',
            impTotal: '110.50',
            impNeto: '100.00',
            impIva: '10.50',
            alicIva: [
                ['Id' => '4', 'BaseImp' => '100.00', 'Importe' => '10.50'], // 10.5% Id=4
            ],
        );
        SnapshotComparer::compare($snap, $actual, '20250615');
        $this->expectNotToPerformAssertions();
    }

    // =================================================================
    // 12: Factura C (no discrimina IVA) sin AlicIva
    // =================================================================

    public function test_factura_c_sin_alicIva_matchea(): void
    {
        $snap = $this->makeSnapshotFacturaC();
        // Factura C: ARCA devuelve AlicIva vacio.
        $actual = $this->makeConsultado(
            impTotal: '100.00',
            impNeto: '100.00',
            impIva: '0.00',
        );
        SnapshotComparer::compare($snap, $actual, '20250615');
        $this->expectNotToPerformAssertions();
    }

    // =================================================================
    // 13: NC con cbtes_asoc
    // =================================================================

    public function test_NC_con_cbtes_asoc_matchea(): void
    {
        $snap = new Comprobante(
            cbteTipo: TiposComprobante::NOTA_CREDITO_A,
            puntoVenta: 1,
            concepto: 1,
            receptorDocumentoTipo: 80,
            receptorDocumentoNro: '20999999999',
            receptorCondicionIva: 'RI',
            monId: 'PES', monCotiz: '1.0000',
            items: [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            cbtesAsoc: [
                ['Tipo' => 1, 'PtoVta' => 1, 'Nro' => 50],
            ],
        );
        $actual = $this->makeConsultado(
            cbteTipo: TiposComprobante::NOTA_CREDITO_A,
            impTotal: '121.00',
            impNeto: '100.00',
            impIva: '21.00',
            cbtesAsoc: [
                ['Tipo' => 1, 'PtoVta' => 1, 'Nro' => 50],
            ],
            alicIva: [
                ['Id' => '5', 'BaseImp' => '100.00', 'Importe' => '21.00'],
            ],
        );
        SnapshotComparer::compare($snap, $actual, '20250615');
        $this->expectNotToPerformAssertions();
    }

    public function test_NC_con_cbtes_asoc_diferente_lanza_CaeSecuestradoException(): void
    {
        $snap = new Comprobante(
            cbteTipo: TiposComprobante::NOTA_CREDITO_A,
            puntoVenta: 1,
            concepto: 1,
            receptorDocumentoTipo: 80,
            receptorDocumentoNro: '20999999999',
            receptorCondicionIva: 'RI',
            monId: 'PES', monCotiz: '1.0000',
            items: [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            cbtesAsoc: [
                ['Tipo' => 1, 'PtoVta' => 1, 'Nro' => 50],
            ],
        );
        $actual = $this->makeConsultado(
            cbteTipo: TiposComprobante::NOTA_CREDITO_A,
            impTotal: '121.00',
            impNeto: '100.00',
            impIva: '21.00',
            cbtesAsoc: [
                ['Tipo' => 1, 'PtoVta' => 1, 'Nro' => 99], // Diferente nro
            ],
            alicIva: [
                ['Id' => '5', 'BaseImp' => '100.00', 'Importe' => '21.00'],
            ],
        );
        $this->expectException(CaeSecuestradoException::class);
        $this->expectExceptionMessageMatches('/cbtes_asoc/');
        SnapshotComparer::compare($snap, $actual, '20250615');
    }

    public function test_NC_con_snapshot_que_requiere_asoc_pero_arca_no_devuelve_lanza_CaeSecuestradoException(): void
    {
        $snap = new Comprobante(
            cbteTipo: TiposComprobante::NOTA_CREDITO_A,
            puntoVenta: 1,
            concepto: 1,
            receptorDocumentoTipo: 80,
            receptorDocumentoNro: '20999999999',
            receptorCondicionIva: 'RI',
            monId: 'PES', monCotiz: '1.0000',
            items: [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            cbtesAsoc: [
                ['Tipo' => 1, 'PtoVta' => 1, 'Nro' => 50],
            ],
        );
        $actual = $this->makeConsultado(
            cbteTipo: TiposComprobante::NOTA_CREDITO_A,
            impTotal: '121.00',
            impNeto: '100.00',
            impIva: '21.00',
            cbtesAsoc: [], // ARCA no devolvio cbtes_asoc
            alicIva: [
                ['Id' => '5', 'BaseImp' => '100.00', 'Importe' => '21.00'],
            ],
        );
        $this->expectException(CaeSecuestradoException::class);
        $this->expectExceptionMessageMatches('/protocol error.*CbtesAsoc/');
        SnapshotComparer::compare($snap, $actual, '20250615');
    }

    // =================================================================
    // 14: Snapshot requiere AlicIva que ARCA no devolvio
    // =================================================================

    public function test_snapshot_requiere_alicIva_que_arca_no_devuelve_lanza_protocol_error(): void
    {
        $snap = $this->makeSnapshotFacturaB();
        $actual = $this->makeConsultado(
            cbteTipo: TiposComprobante::FACTURA_B,
            docNro: '20123456786',
            impTotal: '121.00',
            impNeto: '100.00',
            impIva: '21.00',
            // Sin AlicIva (ARCA no devolvio el detalle).
            alicIva: [],
        );
        $this->expectException(CaeSecuestradoException::class);
        $this->expectExceptionMessageMatches('/protocol error.*AlicIva|alicuotas/');
        SnapshotComparer::compare($snap, $actual, '20250615');
    }

    // =================================================================
    // 15: ARCA con AlicIva adicional no incluido en snapshot
    // =================================================================

    public function test_arca_con_alicIva_adicional_solo_lo_que_esta_en_snapshot_se_compara(): void
    {
        // Snapshot: 100 gravado a 21%. ARCA: ademas, un AlicIva 0%
        // (que el snapshot no incluye). Comparacion: el snapshot no
        // exige AlicIva de 0%, asi que debe matchear.
        $snap = $this->makeSnapshotFacturaB();
        $actual = $this->makeConsultado(
            cbteTipo: TiposComprobante::FACTURA_B,
            docNro: '20123456786',
            impTotal: '121.00',
            impNeto: '100.00',
            impIva: '21.00',
            alicIva: [
                ['Id' => '5', 'BaseImp' => '100.00', 'Importe' => '21.00'],
                ['Id' => '3', 'BaseImp' => '0.00', 'Importe' => '0.00'],
            ],
        );
        SnapshotComparer::compare($snap, $actual, '20250615');
        $this->expectNotToPerformAssertions();
    }

    // =================================================================
    // Extras: diferencias varias
    // =================================================================

    public function test_fecha_diferente_lanza_CaeSecuestradoException(): void
    {
        $snap = $this->makeSnapshotFacturaC();
        $actual = $this->makeConsultado(cbteFch: '20250616');
        $this->expectException(CaeSecuestradoException::class);
        $this->expectExceptionMessageMatches('/cbte_fch/');
        SnapshotComparer::compare($snap, $actual, '20250615');
    }

    public function test_punto_venta_diferente_lanza_CaeSecuestradoException(): void
    {
        $snap = $this->makeSnapshotFacturaC(pv: 1);
        $actual = $this->makeConsultado(pv: 2);
        $this->expectException(CaeSecuestradoException::class);
        $this->expectExceptionMessageMatches('/punto_venta/');
        SnapshotComparer::compare($snap, $actual, '20250615');
    }

    public function test_tipo_diferente_lanza_CaeSecuestradoException(): void
    {
        $snap = $this->makeSnapshotFacturaC(cbteTipo: 11);
        $actual = $this->makeConsultado(cbteTipo: 13);
        $this->expectException(CaeSecuestradoException::class);
        $this->expectExceptionMessageMatches('/cbte_tipo/');
        SnapshotComparer::compare($snap, $actual, '20250615');
    }

    public function test_moneda_cotiz_diferente_lanza_CaeSecuestradoException(): void
    {
        $snap = $this->makeSnapshotFacturaC();
        $actual = $this->makeConsultado(monId: 'DOL', monCotiz: '500.0000');
        $this->expectException(CaeSecuestradoException::class);
        $this->expectExceptionMessageMatches('/mon_id|mon_cotiz/');
        SnapshotComparer::compare($snap, $actual, '20250615');
    }

    public function test_dni_preserva_ceros_a_la_izquierda(): void
    {
        // El snapshot trae DNI "01234567" (con cero a la izquierda),
        // ARCA devuelve "01234567". Matchean literal.
        $snap = new Comprobante(
            cbteTipo: TiposComprobante::FACTURA_C,
            puntoVenta: 1,
            concepto: 1,
            receptorDocumentoTipo: 96, // DNI
            receptorDocumentoNro: '01234567',
            receptorCondicionIva: 'CF',
            monId: 'PES', monCotiz: '1.0000',
            items: [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        );
        $actual = $this->makeConsultado(docTipo: 96, docNro: '01234567');
        SnapshotComparer::compare($snap, $actual, '20250615');
        $this->expectNotToPerformAssertions();
    }

    public function test_dni_distinto_por_un_digito_lanza_mismatch(): void
    {
        $snap = new Comprobante(
            cbteTipo: TiposComprobante::FACTURA_C,
            puntoVenta: 1,
            concepto: 1,
            receptorDocumentoTipo: 96,
            receptorDocumentoNro: '01234567',
            receptorCondicionIva: 'CF',
            monId: 'PES', monCotiz: '1.0000',
            items: [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        );
        $actual = $this->makeConsultado(docTipo: 96, docNro: '01234568');
        $this->expectException(CaeSecuestradoException::class);
        SnapshotComparer::compare($snap, $actual, '20250615');
    }
}
