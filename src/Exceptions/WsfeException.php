<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * Fallos de infraestructura de WSFE: red, timeout, SoapFault HTTP/WSDL,
 * 5xx, codigo ARCA 9999. Por defecto transitorio.
 */
class WsfeException extends ArcaException
{
}
