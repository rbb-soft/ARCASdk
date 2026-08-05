<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * Falla la recuperacion de un comprobante zombie: ARCA no confirma ausencia
 * inequivoca y la consulta no es verificable, o se agota la retry policy
 * sobre el flujo de recuperacion.
 */
class ZombieRecoveryFailedException extends ArcaException
{
    public function __construct(string $message, public readonly bool $esFalloInfra = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
