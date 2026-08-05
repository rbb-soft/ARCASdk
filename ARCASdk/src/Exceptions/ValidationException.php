<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * Datos de entrada invalidos antes de tocar WSFE o persistir.
 * NO consume intentos de idempotencia (la fila aun no existe o no muto).
 */
class ValidationException extends ArcaException
{
}
