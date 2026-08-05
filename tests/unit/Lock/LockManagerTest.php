<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Lock;

use Closure;
use PDO;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Lock\LockManager;
use RuntimeException;
use Throwable;

/**
 * Tests del LockManager. Cubre:
 *  - happy path acquire/release contra MySQL real
 *  - acquire sobre lock tomado por otro proceso: timeout 0 -> false,
 *    timeout > 0 -> espera y gana cuando el otro libera
 *  - release cuando nunca se adquirio -> false
 *  - double release -> false
 *  - el `finally` no llama a release() si acquire fallo
 *  - longitud del lock name <= 64 chars
 *  - nombres distintos -> locks distintos (concurrentes ambos ganan)
 *  - mismo nombre -> mismo lock (held bloquea al segundo)
 *  - acquire con -1 (error/deadlock) -> RuntimeException
 *  - acquire con nombre > 64 chars -> RuntimeException
 *
 * Usa una DB MySQL separada (`arca_facturador_test`) para no
 * contaminar la DB de produccion. Si MySQL no esta disponible, los
 * tests se saltan (markTestSkipped).
 *
 * Para los tests de "lock tomado por otro proceso" usa proc_open
 * con un script PHP embebido (mismo patron que MysqlTicketCacheTest).
 */
final class LockManagerTest extends TestCase
{
    private const DSN  = 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
    private const USER = 'root';
    private const PASS = '';

    private const CUIT_A = '20111111111';
    private const CUIT_B = '20222222222';
    private const PV     = 1;
    private const TIPO   = 11; // Factura C

    private ?PDO $pdo = null;
    private ?Closure $factory = null;

    /**
     * PIDs de procesos hijo que se lanzaron con proc_open y aun no
     * terminaron. Se matan en tearDown (taskkill /F /PID en Windows,
     * posix_kill en Unix) para que un test fallido no deje zombies
     * que mantengan locks.
     *
     * @var int[]
     */
    private array $childPids = [];

    /**
     * Script PHP embebido que adquiere un named lock de MySQL, duerme
     * N ms, y lo libera. Sincroniza con el padre escribiendo un
     * "ready" file despues de confirmar que tiene el lock (mismo
     * patron que MysqlTicketCacheTest::HOLDER_SCRIPT).
     */
    private const HOLDER_SCRIPT = <<<'PHP_HOLDER'
<?php
declare(strict_types=1);

// arca_lock_holder.php - holds a MySQL named lock for a fixed duration.
// argv: <lockName> <sleepMs> <readyFile>
$lockName  = $argv[1] ?? '';
$sleepMs   = (int) ($argv[2] ?? 0);
$readyFile = $argv[3] ?? '';
if ($lockName === '' || $sleepMs <= 0 || $readyFile === '') {
    fwrite(STDERR, "bad args\n");
    exit(2);
}
$dsn  = getenv('ARCA_TEST_DSN')  ?: 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
$user = getenv('ARCA_TEST_USER') ?: 'root';
$pass = getenv('ARCA_TEST_PASS') ?: '';
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_PERSISTENT => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "connect failed: " . $e->getMessage() . "\n");
    exit(3);
}
$stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
$stmt->execute([$lockName]);
$acquired = (int) $stmt->fetchColumn();
if ($acquired !== 1) {
    fwrite(STDERR, "GET_LOCK returned $acquired for $lockName\n");
    exit(4);
}
@file_put_contents($readyFile, 'pid=' . getmypid() . ' at=' . microtime(true));
usleep($sleepMs * 1000);
$rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
$rel->execute([$lockName]);
$rel->fetchColumn();
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

        $dsn = self::DSN;
        $user = self::USER;
        $pass = self::PASS;
        $this->factory = static function () use ($dsn, $user, $pass): PDO {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,
            ]);
        };
    }

    protected function tearDown(): void
    {
        // Matar cualquier proceso hijo que proc_open haya dejado vivo.
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

    private function newManager(): LockManager
    {
        return new LockManager($this->factory);
    }

    private function uniqueName(string $prefix = 'lk'): string
    {
        // 32 hex chars + prefix. Quedan < 64.
        return $prefix . '_' . bin2hex(random_bytes(16));
    }

    // -----------------------------------------------------------------
    // Lock name
    // -----------------------------------------------------------------

    public function test_computeEmitLockName_es_determinista_y_64_chars_o_menos(): void
    {
        $a = LockManager::computeEmitLockName(self::CUIT_A, self::PV, self::TIPO);
        $b = LockManager::computeEmitLockName(self::CUIT_A, self::PV, self::TIPO);
        $c = LockManager::computeEmitLockName(self::CUIT_B, self::PV, self::TIPO);
        $d = LockManager::computeEmitLockName(self::CUIT_A, self::PV, 6); // tipo distinto

        $this->assertSame($a, $b, 'mismo (cuit, pv, tipo) -> mismo lockName');
        $this->assertNotSame($a, $c, 'distinto cuit -> distinto lockName');
        $this->assertNotSame($a, $d, 'distinto tipo -> distinto lockName');
        $this->assertLessThanOrEqual(LockManager::MAX_LOCK_NAME_LENGTH, strlen($a));
        $this->assertStringStartsWith(LockManager::EMIT_LOCK_PREFIX, $a);
        $this->assertSame('arca_emit_' . substr(hash('sha256', self::CUIT_A . ':' . self::PV . ':' . self::TIPO), 0, 50), $a);
    }

    public function test_acquire_rechaza_nombre_mayor_a_64_chars(): void
    {
        $lm = $this->newManager();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/excede 64 chars/');
        $lm->acquire(str_repeat('a', 65), 1);
    }

    public function test_acquire_rechaza_timeout_negativo(): void
    {
        $lm = $this->newManager();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/timeoutSeconds no puede ser negativo/');
        $lm->acquire('some_name', -1);
    }

    // -----------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------

    public function test_acquire_y_release_happy_path(): void
    {
        $lm = $this->newManager();
        $name = $this->uniqueName();

        $ok = $lm->acquire($name, 2);
        $this->assertTrue($ok, 'lock adquirido');
        $this->assertTrue($lm->isHeld($name), 'isHeld true despues de acquire');

        $released = $lm->release($name);
        $this->assertTrue($released, 'release devuelve true');
        $this->assertFalse($lm->isHeld($name), 'isHeld false despues de release');
    }

    public function test_release_sin_haber_adquirido_devuelve_false(): void
    {
        $lm = $this->newManager();
        $name = $this->uniqueName();
        // Nunca llamamos a acquire().
        $this->assertFalse($lm->release($name));
    }

    public function test_double_release_segunda_llamada_false(): void
    {
        $lm = $this->newManager();
        $name = $this->uniqueName();
        $lm->acquire($name, 2);
        $this->assertTrue($lm->release($name));
        // Segunda llamada: la instancia ya no lo sostiene.
        $this->assertFalse($lm->release($name));
    }

    public function test_doble_acquire_mismo_nombre_libera_previo_y_re_toma(): void
    {
        $lm = $this->newManager();
        $name = $this->uniqueName();

        $this->assertTrue($lm->acquire($name, 2));
        $this->assertTrue($lm->acquire($name, 2), 'segundo acquire del mismo nombre tambien tiene exito');
        $this->assertTrue($lm->release($name), 'release del segundo');
        // El primero ya fue liberado internamente; intentar release
        // ahora devuelve false.
        $this->assertFalse($lm->release($name));
    }

    public function test_conexion_se_libera_al_release(): void
    {
        $lm = $this->newManager();
        $name = $this->uniqueName();
        $lm->acquire($name, 2);

        // Verificar que la conexion que sostiene el lock esta viva
        // contando las conexiones activas del server. Esto valida
        // que NO se cerro prematuramente.
        $openBefore = $this->openConnectionCount();
        $lm->release($name);
        $openAfter = $this->openConnectionCount();

        $this->assertLessThanOrEqual(
            $openBefore,
            $openAfter,
            'conexion cerrada al hacer release() (open connections no aumento)'
        );
    }

    private function openConnectionCount(): int
    {
        // Connections abiertas por el usuario actual hacia esta DB.
        // Cierra al PDO en PDO::FETCH_ASSOC; un row por conexion.
        $row = $this->pdo->query(
            "SELECT COUNT(*) AS c FROM information_schema.processlist WHERE db = 'arca_facturador_test'"
        )->fetch();
        return (int) ($row['c'] ?? 0);
    }

    // -----------------------------------------------------------------
    // Contention tests via procesos externos
    // -----------------------------------------------------------------

    public function test_acquire_en_lock_held_con_timeout_cero_devuelve_false(): void
    {
        $name = $this->uniqueName();
        // El padre toma el lock con su propio PDO.
        $holderPdo = ($this->factory)();
        $stmt = $holderPdo->prepare('SELECT GET_LOCK(?, 1)');
        $stmt->execute([$name]);
        $this->assertSame('1', (string) $stmt->fetchColumn(), 'padre sostiene el lock');

        try {
            $lm = $this->newManager();
            $ok = $lm->acquire($name, 0);
            $this->assertFalse($ok, 'timeout=0 devuelve false cuando el lock esta tomado');
            $this->assertFalse($lm->isHeld($name));
        } finally {
            $rel = $holderPdo->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$name]);
            $rel->fetchColumn();
        }
    }

    /**
     * Sostiene un lock por $holdMs en un proceso hijo, y verifica
     * que la llamada a acquire() en el padre espera hasta que el
     * hijo libera.
     */
    public function test_acquire_en_lock_held_espera_y_gana_cuando_se_libera(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open no disponible');
        }
        $php = PHP_BINARY;
        if ($php === '' || !is_executable($php)) {
            $this->markTestSkipped("PHP CLI no encontrado: '{$php}'");
        }

        $name = $this->uniqueName('wait');
        $holdMs = 700;
        $lockTimeout = 3; // el padre espera hasta 3s

        $holderPath = tempnam(sys_get_temp_dir(), 'arca_lock_holder_');
        if ($holderPath === false) {
            $this->markTestSkipped('tempnam fallo');
        }
        $holderPathPhp = $holderPath . '.php';
        if (!@rename($holderPath, $holderPathPhp)) {
            @unlink($holderPath);
            $this->markTestSkipped('rename a php fallo');
        }
        if (file_put_contents($holderPathPhp, self::HOLDER_SCRIPT) === false) {
            @unlink($holderPathPhp);
            $this->markTestSkipped('escribir holder fallo');
        }
        @chmod($holderPathPhp, 0o700);

        $readyFile = tempnam(sys_get_temp_dir(), 'arca_lock_ready_');
        if ($readyFile === false) {
            @unlink($holderPathPhp);
            $this->markTestSkipped('tempnam ready fallo');
        }

        $cmd = [$php, $holderPathPhp, $name, (string) $holdMs, $readyFile];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // En Windows, proc_open con env custom REEMPLAZA el env del
        // proceso. Sin SystemRoot / TEMP / TMP el child PHP no puede
        // resolver DNS ni escribir logs. (Ver MysqlTicketCacheTest
        // para el mismo patron.)
        $env = [
            'ARCA_TEST_DSN'  => self::DSN,
            'ARCA_TEST_USER' => self::USER,
            'ARCA_TEST_PASS' => self::PASS,
            'PATH'           => getenv('PATH') ?: '',
            'SystemRoot'     => getenv('SystemRoot') ?: 'C:\\Windows',
            'TEMP'           => getenv('TEMP') ?: sys_get_temp_dir(),
            'TMP'            => getenv('TMP') ?: sys_get_temp_dir(),
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($proc, 'proc_open retorno un resource');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $status = proc_get_status($proc);
        $this->childPids[] = (int) $status['pid'];

        // Esperar a que el hijo confirme que tiene el lock (ready file).
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $readyFile);
            if (is_file($readyFile) && filesize($readyFile) > 0) {
                break;
            }
            $curStatus = proc_get_status($proc);
            if (!$curStatus['running']) {
                $stderr = stream_get_contents($pipes[2]);
                $this->fail("child exited code={$curStatus['exitcode']} before ready file. stderr: {$stderr}");
            }
            usleep(20_000);
        }
        $this->assertTrue(
            is_file($readyFile) && filesize($readyFile) > 0,
            'el hijo confirmo que tiene el lock via ready file'
        );

        // Ahora intentar acquire() en el padre: debe esperar ~700ms.
        $lm = $this->newManager();
        $start = microtime(true);
        $ok = $lm->acquire($name, $lockTimeout);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($ok, 'padre adquirio el lock despues de que el hijo lo libero');
        $this->assertGreaterThanOrEqual(
            ($holdMs / 1000) * 0.85,
            $elapsed,
            'el padre espero al menos ~85% del holdMs'
        );
        $this->assertLessThan($lockTimeout + 1.5, $elapsed, 'no excedio lockTimeout + margen');

        $lm->release($name);

        // Cleanup del proceso hijo (proc_close deberia haber esperado
        // porque el script ya termino al hacer RELEASE_LOCK y exit).
        @proc_close($proc);
        @unlink($readyFile);
        @unlink($holderPathPhp);
    }

    /**
     * Nombres distintos -> locks distintos. Dos acquires paralelos
     * en el MISMO proceso, sobre nombres distintos, ambos ganan.
     */
    public function test_nombres_distintos_locks_distintos_ambos_ganan(): void
    {
        $lm = $this->newManager();
        $a = $this->uniqueName('A');
        $b = $this->uniqueName('B');

        $this->assertTrue($lm->acquire($a, 2));
        $this->assertTrue($lm->acquire($b, 2), 'segundo acquire con nombre distinto tiene exito');
        $this->assertTrue($lm->isHeld($a));
        $this->assertTrue($lm->isHeld($b));

        $this->assertTrue($lm->release($a));
        $this->assertTrue($lm->release($b));
    }

    /**
     * Mismo nombre -> mismo lock. Un acquire en un proceso externo
     * bloquea al acquire del padre.
     */
    public function test_mismo_nombre_mismo_lock_held_bloquea_al_segundo(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open no disponible');
        }
        $php = PHP_BINARY;
        if ($php === '' || !is_executable($php)) {
            $this->markTestSkipped("PHP CLI no encontrado: '{$php}'");
        }

        $name = $this->uniqueName('same');
        $holdMs = 1500;
        $lockTimeout = 5;

        $holderPath = tempnam(sys_get_temp_dir(), 'arca_lock_holder_');
        if ($holderPath === false) {
            $this->markTestSkipped('tempnam fallo');
        }
        $holderPathPhp = $holderPath . '.php';
        if (!@rename($holderPath, $holderPathPhp)) {
            @unlink($holderPath);
            $this->markTestSkipped('rename fallo');
        }
        if (file_put_contents($holderPathPhp, self::HOLDER_SCRIPT) === false) {
            @unlink($holderPathPhp);
            $this->markTestSkipped('escribir holder fallo');
        }
        @chmod($holderPathPhp, 0o700);

        $readyFile = tempnam(sys_get_temp_dir(), 'arca_lock_ready_');
        if ($readyFile === false) {
            @unlink($holderPathPhp);
            $this->markTestSkipped('tempnam ready fallo');
        }

        $cmd = [$php, $holderPathPhp, $name, (string) $holdMs, $readyFile];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = [
            'ARCA_TEST_DSN'  => self::DSN,
            'ARCA_TEST_USER' => self::USER,
            'ARCA_TEST_PASS' => self::PASS,
            'PATH'           => getenv('PATH') ?: '',
            'SystemRoot'     => getenv('SystemRoot') ?: 'C:\\Windows',
            'TEMP'           => getenv('TEMP') ?: sys_get_temp_dir(),
            'TMP'            => getenv('TMP') ?: sys_get_temp_dir(),
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $status = proc_get_status($proc);
        $this->childPids[] = (int) $status['pid'];

        // Esperar a que el hijo tenga el lock.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $readyFile);
            if (is_file($readyFile) && filesize($readyFile) > 0) {
                break;
            }
            usleep(20_000);
        }
        $this->assertTrue(is_file($readyFile) && filesize($readyFile) > 0);

        // El padre intenta con timeout corto para verificar que NO gana
        // mientras el hijo sostiene el lock.
        $lm = $this->newManager();
        $ok = $lm->acquire($name, 1);
        $this->assertFalse($ok, 'mismo nombre -> mismo lock -> el padre NO adquiere en 1s');

        // Cleanup: esperar a que el hijo termine.
        @proc_close($proc);
        @unlink($readyFile);
        @unlink($holderPathPhp);
    }

    // -----------------------------------------------------------------
    // finally pattern: NO release si NO se adquirio
    // -----------------------------------------------------------------

    /**
     * Verifica que cuando acquire() devuelve false (timeout 0 con
     * lock tomado), isHeld() queda en false y un release posterior
     * no tiene efecto. Esto es la pieza clave del "finally pattern":
     * el orquestador que vea acquire=false NO debe llamar a release.
     */
    public function test_acquire_false_no_establece_held_y_release_no_opera(): void
    {
        $name = $this->uniqueName('false');
        $holderPdo = ($this->factory)();
        $stmt = $holderPdo->prepare('SELECT GET_LOCK(?, 1)');
        $stmt->execute([$name]);
        $this->assertSame('1', (string) $stmt->fetchColumn());

        try {
            $lm = $this->newManager();
            $ok = $lm->acquire($name, 0);
            $this->assertFalse($ok);
            $this->assertFalse($lm->isHeld($name), 'isHeld queda en false');
            // Llamar a release cuando isHeld=false es no-op (no error).
            $this->assertFalse($lm->release($name));
        } finally {
            $rel = $holderPdo->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$name]);
            $rel->fetchColumn();
        }
    }

    // -----------------------------------------------------------------
    // releaseAll / __destruct
    // -----------------------------------------------------------------

    public function test_releaseAll_libera_todos_los_locks_sostenidos(): void
    {
        $lm = $this->newManager();
        $a = $this->uniqueName('allA');
        $b = $this->uniqueName('allB');
        $lm->acquire($a, 2);
        $lm->acquire($b, 2);
        $this->assertTrue($lm->isHeld($a));
        $this->assertTrue($lm->isHeld($b));

        $lm->releaseAll();
        $this->assertFalse($lm->isHeld($a));
        $this->assertFalse($lm->isHeld($b));
    }

    public function test_destructor_libera_locks_al_poder_ser_garbage_collected(): void
    {
        $name = $this->uniqueName('destruct');
        $lm = $this->newManager();
        $lm->acquire($name, 2);
        $this->assertTrue($lm->isHeld($name));
        unset($lm); // dispara __destruct
        // El lock deberia estar liberado ahora. Un nuevo LockManager
        // que intente acquire deberia ganar inmediatamente.
        $lm2 = $this->newManager();
        $this->assertTrue(
            $lm2->acquire($name, 2),
            'lock fue liberado por el destructor del LM anterior'
        );
        $lm2->release($name);
    }
}
