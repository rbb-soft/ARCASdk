<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Support;

use Closure;
use Rbbsoft\ArcaSdk\Exceptions\CbteRechazadoException;
use Rbbsoft\ArcaSdk\Exceptions\PadronArcaTransientException;
use Rbbsoft\ArcaSdk\Exceptions\PadronProtocolException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeArcaTransientException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeProtocolException;
use SoapFault;
use Throwable;

/**
 * Politica centralizada de reintentos y clasificacion de transitoriedad.
 *
 * Reglas (del plan maestro, seccion 4):
 *
 *  - isTransient() es la UNICA fuente de verdad sobre si un fallo es
 *    reintentable. El bucle de retry en execute() y la futura capa de
 *    Idempotencia (Phase 5, es_fallo_infra) la comparten para nunca
 *    discrepar.
 *
 *  - Default-deny: codigos desconocidos son NO transitorios. Para
 *    incorporar uno a la allowlist se hace explicito aca, no en cada
 *    caller.
 *
 *  - Transitorios: red, timeout, SoapFault HTTP/WSDL, HTTP 5xx,
 *    codigo ARCA 9999 (observacion/evento).
 *
 *  - NO transitorios: rechazos funcionales (CbteRechazadoException),
 *    errores de validacion del caller (ValidationException), fallos de
 *    protocolo estructurales (WsfeProtocolException con causa
 *    estructural o desconocida).
 *
 *  - WsfeProtocolException SOLO se reintenta cuando la causa es
 *    body vacio/truncado, HTML de gateway, o respuesta asociada a
 *    HTTP 5xx.
 */
final class RetryPolicy
{
    /** Codigo ARCA que indica "infra transitoria" en observaciones/eventos. */
    public const ARCA_TRANSIENT_CODE = 9999;

    /** Jitter: ±25% del backoff base. */
    private const JITTER_RATIO = 0.25;

    /**
     * Clasifica una excepcion como transitoria o no. Funcion pura: no
     * toca estado, no tiene side effects. La misma funcion es la que
     * usara la capa de Idempotencia en Phase 5 para clasificar
     * es_fallo_infra.
     */
    public static function isTransient(Throwable $e): bool
    {
        // 1) Rechazo funcional ARCA -> NUNCA transitorio.
        if ($e instanceof CbteRechazadoException) {
            return false;
        }
        // 2) Validacion del caller -> NUNCA transitorio.
        if ($e instanceof ValidationException) {
            return false;
        }
        // 3) WsfeArcaTransientException (codigo 9999 normalizado) -> transitorio.
        if ($e instanceof WsfeArcaTransientException) {
            return true;
        }
        // 3b) PadronArcaTransientException (codigo 9999 normalizado en
        //     el padron A13) -> transitorio.
        if ($e instanceof PadronArcaTransientException) {
            return true;
        }
        // 4) WsfeProtocolException: transitorio solo si la causa es
        //    body vacio/truncado, HTML de gateway o HTTP 5xx.
        if ($e instanceof WsfeProtocolException) {
            return self::isProtocolKindTransient($e->kind);
        }
        // 4b) PadronProtocolException: misma politica que WSFE.
        if ($e instanceof PadronProtocolException) {
            return self::isProtocolKindTransient($e->kind);
        }
        // 5) SoapFault: HTTP, WSDL, o red detectable.
        if ($e instanceof SoapFault) {
            return self::isSoapFaultTransient($e);
        }
        // 6) Excepciones de red (RuntimeException con marcadores tipicos).
        if (self::isNetworkError($e)) {
            return true;
        }
        // 7) Default-deny: desconocidos son no transitorios.
        return false;
    }

    /**
     * Ejecuta $op con reintentos acotados. Cada vez que $op lanza una
     * excepcion transitoria, espera un backoff exponencial con jitter
     * (±25%) y vuelve a intentarla. Cuando agota los intentos, relanza
     * la ultima excepcion.
     *
     * Si la excepcion NO es transitoria, se relanza inmediatamente
     * (no se consume ningun intento adicional).
     *
     * @template T
     * @param Closure(): T $op
     * @param int $maxAttempts      Cantidad maxima de intentos (>= 1). El primero cuenta.
     * @param int $baseBackoffMs    Backoff base en milisegundos (> 0).
     * @param int $maxBackoffMs     Tope de backoff en milisegundos (>= baseBackoffMs).
     * @param (Closure(int): void)|null $sleeper  Callable que duerme $ms milisegundos.
     *                              Por defecto usa usleep(). Inyectable para tests.
     * @return T
     */
    public function execute(
        Closure $op,
        int $maxAttempts,
        int $baseBackoffMs,
        int $maxBackoffMs,
        ?Closure $sleeper = null,
    ): mixed {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts debe ser >= 1');
        }
        if ($baseBackoffMs <= 0 || $maxBackoffMs < $baseBackoffMs) {
            throw new \InvalidArgumentException('backoff invalido');
        }
        $sleeper ??= static function (int $ms): void {
            usleep($ms * 1000);
        };

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $op();
            } catch (Throwable $e) {
                if (!self::isTransient($e)) {
                    throw $e;
                }
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                $sleeper(self::computeBackoffMs($attempt, $baseBackoffMs, $maxBackoffMs));
            }
        }
    }

    /**
     * Calcula el backoff (en milisegundos) para el intento $attempt
     * (1-indexed). Antes del intento 1 NO se duerme.
     *
     * Formula:
     *   base  = min(baseBackoffMs * 2^(attempt-1), maxBackoffMs)
     *   final = base con jitter uniforme en [base*0.75, base*1.25]
     *   final >= 1 ms.
     */
    public static function computeBackoffMs(int $attempt, int $baseBackoffMs, int $maxBackoffMs): int
    {
        if ($attempt < 1) {
            return 0;
        }
        // 2^(attempt-1) sin pow() (que devuelve float). Para intentos
        // grandes dejamos saturar en $maxBackoffMs.
        $mult = 1;
        for ($i = 1; $i < $attempt; $i++) {
            $mult *= 2;
            if ($mult > 1_000_000) {
                $mult = 1_000_000;
                break;
            }
        }
        $base = $baseBackoffMs * $mult;
        if ($base > $maxBackoffMs) {
            $base = $maxBackoffMs;
        }
        // Jitter ±25% uniforme. mt_rand para no depender de la
        // implementacion de random_int en cada llamada.
        $jitterRange = (int) floor($base * self::JITTER_RATIO);
        $delta = $jitterRange > 0 ? mt_rand(-$jitterRange, $jitterRange) : 0;
        $final = $base + $delta;
        if ($final < 1) {
            $final = 1;
        }
        return $final;
    }

    /**
     * @see WsfeProtocolException kinds
     */
    private static function isProtocolKindTransient(?string $kind): bool
    {
        if ($kind === null) {
            // Excepciones lanzadas sin kind explicito: tratar como
            // estructural / desconocido -> no transitorio.
            return false;
        }
        return in_array($kind, [
            WsfeProtocolException::KIND_EMPTY_BODY,
            WsfeProtocolException::KIND_HTML_GATEWAY,
            WsfeProtocolException::KIND_HTTP_5XX,
        ], true);
    }

    private static function isSoapFaultTransient(SoapFault $e): bool
    {
        $code = (string) $e->faultcode;
        $msg  = strtolower($e->getMessage());

        // HTTP-level faults (codigo de SoapFault tipicamente "HTTP")
        if (strcasecmp($code, 'HTTP') === 0) {
            return true;
        }
        // WSDL-level faults
        if (strcasecmp($code, 'WSDL') === 0) {
            return true;
        }
        // Ciertos codigos del W3C SOAP
        if (strcasecmp($code, 'soap:Client') === 0 && self::msgMatchesNetworkOrTimeout($msg)) {
            return true;
        }
        if (strcasecmp($code, 'soap:Server') === 0) {
            // soap:Server suele ser infra transitoria.
            return true;
        }

        // Fallback: mirar el mensaje por marcadores de red.
        return self::msgMatchesNetworkOrTimeout($msg);
    }

    private static function isNetworkError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return self::msgMatchesNetworkOrTimeout($msg);
    }

    private static function msgMatchesNetworkOrTimeout(string $msg): bool
    {
        // Marcadores tipicos de SoapClient / cURL / DNS / TCP / TLS.
        $needles = [
            'could not connect',
            'connection refused',
            'connection reset',
            'connection timed out',
            'timed out',
            'timeout',
            'network is unreachable',
            'no route to host',
            'temporary failure in name resolution',
            'failed to connect',
            'ssl: ',
            'curl error',
            'resolving timed out',
            'no such host',
            'server has gone away',
            'broken pipe',
        ];
        foreach ($needles as $n) {
            if (str_contains($msg, $n)) {
                return true;
            }
        }
        return false;
    }
}
