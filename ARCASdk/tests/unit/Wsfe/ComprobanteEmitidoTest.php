<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsfe;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteEmitido;

/**
 * Tests del DTO {@see ComprobanteEmitido}.
 *
 * Cubre:
 *  - Construccion desde array mergeado tipico (caller real).
 *  - Campos opcionales con defaults sensatos (receptor nulls, items []).
 *  - Validacion de campos obligatorios (cae, cbteNro, etc.).
 *  - Forma snake_case de `asArray()` para compat con callers v0.2.x.
 *  - Payload del QR contra la spec oficial de ARCA v1.
 *  - Omision de campos del receptor cuando son null/0/vacios.
 *  - Roundtrip fromArray/asArray.
 */
final class ComprobanteEmitidoTest extends TestCase
{
    /**
     * CUIT receptor ficticio (con checksum valido) usado en los datos
     * canonicos del comprobante.
     */
    private const REF_CUIT_RECEPTOR = 30912345676;

    /**
     * Datos canonicos del comprobante real emitido en la sesion 2026-08-04
     * (cbte_tipo=11, cbte_nro=4, CAE 86310728945116). Reusado por varios
     * tests para no repetir el mismo array largo.
     *
     * @return array<string, mixed>
     */
    private function dataEmitidoReal(): array
    {
        return [
            'cbte_tipo'                 => 11,
            'cbte_nro'                  => 4,
            'cbte_fch'                  => '2026-08-04',
            'cae'                       => '86310728945116',
            'cae_fch_vto'               => '20260814',
            'monto_total'               => '100.00',
            'monto_neto'                => '100.00',
            'monto_iva'                 => '0.00',
            'mon_id'                    => 'PES',
            'mon_cotiz'                 => '1.00',
            'punto_venta'               => 2,
            'cuit'                      => 20123456786,
            'receptor_documento_tipo'   => 80,
            'receptor_documento_nro'    => (string) self::REF_CUIT_RECEPTOR,
            'receptor_condicion_iva'    => 'MT',
            'items'                     => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
        ];
    }

    public function test_from_array_con_input_completo_no_tira(): void
    {
        $dto = ComprobanteEmitido::fromArray($this->dataEmitidoReal());

        $this->assertSame(11, $dto->cbteTipo);
        $this->assertSame(4, $dto->cbteNro);
        $this->assertSame('2026-08-04', $dto->cbteFch);
        $this->assertSame('86310728945116', $dto->cae);
        $this->assertSame('20260814', $dto->caeFchVto);
        $this->assertSame('100.00', $dto->montoTotal);
        $this->assertSame('100.00', $dto->montoNeto);
        $this->assertSame('0.00', $dto->montoIva);
        $this->assertSame('PES', $dto->monId);
        $this->assertSame('1.00', $dto->monCotiz);
        $this->assertSame(2, $dto->puntoVenta);
        $this->assertSame(20123456786, $dto->cuit);
        $this->assertSame(80, $dto->receptorDocumentoTipo);
        $this->assertSame((string) self::REF_CUIT_RECEPTOR, $dto->receptorDocumentoNro);
        $this->assertSame('MT', $dto->receptorCondicionIva);
        $this->assertCount(1, $dto->items);
        $this->assertSame('100.00', $dto->items[0]['importe_gravado']);
        $this->assertSame('21', $dto->items[0]['alicuota_iva']);
    }

    public function test_from_array_sin_receptor_devuelve_nulls_y_omite_en_qr(): void
    {
        $data = $this->dataEmitidoReal();
        unset($data['receptor_documento_tipo'], $data['receptor_documento_nro'], $data['receptor_condicion_iva']);

        $dto = ComprobanteEmitido::fromArray($data);

        $this->assertNull($dto->receptorDocumentoTipo);
        $this->assertNull($dto->receptorDocumentoNro);
        $this->assertNull($dto->receptorCondicionIva);

        $qr = $dto->toQrPayload();
        $this->assertArrayNotHasKey('tipoDocRec', $qr);
        $this->assertArrayNotHasKey('nroDocRec', $qr);
    }

    public function test_from_array_falta_campo_obligatorio_tira(): void
    {
        $data = $this->dataEmitidoReal();
        unset($data['cae']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cae/');
        ComprobanteEmitido::fromArray($data);
    }

    public function test_from_array_falta_cbte_tipo_tira(): void
    {
        $data = $this->dataEmitidoReal();
        unset($data['cbte_tipo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cbteTipo/');
        ComprobanteEmitido::fromArray($data);
    }

    public function test_from_array_acepta_camel_case_y_snake_case(): void
    {
        // snake_case puro (v0.2.x asArray()).
        $snake = $this->dataEmitidoReal();
        $dtoSnake = ComprobanteEmitido::fromArray($snake);

        // camelCase puro (forma canonica del DTO).
        $camel = [
            'cbteTipo'               => 11,
            'cbteNro'                => 4,
            'cbteFch'                => '2026-08-04',
            'cae'                    => '86310728945116',
            'caeFchVto'              => '20260814',
            'montoTotal'             => '100.00',
            'montoNeto'              => '100.00',
            'montoIva'               => '0.00',
            'monId'                  => 'PES',
            'monCotiz'               => '1.00',
            'puntoVenta'             => 2,
            'cuit'                   => 20123456786,
        ];
        $dtoCamel = ComprobanteEmitido::fromArray($camel);

        // Mismos valores en los campos compartidos.
        $this->assertSame($dtoSnake->cbteTipo, $dtoCamel->cbteTipo);
        $this->assertSame($dtoSnake->cbteNro, $dtoCamel->cbteNro);
        $this->assertSame($dtoSnake->cae, $dtoCamel->cae);
        $this->assertSame($dtoSnake->montoTotal, $dtoCamel->montoTotal);
        $this->assertSame($dtoSnake->puntoVenta, $dtoCamel->puntoVenta);
        $this->assertSame($dtoSnake->cuit, $dtoCamel->cuit);
    }

    public function test_as_array_devuelve_snake_case_para_compat(): void
    {
        $dto = ComprobanteEmitido::fromArray($this->dataEmitidoReal());

        $arr = $dto->asArray();

        $this->assertArrayHasKey('cae', $arr);
        $this->assertArrayHasKey('cae_fch_vto', $arr);
        $this->assertArrayHasKey('cbte_nro', $arr);
        $this->assertArrayHasKey('cbte_fch', $arr);
        $this->assertArrayHasKey('monto_total', $arr);
        $this->assertArrayHasKey('monto_neto', $arr);
        $this->assertArrayHasKey('monto_iva', $arr);
        $this->assertArrayHasKey('cbte_tipo', $arr);
        $this->assertArrayHasKey('punto_venta', $arr);
        $this->assertArrayHasKey('mon_id', $arr);
        $this->assertArrayHasKey('mon_cotiz', $arr);
        $this->assertArrayHasKey('cuit', $arr);
        $this->assertArrayHasKey('receptor_documento_tipo', $arr);
        $this->assertArrayHasKey('receptor_documento_nro', $arr);
        $this->assertArrayHasKey('items', $arr);
        $this->assertArrayHasKey('observaciones', $arr);

        // Valores exactos.
        $this->assertSame('86310728945116', $arr['cae']);
        $this->assertSame('20260814', $arr['cae_fch_vto']);
        $this->assertSame(4, $arr['cbte_nro']);
        $this->assertSame('2026-08-04', $arr['cbte_fch']);
        $this->assertSame('100.00', $arr['monto_total']);
        $this->assertSame(11, $arr['cbte_tipo']);
        $this->assertSame(2, $arr['punto_venta']);
        $this->assertSame(80, $arr['receptor_documento_tipo']);
    }

    public function test_to_qr_payload_cumple_spec_oficial(): void
    {
        $dto = ComprobanteEmitido::fromArray($this->dataEmitidoReal());

        $payload = $dto->toQrPayload();

        $this->assertSame(1, $payload['ver']);
        $this->assertSame('2026-08-04', $payload['fecha']);
        $this->assertSame(20123456786, $payload['cuit']);
        $this->assertSame(2, $payload['ptoVta']);
        $this->assertSame(11, $payload['tipoCmp']);
        $this->assertSame(4, $payload['nroCmp']);
        $this->assertSame(10000, $payload['importe']);    // 100.00 * 100
        $this->assertSame('PES', $payload['moneda']);
        $this->assertSame(1000000, $payload['ctz']);       // 1.00 * 1.000.000
        $this->assertSame('E', $payload['tipoCodAut']);
        $this->assertSame(86310728945116, $payload['codAut']);
        $this->assertSame(80, $payload['tipoDocRec']);
        $this->assertSame(self::REF_CUIT_RECEPTOR, $payload['nroDocRec']);

        // Exactamente 13 campos (los canonicos de la spec v1 con receptor).
        $this->assertCount(13, $payload, 'payload QR debe tener exactamente 13 campos con receptor');
    }

    public function test_to_qr_payload_omite_receptor_si_null(): void
    {
        $data = $this->dataEmitidoReal();
        unset($data['receptor_documento_tipo'], $data['receptor_documento_nro']);
        $dto = ComprobanteEmitido::fromArray($data);

        $payload = $dto->toQrPayload();

        $this->assertArrayNotHasKey('tipoDocRec', $payload);
        $this->assertArrayNotHasKey('nroDocRec', $payload);
        $this->assertCount(11, $payload, 'payload QR sin receptor = 11 campos');
    }

    public function test_to_qr_payload_omite_receptor_si_vacio_o_cero(): void
    {
        $data = $this->dataEmitidoReal();
        $data['receptor_documento_tipo'] = 0;
        $data['receptor_documento_nro'] = '';
        $dto = ComprobanteEmitido::fromArray($data);

        $payload = $dto->toQrPayload();

        $this->assertArrayNotHasKey('tipoDocRec', $payload);
        $this->assertArrayNotHasKey('nroDocRec', $payload);
    }

    public function test_to_qr_payload_limpia_no_numericos_del_nro_doc(): void
    {
        $data = $this->dataEmitidoReal();
        $data['receptor_documento_nro'] = '30-91234567-6';
        $dto = ComprobanteEmitido::fromArray($data);

        $payload = $dto->toQrPayload();

        $this->assertSame(self::REF_CUIT_RECEPTOR, $payload['nroDocRec']);
    }

    public function test_roundtrip_from_as_array(): void
    {
        $a = $this->dataEmitidoReal();
        $a['external_id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $a['resultado'] = 'A';
        $a['cbtes_asoc'] = [['Tipo' => 1, 'PtoVta' => 1, 'Nro' => 99]];

        $dto = ComprobanteEmitido::fromArray($a);
        $b = $dto->asArray();

        // Campos compartidos (los que no son defaults).
        $this->assertSame($a['cae'], $b['cae']);
        $this->assertSame($a['cae_fch_vto'], $b['cae_fch_vto']);
        $this->assertSame($a['cbte_nro'], $b['cbte_nro']);
        $this->assertSame($a['cbte_fch'], $b['cbte_fch']);
        $this->assertSame($a['monto_total'], $b['monto_total']);
        $this->assertSame($a['monto_neto'], $b['monto_neto']);
        $this->assertSame($a['monto_iva'], $b['monto_iva']);
        $this->assertSame($a['cbte_tipo'], $b['cbte_tipo']);
        $this->assertSame($a['punto_venta'], $b['punto_venta']);
        $this->assertSame($a['mon_id'], $b['mon_id']);
        $this->assertSame($a['mon_cotiz'], $b['mon_cotiz']);
        $this->assertSame($a['cuit'], $b['cuit']);
        $this->assertSame($a['receptor_documento_tipo'], $b['receptor_documento_tipo']);
        $this->assertSame($a['receptor_documento_nro'], $b['receptor_documento_nro']);
        $this->assertSame($a['receptor_condicion_iva'], $b['receptor_condicion_iva']);
        $this->assertSame($a['external_id'], $b['external_id']);
        $this->assertSame($a['resultado'], $b['resultado']);
    }
}
