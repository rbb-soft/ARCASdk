<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Padron;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Padron\PadronSoapClientFactory;
use SoapClient;

/**
 * Tests de PadronSoapClientFactory (Phase 2 - integracion).
 *
 * Convenciones:
 *  - WSDL local (archivo temporal escrito en setUp) para evitar la red
 *    de ARCA. El factory acepta un $wsdlPath en el constructor que
 *    tiene precedencia sobre $config->padronUrl; cuando se inyecta,
 *    el test es deterministico y rapido.
 *  - Para verificar que el factory usa Config::padronUrl cuando NO se
 *    inyecta $wsdlPath, usamos Reflection para inspeccionar el config
 *    y aserciones sobre optionsForPadron(), que es el unico metodo
 *    "puro" que la factory expone (no requiere red ni I/O).
 */
final class PadronSoapClientFactoryTest extends TestCase
{
    private const CUIT = '20123456786';

    private string $wsdlPath = '';

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
            'soap_timeout'         => 17, // valor arbitrario para detectar
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

    /**
     * Escribe un WSDL minimo valido a un archivo temporal. SoapClient
     * requiere un service+port+binding para poder construir la
     * instancia sin red. El WSDL real de ARCA tiene muchos elementos;
     * para verificar la politica de cache y el timeout no se necesita
     * el contrato completo.
     */
    private function writeMinimalWsdl(): string
    {
        // El WSDL minimo debe usar el targetNamespace real del WSDL
        // de personaServiceA13 (http://a13.soap.ws.server.puc.sr/) para
        // que cualquier codepath que inspeccione el namespace matchee.
        // El resto del contrato (portType, binding, service) es
        // suficiente para que SoapClient lo cargue en WSDL mode sin
        // red.
        $wsdl = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<definitions name="testPadron"
             targetNamespace="http://a13.soap.ws.server.puc.sr/"
             xmlns:tns="http://a13.soap.ws.server.puc.sr/"
             xmlns:xsd="http://www.w3.org/2001/XMLSchema"
             xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"
             xmlns="http://schemas.xmlsoap.org/wsdl/">
    <portType name="testPort">
        <operation name="getPersona"/>
    </portType>
    <binding name="testBinding" type="tns:testPort">
        <soap:binding style="document" transport="http://schemas.xmlsoap.org/soap/http"/>
        <operation name="getPersona">
            <soap:operation soapAction="getPersona"/>
        </operation>
    </binding>
    <service name="testService">
        <port name="testPort" binding="tns:testBinding">
            <soap:address location="http://test/padron"/>
        </port>
    </service>
</definitions>
XML;
        $path = tempnam(sys_get_temp_dir(), 'padron-wsdl-') . '.xml';
        file_put_contents($path, $wsdl);
        return $path;
    }

    protected function setUp(): void
    {
        $this->wsdlPath = $this->writeMinimalWsdl();
    }

    protected function tearDown(): void
    {
        if ($this->wsdlPath !== '' && is_file($this->wsdlPath)) {
            unlink($this->wsdlPath);
        }
    }

    public function test_create_construye_soapclient_apuntando_a_url_padron(): void
    {
        $config = $this->makeConfig(['env' => 'homo']);

        // Pasamos un wsdlPath para que no se intente cargar la URL remota
        // de ARCA. La verificacion de que el factory usa Config::padronUrl
        // cuando NO se inyecta wsdlPath la hacemos via Reflection sobre
        // el config.
        $factory = new PadronSoapClientFactory($config, $this->wsdlPath);
        $soap = $factory->create();

        $this->assertInstanceOf(SoapClient::class, $soap);

        // El Config resuelve padronUrl segun env (no segun wsdlPath).
        $this->assertSame(Config::URL_PADRON_HOMO, $config->padronUrl,
            'Config::padronUrl debe resolver a URL_PADRON_HOMO en env=homo');
    }

    public function test_create_usa_padronUrl_del_config_cuando_no_hay_wsdlPath(): void
    {
        // Sin wsdlPath, el factory debe formar el WSDL a partir de
        // config->padronUrl. Verificamos via Reflection que el codepath
        // de `create()` apuntaria a esa URL.
        $config = $this->makeConfig(['env' => 'prod']);
        $factory = new PadronSoapClientFactory($config);

        $r = new \ReflectionClass(PadronSoapClientFactory::class);
        $wsdlPathProp = $r->getProperty('wsdlPath');
        $wsdlPathProp->setAccessible(true);
        $configProp = $r->getProperty('config');
        $configProp->setAccessible(true);

        $this->assertNull($wsdlPathProp->getValue($factory),
            'wsdlPath no inyectado, debe ser null');
        $this->assertSame(Config::URL_PADRON_PROD, $configProp->getValue($factory)->padronUrl,
            'config->padronUrl debe ser URL_PADRON_PROD en env=prod');
    }

    public function test_create_aplica_cache_wsdl_none_en_homo(): void
    {
        $config = $this->makeConfig(['env' => 'homo']);
        $factory = new PadronSoapClientFactory($config, $this->wsdlPath);

        $opts = $factory->optionsForPadron();

        $this->assertArrayHasKey('cache_wsdl', $opts);
        $this->assertSame(WSDL_CACHE_NONE, $opts['cache_wsdl'],
            'En homo el cache_wsdl debe ser WSDL_CACHE_NONE');
    }

    public function test_create_aplica_cache_wsdl_disk_en_prod(): void
    {
        $config = $this->makeConfig(['env' => 'prod']);
        $factory = new PadronSoapClientFactory($config, $this->wsdlPath);

        $opts = $factory->optionsForPadron();

        $this->assertArrayHasKey('cache_wsdl', $opts);
        $this->assertSame(WSDL_CACHE_DISK, $opts['cache_wsdl'],
            'En prod el cache_wsdl debe ser WSDL_CACHE_DISK');
    }

    public function test_create_aplica_connection_timeout_del_config(): void
    {
        // soap_timeout=17 (arbitrario, distinto del default 30) para
        // asegurar que la factory propaga el valor del config.
        $config = $this->makeConfig(['env' => 'homo', 'soap_timeout' => 17]);
        $factory = new PadronSoapClientFactory($config, $this->wsdlPath);

        $opts = $factory->optionsForPadron();

        $this->assertArrayHasKey('connection_timeout', $opts);
        $this->assertSame(17, $opts['connection_timeout'],
            'connection_timeout debe venir de Config::soapTimeout');
    }

    public function test_options_soap_version_es_1_1(): void
    {
        // El padron A13 expone SOAP 1.1. Verificamos que la factory
        // fija soap_version=SOAP_1_1 (contrato de ARCA).
        $config = $this->makeConfig();
        $factory = new PadronSoapClientFactory($config, $this->wsdlPath);

        $opts = $factory->optionsForPadron();

        $this->assertArrayHasKey('soap_version', $opts);
        $this->assertSame(SOAP_1_1, $opts['soap_version']);
    }

    /**
     * Test-friendly helper: invoca el metodo privado resolveWsdl()
     * sin red. SoapClient no expone la URL WSDL tras la construccion,
     * asi que la logica de "duplicar ?WSDL" se verifica aqui.
     */
    private function callResolveWsdl(PadronSoapClientFactory $factory): string
    {
        $r = new \ReflectionClass(PadronSoapClientFactory::class);
        $m = $r->getMethod('resolveWsdl');
        $m->setAccessible(true);
        return (string) $m->invoke($factory);
    }

    public function test_create_no_duplica_WSDL_cuando_url_ya_lo_trae(): void
    {
        // Las constantes Config::URL_PADRON_HOMO y Config::URL_PADRON_PROD
        // ya terminan en `?WSDL` (es el contrato del WSDL remoto de ARCA).
        // La factory debe usar la URL tal cual y NO concatenar otro
        // `?WSDL` que produzca `?WSDL?WSDL` y rompa la carga del WSDL.
        $config = $this->makeConfig(['env' => 'homo']);
        $factory = new PadronSoapClientFactory($config);

        $this->assertSame(Config::URL_PADRON_HOMO, $config->padronUrl,
            'Config::padronUrl en env=homo debe terminar en ?WSDL');
        $resolved = $this->callResolveWsdl($factory);

        $this->assertSame(Config::URL_PADRON_HOMO, $resolved,
            'resolveWsdl() no debe duplicar ?WSDL cuando la URL ya lo trae');
        $this->assertSame(
            1,
            substr_count($resolved, '?WSDL'),
            'La URL resuelta debe contener ?WSDL exactamente una vez',
        );

        // Tambien verificar en prod (la otra constante con ?WSDL).
        $configProd = $this->makeConfig(['env' => 'prod']);
        $factoryProd = new PadronSoapClientFactory($configProd);
        $resolvedProd = $this->callResolveWsdl($factoryProd);
        $this->assertSame(Config::URL_PADRON_PROD, $resolvedProd,
            'resolveWsdl() en env=prod no debe duplicar ?WSDL');
    }

    public function test_create_agrega_WSDL_cuando_url_no_lo_trae(): void
    {
        // Si la URL base NO trae `?WSDL` (caso del WSAA / WSFE en
        // versiones donde el operador pueda sobreescribir), la
        // factory debe agregarlo. Usamos Config::URL_WSAA_HOMO que
        // es un buen ejemplo de URL HTTPS sin query string de WSDL.
        $config = $this->makeConfig(['env' => 'homo']);
        $factory = new PadronSoapClientFactory(
            $config,
            Config::URL_WSAA_HOMO,
        );

        $this->assertStringNotContainsString('?WSDL', Config::URL_WSAA_HOMO,
            'precondicion: la URL base no debe traer ?WSDL');
        $resolved = $this->callResolveWsdl($factory);

        $this->assertSame(Config::URL_WSAA_HOMO . '?WSDL', $resolved,
            'resolveWsdl() debe agregar ?WSDL cuando la URL no lo trae');
        $this->assertSame(
            1,
            substr_count($resolved, '?WSDL'),
            'La URL resuelta debe contener ?WSDL exactamente una vez',
        );
    }

    public function test_create_respeta_wsdlPath_inyectado(): void
    {
        // Cuando se inyecta $wsdlPath en el constructor, la factory
        // debe usar ese path tal cual, sin tocar padronUrl y sin
        // agregar `?WSDL` (porque $wsdlPath es, por contrato, un
        // path local o URL final ya lista para SoapClient).
        $config = $this->makeConfig(['env' => 'prod']);
        // Path local: el de setUp() no trae ?WSDL y la factory no
        // debe agregarlo (porque es wsdlPath, no URL).
        $factory = new PadronSoapClientFactory($config, $this->wsdlPath);
        $resolved = $this->callResolveWsdl($factory);

        $this->assertSame($this->wsdlPath, $resolved,
            'resolveWsdl() debe respetar el wsdlPath inyectado, sin '
            . 'concatenar ?WSDL ni consultar padronUrl');
        $this->assertStringNotContainsString('?WSDL', $resolved,
            'El path local inyectado no debe recibir ?WSDL');

        // Tambien: aunque el wsdlPath tuviera ?WSDL, se respeta tal
        // cual (defensivo: la duplicacion se evita en cualquier caso).
        $factoryWithWsdl = new PadronSoapClientFactory(
            $config,
            'https://example.com/personaServiceA13?WSDL',
        );
        $resolvedWithWsdl = $this->callResolveWsdl($factoryWithWsdl);
        $this->assertSame(
            'https://example.com/personaServiceA13?WSDL',
            $resolvedWithWsdl,
            'wsdlPath con ?WSDL se respeta tal cual',
        );
    }
}
