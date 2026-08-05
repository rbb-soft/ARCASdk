<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * Fallos de infraestructura de WSAA: red, firma, loginCms, cache de TA.
 * Por defecto se considera transitorio salvo que el caller lo clasifique
 * distinto.
 */
class WsaaException extends ArcaException
{
}
