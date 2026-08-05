<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * La consulta a ARCA devolvio un comprobante pero sus datos NO coinciden
 * con el snapshot inmutable. Posible secuestro de numero; el SDK NO se
 * apropia del CAE y marca la fila como fallido con es_fallo_infra=0.
 */
class CaeSecuestradoException extends ArcaException
{
    /**
     * @param array<string, string> $esperado Campos del snapshot inmutable.
     * @param array<string, string> $recibido  Campos de la respuesta ARCA.
     */
    public function __construct(
        string $message,
        public readonly array $esperado = [],
        public readonly array $recibido = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
