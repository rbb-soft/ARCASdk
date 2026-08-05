<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsaa;

use Closure;

/**
 * Contrato comun para caches de Ticket de Acceso.
 *
 * El SDK usa una envoltura de dos niveles (NullTicketCache en memoria del
 * Singleton + MysqlTicketCache compartido entre workers). Ambas
 * implementaciones respetan la misma semantica de validez: un TA es
 * valido mientras (expirationTimeUtc - expiryMarginSeconds) > now, donde
 * now SIEMPRE se evalua en PHP contra UTC. Nunca se usa NOW() /
 * UTC_TIMESTAMP() en la logica de vigencia.
 *
 * Metodos:
 *  - load(): lee del nivel actual. Devuelve null si no hay fila o si
 *    la fila existe pero esta vencida (con margen).
 *  - save(): persiste (UPSERT) el TA recibido.
 *  - loadOrAcquire(): con exclusion mutua por (cuit, wsn), hace
 *    double-check del cache y, si sigue vacio, invoca al producer
 *    pasado por el caller para generar un TA fresco. Pensado para
 *    envolver WsaaClient::getToken() en una sola llamada.
 *  - flush(): borrado exacto de la fila (operacion administrativa /
 *    recuperacion tras error de protocolo).
 *  - getTokenInfo(): devuelve la fila actual para diagnostico, sin
 *    evaluar la vigencia (la incluye como flag calculado).
 */
interface TicketCacheInterface
{
    /**
     * Devuelve el TA vigente para ($cuit, $wsn) o null si no existe /
     * esta vencido. La vigencia se evalua en PHP contra UTC.
     */
    public function load(string $cuit, string $wsn): ?TicketDeAcceso;

    /**
     * Persiste (UPSERT) el TA. La implementacion debe manejar el caso
     * de fila preexistente.
     */
    public function save(TicketDeAcceso $ticket): void;

    /**
     * Adquiere exclusion para ($cuit, $wsn), hace double-check del cache
     * y, si no hay TA vigente, invoca $producer() (que se espera devuelva
     * un TicketDeAcceso fresco, ej. via WsaaClient::getToken en su nivel
     * mas bajo). Persiste el resultado y lo devuelve.
     *
     * @param Closure(): TicketDeAcceso $producer
     */
    public function loadOrAcquire(string $cuit, string $wsn, Closure $producer): TicketDeAcceso;

    /**
     * Borra la fila (cuit, wsn). Idempotente: no falla si no existe.
     */
    public function flush(string $cuit, string $wsn): void;

    /**
     * Devuelve un array con la fila cruda + un flag is_valid calculado.
     * Pensado para diagnostico / herramientas administrativas. Devuelve
     * null si la fila no existe.
     *
     * @return array<string, mixed>|null
     */
    public function getTokenInfo(string $cuit, string $wsn): ?array;
}
