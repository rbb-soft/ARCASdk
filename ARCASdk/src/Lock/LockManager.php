<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Lock;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Wrapper sobre MySQL named locks (GET_LOCK / RELEASE_LOCK) usado por
 * el orquestador de emision (Phase 6).
 *
 * Decisiones de diseno (leidas del plan maestro, seccion 6 punto 5):
 *
 *  - **Conexion dedicada, NO persistente**: el brief y MysqlTicketCache
 *    ya establecen este patron. acquire() crea una nueva PDO via la
 *    factoria inyectada; el manager mantiene la conexion viva hasta que
 *    se llama a release(). Asi, si el proceso muere de forma abrupta
 *    entre acquire y release, la conexion se cierra (no persistente) y
 *    MySQL libera el lock.
 *
 *  - **wait_timeout = interactive_timeout = 30 s**: misma politica que
 *    MysqlTicketCache::configureLockConnection. Si el cliente muere, el
 *    server libera la conexion en <= 30 s y por lo tanto libera el lock.
 *
 *  - **acquire / release separados**: el orquestador Phase 6 sigue el
 *    patron:
 *        $acquired = $lock->acquire($name, $timeout);
 *        try { ... do work ... } finally {
 *            if ($acquired) { $lock->release($name); }
 *        }
 *    El LockManager mantiene internamente la conexion entre acquire y
 *    release (los named locks de MySQL son connection-scoped: una
 *    conexion distinta no puede liberar el lock de otra).
 *
 *  - **GET_LOCK resultado**:
 *      1  -> lock adquirido (acquire devuelve true, conexion retenida)
 *      0  -> timeout agotado, lock NO adquirido (devuelve false)
 *      -1 -> error (deadlock, killed, etc.) -> lanzamos RuntimeException
 *    La diferenciacion entre 0 (espera legitima) y -1 (error real) es
 *    importante para que el caller decida si reintentar.
 *
 *  - **release()**:
 *      1  -> lock liberado (devuelve true)
 *      0  -> lock nunca fue adquirido por ESTA conexion, o ya fue
 *            liberado, o la conexion cayo (devuelve false)
 *    Por consistencia con la API publica, release() es "best effort":
 *    no propaga excepciones. Si la conexion murio, la liberacion igual
 *    ocurre cuando la conexion se cierre en el server (wait_timeout).
 *
 *  - **Lock name length**: el caller es responsable de pasar un nombre
 *    que cumpla el limite de 64 chars de MySQL. Ofrecemos
 *    `computeEmitLockName()` que ya respeta ese limite.
 *
 *  - **Sin timeout implicito en SQL**: timeoutSeconds se pasa a GET_LOCK
 *    como segundo argumento. No usamos SET STATEMENT ni cosas similares.
 */
class LockManager
{
    /**
     * Limite duro de MySQL para el nombre de un GET_LOCK.
     */
    public const MAX_LOCK_NAME_LENGTH = 64;

    /**
     * Prefijo usado para los locks de emision. Distinto del prefijo WSAA
     * ('arca_wsaa_') para evitar colisiones en la tabla interna de locks
     * de MySQL.
     */
    public const EMIT_LOCK_PREFIX = 'arca_emit_';

    /**
     * Mapa nombre => PDO. Solo contiene locks que acquire() devolvio
     * como true (es decir, locks que ESTA instancia mantiene). Las
     * entradas se borran al llamar a release() o al destruct.
     *
     * @var array<string, PDO>
     */
    private array $heldLocks = [];

    /**
     * @param Closure(): PDO $connectionFactory Fabrica de conexiones
     *        PDO NO persistentes. Se invoca una vez por acquire()
     *        exitoso; la conexion resultante se mantiene hasta el
     *        release() correspondiente.
     * @param int $waitTimeoutSeconds SET SESSION wait_timeout al abrir
     *        la conexion. Default 30 s (alineado con MysqlTicketCache).
     * @param int $interactiveTimeoutSeconds SET SESSION interactive_timeout
     *        al abrir la conexion. Default 30 s.
     */
    public function __construct(
        private readonly Closure $connectionFactory,
        private readonly int $waitTimeoutSeconds = 30,
        private readonly int $interactiveTimeoutSeconds = 30,
    ) {
    }

    /**
     * Intenta adquirir el named lock `$name` con timeout `$timeoutSeconds`.
     *
     * Reglas de retorno:
     *  - true:  GET_LOCK devolvio 1 (lock adquirido, conexion retenida
     *           internamente hasta que se llame a release($name))
     *  - false: GET_LOCK devolvio 0 (timeout agotado, lock NO adquirido)
     *  - throw RuntimeException: GET_LOCK devolvio -1 (error / deadlock)
     *  - throw RuntimeException: nombre > 64 chars
     *  - throw RuntimeException: timeoutSeconds < 0
     *
     * Si la conexion de lock ya existia para este nombre (release no
     * llamado), releaseQuietly y re-toma.
     *
     * @throws RuntimeException Si el nombre excede 64 chars, el timeout
     *                          es negativo, o MySQL reporta -1.
     */
    public function acquire(string $name, int $timeoutSeconds): bool
    {
        if (strlen($name) > self::MAX_LOCK_NAME_LENGTH) {
            throw new RuntimeException(sprintf(
                'LockManager: nombre de lock excede %d chars (long=%d). Nombre: "%s"',
                self::MAX_LOCK_NAME_LENGTH,
                strlen($name),
                $name
            ));
        }
        if ($timeoutSeconds < 0) {
            throw new RuntimeException('LockManager: timeoutSeconds no puede ser negativo');
        }

        // Defensa contra doble acquire del mismo nombre: si esta
        // instancia ya lo sostiene, lo liberamos antes de re-tomar
        // para evitar la condicion "el mismo session tiene el lock
        // dos veces", que MySQL resuelve con un counter pero no es
        // lo que el orquestador quiere.
        if (isset($this->heldLocks[$name])) {
            $this->releaseQuietly($this->heldLocks[$name], $name);
            unset($this->heldLocks[$name]);
        }

        $pdo = ($this->connectionFactory)();
        $this->configureConnection($pdo);

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$name, $timeoutSeconds]);
        $raw = $stmt->fetchColumn();

        // GET_LOCK puede devolver NULL segun docs (raro en la practica).
        // Lo tratamos igual que -1 (error).
        if ($raw === null || $raw === false) {
            // cerrar conexion
            $pdo = null;
            throw new RuntimeException(sprintf(
                'LockManager: GET_LOCK("%s", %d) devolvio NULL/false (sin resultado)',
                $name,
                $timeoutSeconds
            ));
        }
        $result = (int) $raw;
        if ($result === 1) {
            $this->heldLocks[$name] = $pdo;
            return true;
        }
        if ($result === 0) {
            // timeout: cerramos la conexion y devolvemos false.
            $pdo = null;
            return false;
        }
        // -1 (o cualquier otro valor negativo) -> error de MySQL.
        $pdo = null;
        throw new RuntimeException(sprintf(
            'LockManager: GET_LOCK("%s", %d) devolvio %d (error/deadlock). '
            . 'MySQL reporto un problema no recuperable con la conexion de lock.',
            $name,
            $timeoutSeconds,
            $result
        ));
    }

    /**
     * Libera el named lock `$name`.
     *
     * Devuelve:
     *  - true:  RELEASE_LOCK devolvio 1 (lock liberado, conexion cerrada)
     *  - false: lock nunca fue adquirido por ESTA instancia, o el nombre
     *           ya no esta en heldLocks, o RELEASE_LOCK devolvio 0
     *
     * NO lanza excepciones: la liberacion es best-effort. Si la conexion
     * murio, MySQL libera el lock cuando la cierra (wait_timeout).
     */
    public function release(string $name): bool
    {
        if (!isset($this->heldLocks[$name])) {
            return false;
        }
        $pdo = $this->heldLocks[$name];
        unset($this->heldLocks[$name]);

        $ok = $this->releaseQuietly($pdo, $name);
        // cerrar conexion (PHP la destruye al perder la referencia)
        $pdo = null;
        return $ok;
    }

    /**
     * True si esta instancia sostiene el lock $name actualmente.
     * Util para diagnostico y para que el orquestador verifique el
     * "finally pattern" sin escribir try/finally explicito.
     */
    public function isHeld(string $name): bool
    {
        return isset($this->heldLocks[$name]);
    }

    /**
     * Calcula el nombre del lock de emision para (cuit, puntoVenta, cbteTipo).
     *
     * Pre-condiciones del nombre:
     *  - Determinista: misma tripla -> mismo nombre
     *  - <= 64 chars: 'arca_emit_' (10) + 50 hex = 60 chars (margen)
     *  - Sin caracteres no controlados: solo [a-z0-9_]
     *
     * El caller debe usar exactamente este nombre al tomar el lock; sino
     * dos workers no se sincronizaran.
     */
    public static function computeEmitLockName(string $cuit, int $puntoVenta, int $cbteTipo): string
    {
        $hash = hash('sha256', $cuit . ':' . $puntoVenta . ':' . $cbteTipo);
        return self::EMIT_LOCK_PREFIX . substr($hash, 0, 50);
    }

    /**
     * Libera cualquier lock que aun sostenga esta instancia. Pensado
     * para destructores/tests. NO deberia invocarse desde el flujo
     * normal del orquestador: el `finally` ya hace release().
     */
    public function releaseAll(): void
    {
        foreach (array_keys($this->heldLocks) as $name) {
            $this->release($name);
        }
    }

    public function __destruct()
    {
        $this->releaseAll();
    }

    /**
     * Configura la conexion de lock: wait_timeout / interactive_timeout.
     * SET SESSION no es transaccional; si falla (ej. permisos), la
     * conexion queda utilizable igualmente.
     */
    private function configureConnection(PDO $pdo): void
    {
        try {
            $pdo->exec(sprintf('SET SESSION wait_timeout = %d', $this->waitTimeoutSeconds));
        } catch (Throwable) {
            // best-effort: si el server no permite SET SESSION, igual
            // la conexion sirve para GET_LOCK.
        }
        try {
            $pdo->exec(sprintf('SET SESSION interactive_timeout = %d', $this->interactiveTimeoutSeconds));
        } catch (Throwable) {
            // best-effort
        }
    }

    /**
     * Best-effort RELEASE_LOCK. Devuelve true si MySQL reporto 1,
     * false en cualquier otro caso (incluyendo excepciones).
     */
    private function releaseQuietly(PDO $pdo, string $name): bool
    {
        try {
            $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$name]);
            $raw = $rel->fetchColumn();
            if ($raw === null || $raw === false) {
                return false;
            }
            return (int) $raw === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
