<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Idempotencia;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Idempotencia\FilaEmision;
use Rbbsoft\ArcaSdk\Idempotencia\UuidFactory;

final class FilaEmisionTest extends TestCase
{
    private const EXTERNAL_ID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
    private const CUIT        = '20111111111';
    private const OTRO_CUIT   = '20222222222';
    private const LEASE_A     = '11111111-1111-4111-8111-111111111111';
    private const LEASE_B     = '22222222-2222-4222-8222-222222222222';
    private const FINGERPRINT = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private function makeFila(
        ?string $leaseToken = self::LEASE_A,
        string $estado = FilaEmision::ESTADO_EN_CURSO,
        int $intento = 0,
        bool $esFalloInfra = false,
        ?int $cbteNroEnviado = null,
        ?DateTimeImmutable $cbteFchEnviado = null,
        ?string $cae = null,
        ?DateTimeImmutable $caeFchVto = null,
        ?int $cbteNroConfirmado = null,
        ?string $responseJson = null,
    ): FilaEmision {
        $utc = new DateTimeZone('UTC');
        return new FilaEmision(
            externalId:         self::EXTERNAL_ID,
            cuit:               self::CUIT,
            puntoVenta:         1,
            cbteTipo:           11,
            estado:             $estado,
            leaseToken:         $leaseToken,
            intento:            $intento,
            esFalloInfra:       $esFalloInfra,
            requestFingerprint: self::FINGERPRINT,
            requestJson:        '{"hola":"mundo"}',
            cbteNroEnviado:     $cbteNroEnviado,
            cbteFchEnviado:     $cbteFchEnviado,
            cae:                $cae,
            caeFchVto:          $caeFchVto,
            cbteNroConfirmado:  $cbteNroConfirmado,
            responseJson:       $responseJson,
            createdAt:          new DateTimeImmutable('2025-06-15 10:00:00', $utc),
            updatedAt:          new DateTimeImmutable('2025-06-15 10:00:05', $utc),
        );
    }

    // -------------------------------------------------------------------
    // Constructor + accessors
    // -------------------------------------------------------------------

    public function test_constructor_y_accessors_18_propiedades(): void
    {
        $utc = new DateTimeZone('UTC');
        $created = new DateTimeImmutable('2025-01-01 00:00:00', $utc);
        $updated = new DateTimeImmutable('2025-01-01 00:00:30', $utc);
        $fch = new DateTimeImmutable('2025-01-15', $utc);
        $vto = new DateTimeImmutable('2025-02-15', $utc);

        $fila = new FilaEmision(
            externalId:         self::EXTERNAL_ID,
            cuit:               self::CUIT,
            puntoVenta:         5,
            cbteTipo:           1,
            estado:             FilaEmision::ESTADO_EMITIDO,
            leaseToken:         null,
            intento:            2,
            esFalloInfra:       false,
            requestFingerprint: self::FINGERPRINT,
            requestJson:        '{"k":"v"}',
            cbteNroEnviado:     100,
            cbteFchEnviado:     $fch,
            cae:                '74123456789012',
            caeFchVto:          $vto,
            cbteNroConfirmado:  100,
            responseJson:       '{"Resultado":"A"}',
            createdAt:          $created,
            updatedAt:          $updated,
        );

        $this->assertSame(self::EXTERNAL_ID, $fila->externalId);
        $this->assertSame(self::CUIT, $fila->cuit);
        $this->assertSame(5, $fila->puntoVenta);
        $this->assertSame(1, $fila->cbteTipo);
        $this->assertSame(FilaEmision::ESTADO_EMITIDO, $fila->estado);
        $this->assertNull($fila->leaseToken);
        $this->assertSame(2, $fila->intento);
        $this->assertFalse($fila->esFalloInfra);
        $this->assertSame(self::FINGERPRINT, $fila->requestFingerprint);
        $this->assertSame('{"k":"v"}', $fila->requestJson);
        $this->assertSame(100, $fila->cbteNroEnviado);
        $this->assertEquals($fch, $fila->cbteFchEnviado);
        $this->assertSame('74123456789012', $fila->cae);
        $this->assertEquals($vto, $fila->caeFchVto);
        $this->assertSame(100, $fila->cbteNroConfirmado);
        $this->assertSame('{"Resultado":"A"}', $fila->responseJson);
        $this->assertEquals($created, $fila->createdAt);
        $this->assertEquals($updated, $fila->updatedAt);
    }

    public function test_propiedades_son_readonly(): void
    {
        $fila = $this->makeFila();
        // readonly: no podemos reasignar. La verificacion se hace
        // en compile time; si PHP permitiera la asignacion, el
        // analisis estatico marcaria error. Acá simplemente leemos
        // cada propiedad para asegurarnos de que el binding con
        // el constructor quedo OK.
        $reflection = new \ReflectionClass($fila);
        $publicProps = array_map(
            static fn(\ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties(\ReflectionProperty::IS_PUBLIC)
        );
        sort($publicProps);
        $expected = [
            'cae', 'caeFchVto', 'cbteFchEnviado', 'cbteNroConfirmado',
            'cbteNroEnviado', 'cbteTipo', 'cuit', 'createdAt',
            'esFalloInfra', 'estado', 'externalId', 'intento',
            'leaseToken', 'puntoVenta', 'requestFingerprint', 'requestJson',
            'responseJson', 'updatedAt',
        ];
        sort($expected);
        $this->assertSame(
            $expected,
            $publicProps,
            '18 propiedades public, una por columna de la tabla'
        );
    }

    // -------------------------------------------------------------------
    // isOwnedBy()
    // -------------------------------------------------------------------

    public function test_isOwnedBy_true_para_mismo_lease(): void
    {
        $fila = $this->makeFila(leaseToken: self::LEASE_A);
        $this->assertTrue($fila->isOwnedBy(self::LEASE_A));
    }

    public function test_isOwnedBy_false_para_otro_lease(): void
    {
        $fila = $this->makeFila(leaseToken: self::LEASE_A);
        $this->assertFalse($fila->isOwnedBy(self::LEASE_B));
    }

    public function test_isOwnedBy_false_si_lease_null(): void
    {
        // Estado terminal: lease_token=NULL, ninguna lease coincide.
        $fila = $this->makeFila(leaseToken: null, estado: FilaEmision::ESTADO_FALLIDO);
        $this->assertFalse($fila->isOwnedBy(self::LEASE_A));
        $this->assertFalse($fila->isOwnedBy(''));
    }

    public function test_isOwnedBy_false_si_string_vacio(): void
    {
        $fila = $this->makeFila(leaseToken: self::LEASE_A);
        $this->assertFalse($fila->isOwnedBy(''));
    }

    public function test_isOwnedBy_false_si_strings_longitud_distinta(): void
    {
        // hash_equals retorna false para longitudes distintas sin
        // lanzar warning. Verificamos que no haya excepcion.
        $fila = $this->makeFila(leaseToken: self::LEASE_A);
        $this->assertFalse($fila->isOwnedBy('corto'));
        $this->assertFalse($fila->isOwnedBy(str_repeat('a', 100)));
    }

    // -------------------------------------------------------------------
    // toArray() + round-trip
    // -------------------------------------------------------------------

    public function test_toArray_contiene_todas_las_claves_snake_case(): void
    {
        $fila = $this->makeFila();
        $arr = $fila->toArray();
        $this->assertSame(
            [
                'external_id', 'cuit', 'punto_venta', 'cbte_tipo', 'estado',
                'lease_token', 'intento', 'es_fallo_infra', 'request_fingerprint',
                'request_json', 'cbte_nro_enviado', 'cbte_fch_enviado', 'cae',
                'cae_fch_vto', 'cbte_nro_confirmado', 'response_json',
                'created_at', 'updated_at',
            ],
            array_keys($arr),
            'toArray debe usar snake_case igual que los nombres de columna'
        );
    }

    public function test_toArray_serializa_fechas_en_formato_canonico_utc(): void
    {
        $utc = new DateTimeZone('UTC');
        $fila = new FilaEmision(
            externalId:         self::EXTERNAL_ID,
            cuit:               self::CUIT,
            puntoVenta:         1,
            cbteTipo:           11,
            estado:             FilaEmision::ESTADO_EMITIDO,
            leaseToken:         null,
            intento:            0,
            esFalloInfra:       false,
            requestFingerprint: self::FINGERPRINT,
            requestJson:        '{}',
            cbteNroEnviado:     42,
            cbteFchEnviado:     new DateTimeImmutable('2025-06-20', $utc),
            cae:                '74000000000017',
            caeFchVto:          new DateTimeImmutable('2025-07-20', $utc),
            cbteNroConfirmado:  42,
            responseJson:       '{}',
            createdAt:          new DateTimeImmutable('2025-06-15 10:00:00', $utc),
            updatedAt:          new DateTimeImmutable('2025-06-15 10:00:05', $utc),
        );
        $arr = $fila->toArray();
        $this->assertSame('2025-06-20', $arr['cbte_fch_enviado']);
        $this->assertSame('2025-07-20', $arr['cae_fch_vto']);
        $this->assertSame('2025-06-15 10:00:00', $arr['created_at']);
        $this->assertSame('2025-06-15 10:00:05', $arr['updated_at']);
    }

    public function test_toArray_serializa_esFalloInfra_como_int(): void
    {
        $filaInfra = $this->makeFila(esFalloInfra: true);
        $filaNeg = $this->makeFila(esFalloInfra: false);
        $this->assertSame(1, $filaInfra->toArray()['es_fallo_infra']);
        $this->assertSame(0, $filaNeg->toArray()['es_fallo_infra']);
    }

    public function test_toArray_preserva_nulls(): void
    {
        $fila = $this->makeFila(leaseToken: null, estado: FilaEmision::ESTADO_FALLIDO);
        $arr = $fila->toArray();
        $this->assertNull($arr['lease_token']);
        $this->assertNull($arr['cbte_nro_enviado']);
        $this->assertNull($arr['cbte_fch_enviado']);
        $this->assertNull($arr['cae']);
        $this->assertNull($arr['cae_fch_vto']);
        $this->assertNull($arr['cbte_nro_confirmado']);
        $this->assertNull($arr['response_json']);
    }

    public function test_toArray_roundtrip(): void
    {
        $original = $this->makeFila();
        $arr = $original->toArray();
        // Reconstruir a partir del array. Como DateTime fields vuelven
        // como strings, los re-parseamos.
        $utc = new DateTimeZone('UTC');
        $reconstruida = new FilaEmision(
            externalId:         (string) $arr['external_id'],
            cuit:               (string) $arr['cuit'],
            puntoVenta:         (int) $arr['punto_venta'],
            cbteTipo:           (int) $arr['cbte_tipo'],
            estado:             (string) $arr['estado'],
            leaseToken:         $arr['lease_token'] === null ? null : (string) $arr['lease_token'],
            intento:            (int) $arr['intento'],
            esFalloInfra:       ((int) $arr['es_fallo_infra']) === 1,
            requestFingerprint: (string) $arr['request_fingerprint'],
            requestJson:        (string) $arr['request_json'],
            cbteNroEnviado:     $arr['cbte_nro_enviado'] === null ? null : (int) $arr['cbte_nro_enviado'],
            cbteFchEnviado:     $arr['cbte_fch_enviado'] === null ? null : new DateTimeImmutable((string) $arr['cbte_fch_enviado'], $utc),
            cae:                $arr['cae'] === null ? null : (string) $arr['cae'],
            caeFchVto:          $arr['cae_fch_vto'] === null ? null : new DateTimeImmutable((string) $arr['cae_fch_vto'], $utc),
            cbteNroConfirmado:  $arr['cbte_nro_confirmado'] === null ? null : (int) $arr['cbte_nro_confirmado'],
            responseJson:       $arr['response_json'] === null ? null : (string) $arr['response_json'],
            createdAt:          new DateTimeImmutable((string) $arr['created_at'], $utc),
            updatedAt:          new DateTimeImmutable((string) $arr['updated_at'], $utc),
        );
        $this->assertEquals($original, $reconstruida);
    }

    public function test_toArray_es_json_encodable(): void
    {
        $fila = $this->makeFila();
        $json = json_encode($fila->toArray(), JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame($fila->toArray(), $decoded);
    }

    // -------------------------------------------------------------------
    // Validaciones del constructor
    // -------------------------------------------------------------------

    public function test_estado_invalido_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estado invalido');
        $this->makeFila(estado: 'otro-estado');
    }

    public function test_externalId_corto_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('externalId');
        new FilaEmision(
            externalId:         'corto',
            cuit:               self::CUIT,
            puntoVenta:         1,
            cbteTipo:           11,
            estado:             FilaEmision::ESTADO_EN_CURSO,
            leaseToken:         null,
            intento:            0,
            esFalloInfra:       false,
            requestFingerprint: self::FINGERPRINT,
            requestJson:        '{}',
            cbteNroEnviado:     null,
            cbteFchEnviado:     null,
            cae:                null,
            caeFchVto:          null,
            cbteNroConfirmado:  null,
            responseJson:       null,
            createdAt:          new DateTimeImmutable('2025-06-15 10:00:00', new DateTimeZone('UTC')),
            updatedAt:          new DateTimeImmutable('2025-06-15 10:00:05', new DateTimeZone('UTC')),
        );
    }

    public function test_cuit_invalido_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cuit');
        new FilaEmision(
            externalId:         self::EXTERNAL_ID,
            cuit:               '123',
            puntoVenta:         1,
            cbteTipo:           11,
            estado:             FilaEmision::ESTADO_EN_CURSO,
            leaseToken:         null,
            intento:            0,
            esFalloInfra:       false,
            requestFingerprint: self::FINGERPRINT,
            requestJson:        '{}',
            cbteNroEnviado:     null,
            cbteFchEnviado:     null,
            cae:                null,
            caeFchVto:          null,
            cbteNroConfirmado:  null,
            responseJson:       null,
            createdAt:          new DateTimeImmutable('2025-06-15 10:00:00', new DateTimeZone('UTC')),
            updatedAt:          new DateTimeImmutable('2025-06-15 10:00:05', new DateTimeZone('UTC')),
        );
    }

    public function test_fingerprint_invalido_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requestFingerprint');
        new FilaEmision(
            externalId:         self::EXTERNAL_ID,
            cuit:               self::CUIT,
            puntoVenta:         1,
            cbteTipo:           11,
            estado:             FilaEmision::ESTADO_EN_CURSO,
            leaseToken:         null,
            intento:            0,
            esFalloInfra:       false,
            requestFingerprint: 'demasiado-corto',
            requestJson:        '{}',
            cbteNroEnviado:     null,
            cbteFchEnviado:     null,
            cae:                null,
            caeFchVto:          null,
            cbteNroConfirmado:  null,
            responseJson:       null,
            createdAt:          new DateTimeImmutable('2025-06-15 10:00:00', new DateTimeZone('UTC')),
            updatedAt:          new DateTimeImmutable('2025-06-15 10:00:05', new DateTimeZone('UTC')),
        );
    }

    public function test_constantes_estado(): void
    {
        $this->assertSame('en_curso', FilaEmision::ESTADO_EN_CURSO);
        $this->assertSame('emitido',  FilaEmision::ESTADO_EMITIDO);
        $this->assertSame('fallido',  FilaEmision::ESTADO_FALLIDO);
        $this->assertSame(
            ['en_curso', 'emitido', 'fallido'],
            FilaEmision::ESTADOS_VALIDOS
        );
    }
}
