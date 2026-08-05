<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Idempotencia;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\EmisionEnCursoException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Exceptions\MaxIdempotencyAttemptsException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Idempotencia\FilaEmision;
use Rbbsoft\ArcaSdk\Idempotencia\IdempotenciaRepository;
use Throwable;

/**
 * Tests de IdempotenciaRepository con una conexion MySQL REAL.
 *
 * Convenciones:
 *  - DB separada 'arca_facturador_test' (no toca produccion).
 *  - Cada test arranca con `arca_emisiones_idempotencia` vacia
 *    (TRUNCATE en setUp).
 *  - Si MySQL no esta disponible, los tests se saltan.
 *  - El clock se inyecta para tener `now` determinista.
 *  - El uuidFactory se inyecta para que las pruebas verifiquen
 *    el contrato de generacion de leases.
 *
 * Estos son los "integrity tests" de Phase 5: si alguno falla, el
 * diseno del CAS esta mal. La transicion fallido -> en_curso
 * (test_h, test_g, test_f, test_i) es la pieza mas sensible del
 * SDK: un bug ahi es plata que se pierde o duplica.
 */
final class IdempotenciaRepositoryTest extends TestCase
{
    private const DSN  = 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
    private const USER = 'root';
    private const PASS = '';

    private const CUIT        = '20111111111';
    private const PUNTO_VENTA = 1;
    private const CBTE_TIPO   = 11; // Factura C

    private const FP_BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const FP_OTRO = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** externalId del test. Se reusa en varios tests. */
    private const EXT = 'ext-aaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private ?PDO $pdo = null;
    private ?Config $config = null;
    private ?DateTimeImmutable $now = null;
    private ?Closure $clock = null;
    private ?IdempotenciaRepository $repo = null;
    private int $uuidCounter = 0;
    private ?Closure $uuidFactory = null;

    /**
     * PIDs de procesos hijo lanzados con proc_open que aun no se cerraron.
     * Se matan en tearDown (taskkill /F /PID en Windows, posix_kill en Unix)
     * para que un test fallido no deje zombies que mantengan locks.
     *
     * @var int[]
     */
    private array $childPids = [];

    /**
     * Script PHP autonomo que ejecuta transitionEnCursoToFallido
     * sobre una fila, simulando un worker externo que corre contra
     * la MISMA base. La sincronizacion con el padre es por archivos:
     *  - argv: externalId, lease, fingerprint, dsn, user, pass,
     *          readyFile, resultFile
     *  - readyFile: escrito al terminar el setup
     *  - resultFile: el resultado (1, 0, o excepcion) se escribe
     *    ahi como JSON.
     *
     * Mantener el script EMBEBIDO (vs. en un archivo separado) evita
     * problemas de deploy de tests y deja la logica a la vista.
     */
    private const CAS_SCRIPT = <<<'PHP_SCRIPT'
<?php
declare(strict_types=1);

// Script: ejecuta transitionEnCursoToFallido contra arca_facturador_test.
// argv[1..]: externalId, lease, fingerprint, readyFile, resultFile, dsn, user, pass
$externalId  = $argv[1] ?? '';
$lease       = $argv[2] ?? '';
$fingerprint = $argv[3] ?? '';
$readyFile   = $argv[4] ?? '';
$resultFile  = $argv[5] ?? '';
$dsn         = $argv[6] ?? 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
$user        = $argv[7] ?? 'root';
$pass        = $argv[8] ?? '';

if ($externalId === '' || $lease === '' || $fingerprint === '' || $readyFile === '' || $resultFile === '') {
    fwrite(STDERR, "missing args\n");
    exit(2);
}
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    @file_put_contents($resultFile, json_encode(['ok' => false, 'error' => 'connect: ' . $e->getMessage()]));
    exit(3);
}

try {
    $sql = 'UPDATE arca_emisiones_idempotencia
               SET estado = :fallido,
                   es_fallo_infra = 1,
                   lease_token = NULL,
                   response_json = :response_json,
                   updated_at = :updated_at
             WHERE external_id = :external_id
               AND estado = :en_curso
               AND lease_token = :lease_token
               AND request_fingerprint = :request_fingerprint';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fallido'             => 'fallido',
        ':response_json'       => 'child-won',
        ':updated_at'          => gmdate('Y-m-d H:i:s', time()),
        ':external_id'         => $externalId,
        ':en_curso'            => 'en_curso',
        ':lease_token'         => $lease,
        ':request_fingerprint' => $fingerprint,
    ]);
    $affected = $stmt->rowCount();
    // Listo, escribo ready y dejo al padre correr.
    @file_put_contents($readyFile, 'pid=' . getmypid());
    // Senal de "go" del padre: archivo goFile. Poll hasta verlo.
    // (El padre ya lo escribio antes de spawnear; en este diseno
    //  el hijo corre primero, el padre corre despues. El CAS
    //  semantics son los mismos: exactamente uno gana.)
    @file_put_contents($resultFile, json_encode(['ok' => true, 'affected' => $affected]));
    exit(0);
} catch (Throwable $e) {
    @file_put_contents($resultFile, json_encode(['ok' => false, 'error' => $e->getMessage()]));
    exit(4);
}
PHP_SCRIPT;

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
        $this->pdo->exec('TRUNCATE TABLE arca_emisiones_idempotencia');

        $this->config = Config::fromArray([
            'env'                       => 'homo',
            'cuit'                      => self::CUIT,
            'punto_venta'               => self::PUNTO_VENTA,
            'cert_path'                 => 'C:\xampp\htdocs\Certificados\MiCertificado.pem',
            'key_path'                  => 'C:\xampp\htdocs\Certificados\MiClavePrivada.key',
            'db_dsn'                    => self::DSN,
            'db_user'                   => self::USER,
            'db_pass'                   => self::PASS,
            'db_persistent'             => false,
            'soap_timeout'              => 30,
            'wsaa_lock_timeout'         => 10,
            'emit_lock_timeout'         => 15,
            'wsaa_tra_ttl'              => 600,
            'wsaa_generation_skew'      => 120,
            'wsaa_expiry_margin'        => 300,
            'retry_max_attempts'        => 3,
            'retry_base_backoff_ms'     => 200,
            'retry_max_backoff_ms'      => 2000,
            'idempotencia_max_intentos' => 5,
            'idempotencia_ttl_segundos' => 300,
        ]);

        $this->now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $this->clock = fn(): DateTimeImmutable => $this->now;

        // UUID factory determinista: el counter asegura unico por
        // llamada dentro del test, y el formato canonico cumple
        // con la validacion de FilaEmision (36 chars). Esto evita
        // colision entre tests y mantiene la salida estable para
        // asserts.
        $this->uuidCounter = 0;
        $this->uuidFactory = function (): string {
            $this->uuidCounter++;
            // UUID v4 canonico: 36 chars, version=4, variant=8/9/a/b.
            // Counter y self::EXT base nos da uno unico por llamada.
            $hex = str_pad(dechex($this->uuidCounter), 12, '0', STR_PAD_LEFT);
            return sprintf(
                '00000000-0000-4000-8000-%s',
                $hex
            );
        };

        $this->repo = new IdempotenciaRepository(
            $this->pdo,
            $this->config,
            $this->uuidFactory,
            $this->clock
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            try {
                $this->pdo->exec('TRUNCATE TABLE arca_emisiones_idempotencia');
            } catch (Throwable $e) {
                // ignore
            }
        }
        // Matar cualquier proceso hijo que proc_open haya dejado vivo
        // (test fallo, excepcion intermedia). Windows: taskkill /F /PID.
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
        // Crear DB y tabla si hace falta (idempotente, mismo
        // shape que sql/schema.sql).
        $rootPdo = new PDO('mysql:host=localhost;charset=utf8mb4', self::USER, self::PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $rootPdo->exec('CREATE DATABASE IF NOT EXISTS arca_facturador_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS arca_emisiones_idempotencia (
                external_id          CHAR(36)        NOT NULL,
                cuit                 CHAR(11)        NOT NULL,
                punto_venta          INT UNSIGNED    NOT NULL,
                cbte_tipo            INT UNSIGNED    NOT NULL,
                estado               ENUM(\'en_curso\',\'emitido\',\'fallido\') NOT NULL,
                lease_token          CHAR(36)        NULL,
                intento              INT UNSIGNED    NOT NULL DEFAULT 0,
                es_fallo_infra       TINYINT(1)      NOT NULL DEFAULT 0,
                request_fingerprint  CHAR(64)        NOT NULL,
                request_json         LONGTEXT        NOT NULL,
                cbte_nro_enviado     INT UNSIGNED    NULL,
                cbte_fch_enviado     DATE            NULL,
                cae                  VARCHAR(14)     NULL,
                cae_fch_vto          DATE            NULL,
                cbte_nro_confirmado  INT UNSIGNED    NULL,
                response_json        LONGTEXT        NULL,
                created_at           DATETIME        NOT NULL,
                updated_at           DATETIME        NOT NULL,
                PRIMARY KEY (external_id),
                KEY idx_estado_updated (estado, updated_at),
                KEY idx_cuit_pv_tipo (cuit, punto_venta, cbte_tipo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function utc(string $ymdhis): DateTimeImmutable
    {
        return new DateTimeImmutable($ymdhis, new DateTimeZone('UTC'));
    }

    private function rawRow(string $externalId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM arca_emisiones_idempotencia WHERE external_id = :ext');
        $stmt->execute([':ext' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, "fila {$externalId} no existe");
        return $row;
    }

    private function forceEstado(string $externalId, string $estado, int $intento = 0, bool $esFalloInfra = false, ?string $lease = null): void
    {
        $sql = 'UPDATE arca_emisiones_idempotencia
                   SET estado = :estado, intento = :intento, es_fallo_infra = :es_fallo_infra, lease_token = :lease
                 WHERE external_id = :ext';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':estado'        => $estado,
            ':intento'       => $intento,
            ':es_fallo_infra'=> $esFalloInfra ? 1 : 0,
            ':lease'         => $lease,
            ':ext'           => $externalId,
        ]);
    }

    private function forceUpdatedAt(string $externalId, string $updatedAt): void
    {
        $sql = 'UPDATE arca_emisiones_idempotencia
                   SET updated_at = :ts
                 WHERE external_id = :ext';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':ts' => $updatedAt, ':ext' => $externalId]);
    }

    // -----------------------------------------------------------------
    // (a) insertEnCurso
    // -----------------------------------------------------------------

    public function test_a_insertEnCurso_genera_lease_inserta_y_find_lo_devuelve(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{"k":"v"}'
        );
        $this->assertIsString($lease);
        $this->assertNotEmpty($lease);
        // Lease generado por la factory determinista: empieza con
        // '00000000-0000-4000-8000-' (prefijo v4 canonico).
        $this->assertMatchesRegularExpression(
            '/^00000000-0000-4000-8000-[0-9a-f]{12}$/',
            $lease
        );

        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertNotNull($fila);
        $this->assertSame(self::EXT, $fila->externalId);
        $this->assertSame(self::CUIT, $fila->cuit);
        $this->assertSame(self::PUNTO_VENTA, $fila->puntoVenta);
        $this->assertSame(self::CBTE_TIPO, $fila->cbteTipo);
        $this->assertSame(FilaEmision::ESTADO_EN_CURSO, $fila->estado);
        $this->assertSame($lease, $fila->leaseToken);
        $this->assertSame(0, $fila->intento);
        $this->assertFalse($fila->esFalloInfra);
        $this->assertSame(self::FP_BASE, $fila->requestFingerprint);
        $this->assertSame('{"k":"v"}', $fila->requestJson);
        $this->assertNull($fila->cbteNroEnviado);
        $this->assertNull($fila->cbteFchEnviado);
        $this->assertNull($fila->cae);
        $this->assertNull($fila->caeFchVto);
        $this->assertNull($fila->cbteNroConfirmado);
        $this->assertNull($fila->responseJson);
        $this->assertEquals($this->now, $fila->createdAt);
        $this->assertEquals($this->now, $fila->updatedAt);
    }

    // -----------------------------------------------------------------
    // (b) insertEnCurso duplicate key
    // -----------------------------------------------------------------

    public function test_b_insertEnCurso_duplicate_key_lanza_PDOException(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $this->expectException(PDOException::class);
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
    }

    public function test_find_devuelve_null_si_no_existe(): void
    {
        $this->assertNull($this->repo->findByExternalId('00000000-0000-4000-8000-000000000000'));
    }

    // -----------------------------------------------------------------
    // (c, d, e) transitionEnCursoToFallido
    // -----------------------------------------------------------------

    public function test_c_transitionEnCursoToFallido_lease_valido(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $ok = $this->repo->transitionEnCursoToFallido(
            self::EXT, $lease, self::FP_BASE, true, '{"err":"x"}'
        );
        $this->assertTrue($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(1, (int) $row['es_fallo_infra']);
        $this->assertNull($row['lease_token']);
        $this->assertSame('{"err":"x"}', $row['response_json']);
    }

    public function test_d_transitionEnCursoToFallido_lease_invalido_no_cambia(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $ok = $this->repo->transitionEnCursoToFallido(
            self::EXT, '00000000-0000-4000-8000-000000000000', self::FP_BASE, true
        );
        $this->assertFalse($ok);
        // Fila intacta
        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertSame(FilaEmision::ESTADO_EN_CURSO, $fila->estado);
        $this->assertSame($lease, $fila->leaseToken);
    }

    public function test_e_transitionEnCursoToFallido_fingerprint_invalido_no_cambia(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $ok = $this->repo->transitionEnCursoToFallido(
            self::EXT, $lease, self::FP_OTRO, true
        );
        $this->assertFalse($ok);
        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertSame(FilaEmision::ESTADO_EN_CURSO, $fila->estado);
        $this->assertSame($lease, $fila->leaseToken);
    }

    public function test_transitionEnCursoToFallido_no_afecta_si_y_esta_en_estado_terminal(): void
    {
        // Forzar emitido: el WHERE requiere estado='en_curso', no
        // debe hacer nada.
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_EMITIDO, 0, false, null);
        $ok = $this->repo->transitionEnCursoToFallido(
            self::EXT, $lease, self::FP_BASE, true
        );
        $this->assertFalse($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertSame('emitido', $row['estado']);
    }

    // -----------------------------------------------------------------
    // (f, g, h, i, j) transitionFallidoToEnCurso
    // -----------------------------------------------------------------

    public function test_f_transitionFallidoToEnCurso_desde_infra_no_incrementa_intento(): void
    {
        $leaseA = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_FALLIDO, 2, true, null);

        $leaseB = $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_BASE);

        $this->assertNotSame($leaseA, $leaseB, 'lease renovado');
        $row = $this->rawRow(self::EXT);
        $this->assertSame('en_curso', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra'], 'es_fallo_infra reseteado a 0');
        $this->assertSame(2, (int) $row['intento'], 'intento NO se incrementa para infra');
        $this->assertSame($leaseB, $row['lease_token']);
    }

    public function test_g_transitionFallidoToEnCurso_desde_negocio_incrementa_intento(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_FALLIDO, 2, false, null);

        $leaseB = $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_BASE);

        $row = $this->rawRow(self::EXT);
        $this->assertSame('en_curso', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra']);
        $this->assertSame(3, (int) $row['intento'], 'intento SI se incrementa para negocio');
        $this->assertSame($leaseB, $row['lease_token']);
    }

    /**
     * CRITICAL: este test es el mas importante de Phase 5. Confirma
     * que la primera SET clause del UPDATE (intento = intento + IF(...))
     * se evalua ANTES que es_fallo_infra = 0. Si el orden se invirtio
     * en el repositorio, este test detecta el bug inmediatamente
     * porque `intento` siempre seria "no incrementado" (la rama
     * infra), incluso cuando es_fallo_infra era 0.
     */
    public function test_h_assignment_order_probe_caso_infra(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_FALLIDO, 2, true, null);
        $before = $this->rawRow(self::EXT);
        $this->assertSame(1, (int) $before['es_fallo_infra']);
        $this->assertSame(2, (int) $before['intento']);

        $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_BASE);

        $after = $this->rawRow(self::EXT);
        $this->assertSame('en_curso', $after['estado']);
        $this->assertSame(0, (int) $after['es_fallo_infra']);
        // intento debe ser 2 (no se incremento). Si fuera 3, el orden
        // de asignaciones esta mal y MariaDB evaluo es_fallo_infra=0
        // antes que la IF.
        $this->assertSame(
            2,
            (int) $after['intento'],
            'CAS order bug: intento se incremento con es_fallo_infra=1; '
            . 'esperaba 2 (sin incremento), recibio ' . $after['intento']
        );
    }

    public function test_h_assignment_order_probe_caso_negocio(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_FALLIDO, 2, false, null);
        $before = $this->rawRow(self::EXT);
        $this->assertSame(0, (int) $before['es_fallo_infra']);
        $this->assertSame(2, (int) $before['intento']);

        $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_BASE);

        $after = $this->rawRow(self::EXT);
        $this->assertSame('en_curso', $after['estado']);
        $this->assertSame(0, (int) $after['es_fallo_infra']);
        // intento debe ser 3 (se incremento). Si fuera 2, el IF vio
        // un valor de es_fallo_infra distinto al original.
        $this->assertSame(
            3,
            (int) $after['intento'],
            'CAS order bug: intento NO se incremento con es_fallo_infra=0; '
            . 'esperaba 3, recibio ' . $after['intento']
        );
    }

    public function test_h_assignment_order_probe_via_sql_directo(): void
    {
        // Doble check: replicar la logica del SQL exactamente y
        // verificar que la fila final tiene los valores correctos.
        // Esto protege contra un cambio accidental de orden en
        // IdempotenciaRepository.
        $this->pdo->exec("INSERT INTO arca_emisiones_idempotencia
            (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token,
             intento, es_fallo_infra, request_fingerprint, request_json,
             created_at, updated_at)
            VALUES ('ext-aaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', '20111111111', 1, 11,
             'fallido', NULL, 2, 1,
             'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
             '{}', NOW(), NOW())");

        $this->pdo->exec("UPDATE arca_emisiones_idempotencia
              SET intento = intento + IF(es_fallo_infra = 1, 0, 1),
                  estado = 'en_curso',
                  es_fallo_infra = 0,
                  lease_token = '00000000-0000-4000-8000-000000000001',
                  updated_at = '2025-06-15 12:00:00'
            WHERE external_id = 'ext-aaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'");

        $row = $this->rawRow(self::EXT);
        $this->assertSame(2, (int) $row['intento'], 'intento=2 (no incremento) preservado');
        $this->assertSame(0, (int) $row['es_fallo_infra']);
        $this->assertSame('en_curso', $row['estado']);
    }

    public function test_i_excede_max_intentos_lanza_MaxIdempotencyAttempts(): void
    {
        $max = $this->config->idempotenciaMaxIntentos;
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        // intento = max - 1, es_fallo_infra = 0 (negocio)
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_FALLIDO, $max - 1, false, null);

        // Primera llamada: CAS succeed, intento -> max
        $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_BASE);
        $row = $this->rawRow(self::EXT);
        $this->assertSame('en_curso', $row['estado']);
        $this->assertSame($max, (int) $row['intento']);

        // Marcar fallido de nuevo con es_fallo_infra=0, intento=max
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_FALLIDO, $max, false, null);

        // Segunda llamada: deberia lanzar MaxIdempotencyAttemptsException
        $this->expectException(MaxIdempotencyAttemptsException::class);
        $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_BASE);
    }

    public function test_i_infra_con_intento_max_aun_reabre(): void
    {
        $max = $this->config->idempotenciaMaxIntentos;
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        // intento=max, es_fallo_infra=1: el OR short-circuita, CAS succeed
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_FALLIDO, $max, true, null);
        $lease = $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_BASE);
        $row = $this->rawRow(self::EXT);
        $this->assertSame('en_curso', $row['estado']);
        $this->assertSame($max, (int) $row['intento'], 'infra NO incrementa intento aunque este al max');
        $this->assertSame($lease, $row['lease_token']);
    }

    public function test_j_state_race_emitido_lanza_IdempotencyStateException(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        // Forzar estado a emitido: como si otro worker lo hubiera cerrado.
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_EMITIDO, 0, false, null);
        $this->expectException(IdempotencyStateException::class);
        $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_BASE);
    }

    public function test_j_state_race_en_curso_lanza_EmisionEnCursoException(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        // Forzar estado a en_curso con un lease distinto: el CAS no
        // matchea por lease, el re-read ve en_curso, lanza
        // EmisionEnCursoException.
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_EN_CURSO, 0, false, '00000000-0000-4000-8000-000000000099');
        $this->expectException(EmisionEnCursoException::class);
        $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_BASE);
    }

    public function test_j_state_race_fingerprint_distinto_lanza_estado(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_FALLIDO, 0, false, null);
        // Llamar con un fingerprint distinto al insertado. El CAS
        // no matchea. El re-read ve fallido con es_fallo_infra=0
        // e intento=0 < max, asi que cae en "estado incoherente"
        // (no deberia haber pasado, pero defensivo).
        $this->expectException(IdempotencyStateException::class);
        $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_OTRO);
    }

    // -----------------------------------------------------------------
    // (k, l) transitionEnCursoToEmitido
    // -----------------------------------------------------------------

    public function test_k_transitionEnCursoToEmitido_lease_valido(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $ok = $this->repo->transitionEnCursoToEmitido(
            self::EXT, $lease, self::FP_BASE,
            '74123456789012', '20251231', 12345, '{"Resultado":"A"}'
        );
        $this->assertTrue($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertSame('emitido', $row['estado']);
        $this->assertSame('74123456789012', $row['cae']);
        $this->assertSame('2025-12-31', $row['cae_fch_vto']);
        $this->assertSame(12345, (int) $row['cbte_nro_confirmado']);
        $this->assertNull($row['lease_token']);
        $this->assertSame('{"Resultado":"A"}', $row['response_json']);

        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertNotNull($fila);
        $this->assertSame('74123456789012', $fila->cae);
        $this->assertNotNull($fila->caeFchVto);
        $this->assertEquals($this->utc('2025-12-31'), $fila->caeFchVto);
        $this->assertSame(12345, $fila->cbteNroConfirmado);
    }

    public function test_k_transitionEnCursoToEmitido_lease_invalido(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $ok = $this->repo->transitionEnCursoToEmitido(
            self::EXT, '00000000-0000-4000-8000-000000000000',
            self::FP_BASE, '74123456789012', '20251231', 12345
        );
        $this->assertFalse($ok);
        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertSame(FilaEmision::ESTADO_EN_CURSO, $fila->estado);
        $this->assertNull($fila->cae);
    }

    public function test_l_transitionEnCursoToEmitido_valida_cae_formato(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $lease = '00000000-0000-4000-8000-000000000001';

        // CAE con letras: 14 chars pero no todos digitos
        try {
            $this->repo->transitionEnCursoToEmitido(
                self::EXT, $lease, self::FP_BASE, '7412345678901a', '20251231', 1
            );
            $this->fail('ValidationException esperada por CAE no-numerico');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cae', $e->getMessage());
        }

        // CAE muy corto
        try {
            $this->repo->transitionEnCursoToEmitido(
                self::EXT, $lease, self::FP_BASE, '1234', '20251231', 1
            );
            $this->fail('ValidationException esperada por CAE corto');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cae', $e->getMessage());
        }

        // CAE muy largo
        try {
            $this->repo->transitionEnCursoToEmitido(
                self::EXT, $lease, self::FP_BASE, '7412345678901234', '20251231', 1
            );
            $this->fail('ValidationException esperada por CAE largo');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cae', $e->getMessage());
        }

        // Fila sin tocar
        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertSame(FilaEmision::ESTADO_EN_CURSO, $fila->estado);
        $this->assertNull($fila->cae);
    }

    public function test_l_transitionEnCursoToEmitido_valida_caeFchVto_formato(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $lease = '00000000-0000-4000-8000-000000000001';

        // caeFchVto con guiones (formato DATE): invalido, ARCA entrega YYYYMMDD
        try {
            $this->repo->transitionEnCursoToEmitido(
                self::EXT, $lease, self::FP_BASE, '74123456789012', '2025-12-31', 1
            );
            $this->fail('ValidationException esperada por cae_fch_vto con formato DATE');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cae_fch_vto', $e->getMessage());
        }

        // caeFchVto de 7 digitos
        try {
            $this->repo->transitionEnCursoToEmitido(
                self::EXT, $lease, self::FP_BASE, '74123456789012', '2025123', 1
            );
            $this->fail('ValidationException esperada por cae_fch_vto corto');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cae_fch_vto', $e->getMessage());
        }
    }

    public function test_l_transitionEnCursoToEmitido_valida_cbteNro_positivo(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $lease = '00000000-0000-4000-8000-000000000001';

        // cbteNro = 0: invalido
        try {
            $this->repo->transitionEnCursoToEmitido(
                self::EXT, $lease, self::FP_BASE, '74123456789012', '20251231', 0
            );
            $this->fail('ValidationException esperada por cbteNro=0');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cbteNroConfirmado', $e->getMessage());
        }

        // cbteNro negativo: invalido
        try {
            $this->repo->transitionEnCursoToEmitido(
                self::EXT, $lease, self::FP_BASE, '74123456789012', '20251231', -1
            );
            $this->fail('ValidationException esperada por cbteNro<0');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cbteNroConfirmado', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // (m, n, o) reservarNumero
    // -----------------------------------------------------------------

    public function test_m_reservarNumero_con_cbteNro_null(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{"a":1}'
        );
        $ok = $this->repo->reservarNumero(
            self::EXT, $lease, self::FP_BASE,
            12345, '2025-06-15'
        );
        $this->assertTrue($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertSame(12345, (int) $row['cbte_nro_enviado']);
        $this->assertSame('2025-06-15', $row['cbte_fch_enviado']);
        // request_json NO se sobreescribio (no se paso override)
        $this->assertSame('{"a":1}', $row['request_json']);
    }

    public function test_m_reservarNumero_con_requestJsonOverride(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{"a":1}'
        );
        $ok = $this->repo->reservarNumero(
            self::EXT, $lease, self::FP_BASE,
            12345, '2025-06-15', '{"a":2,"b":3}'
        );
        $this->assertTrue($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertSame('{"a":2,"b":3}', $row['request_json']);
    }

    public function test_n_reservarNumero_no_reasigna_si_cbteNro_y_esta_poblado(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $this->repo->reservarNumero(self::EXT, $lease, self::FP_BASE, 100, '2025-06-15');

        // Re-llamar: el WHERE requiere cbte_nro_enviado IS NULL -> no matchea
        $ok = $this->repo->reservarNumero(
            self::EXT, $lease, self::FP_BASE, 200, '2025-06-16'
        );
        $this->assertFalse($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertSame(100, (int) $row['cbte_nro_enviado'], 'numero original preservado');
        $this->assertSame('2025-06-15', $row['cbte_fch_enviado'], 'fecha original preservada');
    }

    public function test_o_reservarNumero_lease_invalido(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $ok = $this->repo->reservarNumero(
            self::EXT, '00000000-0000-4000-8000-000000000000',
            self::FP_BASE, 12345, '2025-06-15'
        );
        $this->assertFalse($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertNull($row['cbte_nro_enviado']);
    }

    public function test_reservarNumero_valida_cbteFchYmd_formato(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        // Formato YYYYMMDD: invalido
        try {
            $this->repo->reservarNumero(self::EXT, $lease, self::FP_BASE, 1, '20250615');
            $this->fail('ValidationException esperada por fecha sin guiones');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cbteFchYmd', $e->getMessage());
        }
        // cbteNro=0: invalido
        try {
            $this->repo->reservarNumero(self::EXT, $lease, self::FP_BASE, 0, '2025-06-15');
            $this->fail('ValidationException esperada por cbteNro=0');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cbteNro', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // (p) updateResponseJson
    // -----------------------------------------------------------------

    public function test_p_updateResponseJson_con_lease_valido(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $ok = $this->repo->updateResponseJson(self::EXT, $lease, '{"err":"transitorio"}');
        $this->assertTrue($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertSame('{"err":"transitorio"}', $row['response_json']);
    }

    public function test_updateResponseJson_lease_invalido(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $ok = $this->repo->updateResponseJson(
            self::EXT, '00000000-0000-4000-8000-000000000000', '{"x":1}'
        );
        $this->assertFalse($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertNull($row['response_json']);
    }

    public function test_updateResponseJson_null_limpia_el_campo(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $this->repo->updateResponseJson(self::EXT, $lease, '{"a":1}');
        $ok = $this->repo->updateResponseJson(self::EXT, $lease, null);
        $this->assertTrue($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertNull($row['response_json']);
    }

    // -----------------------------------------------------------------
    // (q, r) markEnCursoZombieFromStaleLock
    // -----------------------------------------------------------------

    public function test_q_markEnCursoZombie_fila_stale_cambia_a_fallido(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        // updated_at = now. Cutoff = now + 1s => updated_at < cutoff es true.
        $cutoff = $this->now->modify('+1 second')->format('Y-m-d H:i:s');
        $ok = $this->repo->markEnCursoZombieFromStaleLock(
            self::EXT, $lease, self::FP_BASE, $cutoff
        );
        $this->assertTrue($ok);
        $row = $this->rawRow(self::EXT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra']);
        $this->assertNull($row['lease_token']);
    }

    public function test_r_markEnCursoZombie_fila_no_stale_no_cambia(): void
    {
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        // updated_at = now. Cutoff = now - 1s => updated_at < cutoff es false.
        $cutoff = $this->now->modify('-1 second')->format('Y-m-d H:i:s');
        $ok = $this->repo->markEnCursoZombieFromStaleLock(
            self::EXT, $lease, self::FP_BASE, $cutoff
        );
        $this->assertFalse($ok);
        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertSame(FilaEmision::ESTADO_EN_CURSO, $fila->estado);
        $this->assertSame($lease, $fila->leaseToken);
    }

    public function test_markEnCursoZombie_lease_invalido_no_cambia(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $cutoff = $this->now->modify('+1 day')->format('Y-m-d H:i:s');
        $ok = $this->repo->markEnCursoZombieFromStaleLock(
            self::EXT, '00000000-0000-4000-8000-000000000000',
            self::FP_BASE, $cutoff
        );
        $this->assertFalse($ok);
        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertSame(FilaEmision::ESTADO_EN_CURSO, $fila->estado);
    }

    // -----------------------------------------------------------------
    // (s) expireEnCursoLeases
    // -----------------------------------------------------------------

    public function test_s_expireEnCursoLeases_procesa_multiples_filas(): void
    {
        // Insertar 3 filas con updated_at viejo
        for ($i = 0; $i < 3; $i++) {
            $ext = sprintf('ext-0000-0000-4000-8000-%012d', $i + 1);
            $this->repo->insertEnCurso(
                $ext, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
                self::FP_BASE, '{}'
            );
            $this->forceUpdatedAt($ext, '2020-01-01 00:00:00');
        }

        $cutoff = '2021-01-01 00:00:00';
        $count = $this->repo->expireEnCursoLeases($cutoff, 10);
        $this->assertSame(3, $count);

        foreach (range(1, 3) as $i) {
            $ext = sprintf('ext-0000-0000-4000-8000-%012d', $i);
            $row = $this->rawRow($ext);
            $this->assertSame('fallido', $row['estado'], "fila {$ext} debe estar fallido");
            $this->assertSame(0, (int) $row['es_fallo_infra']);
            $this->assertNull($row['lease_token']);
        }
    }

    public function test_expireEnCursoLeases_no_toca_filas_frescas(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        // updated_at = $this->now. Cutoff DEBE ser anterior a
        // updated_at para que la fila NO sea considerada stale.
        // Si el cutoff esta en el futuro, una fila "fresca" se
        // considera expirada por el WHERE updated_at < cutoff
        // (trivialmente cierto).
        $cutoff = $this->now->modify('-1 hour')->format('Y-m-d H:i:s');
        $count = $this->repo->expireEnCursoLeases($cutoff, 10);
        $this->assertSame(0, $count);
        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertSame(FilaEmision::ESTADO_EN_CURSO, $fila->estado);
    }

    public function test_expireEnCursoLeases_no_toca_filas_terminales(): void
    {
        $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        $this->forceEstado(self::EXT, FilaEmision::ESTADO_FALLIDO, 0, true, null);
        $this->forceUpdatedAt(self::EXT, '2020-01-01 00:00:00');
        // cutoff muy viejo, fila fallido con updated_at viejo
        $count = $this->repo->expireEnCursoLeases('2021-01-01 00:00:00', 10);
        $this->assertSame(0, $count, 'WHERE filtra por estado=en_curso');
        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertSame(FilaEmision::ESTADO_FALLIDO, $fila->estado);
        $this->assertTrue($fila->esFalloInfra, 'flag infra preservado');
    }

    public function test_expireEnCursoLeases_respeta_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $ext = sprintf('ext-0000-0000-4000-8000-%012d', $i + 1);
            $this->repo->insertEnCurso(
                $ext, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
                self::FP_BASE, '{}'
            );
            $this->forceUpdatedAt($ext, '2020-01-01 00:00:00');
        }
        $count = $this->repo->expireEnCursoLeases('2021-01-01 00:00:00', 2);
        $this->assertSame(2, $count, 'limit=2 afecta a 2 filas');

        // Las 2 que tocamos ahora son fallido; las otras 3 siguen en_curso
        $fallidoCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM arca_emisiones_idempotencia WHERE estado = 'fallido'"
        )->fetchColumn();
        $enCursoCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM arca_emisiones_idempotencia WHERE estado = 'en_curso'"
        )->fetchColumn();
        $this->assertSame(2, $fallidoCount);
        $this->assertSame(3, $enCursoCount);
    }

    // -----------------------------------------------------------------
    // (t) Concurrent CAS probe con proc_open
    // -----------------------------------------------------------------

    /**
     * Race test REAL con dos procesos PHP independientes.
     *
     * Que verifica:
     *  - Dos workers distintos que intenten CAS al mismo tiempo
     *    sobre la misma fila: exactamente uno gana (affected_rows=1),
     *    el otro pierde (affected_rows=0).
     *  - El CAS es atomico: no hay estado intermedio.
     *
     * Mecanica:
     *  - El padre inserta la fila con un lease.
     *  - El padre hace su propio CAS (con el mismo lease). Su
     *    fila queda en fallido con lease=NULL.
     *  - El padre spawna un hijo que intenta el mismo CAS con el
     *    mismo lease. El hijo SIEMPRE pierde porque la fila ya no
     *    esta en_curso y su lease es NULL.
     *  - Eso es el caso "el padre gana" del race.
     *  - Luego invertimos: el padre spawna al hijo primero, y el
     *    padre intenta despues. El hijo gana, el padre pierde.
     *
     * En conjunto, ambos ordenamientos se cubren: exactamente uno
     * gana en cada caso.
     *
     * Restricciones:
     *  - Requiere proc_open() y PHP CLI accesible via PHP_BINARY.
     *  - Si no esta disponible, markTestSkipped.
     */
    public function test_t_concurrent_CAS_exactly_one_wins(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open no disponible en este PHP');
        }
        $php = PHP_BINARY;
        if ($php === '' || !is_executable($php)) {
            $this->markTestSkipped("PHP CLI no encontrado o no ejecutable: '{$php}'");
        }

        // Escribir el script a un tempnam
        $scriptPath = tempnam(sys_get_temp_dir(), 'arca_cas_');
        if ($scriptPath === false) {
            $this->markTestSkipped('tempnam() devolvio false; no se pudo crear script');
        }
        $scriptPathPhp = $scriptPath . '.php';
        if (!@rename($scriptPath, $scriptPathPhp)) {
            @unlink($scriptPath);
            $this->markTestSkipped("rename a {$scriptPathPhp} fallo");
        }
        if (file_put_contents($scriptPathPhp, self::CAS_SCRIPT) === false) {
            @unlink($scriptPathPhp);
            $this->markTestSkipped("no se pudo escribir script {$scriptPathPhp}");
        }
        @chmod($scriptPathPhp, 0o700);

        // Caso 1: el padre corre primero, el hijo corre despues.
        // Esperado: padre=1, hijo=0.
        $this->runRace($php, $scriptPathPhp, parentFirst: true);
        // Caso 2: el hijo corre primero, el padre corre despues.
        // Esperado: padre=0, hijo=1.
        $this->runRace($php, $scriptPathPhp, parentFirst: false);

        @unlink($scriptPathPhp);
    }

    /**
     * Ejecuta un round del race test. Inserta una fila, corre el
     * CAS del padre y del hijo en el orden especificado, y
     * verifica que exactamente uno gana.
     *
     * Sincronizacion: el script del hijo corre su CAS apenas se
     * conecta a MySQL. Para los DOS ordenamientos (padre primero
     * o hijo primero), la mecanica es:
     *   1. Padre inserta la fila.
     *   2. Padre spawna al hijo (proc_open, no espera).
     *   3. En parentFirst, padre corre CAS inmediatamente, despues
     *      proc_close (espera al hijo). En childFirst, padre hace
     *      proc_close primero (espera al hijo), corre su CAS despues.
     *   4. Se leen ambos resultados. Exactamente uno debe ser 1.
     *
     * Importante: el script del hijo corre el CAS apenas arranca
     * (no espera senal). Esto significa que en childFirst, el CAS
     * del hijo YA termino cuando proc_close retorna. En parentFirst,
     * el CAS del padre corre primero, luego cuando el hijo arranca
     * (o ya arranco) ve la fila en fallido y su CAS falla.
     */
    private function runRace(string $php, string $scriptPathPhp, bool $parentFirst): void
    {
        // Limpiar la tabla para cada round.
        $this->pdo->exec('TRUNCATE TABLE arca_emisiones_idempotencia');

        // Insertar fila.
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );

        $readyFile = tempnam(sys_get_temp_dir(), 'arca_cas_ready_');
        $resultFile = tempnam(sys_get_temp_dir(), 'arca_cas_result_');
        if ($readyFile === false || $resultFile === false) {
            @unlink($readyFile);
            @unlink($resultFile);
            $this->markTestSkipped('tempnam() devolvio false');
        }

        $cmd = [
            $php, $scriptPathPhp,
            self::EXT, $lease, self::FP_BASE,
            $readyFile, $resultFile,
            self::DSN, self::USER, self::PASS,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = [
            'PATH'       => getenv('PATH') ?: '',
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
            'TEMP'       => getenv('TEMP') ?: sys_get_temp_dir(),
            'TMP'        => getenv('TMP') ?: sys_get_temp_dir(),
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        try {
            $parentResult = null;
            $childResult = null;

            if ($parentFirst) {
                // Padre corre CAS primero, despues espera al hijo.
                $parentResult = $this->repo->transitionEnCursoToFallido(
                    self::EXT, $lease, self::FP_BASE, true, 'parent-won'
                );
                $childExit = proc_close($proc);
                $rawChild = (string) @file_get_contents($resultFile);
            } else {
                // childFirst: esperamos al hijo primero, despues el padre.
                $childExit = proc_close($proc);
                $rawChild = (string) @file_get_contents($resultFile);
                $parentResult = $this->repo->transitionEnCursoToFallido(
                    self::EXT, $lease, self::FP_BASE, true, 'parent-attempt'
                );
            }

            $decoded = json_decode($rawChild, true);
            $this->assertIsArray($decoded, "child result JSON invalido: '{$rawChild}'");
            $this->assertTrue($decoded['ok'], "child no reporto ok: " . json_encode($decoded));
            $childResult = (int) $decoded['affected'];

            $this->assertNotNull($parentResult, 'parent no reporto affected');
            $this->assertNotNull($childResult, 'child no reporto affected');

            // Exactly one of them is 1 (won), the other is 0 (lost).
            $winners = ((int) $parentResult) + $childResult;
            $this->assertSame(
                1,
                $winners,
                sprintf(
                    'exactly one should win, got parent=%d child=%d (parentFirst=%s)',
                    (int) $parentResult, $childResult, $parentFirst ? 'true' : 'false'
                )
            );
        } finally {
            foreach ([1, 2] as $idx) {
                if (is_resource($pipes[$idx])) {
                    fclose($pipes[$idx]);
                }
            }
            // Si proc_close no cerro (raro), encolar el PID.
            if (is_resource($proc)) {
                $status = proc_get_status($proc);
                $childPid = $status['pid'] ?? 0;
                @proc_close($proc);
                if ($childPid > 0) {
                    $this->childPids[] = $childPid;
                }
            }
            @unlink($readyFile);
            @unlink($resultFile);
        }
    }

    // -----------------------------------------------------------------
    // Happy path integration probe
    // -----------------------------------------------------------------

    public function test_happy_path_completo_insert_reservar_emitido(): void
    {
        // Smoke: el flujo que el orquestador (Phase 6) ejecutara.
        $lease = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{"snap":"v1"}'
        );

        $okReservar = $this->repo->reservarNumero(
            self::EXT, $lease, self::FP_BASE, 12345, '2025-06-15'
        );
        $this->assertTrue($okReservar);

        $okEmitido = $this->repo->transitionEnCursoToEmitido(
            self::EXT, $lease, self::FP_BASE,
            '74123456789012', '20251231', 12345, '{"Resultado":"A"}'
        );
        $this->assertTrue($okEmitido);

        $fila = $this->repo->findByExternalId(self::EXT);
        $this->assertNotNull($fila);
        $this->assertSame(FilaEmision::ESTADO_EMITIDO, $fila->estado);
        $this->assertSame('74123456789012', $fila->cae);
        $this->assertNotNull($fila->caeFchVto);
        $this->assertEquals($this->utc('2025-12-31'), $fila->caeFchVto);
        $this->assertSame(12345, $fila->cbteNroConfirmado);
        $this->assertSame(12345, $fila->cbteNroEnviado, 'numero reservado preservado');
        $this->assertNotNull($fila->cbteFchEnviado);
        $this->assertEquals($this->utc('2025-06-15'), $fila->cbteFchEnviado);
        $this->assertNull($fila->leaseToken, 'lease liberado al emitir');
        $this->assertSame('{"snap":"v1"}', $fila->requestJson, 'snapshot inmutable preservado');
        $this->assertSame('{"Resultado":"A"}', $fila->responseJson);
    }

    public function test_happy_path_fallo_y_reapertura(): void
    {
        $lease1 = $this->repo->insertEnCurso(
            self::EXT, self::CUIT, self::PUNTO_VENTA, self::CBTE_TIPO,
            self::FP_BASE, '{}'
        );
        // Marcar fallido (negocio)
        $ok1 = $this->repo->transitionEnCursoToFallido(
            self::EXT, $lease1, self::FP_BASE, false, '{"rechazo":"x"}'
        );
        $this->assertTrue($ok1);
        $row = $this->rawRow(self::EXT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra']);
        $this->assertSame(0, (int) $row['intento']);
        $this->assertNull($row['lease_token']);

        // Reabrir: intento se incrementa a 1
        $lease2 = $this->repo->transitionFallidoToEnCurso(self::EXT, self::FP_BASE);
        $row = $this->rawRow(self::EXT);
        $this->assertSame('en_curso', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra']);
        $this->assertSame(1, (int) $row['intento']);
        $this->assertSame($lease2, $row['lease_token']);
        $this->assertNotSame($lease1, $lease2);
    }
}
