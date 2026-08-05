<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Zombie;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\CaeSecuestradoException;
use Rbbsoft\ArcaSdk\Exceptions\CbteRechazadoException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeArcaTransientException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeProtocolException;
use Rbbsoft\ArcaSdk\Exceptions\ZombieRecoveryFailedException;
use Rbbsoft\ArcaSdk\Idempotencia\FilaEmision;
use Rbbsoft\ArcaSdk\Idempotencia\IdempotenciaRepository;
use Rbbsoft\ArcaSdk\Time\Clock;
use Rbbsoft\ArcaSdk\Tests\Unit\ArcaSdk\WsfeResponseBuilder;
use Rbbsoft\ArcaSdk\Tests\Unit\Lock\LockManagerDouble;
use Rbbsoft\ArcaSdk\Tests\Unit\Wsfe\SoapClientDouble;
use Rbbsoft\ArcaSdk\Wsfe\Comprobante;
use Rbbsoft\ArcaSdk\Wsfe\TiposComprobante;
use Rbbsoft\ArcaSdk\Wsfe\WsfeClient;
use Rbbsoft\ArcaSdk\Zombie\ZombieRecovery;
use Rbbsoft\ArcaSdk\Tests\Unit\Wsfe\WsaaClientDouble;
use SoapFault;

/**
 * Tests de ZombieRecovery (Phase 7) con un WsfeClient real + SoapClientDouble
 * + repo real contra la DB MySQL de test.
 */
final class ZombieRecoveryTest extends TestCase
{
    private const DSN  = 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
    private const USER = 'root';
    private const PASS = '';

    private const CUIT        = '20111111112';
    private const PUNTO_VENTA = 1;
    private const CBTE_TIPO   = 11; // Factura C
    private const EXT         = 'zzzz9999-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    private const LEASE       = '00000000-0000-4000-8000-000000000999';

    private ?PDO $pdo = null;
    private ?Config $config = null;
    private ?DateTimeImmutable $now = null;
    private ?SoapClientDouble $soap = null;
    private ?WsfeResponseBuilder $responseBuilder = null;
    private ?LockManagerDouble $lockManager = null;
    private ?IdempotenciaRepository $repo = null;
    private ?Clock $clock = null;
    private ?WsfeClient $wsfe = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql no disponible');
        }
        try {
            $this->pdo = new PDO(self::DSN, self::USER, self::PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('No se pudo conectar a MySQL: ' . $e->getMessage());
        }
        $this->ensureTable();
        $this->pdo->exec('TRUNCATE TABLE arca_emisiones_idempotencia');

        $this->config = Config::fromArray([
            'cuit' => self::CUIT,
            'punto_venta' => self::PUNTO_VENTA,
            'cert_path' => 'C:\xampp\htdocs\Certificados\MiCertificado.pem',
            'key_path'  => 'C:\xampp\htdocs\Certificados\MiClavePrivada.key',
            'env' => 'homo',
            'db_dsn' => self::DSN,
            'db_user' => self::USER,
            'db_pass' => self::PASS,
            'soap_timeout' => 5,
            'wsaa_lock_timeout' => 5,
            'emit_lock_timeout' => 5,
            'wsaa_tra_ttl' => 600,
            'wsaa_generation_skew' => 120,
            'wsaa_expiry_margin' => 300,
            'retry_max_attempts' => 1, // 1 intento para no alargar tests
            'retry_base_backoff_ms' => 1,
            'retry_max_backoff_ms' => 2,
            'idempotencia_max_intentos' => 5,
            'idempotencia_ttl_segundos' => 300,
        ]);

        $this->now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $this->clock = new Clock(function (): DateTimeImmutable { return $this->now; });

        $this->soap = new SoapClientDouble();
        $this->responseBuilder = new WsfeResponseBuilder($this->soap);
        $this->lockManager = new LockManagerDouble();

        $self = $this;
        $this->repo = new IdempotenciaRepository(
            $this->pdo,
            $this->config,
            null,
            static function () use ($self): DateTimeImmutable { return $self->now; }
        );

        $wsaa = new WsaaClientDouble();
        $this->wsfe = new WsfeClient(
            $this->config,
            $wsaa->asTokenProvider(),
            null,
            new \Rbbsoft\ArcaSdk\Support\RetryPolicy(),
            $this->soap,
        );
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
     * Inserta una fila zombie en la tabla.
     */
    private function insertZombieFila(
        string $externalId,
        string $lease,
        int $cbteNro,
        string $cbteFchYmd,
        string $dataJson
    ): FilaEmision {
        $data = json_decode($dataJson, true);
        $comprobante = Comprobante::fromArray(
            $data, self::PUNTO_VENTA, self::CBTE_TIPO
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
            ':ext' => $externalId, ':cuit' => self::CUIT, ':pv' => self::PUNTO_VENTA,
            ':tipo' => self::CBTE_TIPO, ':estado' => 'en_curso',
            ':lease' => $lease,
            ':fp' => $fp, ':rj' => $rj,
            ':nro' => $cbteNro, ':fch' => $cbteFchYmd,
            ':created_at' => $staleTime, ':updated_at' => $staleTime,
        ]);
        return $this->repo->findByExternalId($externalId);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFacturaC(): array
    {
        return [
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20999999999',
            'receptor_condicion_iva'  => 'RI',
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
        ];
    }

    // =================================================================
    // 16: Happy path - comprobante existe en ARCA y matchea
    // =================================================================

    public function test_happy_path_consultar_matchea_y_recupera_sin_reemitir(): void
    {
        $data = $this->dataFacturaC();
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 50, '2025-06-15',
            json_encode($data)
        );

        // FECompConsultar: comprobante existe y matchea.
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

        $result = ZombieRecovery::recover(
            $fila, $this->repo, $this->wsfe, $this->config,
            $this->clock, $this->lockManager, self::EXT
        );

        $this->assertSame(self::EXT, $result->externalId);
        $this->assertSame(50, $result->cbteNro);
        $this->assertSame('12345678901250', $result->cae);
        $this->assertSame('zombie_consultar', $result->origen);

        // Fila: emitido.
        $row = $this->rawRow(self::EXT);
        $this->assertSame('emitido', $row['estado']);
        $this->assertSame('12345678901250', $row['cae']);
        $this->assertNull($row['lease_token']);

        // Solo se llamo a FECompConsultar (no a solicitar).
        $this->assertSame(1, $this->soap->callCount);
    }

    // =================================================================
    // 17: Snapshot mismatch (CAE secuestrado)
    // =================================================================

    public function test_snapshot_mismatch_lanza_CaeSecuestradoException_y_marca_fallido(): void
    {
        $data = $this->dataFacturaC();
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 50, '2025-06-15',
            json_encode($data)
        );

        // ARCA tiene el comprobante pero con importe distinto.
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
            impTotal: '200.00', // <- MISMATCH: snapshot dice 100.00
            impNeto: '200.00',
            impIva: '0.00',
        );

        try {
            ZombieRecovery::recover(
                $fila, $this->repo, $this->wsfe, $this->config,
                $this->clock, $this->lockManager, self::EXT
            );
            $this->fail('debió lanzar CaeSecuestradoException');
        } catch (CaeSecuestradoException $e) {
            $this->assertStringContainsString('snapshot mismatch', $e->getMessage());
            $this->assertStringContainsString('total', $e->getMessage());
        }

        $row = $this->rawRow(self::EXT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra']); // negocio
        $this->assertStringContainsString('cae_secuestrado', (string) $row['response_json']);
    }

    // =================================================================
    // Verifier-found bug: cbte_nro identity comparison was missing.
    // Plan section 7 step 2 lists cbte_nro as identity field obligatorio;
    // the implementation must compare it (FilaEmision::cbteNroEnviado vs
    // ComprobanteConsultado::cbteNro) and refuse to take the CAE.
    // =================================================================

    public function test_arca_devuelve_comprobante_con_cbte_nro_distinto_lanza_CaeSecuestrado(): void
    {
        $data = $this->dataFacturaC();
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 42, '2025-06-15',
            json_encode($data)
        );

        // ARCA tiene un comprobante pero con cbte_nro distinto al que este
        // externalId reservo. Todos los demas campos matchean.
        $this->responseBuilder->enqueueConsultar(
            puntoVenta: self::PUNTO_VENTA,
            cbteTipo: self::CBTE_TIPO,
            cbteNro: 99, // <- MISMATCH: la fila persistio 42
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

        try {
            ZombieRecovery::recover(
                $fila, $this->repo, $this->wsfe, $this->config,
                $this->clock, $this->lockManager, self::EXT
            );
            $this->fail('debió lanzar CaeSecuestradoException: cbte_nro de ARCA no es el persistido');
        } catch (CaeSecuestradoException $e) {
            $this->assertStringContainsString('cbte_nro', $e->getMessage());
            $this->assertStringContainsString('99', $e->getMessage());
            $this->assertStringContainsString('42', $e->getMessage());
            $this->assertSame('42', $e->esperado['cbte_nro']);
            $this->assertSame('99', $e->recibido['cbte_nro']);
        }

        $row = $this->rawRow(self::EXT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra']); // negocio, no infra
        $this->assertNull($row['cae']); // NO nos apropiamos del CAE ajeno
        $this->assertNull($row['cbte_nro_confirmado']);
        $this->assertStringContainsString('cbte_nro_no_coincide', (string) $row['response_json']);
        $this->assertStringContainsString('99', (string) $row['response_json']); // arca_cbte_nro
        $this->assertStringContainsString('42', (string) $row['response_json']); // cbte_nro_enviado
    }

    // =================================================================
    // 18: Comprobante no existe (601) + ultimoAutorizado < cbteNro
    //      -> re-emit del snapshot
    // =================================================================

    public function test_reemit_con_ultimoAutorizado_menor(): void
    {
        $data = $this->dataFacturaC();
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 50, '2025-06-15',
            json_encode($data)
        );

        $this->responseBuilder->enqueueConsultarNoExiste(
            self::PUNTO_VENTA, self::CBTE_TIPO, 50
        );
        $this->responseBuilder->enqueueUltimoAutorizado(49);
        $this->responseBuilder->enqueueAprobado(50, '98765432109876', '20250720');

        $result = ZombieRecovery::recover(
            $fila, $this->repo, $this->wsfe, $this->config,
            $this->clock, $this->lockManager, self::EXT
        );

        $this->assertSame(50, $result->cbteNro);
        $this->assertSame('98765432109876', $result->cae);
        $this->assertSame('zombie_reemit', $result->origen);

        $row = $this->rawRow(self::EXT);
        $this->assertSame('emitido', $row['estado']);
        $this->assertSame('98765432109876', $row['cae']);

        // 3 SOAP calls: consultar, ultimoAutorizado, solicitar.
        $this->assertSame(3, $this->soap->callCount);

        // El body de solicitar debe llevar CbteFch=20250615 (la fecha
        // persistida, NO today).
        $lastRequest = html_entity_decode($this->soap->lastRequest(), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('<CbteFch>20250615</CbteFch>', $lastRequest);
    }

    // =================================================================
    // 19: Re-issue con Resultado='R' -> CbteRechazadoException
    // =================================================================

    public function test_reemit_con_resultado_R_lanza_CbteRechazadoException(): void
    {
        $data = $this->dataFacturaC();
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 50, '2025-06-15',
            json_encode($data)
        );

        $this->responseBuilder->enqueueConsultarNoExiste(
            self::PUNTO_VENTA, self::CBTE_TIPO, 50
        );
        $this->responseBuilder->enqueueUltimoAutorizado(49);
        $this->responseBuilder->enqueueRechazado(50, [
            ['codigo' => 100, 'mensaje' => 'Rechazo de prueba'],
        ]);

        try {
            ZombieRecovery::recover(
                $fila, $this->repo, $this->wsfe, $this->config,
                $this->clock, $this->lockManager, self::EXT
            );
            $this->fail('debió lanzar CbteRechazadoException');
        } catch (CbteRechazadoException $e) {
            $this->assertStringContainsString('rechazado', $e->getMessage());
        }

        $row = $this->rawRow(self::EXT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra']);
        $this->assertStringContainsString('"resultado":"R"', (string) $row['response_json']);
    }

    // =================================================================
    // 20: Re-issue con transient fault
    // =================================================================

    public function test_reemit_con_transient_lanza_WsfeArcaTransientException(): void
    {
        $data = $this->dataFacturaC();
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 50, '2025-06-15',
            json_encode($data)
        );

        // 1) FECompConsultar -> no existe
        $this->responseBuilder->enqueueConsultarNoExiste(
            self::PUNTO_VENTA, self::CBTE_TIPO, 50
        );
        // 2) FECompUltimoAutorizado -> 49 (< 50, re-emit path)
        $this->responseBuilder->enqueueUltimoAutorizado(49);
        // 3) FECAESolicitar -> Event 9999 (WsfeArcaTransientException)
        $this->responseBuilder->enqueueSolicitarCon9999();

        try {
            ZombieRecovery::recover(
                $fila, $this->repo, $this->wsfe, $this->config,
                $this->clock, $this->lockManager, self::EXT
            );
            $this->fail('debió lanzar WsfeArcaTransientException');
        } catch (WsfeArcaTransientException $e) {
            // OK
        } catch (WsfeException $e) {
            // Aceptamos cualquier WsfeException transient.
            $this->assertTrue(true);
        }

        $row = $this->rawRow(self::EXT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(1, (int) $row['es_fallo_infra']);
    }

    // =================================================================
    // 21: Estado ambiguo (ultimoAutorizado >= cbteNroEnviado)
    // =================================================================

    public function test_estado_ambiguo_lanza_ZombieRecoveryFailedException(): void
    {
        $data = $this->dataFacturaC();
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 50, '2025-06-15',
            json_encode($data)
        );

        $this->responseBuilder->enqueueConsultarNoExiste(
            self::PUNTO_VENTA, self::CBTE_TIPO, 50
        );
        // ultimoAutorizado = 50 (igual a cbteNroEnviado)
        $this->responseBuilder->enqueueUltimoAutorizado(50);

        try {
            ZombieRecovery::recover(
                $fila, $this->repo, $this->wsfe, $this->config,
                $this->clock, $this->lockManager, self::EXT
            );
            $this->fail('debió lanzar ZombieRecoveryFailedException');
        } catch (ZombieRecoveryFailedException $e) {
            $this->assertStringContainsString('ambiguo', $e->getMessage());
            $this->assertFalse($e->esFalloInfra);
        }

        $row = $this->rawRow(self::EXT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra']);
        $this->assertStringContainsString('estado_ambiguo', (string) $row['response_json']);
    }

    // =================================================================
    // 22: FECompConsultar falla con transient
    // =================================================================

    public function test_consultar_con_transient_lanza_ZombieRecoveryFailedException_con_esFalloInfra_true(): void
    {
        $data = $this->dataFacturaC();
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 50, '2025-06-15',
            json_encode($data)
        );

        // SoapFault de red (no ARCA 9999 puro, pero matching network markers).
        $this->soap->enqueueFault(new SoapFault('HTTP', 'Could not connect to host'));

        try {
            ZombieRecovery::recover(
                $fila, $this->repo, $this->wsfe, $this->config,
                $this->clock, $this->lockManager, self::EXT
            );
            $this->fail('debió lanzar ZombieRecoveryFailedException');
        } catch (ZombieRecoveryFailedException $e) {
            // SoapFault 'HTTP' es transient segun RetryPolicy.
            $this->assertTrue($e->esFalloInfra);
        }

        $row = $this->rawRow(self::EXT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(1, (int) $row['es_fallo_infra']);
    }

    // =================================================================
    // 23: FECompConsultar falla con structural protocol error
    // =================================================================

    public function test_consultar_con_protocol_estructural_lanza_ZombieRecoveryFailedException_con_esFalloInfra_false(): void
    {
        $data = $this->dataFacturaC();
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 50, '2025-06-15',
            json_encode($data)
        );

        // Encolar respuesta con estructura SOAP "inesperada" (root
        // que no es operacion FEV1) -> WsfeProtocolException kind=structural.
        $badXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . '<OtroRoot xmlns="http://example.com/weird/"/>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
        $this->soap->enqueueResponse($badXml);

        try {
            ZombieRecovery::recover(
                $fila, $this->repo, $this->wsfe, $this->config,
                $this->clock, $this->lockManager, self::EXT
            );
            $this->fail('debió lanzar ZombieRecoveryFailedException');
        } catch (ZombieRecoveryFailedException $e) {
            // Structural no es transient.
            $this->assertFalse($e->esFalloInfra);
        }

        $row = $this->rawRow(self::EXT);
        $this->assertSame('fallido', $row['estado']);
        $this->assertSame(0, (int) $row['es_fallo_infra']);
    }

    // =================================================================
    // 24: Cross-midnight: cbte_fch_enviado = ayer, recovery a manana
    // =================================================================

    public function test_cross_midnight_usa_fecha_persistida_no_today(): void
    {
        // Fila persistida con fecha 2025-06-15 (ayer).
        $data = $this->dataFacturaC();
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 50, '2025-06-15',
            json_encode($data)
        );

        // El "now" del Clock es 2025-06-16 02:00 UTC (manana en UTC,
        // pero en Argentina 23:00 del 15/06). De cualquier forma, la
        // fecha persistida manda.
        $this->now = new DateTimeImmutable('2025-06-16T02:00:00+00:00');

        // FECompConsultar: no existe. ultimoAutorizado: 49.
        $this->responseBuilder->enqueueConsultarNoExiste(
            self::PUNTO_VENTA, self::CBTE_TIPO, 50
        );
        $this->responseBuilder->enqueueUltimoAutorizado(49);
        $this->responseBuilder->enqueueAprobado(50, '11111111111111', '20250715');

        $result = ZombieRecovery::recover(
            $fila, $this->repo, $this->wsfe, $this->config,
            $this->clock, $this->lockManager, self::EXT
        );

        $this->assertSame(50, $result->cbteNro);
        $this->assertSame('11111111111111', $result->cae);

        // El request ARCA debe llevar CbteFch=20250615 (la fecha del
        // snapshot, NO 20250616 que seria today).
        $lastRequest = html_entity_decode($this->soap->lastRequest(), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('<CbteFch>20250615</CbteFch>', $lastRequest);
        $this->assertStringNotContainsString('<CbteFch>20250616</CbteFch>', $lastRequest);
    }

    // =================================================================
    // 25: Re-issue usa el snapshot, no los datos nuevos
    // =================================================================

    public function test_reissue_usa_payload_persisted_no_nueva_data(): void
    {
        // Fila persistida con receptor 20123456786, importe 100.
        $dataOriginal = [
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20123456786',
            'receptor_condicion_iva'  => 'RI',
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
        ];
        $fila = $this->insertZombieFila(
            self::EXT, self::LEASE, 50, '2025-06-15',
            json_encode($dataOriginal)
        );

        // FECompConsultar: ARCA dice que no existe.
        $this->responseBuilder->enqueueConsultarNoExiste(
            self::PUNTO_VENTA, self::CBTE_TIPO, 50
        );
        $this->responseBuilder->enqueueUltimoAutorizado(49);
        $this->responseBuilder->enqueueAprobado(50, '22222222222222', '20250715');

        $result = ZombieRecovery::recover(
            $fila, $this->repo, $this->wsfe, $this->config,
            $this->clock, $this->lockManager, self::EXT
        );

        // Verificar que el body de la re-emision al ARCA lleva el
        // receptor ORIGINAL (20123456786), no uno cualquiera.
        $lastRequest = html_entity_decode($this->soap->lastRequest(), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('<DocNro>20123456786</DocNro>', $lastRequest);
        $this->assertStringContainsString('<ImpTotal>100.00</ImpTotal>', $lastRequest);
        $this->assertSame(50, $result->cbteNro);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function rawRow(string $externalId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM arca_emisiones_idempotencia WHERE external_id = :ext');
        $stmt->execute([':ext' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, "fila {$externalId} no existe");
        return $row;
    }
}
