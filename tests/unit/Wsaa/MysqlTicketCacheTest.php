<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsaa;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Exceptions\WsaaException;
use Rbbsoft\ArcaSdk\Wsaa\MysqlTicketCache;
use Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso;
use Throwable;

/**
 * Tests de MysqlTicketCache con una conexion MySQL REAL.
 * Usa una DB separada 'arca_facturador_test' para no contaminar
 * la DB de produccion. Cada test arranca con la tabla limpia.
 *
 * Si MySQL no esta disponible, los tests se saltan (markTestSkipped)
 * para no romper la suite en entornos sin DB.
 */
final class MysqlTicketCacheTest extends TestCase
{
    private const DSN  = 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
    private const USER = 'root';
    private const PASS = '';

    private const CUIT_A = '20111111111';
    private const CUIT_B = '20222222222';
    private const WSN    = 'wsfe';

    private ?PDO $pdo = null;
    private ?Closure $rwFactory = null;
    private ?Closure $lockFactory = null;
    private ?DateTimeImmutable $now = null;
    private ?Closure $clock = null;

    /**
     * PIDs de procesos hijo lanzados con proc_open que aun no se cerraron.
     * Se matan en tearDown para que un test fallido no deje zombies que
     * mantengan el lock de MySQL tomado contra fixtures futuras.
     *
     * @var int[]
     */
    private array $childPids = [];

    /**
     * Script PHP autonomo que adquiere el named lock WSAA, duerme N ms y
     * lo libera. Vive embebido como const para no depender de un archivo
     * en el filesystem que pueda no existir. El test lo escribe a un
     * tempnam() antes de invocar proc_open.
     *
     * Convencion: el lock name se recalcula con la misma formula que
     * MysqlTicketCache::computeLockName() (sha256(cuit:wsn)[:50] con
     * prefijo 'arca_wsaa_'). NO se importa la clase para mantener el
     * proceso hijo libre de dependencias del autoloader del proyecto.
     *
     * Sincronizacion con el padre: ademas de stderr, el hijo escribe a
     * un archivo "ready" cuando confirma que tiene el lock. El padre
     * hace polling de ese archivo en vez de probar con GET_LOCK (que
     * puede dar resultados confusos si su propia conexion MySQL queda
     * abierta compitiendo por el lock).
     */
    private const HOLDER_SCRIPT = <<<'PHP_HOLDER'
<?php
declare(strict_types=1);

// arca_lock_holder.php - holds a MySQL named lock for a fixed duration.
// argv: <cuit> <wsn> <sleepMs> <readyFile>
// env:  ARCA_TEST_DSN / ARCA_TEST_USER / ARCA_TEST_PASS (opcionalmente
//       ARCA_HOLDER_LOG para un log extra en disco).
$cuit      = $argv[1] ?? '';
$wsn       = $argv[2] ?? '';
$sleepMs   = (int) ($argv[3] ?? 0);
$readyFile = $argv[4] ?? '';
$logPath   = getenv('ARCA_HOLDER_LOG') ?: '';
$log = static function (string $msg) use ($logPath): void {
    fwrite(STDERR, "holder: $msg\n");
    if ($logPath !== '') {
        @file_put_contents($logPath, '[' . date('H:i:s') . "] $msg\n", FILE_APPEND);
    }
};
if ($cuit === '' || $wsn === '' || $sleepMs <= 0 || $readyFile === '') {
    $log("bad args (cuit='$cuit' wsn='$wsn' sleepMs=$sleepMs readyFile='$readyFile')");
    exit(2);
}
$lockName = 'arca_wsaa_' . substr(hash('sha256', $cuit . ':' . $wsn), 0, 50);
$dsn  = getenv('ARCA_TEST_DSN')  ?: 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
$user = getenv('ARCA_TEST_USER') ?: 'root';
$pass = getenv('ARCA_TEST_PASS') ?: '';
$log("starting; lock=$lockName sleep={$sleepMs}ms pid=" . getmypid());
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_PERSISTENT => false,
    ]);
} catch (Throwable $e) {
    $log('connect failed: ' . $e->getMessage());
    exit(3);
}
$stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
$stmt->execute([$lockName]);
$acquired = (int) $stmt->fetchColumn();
if ($acquired !== 1) {
    $log("GET_LOCK returned $acquired (not 1) for $lockName");
    exit(4);
}
$log("acquired $lockName; sleeping {$sleepMs}ms");
// Senal al padre: archivo ready. El padre lo lee para saber que
// el lock esta tomado y arrancar su propia medicion de timing.
@file_put_contents($readyFile, 'pid=' . getmypid() . ' at=' . microtime(true));
usleep($sleepMs * 1000);
$rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
$rel->execute([$lockName]);
$rel->fetchColumn();
$log("released $lockName; exiting");
@unlink($readyFile);
exit(0);
PHP_HOLDER;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql no disponible');
        }
        try {
            $this->pdo = new PDO(self::DSN, self::USER, self::PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (Throwable $e) {
            $this->markTestSkipped('No se pudo conectar a MySQL de test: ' . $e->getMessage());
        }

        $this->ensureTable();
        $this->pdo->exec('TRUNCATE TABLE arca_ticket_acceso');

        $this->rwFactory = fn(): PDO => new PDO(self::DSN, self::USER, self::PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $this->lockFactory = fn(): PDO => new PDO(self::DSN, self::USER, self::PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false,
        ]);

        $this->now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $this->clock = fn(): DateTimeImmutable => $this->now;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            try {
                $this->pdo->exec('TRUNCATE TABLE arca_ticket_acceso');
            } catch (Throwable $e) {
                // ignore
            }
        }
        // Matar cualquier proceso hijo que proc_open haya dejado vivo
        // (test fallo, excepcion intermedia, etc.). En Windows la
        // sintaxis es taskkill /F /PID; en Linux/Mac seria kill -9.
        foreach ($this->childPids as $pid) {
            if ($pid <= 0) {
                continue;
            }
            if (DIRECTORY_SEPARATOR === '\\') {
                @exec(sprintf('taskkill /F /PID %d 2>nul', $pid));
            } else {
                @posix_kill($pid, 9);
            }
        }
        $this->childPids = [];
    }

    private function ensureTable(): void
    {
        // El test corre contra una DB limpia; la crea en setUp si hace falta.
        // Primero intenta crear la DB (puede fallar si ya existe).
        $rootPdo = new PDO('mysql:host=localhost;charset=utf8mb4', self::USER, self::PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $rootPdo->exec('CREATE DATABASE IF NOT EXISTS arca_facturador_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS arca_ticket_acceso (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cuit            CHAR(11)        NOT NULL,
                wsn             VARCHAR(64)     NOT NULL,
                token           TEXT            NOT NULL,
                sign            TEXT            NOT NULL,
                expiration_time DATETIME        NOT NULL,
                source          VARCHAR(32)     NULL,
                created_at      DATETIME        NOT NULL,
                updated_at      DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_cuit_wsn (cuit, wsn)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function newCache(int $margin = 300, int $lockTimeout = 10, ?Closure $clock = null): MysqlTicketCache
    {
        return new MysqlTicketCache(
            ($this->rwFactory)(),
            $this->lockFactory,
            expiryMarginSeconds: $margin,
            lockTimeoutSeconds: $lockTimeout,
            clock: $clock ?? $this->clock,
        );
    }

    private function makeTicket(string $cuit, string $token, DateTimeImmutable $exp, string $source = 'wsfe'): TicketDeAcceso
    {
        return new TicketDeAcceso($cuit, self::WSN, $token, 'SGN_' . $token, $exp, $source);
    }

    public function test_lockName_deterministico_y_dentro_del_limite(): void
    {
        $a = MysqlTicketCache::computeLockName(self::CUIT_A, self::WSN);
        $b = MysqlTicketCache::computeLockName(self::CUIT_A, self::WSN);
        $c = MysqlTicketCache::computeLockName(self::CUIT_B, self::WSN);
        $this->assertSame($a, $b, 'mismo (cuit, wsn) -> mismo lockName');
        $this->assertNotSame($a, $c, 'distinto cuit -> distinto lockName');
        $this->assertLessThanOrEqual(64, strlen($a), 'lockName <= 64 chars (limite MySQL)');
        $this->assertStringStartsWith('arca_wsaa_', $a);
    }

    public function test_save_y_load_roundtrip(): void
    {
        $cache = $this->newCache();
        $exp = $this->now->modify('+30 minutes');
        $cache->save($this->makeTicket(self::CUIT_A, 'TKN1', $exp));

        $loaded = $cache->load(self::CUIT_A, self::WSN);
        $this->assertNotNull($loaded);
        $this->assertSame('TKN1', $loaded->token);
        $this->assertSame('SGN_TKN1', $loaded->sign);
        $this->assertSame($exp->format('Y-m-d H:i:s'), $loaded->expirationTimeUtc->format('Y-m-d H:i:s'));
    }

    public function test_save_upserts(): void
    {
        $cache = $this->newCache();
        $exp1 = $this->now->modify('+30 minutes');
        $cache->save($this->makeTicket(self::CUIT_A, 'TKN_1', $exp1));

        $exp2 = $this->now->modify('+60 minutes');
        $cache->save($this->makeTicket(self::CUIT_A, 'TKN_2', $exp2));

        // Sigue siendo una sola fila para (CUIT_A, WSN)
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM arca_ticket_acceso WHERE cuit = ' . $this->pdo->quote(self::CUIT_A) . ' AND wsn = ' . $this->pdo->quote(self::WSN));
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'save() hace upsert, no duplica filas');

        $loaded = $cache->load(self::CUIT_A, self::WSN);
        $this->assertSame('TKN_2', $loaded->token, 'segundo save() sobrescribio el primero');
    }

    public function test_load_devuelve_null_si_no_hay_fila(): void
    {
        $cache = $this->newCache();
        $this->assertNull($cache->load('20999999999', self::WSN));
    }

    public function test_load_evalua_expiracion_con_margen_en_utc(): void
    {
        $cache = $this->newCache(margin: 300); // 5 min
        // Vence en 4 minutos -> con margen 5 min -> VENCIDO
        $exp = $this->now->modify('+4 minutes');
        $cache->save($this->makeTicket(self::CUIT_A, 'TKN_CLOSE', $exp));
        $this->assertNull($cache->load(self::CUIT_A, self::WSN), 'TA con margen vencido devuelve null');

        // Vence en 10 minutos -> con margen 5 min -> VIGENTE
        $exp2 = $this->now->modify('+10 minutes');
        $cache->save($this->makeTicket(self::CUIT_A, 'TKN_FAR', $exp2));
        $loaded = $cache->load(self::CUIT_A, self::WSN);
        $this->assertNotNull($loaded);
        $this->assertSame('TKN_FAR', $loaded->token);
    }

    public function test_load_con_ta_vencido_no_devuelve_incluso_si_fila_existe(): void
    {
        $cache = $this->newCache(margin: 0);
        // Vencio hace 1 hora
        $exp = $this->now->modify('-1 hour');
        $cache->save($this->makeTicket(self::CUIT_A, 'TKN_OLD', $exp));
        $this->assertNull($cache->load(self::CUIT_A, self::WSN));

        // La fila sigue en la DB
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM arca_ticket_acceso WHERE cuit = ' . $this->pdo->quote(self::CUIT_A));
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_flush_borra_fila(): void
    {
        $cache = $this->newCache();
        $exp = $this->now->modify('+30 minutes');
        $cache->save($this->makeTicket(self::CUIT_A, 'TKN', $exp));
        $this->assertNotNull($cache->load(self::CUIT_A, self::WSN));
        $cache->flush(self::CUIT_A, self::WSN);
        $this->assertNull($cache->load(self::CUIT_A, self::WSN));
        // flush idempotente
        $cache->flush(self::CUIT_A, self::WSN);
    }

    public function test_getTokenInfo_null_si_no_hay_fila(): void
    {
        $cache = $this->newCache();
        $this->assertNull($cache->getTokenInfo('20999999999', self::WSN));
    }

    public function test_getTokenInfo_incluye_is_valid(): void
    {
        $cache = $this->newCache(margin: 300);
        $exp = $this->now->modify('+30 minutes');
        $cache->save($this->makeTicket(self::CUIT_A, 'TKN', $exp));
        $info = $cache->getTokenInfo(self::CUIT_A, self::WSN);
        $this->assertIsArray($info);
        $this->assertSame(self::CUIT_A, $info['cuit']);
        $this->assertSame(self::WSN, $info['wsn']);
        $this->assertTrue($info['is_valid']);
        $this->assertSame('wsfe', $info['source']);
    }

    public function test_lock_adquirido_y_liberado_en_finally(): void
    {
        // Caso cache-miss: el producer es invocado dentro del lock y
        // guarda la fila; al salir el lock debe estar liberado.
        $cache = $this->newCache(lockTimeout: 5);
        $exp = $this->now->modify('+30 minutes');

        $producerCalls = 0;
        $producer = function () use ($cache, $exp, &$producerCalls): TicketDeAcceso {
            $producerCalls++;
            // Productor: dentro del lock, valida que la fila aun no esta
            // y la guarda. Despues de guardar, load() la ve.
            $before = $cache->load(self::CUIT_A, self::WSN);
            $this->assertNull($before, 'antes del save del producer, load debe devolver null');
            $cache->save($this->makeTicket(self::CUIT_A, 'TKN_LOCKED', $exp));
            return $cache->load(self::CUIT_A, self::WSN);
        };

        $ticket = $cache->loadOrAcquire(self::CUIT_A, self::WSN, $producer);
        $this->assertNotNull($ticket);
        $this->assertSame(1, $producerCalls, 'producer debe ser invocado una vez (cache miss inicial)');

        // Tras salir, el lock debe estar liberado: otro proceso puede tomarlo
        $lockName = MysqlTicketCache::computeLockName(self::CUIT_A, self::WSN);
        $other = ($this->lockFactory)();
        $stmt = $other->prepare('SELECT GET_LOCK(?, 1)');
        $stmt->execute([$lockName]);
        $this->assertSame('1', (string) $stmt->fetchColumn(), 'tras loadOrAcquire el lock debe estar liberado');
        $rel = $other->prepare('SELECT RELEASE_LOCK(?)');
        $rel->execute([$lockName]);
        $rel->fetchColumn();
    }

    public function test_loadOrAcquire_cache_hit_no_invoca_producer(): void
    {
        $cache = $this->newCache();
        $exp = $this->now->modify('+30 minutes');
        $cache->save($this->makeTicket(self::CUIT_A, 'TKN_CACHED', $exp));

        $calls = 0;
        $ticket = $cache->loadOrAcquire(self::CUIT_A, self::WSN, function () use (&$calls) {
            $calls++;
            return $this->makeTicket('99999999999', 'NEVER', $this->now->modify('+30 minutes'));
        });
        $this->assertSame(0, $calls);
        $this->assertSame('TKN_CACHED', $ticket->token);
    }

    public function test_loadOrAcquire_lock_timeout_lanza_wsaaexception(): void
    {
        // Sostener el lock externamente y luego intentar loadOrAcquire.
        $lockName = MysqlTicketCache::computeLockName(self::CUIT_A, self::WSN);
        $holder = ($this->lockFactory)();
        $stmt = $holder->prepare('SELECT GET_LOCK(?, 1)');
        $stmt->execute([$lockName]);
        $this->assertSame('1', (string) $stmt->fetchColumn(), 'el holder externo tomo el lock');

        try {
            // Intentar loadOrAcquire con timeout corto -> falla en ~1s
            $cache = $this->newCache(lockTimeout: 1);
            $producer = function () {
                $this->fail('producer no debe invocarse cuando el lock no se adquiere');
            };
            $start = microtime(true);
            try {
                $cache->loadOrAcquire(self::CUIT_A, self::WSN, $producer);
                $this->fail('Debio lanzar WsaaException por timeout de GET_LOCK');
            } catch (WsaaException $e) {
                $msg = $e->getMessage();
                $this->assertStringContainsString('lock', $msg);
            }
            $elapsed = microtime(true) - $start;
            $this->assertGreaterThanOrEqual(0.9, $elapsed, 'timeout cercano a 1s');
            $this->assertLessThan(3.0, $elapsed, 'timeout no debe exceder 2x el limite');
        } finally {
            $rel = $holder->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$lockName]);
            $rel->fetchColumn();
        }
    }

    /**
     * @group disabled
     *
     * DEPRECADO: este test NO ejercita la condicion de carrera. PHP no
     * tiene threads reales; lo que hacia era:
     *   1. adquirir el lock en el mismo proceso del test
     *   2. llamar a usleep(500_000) (sigue en el mismo proceso)
     *   3. liberar el lock
     *   4. recien ahi llamar a loadOrAcquire() -> corre con lock libre
     * El probe mostro: loadOrAcquire (lock was free) took: 0.015s.
     * Una falla real en la logica del lock (nombre mal, no liberacion,
     * deadlock) NUNCA se detectaria con este test.
     *
     * El reemplazo real es
     *   test_loadOrAcquire_dos_workers_concurrentes_segundo_espera_y_procede
     * que spawna un proceso hijo con proc_open y verifica que el padre
     * efectivamente espera.
     *
     * Marcado @group disabled y excluido en phpunit.xml para no
     * confundir a futuros lectores con un nombre que sugiere lo que
     * el test NO hace.
     */
    public function test_loadOrAcquire_segundo_caller_espera_y_procede(): void
    {
        // Sostener el lock externamente por un instante; loadOrAcquire debe
        // esperar y luego proceder.
        $lockName = MysqlTicketCache::computeLockName(self::CUIT_A, self::WSN);
        $holder = ($this->lockFactory)();
        $stmt = $holder->prepare('SELECT GET_LOCK(?, 1)');
        $stmt->execute([$lockName]);
        $this->assertSame('1', (string) $stmt->fetchColumn());

        // Liberar el lock despues de 500ms
        $released = false;
        $releaseThread = function () use ($holder, $lockName, &$released) {
            usleep(500_000);
            $rel = $holder->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$lockName]);
            $rel->fetchColumn();
            $released = true;
        };
        // No podemos hacer threads reales en PHP; lanzamos un proceso
        // separado? Mejor: usamos un lock muy corto (50ms) y dejamos
        // que el holder lo libere mientras el caller espera.
        //
        // En lugar de eso: el holder mantiene el lock y loadOrAcquire
        // intenta con timeout > 500ms. El holder libera a los 500ms.
        $releaseThread();

        // Esperar a que el thread lo libere
        $deadline = microtime(true) + 1.0;
        while (!$released && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertTrue($released);

        // El lock esta libre: loadOrAcquire debe proceder
        $cache = $this->newCache(lockTimeout: 3);
        $calls = 0;
        $ticket = $cache->loadOrAcquire(self::CUIT_A, self::WSN, function () use (&$calls) {
            $calls++;
            return $this->makeTicket(self::CUIT_A, 'TKN_FRESH', $this->now->modify('+30 minutes'));
        });
        $this->assertSame(1, $calls);
        $this->assertSame('TKN_FRESH', $ticket->token);
    }

    public function test_diferente_cuit_o_wsn_distinto_lockName(): void
    {
        $cache = $this->newCache();
        $exp = $this->now->modify('+30 minutes');
        $cache->save($this->makeTicket(self::CUIT_A, 'TKN_A', $exp));
        $cache->save($this->makeTicket(self::CUIT_B, 'TKN_B', $exp));

        $lockNameA = MysqlTicketCache::computeLockName(self::CUIT_A, self::WSN);
        $lockNameB = MysqlTicketCache::computeLockName(self::CUIT_B, self::WSN);
        $this->assertNotSame($lockNameA, $lockNameB);

        // loadOrAcquire de CUIT_A no ve la fila de CUIT_B
        $a = $cache->loadOrAcquire(self::CUIT_A, self::WSN, fn() => $this->makeTicket('00000000000', 'X', $exp));
        $this->assertSame('TKN_A', $a->token);
        $b = $cache->loadOrAcquire(self::CUIT_B, self::WSN, fn() => $this->makeTicket('00000000000', 'X', $exp));
        $this->assertSame('TKN_B', $b->token);
    }

    /**
     * Race test REAL con dos procesos PHP independientes.
     *
     * Que verifica:
     *  1. Mientras un proceso externo sostiene el named lock, el padre
     *     que llama a loadOrAcquire() BLOQUEA esperando.
     *  2. Cuando el hijo libera el lock, el padre toma el lock, corre
     *     el producer y devuelve el TA.
     *  3. El tiempo total de espera del padre es >= tiempo que el hijo
     *     sostuvo el lock + un pequeno epsilon (clock granularity).
     *
     * Mecanica:
     *  - proc_open lanza un PHP hijo que ejecuta HOLDER_SCRIPT. El hijo
     *    toma el lock con GET_LOCK(name, 5), duerme $sleepMs, lo libera
     *    y exit. Usa exactamente la misma formula de lock name que
     *    MysqlTicketCache::computeLockName().
     *  - Antes de medir, el padre espera a que el hijo REALMENTE tenga
     *    el lock leyendo un "ready file" que el hijo escribe como
     *    side-effect post-GET_LOCK. Esto elimina la ventana en la que
     *    el padre podria entrar antes que el hijo y no experimentar
     *    contencion (un probe con GET_LOCK(name, 0) no es confiable
     *    porque la propia conexion del probe puede quedar abierta
     *    compitiendo con el padre por el lock).
     *  - El padre mide wall-clock alrededor de loadOrAcquire() y asserta
     *    elapsed >= sleepMs/1000 - epsilon.
     *  - tearDown() mata cualquier PID hijo que proc_close() no haya
     *    podido cerrar (test fallo, excepcion intermedia) usando
     *    `taskkill /F /PID` en Windows y `posix_kill(pid, 9)` en Unix.
     *
     * Restricciones:
     *  - Requiere proc_open() y PHP CLI accesible via PHP_BINARY.
     *  - Si proc_open no esta, el test se skipea; no rompe la suite.
     */
    public function test_loadOrAcquire_dos_workers_concurrentes_segundo_espera_y_procede(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open no disponible en este PHP');
        }
        $php = PHP_BINARY;
        if ($php === '' || !is_executable($php)) {
            $this->markTestSkipped("PHP CLI no encontrado o no ejecutable: '{$php}'");
        }

        // Escribir el holder script a un tempnam (writable, unico).
        $holderPath = tempnam(sys_get_temp_dir(), 'arca_holder_');
        if ($holderPath === false) {
            $this->markTestSkipped('tempnam() devolvio false; no se pudo crear holder script');
        }
        // Agregar extension .php para que se autodescriba, aunque
        // proc_open no la necesita (el shebang/CLI la infiere por argv).
        $holderPathPhp = $holderPath . '.php';
        if (!@rename($holderPath, $holderPathPhp)) {
            @unlink($holderPath);
            $this->markTestSkipped("rename a {$holderPathPhp} fallo");
        }
        if (file_put_contents($holderPathPhp, self::HOLDER_SCRIPT) === false) {
            @unlink($holderPathPhp);
            $this->markTestSkipped("no se pudo escribir holder script {$holderPathPhp}");
        }
        @chmod($holderPathPhp, 0o700);

        // Configuracion del hijo. Pasar DSN/user/pass por env asi el
        // script no necesita conocer los defaults del proyecto.
        $sleepMs = 800;
        // El child escribe este archivo cuando confirma que tiene el
        // lock; el padre lo poll-ea en vez de probar con GET_LOCK para
        // evitar races con la propia conexion de probe del padre.
        $readyFile = tempnam(sys_get_temp_dir(), 'arca_holder_ready_');
        if ($readyFile === false) {
            @unlink($holderPathPhp);
            $this->markTestSkipped('tempnam() devolvio false para readyFile');
        }
        $cmd = [
            $php,
            $holderPathPhp,
            self::CUIT_A,
            self::WSN,
            (string) $sleepMs,
            $readyFile,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // En Windows, proc_open necesita ciertas env vars para que el
        // child PHP cargue php.ini; PATH y SystemRoot son las que mas
        // rompen si faltan.
        $env = [
            'ARCA_TEST_DSN'  => self::DSN,
            'ARCA_TEST_USER' => self::USER,
            'ARCA_TEST_PASS' => self::PASS,
            'PATH'           => getenv('PATH') ?: '',
            'SystemRoot'     => getenv('SystemRoot') ?: 'C:\\Windows',
            'TEMP'           => getenv('TEMP') ?: sys_get_temp_dir(),
            'TMP'            => getenv('TMP') ?: sys_get_temp_dir(),
        ];
        // Tambien pasamos un log file path asi el child deja un rastro
        // diagnostico en disco, util cuando el stderr del pipe esta
        // vacio o buffering hace que no se vea.
        $holderLog = tempnam(sys_get_temp_dir(), 'arca_holder_log_') . '.log';
        @unlink($holderLog); // empezar limpio
        $env['ARCA_HOLDER_LOG'] = $holderLog;

        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($proc, 'proc_open devolvio un process resource');
        // No escribimos al stdin del hijo; cerrarlo inmediatamente.
        fclose($pipes[0]);
        // Drenar pipes en non-blocking: el hijo escribe ~100 bytes,
        // no deberia llenar el buffer, pero por las dudas no bloqueamos.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $childStdout = '';
        $childStderr = '';

        try {
            // Paso 1: esperar a que el hijo confirme (vía readyFile) que
            // tiene el lock. El archivo es un side-effect post-GET_LOCK,
            // asi que cuando aparece sabemos que el GET_LOCK retorno 1.
            $readyDeadline = microtime(true) + 5.0;
            $pollIterations = 0;
            while (microtime(true) < $readyDeadline) {
                $pollIterations++;
                clearstatcache(true, $readyFile);
                if (is_file($readyFile)) {
                    $sz = (int) @filesize($readyFile);
                    if ($sz > 0) {
                        break;
                    }
                }
                usleep(20_000); // 20ms
            }
            clearstatcache(true, $readyFile);
            $existsAfterBreak = is_file($readyFile);
            $sizeAfterBreak = $existsAfterBreak ? (int) @filesize($readyFile) : -1;
            $this->assertTrue(
                $existsAfterBreak && $sizeAfterBreak > 0,
                sprintf(
                    'el proceso hijo no escribio el readyFile en 5s (poll iters=%d, postBreakExists=%s postBreakSize=%d)',
                    $pollIterations,
                    $existsAfterBreak ? 'true' : 'false',
                    $sizeAfterBreak
                )
            );

            // Paso 2: ahora que el hijo tiene el lock, el padre llama
            // a loadOrAcquire. El GET_LOCK(name, 10) del padre debe
            // bloquear hasta que el hijo libere.
            $cache = $this->newCache(lockTimeout: 10);
            $producerCalls = 0;
            $start = microtime(true);
            $ticket = $cache->loadOrAcquire(self::CUIT_A, self::WSN, function () use (&$producerCalls) {
                $producerCalls++;
                return $this->makeTicket(self::CUIT_A, 'TKN_AFTER_WAIT', $this->now->modify('+30 minutes'));
            });
            $elapsed = microtime(true) - $start;

            // Paso 3: aserciones de comportamiento y timing.
            $this->assertSame(
                1,
                $producerCalls,
                'producer se invoco una vez (cache miss + post-lock double-check miss)'
            );
            $this->assertSame(
                'TKN_AFTER_WAIT',
                $ticket->token,
                'el padre recibio el TA del producer tras esperar'
            );

            // Epsilon: usleep() en Windows es preciso a ~15ms (scheduler
            // tick); permitimos 100ms de slack hacia abajo y 1s hacia
            // arriba (el lockTimeout del padre es 10s, asi que algo
            // muy alto indicaria que el padre no se desbloqueo).
            $minElapsed = ($sleepMs / 1000.0) - 0.1;
            $this->assertGreaterThanOrEqual(
                $minElapsed,
                $elapsed,
                sprintf(
                    'el padre debio esperar al menos %.3fs (sleep=%dms); elapsed=%.3fs',
                    $minElapsed,
                    $sleepMs,
                    $elapsed
                )
            );
            $this->assertLessThan(
                5.0,
                $elapsed,
                sprintf('el padre demoro %.3fs, demasiado para un sleep de %dms', $elapsed, $sleepMs)
            );
        } finally {
            // Drenar pipes y cerrar child, registre o no su PID para
            // cleanup en tearDown por si proc_close no logra cerrarlo.
            $childStdout .= (string) stream_get_contents($pipes[1]);
            $childStderr .= (string) stream_get_contents($pipes[2]);
            foreach ([1, 2] as $idx) {
                if (is_resource($pipes[$idx])) {
                    fclose($pipes[$idx]);
                }
            }
            $status = proc_get_status($proc);
            $childPid = $status['pid'] ?? 0;
            $exit = proc_close($proc);
            if ($exit !== 0 && $childPid > 0) {
                // Si proc_close devolvio no-cero y el child sigue
                // registrado, lo encolamos para que tearDown lo mate.
                $this->childPids[] = $childPid;
            }
            @unlink($holderPathPhp);
            @unlink($readyFile);

            // Si llegamos al try/finally y el test fallo, queremos
            // ver el stderr del hijo en el mensaje de error.
            if ($exit !== 0) {
                $logContents = is_file($holderLog) ? (string) file_get_contents($holderLog) : '(no log file)';
                @unlink($holderLog);
                $this->fail(sprintf(
                    "child PHP exit code=%d.\nstdout=%s\nstderr=%s\nholderLog=%s",
                    $exit,
                    trim($childStdout),
                    trim($childStderr),
                    trim($logContents)
                ));
            }
            @unlink($holderLog);
        }
    }
}
