<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * Mismo external_id con datos de negocio diferentes (CUIT, PV, tipo o
 * fingerprint). Detectada antes de tomar locks o llamar a ARCA.
 */
class IdempotencyConflictException extends ArcaException
{
}
