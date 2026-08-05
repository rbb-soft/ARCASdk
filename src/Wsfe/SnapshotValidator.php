<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

use DateTimeZone;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;

/**
 * Validador del snapshot inmutable de un comprobante persistido en
 * `request_json` durante una emision (Phase 7, plan seccion 7 paso 3).
 *
 * Responsabilidades:
 *  1. Decodificar el JSON de forma estricta. Si el JSON esta corrupto
 *     (no es JSON, no es un objeto, no es UTF-8 valido), lanzar
 *     `IdempotencyStateException` con mensaje accionable.
 *  2. Verificar la version de schema del snapshot. Para v1, la version
 *     puede estar ausente (compatibilidad hacia atras con filas
 *     emitidas antes de que existiera el campo). Si esta presente,
 *     debe ser `1`.
 *  3. Cross-checkear `punto_venta` y `cbte_tipo` del JSON contra las
 *     columnas dedicadas de la fila. Si contradice, no estamos
 *     comparando el comprobante correcto: `IdempotencyStateException`.
 *  4. Reconstruir un `Comprobante` inmutable a partir del snapshot.
 *     Si la reconstruccion falla por campos faltantes o invalidos,
 *     `IdempotencyStateException` (no `ValidationException`): un
 *     snapshot corrupto NO es un input invalido del caller, es un
 *     bug o corrupcion de almacenamiento y requiere revision manual.
 *  5. Validar `cbteFch` (de la columna DATE de la fila) aceptando
 *     unicamente el formato `YYYY-MM-DD` que el parser documenta.
 *     Cualquier formato inesperado es error de protocolo, no
 *     mismatch de negocio: `IdempotencyStateException`.
 *  6. Si la fila no tiene `cbteFch` o no se puede parsear, falla.
 *
 * Decisiones:
 *  - **Reconstruccion del Comprobante**: NO usamos
 *    `Comprobante::fromArray()` porque ese factory valida el input
 *    del caller (claves desconocidas -> ValidationException) y
 *    queremos que las claves desconocidas en el snapshot CANONICO
 *    (versionado, no input del caller) NO sean rechazadas. En su
 *    lugar, parseamos el JSON y construimos el Comprobante
 *    directamente. La normalizacion de items/alicuotas ya se aplico
 *    cuando se construyo el snapshot original, asi que las leemos
 *    tal cual.
 *  - **El snapshot NO incluye CbteNro ni CbteFch** (lo dice el plan:
 *    esos campos los asigna el SDK y los persiste por separado en
 *    `cbte_nro_enviado` y `cbte_fch_enviado`). Por eso el VO
 *    Comprobante no los tiene; el caller debe usarlos como parametros
 *    separados de `WsfeClient::solicitar()`. Aqui solo validamos la
 *    coherencia entre snapshot y columnas dedicadas.
 *  - **Para NC**: si el snapshot declara `cbtes_asoc`, debe ser un
 *    array no vacio de objetos con `Tipo`, `PtoVta`, `Nro`. Si no,
 *    `IdempotencyStateException`.
 */
final class SnapshotValidator
{
    /** Version de schema que el validador acepta. */
    public const SCHEMA_VERSION = 1;

    /**
     * Valida el snapshot y reconstruye el Comprobante correspondiente.
     *
     * @param string $requestJson Valor de la columna `request_json`
     *                            (LONGTEXT) tal como esta persistido.
     * @param array{punto_venta:int, cbte_tipo:int, cbte_nro_enviado:int, cbte_fch_enviado:?\DateTimeInterface|string} $row
     *                            Subset de columnas dedicadas que se
     *                            cross-checkean contra el JSON.
     *
     * @return Comprobante Reconstruido a partir del snapshot. Nunca null.
     *
     * @throws IdempotencyStateException Si el snapshot esta corrupto,
     *                                   incompleto, o contradice las
     *                                   columnas dedicadas.
     */
    public static function validateAndReconstruct(string $requestJson, array $row): Comprobante
    {
        // 1) JSON parse. Strict mode: no assoc, no json_numeric_check.
        try {
            $decoded = json_decode($requestJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new IdempotencyStateException(
                'snapshot corrupto: JSON invalido - ' . $e->getMessage(),
                0,
                $e,
            );
        }
        if (!is_array($decoded)) {
            throw new IdempotencyStateException(
                'snapshot corrupto: se esperaba objeto JSON, recibio '
                . gettype($decoded)
            );
        }

        // 2) Schema version: opcional en v1 (compatibilidad), requerido
        //    y validado si esta presente.
        if (array_key_exists('schema_version', $decoded)) {
            $sv = $decoded['schema_version'];
            if (!is_int($sv) || $sv !== self::SCHEMA_VERSION) {
                throw new IdempotencyStateException(
                    'snapshot corrupto: schema_version invalido (recibio: '
                    . var_export($sv, true) . ', esperado: ' . self::SCHEMA_VERSION . ')'
                );
            }
        }

        // 3) Cross-check de columnas dedicadas contra el JSON.
        if (!isset($decoded['punto_venta']) || (int) $decoded['punto_venta'] !== (int) $row['punto_venta']) {
            throw new IdempotencyStateException(sprintf(
                'snapshot corrupto: punto_venta del JSON (%s) != columna dedicada (%s)',
                var_export($decoded['punto_venta'] ?? null, true),
                $row['punto_venta']
            ));
        }
        if (!isset($decoded['cbte_tipo']) || (int) $decoded['cbte_tipo'] !== (int) $row['cbte_tipo']) {
            throw new IdempotencyStateException(sprintf(
                'snapshot corrupto: cbte_tipo del JSON (%s) != columna dedicada (%s)',
                var_export($decoded['cbte_tipo'] ?? null, true),
                $row['cbte_tipo']
            ));
        }
        $cbteTipo = (int) $decoded['cbte_tipo'];
        $puntoVenta = (int) $decoded['punto_venta'];

        // 4) cbteFch (de la columna DATE): el unico formato aceptado
        //    por el parser es YYYY-MM-DD. Cualquier otra cosa es
        //    error de protocolo.
        $fchRaw = $row['cbte_fch_enviado'] ?? null;
        if ($fchRaw === null || $fchRaw === '') {
            throw new IdempotencyStateException(
                'snapshot corrupto: cbte_fch_enviado es NULL/vacio'
            );
        }
        if ($fchRaw instanceof \DateTimeInterface) {
            $fchYmd = $fchRaw->format('Y-m-d');
        } else {
            $fchYmd = (string) $fchRaw;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fchYmd)) {
            throw new IdempotencyStateException(
                'snapshot corrupto: cbteFch formato inesperado (recibio: '
                . $fchYmd . ', esperado: YYYY-MM-DD)'
            );
        }
        $parsed = \DateTime::createFromFormat('Y-m-d', $fchYmd, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $fchYmd) {
            throw new IdempotencyStateException(
                'snapshot corrupto: cbteFch no es una fecha valida (recibio: '
                . $fchYmd . ')'
            );
        }

        // 5) cbte_nro_enviado: el campo es obligatorio y debe ser > 0
        //    (la columna es UNSIGNED pero la validacion se hace igual
        //    para mensajes explicitos).
        $cbteNroEnviado = $row['cbte_nro_enviado'] ?? null;
        if ($cbteNroEnviado === null || (int) $cbteNroEnviado <= 0) {
            throw new IdempotencyStateException(sprintf(
                'snapshot corrupto: cbte_nro_enviado invalido (recibio: %s)',
                var_export($cbteNroEnviado, true)
            ));
        }
        $cbteNro = (int) $cbteNroEnviado;

        // 6) Reconstruir el Comprobante. Si cualquier campo falta o es
        //    del tipo equivocado, IdempotencyStateException (no
        //    ValidationException): un snapshot persistido no deberia
        //    ser input del caller.
        try {
            return self::buildComprobante($decoded, $cbteTipo, $puntoVenta);
        } catch (IdempotencyStateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new IdempotencyStateException(
                'snapshot corrupto: no se pudo reconstruir el Comprobante - '
                . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Construye un Comprobante a partir del array decodificado del
     * snapshot. NO valida reglas de negocio (esas ya se aplicaron
     * cuando se creo el snapshot original): solo verifica shape.
     *
     * @param array<string, mixed> $decoded Snapshot decodificado.
     */
    private static function buildComprobante(array $decoded, int $cbteTipo, int $puntoVenta): Comprobante
    {
        // Requeridos por todos los tipos.
        $concepto = self::intOrFail($decoded, 'concepto', 'snapshot');

        // Receptor (sub-objeto en el canonical JSON).
        if (!isset($decoded['receptor']) || !is_array($decoded['receptor'])) {
            throw new IdempotencyStateException(
                'snapshot corrupto: falta bloque "receptor" o no es objeto'
            );
        }
        $rec = $decoded['receptor'];
        $docTipo = self::intOrFail($rec, 'documento_tipo', 'receptor');
        $docNro = self::stringOrFail($rec, 'documento_nro', 'receptor');
        $condIva = self::stringOrFail($rec, 'condicion_iva', 'receptor');

        // Moneda (sub-objeto).
        if (!isset($decoded['moneda']) || !is_array($decoded['moneda'])) {
            throw new IdempotencyStateException(
                'snapshot corrupto: falta bloque "moneda" o no es objeto'
            );
        }
        $mon = $decoded['moneda'];
        $monId = self::stringOrFail($mon, 'id', 'moneda');
        $monCotiz = self::stringOrFail($mon, 'cotiz', 'moneda');

        // Items.
        if (!isset($decoded['items']) || !is_array($decoded['items'])) {
            throw new IdempotencyStateException(
                'snapshot corrupto: falta "items" o no es array'
            );
        }
        $items = self::parseItems($decoded['items']);

        // Importes (defaults a "0.00" si ausentes).
        $impNoGrav = self::stringOrDefault($decoded, 'importe_no_gravado', '0.00');
        $impExento = self::stringOrDefault($decoded, 'importe_exento', '0.00');
        $impOtrosT = self::stringOrDefault($decoded, 'importe_otros_tributos', '0.00');

        // Servicio (solo si concepto != 1).
        $servDesde = null;
        $servHasta = null;
        $vencPago = null;
        if ($concepto !== 1) {
            if (!isset($decoded['servicio']) || !is_array($decoded['servicio'])) {
                throw new IdempotencyStateException(
                    'snapshot corrupto: concepto=' . $concepto . ' requiere bloque "servicio"'
                );
            }
            $srv = $decoded['servicio'];
            $servDesde = self::intOrFail($srv, 'desde', 'servicio');
            $servHasta = self::intOrFail($srv, 'hasta', 'servicio');
            if (isset($srv['vencimiento_pago']) && $srv['vencimiento_pago'] !== null) {
                $vencPago = (int) $srv['vencimiento_pago'];
            }
        }

        // cbtes_asoc (solo NC).
        $cbtesAsoc = [];
        if (TiposComprobante::esNotaCredito($cbteTipo)) {
            if (!isset($decoded['cbtes_asoc']) || !is_array($decoded['cbtes_asoc'])) {
                throw new IdempotencyStateException(
                    'snapshot corrupto: NC requiere "cbtes_asoc" como array'
                );
            }
            foreach ($decoded['cbtes_asoc'] as $idx => $a) {
                if (!is_array($a) || !isset($a['Tipo'], $a['PtoVta'], $a['Nro'])) {
                    throw new IdempotencyStateException(
                        "snapshot corrupto: cbtes_asoc[{$idx}] requiere Tipo/PtoVta/Nro"
                    );
                }
                $aTipo = (int) $a['Tipo'];
                $esperado = TiposComprobante::tipoAsocEsperadoParaNotaCredito($cbteTipo);
                if ($esperado !== null && $aTipo !== $esperado) {
                    throw new IdempotencyStateException(sprintf(
                        'snapshot corrupto: cbtes_asoc[%d].Tipo=%d incompatible con cbte_tipo=%d (esperado %d)',
                        $idx, $aTipo, $cbteTipo, $esperado
                    ));
                }
                $cbtesAsoc[] = [
                    'Tipo'   => $aTipo,
                    'PtoVta' => (int) $a['PtoVta'],
                    'Nro'    => (int) $a['Nro'],
                ];
            }
        }

        return new Comprobante(
            cbteTipo: $cbteTipo,
            puntoVenta: $puntoVenta,
            concepto: $concepto,
            receptorDocumentoTipo: $docTipo,
            receptorDocumentoNro: $docNro,
            receptorCondicionIva: $condIva,
            monId: $monId,
            monCotiz: $monCotiz,
            items: $items,
            importeNoGravado: $impNoGrav,
            importeExento: $impExento,
            importeOtrosTributos: $impOtrosT,
            servicioDesde: $servDesde,
            servicioHasta: $servHasta,
            vencimientoPago: $vencPago,
            cbtesAsoc: $cbtesAsoc,
        );
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<int, array{importe_gravado: string, alicuota_iva: string}>
     */
    private static function parseItems(array $raw): array
    {
        $out = [];
        foreach ($raw as $idx => $item) {
            if (!is_array($item)
                || !isset($item['importe_gravado'], $item['alicuota_iva'])
            ) {
                throw new IdempotencyStateException(
                    "snapshot corrupto: items[{$idx}] requiere importe_gravado y alicuota_iva"
                );
            }
            $out[] = [
                'importe_gravado' => (string) $item['importe_gravado'],
                'alicuota_iva'    => (string) $item['alicuota_iva'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function intOrFail(array $arr, string $key, string $path): int
    {
        if (!array_key_exists($key, $arr)) {
            throw new IdempotencyStateException(
                "snapshot corrupto: falta {$path}.{$key}"
            );
        }
        $v = $arr[$key];
        if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
            throw new IdempotencyStateException(
                "snapshot corrupto: {$path}.{$key} no es entero (recibio: "
                . var_export($v, true) . ')'
            );
        }
        return (int) $v;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function stringOrFail(array $arr, string $key, string $path): string
    {
        if (!array_key_exists($key, $arr)) {
            throw new IdempotencyStateException(
                "snapshot corrupto: falta {$path}.{$key}"
            );
        }
        $v = $arr[$key];
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            throw new IdempotencyStateException(
                "snapshot corrupto: {$path}.{$key} no es string-coercible (recibio: "
                . gettype($v) . ')'
            );
        }
        return (string) $v;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function stringOrDefault(array $arr, string $key, string $default): string
    {
        if (!array_key_exists($key, $arr) || $arr[$key] === null) {
            return $default;
        }
        return (string) $arr[$key];
    }
}
