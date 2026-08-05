<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Asn1;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Builder de TLV DER ASN.1 minimo para construir PKCS#7 / CMS a mano.
 *
 * Por que existe:
 *  - La funcion nativa openssl_pkcs7_sign() de PHP, en algunos
 *    binarios (notablemente PHP 8.2 sobre Windows / XAMPP), produce
 *    un SignedData con digestEncryptionAlgorithm =
 *    rsaEncryption (OID 1.2.840.113549.1.1.1) en lugar del
 *    sha256WithRSAEncryption (OID 1.2.840.113549.1.1.11) que
 *    exige ARCA/WSAA. El rechazo de WSAA es "Firma invalida o
 *    algoritmo no soportado".
 *  - La salida de openssl_pkcs7_sign() tampoco es DER puro: viene
 *    envuelta en multipart/signed S/MIME, que en este build no se
 *    puede parsear con openssl_pkcs7_verify() / openssl_pkcs7_read()
 *    (devuelven "No certificates in file" / "no start line").
 *  - Solucion: armar el CMS a mano con openssl_sign() (que SI
 *    produce sha256WithRSAEncryption) y este builder para el
 *    resto de la estructura ASN.1.
 *
 * Lo que NO hace:
 *  - No implementa parser/decoder ASN.1 (solo encoder). Para
 *    decodificar se usa OpenSSL CLI en los tests.
 *  - No cubre el 100% de ASN.1: solo las construcciones que
 *    necesita PKCS#7 SignedData para WSAA (SEQUENCE, SET, INTEGER,
 *    OCTET STRING, OID, NULL, UTCTIME, tags context-specific
 *    [0] EXPLICIT/IMPLICIT CONSTRUCTED, BIT STRING).
 *
 * Convenciones:
 *  - Todos los metodos reciben o devuelven string binario (raw
 *    bytes). No hay Base64, no hay PEM. El caller codifica.
 *  - La longitud se codifica en formato DER: corta (< 128) o
 *    larga (primer byte 0x80 | N, seguido de N bytes big-endian).
 *  - El INTEGER se codifica en big-endian con la cantidad minima
 *    de bytes; el bit alto del primer byte indica el signo y se
 *    antepone 0x00 si hace falta.
 *
 * Tests unitarios en tests/unit/Asn1/Asn1BuilderTest.php.
 */
final class Asn1Builder
{
    /**
     * Codifica la longitud de un TLV ASN.1 en formato DER.
     *
     * Forma corta: si $length < 128, un solo byte = $length.
     * Forma larga: si $length >= 128, primer byte = 0x80 | N
     * (donde N = cantidad de bytes de longitud), seguido de N
     * bytes big-endian.
     *
     * @param int $length Cantidad de bytes del campo "value" del TLV (>= 0).
     */
    public static function encodeLength(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Asn1Builder::encodeLength: length debe ser >= 0');
        }
        if ($length < 128) {
            return chr($length);
        }
        // Forma larga: separar $length en bytes big-endian.
        $bytes = '';
        $n = $length;
        while ($n > 0) {
            $bytes = chr($n & 0xFF) . $bytes;
            $n >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Empaqueta $content en un TLV SEQUENCE (tag 0x30, constructed).
     */
    public static function sequence(string $content): string
    {
        return "\x30" . self::encodeLength(strlen($content)) . $content;
    }

    /**
     * Empaqueta $content en un TLV SET (tag 0x31, constructed).
     */
    public static function set(string $content): string
    {
        return "\x31" . self::encodeLength(strlen($content)) . $content;
    }

    /**
     * Codifica un INTEGER ASN.1 firmado (big-endian, cantidad minima
     * de bytes) y lo devuelve como TLV con tag 0x02.
     *
     * Casos cubiertos:
     *  - 0 -> "\x02\x01\x00" (un solo byte 0x00)
     *  - 127 -> "\x02\x01\x7F"
     *  - 128 -> "\x02\x02\x00\x80" (prepend 0x00 porque el high bit es 1)
     *  - 255 -> "\x02\x02\x00\xFF"
     *  - 256 -> "\x02\x02\x01\x00"
     *  - -1 -> "\x02\x01\xFF" (8 bits)
     *  - -128 -> "\x02\x01\x80"
     *  - -129 -> "\x02\x02\xFF\x7F" (complemento a dos, 2 bytes)
     *
     * @param int $value Entero con signo (rango PHP_INT).
     */
    public static function integer(int $value): string
    {
        // Representacion binaria de 8 bytes big-endian del valor
        // (pack 'J' escribe el bit pattern sin signo). Para $value
        // negativo eso ES el complemento a dos que queremos.
        $packed = pack('J', $value);

        // Cantidad minima: para positivos, strip leading 0x00;
        // para negativos, strip leading 0xFF. Siempre queda al
        // menos 1 byte que mantiene el signo.
        $strip = 0;
        if ($value >= 0) {
            while ($strip < 7 && ord($packed[$strip]) === 0x00) {
                $strip++;
            }
        } else {
            while ($strip < 7 && ord($packed[$strip]) === 0xFF) {
                $strip++;
            }
        }
        $body = substr($packed, $strip);

        // Prevenir confusion de signo: si positivo y el high bit
        // esta prendido, prepend 0x00. Si negativo y el high bit
        // esta apagado, prepend 0xFF.
        $highBit = ord($body[0]) & 0x80;
        if ($value >= 0 && $highBit) {
            $body = "\x00" . $body;
        } elseif ($value < 0 && !$highBit) {
            $body = "\xFF" . $body;
        }

        return "\x02" . self::encodeLength(strlen($body)) . $body;
    }

    /**
     * Codifica un INTEGER ASN.1 a partir de bytes big-endian sin
     * signo (util para seriales de certificados X.509, que pueden
     * exceder 2^63 y necesitan representacion unsigned).
     *
     * Se prepende 0x00 si el bit alto del primer byte esta
     * prendido, para evitar que se interprete como negativo.
     *
     * @param string $bytes Bytes big-endian del valor (puede ser vacio, lo tratamos como 0).
     */
    public static function integerFromBytes(string $bytes): string
    {
        if ($bytes === '') {
            return "\x02\x01\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::encodeLength(strlen($bytes)) . $bytes;
    }

    /**
     * Empaqueta $bytes en un TLV OCTET STRING (tag 0x04).
     */
    public static function octetString(string $bytes): string
    {
        return "\x04" . self::encodeLength(strlen($bytes)) . $bytes;
    }

    /**
     * Codifica un OBJECT IDENTIFIER (tag 0x06) a partir de la
     * notacion decimal con puntos, ej. "1.2.840.113549.1.7.2".
     *
     * Reglas de encoding (X.690):
     *  - El primer byte codifica los dos primeros arcos:
     *    first_byte = first_arc * 40 + second_arc.
     *  - Cada arco subsiguiente se codifica en base-128 con
     *    continuation bit (0x80) en todos los bytes menos el
     *    ultimo.
     *
     * Lanza InvalidArgumentException si el OID no tiene al menos
     * dos arcos o si algun arco es negativo.
     */
    public static function oid(string $dotNotation): string
    {
        $arcs = explode('.', $dotNotation);
        if (count($arcs) < 2) {
            throw new InvalidArgumentException(
                'Asn1Builder::oid: OID "' . $dotNotation . '" debe tener al menos 2 arcos'
            );
        }
        $first = (int) $arcs[0];
        $second = (int) $arcs[1];
        if ($first < 0 || $first > 2 || $second < 0) {
            throw new InvalidArgumentException(
                'Asn1Builder::oid: OID "' . $dotNotation . '" tiene arcos invalidos'
            );
        }
        if ($first < 2 && $second >= 40) {
            throw new InvalidArgumentException(
                'Asn1Builder::oid: OID "' . $dotNotation . '" tiene second_arc >= 40 con first_arc < 2'
            );
        }

        $body = chr($first * 40 + $second);
        for ($i = 2, $n = count($arcs); $i < $n; $i++) {
            $arc = (int) $arcs[$i];
            if ($arc < 0) {
                throw new InvalidArgumentException(
                    'Asn1Builder::oid: arco negativo en "' . $dotNotation . '"'
                );
            }
            $body .= self::encodeBase128($arc);
        }
        return "\x06" . self::encodeLength(strlen($body)) . $body;
    }

    /**
     * Empaqueta el valor NULL ASN.1 (tag 0x05, length 0x00).
     */
    public static function null(): string
    {
        return "\x05\x00";
    }

    /**
     * Codifica una fecha/hora como UTCTIME (tag 0x17) o
     * GeneralizedTime (tag 0x18) segun el anio.
     *
     * Reglas RFC 5280:
     *  - 1950 <= year < 2000: UTCTIME YYMMDDHHMMSSZ donde
     *    YY = year - 1900. 1950 -> 50, 1999 -> 99.
     *  - 2000 <= year < 2050: UTCTIME YYMMDDHHMMSSZ donde
     *    YY = year - 2000. 2000 -> 00, 2049 -> 49.
     *  - year >= 2050: GeneralizedTime (tag 0x18)
     *    YYYYMMDDHHMMSSZ (4 bytes de anio).
     *
     * Microsegundos del $dt se redondean a segundos (se ignora
     * el campo de fraccion). Se convierte a UTC antes de
     * formatear.
     */
    public static function utctime(DateTimeImmutable $dt): string
    {
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));
        $year = (int) $utc->format('Y');

        if ($year >= 1950 && $year < 2000) {
            $yy = $year - 1900;
            $body = sprintf(
                '%02d%02d%02d%02d%02d%02dZ',
                $yy,
                (int) $utc->format('n'),
                (int) $utc->format('j'),
                (int) $utc->format('G'),
                (int) $utc->format('i'),
                (int) $utc->format('s')
            );
            return "\x17" . self::encodeLength(strlen($body)) . $body;
        }
        if ($year >= 2000 && $year < 2050) {
            $body = sprintf(
                '%02d%02d%02d%02d%02d%02dZ',
                $year - 2000,
                (int) $utc->format('n'),
                (int) $utc->format('j'),
                (int) $utc->format('G'),
                (int) $utc->format('i'),
                (int) $utc->format('s')
            );
            return "\x17" . self::encodeLength(strlen($body)) . $body;
        }
        if ($year >= 2050) {
            $body = $utc->format('YmdHis') . 'Z';
            return "\x18" . self::encodeLength(strlen($body)) . $body;
        }
        throw new InvalidArgumentException(
            'Asn1Builder::utctime: anio ' . $year . ' fuera de rango (soportado: >= 1950)'
        );
    }

    /**
     * Empaqueta $content en un TLV con tag context-specific [tagNum]
     * CONSTRUCTED (byte 0xA0 | tagNum).
     *
     * Se usa para tags [N] EXPLICIT, donde el contenido es un TLV
     * ASN.1 completo (no se re-tagea).
     */
    public static function contextExplicit(int $tagNum, string $content): string
    {
        self::assertTagNum($tagNum);
        return chr(0xA0 | $tagNum) . self::encodeLength(strlen($content)) . $content;
    }

    /**
     * Empaqueta $content en un TLV con tag context-specific [tagNum]
     * CONSTRUCTED (byte 0xA0 | tagNum), asumiendo que el tag
     * subyacente fue REEMPLAZADO (IMPLICIT) en lugar de wrappeado.
     *
     * A nivel TLV es identico a contextExplicit(); el metodo existe
     * separado para autodocumentar la intencion del caller y porque
     * semantica IMPLICIT vs EXPLICIT difiere en como se construye
     * el contenido previo (IMPLICIT = sin tag interno, EXPLICIT =
     * con tag interno del tipo base).
     */
    public static function contextImplicitConstructed(int $tagNum, string $content): string
    {
        self::assertTagNum($tagNum);
        return chr(0xA0 | $tagNum) . self::encodeLength(strlen($content)) . $content;
    }

    /**
     * Empaqueta $bytes en un TLV BIT STRING (tag 0x03) con el byte
     * de bits-no-usados al principio (default 0).
     *
     * No se usa en el PKCS#7 que ARCA espera, pero queda en la API
     * por completitud ASN.1.
     */
    public static function bitString(string $bytes, int $unusedBits = 0): string
    {
        if ($unusedBits < 0 || $unusedBits > 7) {
            throw new InvalidArgumentException(
                'Asn1Builder::bitString: unusedBits debe estar en [0, 7]'
            );
        }
        $body = chr($unusedBits) . $bytes;
        return "\x03" . self::encodeLength(strlen($body)) . $body;
    }

    /**
     * Codifica $value en base-128 con continuation bit (X.690 8.19).
     * Usado para arcos de OID mayores a 127.
     */
    private static function encodeBase128(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Asn1Builder::encodeBase128: value debe ser >= 0');
        }
        if ($value === 0) {
            return "\x00";
        }
        $bytes = '';
        while ($value > 0) {
            $bytes = chr(($value & 0x7F) | ($bytes === '' ? 0 : 0x80)) . $bytes;
            $value >>= 7;
        }
        return $bytes;
    }

    /**
     * Valida que $tagNum quepa en un byte de tag context-specific
     * (clase 0x80..0xBF con construction bit 0x20).
     *
     * En ASN.1 X.680 los tags multi-byte existen pero no se usan
     * para context-specific [0]/[1]/[2] que necesitamos en PKCS#7.
     */
    private static function assertTagNum(int $tagNum): void
    {
        if ($tagNum < 0 || $tagNum > 30) {
            throw new InvalidArgumentException(
                'Asn1Builder: tagNum debe estar en [0, 30] (single-byte context-specific), recibio ' . $tagNum
            );
        }
    }
}
