<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Idempotencia;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\EmisionEnCursoException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Exceptions\MaxIdempotencyAttemptsException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;

/**
 * Repositorio de la tabla `arca_emisiones_idempotencia`.
 *
 * Es la UNICA superficie del SDK que toca la tabla. El resto del
 * codigo (orquestador en Phase 6) consume FilaEmision y nunca
 * ejecuta SQL directo.
 *
 * ----------------------------------------------------------------
 * Reglas de diseno (no negociables)
 * ----------------------------------------------------------------
 *
 *  1. **CAS atómico**: cada transición es un SOLO UPDATE con
 *     `affected_rows === 1` como frontera de exito. NUNCA
 *     `SELECT` y despues `UPDATE`: eso es una race condition.
 *
 *  2. **Lease-token en el WHERE** de todo CAS mutante. Un worker
 *     con lease vencido o de otra fila no puede mutar.
 *
 *  3. **Timestamps UTC formateados en PHP** (`gmdate('Y-m-d H:i:s', time())`).
 *     NUNCA `NOW()` / `UTC_TIMESTAMP() - INTERVAL ? SECOND` ni en
 *     WHERE ni en SET: dependen de la zona de la sesion MySQL.
 *
 *  4. **Asignaciones en `transitionFallidoToEnCurso` evaluadas
 *     left-to-right por MariaDB**. Por eso `intento = intento + IF(...)`
 *     aparece ANTES de `es_fallo_infra = 0`. Si se invierte, el IF
 *     ve el valor nuevo (0) y nunca incrementa. Verificado con
 *     probe real contra MariaDB 10.4.32.
 *
 *  5. **Sin nombre lock**: el named lock de emision por
 *     (cuit, pv, tipo) se toma en el orquestador (Phase 6). El
 *     repositorio es unaware de GET_LOCK; su unica coordinacion
 *     entre workers es el CAS sobre `arca_emisiones_idempotencia`.
 *
 *  6. **PDO injectado**: el caller maneja transacciones y la
 *     conexion. El repositorio no hace BEGIN/COMMIT/ROLLBACK.
 *
 *  7. **Validaciones de entrada antes del UPDATE**: `transitionEnCursoToEmitido`
 *     y `reservarNumero` validan formato antes de tocar SQL; una
 *     CAE mal formado lanza ValidationException sin gastar el CAS.
 *
 *  8. **Retry policy es la unica fuente de verdad para
 *     `es_fallo_infra`**: el orquestador (Phase 6) clasifica el
 *     fallo con `RetryPolicy::isTransient()` y llama con el valor
 *     apropiado. El repositorio NO clasifica.
 */
final class IdempotenciaRepository
{
    /**
     * @param PDO $pdo Conexion PDO ya abierta. El caller controla
     *                 el ciclo de vida y la transaccion.
     * @param Config $config Configuracion del SDK; el repositorio
     *                 lee `idempotenciaMaxIntentos` de aca.
     * @param (Closure(): string)|null $uuidFactory Generador de UUIDs
     *                 v4. Default: UuidFactory::v4().
     * @param (Closure(): DateTimeImmutable)|null $clock Clock inyectable
     *                 para tests. Default: now() en UTC.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly ?Closure $uuidFactory = null,
        private readonly ?Closure $clock = null,
    ) {
    }

    // -----------------------------------------------------------------
    // Insercion / lectura
    // -----------------------------------------------------------------

    /**
     * Inserta una fila en estado 'en_curso' con un lease_token
     * generado. Devuelve el lease para que el caller lo conserve
     * durante todo el procesamiento de esta emision logica.
     *
     * **No captura PDOException por duplicate key**: el caller es
     * responsable de detectar la colision (PDOException con SQLSTATE
     * 23000) y llamar a findByExternalId() para decidir que hacer.
     * Esa politica es la que describe el plan maestro (Phase 6):
     * "ante duplicate key, releer y reconciliar".
     *
     * @return string Lease token UUID v4 generado.
     *
     * @throws PDOException Si external_id ya existe (duplicate key
     *                      SQLSTATE 23000) o cualquier otro error
     *                      de la base.
     */
    public function insertEnCurso(
        string $externalId,
        string $cuit,
        int $puntoVenta,
        int $cbteTipo,
        string $requestFingerprint,
        string $requestJson,
    ): string {
        $lease = $this->uuid();
        $now = $this->nowUtcString();

        $sql = 'INSERT INTO arca_emisiones_idempotencia
                   (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token,
                    intento, es_fallo_infra, request_fingerprint, request_json,
                    created_at, updated_at)
                VALUES
                   (:external_id, :cuit, :punto_venta, :cbte_tipo, :estado, :lease_token,
                    0, 0, :request_fingerprint, :request_json,
                    :created_at, :updated_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':external_id'         => $externalId,
            ':cuit'                => $cuit,
            ':punto_venta'         => $puntoVenta,
            ':cbte_tipo'           => $cbteTipo,
            ':estado'              => FilaEmision::ESTADO_EN_CURSO,
            ':lease_token'         => $lease,
            ':request_fingerprint' => $requestFingerprint,
            ':request_json'        => $requestJson,
            ':created_at'          => $now,
            ':updated_at'          => $now,
        ]);

        return $lease;
    }

    /**
     * Busca una fila por external_id. Devuelve null si no existe.
     * SELECT puro, sin lock, sin side effects.
     */
    public function findByExternalId(string $externalId): ?FilaEmision
    {
        $sql = 'SELECT external_id, cuit, punto_venta, cbte_tipo, estado, lease_token,
                       intento, es_fallo_infra, request_fingerprint, request_json,
                       cbte_nro_enviado, cbte_fch_enviado, cae, cae_fch_vto,
                       cbte_nro_confirmado, response_json, created_at, updated_at
                  FROM arca_emisiones_idempotencia
                 WHERE external_id = :external_id
                 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':external_id' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->rowToFila($row);
    }

    // -----------------------------------------------------------------
    // Transiciones CAS
    // -----------------------------------------------------------------

    /**
     * Transicion CAS: en_curso -> fallido.
     *
     * WHERE incluye lease_token=? + request_fingerprint=? + estado='en_curso'
     * (no es UPDATE-then-SELECT, no es racy). El lease se limpia al
     * pasar a estado terminal.
     *
     * El caller es responsable de clasificar el fallo con
     * `RetryPolicy::isTransient()` y pasar `$esFalloInfra` acorde.
     *
     * @return bool true iff exactly 1 row was affected.
     */
    public function transitionEnCursoToFallido(
        string $externalId,
        string $leaseToken,
        string $requestFingerprint,
        bool $esFalloInfra,
        ?string $responseJson = null,
    ): bool {
        $now = $this->nowUtcString();
        $sql = 'UPDATE arca_emisiones_idempotencia
                   SET estado = :fallido,
                       es_fallo_infra = :es_fallo_infra,
                       lease_token = NULL,
                       response_json = :response_json,
                       updated_at = :updated_at
                 WHERE external_id = :external_id
                   AND estado = :en_curso
                   AND lease_token = :lease_token
                   AND request_fingerprint = :request_fingerprint';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':fallido'             => FilaEmision::ESTADO_FALLIDO,
            ':es_fallo_infra'      => $esFalloInfra ? 1 : 0,
            ':response_json'       => $responseJson,
            ':updated_at'          => $now,
            ':external_id'         => $externalId,
            ':en_curso'            => FilaEmision::ESTADO_EN_CURSO,
            ':lease_token'         => $leaseToken,
            ':request_fingerprint' => $requestFingerprint,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Transicion CAS UNICA que distingue infra/negocio. fallido -> en_curso.
     *
     * **Orden de asignaciones CRITICO**: la primera asignacion es
     * `intento = intento + IF(es_fallo_infra = 1, 0, 1)`. MariaDB
     * evalua las SET clauses de un UPDATE de tabla unica de izquierda
     * a derecha; si la primera linea fuera `es_fallo_infra = 0`, el
     * IF veria 0 y nunca incrementaria intento en la rama de negocio.
     * Verificado con probe real en MariaDB 10.4.32.
     *
     * La condicion del WHERE `(es_fallo_infra = 1 OR intento < ?)`
     * refleja la regla de maxIntentos: las fallas de infraestructura
     * no consumen intento y siempre reabren; las de negocio consumen
     * un intento y paran al alcanzar el maximo.
     *
     * Si affected_rows === 0, se relee la fila para clasificar:
     *  - estado='en_curso'   -> otro worker la reabrio o esta activa
     *  - estado='emitido'    -> ya emitida por este u otro worker
     *  - estado='fallido' + intento >= max + !infra -> excedio max
     *  - resto               -> corrupcion de estado
     *
     * @return string Nuevo lease_token UUID v4 generado.
     *
     * @throws EmisionEnCursoException       si la fila ya esta en_curso
     * @throws IdempotencyStateException     si esta emitido o corrupto
     * @throws MaxIdempotencyAttemptsException si negocio alcanzo el max
     */
    public function transitionFallidoToEnCurso(
        string $externalId,
        string $requestFingerprint,
    ): string {
        $lease = $this->uuid();
        $now = $this->nowUtcString();
        $maxIntentos = $this->config->idempotenciaMaxIntentos;

        $sql = 'UPDATE arca_emisiones_idempotencia
                   SET intento = intento + IF(es_fallo_infra = 1, 0, 1),
                       estado = :en_curso,
                       es_fallo_infra = 0,
                       lease_token = :lease_token,
                       updated_at = :updated_at
                 WHERE external_id = :external_id
                   AND estado = :fallido
                   AND request_fingerprint = :request_fingerprint
                   AND (es_fallo_infra = 1 OR intento < :max_intentos)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':en_curso'            => FilaEmision::ESTADO_EN_CURSO,
            ':lease_token'         => $lease,
            ':updated_at'          => $now,
            ':external_id'         => $externalId,
            ':fallido'             => FilaEmision::ESTADO_FALLIDO,
            ':request_fingerprint' => $requestFingerprint,
            ':max_intentos'        => $maxIntentos,
        ]);

        if ($stmt->rowCount() === 1) {
            return $lease;
        }

        // Re-leer para clasificar el fallo. La fila puede haber
        // cambiado entre el CAS y aca; el SELECT autoritativo define
        // que excepcion corresponde.
        $row = $this->findByExternalId($externalId);
        if ($row === null) {
            // La fila desaparecio entre el UPDATE y el SELECT. No
            // deberia pasar (PK no se elimina por nadie del SDK), pero
            // si pasa es corrupcion.
            throw new IdempotencyStateException(
                "transitionFallidoToEnCurso: fila {$externalId} desaparecio despues de CAS fallido"
            );
        }

        if ($row->estado === FilaEmision::ESTADO_EMITIDO) {
            // Otro worker la cerro como emitido mientras esperabamos.
            // El fingerprint ya fue validado por el caller; emitir de
            // nuevo seria duplicar el comprobante.
            throw new IdempotencyStateException(
                "transitionFallidoToEnCurso: fila {$externalId} ya esta emitido; no se puede reabrir"
            );
        }
        if ($row->estado === FilaEmision::ESTADO_EN_CURSO) {
            // Otro worker la reabrio primero. Carrera legitima.
            throw new EmisionEnCursoException(
                "transitionFallidoToEnCurso: fila {$externalId} ya esta en_curso (otro worker la tomo)"
            );
        }
        // estado == 'fallido' pero el WHERE no matcheo. Las unicas
        // formas en que esto puede ocurrir:
        //  - es_fallo_infra=0 AND intento >= maxIntentos
        //  - fingerprint cambiado (no deberia: el caller valida)
        //  - corrupcion fisica
        if ($row->esFalloInfra === false && $row->intento >= $maxIntentos) {
            throw new MaxIdempotencyAttemptsException(
                "transitionFallidoToEnCurso: fila {$externalId} alcanzo el maximo de intentos "
                . "({$maxIntentos}). resetExternalId() administrativo requerido."
            );
        }
        if ($row->esFalloInfra === true) {
            // es_fallo_infra=1: el WHERE (es_fallo_infra=1 OR intento<max)
            // SI matchea. Si el CAS fallo aca, es corrupcion.
            throw new IdempotencyStateException(
                "transitionFallidoToEnCurso: fila {$externalId} en fallido con es_fallo_infra=1 "
                . "pero CAS no afecto la fila (intento={$row->intento})"
            );
        }
        // Estado incoherente que no deberia existir.
        throw new IdempotencyStateException(
            "transitionFallidoToEnCurso: fila {$externalId} en estado incoherente "
            . "(estado={$row->estado}, intento={$row->intento}, es_fallo_infra="
            . ($row->esFalloInfra ? '1' : '0') . ')'
        );
    }

    /**
     * Transicion CAS: en_curso -> emitido. Solo una respuesta
     * aprobada de ARCA con CAE puede llegar aca (el orquestador en
     * Phase 6 garantiza esto; ver plan seccion 6 punto 8).
     *
     * **Validaciones ANTES del UPDATE** (gastar el CAS con un CAE
     * malformado no seria idempotente):
     *  - cae:           14 digitos exactos (formato ARCA)
     *  - cae_fch_vto:   8 digitos exactos (YYYYMMDD), se convierte a DATE
     *  - cbte_nro:      > 0
     *
     * @return bool true iff exactly 1 row was affected.
     *
     * @throws ValidationException si cae/cae_fch_vto/cbte_nro son invalidos
     */
    public function transitionEnCursoToEmitido(
        string $externalId,
        string $leaseToken,
        string $requestFingerprint,
        string $cae,
        string $caeFchVto,
        int $cbteNroConfirmado,
        ?string $responseJson = null,
    ): bool {
        if (!preg_match('/^\d{14}$/', $cae)) {
            throw new ValidationException(
                "transitionEnCursoToEmitido: cae debe tener 14 digitos (recibio '{$cae}')"
            );
        }
        if (!preg_match('/^\d{8}$/', $caeFchVto)) {
            throw new ValidationException(
                "transitionEnCursoToEmitido: cae_fch_vto debe tener 8 digitos YYYYMMDD "
                . "(recibio '{$caeFchVto}')"
            );
        }
        if ($cbteNroConfirmado <= 0) {
            throw new ValidationException(
                "transitionEnCursoToEmitido: cbteNroConfirmado debe ser > 0 (recibio {$cbteNroConfirmado})"
            );
        }

        // ARCA entrega cae_fch_vto como YYYYMMDD (8 digitos, sin
        // separadores). La columna DATE espera YYYY-MM-DD. Convertimos
        // explicitamente para no depender de la interpretacion de la
        // sesion MySQL.
        $caeFchVtoDate = sprintf(
            '%s-%s-%s',
            substr($caeFchVto, 0, 4),
            substr($caeFchVto, 4, 2),
            substr($caeFchVto, 6, 2)
        );

        $now = $this->nowUtcString();
        $sql = 'UPDATE arca_emisiones_idempotencia
                   SET estado = :emitido,
                       cae = :cae,
                       cae_fch_vto = :cae_fch_vto,
                       cbte_nro_confirmado = :cbte_nro,
                       lease_token = NULL,
                       response_json = :response_json,
                       updated_at = :updated_at
                 WHERE external_id = :external_id
                   AND estado = :en_curso
                   AND lease_token = :lease_token
                   AND request_fingerprint = :request_fingerprint';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':emitido'             => FilaEmision::ESTADO_EMITIDO,
            ':cae'                 => $cae,
            ':cae_fch_vto'         => $caeFchVtoDate,
            ':cbte_nro'            => $cbteNroConfirmado,
            ':response_json'       => $responseJson,
            ':updated_at'          => $now,
            ':external_id'         => $externalId,
            ':en_curso'            => FilaEmision::ESTADO_EN_CURSO,
            ':lease_token'         => $leaseToken,
            ':request_fingerprint' => $requestFingerprint,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Persiste cbte_nro_enviado y cbte_fch_enviado antes de la
     * primera llamada a ARCA. Asi, una recuperacion zombie
     * (seccion 7 del plan) puede usar el numero persistido y
     * NUNCA recalcular `ultimo + 1` para el mismo external_id.
     *
     * El CAS incluye `cbte_nro_enviado IS NULL` para garantizar
     * que un numero nunca se reasigna: si ya hay un numero
     * persistido (de un intento previo), el WHERE no matchea y
     * affected_rows es 0.
     *
     * Si $requestJsonOverride es null, se conserva el `request_json`
     * actual (COALESCE). Si no es null, se reemplaza.
     *
     * **Validacion ANTES del UPDATE**: cae_fch_enviado en formato
     * YYYY-MM-DD. cbte_nro_enviado > 0. (caeFchVto/CAE no aplica
     * aca, son del emitido.)
     *
     * @return bool true iff exactly 1 row was affected.
     *
     * @throws ValidationException si cbteFchYmd o cbteNro son invalidos
     */
    public function reservarNumero(
        string $externalId,
        string $leaseToken,
        string $requestFingerprint,
        int $cbteNro,
        string $cbteFchYmd,
        ?string $requestJsonOverride = null,
    ): bool {
        if ($cbteNro <= 0) {
            throw new ValidationException(
                "reservarNumero: cbteNro debe ser > 0 (recibio {$cbteNro})"
            );
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cbteFchYmd)) {
            throw new ValidationException(
                "reservarNumero: cbteFchYmd debe tener formato YYYY-MM-DD (recibio '{$cbteFchYmd}')"
            );
        }
        // Sanity basico: que la fecha sea parseable. BC no es necesario.
        $parsed = \DateTime::createFromFormat('Y-m-d', $cbteFchYmd);
        if ($parsed === false || $parsed->format('Y-m-d') !== $cbteFchYmd) {
            throw new ValidationException(
                "reservarNumero: cbteFchYmd no es una fecha valida '{$cbteFchYmd}'"
            );
        }

        $now = $this->nowUtcString();
        $sql = 'UPDATE arca_emisiones_idempotencia
                   SET cbte_nro_enviado = :cbte_nro,
                       cbte_fch_enviado = :cbte_fch,
                       request_json = COALESCE(:request_json_override, request_json),
                       updated_at = :updated_at
                 WHERE external_id = :external_id
                   AND estado = :en_curso
                   AND lease_token = :lease_token
                   AND request_fingerprint = :request_fingerprint
                   AND cbte_nro_enviado IS NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':cbte_nro'            => $cbteNro,
            ':cbte_fch'            => $cbteFchYmd,
            ':request_json_override' => $requestJsonOverride,
            ':updated_at'          => $now,
            ':external_id'         => $externalId,
            ':en_curso'            => FilaEmision::ESTADO_EN_CURSO,
            ':lease_token'         => $leaseToken,
            ':request_fingerprint' => $requestFingerprint,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Persiste `response_json` con guard de lease. Util para
     * guardar el cuerpo de error antes de marcar fallido, o para
     * cualquier actualizacion de response_json que no amerite una
     * transicion de estado.
     *
     * Sin check de estado: el guard de lease_token es suficiente
     * (en estado terminal el lease es NULL, asi que el WHERE no
     * matchea).
     *
     * @return bool true iff exactly 1 row was affected.
     */
    public function updateResponseJson(
        string $externalId,
        string $leaseToken,
        ?string $responseJson,
    ): bool {
        $now = $this->nowUtcString();
        $sql = 'UPDATE arca_emisiones_idempotencia
                   SET response_json = :response_json,
                       updated_at = :updated_at
                 WHERE external_id = :external_id
                   AND lease_token = :lease_token';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':response_json' => $responseJson,
            ':updated_at'    => $now,
            ':external_id'   => $externalId,
            ':lease_token'   => $leaseToken,
        ]);
        return $stmt->rowCount() === 1;
    }

    // -----------------------------------------------------------------
    // Zombie / sweeper
    // -----------------------------------------------------------------

    /**
     * Marca como fallido una fila en_curso que esta stale (TTL
     * expirado) Y cuyo lease el orquestador acaba de leer.
     *
     * Es un CAS "defensivo": el orquestador leyo la fila, vio que
     * updated_at < cutoff y pidio al worker cerrarla. El guard
     * de lease evita que un orquestador con vista obsoleta cierre
     * una fila que ya fue reclamada por otro (sweeper, o nuevo
     * worker que re-intento).
     *
     * Para Phase 5 solo exponemos el metodo. El sweeper real
     * (`expireEnCursoLeases`) es la contraparte bulk que se usa
     * sin lease guard.
     *
     * `es_fallo_infra=0` porque la causa es desconocida (TTL):
     * si se reabre despues via transitionFallidoToEnCurso,
     * consumira un intento.
     *
     * @return bool true iff exactly 1 row was affected.
     */
    public function markEnCursoZombieFromStaleLock(
        string $externalId,
        string $leaseToken,
        string $requestFingerprint,
        string $cutoffUtc,
    ): bool {
        $now = $this->nowUtcString();
        $sql = 'UPDATE arca_emisiones_idempotencia
                   SET estado = :fallido,
                       es_fallo_infra = 0,
                       lease_token = NULL,
                       updated_at = :updated_at
                 WHERE external_id = :external_id
                   AND estado = :en_curso
                   AND updated_at < :cutoff
                   AND lease_token = :lease_token
                   AND request_fingerprint = :request_fingerprint';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':fallido'             => FilaEmision::ESTADO_FALLIDO,
            ':updated_at'          => $now,
            ':external_id'         => $externalId,
            ':en_curso'            => FilaEmision::ESTADO_EN_CURSO,
            ':cutoff'              => $cutoffUtc,
            ':lease_token'         => $leaseToken,
            ':request_fingerprint' => $requestFingerprint,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Bulk reclaim de leases expirados por el sweeper. SIN guard
     * de lease_token (a diferencia de markEnCursoZombieFromStaleLock):
     * el sweeper reclama cualquier lease cuyo updated_at < cutoff.
     *
     * La condicion updated_at < cutoff se evalua contra el valor
     * pasado por el caller (formateado en PHP como 'Y-m-d H:i:s'
     * UTC). El caller DEBE calcular el cutoff afuera y pasarlo
     * bindeado, nunca usar NOW() o UTC_TIMESTAMP() en SQL.
     *
     * Devuelve la cantidad de filas afectadas. Phase 8 implementa
     * el sweeper real; esta funcion es la primitiva que el sweeper
     * invocara en cada pasada.
     *
     * @return int Numero de filas transicionadas a fallido.
     */
    public function expireEnCursoLeases(string $cutoffUtc, int $limit = 100): int
    {
        $now = $this->nowUtcString();
        $sql = 'UPDATE arca_emisiones_idempotencia
                   SET estado = :fallido,
                       es_fallo_infra = 0,
                       lease_token = NULL,
                       updated_at = :updated_at
                 WHERE estado = :en_curso
                   AND updated_at < :cutoff
                 LIMIT :limite';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':fallido'    => FilaEmision::ESTADO_FALLIDO,
            ':updated_at' => $now,
            ':en_curso'   => FilaEmision::ESTADO_EN_CURSO,
            ':cutoff'     => $cutoffUtc,
            ':limite'     => $limit,
        ]);
        return $stmt->rowCount();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Convierte una fila cruda del SELECT a FilaEmision.
     * Centraliza el casting (int/bool), conversion de fechas a
     * DateTimeImmutable UTC, y manejo de NULLs.
     *
     * @param array<string, mixed> $row Fila cruda del SELECT (PDO::FETCH_ASSOC).
     */
    private function rowToFila(array $row): FilaEmision
    {
        $utc = new DateTimeZone('UTC');
        return new FilaEmision(
            externalId:         (string) $row['external_id'],
            cuit:               (string) $row['cuit'],
            puntoVenta:         (int) $row['punto_venta'],
            cbteTipo:           (int) $row['cbte_tipo'],
            estado:             (string) $row['estado'],
            leaseToken:         $row['lease_token'] === null ? null : (string) $row['lease_token'],
            intento:            (int) $row['intento'],
            // tinyint(1): MySQL lo devuelve como int 0/1. Cast a bool.
            esFalloInfra:       ((int) $row['es_fallo_infra']) === 1,
            requestFingerprint: (string) $row['request_fingerprint'],
            requestJson:        (string) $row['request_json'],
            cbteNroEnviado:     $row['cbte_nro_enviado'] === null ? null : (int) $row['cbte_nro_enviado'],
            cbteFchEnviado:     $this->parseDateOrNull($row['cbte_fch_enviado'], $utc),
            cae:                $row['cae'] === null ? null : (string) $row['cae'],
            caeFchVto:          $this->parseDateOrNull($row['cae_fch_vto'], $utc),
            cbteNroConfirmado:  $row['cbte_nro_confirmado'] === null ? null : (int) $row['cbte_nro_confirmado'],
            responseJson:       $row['response_json'] === null ? null : (string) $row['response_json'],
            createdAt:          new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt:          new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }

    /**
     * Convierte un valor de columna DATE (string 'Y-m-d' o null)
     * a DateTimeImmutable en UTC. Devuelve null si la entrada es
     * null o string vacio.
     *
     * @param mixed $raw Valor leido del PDO.
     */
    private function parseDateOrNull($raw, DateTimeZone $utc): ?DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }
        $s = (string) $raw;
        if ($s === '' || $s === '0000-00-00') {
            // MariaDB tiene un modo ANSI que rellena fechas invalidas
            // con 0000-00-00; las tratamos como NULL.
            return null;
        }
        return new DateTimeImmutable($s, $utc);
    }

    /**
     * Genera un UUID v4 via el factory inyectado o el default.
     */
    private function uuid(): string
    {
        if ($this->uuidFactory !== null) {
            return ($this->uuidFactory)();
        }
        return UuidFactory::v4();
    }

    /**
     * Devuelve el reloj inyectado o now() en UTC.
     */
    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Devuelve el reloj actual formateado como 'Y-m-d H:i:s' UTC
     * listo para bindear a un DATETIME.
     */
    private function nowUtcString(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }
}
