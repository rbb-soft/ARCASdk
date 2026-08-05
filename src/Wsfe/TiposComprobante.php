<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

/**
 * Tipos de comprobante ARCA (WSFE / wsfev1).
 *
 * Documentacion oficial: Manual WSFEV1 v4.3 (RG 4291).
 *
 * Discriminacion de IVA:
 *   - A (1, 2, 3): discrimina IVA, requiere receptor con CUIT.
 *   - B (6, 7, 8): discrimina IVA, receptor puede ser CUIT o DNI.
 *   - C (11, 12, 13): NO discrimina IVA, total = gravado.
 *   - M (51, 53): NO discrimina IVA (factura monotributo).
 */
final class TiposComprobante
{
    public const FACTURA_A        = 1;
    public const NOTA_DEBITO_A    = 2;
    public const NOTA_CREDITO_A   = 3;
    public const FACTURA_B        = 6;
    public const NOTA_DEBITO_B    = 7;
    public const NOTA_CREDITO_B   = 8;
    public const FACTURA_C        = 11;
    public const NOTA_DEBITO_C    = 12;
    public const NOTA_CREDITO_C   = 13;
    public const FACTURA_M        = 51;
    public const NOTA_CREDITO_M   = 53;

    /** Tipos que el SDK soporta en v1. */
    public const SOPORTADOS = [
        self::FACTURA_A,
        self::NOTA_DEBITO_A,
        self::NOTA_CREDITO_A,
        self::FACTURA_B,
        self::NOTA_DEBITO_B,
        self::NOTA_CREDITO_B,
        self::FACTURA_C,
        self::NOTA_DEBITO_C,
        self::NOTA_CREDITO_C,
        self::FACTURA_M,
        self::NOTA_CREDITO_M,
    ];

    /** Tipos que discriminan IVA. */
    private const DISCRIMINAN_IVA = [
        self::FACTURA_A, self::NOTA_DEBITO_A, self::NOTA_CREDITO_A,
        self::FACTURA_B, self::NOTA_DEBITO_B, self::NOTA_CREDITO_B,
    ];

    /** Tipos que son Nota de Credito. */
    private const NOTAS_CREDITO = [
        self::NOTA_CREDITO_A, self::NOTA_CREDITO_B, self::NOTA_CREDITO_C, self::NOTA_CREDITO_M,
    ];

    /** Tipos que requieren CUIT (no DNI). */
    private const REQUIEREN_CUIT = [
        self::FACTURA_A, self::NOTA_DEBITO_A, self::NOTA_CREDITO_A,
        self::FACTURA_M, self::NOTA_CREDITO_M,
    ];

    /**
     * Tipos que NO discriminan IVA pero aun asi exigen el bloque <Iva>
     * explicito cuando hay gravado. Bajo RG 5616 la M requiere que el
     * objeto <Iva> este presente (codigo 10070 si falta) aunque el
     * total ya incluya el impuesto. La C, en cambio, no lo exige.
     */
    private const REQUIEREN_BLOQUE_IVA = [
        self::FACTURA_M, self::NOTA_CREDITO_M,
    ];

    public static function esValido(int $cbteTipo): bool
    {
        return in_array($cbteTipo, self::SOPORTADOS, true);
    }

    public static function discriminaIva(int $cbteTipo): bool
    {
        return in_array($cbteTipo, self::DISCRIMINAN_IVA, true);
    }

    public static function esNotaCredito(int $cbteTipo): bool
    {
        return in_array($cbteTipo, self::NOTAS_CREDITO, true);
    }

    public static function requiereCuit(int $cbteTipo): bool
    {
        return in_array($cbteTipo, self::REQUIEREN_CUIT, true);
    }

    public static function requiereBloqueIva(int $cbteTipo): bool
    {
        return in_array($cbteTipo, self::REQUIEREN_BLOQUE_IVA, true);
    }

    /**
     * Para un tipo de comprobante T, devuelve el tipo del comprobante
     * asociado esperado en una Nota de Credito (misma letra). Util para
     * validar que un cbte_asoc tipo = X sea coherente con cbte_tipo.
     */
    public static function tipoAsocEsperadoParaNotaCredito(int $cbteTipo): ?int
    {
        return match ($cbteTipo) {
            self::NOTA_CREDITO_A => self::FACTURA_A,
            self::NOTA_CREDITO_B => self::FACTURA_B,
            self::NOTA_CREDITO_C => self::FACTURA_C,
            self::NOTA_CREDITO_M => self::FACTURA_M,
            default              => null,
        };
    }
}
