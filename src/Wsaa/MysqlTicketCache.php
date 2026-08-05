<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsaa;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Rbbsoft\ArcaSdk\Exceptions\WsaaException;
use Throwable;

/**
 * Cache de Ticket de Acceso compartido entre workers via MySQL.
 *
 * Decisiones clave (leidas del plan maestro):
 *  - Key por (cuit, wsn). Vigencia evaluada SIEMPRE en PHP contra UTC,
 *    con un margen configurable (expiryMarginSeconds, default 300 s).
 *    Nunca NOW() / UTC_TIMESTAMP() para comparar expiracion.
 *  - loadOrAcquire() usa una conexion PDO dedicada, NO persistente,
 *    SOLO para tomar el named lock con GET_LOCK. Esa conexion se
 *    destruye al salir del try/finally (PHP cierra el PDO al perder
 *    la referencia). Las operaciones de lectura/escritura usan la
 *    conexion principal provista por el caller.
 *  - El lock name es deterministico y <= 64 chars (limite MySQL):
 *    'arca_wsaa_' . substr(sha256(cuit:wsn), 0, 50)
 *  - Configuracion de la conexion de lock:
 *      SET SESSION wait_timeout = 30
 *      SET SESSION interactive_timeout = 30
 *    Esto evita que el server mantenga conexiones zombie si el PHP-FPM
 *    muere de forma abrupta.
 *  - El lock se libera en finally, pero SOLO si fue adquirido. Ningun
 *    catch intermedio lo libera (para no liberar el lock de otro
 *    proceso en caso de carrera).
 *  - Doble-check: despues de tomar el lock, se vuelve a leer el cache.
 *    Si ya hay un TA vigente, se devuelve sin llamar al producer.
 */
final class MysqlTicketCache implements TicketCacheInterface
{
    /**
     * @param PDO $mainPdo Conexion PDO para load/save/flush/getTokenInfo.
     *                      El caller es dueno de su ciclo de vida.
     * @param Closure(): PDO $lockConnectionFactory Fabrica de conexiones
     *                      NO persistentes para los named locks. Cada
     *                      llamada debe devolver un PDO nuevo.
     * @param int $expiryMarginSeconds Margen de seguridad (segundos) al
     *                      evaluar la vigencia. Por defecto 300 s.
     * @param int $lockTimeoutSeconds Timeout pasado como 2do argumento
     *                      a GET_LOCK. Por defecto 10 s.
     * @param (Closure(): DateTimeImmutable)|null $clock Clock inyectable
     *                      para tests. Si es null, usa now() UTC.
     * @param int $lockWaitTimeoutSeconds Timeout de la conexion de lock
     *                      (atributo PDO::ATTR_TIMEOUT). Por defecto 5 s.
     */
    public function __construct(
        private readonly PDO $mainPdo,
        private readonly Closure $lockConnectionFactory,
        private readonly int $expiryMarginSeconds = 300,
        private readonly int $lockTimeoutSeconds = 10,
        private readonly ?Closure $clock = null,
        private readonly int $lockWaitTimeoutSeconds = 5,
    ) {
    }

    public function load(string $cuit, string $wsn): ?TicketDeAcceso
    {
        $stmt = $this->mainPdo->prepare(
            'SELECT cuit, wsn, token, sign, expiration_time, created_at, updated_at
               FROM arca_ticket_acceso
              WHERE cuit = ? AND wsn = ?
              LIMIT 1'
        );
        $stmt->execute([$cuit, $wsn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $ticket = $this->rowToTicket($row);
        return $ticket->isValidAt($this->now(), $this->expiryMarginSeconds) ? $ticket : null;
    }

    public function save(TicketDeAcceso $ticket): void
    {
        $now = $this->now()->format('Y-m-d H:i:s');
        $expUtc = $ticket->expirationTimeUtc->format('Y-m-d H:i:s');
        // UPSERT por (cuit, wsn). En MySQL/MariaDB: INSERT ... ON DUPLICATE
        // KEY UPDATE. Mantiene created_at intacto (no se sobreescribe).
        // OJO: PDO de MySQL con emulated prepares=false NO permite
        // referenciar el mismo parametro nombrado dos veces en el SQL.
        // Usamos :created_at y :updated_at como dos binds separados
        // aunque el valor sea identico.
        $sql = 'INSERT INTO arca_ticket_acceso
                   (cuit, wsn, token, sign, expiration_time, source, created_at, updated_at)
                VALUES
                   (:cuit, :wsn, :token, :sign, :expiration_time, :source, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                   token = VALUES(token),
                   sign = VALUES(sign),
                   expiration_time = VALUES(expiration_time),
                   source = VALUES(source),
                   updated_at = VALUES(updated_at)';
        $stmt = $this->mainPdo->prepare($sql);
        $stmt->execute([
            ':cuit'            => $ticket->cuit,
            ':wsn'             => $ticket->wsn,
            ':token'           => $ticket->token,
            ':sign'            => $ticket->sign,
            ':expiration_time' => $expUtc,
            ':source'          => $ticket->source,
            ':created_at'      => $now,
            ':updated_at'      => $now,
        ]);
    }

    public function loadOrAcquire(string $cuit, string $wsn, Closure $producer): TicketDeAcceso
    {
        // Doble-check antes de tomar el lock: si ya tenemos un TA
        // vigente evitamos la pelea de candado.
        $existing = $this->load($cuit, $wsn);
        if ($existing !== null) {
            return $existing;
        }

        $lockPdo = null;
        $lockName = $this->lockName($cuit, $wsn);
        $acquired = false;
        try {
            $lockPdo = ($this->lockConnectionFactory)();
            $this->configureLockConnection($lockPdo);
            $stmt = $lockPdo->prepare('SELECT GET_LOCK(?, ?)');
            $stmt->execute([$lockName, $this->lockTimeoutSeconds]);
            $row = $stmt->fetchColumn();
            // GET_LOCK devuelve 1 si lo adquirio, 0 si no, NULL si error.
            if ($row === false || $row === null || (int) $row !== 1) {
                throw new WsaaException(
                    sprintf(
                        'No se pudo adquirir el lock WSAA "%s" en %d s (resultado=%s). '
                        . 'Otro worker esta generando un TA o el server MySQL no responde.',
                        $lockName,
                        $this->lockTimeoutSeconds,
                        var_export($row, true)
                    )
                );
            }
            $acquired = true;

            // Doble-check post-lock: si entre el load() y el acquire()
            // alguien mas publico un TA valido, lo respetamos.
            $existing = $this->load($cuit, $wsn);
            if ($existing !== null) {
                return $existing;
            }

            $ticket = $producer();
            $this->save($ticket);
            return $ticket;
        } finally {
            if ($acquired && $lockPdo !== null) {
                try {
                    $rel = $lockPdo->prepare('SELECT RELEASE_LOCK(?)');
                    $rel->execute([$lockName]);
                    $rel->fetchColumn();
                } catch (Throwable $e) {
                    // best-effort: si el server se cayo entre la
                    // publicacion y la liberacion, el lock expira solo
                    // cuando la conexion del lock se cierre.
                }
            }
            // Al salir del scope, $lockPdo se destruye y la conexion
            // NO persistente se cierra.
            $lockPdo = null;
        }
    }

    public function flush(string $cuit, string $wsn): void
    {
        $stmt = $this->mainPdo->prepare(
            'DELETE FROM arca_ticket_acceso WHERE cuit = ? AND wsn = ?'
        );
        $stmt->execute([$cuit, $wsn]);
    }

    public function getTokenInfo(string $cuit, string $wsn): ?array
    {
        $stmt = $this->mainPdo->prepare(
            'SELECT cuit, wsn, token, sign, expiration_time, source, created_at, updated_at
               FROM arca_ticket_acceso
              WHERE cuit = ? AND wsn = ?
              LIMIT 1'
        );
        $stmt->execute([$cuit, $wsn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $ticket = $this->rowToTicket($row);
        return [
            'cuit'                => $row['cuit'],
            'wsn'                 => $row['wsn'],
            'token'               => $row['token'],
            'sign'                => $row['sign'],
            'expiration_time_utc' => $ticket->expirationTimeUtc->format('Y-m-d H:i:s'),
            'source'              => $row['source'] ?? $ticket->source,
            'is_valid'            => $ticket->isValidAt($this->now(), $this->expiryMarginSeconds),
            'created_at'          => $row['created_at'],
            'updated_at'          => $row['updated_at'],
        ];
    }

    /**
     * Nombre del named lock para el par (cuit, wsn). Deterministicoy
     * <= 64 chars (limite duro de MySQL para GET_LOCK).
     *  - Prefijo: 'arca_wsaa_' (10 chars)
     *  - Hash:    sha256(cuit + ':' + wsn) en hex (64 chars), truncado
     *             a 50 para totalizar 60 chars (margen para prefijos
     *             mas largos si hace falta).
     * Resultado: 'arca_wsaa_' + 50 hex chars = 60 chars.
     */
    public static function computeLockName(string $cuit, string $wsn): string
    {
        $hash = hash('sha256', $cuit . ':' . $wsn);
        return 'arca_wsaa_' . substr($hash, 0, 50);
    }

    private function lockName(string $cuit, string $wsn): string
    {
        return self::computeLockName($cuit, $wsn);
    }

    private function configureLockConnection(PDO $pdo): void
    {
        // wait_timeout e interactive_timeout en 30 s: si este proceso
        // muere de forma abrupta, MySQL cierra la conexion y libera
        // cualquier lock que tuviera.
        $pdo->exec('SET SESSION wait_timeout = 30');
        $pdo->exec('SET SESSION interactive_timeout = 30');
    }

    private function rowToTicket(array $row): TicketDeAcceso
    {
        // expiration_time esta persistido en UTC (ver save() y la
        // convencion documentada en sql/schema.sql). Forzamos la zona
        // para evitar depender de la zona de la sesion MySQL.
        $expUtc = new DateTimeImmutable($row['expiration_time'], new DateTimeZone('UTC'));
        $source = isset($row['source']) && $row['source'] !== '' ? (string) $row['source'] : 'mysql';
        return new TicketDeAcceso(
            cuit: (string) $row['cuit'],
            wsn: (string) $row['wsn'],
            token: (string) $row['token'],
            sign: (string) $row['sign'],
            expirationTimeUtc: $expUtc,
            source: $source,
        );
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
