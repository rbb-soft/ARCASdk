<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * Excepcion base para cualquier fallo del web service de padron A13 de
 * ARCA. Capturable como PadronException o, mas ampliamente, como
 * ArcaException. Se subdivide en PadronArcaTransientException (causa
 * transitoria, reintentable por la RetryPolicy) y PadronProtocolException
 * (respuesta malformada, HTML, HTTP 5xx, etc).
 */
class PadronException extends ArcaException
{
}
