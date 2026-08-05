<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\ArcaSdk;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\CbteRechazadoException;
use Rbbsoft\ArcaSdk\Exceptions\ConfigException;
use Rbbsoft\ArcaSdk\Exceptions\EmisionEnCursoException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyConflictException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Exceptions\MaxIdempotencyAttemptsException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeArcaTransientException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeProtocolException;
use Rbbsoft\ArcaSdk\Exceptions\ZombieRecoveryFailedException;
use Rbbsoft\ArcaSdk\Sdk\Container;
use Rbbsoft\ArcaSdk\Idempotencia\FilaEmision;
use Rbbsoft\ArcaSdk\Time\Clock;
use Rbbsoft\ArcaSdk\Wsfe\TiposComprobante;
use Rbbsoft\ArcaSdk\Wsfe\WsfeClient;
use Rbbsoft\ArcaSdk\Tests\Unit\ArcaSdk\WsfeResponseBuilder;
use Rbbsoft\ArcaSdk\Tests\Unit\Lock\LockManagerDouble;
use Rbbsoft\ArcaSdk\Tests\Unit\Wsfe\SoapClientDouble;
use Rbbsoft\ArcaSdk\Tests\Unit\Wsfe\WsaaClientDouble;
use SoapFault;

/**
 * Tests de integracion del orquestador ArcaSdk (Phase 6).
 *
 * Convenciones:
 *  - DB MySQL real (arca_facturador_test).
 *  - WsfeClient real con SoapClientDouble inyectado.
 *  - WsaaClientDouble (closure-based token provider).
 *  - LockManagerDouble (simula acquire/release en memoria, permite
 *    scriptar contention).
 *  - Clock inyectable (reloj fijo o congelado).
 *  - Cada test arranca con la tabla idempotente vacia (TRUNCATE).
 *  - Singleton se resetea en setUp (los tests son independientes).
 *
 * Los tests que el brief describe estan mapeados 1:1. Cada test
 * cubre un unico aspecto del flujo para que un fallo apunte a un
 * lugar exacto del orquestador.
 */
final class ArcaSdkTest extends TestCase
{
    private const DSN  = 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
    private const USER = 'root';
    private const PASS = '';

    private const CUIT        = '20111111112';
    private const PUNTO_VENTA = 1;
    private const CBTE_TIPO   = 11; // Factura C

    /** UUID v4 fijos para tests deterministas. */
    private const EXT_FACT  = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    private const EXT_FACT2 = 'aaaaaaaa-bbbb-4ccc-8ddd-ffffffffffff';
    private const EXT_NC    = '11111111-2222-4333-8444-555555555555';
    private const EXT_BAD_UUID = 'not-a-uuid';

    private ?PDO $pdo = null;
    private ?Config $config = null;
    private ?Clock $clock = null;
    private ?DateTimeImmutable $now = null;
    private ?WsaaClientDouble $wsaa = null;
    private ?SoapClientDouble $soap = null;
    private ?WsfeResponseBuilder $responseBuilder = null;
    private ?LockManagerDouble $lockManager = null;
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
        $this->clock = new Clock(function (): DateTimeImmutable { return $this->now; });

        $this->wsaa = new WsaaClientDouble();
        $this->soap = new SoapClientDouble();
        $this->responseBuilder = new WsfeResponseBuilder($this->soap);
        $this->lockManager = new LockManagerDouble();

        $this->container = (new Container($this->config))
            ->withPdo($this->pdo)
            ->withClock($this->clock)
            ->withLockManager($this->lockManager)
            ->withWsaaClient($this->buildWsaaClient($this->wsaa))
            ->withWsfeClient($this->buildWsfeClient($this->soap, $this->wsaa));

        // Reset Singleton para que cada test tenga instancia fresca.
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeConfig(array $overrides = []): Config
    {
        $base = [
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
            'idempotencia_ttl_segundos' => 300,
        ];
        return Config::fromArray(array_merge($base, $overrides));
    }

    private function buildWsfeClient(SoapClientDouble $soap, WsaaClientDouble $wsaa): WsfeClient
    {
        $policy = new \Rbbsoft\ArcaSdk\Support\RetryPolicy();
        return new WsfeClient(
            $this->config,
            $wsaa->asTokenProvider(),
            null,
            $policy,
            $soap,
        );
    }

    private function buildWsaaClient(WsaaClientDouble $wsaa): \Rbbsoft\ArcaSdk\Wsaa\WsaaClient
    {
        // Creamos un WsaaClient real con un SoapClient que el doble no
        // usa (porque wsaa client es un placeholder). En la practica,
        // WsfeClient llama a wsaaClient->getToken(wsn) o al
        // tokenProvider directamente. Como withWsfeClient es lo
        // importante, este wsaaClient es solo para que Container
        // pueda construir WsfeClient si se le pide.
        $wsaaSoap = new SoapClientDouble();
        return new \Rbbsoft\ArcaSdk\Wsaa\WsaaClient(
            $this->config,
            $wsaaSoap,
            new \Rbbsoft\ArcaSdk\Wsaa\NullTicketCache(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFactura(array $overrides = []): array
    {
        return array_merge([
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20999999999',
            'receptor_condicion_iva'  => 'RI',
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataNotaCreditoB(array $overrides = []): array
    {
        return array_merge([
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20999999999',
            'receptor_condicion_iva'  => 'RI',
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
            'cbtes_asoc' => [[
                'tipo' => TiposComprobante::FACTURA_B,
                'punto_venta' => 1,
                'nro' => 1234,
            ]],
        ], $overrides);
    }

    private function sdk(): ArcaSdk
    {
        return ArcaSdk::getInstance($this->config, $this->container);
    }

    private function rawRow(string $externalId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM arca_emisiones_idempotencia WHERE external_id = :ext');
        $stmt->execute([':ext' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, "fila {$externalId} no existe");
        return $row;
    }

    private function filaVacia(string $externalId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM arca_emisiones_idempotencia WHERE external_id = :ext');
        $stmt->execute([':ext' => $externalId]);
        return $stmt->fetch() === false;
    }

    /**
     * Avanza el reloj del test a una nueva hora. Todos los `now`
     * subsiguientes devolveran este valor.
     */
    private function advanceTime(string $modify): void
    {
        $this->now = $this->now->modify($modify);
    }

    /**
     * Avanza el reloj y refleja el cambio en el Container (que
     * reusa el closure pasado a IdempotenciaRepository).
     */
    private function advanceTimeAndRefresh(string $modify): void
    {
        $this->advanceTime($modify);
        $this->container = $this->container->withClock($this->clock);
    }

    // =================================================================
    // 1-2: Singleton
    // =================================================================

    public function test_singleton_getInstance_devuelve_misma_instancia_con_mismo_config(): void
    {
        $a = ArcaSdk::getInstance($this->config, $this->container);
        $b = ArcaSdk::getInstance($this->config, $this->container);
        $this->assertSame($a, $b, 'mismo Container + mismo Config -> misma instancia');
    }

    public function test_singleton_incompatible_por_cuit_lanza_ConfigException(): void
    {
        ArcaSdk::getInstance($this->config, $this->container);
        $otro = $this->makeConfig(['cuit' => '20999999999']);
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/single-tenant/');
        ArcaSdk::getInstance($otro, $this->container);
    }

    public function test_singleton_incompatible_por_punto_venta_lanza_ConfigException(): void
    {
        ArcaSdk::getInstance($this->config, $this->container);
        $otro = $this->makeConfig(['punto_venta' => 2]);
        $this->expectException(ConfigException::class);
        ArcaSdk::getInstance($otro, $this->container);
    }

    public function test_singleton_incompatible_por_env_lanza_ConfigException(): void
    {
        ArcaSdk::getInstance($this->config, $this->container);
        $otro = $this->makeConfig(['env' => 'prod']);
        $this->expectException(ConfigException::class);
        ArcaSdk::getInstance($otro, $this->container);
    }

    public function test_singleton_incompatible_por_dbDsn_lanza_ConfigException(): void
    {
        ArcaSdk::getInstance($this->config, $this->container);
        $otro = $this->makeConfig(['db_dsn' => 'mysql:host=localhost;dbname=otra_db;charset=utf8mb4']);
        $this->expectException(ConfigException::class);
        ArcaSdk::getInstance($otro, $this->container);
    }

    public function test_getInstance_sin_container_cablea_pdo_desde_config(): void
    {
        // Reset para forzar el camino de construccion sin container pre-armado.
        ArcaSdk::resetInstance();
        $sdk = ArcaSdk::getInstance($this->config);

        $this->assertTrue(
            $sdk->container()->hasPdo(),
            'getInstance sin Container debe auto-cablear el PDO desde Config'
        );

        $pdo = $sdk->container()->pdo();
        $this->assertSame(
            PDO::ERRMODE_EXCEPTION,
            $pdo->getAttribute(PDO::ATTR_ERRMODE),
            'PDO auto-cableado debe tener ERRMODE_EXCEPTION'
        );
    }

    // =================================================================
    // 3: emitirFactura happy path
    // =================================================================

    public function test_emitirFactura_happy_path_nueva_emision_arca_aprueba(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(99);
        $this->responseBuilder->enqueueAprobado(100, '12345678901234', '20250715');
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        $result = $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());

        $this->assertSame(self::EXT_FACT, $result->externalId);
        $this->assertSame(100, $result->cbteNro);
        $this->assertSame('12345678901234', $result->cae);
        $this->assertSame('20250715', $result->caeFchVto);
        $this->assertSame('A', $result->resultado);
        $this->assertSame(self::PUNTO_VENTA, $result->puntoVenta);
        $this->assertSame(self::CBTE_TIPO, $result->cbteTipo);
        // Factura C no discrimina IVA: total == gravado.
        $this->assertSame('100.00', $result->montoTotal);
        $this->assertSame('100.00', $result->montoNeto);
        $this->assertSame('0.00', $result->montoIva);

        // DB
        $row = $this->rawRow(self::EXT_FACT);
        $this->assertSame('emitido', $row['estado']);
        $this->assertSame('12345678901234', $row['cae']);
        $this->assertSame(100, (int) $row['cbte_nro_confirmado']);
        $this->assertNull($row['lease_token']);
        $this->assertSame(0, (int) $row['intento']);
        $this->assertSame(0, (int) $row['es_fallo_infra']);
        $this->assertNotEmpty($row['request_fingerprint']);
        $this->assertNotEmpty($row['request_json']);

        // Lock: acquire y release ejecutados ambos.
        $this->assertSame(1, $this->lockManager->acquireCallCount);
        $this->assertSame(1, $this->lockManager->releaseCallCount);

        // SOAP: ultimoAutorizado y solicitar llamados una vez.
        $this->assertSame(2, $this->soap->callCount);
    }

    // =================================================================
    // 4: emitirFactura idempotent replay
    // =================================================================

    public function test_emitirFactura_idempotent_replay_mismo_externalId_mismo_fingerprint(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(99);
        $this->responseBuilder->enqueueAprobado(100, '12345678901234', '20250715');
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        $first = $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
        $this->assertSame(100, $first->cbteNro);

        // Replay: misma data, mismo externalId. La segunda llamada
        // NO debe tocar SOAP (retorna cached).
        $second = $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
        $this->assertEquals($first->cae, $second->cae, 'replay mantiene el CAE');
        $this->assertEquals($first->cbteNro, $second->cbteNro, 'replay mantiene el cbte_nro');
        $this->assertEquals($first->montoTotal, $second->montoTotal, 'replay mantiene el monto_total');
        $this->assertSame(2, $this->soap->callCount, 'SOAP no se llamo en replay (sigue en 2)');
        $this->assertSame(1, $this->lockManager->acquireCallCount, 'lock NO se adquirio en replay');
        $this->assertSame(2, $this->soap->callCount, 'SOAP no se llamo en replay (sigue en 2)');
        $this->assertSame(1, $this->lockManager->acquireCallCount, 'lock NO se adquirio en replay');
    }

    // =================================================================
    // 5: emitirFactura fingerprint mismatch
    // =================================================================

    public function test_emitirFactura_fingerprint_mismatch_lanza_IdempotencyConflictException(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(99);
        $this->responseBuilder->enqueueAprobado(100, '12345678901234', '20250715');
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());

        // Mismo externalId, datos distintos (importe_gravado mayor).
        $this->expectException(IdempotencyConflictException::class);
        $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura([
            'items' => [
                ['importe_gravado' => '500.00', 'alicuota_iva' => '21'],
            ],
        ]));
    }

    // =================================================================
    // 6: emitirFactura Resultado='R' (rechazo)
    // =================================================================

    public function test_emitirFactura_rechazo_arca(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(50);
        $this->responseBuilder->enqueueRechazado(51, [
            ['codigo' => 100, 'mensaje' => 'Error de validacion'],
        ]);
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();

        try {
            $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
            $this->fail('debio lanzar CbteRechazadoException');
        } catch (CbteRechazadoException $e) {
            $this->assertSame([['codigo' => 100, 'mensaje' => 'Error de validacion']], $e->observaciones);
        }

        $row = $this->rawRow(self::EXT_FACT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra']);
        $this->assertNull($row['lease_token']);
        $this->assertNotNull($row['response_json']);
        $decoded = json_decode($row['response_json'], true);
        $this->assertSame('R', $decoded['resultado']);

        // Lock se libero.
        $this->assertSame(1, $this->lockManager->acquireCallCount);
        $this->assertSame(1, $this->lockManager->releaseCallCount);
    }

    // =================================================================
    // 7: emitirFactura transient fault
    // =================================================================

    public function test_emitirFactura_fallo_transitorio_arca_9999(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(50);
        // Forzamos 3 SoapFaults (uno por intento del retry policy del WsfeClient).
        $fault = new SoapFault('soap:Server', 'Internal Server Error');
        $this->soap->enqueueFault($fault);
        $this->soap->enqueueFault($fault);
        $this->soap->enqueueFault($fault);
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();

        try {
            $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
            $this->fail('debio lanzar WsfeException');
        } catch (WsfeException) {
            // OK
        }

        $row = $this->rawRow(self::EXT_FACT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(1, (int) $row['es_fallo_infra'], 'transitorio -> es_fallo_infra=1');
        $this->assertSame(0, (int) $row['intento'], 'infra no consume intento');

        $this->assertSame(1, $this->lockManager->acquireCallCount);
        $this->assertSame(1, $this->lockManager->releaseCallCount);
    }

    public function test_emitirFactura_fallo_transitorio_WsfeArcaTransientException(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(50);
        $this->soap->enqueueFault(new SoapFault('soap:Server', 'Internal Server Error'));
        $this->soap->enqueueFault(new SoapFault('soap:Server', 'Internal Server Error'));
        $this->soap->enqueueFault(new SoapFault('soap:Server', 'Internal Server Error'));
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();

        try {
            $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
            $this->fail('debio lanzar WsfeException');
        } catch (WsfeException) {
            $row = $this->rawRow(self::EXT_FACT);
            $this->assertSame('fallido', $row['estado']);
            $this->assertSame(1, (int) $row['es_fallo_infra']);
        }
    }

    // =================================================================
    // 8: emitirFactura structural fault
    // =================================================================

    public function test_emitirFactura_fallo_estructural(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(50);
        // Encolar un envelope que el parser no pueda normalizar:
        // root inesperado. El WsfeClient NO reintenta protocolos
        // estructurales, asi que solo necesitamos una respuesta.
        $this->soap->enqueueResponse(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . '<NotARCAOp xmlns="http://example.com/"/>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>'
        );
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();

        try {
            $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
            $this->fail('debio lanzar WsfeProtocolException o WsfeException');
        } catch (WsfeProtocolException | WsfeException) {
            $row = $this->rawRow(self::EXT_FACT);
            $this->assertSame('fallido', $row['estado']);
            $this->assertSame(0, (int) $row['es_fallo_infra'], 'estructural -> es_fallo_infra=0');
        }

        $this->assertSame(1, $this->lockManager->acquireCallCount);
        $this->assertSame(1, $this->lockManager->releaseCallCount);
    }

    // =================================================================
    // 9: emitirFactura en_curso con lease fresh
    // =================================================================

    public function test_emitirFactura_en_curso_fresco_lanza_EmisionEnCursoException(): void
    {
        // Insertar manualmente una fila en_curso dentro del TTL.
        // El fingerprint debe coincidir con el de la data que enviaremos.
        $data = $this->dataFactura();
        $comprobante = \Rbbsoft\ArcaSdk\Wsfe\Comprobante::fromArray(
            $data, $this->config->puntoVenta, self::CBTE_TIPO
        );
        $fp = $comprobante->fingerprint();
        $nowStr = $this->now->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO arca_emisiones_idempotencia
               (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token, intento, es_fallo_infra,
                request_fingerprint, request_json, created_at, updated_at)
             VALUES
               (:ext, :cuit, :pv, :tipo, :estado, :lease, 0, 0, :fp, :rj, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':ext' => self::EXT_FACT, ':cuit' => self::CUIT, ':pv' => self::PUNTO_VENTA,
            ':tipo' => self::CBTE_TIPO, ':estado' => 'en_curso',
            ':lease' => '00000000-0000-4000-8000-000000000001',
            ':fp' => $fp, ':rj' => $comprobante->canonicalJson(),
            ':created_at' => $nowStr, ':updated_at' => $nowStr,
        ]);

        $sdk = $this->sdk();
        $this->expectException(EmisionEnCursoException::class);
        $sdk->emitirFactura(self::EXT_FACT, $data);
    }

    // =================================================================
    // 10: emitirFactura en_curso con lease stale
    // =================================================================

    public function test_emitirFactura_en_curso_stale_se_reclama_y_procede(): void
    {
        // Insertar una fila en_curso con updated_at viejo (fuera del TTL).
        $staleTime = $this->now->modify('-1 hour')->format('Y-m-d H:i:s');
        $fp = str_repeat('a', 64);
        $stmt = $this->pdo->prepare(
            'INSERT INTO arca_emisiones_idempotencia
               (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token, intento, es_fallo_infra,
                request_fingerprint, request_json, created_at, updated_at)
             VALUES
               (:ext, :cuit, :pv, :tipo, :estado, :lease, 0, 0, :fp, :rj, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':ext' => self::EXT_FACT, ':cuit' => self::CUIT, ':pv' => self::PUNTO_VENTA,
            ':tipo' => self::CBTE_TIPO, ':estado' => 'en_curso',
            ':lease' => '00000000-0000-4000-8000-000000000002',
            ':fp' => $fp, ':rj' => '{}',
            ':created_at' => $staleTime, ':updated_at' => $staleTime,
        ]);

        // Calcular fingerprint REAL de la data que vamos a enviar.
        $sdk = $this->sdk();

        // Pre-llenar request_fingerprint con el de la data que enviaremos,
        // para que assertIdentity no falle.
        $data = $this->dataFactura();
        $comprobante = \Rbbsoft\ArcaSdk\Wsfe\Comprobante::fromArray(
            $data, $this->config->puntoVenta, self::CBTE_TIPO
        );
        $realFp = $comprobante->fingerprint();

        $this->pdo->prepare('UPDATE arca_emisiones_idempotencia SET request_fingerprint = :fp WHERE external_id = :ext')
            ->execute([':fp' => $realFp, ':ext' => self::EXT_FACT]);

        // Respuestas SOAP: emitir normal.
        $this->responseBuilder->enqueueUltimoAutorizado(99);
        $this->responseBuilder->enqueueAprobado(100, '12345678901234', '20250715');
        $this->lockManager->setNextAcquireResult(true);

        $result = $sdk->emitirFactura(self::EXT_FACT, $data);
        $this->assertSame(100, $result->cbteNro);
        $this->assertSame(2, $this->soap->callCount, 'SOAP fue llamado para la nueva emision');

        $row = $this->rawRow(self::EXT_FACT);
        $this->assertSame('emitido', $row['estado']);
        $this->assertSame(1, (int) $row['intento'], 'stale se reabre via fallido -> intento=1');
    }

    // =================================================================
    // 11: emitirFactura fallido reopen (infra)
    // =================================================================

    public function test_emitirFactura_fallido_reopen_infra_no_consume_intento(): void
    {
        // Insertar fila fallido con es_fallo_infra=1 y un fingerprint valido.
        $data = $this->dataFactura();
        $comprobante = \Rbbsoft\ArcaSdk\Wsfe\Comprobante::fromArray(
            $data, $this->config->puntoVenta, self::CBTE_TIPO
        );
        $fp = $comprobante->fingerprint();
        $rj = $comprobante->canonicalJson();

        $stmt = $this->pdo->prepare(
            'INSERT INTO arca_emisiones_idempotencia
               (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token, intento, es_fallo_infra,
                request_fingerprint, request_json, created_at, updated_at)
             VALUES
               (:ext, :cuit, :pv, :tipo, :estado, NULL, 2, 1, :fp, :rj, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':ext' => self::EXT_FACT, ':cuit' => self::CUIT, ':pv' => self::PUNTO_VENTA,
            ':tipo' => self::CBTE_TIPO, ':estado' => 'fallido',
            ':fp' => $fp, ':rj' => $rj,
            ':created_at' => $this->now->format('Y-m-d H:i:s'),
            ':updated_at' => $this->now->format('Y-m-d H:i:s'),
        ]);

        $this->responseBuilder->enqueueUltimoAutorizado(99);
        $this->responseBuilder->enqueueAprobado(100, '12345678901234', '20250715');
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        $sdk->emitirFactura(self::EXT_FACT, $data);

        $row = $this->rawRow(self::EXT_FACT);
        $this->assertSame('emitido', $row['estado']);
        $this->assertSame(2, (int) $row['intento'], 'infra no incrementa intento');
    }

    // =================================================================
    // 12: emitirFactura fallido reopen (negocio)
    // =================================================================

    public function test_emitirFactura_fallido_reopen_negocio_consume_intento(): void
    {
        $data = $this->dataFactura();
        $comprobante = \Rbbsoft\ArcaSdk\Wsfe\Comprobante::fromArray(
            $data, $this->config->puntoVenta, self::CBTE_TIPO
        );
        $fp = $comprobante->fingerprint();
        $rj = $comprobante->canonicalJson();

        $stmt = $this->pdo->prepare(
            'INSERT INTO arca_emisiones_idempotencia
               (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token, intento, es_fallo_infra,
                request_fingerprint, request_json, created_at, updated_at)
             VALUES
               (:ext, :cuit, :pv, :tipo, :estado, NULL, 1, 0, :fp, :rj, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':ext' => self::EXT_FACT, ':cuit' => self::CUIT, ':pv' => self::PUNTO_VENTA,
            ':tipo' => self::CBTE_TIPO, ':estado' => 'fallido',
            ':fp' => $fp, ':rj' => $rj,
            ':created_at' => $this->now->format('Y-m-d H:i:s'),
            ':updated_at' => $this->now->format('Y-m-d H:i:s'),
        ]);

        $this->responseBuilder->enqueueUltimoAutorizado(99);
        $this->responseBuilder->enqueueAprobado(100, '12345678901234', '20250715');
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        $sdk->emitirFactura(self::EXT_FACT, $data);

        $row = $this->rawRow(self::EXT_FACT);
        $this->assertSame('emitido', $row['estado']);
        $this->assertSame(2, (int) $row['intento'], 'negocio incrementa intento (1+1=2)');
    }

    // =================================================================
    // 13: emitirFactura fallido hits max attempts
    // =================================================================

    public function test_emitirFactura_fallido_alcanza_max_intentos_lanza_MaxIdempotencyAttemptsException(): void
    {
        // idempotencia_max_intentos = 5. Insertar fila fallido con
        // intento=5 y es_fallo_infra=0. El CAS `intento<max` falla
        // (5<5 = false), transitionFallidoToEnCurso re-lee y detecta
        // intento >= max, lanza MaxIdempotencyAttemptsException.
        $data = $this->dataFactura();
        $comprobante = \Rbbsoft\ArcaSdk\Wsfe\Comprobante::fromArray(
            $data, $this->config->puntoVenta, self::CBTE_TIPO
        );
        $fp = $comprobante->fingerprint();
        $rj = $comprobante->canonicalJson();

        $stmt = $this->pdo->prepare(
            'INSERT INTO arca_emisiones_idempotencia
               (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token, intento, es_fallo_infra,
                request_fingerprint, request_json, created_at, updated_at)
             VALUES
               (:ext, :cuit, :pv, :tipo, :estado, NULL, 5, 0, :fp, :rj, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':ext' => self::EXT_FACT, ':cuit' => self::CUIT, ':pv' => self::PUNTO_VENTA,
            ':tipo' => self::CBTE_TIPO, ':estado' => 'fallido',
            ':fp' => $fp, ':rj' => $rj,
            ':created_at' => $this->now->format('Y-m-d H:i:s'),
            ':updated_at' => $this->now->format('Y-m-d H:i:s'),
        ]);

        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        $this->expectException(MaxIdempotencyAttemptsException::class);
        $sdk->emitirFactura(self::EXT_FACT, $data);

        // Lock se libero (el orquestador lo libera en finally).
        $this->assertSame(1, $this->lockManager->releaseCallCount);
    }

    // =================================================================
    // 14: emitirFactura lock contention
    // =================================================================

    public function test_emitirFactura_lock_no_adquirido_lanza_excepcion_y_marca_fallido(): void
    {
        $this->lockManager->setNextAcquireResult(false);

        $sdk = $this->sdk();

        try {
            $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
            $this->fail('debio lanzar WsfeException');
        } catch (WsfeException $e) {
            $this->assertStringContainsString('no se pudo adquirir el named lock', $e->getMessage());
        }

        $row = $this->rawRow(self::EXT_FACT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(1, (int) $row['es_fallo_infra'], 'lock no adquirido -> es_fallo_infra=1');
    }

    // =================================================================
    // 15-16: lock release semantics
    // =================================================================

    public function test_emitirFactura_release_se_llama_en_happy_path(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(50);
        $this->responseBuilder->enqueueAprobado(51, '12345678901234', '20250715');
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());

        $this->assertSame(1, $this->lockManager->acquireCallCount);
        $this->assertSame(1, $this->lockManager->releaseCallCount);
    }

    public function test_emitirFactura_release_se_llama_en_rechazo(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(50);
        $this->responseBuilder->enqueueRechazado(51, [['codigo' => 1, 'mensaje' => 'X']]);
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        try {
            $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
        } catch (CbteRechazadoException) {
            // OK
        }
        $this->assertSame(1, $this->lockManager->releaseCallCount);
    }

    public function test_emitirFactura_release_se_llama_en_fallo_transitorio(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(50);
        $fault = new SoapFault('soap:Server', 'Internal Server Error');
        $this->soap->enqueueFault($fault);
        $this->soap->enqueueFault($fault);
        $this->soap->enqueueFault($fault);
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        try {
            $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
        } catch (WsfeException) {
            // OK
        }
        $this->assertSame(1, $this->lockManager->releaseCallCount);
    }

    public function test_emitirFactura_release_se_llama_en_fallo_estructural(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(50);
        $this->soap->enqueueResponse(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Body><BadOp xmlns="http://example.com/"/></SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>'
        );
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        try {
            $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
        } catch (WsfeException) {
            // OK
        }
        $this->assertSame(1, $this->lockManager->releaseCallCount);
    }

    public function test_emitirFactura_release_NO_se_llama_cuando_acquire_falla(): void
    {
        $this->lockManager->setNextAcquireResult(false);

        $sdk = $this->sdk();
        try {
            $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());
        } catch (WsfeException) {
            // OK
        }
        $this->assertSame(0, $this->lockManager->releaseCallCount, 'release NO se llama si acquire=false');
    }

    // =================================================================
    // 17: emitirFactura externalId no UUID
    // =================================================================

    public function test_emitirFactura_externalId_invalido_antes_de_tocar_DB(): void
    {
        $sdk = $this->sdk();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/UUID v4/');
        $sdk->emitirFactura(self::EXT_BAD_UUID, $this->dataFactura());
        $this->assertTrue($this->filaVacia(self::EXT_BAD_UUID), 'no se persistio ninguna fila');
    }

    // =================================================================
    // 18: emitirFactura validacion de datos
    // =================================================================

    public function test_emitirFactura_validacion_interna_lanza_ValidationException_sin_persistir(): void
    {
        $sdk = $this->sdk();
        // Sin items -> Comprobante::fromArray falla.
        $this->expectException(ValidationException::class);
        $sdk->emitirFactura(self::EXT_FACT, array_diff_key($this->dataFactura(), ['items' => 0]));
        $this->assertTrue($this->filaVacia(self::EXT_FACT), 'no se persistio la fila');

        // Sin receptor_documento_nro -> validacion falla.
        $this->expectException(ValidationException::class);
        $bad = $this->dataFactura();
        unset($bad['receptor_documento_nro']);
        $sdk->emitirFactura(self::EXT_FACT2, $bad);
        $this->assertTrue($this->filaVacia(self::EXT_FACT2));
    }

    // =================================================================
    // 19: reservarNumero persiste cbte_nro_enviado
    // =================================================================

    public function test_emitirFactura_persiste_cbte_nro_enviado_y_request_json(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(199);
        $this->responseBuilder->enqueueAprobado(200, '12345678901234', '20250715');
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        $result = $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());

        $row = $this->rawRow(self::EXT_FACT);
        $this->assertSame(200, (int) $row['cbte_nro_enviado'], 'cbte_nro_enviado = ultimo+1');
        $this->assertSame(200, (int) $row['cbte_nro_confirmado'], 'cbte_nro_confirmado = cbte_nro_enviado');
        $this->assertNotNull($row['cbte_fch_enviado']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['cbte_fch_enviado']);
        $this->assertNotEmpty($row['request_json'], 'request_json persistido');
    }

    // =================================================================
    // 20: zombie happy path - el comprobante ya esta en ARCA y matchea
    //     el snapshot -> recuperar sin volver a FECAESolicitar
    // =================================================================

    public function test_emitirFactura_zombie_recupera_de_consultar_sin_reemitir(): void
    {
        // Pre-sembrar fila zombie: cbte_nro_enviado=50, fecha 2025-06-15,
        // updated_at viejo para que el orquestador llegue al branch
        // zombie (no se corta antes por TTL fresh).
        $data = $this->dataFactura();
        $comprobante = \Rbbsoft\ArcaSdk\Wsfe\Comprobante::fromArray(
            $data, $this->config->puntoVenta, self::CBTE_TIPO
        );
        $fp = $comprobante->fingerprint();
        $rj = $comprobante->canonicalJson();

        $staleTime = $this->now->modify('-1 hour')->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO arca_emisiones_idempotencia
               (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token, intento, es_fallo_infra,
                request_fingerprint, request_json, cbte_nro_enviado, cbte_fch_enviado,
                created_at, updated_at)
             VALUES
               (:ext, :cuit, :pv, :tipo, :estado, :lease, 0, 0, :fp, :rj, :nro, :fch, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':ext' => self::EXT_FACT, ':cuit' => self::CUIT, ':pv' => self::PUNTO_VENTA,
            ':tipo' => self::CBTE_TIPO, ':estado' => 'en_curso',
            ':lease' => '00000000-0000-4000-8000-000000000003',
            ':fp' => $fp, ':rj' => $rj,
            ':nro' => 50, ':fch' => '2025-06-15',
            ':created_at' => $staleTime, ':updated_at' => $staleTime,
        ]);

        // FECompConsultar: ARCA ya tiene el comprobante con los
        // mismos datos que el snapshot. Para Factura C (no discrimina
        // IVA) total == gravado = 100, ImpIva = 0.00, sin AlicIva.
        $this->responseBuilder->enqueueConsultar(
            puntoVenta: self::PUNTO_VENTA,
            cbteTipo: self::CBTE_TIPO,
            cbteNro: 50,
            cbteFch: '20250615',
            cae: '12345678901250',
            caeFchVto: '20250715',
            concepto: 1,
            docTipo: 80,
            docNro: '20999999999',
            impTotal: '100.00',
            impNeto: '100.00',
            impIva: '0.00',
        );

        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        $result = $sdk->emitirFactura(self::EXT_FACT, $data);

        // Match: se recupera el CAE sin reemitir.
        $this->assertSame(self::EXT_FACT, $result->externalId);
        $this->assertSame(50, $result->cbteNro);
        $this->assertSame('12345678901250', $result->cae);
        $this->assertSame('20250715', $result->caeFchVto);
        $this->assertSame('100.00', $result->montoTotal);
        $this->assertArrayHasKey('origen', $result->asArray());
        $this->assertSame('zombie_consultar', $result->origen);

        // La fila queda emitido con el CAE recuperado.
        $row = $this->rawRow(self::EXT_FACT);
        $this->assertSame('emitido', $row['estado']);
        $this->assertSame('12345678901250', $row['cae']);
        $this->assertSame(50, (int) $row['cbte_nro_confirmado']);
        $this->assertNull($row['lease_token']);

        // Lock liberado una sola vez.
        $this->assertSame(1, $this->lockManager->acquireCallCount);
        $this->assertSame(1, $this->lockManager->releaseCallCount);

        // Solo se llamo a FECompConsultar (no se re-emitio). Las 2
        // llamadas SOAP que hubo son: lock-adquirir (no es SOAP) +
        // consultar. Como WsfeClient no hace llamadas a ultimoAutorizado
        // ni solicitar en este path, el callCount del SOAP debe ser 1.
        $this->assertSame(1, $this->soap->callCount);
    }

    // =================================================================
    // 20b: zombie -> ARCA dice 601 + ultimoAutorizado < cbteNroEnviado
    //      -> re-emit del snapshot
    // =================================================================

    public function test_emitirFactura_zombie_reemit_por_ultimoAutorizado_menor(): void
    {
        $data = $this->dataFactura();
        $comprobante = \Rbbsoft\ArcaSdk\Wsfe\Comprobante::fromArray(
            $data, $this->config->puntoVenta, self::CBTE_TIPO
        );
        $fp = $comprobante->fingerprint();
        $rj = $comprobante->canonicalJson();

        $staleTime = $this->now->modify('-1 hour')->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO arca_emisiones_idempotencia
               (external_id, cuit, punto_venta, cbte_tipo, estado, lease_token, intento, es_fallo_infra,
                request_fingerprint, request_json, cbte_nro_enviado, cbte_fch_enviado,
                created_at, updated_at)
             VALUES
               (:ext, :cuit, :pv, :tipo, :estado, :lease, 0, 0, :fp, :rj, :nro, :fch, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':ext' => self::EXT_FACT, ':cuit' => self::CUIT, ':pv' => self::PUNTO_VENTA,
            ':tipo' => self::CBTE_TIPO, ':estado' => 'en_curso',
            ':lease' => '00000000-0000-4000-8000-000000000003',
            ':fp' => $fp, ':rj' => $rj,
            ':nro' => 50, ':fch' => '2025-06-15',
            ':created_at' => $staleTime, ':updated_at' => $staleTime,
        ]);

        // 1) FECompConsultar -> 601 (no existe)
        $this->responseBuilder->enqueueConsultarNoExiste(
            self::PUNTO_VENTA, self::CBTE_TIPO, 50
        );
        // 2) FECompUltimoAutorizado -> 49 (< 50, numero libre)
        $this->responseBuilder->enqueueUltimoAutorizado(49);
        // 3) FECAESolicitar -> aprobado con NUEVO CAE (distinto al
        //    original: el original nunca llego a ARCA).
        $this->responseBuilder->enqueueAprobado(50, '98765432109876', '20250720');

        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        $result = $sdk->emitirFactura(self::EXT_FACT, $data);

        // Re-emit aprobado: nuevo CAE.
        $this->assertSame(50, $result->cbteNro);
        $this->assertSame('98765432109876', $result->cae);
        $this->assertSame('zombie_reemit', $result->origen);

        $row = $this->rawRow(self::EXT_FACT);
        $this->assertSame('emitido', $row['estado']);
        $this->assertSame('98765432109876', $row['cae']);

        // 3 llamadas SOAP: consultar, ultimoAutorizado, solicitar.
        $this->assertSame(3, $this->soap->callCount);
        // El body de la solicitar debe llevar CbteFch=20250615
        // (la fecha persistida, NO today). El body es XML-escaped
        // por el SoapClientDouble, asi que decodificamos las entidades.
        $lastRequest = html_entity_decode($this->soap->lastRequest(), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('<CbteFch>20250615</CbteFch>', $lastRequest);
        $this->assertStringContainsString('<CbteDesde>50</CbteDesde>', $lastRequest);
    }

    // =================================================================
    // 21: emitirNotaCredito happy path
    // =================================================================

    public function test_emitirNotaCredito_happy_path(): void
    {
        $this->responseBuilder->enqueueUltimoAutorizado(49);
        $this->responseBuilder->enqueueAprobado(50, '12345678901235', '20250715');
        $this->lockManager->setNextAcquireResult(true);

        $sdk = $this->sdk();
        // Especificar cbte_tipo=8 (NC_B) explicitamente para que la
        // asociacion de cbtes_asoc (tipo 6 = Factura B) sea valida.
        $result = $sdk->emitirNotaCredito(self::EXT_NC, $this->dataNotaCreditoB([
            'cbte_tipo' => TiposComprobante::NOTA_CREDITO_B,
        ]));

        $this->assertSame(self::EXT_NC, $result->externalId);
        $this->assertSame(TiposComprobante::NOTA_CREDITO_B, $result->cbteTipo);
        $this->assertSame(50, $result->cbteNro);
        $this->assertSame('12345678901235', $result->cae);
        $this->assertNotEmpty($result->cbtesAsoc);
        $this->assertCount(1, $result->cbtesAsoc);
    }

    // =================================================================
    // 22: emitirNotaCredito con cbtes_asoc incompatible
    // =================================================================

    public function test_emitirNotaCredito_cbtes_asoc_incompatible_ValidationException(): void
    {
        $sdk = $this->sdk();
        $this->expectException(ValidationException::class);
        // NC_B con cbtes_asoc.tipo = NC_A (incompatible).
        $sdk->emitirNotaCredito(self::EXT_NC, $this->dataNotaCreditoB([
            'cbtes_asoc' => [[
                'tipo' => TiposComprobante::NOTA_CREDITO_A,
                'punto_venta' => 1,
                'nro' => 1234,
            ]],
        ]));
    }

    // =================================================================
    // 22b: emitirNotaCredito sin cbtes_asoc
    // =================================================================

    public function test_emitirNotaCredito_sin_cbtes_asoc_ValidationException(): void
    {
        $sdk = $this->sdk();
        $this->expectException(ValidationException::class);
        $sdk->emitirNotaCredito(self::EXT_NC, $this->dataNotaCreditoB([
            'cbtes_asoc' => [],
        ]));
    }

    // =================================================================
    // emitFactura con cbte_tipo que no es Factura -> ValidationException
    // =================================================================

    public function test_emitirFactura_con_cbte_tipo_nota_credito_ValidationException(): void
    {
        $sdk = $this->sdk();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/emitirFactura/');
        // Pasar cbte_tipo explicito de NC: emitirFactura debe rechazarlo.
        $sdk->emitirFactura(self::EXT_FACT, array_merge($this->dataNotaCreditoB(), [
            'cbte_tipo' => TiposComprobante::NOTA_CREDITO_B,
        ]));
    }

    public function test_emitirNotaCredito_con_cbte_tipo_factura_ValidationException(): void
    {
        $sdk = $this->sdk();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/emitirNotaCredito/');
        // Pasar cbte_tipo explicito de Factura: emitirNotaCredito debe rechazarlo.
        $sdk->emitirNotaCredito(self::EXT_NC, array_merge($this->dataFactura(), [
            'cbte_tipo' => TiposComprobante::FACTURA_B,
        ]));
    }

    // =================================================================
    // esIdempotente
    // =================================================================

    public function test_esIdempotente_true_para_fila_existente_y_false_para_no_existente(): void
    {
        $sdk = $this->sdk();
        $this->assertFalse($sdk->esIdempotente(self::EXT_FACT));

        $this->responseBuilder->enqueueUltimoAutorizado(49);
        $this->responseBuilder->enqueueAprobado(50, '12345678901235', '20250715');
        $this->lockManager->setNextAcquireResult(true);
        $sdk->emitirFactura(self::EXT_FACT, $this->dataFactura());

        $this->assertTrue($sdk->esIdempotente(self::EXT_FACT));
        $this->assertFalse($sdk->esIdempotente(self::EXT_FACT2));
        $this->assertFalse($sdk->esIdempotente('not-a-uuid'));
    }

    // =================================================================
    // resetInstance entre tests
    // =================================================================

    public function test_resetInstance_permite_otra_instancia(): void
    {
        $a = ArcaSdk::getInstance($this->config, $this->container);
        ArcaSdk::resetInstance();
        $b = ArcaSdk::getInstance($this->config, $this->container);
        $this->assertNotSame($a, $b);
    }
}
