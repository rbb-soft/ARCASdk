<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Facturador;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Sdk\Container;
use Rbbsoft\ArcaSdk\Padron\PadronClient;
use Rbbsoft\ArcaSdk\Support\RetryPolicy;
use SoapClient;

/**
 * Tests del Container relacionados con PadronClient (Phase 2 -
 * integracion del padron A13).
 *
 * Convenciones:
 *  - Sin DB ni red: la Container se construye con un Config minimo
 *    valido (paths de cert/key inexistentes no se validan porque no
 *    se construye el WsaaClient en estos tests). Para forzar
 *    situaciones donde SI se necesita el WsaaClient (caso real del
 *    padronClient()), inyectamos un SoapClient custom y/o un
 *    WsaaClient mockeado via withWsaaClient().
 *  - Se usa un Closure trivial de soapFactory cuando se quiere
 *    capturar la URL del SoapClient creado.
 */
final class PadronClientContainerTest extends TestCase
{
    private const CUIT = '20123456786';

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeConfig(array $overrides = []): Config
    {
        $base = [
            'cuit'                 => self::CUIT,
            'punto_venta'          => 1,
            'cert_path'            => 'C:\xampp\htdocs\Certificados\MiCertificado.pem',
            'key_path'             => 'C:\xampp\htdocs\Certificados\MiClavePrivada.key',
            'env'                  => 'homo',
            'db_dsn'               => 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4',
            'db_user'              => 'root',
            'db_pass'              => '',
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

    public function test_padronClient_lazy_init(): void
    {
        $config = $this->makeConfig();
        $container = new Container($config);
        $this->wireContainer($container, $config);

        // Sin soapFactory inyectado, el padronClient no se construye
        // hasta que se llame. Verificamos via reflection que el campo
        // $padronClient esta en null antes del primer llamado.
        $r = new \ReflectionClass($container);
        $prop = $r->getProperty('padronClient');
        $prop->setAccessible(true);

        $this->assertNull($prop->getValue($container),
            'padronClient debe estar null antes del primer acceso');

        $client = $container->padronClient();

        $this->assertInstanceOf(PadronClient::class, $client);
        $this->assertNotNull($prop->getValue($container),
            'padronClient debe estar seteado despues del primer acceso');
    }

    public function test_padronClient_reusa_instancia(): void
    {
        $config = $this->makeConfig();
        $container = new Container($config);
        $this->wireContainer($container, $config);

        $a = $container->padronClient();
        $b = $container->padronClient();

        $this->assertSame($a, $b, 'Dos llamadas a padronClient() deben devolver la misma instancia');
    }

    public function test_withPadronClient_inyecta_instancia_custom(): void
    {
        $config = $this->makeConfig();
        $container = new Container($config);

        $custom = new PadronClient(
            $config,
            null,
            $this->buildWsaaClient($config),
            new RetryPolicy(),
            new SoapClientDoubleForContainer('http://custom', true),
        );

        $container->withPadronClient($custom);

        $this->assertSame($custom, $container->padronClient(),
            'withPadronClient debe inyectar la instancia sin construir una nueva');
    }

    public function test_withSoapFactory_invalida_padronClient(): void
    {
        $config = $this->makeConfig();
        $container = new Container($config);
        $this->wireContainer($container, $config);

        $primera = $container->padronClient();
        $this->assertInstanceOf(PadronClient::class, $primera);

        // Cambiamos la soapFactory: debe invalidar el padronClient.
        $container->withSoapFactory(static fn(string $url, bool $wsdl): SoapClient => new SoapClientDoubleForContainer('http://nueva', $wsdl));

        $segunda = $container->padronClient();

        $this->assertNotSame($primera, $segunda,
            'withSoapFactory debe forzar reconstruccion del PadronClient');

        $r = new \ReflectionClass($container);
        $prop = $r->getProperty('padronClient');
        $prop->setAccessible(true);
        $this->assertSame($segunda, $prop->getValue($container));
    }

    public function test_withRetryPolicy_invalida_padronClient(): void
    {
        $config = $this->makeConfig();
        $container = new Container($config);
        $this->wireContainer($container, $config);

        $primera = $container->padronClient();
        $this->assertInstanceOf(PadronClient::class, $primera);

        // Cambiamos la RetryPolicy: el PadronClient captura el policy
        // en su constructor, asi que debe reconstruirse.
        $container->withRetryPolicy(new RetryPolicy(0.5, 2000));

        $segunda = $container->padronClient();

        $this->assertNotSame($primera, $segunda,
            'withRetryPolicy debe forzar reconstruccion del PadronClient');
    }

    public function test_padronClient_usa_la_url_de_patron_del_config(): void
    {
        $config = $this->makeConfig();
        $container = new Container($config);

        $captured = ['url' => null, 'wsdl' => null];
        $container->withSoapFactory(static function (string $url, bool $wsdl) use (&$captured): SoapClient {
            $captured['url'] = $url;
            $captured['wsdl'] = $wsdl;
            return new SoapClientDoubleForContainer($url, $wsdl);
        });
        // Importante: withSoapFactory invalida wsaaClient, asi que
        // inyectamos el ticket cache y el wsaaClient DESPUES para
        // que la inicializacion lazy no toque PDO.
        $container->withTicketCache(new \Rbbsoft\ArcaSdk\Wsaa\NullTicketCache());
        $container->withWsaaClient($this->buildWsaaClient($config));

        $container->padronClient();

        $this->assertSame(Config::URL_PADRON_HOMO, $captured['url'],
            'El SoapClient del padron debe construirse apuntando a Config::padronUrl (homo)');
        $this->assertTrue($captured['wsdl'],
            'El SoapClient del padron debe construirse en WSDL mode (true)');
    }

    public function test_padronClient_usa_la_url_prod_si_env_es_prod(): void
    {
        $config = $this->makeConfig(['env' => 'prod']);
        $container = new Container($config);

        $captured = ['url' => null];
        $container->withSoapFactory(static function (string $url, bool $wsdl) use (&$captured): SoapClient {
            $captured['url'] = $url;
            return new SoapClientDoubleForContainer($url, $wsdl);
        });
        $container->withTicketCache(new \Rbbsoft\ArcaSdk\Wsaa\NullTicketCache());
        $container->withWsaaClient($this->buildWsaaClient($config));

        $container->padronClient();

        $this->assertSame(Config::URL_PADRON_PROD, $captured['url'],
            'En env=prod el SoapClient del padron debe construirse apuntando a URL_PADRON_PROD');
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * Conecta el container con dependencias minimas para los tests
     * del padron: ticket cache nulo (sin DB), wsaaClient stub, y una
     * soapFactory que devuelve un SoapClient en non-WSDL mode.
     *
     * El orden importa: withSoapFactory invalida wsaaClient, asi que
     * el wsaaClient se inyecta al final.
     */
    private function wireContainer(Container $container, Config $config): void
    {
        $container->withTicketCache(new \Rbbsoft\ArcaSdk\Wsaa\NullTicketCache());
        $container->withSoapFactory(static fn(string $url, bool $wsdl): SoapClient => new SoapClientDoubleForContainer($url, $wsdl));
        $container->withWsaaClient($this->buildWsaaClient($config));
    }

    private function wsaaStubSoap(): \SoapClient
    {
        return new \SoapClient(null, [
            'location' => 'http://test/wsaa',
            'uri'      => 'http://wsaa',
        ]);
    }

    private function buildWsaaClient(Config $config): \Rbbsoft\ArcaSdk\Wsaa\WsaaClient
    {
        return new \Rbbsoft\ArcaSdk\Wsaa\WsaaClient(
            $config,
            $this->wsaaStubSoap(),
            new \Rbbsoft\ArcaSdk\Wsaa\NullTicketCache(),
        );
    }
}

/**
 * SoapClient minimalista para los tests de Container: no necesita
 * WSDL real porque el container solo lo inyecta al PadronClient;
 * los tests del PadronClient usan su propio PadronSoapClientDouble.
 *
 * Se construye en non-WSDL mode para no depender de un WSDL remoto.
 */
final class SoapClientDoubleForContainer extends \SoapClient
{
    public function __construct(public string $constructedUrl, public bool $wsdlMode)
    {
        parent::__construct(null, [
            'location'     => $constructedUrl,
            'uri'          => 'http://test',
            'soap_version' => SOAP_1_1,
        ]);
    }
}
