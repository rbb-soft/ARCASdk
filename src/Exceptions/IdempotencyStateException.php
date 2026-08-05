<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * Estado idempotente incoherente: fila emitido sin CAE, snapshot corrupto,
 * estado terminal con datos inconsistentes, o legacy sin informacion
 * suficiente para recuperarse. Requiere reconciliacion/revision manual.
 */
class IdempotencyStateException extends ArcaException
{
}
