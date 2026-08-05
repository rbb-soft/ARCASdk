<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsaa;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Wsaa\NullTicketCache;
use Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso;

final class NullTicketCacheTest extends TestCase
{
    private const CUIT = '20123456786';
    private const WSN = 'wsfe';

    private function makeTicket(DateTimeImmutable $exp): TicketDeAcceso
    {
        return new TicketDeAcceso(
            cuit: self::CUIT,
            wsn: self::WSN,
            token: 'TKN',
            sign: 'SGN',
            expirationTimeUtc: $exp,
            source: 'wsfe',
        );
    }

    public function test_load_devuelve_null_si_no_hay_fila(): void
    {
        $cache = new NullTicketCache();
        $this->assertNull($cache->load(self::CUIT, self::WSN));
    }

    public function test_save_y_load_roundtrip(): void
    {
        $cache = new NullTicketCache();
        $exp = new DateTimeImmutable('2030-01-01T10:00:00+00:00');
        $cache->save($this->makeTicket($exp));
        $loaded = $cache->load(self::CUIT, self::WSN);
        $this->assertNotNull($loaded);
        $this->assertSame('TKN', $loaded->token);
        $this->assertSame('SGN', $loaded->sign);
        $this->assertSame($exp->format('Y-m-d H:i:s'), $loaded->expirationTimeUtc->format('Y-m-d H:i:s'));
    }

    public function test_save_sobreescribe_ta_previo(): void
    {
        $cache = new NullTicketCache();
        $exp1 = new DateTimeImmutable('2030-01-01T10:00:00+00:00');
        $cache->save($this->makeTicket($exp1));
        $exp2 = new DateTimeImmutable('2031-01-01T10:00:00+00:00');
        $ticket2 = new TicketDeAcceso(self::CUIT, self::WSN, 'TKN2', 'SGN2', $exp2);
        $cache->save($ticket2);
        $loaded = $cache->load(self::CUIT, self::WSN);
        $this->assertSame('TKN2', $loaded->token);
    }

    public function test_load_devuelve_null_si_vencido_con_margen(): void
    {
        // expira hace 10 segundos, margen 60s -> vencido
        $now = new DateTimeImmutable('2025-06-01T12:00:00+00:00');
        $exp = $now->modify('-10 seconds');
        $cache = new NullTicketCache(expiryMarginSeconds: 60, clock: fn() => $now);
        $cache->save($this->makeTicket($exp));
        $this->assertNull($cache->load(self::CUIT, self::WSN));
    }

    public function test_load_devuelve_ticket_si_vigente_con_margen(): void
    {
        $now = new DateTimeImmutable('2025-06-01T12:00:00+00:00');
        // expira en 5 minutos, margen 60s -> vigente (5min > 1min)
        $exp = $now->modify('+5 minutes');
        $cache = new NullTicketCache(expiryMarginSeconds: 60, clock: fn() => $now);
        $cache->save($this->makeTicket($exp));
        $loaded = $cache->load(self::CUIT, self::WSN);
        $this->assertNotNull($loaded);
        $this->assertSame('TKN', $loaded->token);
    }

    public function test_flush_borra(): void
    {
        $cache = new NullTicketCache();
        $cache->save($this->makeTicket(new DateTimeImmutable('2030-01-01T10:00:00+00:00')));
        $this->assertNotNull($cache->load(self::CUIT, self::WSN));
        $cache->flush(self::CUIT, self::WSN);
        $this->assertNull($cache->load(self::CUIT, self::WSN));
        // flush idempotente
        $cache->flush(self::CUIT, self::WSN);
        $this->assertNull($cache->load(self::CUIT, self::WSN));
    }

    public function test_getTokenInfo_devuelve_null_si_no_hay_fila(): void
    {
        $cache = new NullTicketCache();
        $this->assertNull($cache->getTokenInfo(self::CUIT, self::WSN));
    }

    public function test_getTokenInfo_con_is_valid(): void
    {
        $now = new DateTimeImmutable('2025-06-01T12:00:00+00:00');
        $exp = $now->modify('+5 minutes');
        $cache = new NullTicketCache(expiryMarginSeconds: 60, clock: fn() => $now);
        $cache->save($this->makeTicket($exp));
        $info = $cache->getTokenInfo(self::CUIT, self::WSN);
        $this->assertIsArray($info);
        $this->assertSame(self::CUIT, $info['cuit']);
        $this->assertSame(self::WSN, $info['wsn']);
        $this->assertSame('TKN', $info['token']);
        $this->assertSame('SGN', $info['sign']);
        $this->assertTrue($info['is_valid']);
    }

    public function test_loadOrAcquire_devuelve_cache_si_existe(): void
    {
        $cache = new NullTicketCache();
        $cache->save($this->makeTicket(new DateTimeImmutable('2030-01-01T10:00:00+00:00')));
        $calls = 0;
        $result = $cache->loadOrAcquire(self::CUIT, self::WSN, function () use (&$calls) {
            $calls++;
            return $this->makeTicket(new DateTimeImmutable('2030-01-01T10:00:00+00:00'));
        });
        $this->assertSame(0, $calls, 'producer no debe invocarse si el cache tiene un TA vigente');
        $this->assertSame('TKN', $result->token);
    }

    public function test_loadOrAcquire_invoca_producer_si_no_hay_y_guarda(): void
    {
        $cache = new NullTicketCache();
        $calls = 0;
        $exp = new DateTimeImmutable('2030-01-01T10:00:00+00:00');
        $result = $cache->loadOrAcquire(self::CUIT, self::WSN, function () use (&$calls, $exp) {
            $calls++;
            return $this->makeTicket($exp);
        });
        $this->assertSame(1, $calls);
        $this->assertSame('TKN', $result->token);
        // segunda llamada -> cache hit
        $result2 = $cache->loadOrAcquire(self::CUIT, self::WSN, function () use (&$calls) {
            $calls++;
            return $this->makeTicket(new DateTimeImmutable('2030-01-01T10:00:00+00:00'));
        });
        $this->assertSame(1, $calls);
        $this->assertSame('TKN', $result2->token);
    }

    public function test_llaves_distintas_por_cuit_o_wsn(): void
    {
        $cache = new NullTicketCache();
        $cache->save($this->makeTicket(new DateTimeImmutable('2030-01-01T10:00:00+00:00')));
        $this->assertNotNull($cache->load(self::CUIT, self::WSN));
        $this->assertNull($cache->load('99999999999', self::WSN));
        $this->assertNull($cache->load(self::CUIT, 'wsaa'));
    }
}
