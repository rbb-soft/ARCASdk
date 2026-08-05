<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsaa;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso;

final class TicketDeAccesoTest extends TestCase
{
    public function test_constructor_y_accesores(): void
    {
        $exp = new DateTimeImmutable('2030-01-01T10:00:00+00:00');
        $t = new TicketDeAcceso(
            cuit: '20123456786',
            wsn: 'wsfe',
            token: 'TOKEN_BASE64',
            sign: 'SIGN_BASE64',
            expirationTimeUtc: $exp,
            source: 'wsfe',
        );

        $this->assertSame('20123456786', $t->cuit);
        $this->assertSame('wsfe', $t->wsn);
        $this->assertSame('TOKEN_BASE64', $t->token);
        $this->assertSame('SIGN_BASE64', $t->sign);
        $this->assertSame($exp, $t->expirationTimeUtc);
        $this->assertSame('wsfe', $t->source);
    }

    public function test_inmutabilidad_todas_las_propiedades_son_readonly(): void
    {
        $exp = new DateTimeImmutable('2030-01-01T10:00:00+00:00');
        $t = new TicketDeAcceso('20123456786', 'wsfe', 't', 's', $exp);

        $reflection = new \ReflectionClass($t);
        foreach ($reflection->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "propiedad {$prop->getName()} debe ser readonly");
        }
    }

    public function test_source_default_wsfe(): void
    {
        $t = new TicketDeAcceso('20123456786', 'wsfe', 't', 's', new DateTimeImmutable('2030-01-01T10:00:00+00:00'));
        $this->assertSame('wsfe', $t->source);
    }

    public function test_isValidAt_true_futuro(): void
    {
        $exp = new DateTimeImmutable('2030-01-01T10:00:00+00:00');
        $t = new TicketDeAcceso('20123456786', 'wsfe', 't', 's', $exp);
        $now = new DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $this->assertTrue($t->isValidAt($now));
    }

    public function test_isValidAt_false_vencido(): void
    {
        $exp = new DateTimeImmutable('2024-01-01T10:00:00+00:00');
        $t = new TicketDeAcceso('20123456786', 'wsfe', 't', 's', $exp);
        $now = new DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $this->assertFalse($t->isValidAt($now));
    }

    public function test_isValidAt_margen(): void
    {
        // expira en 10 s. Margen 60 s -> debe considerarse vencido.
        $now = new DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $exp = $now->modify('+10 seconds');
        $t = new TicketDeAcceso('20123456786', 'wsfe', 't', 's', $exp);
        $this->assertFalse($t->isValidAt($now, 60));
        $this->assertTrue($t->isValidAt($now, 0));
        $this->assertTrue($t->isValidAt($now, 5));
        $this->assertFalse($t->isValidAt($now, 11));
    }

    public function test_normalizeExpiration_objeto_utc(): void
    {
        $arg = new DateTimeImmutable('2030-01-01T07:00:00-03:00');
        $out = TicketDeAcceso::normalizeExpiration($arg);
        $this->assertSame('UTC', $out->getTimezone()->getName());
        $this->assertSame('2030-01-01T10:00:00+00:00', $out->format('Y-m-d\TH:i:sP'));
    }

    public function test_normalizeExpiration_string_con_offset(): void
    {
        $out = TicketDeAcceso::normalizeExpiration('2030-01-01T07:00:00-03:00');
        $this->assertSame('UTC', $out->getTimezone()->getName());
        $this->assertSame('2030-01-01T10:00:00+00:00', $out->format('Y-m-d\TH:i:sP'));
    }

    public function test_normalizeExpiration_string_con_explicit_utc_offset(): void
    {
        $out = TicketDeAcceso::normalizeExpiration('2030-01-01T10:00:00+00:00');
        $this->assertSame('UTC', $out->getTimezone()->getName());
        $this->assertSame('2030-01-01T10:00:00+00:00', $out->format('Y-m-d\TH:i:sP'));
    }

    public function test_normalizeExpiration_acepta_microsegundos_y_milisegundos(): void
    {
        // ARCA a veces devuelve fractional seconds; deben aceptarse.
        $out = TicketDeAcceso::normalizeExpiration('2030-01-01T10:00:00.123-03:00');
        $this->assertSame('UTC', $out->getTimezone()->getName());
        $this->assertSame('2030-01-01T13:00:00+00:00', $out->format('Y-m-d\TH:i:sP'));
    }
}
