<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * La fila idempotente esta en_curso dentro del TTL: otro worker la esta
 * procesando con su lease vigente. El caller debe reintentar mas tarde o
 * esperar a que termine.
 */
class EmisionEnCursoException extends ArcaException
{
}
