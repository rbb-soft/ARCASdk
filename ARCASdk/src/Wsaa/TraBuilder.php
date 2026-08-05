<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsaa;

use DateTimeImmutable;

/**
 * Builder del TRA (Ticket de Requerimiento de Acceso) que se firma con
 * PKCS#7 y se envia a WSAA.loginCms.
 *
 * Reglas (leidas del plan maestro, seccion 3, punto 1):
 *  - Capturar un unico "now" UTC por TRA y derivar:
 *      uniqueId       = now timestamp (entero)
 *      generationTime = now - wsaa_generation_skew_seconds  (tolerancia drift)
 *      expirationTime = now + wsaa_tra_ttl_seconds           (siempre > generationTime)
 *  - Fechas serializadas como ISO 8601 con offset explicito
 *    (Y-m-d\TH:i:sP), de modo que para UTC queda "+00:00".
 *  - service es un literal ARCA (ej. "wsfe") pasado por el caller.
 *
 * El builder es un value object sin estado: cada llamada a build()
 * recibe todos los parametros necesarios.
 */
final class TraBuilder
{
    /**
     * @param string         $service                Literal ARCA (ej. 'wsfe', 'wsaa').
     * @param DateTimeImmutable $now                  Momento de referencia (UTC).
     * @param int            $generationSkewSeconds  Segundos que se restan a now
     *                                                para obtener generationTime.
     * @param int            $ttlSeconds             Segundos que se suman a now
     *                                                para obtener expirationTime.
     * @param int|null       $uniqueId               Si se omite, se usa $now->getTimestamp().
     */
    public function build(
        string $service,
        DateTimeImmutable $now,
        int $generationSkewSeconds,
        int $ttlSeconds,
        ?int $uniqueId = null,
    ): string {
        if ($generationSkewSeconds < 0) {
            throw new \InvalidArgumentException('generationSkewSeconds debe ser >= 0');
        }
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('ttlSeconds debe ser > 0');
        }
        if ($generationSkewSeconds >= $ttlSeconds) {
            // generationTime = now - skew; expirationTime = now + ttl.
            // Para que expirationTime > generationTime hace falta
            // ttl > skew (porque el intervalo total es ttl + skew).
            // Si ttl <= skew, el TRA nace ya vencido. Lo bloqueamos
            // explicito para no mandar a ARCA un TRA invalido.
            throw new \InvalidArgumentException('ttlSeconds debe ser > generationSkewSeconds');
        }

        $uniqueId ??= $now->getTimestamp();
        $generationTime = $now->modify("-{$generationSkewSeconds} seconds");
        $expirationTime = $now->modify("+{$ttlSeconds} seconds");

        // isoFormat con offset explicito. Para UTC: +00:00.
        $genIso  = $generationTime->format('Y-m-d\TH:i:sP');
        $exprIso = $expirationTime->format('Y-m-d\TH:i:sP');

        // service: ARCA exige un literal puntual (wsfe/wsaa/etc). Lo
        // saneamos para evitar caracteres de control que rompan el XML.
        $safeService = htmlspecialchars($service, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<loginTicketRequest version="1.0">' . "\n"
            . '  <header>' . "\n"
            . '    <uniqueId>' . $uniqueId . '</uniqueId>' . "\n"
            . '    <generationTime>' . $genIso . '</generationTime>' . "\n"
            . '    <expirationTime>' . $exprIso . '</expirationTime>' . "\n"
            . '  </header>' . "\n"
            . '  <service>' . $safeService . '</service>' . "\n"
            . '</loginTicketRequest>';
    }
}
