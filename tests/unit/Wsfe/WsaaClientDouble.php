<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsfe;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso;

/**
 * Test double para la fuente de Ticket de Acceso consumida por
 * WsfeClient. NO extiende WsaaClient (que es final) ni lo modifica;
 * expone:
 *  - asTokenProvider(): Closure que el WsfeClient consume.
 *  - Inspeccion: cantidad de getToken() y WSNs pedidos.
 *
 * Uso:
 *   $wsaa = new WsaaClientDouble();
 *   $client = new WsfeClient($config, $wsaa->asTokenProvider(), $retry, $soap);
 *   $client->dummy();
 *   $this->assertSame(1, $wsaa->callCount);
 *   $this->assertSame(['wsfe'], $wsaa->wsnRequests);
 */
final class WsaaClientDouble
{
    public int $callCount = 0;
    /** @var string[] */
    public array $wsnRequests = [];

    private ?TicketDeAcceso $next;

    public function __construct(?TicketDeAcceso $next = null)
    {
        $this->next = $next ?? new TicketDeAcceso(
            cuit: '00000000000',
            wsn: 'wsfe',
            token: 'TKN_TEST',
            sign: 'SGN_TEST',
            expirationTimeUtc: new DateTimeImmutable('2099-01-01T00:00:00+00:00'),
            source: 'memory',
        );
    }

    public function setNextTicket(TicketDeAcceso $t): void
    {
        $this->next = $t;
    }

    public function asTokenProvider(): Closure
    {
        $self = $this;
        return static function (string $wsn) use ($self): TicketDeAcceso {
            $self->callCount++;
            $self->wsnRequests[] = $wsn;
            if ($self->next === null) {
                throw new \RuntimeException('WsaaClientDouble: sin ticket configurado');
            }
            return $self->next;
        };
    }
}
