<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\ArcaSdk;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Rbbsoft\ArcaSdk\ArcaSdk;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\EmisionEnCursoException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Sdk\Container;
use Rbbsoft\ArcaSdk\Idempotencia\FilaEmision;
use Rbbsoft\ArcaSdk\Time\Clock;

/**
 * Tests de `ArcaSdk::resetExternalId()` (Phase 8).
 *
 * Convenciones:
 *  - DB MySQL real (arca_facturador_test).
 *  - Cada test arranca con la tabla idempotente vacia (TRUNCATE).
 *  - Clock inyectable: las filas `en_curso` se insertan con
 *    `updated_at` antiguo o reciente segun el caso.
 *  - Logger in-memory que captura todos los entries.
 *  - Filas se insertan manualmente con SQL crudo para tener control
 *    exacto de `updated_at`, estado, lease, fingerprint.
 */
final class ResetExternalIdTest extends TestCase
{
    private const DSN  = 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
    private const USER = 'root';
    private const PASS = '';

    private const CUIT        = '20111111112';
    private const PUNTO_VENTA = 1;
    private const CBTE_TIPO   = 11; // Factura C
    private const TTL         = 300; // segundos

    private const EXT_FALLIDO  = 'eeee1111-1111-4111-8111-111111111111';
    private const EXT_EMITIDO  = 'eeee2222-2222-4222-8222-222222222222';
    private const EXT_EN_CURSO = 'eeee3333-3333-4333-8333-333333333333';
    private const EXT_NO_EXISTE = 'eeee9999-9999-4999-8999-999999999999';

    private ?PDO $pdo = null;
    private ?Config $config = null;
    private ?DateTimeImmutable $now = null;
    private ?Clock $clock = null;
    private ?Container $container = null;
    private ?CapturingLogger $logger = null;

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
        } catch (\Throwable $e) {
            $this->markTestSkipped('No se pudo conectar a MySQL de test: ' . $e->getMessage());
        }
        $this->ensureTable();
        $this->pdo->exec('TRUNCATE TABLE arca_emisiones_idempotencia');

        $this->config = $this->makeConfig();
        $this->now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $this->clock = new Clock(function (): DateTimeImmutable {
            return $this->now;
        });
        $this->logger = new CapturingLogger();

        $this->container = (new Container($this->config))
            ->withPdo($this->pdo)
            ->withClock($this->clock);

        ArcaSdk::resetInstance();
    }

    protected function tearDown(): void
    {
        ArcaSdk::resetInstance();
    }

    private function ensureTable(): void
    {
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

    private function makeConfig(): Config
    {
        return Config::fromArray([
            'cuit'                 => self::CUIT,
            'punto_venta'          => self::PUNTO_VENTA,
            'cert_path'            => 'C:\xampp\htdocs\Certificados\MiCertificado.pem',
            'key_path'             => 'C:\xampp\htdocs\Certificados\MiClavePrivada.key',
            'env'                  => 'homo',
            'db_dsn'               => self::DSN,
            'db_user'              => self::USER,
            'db_pass'              => self::PASS,
            'soap_timeout'         => 10,
            'wsaa_lock_timeout'    => 10,
            'emit_lock_timeout'    => 10,
            'wsaa_tra_ttl'         => 600,
            'wsaa_generation_skew' => 120,
            'wsaa_expiry_margin'   => 300,
            'retry_max_attempts'   => 3,
            'retry_base_backoff_ms' => 1,
            'retry_max_backoff_ms'  => 5,
            'idempotencia_max_intentos' => 5,
            'idempotencia_ttl_segundos' => self::TTL,
        ]);
    }

    private function sdk(): ArcaSdk
    {
        return ArcaSdk::getInstance($this->config, $this->container, $this->logger);
    }

    /**
     * Inserta una fila con estado y updated_at dados.
     */
    private function insertFila(
        string $externalId,
        string $estado,
        ?DateTimeImmutable $updatedAt = null,
        ?string $lease = null,
        ?string $fingerprint = null,
        ?int $cbteNroEnviado = null,
        ?int $cbteNroConfirmado = null,
        ?string $cae = null,
    ): void {
        $updatedAt = $updatedAt ?? $this->now;
        $lease = $estado === 'en_curso'
            ? ($lease ?? sprintf('00000000-0000-4000-8000-%012d', random_int(0, 999999999999)))
            : null;
        $fingerprint = $fingerprint ?? str_repeat('a', 64);
        $stmt = $this->pdo->prepare(
            'INSERT INTO arca_emisiones_idempotencia
               (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token, intento, es_fallo_infra,
                request_fingerprint, request_json, cbte_nro_enviado, cbte_nro_confirmado, cae,
                cae_fch_vto, created_at, updated_at)
             VALUES
               (:ext, :cuit, :pv, :tipo, :estado, :lease, 0, 0, :fp, :rj, :nro_env, :nro_conf, :cae,
                :cae_fch, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':ext' => $externalId, ':cuit' => self::CUIT, ':pv' => self::PUNTO_VENTA,
            ':tipo' => self::CBTE_TIPO, ':estado' => $estado,
            ':lease' => $lease, ':fp' => $fingerprint, ':rj' => '{}',
            ':nro_env' => $cbteNroEnviado, ':nro_conf' => $cbteNroConfirmado, ':cae' => $cae,
            ':cae_fch' => $cae === null ? null : '2025-07-15',
            ':created_at' => $updatedAt->format('Y-m-d H:i:s'),
            ':updated_at' => $updatedAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function filaExiste(string $externalId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM arca_emisiones_idempotencia WHERE external_id = :ext');
        $stmt->execute([':ext' => $externalId]);
        return $stmt->fetch() !== false;
    }

    // =================================================================
    // 5: Reset fallido con admin normal -> audit + DELETE
    // =================================================================

    public function test_reset_fallido_con_admin_normal_audita_y_borra(): void
    {
        $this->insertFila(self::EXT_FALLIDO, 'fallido', $this->now->modify('-1 hour'));

        $this->sdk()->resetExternalId(
            self::EXT_FALLIDO,
            'admin@example.com',
            'cleanup manual tras rechazo',
            false,
            false
        );

        // DELETE
        $this->assertFalse($this->filaExiste(self::EXT_FALLIDO), 'fila borrada');

        // Audit: al menos 1 entrada info() con los campos requeridos
        $this->assertGreaterThanOrEqual(1, $this->logger->infoCount(), 'audit log entry escrita');
        $last = $this->logger->lastInfo();
        $this->assertNotNull($last);
        $this->assertStringContainsString('RESET_EXTERNAL_ID', $last['message']);
        $this->assertSame(self::EXT_FALLIDO, $last['context']['external_id']);
        $this->assertSame(self::CUIT, $last['context']['cuit']);
        $this->assertSame(self::PUNTO_VENTA, $last['context']['punto_venta']);
        $this->assertSame(self::CBTE_TIPO, $last['context']['cbte_tipo']);
        $this->assertSame('fallido', $last['context']['estado']);
        $this->assertSame('admin@example.com', $last['context']['operator']);
        $this->assertSame('cleanup manual tras rechazo', $last['context']['motivo']);
        $this->assertSame(0, $last['context']['force_flag']);
    }

    // =================================================================
    // 6: Reset fallido sin motivo -> ValidationException
    // =================================================================

    public function test_reset_fallido_sin_motivo_lanza_ValidationException(): void
    {
        $this->insertFila(self::EXT_FALLIDO, 'fallido');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/motivo/');
        try {
            $this->sdk()->resetExternalId(
                self::EXT_FALLIDO,
                'admin',
                '',
                false,
                false
            );
        } finally {
            $this->assertTrue($this->filaExiste(self::EXT_FALLIDO), 'fila NO borrada');
            $this->assertSame(0, $this->logger->infoCount(), 'audit NO escrito si motivo vacio');
        }
    }

    public function test_reset_sin_operator_lanza_ValidationException(): void
    {
        $this->insertFila(self::EXT_FALLIDO, 'fallido');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/operator/');
        $this->sdk()->resetExternalId(
            self::EXT_FALLIDO,
            '',
            'motivo',
            false,
            false
        );
    }

    public function test_reset_externalId_invalido_antes_de_tocar_DB(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/UUID/');
        $this->sdk()->resetExternalId('not-a-uuid', 'admin', 'motivo');
    }

    // =================================================================
    // 7: Reset emitido sin force_emitido -> IdempotencyStateException
    // =================================================================

    public function test_reset_emitido_sin_force_emitido_rechaza_y_no_borra(): void
    {
        $this->insertFila(
            self::EXT_EMITIDO,
            'emitido',
            null,
            null,
            null,
            100, // cbte_nro_enviado
            100, // cbte_nro_confirmado
            '12345678901234' // cae
        );

        try {
            $this->sdk()->resetExternalId(
                self::EXT_EMITIDO,
                'admin',
                'cleanup por error de operator',
                false,
                false // force_emitido
            );
            $this->fail('debio lanzar IdempotencyStateException');
        } catch (IdempotencyStateException $e) {
            $this->assertStringContainsString('emitido', $e->getMessage());
            $this->assertStringContainsString('force_emitido', $e->getMessage());
        }

        $this->assertTrue($this->filaExiste(self::EXT_EMITIDO), 'fila NO borrada');
        // El audit log SE escribio (la regla dice: audit FIRST, luego
        // refuse checks). El refuse check falla DESPUES.
        $this->assertGreaterThanOrEqual(1, $this->logger->infoCount(), 'audit se escribio');
    }

    // =================================================================
    // 8: Reset emitido CON force_emitido -> audit + DELETE + WARNING
    // =================================================================

    public function test_reset_emitido_con_force_emitido_borra_y_warning(): void
    {
        $this->insertFila(
            self::EXT_EMITIDO,
            'emitido',
            null,
            null,
            null,
            100,
            100,
            '12345678901234'
        );

        $this->sdk()->resetExternalId(
            self::EXT_EMITIDO,
            'admin',
            'recuperar UUID para re-emit',
            true,
            true // force_emitido
        );

        $this->assertFalse($this->filaExiste(self::EXT_EMITIDO), 'fila borrada');

        // INFO entry
        $this->assertGreaterThanOrEqual(1, $this->logger->infoCount());
        // WARNING entry post-delete
        $this->assertGreaterThanOrEqual(1, $this->logger->warningCount(), 'warning emitido');
        $warn = $this->logger->lastWarning();
        $this->assertNotNull($warn);
        $this->assertStringContainsString('WARNING', $warn['message']);
        $this->assertStringContainsString('duplicar', $warn['message']);
        $this->assertSame(self::EXT_EMITIDO, $warn['context']['external_id']);
    }

    // =================================================================
    // 9: Reset en_curso dentro del TTL -> EmisionEnCursoException
    // =================================================================

    public function test_reset_en_curso_dentro_del_TTL_lanza_EmisionEnCursoException(): void
    {
        // updated_at muy reciente: dentro del TTL
        $this->insertFila(self::EXT_EN_CURSO, 'en_curso', $this->now);

        try {
            $this->sdk()->resetExternalId(
                self::EXT_EN_CURSO,
                'admin',
                'cleanup',
                false,
                false
            );
            $this->fail('debio lanzar EmisionEnCursoException');
        } catch (EmisionEnCursoException $e) {
            $this->assertStringContainsString('TTL', $e->getMessage());
        }

        $this->assertTrue($this->filaExiste(self::EXT_EN_CURSO), 'fila NO borrada');
    }

    // =================================================================
    // 10: Reset en_curso fuera del TTL (zombie) -> DELETE
    // =================================================================

    public function test_reset_en_curso_fuera_del_TTL_zombie_se_borra(): void
    {
        $staleTime = $this->now->modify('-' . (self::TTL + 60) . ' seconds');
        $this->insertFila(self::EXT_EN_CURSO, 'en_curso', $staleTime);

        $this->sdk()->resetExternalId(
            self::EXT_EN_CURSO,
            'admin',
            'cleanup zombie post-incidente',
            false,
            false
        );

        $this->assertFalse($this->filaExiste(self::EXT_EN_CURSO), 'zombie borrado');
        $this->assertGreaterThanOrEqual(1, $this->logger->infoCount());
    }

    // =================================================================
    // 11: Reset externalId inexistente -> IdempotencyStateException
    // =================================================================

    public function test_reset_externalId_inexistente_lanza_IdempotencyStateException(): void
    {
        $this->expectException(IdempotencyStateException::class);
        $this->expectExceptionMessageMatches('/no existe/');
        $this->sdk()->resetExternalId(
            self::EXT_NO_EXISTE,
            'admin',
            'cleanup'
        );
    }

    // =================================================================
    // 12: Audit log failure aborta el reset
    // =================================================================

    public function test_audit_failure_aborta_el_reset_y_no_borra(): void
    {
        $this->insertFila(self::EXT_FALLIDO, 'fallido');

        // Inyectar logger que lanza excepcion
        $failLogger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('audit sink down');
            }
        };
        // Re-instanciar el container con el logger que falla via getInstance
        ArcaSdk::resetInstance();
        $sdk = ArcaSdk::getInstance($this->config, $this->container, $failLogger);

        try {
            $sdk->resetExternalId(
                self::EXT_FALLIDO,
                'admin',
                'cleanup'
            );
            $this->fail('debio lanzar RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('audit sink down', $e->getMessage());
        }

        $this->assertTrue($this->filaExiste(self::EXT_FALLIDO), 'fila NO borrada cuando audit falla');
    }

    // =================================================================
    // 13: Audit log entry contiene todos los campos requeridos
    // =================================================================

    public function test_audit_log_entry_contiene_todos_los_campos_requeridos(): void
    {
        $this->insertFila(
            self::EXT_FALLIDO,
            'fallido',
            $this->now->modify('-1 hour'),
            null,
            null,
            50, // cbte_nro_enviado
            null, // no confirmado
            null  // sin cae
        );

        $this->sdk()->resetExternalId(
            self::EXT_FALLIDO,
            'admin@example.com',
            'motivo de test',
            true,
            false
        );

        $entry = $this->logger->lastInfo();
        $this->assertNotNull($entry, 'audit log entry existe');

        $required = [
            'external_id', 'cuit', 'punto_venta', 'cbte_tipo', 'estado', 'intento',
            'es_fallo_infra', 'cbte_nro_enviado', 'cae', 'cae_fch_vto',
            'cbte_nro_confirmado', 'operator', 'motivo', 'timestamp_utc', 'force_flag',
        ];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $entry['context'], "context.{$field} presente");
        }

        // El mensaje formateado tambien debe contener los campos clave
        // (regex checks) para que un sink parseable por texto funcione.
        $this->assertMatchesRegularExpression(
            '/external_id=' . preg_quote(self::EXT_FALLIDO, '/') . '/',
            $entry['message']
        );
        $this->assertMatchesRegularExpression('/cuit=' . self::CUIT . '/', $entry['message']);
        $this->assertMatchesRegularExpression('/punto_venta=' . self::PUNTO_VENTA . '/', $entry['message']);
        $this->assertMatchesRegularExpression('/cbte_tipo=' . self::CBTE_TIPO . '/', $entry['message']);
        $this->assertMatchesRegularExpression('/estado=fallido/', $entry['message']);
        $this->assertMatchesRegularExpression('/operator=admin@example\.com/', $entry['message']);
        $this->assertMatchesRegularExpression('/motivo=motivo de test/', $entry['message']);
        $this->assertMatchesRegularExpression('/timestamp_utc=\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $entry['message']);
        $this->assertMatchesRegularExpression('/force_flag=1/', $entry['message']);
    }
}
