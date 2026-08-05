<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\ArcaSdk;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\ArcaSdk;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\PadronException;
use Rbbsoft\ArcaSdk\Exceptions\PadronProtocolException;
use Rbbsoft\ArcaSdk\Sdk\Container;
use Rbbsoft\ArcaSdk\Padron\Emisor;
use Rbbsoft\ArcaSdk\Padron\PadronClient;
use Rbbsoft\ArcaSdk\Support\RetryPolicy;
use Rbbsoft\ArcaSdk\Tests\Unit\Padron\PadronSoapClientDouble;
use Rbbsoft\ArcaSdk\Tests\Unit\Padron\PadronWsaaClientDouble;
use Rbbsoft\ArcaSdk\Time\Clock;

/**
 * Tests del wrapper ArcaSdk::obtenerEmisor() (Phase 2 - integracion
 * del padron A13).
 *
 * Convenciones:
 *  - DB MySQL real (arca_facturador_test), tabla arca_emisiones_idempotencia.
 *  - El PadronClient real se inyecta al Container via withPadronClient()
 *    armado con PadronSoapClientDouble + PadronWsaaClientDouble, igual
 *    que en tests/unit/Padron/PadronClientTest. Esto permite verificar
 *    que el wrapper delega al Container sin tocar la red.
 *  - El Singleton se resetea en setUp/tearDown para que cada test tenga
 *    instancia fresca.
 *
 * Lo que se verifica:
 *  - delegacion correcta a container->padronClient()->obtener($cuit)
 *  - propagacion de excepciones del padron (transient + protocol)
 *  - la operacion NO escribe en la tabla de emisiones
 *  - el CUIT pasado se reenvia tal cual al PadronClient
 */
final class ObtenerEmisorTest extends TestCase
{
    private const DSN  = 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4';
    private const USER = 'root';
    private const PASS = '';

    private const CUIT_PROPIO  = '20111111112';
    private const CUIT_TERCERO = '20999999999';
    private const PUNTO_VENTA  = 1;

    private ?PDO $pdo = null;
    private ?Config $config = null;
    private ?Container $container = null;
    private ?PadronClient $padronClient = null;
    private ?PadronSoapClientDouble $soap = null;

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
        $this->container = (new Container($this->config))
            ->withPdo($this->pdo)
            ->withClock(new Clock(static fn(): DateTimeImmutable => new DateTimeImmutable('2025-06-15T12:00:00+00:00')));

        // PadronClient real con dobles. El retry_max_attempts se baja
        // en cada test que lo necesita para no demorar el suite.
        $wsaa = new PadronWsaaClientDouble();
        $this->soap = new PadronSoapClientDouble();
        $this->padronClient = new PadronClient(
            $this->config,
            $wsaa->asTokenProvider(),
            null,
            new RetryPolicy(),
            $this->soap,
        );
        $this->container->withPadronClient($this->padronClient);

        ArcaSdk::resetInstance();
    }

    protected function tearDown(): void
    {
        ArcaSdk::resetInstance();
    }

    private function makeConfig(): Config
    {
        return Config::fromArray([
            'cuit'                 => self::CUIT_PROPIO,
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
        ]);
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
                request_json         LONGTEXT        NULL,
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

    private function sdk(): ArcaSdk
    {
        return ArcaSdk::getInstance($this->config, $this->container);
    }

    private function personaReturnValido(int $cuit): string
    {
        // Shape de respuesta del WSDL real de personaServiceA13: los
        // campos viven a top-level de <persona> (no dentro de un
        // <datosGenerales> como en el WSDL A5 viejo).
        $body = '<getPersonaResponse xmlns="http://a13.soap.ws.server.puc.sr/">'
            . '<personaReturn>'
            . '<persona>'
            . '<idPersona>' . $cuit . '</idPersona>'
            . '<tipoPersona>JURIDICA</tipoPersona>'
            . '<estadoClave>ACTIVO</estadoClave>'
            . '<razonSocial>ACME S.A.</razonSocial>'
            . '<domicilio>'
            . '<calle>Av Test 123</calle>'
            . '<idProvincia>0</idProvincia>'
            . '<descripcionProvincia>CAPITAL FEDERAL</descripcionProvincia>'
            . '</domicilio>'
            . '</persona>'
            . '</personaReturn>'
            . '</getPersonaResponse>';
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . $body
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }

    private function countFilas(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM arca_emisiones_idempotencia')->fetchColumn();
    }

    // =================================================================
    // delegacion basica
    // =================================================================

    public function test_obtenerEmisor_delega_a_container_padronClient(): void
    {
        $this->soap->enqueueResponse($this->personaReturnValido((int) self::CUIT_PROPIO));

        $emisor = $this->sdk()->obtenerEmisor((int) self::CUIT_PROPIO);

        $this->assertInstanceOf(Emisor::class, $emisor);
        $this->assertSame((int) self::CUIT_PROPIO, $emisor->cuit);
        $this->assertSame('ACME S.A.', $emisor->razonSocial);
        $this->assertSame(1, $this->soap->callCount,
            'obtenerEmisor debe haber llamado a PadronClient::obtener() una vez');
    }

    public function test_obtenerEmisor_puede_usarse_con_cualquier_cuit(): void
    {
        // padrón de TERCERO: el CUIT es distinto al del Config. El SDK
        // NO debe usar $config->cuit; debe reenviar el CUIT pasado.
        $this->soap->enqueueResponse($this->personaReturnValido((int) self::CUIT_TERCERO));

        $emisor = $this->sdk()->obtenerEmisor((int) self::CUIT_TERCERO);

        $this->assertSame((int) self::CUIT_TERCERO, $emisor->cuit,
            'El Emisor devuelto debe corresponder al CUIT pasado (no a $config->cuit)');
        $this->assertSame(1, $this->soap->callCount);
    }

    public function test_obtenerEmisor_reenvia_el_cuit_al_soap_request(): void
    {
        $this->soap->enqueueResponse($this->personaReturnValido((int) self::CUIT_TERCERO));

        $this->sdk()->obtenerEmisor((int) self::CUIT_TERCERO);

        $requestBody = $this->soap->lastRequest();
        // SoapClient non-WSDL mode envuelve el body en <param0> HTML-encodado.
        $decoded = html_entity_decode($requestBody, ENT_QUOTES | ENT_XML1, 'UTF-8');
        // En el WSDL A13 el CUIT consultado va en <idPersona>; el CUIT
        // emisor (del Config) va en <cuitRepresentada>.
        $this->assertStringContainsString('<idPersona>' . self::CUIT_TERCERO . '</idPersona>', $decoded,
            'El request SOAP al padron debe llevar el CUIT pasado como idPersona');
    }

    // =================================================================
    // excepciones
    // =================================================================

    public function test_obtenerEmisor_propaga_PadronException_en_caso_de_fault_funcional(): void
    {
        // El WSDL A13 no expone un sistema de errorConstancia ni un
        // codigo 9999 en su contrato. Los rechazos funcionales (CUIT
        // no encontrado, sin permisos) llegan como <soap:Fault> con
        // SRValidationException. PadronClient traduce cualquier
        // SoapFault a una subclase de PadronException (ver
        // PadronClientTest::test_obtener_con_soap_fault_se_traduce_a_PadronException
        // para el detalle de la traduccion); este test valida que el
        // wrapper ArcaSdk::obtenerEmisor() propaga esa traduccion.
        $this->soap->enqueueFault(new \SoapFault('SRValidationException', 'No se encontro la persona solicitada'));

        $this->expectException(PadronException::class);
        $this->sdk()->obtenerEmisor((int) self::CUIT_PROPIO);
    }

    public function test_obtenerEmisor_propaga_PadronProtocolException(): void
    {
        // Body vacio -> empty_body (definitivo, no se reintenta).
        $this->soap->enqueueResponse('');

        $this->expectException(PadronProtocolException::class);
        $this->expectExceptionMessageMatches('/vacia o truncada/');
        $this->sdk()->obtenerEmisor((int) self::CUIT_PROPIO);
    }

    // =================================================================
    // no toca emisiones
    // =================================================================

    public function test_obtenerEmisor_no_escribe_en_tabla_de_emisiones(): void
    {
        $this->soap->enqueueResponse($this->personaReturnValido((int) self::CUIT_PROPIO));
        $this->assertSame(0, $this->countFilas(), 'tabla de emisiones arranca vacia');

        $emisor = $this->sdk()->obtenerEmisor((int) self::CUIT_PROPIO);

        $this->assertInstanceOf(Emisor::class, $emisor);
        $this->assertSame(0, $this->countFilas(),
            'obtenerEmisor no debe insertar/actualizar filas de la tabla de emisiones');
    }

    public function test_obtenerEmisor_no_escribe_en_emisiones_incluso_en_error(): void
    {
        // Aunque el padron falle (empty_body), la tabla de emisiones
        // no debe recibir escrituras: la consulta al padron es
        // ortogonal al flujo de emision.
        $this->soap->enqueueResponse('');

        try {
            $this->sdk()->obtenerEmisor((int) self::CUIT_PROPIO);
        } catch (PadronProtocolException) {
            // esperado
        }

        $this->assertSame(0, $this->countFilas(),
            'obtenerEmisor fallido tampoco escribe en emisiones');
    }

    // =================================================================
    // singleton: integracion con Config y fingerprint
    // =================================================================

    public function test_obtenerEmisor_no_modifica_el_fingerprint_del_singleton(): void
    {
        $this->soap->enqueueResponse($this->personaReturnValido((int) self::CUIT_PROPIO));

        $sdk = $this->sdk();
        $fingerprintAntes = $sdk->config()->cuit;
        $this->assertSame(self::CUIT_PROPIO, $fingerprintAntes);

        $sdk->obtenerEmisor((int) self::CUIT_TERCERO);

        // El Config del Singleton no se ve afectado: el padron es
        // una operacion read-only, no debe mutar el tenant.
        $this->assertSame(self::CUIT_PROPIO, $sdk->config()->cuit,
            'El CUIT del Singleton (Config::cuit) no debe cambiar tras una consulta al padron de tercero');
    }
}
