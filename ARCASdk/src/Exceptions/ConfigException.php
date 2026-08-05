<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * Configuracion invalida o incompleta. Detectada en Config::fromArray() y
 * en la validacion de extensiones/dependencias del Singleton.
 */
class ConfigException extends ArcaException
{
}
