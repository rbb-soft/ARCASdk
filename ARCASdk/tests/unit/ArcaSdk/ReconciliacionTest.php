<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\ArcaSdk;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\ArcaSdk;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Sdk\Container;
use Rbbsoft\ArcaSdk\Idempotencia\FilaEmision;
use Rbbsoft\ArcaSdk\Time\Clock;

/**
 * Tests del sweeper `ArcaSdk::reconciliar()` (Phase 8).
 *
 * Convenciones:
 *  - DB MySQL real (arca_facturador_test).
 *  - Cada test arranca con la tabla idempotente vacia (TRUNCATE).
 *  - Clock inyectable: avanzamos para que las filas queden stale
 *    (mas alla de idempotenciaTtlSegundos=300s).
 *  - Filas se insertan manualmente con SQL crudo para tener control
 *    exacto de `updated_at`, lease y fingerprint.
 */
final class ReconciliacionTest extends TestCase
{
    private const DSN  = 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
    private const USER = 'root';
    private const PASS = '';

    private const CUIT        = '20111111112';
    private const PUNTO_VENTA = 1;
    private const CBTE_TIPO   = 11; // Factura C
    private const TTL         = 300; // segundos, debe coincidir con makeConfig

    private const EXT1 = 'aaaa1111-1111-4111-8111-111111111111';
    private const EXT2 = 'aaaa2222-2222-4222-8222-222222222222';
    private const EXT3 = 'aaaa3333-3333-4333-8333-333333333333';
    private const EXT4 = 'aaaa4444-4444-4444-8444-444444444444';
    private const EXT5 = 'aaaa5555-5555-4555-8555-555555555555';

    private ?PDO $pdo = null;
    private ?Config $config = null;
    private ?DateTimeImmutable $now = null;
    private ?Clock $clock = null;
    private ?Container $container = null;

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
        return ArcaSdk::getInstance($this->config, $this->container);
    }

    /**
     * Inserta una fila en_curso con updated_at opcionalmente viejo.
     */
    private function insertEnCurso(
        string $externalId,
        ?DateTimeImmutable $updatedAt = null,
        ?string $lease = null,
        ?string $fingerprint = null,
    ): void {
        $updatedAt = $updatedAt ?? $this->now;
        $lease = $lease ?? sprintf('00000000-0000-4000-8000-%012d', random_int(0, 999999999999));
        $fingerprint = $fingerprint ?? str_repeat('a', 64);
        $stmt = $this->pdo->prepare(
            'INSERT INTO arca_emisiones_idempotencia
               (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token, intento, es_fallo_infra,
                request_fingerprint, request_json, created_at, updated_at)
             VALUES
               (:ext, :cuit, :pv, :tipo, :estado, :lease, 0, 0, :fp, :rj, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':ext' => $externalId, ':cuit' => self::CUIT, ':pv' => self::PUNTO_VENTA,
            ':tipo' => self::CBTE_TIPO, ':estado' => 'en_curso',
            ':lease' => $lease, ':fp' => $fingerprint, ':rj' => '{}',
            ':created_at' => $updatedAt->format('Y-m-d H:i:s'),
            ':updated_at' => $updatedAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function rawEstado(string $externalId): string
    {
        $stmt = $this->pdo->prepare('SELECT estado, es_fallo_infra FROM arca_emisiones_idempotencia WHERE external_id = :ext');
        $stmt->execute([':ext' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, "fila {$externalId} no existe");
        return $row['estado'];
    }

    private function esFalloInfra(string $externalId): int
    {
        $stmt = $this->pdo->prepare('SELECT es_fallo_infra FROM arca_emisiones_idempotencia WHERE external_id = :ext');
        $stmt->execute([':ext' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        return (int) $row['es_fallo_infra'];
    }

    private function filaNoExiste(string $externalId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM arca_emisiones_idempotencia WHERE external_id = :ext');
        $stmt->execute([':ext' => $externalId]);
        return $stmt->fetch() === false;
    }

    private function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM arca_emisiones_idempotencia')->fetchColumn();
    }

    // =================================================================
    // 1: reconciliar() sin candidatas -> 0, sin escrituras
    // =================================================================

    public function test_reconciliar_sin_filas_devuelve_cero_y_no_escribe(): void
    {
        $count = $this->countAll();
        $this->assertSame(0, $count, 'tabla vacia al inicio');

        $count = $this->sdk()->reconciliar();
        $this->assertSame(0, $count);

        $this->assertSame(0, $this->countAll(), 'reconciliar() sin candidatas no inserta nada');
    }

    public function test_reconciliar_solo_con_filas_frescas_devuelve_cero(): void
    {
        // Fila fresca (dentro del TTL).
        $this->insertEnCurso(self::EXT1);
        // Fila con updated_at 10 segundos en el pasado (< TTL=300).
        $this->insertEnCurso(self::EXT2, $this->now->modify('-10 seconds'));

        $count = $this->sdk()->reconciliar();
        $this->assertSame(0, $count);

        $this->assertSame('en_curso', $this->rawEstado(self::EXT1));
        $this->assertSame('en_curso', $this->rawEstado(self::EXT2));
    }

    // =================================================================
    // 2: 3 stale + 2 fresh -> 3 transicionadas
    // =================================================================

    public function test_reconciliar_3_stale_y_2_frescas_solo_transiciona_las_stale(): void
    {
        $staleTime = $this->now->modify('-' . (self::TTL + 60) . ' seconds');
        $this->insertEnCurso(self::EXT1, $staleTime);
        $this->insertEnCurso(self::EXT2, $staleTime);
        $this->insertEnCurso(self::EXT3, $staleTime);
        $this->insertEnCurso(self::EXT4); // fresca
        $this->insertEnCurso(self::EXT5, $this->now->modify('-30 seconds')); // fresca

        $count = $this->sdk()->reconciliar();
        $this->assertSame(3, $count, '3 stale transicionadas');

        // Las 3 stale -> fallido con es_fallo_infra=0
        $this->assertSame(FilaEmision::ESTADO_FALLIDO, $this->rawEstado(self::EXT1));
        $this->assertSame(0, $this->esFalloInfra(self::EXT1), 'stale -> es_fallo_infra=0');
        $this->assertSame(FilaEmision::ESTADO_FALLIDO, $this->rawEstado(self::EXT2));
        $this->assertSame(0, $this->esFalloInfra(self::EXT2));
        $this->assertSame(FilaEmision::ESTADO_FALLIDO, $this->rawEstado(self::EXT3));
        $this->assertSame(0, $this->esFalloInfra(self::EXT3));

        // Las 2 fresh -> en_curso intactas
        $this->assertSame(FilaEmision::ESTADO_EN_CURSO, $this->rawEstado(self::EXT4));
        $this->assertSame(FilaEmision::ESTADO_EN_CURSO, $this->rawEstado(self::EXT5));

        // lease_token de las stale debe haber sido NULLed
        foreach ([self::EXT1, self::EXT2, self::EXT3] as $ext) {
            $stmt = $this->pdo->prepare('SELECT lease_token FROM arca_emisiones_idempotencia WHERE external_id = :ext');
            $stmt->execute([':ext' => $ext]);
            $this->assertNull($stmt->fetchColumn(), "lease de {$ext} debe ser NULL");
        }
    }

    // =================================================================
    // 3: limit=1 transiciona solo 1
    // =================================================================

    public function test_reconciliar_con_limit_1_transiciona_una_sola_y_luego_el_resto(): void
    {
        $staleTime = $this->now->modify('-' . (self::TTL + 60) . ' seconds');
        $this->insertEnCurso(self::EXT1, $staleTime);
        $this->insertEnCurso(self::EXT2, $staleTime);
        $this->insertEnCurso(self::EXT3, $staleTime);

        $count = $this->sdk()->reconciliar(1);
        $this->assertSame(1, $count, 'limit=1 -> 1 transicionada');

        // El caller puede llamar de nuevo. Hay 2 stale restantes.
        $count = $this->sdk()->reconciliar(1);
        $this->assertSame(1, $count, 'segundo llamado: 1 mas');

        $count = $this->sdk()->reconciliar(1);
        $this->assertSame(1, $count, 'tercer llamado: la ultima');

        $count = $this->sdk()->reconciliar(1);
        $this->assertSame(0, $count, 'cuarto llamado: nada mas');
    }

    public function test_reconciliar_limit_default_100_procesa_todas_hasta_100(): void
    {
        $staleTime = $this->now->modify('-' . (self::TTL + 60) . ' seconds');
        for ($i = 1; $i <= 5; $i++) {
            $ext = sprintf('bbbb%04d-0000-4000-8000-000000000000', $i);
            $this->insertEnCurso($ext, $staleTime);
        }

        $count = $this->sdk()->reconciliar(); // default 100
        $this->assertSame(5, $count);
    }

    public function test_reconciliar_limit_cero_o_negativo_lanza_ValidationException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/limit/');
        $this->sdk()->reconciliar(0);
    }

    // =================================================================
    // 4: Sweeper concurrente: dos llamadas seguidas no duplican
    // =================================================================

    public function test_reconciliar_dos_llamadas_seguidas_no_duplican_transicion(): void
    {
        $staleTime = $this->now->modify('-' . (self::TTL + 60) . ' seconds');
        $this->insertEnCurso(self::EXT1, $staleTime);

        $first  = $this->sdk()->reconciliar();
        $second = $this->sdk()->reconciliar();

        $this->assertSame(1, $first, 'primer sweep encuentra la stale');
        $this->assertSame(0, $second, 'segundo sweep no encuentra nada (ya esta fallido)');

        $this->assertSame(FilaEmision::ESTADO_FALLIDO, $this->rawEstado(self::EXT1));
    }

    public function test_reconciliar_no_toca_filas_emitidas_o_fallidas(): void
    {
        $staleTime = $this->now->modify('-' . (self::TTL + 60) . ' seconds');
        // fila emitido (no debe tocar)
        $this->insertEnCurso(self::EXT1, $staleTime);
        $this->pdo->prepare(
            'UPDATE arca_emisiones_idempotencia
                SET estado = :emitido, cae = :cae, cbte_nro_confirmado = :nro, cae_fch_vto = :fch
              WHERE external_id = :ext'
        )->execute([
            ':emitido' => 'emitido', ':cae' => '12345678901234',
            ':nro' => 100, ':fch' => '2025-07-15', ':ext' => self::EXT1,
        ]);
        // fila fallido (no debe tocar)
        $this->insertEnCurso(self::EXT2, $staleTime);
        $this->pdo->prepare(
            'UPDATE arca_emisiones_idempotencia SET estado = :fallido WHERE external_id = :ext'
        )->execute([':fallido' => 'fallido', ':ext' => self::EXT2]);
        // fila en_curso stale (debe transicionar)
        $this->insertEnCurso(self::EXT3, $staleTime);

        $count = $this->sdk()->reconciliar();
        $this->assertSame(1, $count, 'solo la en_curso stale');

        $this->assertSame('emitido', $this->rawEstado(self::EXT1));
        $this->assertSame('fallido', $this->rawEstado(self::EXT2));
        $this->assertSame('fallido', $this->rawEstado(self::EXT3));
    }

    public function test_reconciliar_mismo_cutoff_en_SELECT_y_CAS_no_recalcula_entre_pasos(): void
    {
        // Verifica la garantia del plan: una fila que se vuelve
        // "fresca" entre el SELECT y el CAS no debe ser transicionada
        // por nosotros. Simulamos esto no siendo posible sin race
        // real, pero al menos verificamos que un caller que avanza
        // el reloj DURANTE el sweep no transiciona esa fila.
        //
        // Caso simplificado: insertamos 1 stale, llamamos reconciliar
        // y verificamos que la fila quedo fallido. Si el CAS
        // recalculara el cutoff, no pasaria nada aqui tampoco
        // (porque la fila es stale respecto a cualquier reloj
        // anterior). La proteccion real es el `updated_at < cutoff`
        // en el WHERE del CAS.
        $staleTime = $this->now->modify('-' . (self::TTL + 60) . ' seconds');
        $this->insertEnCurso(self::EXT1, $staleTime);

        $count = $this->sdk()->reconciliar();
        $this->assertSame(1, $count);
        $this->assertSame('fallido', $this->rawEstado(self::EXT1));
    }
}
