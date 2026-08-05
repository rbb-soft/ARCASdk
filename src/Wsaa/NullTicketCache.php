<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsaa;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Cache de Ticket de Acceso en memoria del proceso.
 *
 * Usado por el nivel rapido (Singleton) y como test double para los
 * tests unitarios que no quieren tocar MySQL. La vigencia se evalua
 * en PHP contra UTC con el mismo margen que la implementacion MySQL
 * (parametrizable).
 *
 * No es thread-safe entre procesos: es por-worker. Un orquestador que
 * quiera compartirse entre workers debe componer NullTicketCache (L1)
 * con MysqlTicketCache (L2).
 */
final class NullTicketCache implements TicketCacheInterface
{
    /** @var array<string, TicketDeAcceso> key = "{$cuit}|{$wsn}" */
    private array $store = [];

    private ?Closure $clock;

    /**
     * @param int $expiryMarginSeconds Margen (segundos) que se descuenta
     *                                  de la expiration para evaluar la
     *                                  vigencia. Por defecto 300 (5 min).
     * @param (Closure(): DateTimeImmutable)|null $clock Callable que
     *                                  devuelve la "hora actual" UTC. Por
     *                                  defecto now() UTC.
     */
    public function __construct(
        private readonly int $expiryMarginSeconds = 300,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock;
    }

    public function load(string $cuit, string $wsn): ?TicketDeAcceso
    {
        $key = $this->key($cuit, $wsn);
        if (!isset($this->store[$key])) {
            return null;
        }
        $ticket = $this->store[$key];
        return $ticket->isValidAt($this->now(), $this->expiryMarginSeconds) ? $ticket : null;
    }

    public function save(TicketDeAcceso $ticket): void
    {
        // Preservamos el source del TA entrante. Si viene de WsaaClient
        // con source='wsfe'/'wsaa', el cache no lo reescribe: queremos
        // que la fuente original (la operacion que lo creo) sea visible
        // al diagnostico. Si el caller no proveyo source, usamos
        // 'memory' como fallback.
        $source = $ticket->source !== '' ? $ticket->source : 'memory';
        $stored = new TicketDeAcceso(
            cuit: $ticket->cuit,
            wsn: $ticket->wsn,
            token: $ticket->token,
            sign: $ticket->sign,
            expirationTimeUtc: $ticket->expirationTimeUtc,
            source: $source,
        );
        $this->store[$this->key($ticket->cuit, $ticket->wsn)] = $stored;
    }

    public function loadOrAcquire(string $cuit, string $wsn, Closure $producer): TicketDeAcceso
    {
        // En el nivel in-process no hace falta lock; el guardado es
        // atomico en PHP. Aun asi respetamos el patron double-check para
        // que el comportamiento sea homogeneo con MysqlTicketCache.
        $existing = $this->load($cuit, $wsn);
        if ($existing !== null) {
            return $existing;
        }
        $ticket = $producer();
        $this->save($ticket);
        return $this->load($cuit, $wsn) ?? $ticket;
    }

    public function flush(string $cuit, string $wsn): void
    {
        unset($this->store[$this->key($cuit, $wsn)]);
    }

    public function getTokenInfo(string $cuit, string $wsn): ?array
    {
        $key = $this->key($cuit, $wsn);
        if (!isset($this->store[$key])) {
            return null;
        }
        $t = $this->store[$key];
        return [
            'cuit'              => $t->cuit,
            'wsn'               => $t->wsn,
            'token'             => $t->token,
            'sign'              => $t->sign,
            'expiration_time_utc' => $t->expirationTimeUtc->format('Y-m-d H:i:s'),
            'source'            => $t->source,
            'is_valid'          => $t->isValidAt($this->now(), $this->expiryMarginSeconds),
        ];
    }

    private function key(string $cuit, string $wsn): string
    {
        return $cuit . '|' . $wsn;
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
