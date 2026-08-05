<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * ARCA devolvio una observacion o evento con codigo 9999. Por definicion
 * de ARCA, ese codigo indica "fallo transitorio de infraestructura".
 *
 * El WsfeClient lo lanza al normalizar la respuesta (no del SoapFault
 * crudo, sino del payload normalizado). Es transient => retryable.
 *
 * El IdempotenciaRepository (Phase 5) lo trata como es_fallo_infra=1 y
 * el orquestador persiste la fila como fallido antes de propagar la
 * excepcion.
 */
class WsfeArcaTransientException extends WsfeException
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
