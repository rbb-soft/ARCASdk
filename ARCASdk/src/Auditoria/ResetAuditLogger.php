<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Auditoria;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Rbbsoft\ArcaSdk\Idempotencia\FilaEmision;
use Throwable;

/**
 * Wrapper chico sobre un PSR-3 LoggerInterface que formatea y emite
 * la entrada de auditoria para `ArcaSdk::resetExternalId()`.
 *
 * El plan maestro (seccion 8) exige:
 *  - "antes de modificar la fila, escribir en un sink append-only
 *    externo a `arca_emisiones_idempotencia` ... una copia, operador,
 *    motivo obligatorio y timestamp"
 *  - "si el log falla, abortar el reset"
 *
 * Decisiones de diseno:
 *  - El logger PSR-3 ES el sink. La entrada se emite con info() para
 *    el camino normal, warning() cuando el reset es sobre un
 *    comprobante emitido (posible duplicado en ARCA).
 *  - El formato del mensaje es `clave=valor` separado por espacios,
 *    con el context array como segundo argumento del PSR-3. Asi un
 *    logger estructurado (json, syslog) puede extraer los campos
 *    por nombre sin parsear la cadena.
 *  - audit() retorna true si la entrada fue "aceptada" por el
 *    logger. PSR-3 retorna void, pero si una implementacion
 *    customizada devuelve false (indicativo de falla de sink), se
 *    aborta el reset. Si lanza, el caller aborta por excepcion.
 */
final class ResetAuditLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Emite la entrada de auditoria para un reset. Retorna true si
     * la entrada fue aceptada por el logger.
     *
     * El array $context debe traer los campos documentados por el
     * plan (operator, motivo, timestamp_utc, force_flag, etc.).
     * El mensaje se construye a partir del context para que ambos
     * (string parseable + array estructurado) queden en el log.
     *
     * @param array<string, mixed> $context
     * @throws Throwable Re-lanza la excepcion del logger subyacente
     *                   para que el caller aborte el reset.
     */
    public function audit(array $context, string $message): bool
    {
        $result = $this->logger->info($message, $context);
        // PSR-3 retorna void; si una implementacion customizada
        // devuelve false (sink saturado, etc.) abortamos el reset.
        if ($result === false) {
            return false;
        }
        return true;
    }

    /**
     * Emite un WARNING cuando el reset se completo sobre una fila
     * `emitido`. La razon: el comprobante sigue existiendo en ARCA;
     * reusar el UUID crea una emision logica nueva capaz de
     * duplicarlo.
     *
     * @param array<string, mixed> $context
     */
    public function warnDuplicado(string $message, array $context): void
    {
        $this->logger->warning($message, $context);
    }

    /**
     * Construye el context estandar de auditoria a partir de una
     * fila y los parametros del reset. El timestamp_utc se calcula
     * una sola vez por llamado (regla del plan: una sola fuente de
     * verdad para el reloj).
     *
     * @return array<string, mixed>
     */
    public static function buildContext(
        FilaEmision $fila,
        string $operator,
        string $motivo,
        bool $force,
        bool $forceEmitido,
        DateTimeImmutable $now,
    ): array {
        $cbteNroEnviado = $fila->cbteNroEnviado;
        $cae = $fila->cae;
        $caeFchVto = $fila->caeFchVto?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $cbteNroConfirmado = $fila->cbteNroConfirmado;
        $cbteFchEnviado = $fila->cbteFchEnviado?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

        return [
            'external_id'         => $fila->externalId,
            'cuit'                => $fila->cuit,
            'punto_venta'         => $fila->puntoVenta,
            'cbte_tipo'           => $fila->cbteTipo,
            'estado'              => $fila->estado,
            'intento'             => $fila->intento,
            'es_fallo_infra'      => $fila->esFalloInfra ? 1 : 0,
            'cbte_nro_enviado'    => $cbteNroEnviado,
            'cbte_fch_enviado'    => $cbteFchEnviado,
            'cae'                 => $cae,
            'cae_fch_vto'         => $caeFchVto,
            'cbte_nro_confirmado' => $cbteNroConfirmado,
            'operator'            => $operator,
            'motivo'              => $motivo,
            'timestamp_utc'       => $now->format('Y-m-d H:i:s'),
            'force_flag'          => ($force || $forceEmitido) ? 1 : 0,
        ];
    }

    /**
     * Formatea el context como una linea `clave=valor` para el
     * primer argumento de `LoggerInterface::info()`. Pensado para
     * que un test (o un syslog) pueda parsear con regex.
     *
     * @param array<string, mixed> $context
     */
    public static function formatMessage(array $context): string
    {
        $parts = ['RESET_EXTERNAL_ID'];
        foreach ($context as $k => $v) {
            if ($v === null) {
                $parts[] = $k . '=null';
            } elseif (is_bool($v)) {
                $parts[] = $k . '=' . ($v ? '1' : '0');
            } else {
                $parts[] = $k . '=' . (string) $v;
            }
        }
        return implode(' ', $parts);
    }
}
