<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * La respuesta SOAP no es SoapFault pero no puede normalizarse
 * (body vacio/truncado, HTML de gateway, estructura SOAP incompatible,
 * campo obligatorio ausente de forma consistente).
 *
 * Se reintenta solo cuando la causa es body vacio/truncado, HTML de
 * gateway o respuesta asociada a HTTP 5xx. Cambios de contrato,
 * campos faltantes consistentes o estructura SOAP incompatible son NO
 * transitorios (kinds "structural" / "unknown").
 */
class WsfeProtocolException extends WsfeException
{
    /** Body SOAP vacio, truncado o sin elemento de respuesta esperado. */
    public const KIND_EMPTY_BODY  = 'empty_body';
    /** Respuesta HTML de un gateway/proxy (no es XML). */
    public const KIND_HTML_GATEWAY = 'html_gateway';
    /** Respuesta con encabezado de estado HTTP 5xx detectable. */
    public const KIND_HTTP_5XX     = 'http_5xx';
    /** Estructura SOAP presente pero incompatible/campos faltantes consistentes. */
    public const KIND_STRUCTURAL   = 'structural';
    /** Causa desconocida. Default-deny en el retry. */
    public const KIND_UNKNOWN      = 'unknown';

    public function __construct(
        string $message,
        public readonly ?string $kind = null,
        public readonly ?string $rawExcerpt = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Helper para construir una excepcion de protocolo con kind explicito.
     */
    public static function emptyBody(string $rawExcerpt, ?\Throwable $previous = null): self
    {
        return new self(
            'WSFE: respuesta SOAP vacia o truncada',
            self::KIND_EMPTY_BODY,
            $rawExcerpt,
            $previous,
        );
    }

    public static function htmlGateway(string $rawExcerpt, ?\Throwable $previous = null): self
    {
        return new self(
            'WSFE: respuesta HTML de gateway/proxy (no es SOAP)',
            self::KIND_HTML_GATEWAY,
            $rawExcerpt,
            $previous,
        );
    }

    public static function http5xx(string $rawExcerpt, ?\Throwable $previous = null): self
    {
        return new self(
            'WSFE: respuesta HTTP 5xx',
            self::KIND_HTTP_5XX,
            $rawExcerpt,
            $previous,
        );
    }

    public static function structural(string $message, ?string $rawExcerpt = null, ?\Throwable $previous = null): self
    {
        return new self(
            $message,
            self::KIND_STRUCTURAL,
            $rawExcerpt,
            $previous,
        );
    }
}
