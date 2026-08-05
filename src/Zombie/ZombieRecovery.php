<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Zombie;

use DateTimeZone;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\CaeSecuestradoException;
use Rbbsoft\ArcaSdk\Exceptions\CbteRechazadoException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeArcaTransientException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeProtocolException;
use Rbbsoft\ArcaSdk\Exceptions\ZombieRecoveryFailedException;
use Rbbsoft\ArcaSdk\Idempotencia\FilaEmision;
use Rbbsoft\ArcaSdk\Idempotencia\IdempotenciaRepository;
use Rbbsoft\ArcaSdk\Lock\LockManager;
use Rbbsoft\ArcaSdk\Support\RetryPolicy;
use Rbbsoft\ArcaSdk\Time\Clock;
use Rbbsoft\ArcaSdk\Wsfe\Comprobante;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteConsultado;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteEmitido;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteResponse;
use Rbbsoft\ArcaSdk\Wsfe\IvaCalculator;
use Rbbsoft\ArcaSdk\Wsfe\SnapshotComparer;
use Rbbsoft\ArcaSdk\Wsfe\SnapshotValidator;
use Rbbsoft\ArcaSdk\Wsfe\WsfeClient;
use Throwable;

/**
 * Orquestador de la recuperacion de comprobantes zombie (Phase 7).
 *
 * Una fila es "zombie" cuando tiene `cbte_nro_enviado IS NOT NULL`
 * (un worker reservo el numero y/o llamo a ARCA pero murio antes de
 * completar la transicion a `emitido` o `fallido`). El siguiente worker
 * que reabre la fila debe:
 *
 *  1. Cargar PV, tipo, numero, fecha y `request_json` de la fila.
 *  2. Consultar ARCA por ese numero exacto (`FECompConsultar`).
 *  3. Si ARCA confirma el comprobante y coincide con el snapshot,
 *     persistir el CAE recuperado como `emitido` y devolverlo SIN
 *     volver a llamar a `FECAESolicitar` (eso duplicaria el
 *     comprobante en ARCA).
 *  4. Si ARCA confirma el comprobante pero NO coincide, no apropiarse
 *     del CAE: marcar la fila `fallido` con `es_fallo_infra=0` y
 *     lanzar `CaeSecuestradoException` (operador debe revisar).
 *  5. Si ARCA reporta que el comprobante no existe (codigo 601):
 *     - Si `ultimoAutorizado < cbteNroEnviado`: el numero esta libre,
 *       re-emitir el mismo snapshot con el mismo `cbteNro` y
 *       `cbteFch` persistidos (NO recalcular `ultimo+1`, NO usar
 *       la fecha de hoy).
 *     - Si `ultimoAutorizado >= cbteNroEnviado`: estado ambiguo, no
 *       emitir otro numero. Marcar `fallido` con `es_fallo_infra=0`
 *       y lanzar `ZombieRecoveryFailedException` (operador debe
 *       reconciliar).
 *  6. Si `FECompConsultar` o `ultimoAutorizado` fallan por transitorio
 *     (red/timeout/5xx/9999) despues de agotar retry: marcar
 *     `fallido` con `es_fallo_infra=1`, persistir el error, y lanzar
 *     `ZombieRecoveryFailedException`. La proxima reapertura no
 *     consume intento adicional.
 *  7. Si fallan por error de protocolo (estructural, no transitorio):
 *     marcar `fallido` con `es_fallo_infra=0` y lanzar
 *     `ZombieRecoveryFailedException`. La proxima reapertura consume
 *     un intento.
 *
 * Decisiones:
 *  - **No toca el lock de emision**: el orquestador (ArcaSdk)
 *    ya tomo `GET_LOCK` antes de llamar a `recover()`. Esta clase no
 *    adquiere ni libera locks. Recibe el `LockManager` solo para
 *    mantener la firma consistente y permitir diagnostico.
 *  - **No genera un lease nuevo**: la fila ya tiene un `lease_token`
 *    (es el del worker que la dejo zombie). Se reutiliza para todos
 *    los CAS de esta recuperacion.
 *  - **El snapshot se reconstruye desde `request_json`**, no desde
 *    los argumentos de la llamada actual. Si la nueva llamada trae
 *    datos distintos, la validacion de identidad al inicio del
 *    orquestador ya rechazo la peticion con `IdempotencyConflictException`.
 *  - **Toda mutacion de la fila es via los metodos CAS del repo**.
 *    Ningun `SELECT` + `UPDATE`.
 */
final class ZombieRecovery
{
    /**
     * Punto de entrada. Verifica, consulta, decide y muta la fila
     * idempotente segun corresponda.
     *
     * @return ComprobanteEmitido Shape identico al happy path de
     *                             `ArcaSdk::emitirFactura()`. Use
     *                             `->asArray()` para la forma snake_case
     *                             historica de v0.2.x. El campo `origen`
     *                             distingue las dos rutas de recuperacion:
     *                             `zombie_consultar` (match con
     *                             FECompConsultar) y `zombie_reemit`
     *                             (re-emision del snapshot).
     *
     * @throws IdempotencyStateException      Snapshot corrupto/incoherente.
     * @throws CaeSecuestradoException        ARCA devolvio un comprobante
     *                                        con datos distintos.
     * @throws CbteRechazadoException         Re-emision rechazo funcional.
     * @throws ZombieRecoveryFailedException  Falla no recuperable (ambiguo
     *                                        o infra con retry agotado).
     * @throws WsfeException                  Re-emision con fallo
     *                                        transitorio/estructural; el
     *                                        orquestador ya lo persistio
     *                                        como `fallido`.
     */
    public static function recover(
        FilaEmision $fila,
        IdempotenciaRepository $repo,
        WsfeClient $wsfe,
        Config $config,
        Clock $clock,
        LockManager $lockManager,
        string $externalId
    ): ComprobanteEmitido {
        // 1) Snapshot validation: carga request_json y reconstruye el
        //    Comprobante. Si la reconstruccion falla, la fila queda
        //    en fallido con es_fallo_infra=0 y la excepcion propaga.
        $row = [
            'punto_venta'       => $fila->puntoVenta,
            'cbte_tipo'         => $fila->cbteTipo,
            'cbte_nro_enviado'  => $fila->cbteNroEnviado,
            'cbte_fch_enviado'  => $fila->cbteFchEnviado,
        ];
        try {
            $comprobante = SnapshotValidator::validateAndReconstruct(
                $fila->requestJson,
                $row,
            );
        } catch (IdempotencyStateException $e) {
            // Persistir el diagnostico y propagar.
            self::markFallido(
                $repo, $fila, false,
                json_encode([
                    'error' => 'snapshot_incoherente',
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE)
            );
            throw $e;
        }

        // cbteFch que espera la llamada a ARCA: el persistido, en
        // formato YYYYMMDD.
        $cbteFchYmd = self::formatFchYmd($fila->cbteFchEnviado);
        $cbteNro = (int) $fila->cbteNroEnviado;

        // 2) Consultar ARCA por el numero persistido.
        $consultarResult = null;
        $consultarError = null;
        try {
            $consultarResult = $wsfe->consultar(
                $fila->puntoVenta,
                $fila->cbteTipo,
                $cbteNro,
            );
        } catch (WsfeException $e) {
            $consultarError = $e;
        }

        // 3) Branch por resultado de FECompConsultar.
        if ($consultarError === null) {
            // Sin excepcion. Tres sub-ramas: comprobante existe, no
            // existe, o respuesta inesperada (null con codigo != 601).
            if ($consultarResult === null) {
                // 3b) ARCA reporta que el comprobante no existe (601).
                return self::handleNotExists(
                    $fila, $repo, $wsfe, $comprobante, $cbteNro, $cbteFchYmd
                );
            }
            // 3a) Comprobante existe: comparar contra el snapshot.
            return self::handleExists(
                $fila, $repo, $comprobante, $consultarResult, $cbteFchYmd
            );
        }

        // 3c) FECompConsultar fallo.
        return self::handleConsultarFailed(
            $fila, $repo, $consultarError
        );
    }

    /**
     * Caso 3a: ARCA devolvio el comprobante. Comparar con el snapshot.
     *
     *  - Si coincide: persistir el CAE recuperado como `emitido` y
     *    devolver la respuesta SIN re-llamar a `FECAESolicitar`.
     *  - Si NO coincide: marcar `fallido` con `es_fallo_infra=0` y
     *    lanzar `CaeSecuestradoException`.
     */
    private static function handleExists(
        FilaEmision $fila,
        IdempotenciaRepository $repo,
        Comprobante $comprobante,
        ComprobanteConsultado $actual,
        string $cbteFchYmd
    ): ComprobanteEmitido {
        try {
            SnapshotComparer::compare(
                $comprobante,
                $actual,
                $cbteFchYmd,
            );
        } catch (CaeSecuestradoException $e) {
            // Marcar fallido con es_fallo_infra=0 (negocio, no infra).
            // Persistimos el comparativo en response_json para que el
            // operador vea exactamente que difiere.
            $diagnosis = self::buildCaeSecuestradoDiagnosis(
                $e, $fila, $comprobante, $actual
            );
            self::markFallido(
                $repo, $fila, false, $diagnosis
            );
            throw $e;
        }

        // Match: persistir el CAE recuperado como emitido. NO re-llamar
        // a FECAESolicitar.
        $cae = (string) ($actual->cae ?? '');
        $caeFchVto = (string) ($actual->caeFchVto ?? '');
        $cbteNroRecuperado = (int) $actual->cbteNro;

        // Validacion de identidad cbte_nro: el numero que devolvio ARCA
        // tiene que ser el mismo que la fila persistio al hacer
        // reservarNumero. Si difiere, el CAE que ARCA devolvio pertenece
        // a OTRO comprobante y NO debemos apropiarnoslo. Plan section 7
        // step 2 lista cbte_nro como campo de identidad obligatorio.
        if ($cbteNroRecuperado !== (int) $fila->cbteNroEnviado) {
            $diagnosis = self::buildCbteNroMismatchDiagnosis($fila, $actual);
            self::markFallido($repo, $fila, false, $diagnosis);
            throw new CaeSecuestradoException(
                "ZombieRecovery: cbte_nro de ARCA ({$cbteNroRecuperado}) no coincide con el "
                . "persistido ({$fila->cbteNroEnviado}); el CAE devuelto pertenece a otro comprobante",
                ['cbte_nro' => (string) $fila->cbteNroEnviado],
                ['cbte_nro' => (string) $cbteNroRecuperado]
            );
        }

        if ($cae === '' || !preg_match('/^\d{14}$/', $cae)) {
            // ARCA devolvio un comprobante valido pero sin CAE (e.g.
            // resultado 'R' antiguo). Es un error de protocolo: no
            // podemos apropiarnos del comprobante.
            $diagnosis = json_encode([
                'error' => 'protocol_cae_ausente',
                'cae' => $actual->cae,
                'cae_fch_vto' => $actual->caeFchVto,
                'resultado' => $actual->resultado,
                'cbte_nro' => $actual->cbteNro,
            ], JSON_UNESCAPED_UNICODE);
            self::markFallido($repo, $fila, false, $diagnosis);
            throw new ZombieRecoveryFailedException(
                "ZombieRecovery: ARCA devolvio comprobante sin CAE (nro={$actual->cbteNro}, "
                . "resultado={$actual->resultado}); no se puede recuperar como emitido",
                false,
            );
        }
        if ($caeFchVto === '' || !preg_match('/^\d{8}$/', $caeFchVto)) {
            $diagnosis = json_encode([
                'error' => 'protocol_cae_fch_vto_invalida',
                'cae_fch_vto' => $actual->caeFchVto,
            ], JSON_UNESCAPED_UNICODE);
            self::markFallido($repo, $fila, false, $diagnosis);
            throw new ZombieRecoveryFailedException(
                "ZombieRecovery: caeFchVto invalido en respuesta de ARCA ('{$caeFchVto}')",
                false,
            );
        }

        $responseJson = self::buildEmitidoResponseJsonFromConsultado(
            $comprobante, $actual, $cbteFchYmd, 'zombie_consultar'
        );

        $ok = $repo->transitionEnCursoToEmitido(
            $fila->externalId,
            (string) $fila->leaseToken,
            $fila->requestFingerprint,
            $cae,
            $caeFchVto,
            $cbteNroRecuperado,
            $responseJson,
        );
        if (!$ok) {
            // La fila cambio entre el find y el CAS (lease ajeno,
            // fingerprint cambiado, etc.). No es un caso que el plan
            // contempla explicitamente; lo tratamos como incoherencia.
            throw new IdempotencyStateException(
                "ZombieRecovery: transitionEnCursoToEmitido no afecto la fila {$fila->externalId}"
            );
        }
        return self::buildEmitidoArray(
            $comprobante, $actual, $fila->externalId, $fila->cuit, $cbteFchYmd
        );
    }

    /**
     * Caso 3b: ARCA dice que el comprobante no existe (601).
     *  - Si `ultimoAutorizado < cbteNroEnviado`: re-emitir el snapshot
     *    con el mismo `cbteNro` y `cbteFch`.
     *  - Si `ultimoAutorizado >= cbteNroEnviado`: estado ambiguo.
     */
    private static function handleNotExists(
        FilaEmision $fila,
        IdempotenciaRepository $repo,
        WsfeClient $wsfe,
        Comprobante $comprobante,
        int $cbteNro,
        string $cbteFchYmd
    ): ComprobanteEmitido {
        // Consultar ultimoAutorizado.
        $ultimo = null;
        $ultimoError = null;
        try {
            $ultimo = $wsfe->ultimoAutorizado($fila->puntoVenta, $fila->cbteTipo);
        } catch (WsfeException $e) {
            $ultimoError = $e;
        }
        if ($ultimoError !== null) {
            return self::handleConsultarFailed(
                $fila, $repo, $ultimoError
            );
        }
        if ($ultimo < $cbteNro) {
            // El numero esta libre: re-emitir con el snapshot
            // exacto (mismo cbteNro, misma cbteFch).
            return self::reEmit(
                $fila, $repo, $wsfe, $comprobante, $cbteNro, $cbteFchYmd
            );
        }
        // Estado ambiguo: ARCA dice que el comprobante no existe pero
        // el ultimo autorizado es >= al numero que intentamos.
        // No emitir otro numero.
        $diagnosis = json_encode([
            'error' => 'estado_ambiguo',
            'cbte_nro_enviado' => $cbteNro,
            'ultimo_autorizado' => $ultimo,
            'message' => "ARCA dice que el comprobante {$cbteNro} no existe pero "
                . "ultimoAutorizado={$ultimo} >= cbteNroEnviado={$cbteNro}; "
                . "no se puede re-emitir sin riesgo de duplicar",
        ], JSON_UNESCAPED_UNICODE);
        self::markFallido($repo, $fila, false, $diagnosis);
        throw new ZombieRecoveryFailedException(
            "ZombieRecovery: estado ambiguo: ARCA dice que el comprobante no "
            . "existe pero ultimoAutorizado={$ultimo} >= cbteNroEnviado={$cbteNro}. "
            . "La fila se marco fallido con es_fallo_infra=0. Operador debe "
            . "reconciliar antes de reintentar.",
            false,
        );
    }

    /**
     * Caso 3c: FECompConsultar o ultimoAutorizado fallaron. Clasificar
     * el error y marcar la fila correspondientemente.
     */
    private static function handleConsultarFailed(
        FilaEmision $fila,
        IdempotenciaRepository $repo,
        WsfeException $e
    ): array {
        $esFalloInfra = self::isTransient($e);
        $diagnosis = self::serializeExceptionForLog($e);
        self::markFallido($repo, $fila, $esFalloInfra, $diagnosis);
        throw new ZombieRecoveryFailedException(
            "ZombieRecovery: FECompConsultar/ultimoAutorizado fallo: "
            . $e->getMessage(),
            $esFalloInfra,
            $e,
        );
    }

    /**
     * Re-emite el comprobante usando el snapshot exacto (mismo cbteNro,
     * misma cbteFch). Procesa la respuesta de `FECAESolicitar` igual
     * que la fase 6:
     *  - Resultado='A' -> emitir, devolver array.
     *  - Resultado='R' -> fallido (es_fallo_infra=0), CbteRechazadoException.
     *  - Transient -> fallido (es_fallo_infra=1), WsfeArcaTransientException.
     *  - Structural -> fallido (es_fallo_infra=0), WsfeException.
     */
    private static function reEmit(
        FilaEmision $fila,
        IdempotenciaRepository $repo,
        WsfeClient $wsfe,
        Comprobante $comprobante,
        int $cbteNro,
        string $cbteFchYmd
    ): ComprobanteEmitido {
        try {
            $response = $wsfe->solicitar($comprobante, $cbteNro, $cbteFchYmd);
        } catch (CbteRechazadoException $e) {
            // WsfeClient no lanza esto en la practica; por defensa.
            $diagnosis = json_encode([
                'resultado' => 'R',
                'observaciones' => $e->observaciones,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
            self::markFallido($repo, $fila, false, $diagnosis);
            throw $e;
        } catch (WsfeArcaTransientException $e) {
            $diagnosis = self::serializeExceptionForLog($e);
            self::markFallido($repo, $fila, true, $diagnosis);
            throw $e;
        } catch (WsfeProtocolException $e) {
            $esFalloInfra = self::isTransient($e);
            $diagnosis = self::serializeExceptionForLog($e);
            self::markFallido($repo, $fila, $esFalloInfra, $diagnosis);
            throw $e;
        } catch (WsfeException $e) {
            $esFalloInfra = self::isTransient($e);
            $diagnosis = self::serializeExceptionForLog($e);
            self::markFallido($repo, $fila, $esFalloInfra, $diagnosis);
            throw $e;
        } catch (Throwable $e) {
            $esFalloInfra = self::isTransient($e);
            $diagnosis = self::serializeExceptionForLog($e);
            self::markFallido($repo, $fila, $esFalloInfra, $diagnosis);
            throw $e;
        }

        // Resultado='A' (aprobado)
        if ($response->isAprobado()) {
            $responseJson = self::buildEmitidoResponseJsonFromResponse(
                $comprobante, $response, $cbteFchYmd, 'zombie_reemit'
            );
            $ok = $repo->transitionEnCursoToEmitido(
                $fila->externalId,
                (string) $fila->leaseToken,
                $fila->requestFingerprint,
                (string) $response->cae,
                (string) $response->caeFchVto,
                $response->cbteNro,
                $responseJson,
            );
            if (!$ok) {
                throw new IdempotencyStateException(
                    "ZombieRecovery::reEmit: transitionEnCursoToEmitido no afecto la fila {$fila->externalId}"
                );
            }
            return self::buildEmitidoArrayFromResponse(
                $comprobante, $response, $fila->externalId, $fila->cuit, $cbteFchYmd
            );
        }

        // Resultado='R' (rechazo funcional)
        $diagnosis = json_encode([
            'resultado' => 'R',
            'observaciones' => $response->observaciones,
            'raw_excerpt' => $response->rawExcerpt,
            'message' => 're-emit del snapshot recibio Resultado=R de ARCA',
        ], JSON_UNESCAPED_UNICODE);
        self::markFallido($repo, $fila, false, $diagnosis);
        throw new CbteRechazadoException(
            'ZombieRecovery: re-emit del snapshot fue rechazado por ARCA: '
            . self::observacionesAsString($response->observaciones),
            $response->observaciones,
        );
    }

    /**
     * Persiste la fila como `fallido` con el lease del worker actual.
     * Wrapper sobre el CAS del repositorio.
     */
    private static function markFallido(
        IdempotenciaRepository $repo,
        FilaEmision $fila,
        bool $esFalloInfra,
        ?string $responseJson
    ): void {
        if ($fila->leaseToken === null) {
            // La fila ya esta terminal; nada que hacer.
            return;
        }
        $repo->transitionEnCursoToFallido(
            $fila->externalId,
            $fila->leaseToken,
            $fila->requestFingerprint,
            $esFalloInfra,
            $responseJson,
        );
    }

    /**
     * Clasifica un Throwable como transitorio. Wrapper sobre
     * RetryPolicy para mantener la unica fuente de verdad.
     */
    private static function isTransient(Throwable $e): bool
    {
        return RetryPolicy::isTransient($e);
    }

    /**
     * Serializa una excepcion para guardarla en response_json sin
     * filtrar data sensible.
     */
    private static function serializeExceptionForLog(Throwable $e): string
    {
        $payload = [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
        ];
        // Para WsfeProtocolException, kind ayuda al operador.
        if ($e instanceof WsfeProtocolException) {
            $payload['kind'] = $e->kind;
        }
        if ($e instanceof WsfeArcaTransientException) {
            $payload['observaciones'] = $e->observaciones;
        }
        return json_encode($payload, JSON_UNESCAPED_UNICODE)
            ?: '{"class":"unknown","message":"unserializable"}';
    }

    /**
     * Construye el `response_json` que se persiste al pasar a
     * `emitido` durante la recuperacion desde una respuesta
     * `ComprobanteConsultado` (caso 3a: match con FECompConsultar).
     */
    private static function buildEmitidoResponseJsonFromConsultado(
        Comprobante $comprobante,
        ComprobanteConsultado $actual,
        string $cbteFchYmd,
        string $origen
    ): string {
        $discrimina = \Rbbsoft\ArcaSdk\Wsfe\TiposComprobante::discriminaIva($comprobante->cbteTipo);
        $res = IvaCalculator::calcular(
            $comprobante->items,
            $discrimina,
            $comprobante->importeNoGravado,
            $comprobante->importeExento,
            $comprobante->importeOtrosTributos,
        );
        $payload = [
            'resultado'   => 'A',
            'cae'         => $actual->cae,
            'cae_fch_vto' => $actual->caeFchVto,
            'cbte_nro'    => (int) $actual->cbteNro,
            'cbte_fch'    => self::formatFchYmdDash($cbteFchYmd),
            'monto_total' => $res->total,
            'monto_neto'  => $res->netoGravado,
            'monto_iva'   => $res->ivaTotal,
            'origen'      => $origen,
        ];
        if (count($comprobante->cbtesAsoc) > 0) {
            $payload['cbtes_asoc'] = $comprobante->cbtesAsoc;
        }
        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Construye el `response_json` para el caso de re-emision aprobada
     * (la respuesta es `ComprobanteResponse`).
     */
    private static function buildEmitidoResponseJsonFromResponse(
        Comprobante $comprobante,
        ComprobanteResponse $response,
        string $cbteFchYmd,
        string $origen
    ): string {
        $discrimina = \Rbbsoft\ArcaSdk\Wsfe\TiposComprobante::discriminaIva($comprobante->cbteTipo);
        $res = IvaCalculator::calcular(
            $comprobante->items,
            $discrimina,
            $comprobante->importeNoGravado,
            $comprobante->importeExento,
            $comprobante->importeOtrosTributos,
        );
        $payload = [
            'resultado'   => 'A',
            'cae'         => (string) $response->cae,
            'cae_fch_vto' => (string) $response->caeFchVto,
            'cbte_nro'    => $response->cbteNro,
            'cbte_fch'    => self::formatFchYmdDash($cbteFchYmd),
            'monto_total' => $res->total,
            'monto_neto'  => $res->netoGravado,
            'monto_iva'   => $res->ivaTotal,
            'origen'      => $origen,
        ];
        if (count($comprobante->cbtesAsoc) > 0) {
            $payload['cbtes_asoc'] = $comprobante->cbtesAsoc;
        }
        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Construye el array de retorno cuando la fuente es
     * ComprobanteConsultado (caso 3a: match con FECompConsultar).
     */
    private static function buildEmitidoArray(
        Comprobante $comprobante,
        ComprobanteConsultado $actual,
        string $externalId,
        string $cuit,
        string $cbteFchYmd
    ): ComprobanteEmitido {
        $discrimina = \Rbbsoft\ArcaSdk\Wsfe\TiposComprobante::discriminaIva($comprobante->cbteTipo);
        $res = IvaCalculator::calcular(
            $comprobante->items,
            $discrimina,
            $comprobante->importeNoGravado,
            $comprobante->importeExento,
            $comprobante->importeOtrosTributos,
        );
        $cbteFchYmdDash = self::formatFchYmdDash($cbteFchYmd);
        return new ComprobanteEmitido(
            cbteTipo: $comprobante->cbteTipo,
            cbteNro: (int) $actual->cbteNro,
            cbteFch: $cbteFchYmdDash,
            cae: (string) $actual->cae,
            caeFchVto: (string) $actual->caeFchVto,
            montoTotal: $res->total,
            montoNeto: $res->netoGravado,
            montoIva: $res->ivaTotal,
            monId: $comprobante->monId,
            monCotiz: $comprobante->monCotiz,
            puntoVenta: $comprobante->puntoVenta,
            cuit: (int) $cuit,
            receptorDocumentoTipo: $comprobante->receptorDocumentoTipo,
            receptorDocumentoNro: $comprobante->receptorDocumentoNro,
            receptorCondicionIva: $comprobante->receptorCondicionIva,
            items: $comprobante->items,
            observaciones: [],
            origen: 'zombie_consultar',
            externalId: $externalId,
            resultado: (string) $actual->resultado,
            cbtesAsoc: $comprobante->cbtesAsoc,
        );
    }

    /**
     * Construye el DTO de retorno cuando la fuente es
     * ComprobanteResponse (caso re-emit aprobado).
     */
    private static function buildEmitidoArrayFromResponse(
        Comprobante $comprobante,
        ComprobanteResponse $response,
        string $externalId,
        string $cuit,
        string $cbteFchYmd
    ): ComprobanteEmitido {
        $discrimina = \Rbbsoft\ArcaSdk\Wsfe\TiposComprobante::discriminaIva($comprobante->cbteTipo);
        $res = IvaCalculator::calcular(
            $comprobante->items,
            $discrimina,
            $comprobante->importeNoGravado,
            $comprobante->importeExento,
            $comprobante->importeOtrosTributos,
        );
        $cbteFchYmdDash = self::formatFchYmdDash($cbteFchYmd);
        return new ComprobanteEmitido(
            cbteTipo: $comprobante->cbteTipo,
            cbteNro: $response->cbteNro,
            cbteFch: $cbteFchYmdDash,
            cae: (string) $response->cae,
            caeFchVto: (string) $response->caeFchVto,
            montoTotal: $res->total,
            montoNeto: $res->netoGravado,
            montoIva: $res->ivaTotal,
            monId: $comprobante->monId,
            monCotiz: $comprobante->monCotiz,
            puntoVenta: $comprobante->puntoVenta,
            cuit: (int) $cuit,
            receptorDocumentoTipo: $comprobante->receptorDocumentoTipo,
            receptorDocumentoNro: $comprobante->receptorDocumentoNro,
            receptorCondicionIva: $comprobante->receptorCondicionIva,
            items: $comprobante->items,
            observaciones: [],
            origen: 'zombie_reemit',
            externalId: $externalId,
            resultado: $response->resultado,
            cbtesAsoc: $comprobante->cbtesAsoc,
        );
    }

    /**
     * Construye el JSON de diagnostico cuando ARCA devolvio un comprobante
     * con un cbte_nro distinto al persistido en la fila. Indica claramente
     * que el CAE pertenece a otro comprobante.
     */
    private static function buildCbteNroMismatchDiagnosis(
        FilaEmision $fila,
        ComprobanteConsultado $actual
    ): string {
        return json_encode([
            'error'             => 'cbte_nro_no_coincide',
            'cbte_nro_enviado'  => (int) $fila->cbteNroEnviado,
            'arca_cbte_nro'     => (int) $actual->cbteNro,
            'arca_cae'          => $actual->cae,
            'message'           => "ZombieRecovery: ARCA devolvio cbte_nro={$actual->cbteNro} "
                . "pero la fila persistio cbte_nro_enviado={$fila->cbteNroEnviado}; "
                . "el CAE ({$actual->cae}) pertenece a otro comprobante y NO debe apropiarselo",
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Construye el JSON de diagnostico para un CaeSecuestradoException.
     * Incluye el snapshot relevante y la respuesta de ARCA para que
     * el operador entienda que campo difiere.
     */
    private static function buildCaeSecuestradoDiagnosis(
        CaeSecuestradoException $e,
        FilaEmision $fila,
        Comprobante $comprobante,
        ComprobanteConsultado $actual
    ): string {
        $diagnosis = [
            'error'         => 'cae_secuestrado',
            'expected'      => $e->esperado,
            'received'      => $e->recibido,
            'cbte_nro_enviado' => $fila->cbteNroEnviado,
            'cbte_fch_enviado' => self::formatFchYmdDash(self::formatFchYmd($fila->cbteFchEnviado)),
            'snapshot_pv'   => $comprobante->puntoVenta,
            'snapshot_tipo' => $comprobante->cbteTipo,
            'arca_cae'      => $actual->cae,
            'arca_cbte_nro' => $actual->cbteNro,
            'arca_cbte_fch' => $actual->cbteFch,
            'message'       => $e->getMessage(),
        ];
        return json_encode($diagnosis, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Formatea la fecha civil de la fila (DateTimeImmutable) a
     * YYYYMMDD. Si la fecha no es valida, devuelve string vacio (el
     * SnapshotValidator ya la valido).
     */
    private static function formatFchYmd(?\DateTimeInterface $fch): string
    {
        if ($fch === null) {
            return '';
        }
        return $fch->format('Ymd');
    }

    /**
     * Variante que acepta un YYYYMMDD y devuelve YYYY-MM-DD.
     */
    private static function formatFchYmdDash(string $yyyymmdd): string
    {
        if (strlen($yyyymmdd) === 8) {
            return substr($yyyymmdd, 0, 4) . '-' . substr($yyyymmdd, 4, 2) . '-' . substr($yyyymmdd, 6, 2);
        }
        return $yyyymmdd;
    }

    /**
     * @param array<int, array{codigo:int, mensaje:string}> $observaciones
     */
    private static function observacionesAsString(array $observaciones): string
    {
        $parts = [];
        foreach ($observaciones as $o) {
            $parts[] = sprintf('[%d] %s', $o['codigo'], $o['mensaje']);
        }
        return implode(' | ', $parts);
    }
}
