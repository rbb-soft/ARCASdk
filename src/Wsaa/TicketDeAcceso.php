<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsaa;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Value object inmutable que representa un Ticket de Acceso (TA) devuelto
 * por WSAA o leido del cache.
 *
 * - expirationTimeUtc SIEMPRE es UTC (la conversion se hace al parsear
 *   la respuesta de ARCA respetando su offset).
 * - source describe el origen del TA ('wsfe' para ARCA homologacion,
 *   'wsaa' para produccion, 'cache' para uno leido de cache, 'memory'
 *   para NullTicketCache). Util para diagnostico.
 *
 * Inmutable: no expone setters. La clase de cache crea una nueva instancia
 * al refrescar desde MySQL o desde ARCA.
 */
final class TicketDeAcceso
{
    public function __construct(
        public readonly string $cuit,
        public readonly string $wsn,
        public readonly string $token,
        public readonly string $sign,
        public readonly DateTimeImmutable $expirationTimeUtc,
        public readonly string $source = 'wsfe',
    ) {
    }

    /**
     * Devuelve true si la vigencia (con margen) sigue siendo futura
     * respecto de $now. La margen se resta de la expiration para tener
     * un colchon contra drift / latencia / NTP.
     */
    public function isValidAt(DateTimeImmutable $now, int $expiryMarginSeconds = 0): bool
    {
        $cutoff = $this->expirationTimeUtc->modify("-{$expiryMarginSeconds} seconds");
        return $cutoff > $now;
    }

    /**
     * Helper para construir un TicketDeAcceso a partir de una marca de
     * tiempo que ya viene en UTC (o un string ISO 8601 con offset
     * reconocible). Devuelve siempre un DateTimeImmutable en UTC.
     *
     * @param string|DateTimeImmutable $expirationTime
     */
    public static function normalizeExpiration($expirationTime): DateTimeImmutable
    {
        if ($expirationTime instanceof DateTimeImmutable) {
            return $expirationTime->setTimezone(new DateTimeZone('UTC'));
        }
        // ISO 8601 con offset -> respeta la zona
        $dt = new DateTimeImmutable((string) $expirationTime);
        return $dt->setTimezone(new DateTimeZone('UTC'));
    }
}
