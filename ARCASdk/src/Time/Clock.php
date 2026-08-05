<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Time;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reloj inyectable que SIEMPRE devuelve UTC.
 *
 * Por que existe:
 *  - El orquestador (Phase 6) necesita "now()" en muchos lugares
 *    (cutoff de TTL, updated_at, expirationTime). Pasarlo por un
 *    contrato explicito es mejor que closures ad-hoc: mantiene
 *    un unico punto de conversion a UTC y facilita la inyeccion
 *    en tests.
 *  - El plan maestro prohibe NOW() y UTC_TIMESTAMP() en SQL: la
 *    unica fuente de verdad de "ahora" es PHP. Esta clase es
 *    esa fuente.
 *
 * Uso:
 *  - Produccion: new SystemClock()  (usa new DateTimeImmutable('now', UTC))
 *  - Tests:      new FixedClock($ymdhis) o new FrozenClock() que avanza
 *                con $clock->advance('+5 seconds')
 */
final class Clock
{
    /** @var (callable(): DateTimeImmutable)|null */
    private $now;

    /**
     * @param (callable(): DateTimeImmutable)|null $now Si es null, el
     *        reloj usa new DateTimeImmutable('now', UTC). Si se pasa,
     *        se usa como proveedor de "now" (util para tests).
     */
    public function __construct(?callable $now = null)
    {
        $this->now = $now;
    }

    /**
     * Devuelve la hora actual como DateTimeImmutable en UTC.
     */
    public function now(): DateTimeImmutable
    {
        if ($this->now !== null) {
            $dt = ($this->now)();
            if (!$dt instanceof DateTimeImmutable) {
                throw new \LogicException('Clock provider debe devolver DateTimeImmutable');
            }
            // Garantizar zona UTC sin importar lo que el provider entregue.
            return $dt->setTimezone(new DateTimeZone('UTC'));
        }
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Devuelve la hora actual formateada como 'Y-m-d H:i:s' UTC,
     * lista para bindear a una columna DATETIME.
     */
    public function nowUtcString(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    /**
     * Helper estatico: devuelve un Clock que entrega siempre el mismo
     * DateTimeImmutable. Para tests deterministas.
     */
    public static function fixed(DateTimeImmutable $at): self
    {
        return new self(static fn(): DateTimeImmutable => $at);
    }
}
