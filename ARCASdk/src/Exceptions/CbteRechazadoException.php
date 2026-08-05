<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * ARCA respondio Resultado='R'. Es un rechazo funcional: NO transitorio.
 * La fila se persiste como fallido con es_fallo_infra=0 antes de lanzar.
 */
class CbteRechazadoException extends WsfeException
{
    /**
     * @param array<int, array{codigo:int, mensaje:string}> $observaciones
     */
    public function __construct(
        string $message,
        public readonly array $observaciones = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
