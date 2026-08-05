<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Asn1;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Asn1\Asn1Builder;

/**
 * Tests del builder ASN.1.
 *
 * Estrategia: verificar estructura TLV byte-a-byte (tag, length,
 * value) usando bin2hex() para diff legible, y validar los
 * casos limite que rompen firmas criptograficas si se hacen
 * mal (longitud >= 128, INTEGER con signo, OID multi-byte).
 */
final class Asn1BuilderTest extends TestCase
{
    public function test_encode_length_forma_corta_hasta_127(): void
    {
        // 0..127 -> un solo byte igual al valor.
        $this->assertSame("\x00", Asn1Builder::encodeLength(0));
        $this->assertSame("\x01", Asn1Builder::encodeLength(1));
        $this->assertSame("\x7F", Asn1Builder::encodeLength(127));
    }

    public function test_encode_length_forma_larga_128_y_200(): void
    {
        // 128 = 0x81 0x80 (1 byte de longitud adicional, valor 0x80)
        $this->assertSame("\x81\x80", Asn1Builder::encodeLength(128));
        // 200 = 0x81 0xC8
        $this->assertSame("\x81\xC8", Asn1Builder::encodeLength(200));
    }

    public function test_encode_length_forma_larga_255_y_256(): void
    {
        // 255 = 0x81 0xFF
        $this->assertSame("\x81\xFF", Asn1Builder::encodeLength(255));
        // 256 = 0x82 0x01 0x00 (2 bytes)
        $this->assertSame("\x82\x01\x00", Asn1Builder::encodeLength(256));
    }

    public function test_encode_length_forma_larga_1000(): void
    {
        // 1000 = 0x82 0x03 0xE8
        $this->assertSame("\x82\x03\xE8", Asn1Builder::encodeLength(1000));
    }

    public function test_encode_length_forma_larga_100000(): void
    {
        // 100000 = 0x83 0x01 0x86 0xA0
        $this->assertSame("\x83\x01\x86\xA0", Asn1Builder::encodeLength(100000));
    }

    public function test_encode_length_negativo_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Asn1Builder::encodeLength(-1);
    }

    public function test_sequence_y_set_empaquetan_correctamente(): void
    {
        $content = "\x01\x02\x03";
        // SEQUENCE = 0x30
        $this->assertSame("\x30\x03\x01\x02\x03", Asn1Builder::sequence($content));
        // SET = 0x31
        $this->assertSame("\x31\x03\x01\x02\x03", Asn1Builder::set($content));
    }

    public function test_sequence_anidada(): void
    {
        // NULL ASN.1 = 05 00 (2 bytes de TLV). SEQUENCE anidada:
        //   inner = 30 02 05 00
        //   outer = 30 04 30 02 05 00
        $inner = Asn1Builder::sequence(Asn1Builder::null());
        $outer = Asn1Builder::sequence($inner);
        $this->assertSame('300430020500', bin2hex($outer));
    }

    public function test_integer_cero(): void
    {
        // 0 -> "\x02\x01\x00" (un solo byte 0x00)
        $this->assertSame("\x02\x01\x00", Asn1Builder::integer(0));
    }

    public function test_integer_positivos_chicos(): void
    {
        // 1, 127: un solo byte, no se prepend 0x00 (high bit apagado)
        $this->assertSame("\x02\x01\x01", Asn1Builder::integer(1));
        $this->assertSame("\x02\x01\x7F", Asn1Builder::integer(127));
    }

    public function test_integer_128_necesita_prepend(): void
    {
        // 128 = 0x80: high bit prendido -> prepend 0x00
        $this->assertSame("\x02\x02\x00\x80", Asn1Builder::integer(128));
    }

    public function test_integer_255_y_256(): void
    {
        // 255 = 0xFF: high bit prendido -> prepend 0x00
        $this->assertSame("\x02\x02\x00\xFF", Asn1Builder::integer(255));
        // 256 = 0x01 0x00: high bit apagado, 2 bytes
        $this->assertSame("\x02\x02\x01\x00", Asn1Builder::integer(256));
    }

    public function test_integer_65535(): void
    {
        // 65535 = 0xFFFF. El high bit esta prendido (0xFF como primer byte)
        // -> se prepend 0x00 para evitar confusion de signo. Total 3 bytes.
        $this->assertSame("\x02\x03\x00\xFF\xFF", Asn1Builder::integer(65535));
    }

    public function test_integer_negativos(): void
    {
        // -1 = 0xFF
        $this->assertSame("\x02\x01\xFF", Asn1Builder::integer(-1));
        // -128 = 0x80
        $this->assertSame("\x02\x01\x80", Asn1Builder::integer(-128));
        // -129 = 0xFF 0x7F (complemento a dos, 2 bytes)
        $this->assertSame("\x02\x02\xFF\x7F", Asn1Builder::integer(-129));
    }

    public function test_integer_grandes_con_8_bytes(): void
    {
        // 2^31 = 0x80000000: 4 bytes (high bit prendido -> prepend 0x00, total 5)
        $this->assertSame("\x02\x05\x00\x80\x00\x00\x00", Asn1Builder::integer(0x80000000));
        // 2^32 - 1 = 0xFFFFFFFF: 4 bytes (high bit prendido -> prepend 0x00, total 5)
        $this->assertSame("\x02\x05\x00\xFF\xFF\xFF\xFF", Asn1Builder::integer(0xFFFFFFFF));
    }

    public function test_integer_from_bytes_sin_signo(): void
    {
        // bytes vacios -> 0
        $this->assertSame("\x02\x01\x00", Asn1Builder::integerFromBytes(''));
        // 0x7F: no prepend (high bit apagado)
        $this->assertSame("\x02\x01\x7F", Asn1Builder::integerFromBytes("\x7F"));
        // 0x80: prepend 0x00
        $this->assertSame("\x02\x02\x00\x80", Asn1Builder::integerFromBytes("\x80"));
    }

    public function test_integer_from_bytes_serial_32_bits(): void
    {
        // Simula un serial de cert 4 bytes unsigned: 0x12345678
        $this->assertSame("\x02\x04\x12\x34\x56\x78", Asn1Builder::integerFromBytes("\x12\x34\x56\x78"));
    }

    public function test_integer_from_bytes_serial_con_high_bit(): void
    {
        // Serial 0x2C 0x96 0xB1 0x86 0x7F 0x93 0x5A 0x49 (el del cert AFIP)
        $bytes = "\x2C\x96\xB1\x86\x7F\x93\x5A\x49";
        $hex = '2c96b1867f935a49';
        $expected = '02' . sprintf('%02x', strlen($bytes)) . $hex;
        $this->assertSame(hex2bin($expected), Asn1Builder::integerFromBytes($bytes));
    }

    public function test_octet_string_basico(): void
    {
        $this->assertSame("\x04\x03abc", Asn1Builder::octetString('abc'));
    }

    public function test_octet_string_200_bytes_usa_longitud_larga(): void
    {
        $payload = str_repeat('A', 200);
        $der = Asn1Builder::octetString($payload);
        // Tag 0x04 + length 0x81 0xC8 + 200 bytes
        $this->assertSame("\x04\x81\xC8" . $payload, $der);
    }

    public function test_octet_string_1000_bytes_usa_longitud_larga(): void
    {
        $payload = str_repeat("\xAA", 1000);
        $der = Asn1Builder::octetString($payload);
        // Tag 0x04 + length 0x82 0x03 0xE8 + 1000 bytes
        $this->assertSame("\x04\x82\x03\xE8" . $payload, $der);
    }

    public function test_octet_string_100000_bytes_longitud_larga(): void
    {
        $payload = str_repeat("\xBB", 100000);
        $der = Asn1Builder::octetString($payload);
        // Tag 0x04 + length 0x83 0x01 0x86 0xA0 + 100000 bytes
        $this->assertSame("\x04\x83\x01\x86\xA0" . $payload, $der);
    }

    public function test_octet_string_vacio(): void
    {
        $this->assertSame("\x04\x00", Asn1Builder::octetString(''));
    }

    public function test_oid_signedData(): void
    {
        // 1.2.840.113549.1.7.2 -> hex 06 09 2a 86 48 86 f7 0d 01 07 02
        $this->assertSame(
            "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x02",
            Asn1Builder::oid('1.2.840.113549.1.7.2')
        );
    }

    public function test_oid_data(): void
    {
        // 1.2.840.113549.1.7.1 -> 06 09 2a 86 48 86 f7 0d 01 07 01
        $this->assertSame(
            "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x01",
            Asn1Builder::oid('1.2.840.113549.1.7.1')
        );
    }

    public function test_oid_sha256(): void
    {
        // 2.16.840.1.101.3.4.2.1 -> 06 09 60 86 48 01 65 03 04 02 01
        $this->assertSame(
            "\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01",
            Asn1Builder::oid('2.16.840.1.101.3.4.2.1')
        );
    }

    public function test_oid_sha256_with_rsa_encryption(): void
    {
        // 1.2.840.113549.1.1.11 -> arcos [1, 2, 840, 113549, 1, 1, 11]
        // Encoding: 0x2A | 0x86 0x48 | 0x86 0xF7 0x0D | 0x01 | 0x01 | 0x0B
        // Body: 9 bytes -> tag 06, length 09
        // Hex: 06 09 2a 86 48 86 f7 0d 01 01 0b
        $this->assertSame(
            "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x0B",
            Asn1Builder::oid('1.2.840.113549.1.1.11')
        );
    }

    public function test_oids_authAttrs_del_cms(): void
    {
        // contentType: 1.2.840.113549.1.9.3
        $this->assertSame(
            "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x09\x03",
            Asn1Builder::oid('1.2.840.113549.1.9.3')
        );
        // signingTime: 1.2.840.113549.1.9.5
        $this->assertSame(
            "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x09\x05",
            Asn1Builder::oid('1.2.840.113549.1.9.5')
        );
        // messageDigest: 1.2.840.113549.1.9.4
        $this->assertSame(
            "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x09\x04",
            Asn1Builder::oid('1.2.840.113549.1.9.4')
        );
    }

    public function test_oids_rdn_del_issuer(): void
    {
        $this->assertSame("\x06\x03\x55\x04\x03", Asn1Builder::oid('2.5.4.3'));    // CN
        $this->assertSame("\x06\x03\x55\x04\x0A", Asn1Builder::oid('2.5.4.10'));   // O
        $this->assertSame("\x06\x03\x55\x04\x06", Asn1Builder::oid('2.5.4.6'));    // C
        $this->assertSame("\x06\x03\x55\x04\x05", Asn1Builder::oid('2.5.4.5'));    // serialNumber
    }

    public function test_oid_con_un_solo_arco_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Asn1Builder::oid('1');
    }

    public function test_null(): void
    {
        $this->assertSame("\x05\x00", Asn1Builder::null());
    }

    public function test_utctime_1999_12_31(): void
    {
        // 1999 -> YY = 99 (year-1900). UTCTIME = YYMMDDHHMMSSZ = 13 bytes.
        $dt = new DateTimeImmutable('1999-12-31 23:59:58', new DateTimeZone('UTC'));
        $this->assertSame("\x17\x0D" . '991231235958Z', Asn1Builder::utctime($dt));
    }

    public function test_utctime_2000_01_01(): void
    {
        // 2000 -> YY = 00 (year-2000). UTCTIME = YYMMDDHHMMSSZ = 13 bytes.
        $dt = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'));
        $this->assertSame("\x17\x0D" . '000101000000Z', Asn1Builder::utctime($dt));
    }

    public function test_utctime_2049_12_31(): void
    {
        $dt = new DateTimeImmutable('2049-12-31 12:34:56', new DateTimeZone('UTC'));
        // 2049 -> YY = 49 (year-2000). 13 bytes de body.
        $this->assertSame("\x17\x0D" . '491231123456Z', Asn1Builder::utctime($dt));
    }

    public function test_utctime_2050_usa_generalized_time(): void
    {
        $dt = new DateTimeImmutable('2050-01-01 00:00:00', new DateTimeZone('UTC'));
        // 2050+ usa tag 0x18 (GeneralizedTime) con 4 bytes de anio
        $this->assertSame("\x18\x0F" . '20500101000000Z', Asn1Builder::utctime($dt));
    }

    public function test_utctime_convierte_a_UTC_si_llega_en_otra_zona(): void
    {
        // 2025-01-01 03:00:00 en UTC+3 == 2025-01-01 00:00:00 UTC
        $dt = new DateTimeImmutable('2025-01-01 03:00:00', new DateTimeZone('+03:00'));
        $this->assertSame("\x17\x0D" . '250101000000Z', Asn1Builder::utctime($dt));
    }

    public function test_utctime_anio_menor_a_1950_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $dt = new DateTimeImmutable('1949-12-31 23:59:59', new DateTimeZone('UTC'));
        Asn1Builder::utctime($dt);
    }

    public function test_context_explicit_y_context_implicit_producen_mismo_tlv(): void
    {
        // La diferencia es semantica (EXPLICIT = wrap, IMPLICIT = re-tag);
        // a nivel TLV bytes el resultado es identico cuando el tag base
        // es SET/SEQUENCE (CONSTRUCTED). Verificamos que el byte de tag
        // es context-specific constructed: 0xA0 | tagNum.
        $content = "\x01\x02\x03";
        $this->assertSame("\xA0\x03\x01\x02\x03", Asn1Builder::contextExplicit(0, $content));
        $this->assertSame("\xA0\x03\x01\x02\x03", Asn1Builder::contextImplicitConstructed(0, $content));
        // Tag 3 -> 0xA3
        $this->assertSame("\xA3\x03\x01\x02\x03", Asn1Builder::contextExplicit(3, $content));
        $this->assertSame("\xA3\x03\x01\x02\x03", Asn1Builder::contextImplicitConstructed(3, $content));
    }

    public function test_context_tagnum_fuera_de_rango_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Asn1Builder::contextExplicit(31, 'x');
    }

    public function test_context_tagnum_negativo_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Asn1Builder::contextImplicitConstructed(-1, 'x');
    }

    public function test_bit_string_basico(): void
    {
        // 0 bits unused: "\x03\x02\x00\xAB" (1 byte de unused-bits + 1 byte de payload)
        $this->assertSame("\x03\x02\x00\xAB", Asn1Builder::bitString("\xAB"));
    }

    public function test_bit_string_con_unused_bits_custom(): void
    {
        // 3 bits unused
        $this->assertSame("\x03\x02\x03\xAB", Asn1Builder::bitString("\xAB", 3));
    }

    public function test_bit_string_unused_bits_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Asn1Builder::bitString("\x00", 8);
    }

    public function test_octet_string_round_trip_via_openssl(): void
    {
        // Validacion end-to-end: ASN.1 valido de OpenSSL CLI.
        // Construimos un OCTET STRING de 256 bytes y lo decodificamos
        // con openssl asn1parse -inform DER. Verifica que la longitud
        // larga multi-byte esta bien armada.
        $payload = random_bytes(256);
        $der = Asn1Builder::octetString($payload);
        $tmp = tempnam(sys_get_temp_dir(), 'asn1_');
        file_put_contents($tmp, $der);

        $openssl = 'C:\\xampp\\php\\extras\\openssl\\openssl.exe';
        $cmd = sprintf(
            '%s asn1parse -inform DER -in %s 2>&1',
            escapeshellarg($openssl),
            escapeshellarg($tmp)
        );
        $output = shell_exec($cmd);
        @unlink($tmp);

        $this->assertNotNull($output, 'openssl asn1parse no se ejecuto');
        // OpenSSL imprime "OCTET STRING      :AAAA..." con header length
        // 4 (tag + 0x82 0x01 0x00) y contenido length 256. Buscamos
        // tanto el tipo como el tamano en la salida.
        $this->assertStringContainsString('OCTET STRING', $output);
        $this->assertMatchesRegularExpression('/l=\s*256\b/', $output);
    }

    public function test_sequence_round_trip_via_openssl(): void
    {
        // Construimos un SEQUENCE anidado con OID + NULL y verificamos
        // que OpenSSL CLI lo parsea como AlgorithmIdentifier SHA-256.
        $algId = Asn1Builder::sequence(
            Asn1Builder::oid('2.16.840.1.101.3.4.2.1') . Asn1Builder::null()
        );

        $tmp = tempnam(sys_get_temp_dir(), 'asn1_');
        file_put_contents($tmp, $algId);

        $openssl = 'C:\\xampp\\php\\extras\\openssl\\openssl.exe';
        $cmd = sprintf(
            '%s asn1parse -inform DER -in %s 2>&1',
            escapeshellarg($openssl),
            escapeshellarg($tmp)
        );
        $output = shell_exec($cmd);
        @unlink($tmp);

        $this->assertNotNull($output);
        // OpenSSL imprime "OBJECT" (no "OBJECT IDENTIFIER") y muestra
        // el nombre OID al final con ":" (ej. ":sha256").
        $this->assertStringContainsString('SEQUENCE', $output);
        $this->assertStringContainsString('OBJECT', $output);
        $this->assertStringContainsString('NULL', $output);
        $this->assertStringContainsString('sha256', $output);
    }
}
