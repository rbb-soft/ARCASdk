<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * La fila fallido alcanzo el maximo de intentos configurado
 * (idempotencia_max_intentos). Requiere resetExternalId() administrativo.
 */
class MaxIdempotencyAttemptsException extends ArcaException
{
}
