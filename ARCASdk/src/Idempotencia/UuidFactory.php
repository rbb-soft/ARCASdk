<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Idempotencia;

/**
 * Generador y validador de UUID v4 (RFC 4122).
 *
 * Decisiones:
 *  - v4() usa random_bytes(16) (CSPRNG del runtime). random_bytes
 *    es thread-safe y criptograficamente seguro en PHP >= 7.x.
 *    NUNCA mt_rand()/rand() para el cuerpo del UUID (no son CSPRNG).
 *  - Los bits de version (4) y variant (RFC 4122 10xx) se fijan
 *    explicitamente enmascarando + OR con las mascaras 0x40 y 0x80.
 *  - isValid() acepta EXCLUSIVAMENTE el formato canonico de v4
 *    con la version 4 y variant 8/9/a/b. Cualquier otra cosa
 *    (v1, v3, v5, NIL, MAX, malformed) se rechaza.
 *  - Sin dependencias Composer: ~15 lineas que el SDK no deberia
 *    delegar a un paquete externo. Esto tambien reduce el
 *    blast-radius de un cambio de dependencias.
 */
final class UuidFactory
{
    /**
     * Regex canonica UUID v4:
     *   - 8 hex - 4 hex - 4[hex] - [89ab]hex - 12 hex
     *   - 4to grupo: high nibble = 4 (version)
     *   - 5to grupo (3 chars): high 2 bits = 10 (variant RFC 4122)
     *     => byte empieza con 8, 9, a o b.
     * Case-insensitive para aceptar mayusculas, pero el formato
     * canonico emitido por v4() es lowercase.
     */
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Genera un UUID v4 nuevo.
     *
     * El formato canonico es:
     *   xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     * donde x es cualquier hex digito y es 8|9|a|b.
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);

        // Version 4: high nibble de byte[6] = 0100.
        //   0x0f = mascara para limpiar high nibble
        //   0x40 = version 4
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);

        // Variant RFC 4122: high 2 bits de byte[8] = 10.
        //   0x3f = mascara para limpiar los 2 high bits
        //   0x80 = variant 10xxxxxx
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Valida que el string es un UUID v4 canonico con version 4
     * y variant 8/9/a/b correctos. Cualquier otra cosa retorna false.
     */
    public static function isValid(string $uuid): bool
    {
        return (bool) preg_match(self::PATTERN, $uuid);
    }
}
