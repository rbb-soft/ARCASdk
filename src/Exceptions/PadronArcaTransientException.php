<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * ARCA devolvio una observacion o evento con codigo 9999 en una operacion
 * del padron A13. Por definicion de ARCA, ese codigo indica "fallo
 * transitorio de infraestructura".
 *
 * El PadronClient lo lanza al normalizar la respuesta del web service
 * (no del SoapFault crudo, sino del payload normalizado). Es transient
 * => retryable por la RetryPolicy.
 */
class PadronArcaTransientException extends PadronException
{
    /**
     * @param array<int, array{codigo: int, mensaje: string}> $observaciones
     */
    public function __construct(
        string $message,
        public readonly array $observaciones = [],
        public readonly ?string $operacion = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
