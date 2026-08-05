<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Padron;

use Closure;
use DateTimeImmutable;
use Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso;

/**
 * Test double para la fuente de Ticket de Acceso consumida por
 * PadronClient. NO extiende WsaaClient (que es final) ni lo modifica;
 * expone:
 *  - asTokenProvider(): Closure que el PadronClient consume.
 *  - Inspeccion: cantidad de getToken() y WSNs pedidos.
 *
 * Uso:
 *   $wsaa = new PadronWsaaClientDouble();
 *   $client = new PadronClient($config, $wsaa->asTokenProvider(), $retry, $soap);
 *   $client->obtener(20123456786);
 *   $this->assertSame(1, $wsaa->callCount);
 *   $this->assertSame(['ws_sr_padron_a13'], $wsaa->wsnRequests);
 */
final class PadronWsaaClientDouble
{
    public int $callCount = 0;
    /** @var string[] */
    public array $wsnRequests = [];

    private ?TicketDeAcceso $next;

    public function __construct(?TicketDeAcceso $next = null)
    {
        $this->next = $next ?? new TicketDeAcceso(
            cuit: '00000000000',
            wsn: 'ws_sr_padron_a13',
            token: 'TKN_PADRON_TEST',
            sign: 'SGN_PADRON_TEST',
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
                throw new \RuntimeException('PadronWsaaClientDouble: sin ticket configurado');
            }
            return $self->next;
        };
    }
}
