<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Exceptions\CbteRechazadoException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeArcaTransientException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeProtocolException;
use Rbbsoft\ArcaSdk\Support\RetryPolicy;
use SoapFault;
use stdClass;

/**
 * Cubre la UNICA fuente de verdad de transitoriedad. Cualquier cambio
 * aqui debe mantenerse sincronizado con la capa de Idempotencia
 * (Phase 5) que clasificara es_fallo_infra con la misma funcion.
 */
final class RetryPolicyTest extends TestCase
{
    // -------------------------------------------------------------------
    // isTransient()
    // -------------------------------------------------------------------

    public function test_isTransient_true_para_soapFault_http(): void
    {
        $this->assertTrue(RetryPolicy::isTransient(new SoapFault('HTTP', 'Could not connect to host')));
    }

    public function test_isTransient_true_para_soapFault_wsdl(): void
    {
        $this->assertTrue(RetryPolicy::isTransient(new SoapFault('WSDL', 'Could not load WSDL')));
    }

    public function test_isTransient_true_para_soapFault_soap_server(): void
    {
        $this->assertTrue(RetryPolicy::isTransient(new SoapFault('soap:Server', 'upstream unavailable')));
    }

    public function test_isTransient_true_para_soapFault_con_mensaje_de_red(): void
    {
        $this->assertTrue(RetryPolicy::isTransient(new SoapFault('Server', 'Connection timed out after 5000ms')));
        $this->assertTrue(RetryPolicy::isTransient(new SoapFault('Client', 'Could not connect to host x')));
        $this->assertTrue(RetryPolicy::isTransient(new SoapFault('Server', 'cURL error 28: timeout')));
        $this->assertTrue(RetryPolicy::isTransient(new SoapFault('Server', 'SSL: certificate verify failed')));
        $this->assertTrue(RetryPolicy::isTransient(new SoapFault('Server', 'No route to host')));
    }

    public function test_isTransient_true_para_runtimeexception_de_red(): void
    {
        $this->assertTrue(RetryPolicy::isTransient(new \RuntimeException('Connection refused')));
        $this->assertTrue(RetryPolicy::isTransient(new \RuntimeException('Network is unreachable')));
        $this->assertTrue(RetryPolicy::isTransient(new \RuntimeException('Operation timed out')));
    }

    public function test_isTransient_true_para_wsfe_protocol_empty_body(): void
    {
        $this->assertTrue(RetryPolicy::isTransient(
            WsfeProtocolException::emptyBody('')
        ));
    }

    public function test_isTransient_true_para_wsfe_protocol_html_gateway(): void
    {
        $this->assertTrue(RetryPolicy::isTransient(
            WsfeProtocolException::htmlGateway('<html>...</html>')
        ));
    }

    public function test_isTransient_true_para_wsfe_protocol_http_5xx(): void
    {
        $this->assertTrue(RetryPolicy::isTransient(
            WsfeProtocolException::http5xx('HTTP/1.1 503 Service Unavailable')
        ));
    }

    public function test_isTransient_true_para_observacion_arca_9999(): void
    {
        $this->assertTrue(RetryPolicy::isTransient(
            new WsfeArcaTransientException(
                'observacion 9999',
                [['codigo' => 9999, 'mensaje' => 'reintente']],
                'FECAESolicitar'
            )
        ));
    }

    public function test_isTransient_false_para_cbte_rechazado(): void
    {
        $this->assertFalse(RetryPolicy::isTransient(
            new CbteRechazadoException('rechazo funcional', [
                ['codigo' => 10016, 'mensaje' => '...'],
            ])
        ));
    }

    public function test_isTransient_false_para_validation(): void
    {
        $this->assertFalse(RetryPolicy::isTransient(
            new ValidationException('campo invalido')
        ));
    }

    public function test_isTransient_false_para_wsfe_protocol_structural(): void
    {
        $this->assertFalse(RetryPolicy::isTransient(
            WsfeProtocolException::structural('falta CbteFch')
        ));
    }

    public function test_isTransient_false_para_wsfe_protocol_unknown_kind(): void
    {
        $this->assertFalse(RetryPolicy::isTransient(
            new WsfeProtocolException('mensaje', WsfeProtocolException::KIND_UNKNOWN)
        ));
    }

    public function test_isTransient_false_para_wsfe_protocol_sin_kind(): void
    {
        $this->assertFalse(RetryPolicy::isTransient(
            new WsfeProtocolException('mensaje', null)
        ));
    }

    public function test_isTransient_false_para_arca_10016_rechazo_funcional(): void
    {
        $this->assertFalse(RetryPolicy::isTransient(
            new CbteRechazadoException('10016', [['codigo' => 10016, 'mensaje' => 'balsa']])
        ));
    }

    public function test_isTransient_default_deny_para_desconocidos(): void
    {
        $this->assertFalse(RetryPolicy::isTransient(new \LogicException('?')));
        $this->assertFalse(RetryPolicy::isTransient(new \DomainException('?')));
        $this->assertFalse(RetryPolicy::isTransient(new \TypeError('?')));
    }

    public function test_isTransient_false_para_wsfeexception_generica(): void
    {
        // WsfeException sin kind conocido no es transient por default.
        $this->assertFalse(RetryPolicy::isTransient(
            new WsfeException('error cualquiera')
        ));
    }

    // -------------------------------------------------------------------
    // execute()
    // -------------------------------------------------------------------

    public function test_execute_retry_hasta_max_attempts_y_relanza_ultimo_error(): void
    {
        $policy = new RetryPolicy();
        $invoked = 0;
        $sleeps = [];

        $op = function () use (&$invoked): string {
            $invoked++;
            throw new \RuntimeException('Connection refused');
        };
        $sleeper = function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        };

        try {
            $policy->execute($op, maxAttempts: 3, baseBackoffMs: 100, maxBackoffMs: 1000, sleeper: $sleeper);
            $this->fail('Debio relanzar la ultima excepcion');
        } catch (\RuntimeException $e) {
            $this->assertSame('Connection refused', $e->getMessage());
        }
        $this->assertSame(3, $invoked, 'intenta exactamente maxAttempts veces');
        // Solo duerme entre intentos: 2 sleeps (despues del intento 1 y 2).
        $this->assertCount(2, $sleeps, 'solo duerme entre intentos, no despues del ultimo');
    }

    public function test_execute_no_retry_en_no_transient(): void
    {
        $policy = new RetryPolicy();
        $invoked = 0;
        $sleeps = [];

        $op = function () use (&$invoked): string {
            $invoked++;
            throw new ValidationException('caller data invalid');
        };
        $sleeper = function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        };

        try {
            $policy->execute($op, maxAttempts: 5, baseBackoffMs: 100, maxBackoffMs: 1000, sleeper: $sleeper);
            $this->fail('Debio lanzar ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('caller data invalid', $e->getMessage());
        }
        $this->assertSame(1, $invoked, 'no retry en no-transient: 1 sola invocacion');
        $this->assertCount(0, $sleeps, 'ningun sleep en no-transient');
    }

    public function test_exite_una_exitosa_devuelve_sin_retry(): void
    {
        $policy = new RetryPolicy();
        $invoked = 0;
        $op = function () use (&$invoked): string {
            $invoked++;
            return 'ok';
        };
        $result = $policy->execute($op, 3, 100, 1000, fn() => $this->fail('no debe dormir'));
        $this->assertSame('ok', $result);
        $this->assertSame(1, $invoked);
    }

    public function test_execute_termina_si_intento_intermedio_es_exitoso(): void
    {
        $policy = new RetryPolicy();
        $invoked = 0;
        $op = function () use (&$invoked): string {
            $invoked++;
            if ($invoked < 3) {
                throw new \RuntimeException('Connection refused');
            }
            return 'ok-on-third';
        };
        $result = $policy->execute($op, 5, 50, 500, fn() => null);
        $this->assertSame('ok-on-third', $result);
        $this->assertSame(3, $invoked, 'intenta hasta exito y para');
    }

    public function test_execute_retry_de_codigo_10016_no_transitorio_no_reintenta(): void
    {
        $policy = new RetryPolicy();
        $invoked = 0;
        $op = function () use (&$invoked): string {
            $invoked++;
            throw new CbteRechazadoException('10016 rechazado', [
                ['codigo' => 10016, 'mensaje' => '...'],
            ]);
        };
        try {
            $policy->execute($op, 5, 10, 100, fn() => null);
            $this->fail('Debio lanzar CbteRechazadoException');
        } catch (CbteRechazadoException) {
        }
        $this->assertSame(1, $invoked);
    }

    // -------------------------------------------------------------------
    // computeBackoffMs()
    // -------------------------------------------------------------------

    public function test_backoff_crece_exponencialmente_hasta_tope(): void
    {
        $b1 = RetryPolicy::computeBackoffMs(1, 100, 1000);
        $b2 = RetryPolicy::computeBackoffMs(2, 100, 1000);
        $b3 = RetryPolicy::computeBackoffMs(3, 100, 1000);
        $b4 = RetryPolicy::computeBackoffMs(4, 100, 1000);
        $b5 = RetryPolicy::computeBackoffMs(5, 100, 1000);

        // Bases (sin jitter): 100, 200, 400, 800, 1000 (clamp en attempt 5).
        // Jitter ±25%: el rango superior es base * 1.25, el inferior base * 0.75.
        $this->assertGreaterThanOrEqual(75, $b1);
        $this->assertLessThanOrEqual(125, $b1);
        $this->assertGreaterThanOrEqual(150, $b2);
        $this->assertLessThanOrEqual(250, $b2);
        $this->assertGreaterThanOrEqual(300, $b3);
        $this->assertLessThanOrEqual(500, $b3);
        $this->assertGreaterThanOrEqual(600, $b4);
        $this->assertLessThanOrEqual(1000, $b4);
        // attempt 5: base ya esta en 1000 (clamp). Con jitter ±25%, el
        // bound superior es 1250, no 1000 (no limitamos al base).
        $this->assertGreaterThanOrEqual(750, $b5, 'base 1000 antes del clamp, -25% jitter');
        $this->assertLessThanOrEqual(1250, $b5, 'tope maxBackoffMs +25% jitter');
    }

    public function test_backoff_esta_acotado_por_max_backoff_ms(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $b = RetryPolicy::computeBackoffMs($i, 200, 1000);
            $this->assertLessThanOrEqual(1250, $b, "attempt {$i}: por encima de max+jitter");
            $this->assertGreaterThanOrEqual(1, $b);
        }
    }

    public function test_backoff_jitter_dentro_de_25_por_ciento(): void
    {
        // Para attempt=1, base=100, jitter esperado: [75, 125]
        for ($i = 0; $i < 200; $i++) {
            $b = RetryPolicy::computeBackoffMs(1, 100, 1000);
            $this->assertGreaterThanOrEqual(75, $b);
            $this->assertLessThanOrEqual(125, $b);
        }
    }

    public function test_backoff_intento_0_devuelve_0(): void
    {
        $this->assertSame(0, RetryPolicy::computeBackoffMs(0, 100, 1000));
    }

    public function test_backoff_attempt_grande_no_overflow_y_respeta_tope(): void
    {
        $b = RetryPolicy::computeBackoffMs(50, 200, 1000);
        $this->assertLessThanOrEqual(1250, $b);
        $this->assertGreaterThanOrEqual(1, $b);
    }

    public function test_backoff_con_base_chico_y_max_grande_funciona(): void
    {
        $b = RetryPolicy::computeBackoffMs(3, 1, 100000);
        $this->assertGreaterThanOrEqual(3, $b); // 4 * 0.75 = 3
        $this->assertLessThanOrEqual(5, $b);    // 4 * 1.25 = 5
    }

    public function test_execute_agota_dentro_de_time_budget_pequeno_sin_sleeps_reales(): void
    {
        // 10 intentos con base 1 / max 1 -> backoff siempre ~1ms.
        $policy = new RetryPolicy();
        $invoked = 0;
        $start = microtime(true);

        $op = function () use (&$invoked): string {
            $invoked++;
            throw new \RuntimeException('Connection refused');
        };
        try {
            $policy->execute($op, maxAttempts: 10, baseBackoffMs: 1, maxBackoffMs: 1);
            $this->fail('Debio relanzar');
        } catch (\RuntimeException) {
        }
        $elapsed = microtime(true) - $start;
        $this->assertLessThan(0.5, $elapsed, "no debe demorar (elapsed={$elapsed}s)");
        $this->assertSame(10, $invoked);
    }
}
