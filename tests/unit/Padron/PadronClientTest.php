<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Padron;

use LogicException;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\PadronException;
use Rbbsoft\ArcaSdk\Exceptions\PadronProtocolException;
use Rbbsoft\ArcaSdk\Padron\Emisor;
use Rbbsoft\ArcaSdk\Padron\PadronClient;
use Rbbsoft\ArcaSdk\Support\RetryPolicy;
use SoapFault;

/**
 * Cubre la fachada PadronClient: operacion obtener, integracion con
 * WsaaClient, politica de retry, clasificacion de errores y armado
 * del payload SOAP contra el WSDL real de personaServiceA13
 * (targetNamespace http://a13.soap.ws.server.puc.sr/).
 */
final class PadronClientTest extends TestCase
{
    private const CUIT = '20123456786';

    /**
     * CUIT receptor ficticio (con checksum valido).
     */
    private const REF_CUIT_RECEPTOR = 30912345676;

    /**
     * Config minimo valido en homo.
     *
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

    /**
     * Construye el PadronClient con un PadronSoapClientDouble y un
     * PadronWsaaClientDouble. Devuelve la terna para que el test
     * inspeccione.
     *
     * @return array{0: PadronClient, 1: PadronSoapClientDouble, 2: PadronWsaaClientDouble}
     */
    private function makeClient(Config $config, ?RetryPolicy $policy = null): array
    {
        $wsaa = new PadronWsaaClientDouble();
        $soap = new PadronSoapClientDouble();
        $client = new PadronClient($config, $wsaa->asTokenProvider(), null, $policy ?? new RetryPolicy(), $soap);
        return [$client, $soap, $wsaa];
    }

    private function envelope(string $bodyXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . $bodyXml
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }

    /**
     * Envelope de respuesta exitoso del WSDL A13: el path es
     *   <soap:Body>
     *     <getPersonaResponse>
     *       <personaReturn>
     *         <metadata>...</metadata>
     *         <persona>...</persona>
     *       </personaReturn>
     *     </getPersonaResponse>
     *   </soap:Body>
     * Los datos que el Emisor consume viven dentro de <persona>.
     */
    private function personaReturnValido(): string
    {
        $body = '<getPersonaResponse xmlns="http://a13.soap.ws.server.puc.sr/">'
            . '<personaReturn>'
            . '<metadata>'
            . '<fechaHora>2026-08-04T12:34:56-03:00</fechaHora>'
            . '<servidor>awshomo.afip.gov.ar</servidor>'
            . '</metadata>'
            . '<persona>'
            . '<idPersona>20123456786</idPersona>'
            . '<tipoPersona>JURIDICA</tipoPersona>'
            . '<estadoClave>ACTIVO</estadoClave>'
            . '<razonSocial>ACME S.A.</razonSocial>'
            . '<tipoClave>CUIT</tipoClave>'
            . '<tipoDocumento>CUIT</tipoDocumento>'
            . '<numeroDocumento>20123456786</numeroDocumento>'
            . '<formaJuridica>S.A.</formaJuridica>'
            . '<idActividadPrincipal>620100</idActividadPrincipal>'
            . '<descripcionActividadPrincipal>Servicios de consultoria</descripcionActividadPrincipal>'
            . '<periodoActividadPrincipal>202401</periodoActividadPrincipal>'
            . '<fechaContratoSocial>1998-01-15T00:00:00-03:00</fechaContratoSocial>'
            . '<mesCierre>12</mesCierre>'
            . '<domicilio>'
            . '<calle>Av Siempre Viva</calle>'
            . '<numero>742</numero>'
            . '<codigoPostal>C1414BBD</codigoPostal>'
            . '<localidad>CABA</localidad>'
            . '<idProvincia>0</idProvincia>'
            . '<descripcionProvincia>CAPITAL FEDERAL</descripcionProvincia>'
            . '</domicilio>'
            . '</persona>'
            . '</personaReturn>'
            . '</getPersonaResponse>';
        return $this->envelope($body);
    }

    // -------------------------------------------------------------------
    // obtener() happy path
    // -------------------------------------------------------------------

    public function test_obtener_devuelve_Emisor_con_campos_parseados(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->personaReturnValido());

        $e = $client->obtener(20123456786);

        $this->assertInstanceOf(Emisor::class, $e);
        $this->assertSame(20123456786, $e->cuit);
        $this->assertSame('JURIDICA', $e->tipoPersona);
        $this->assertSame('ACTIVO', $e->estadoClave);
        $this->assertSame('ACME S.A.', $e->razonSocial);
        $this->assertNull($e->apellidoNombre);
        // El WSDL A13 no expone fechaInscripcion, categoriaMonotributo
        // ni condicionIva: deben quedar en null.
        $this->assertNull($e->fechaInscripcion);
        $this->assertNull($e->categoriaMonotributo);
        $this->assertNull($e->condicionIva);
        // El WSDL A13 no expone listas de actividades ni impuestos.
        $this->assertSame([], $e->actividades);
        $this->assertSame([], $e->impuestos);

        $dom = $e->domicilioFiscal;
        $this->assertSame('Av Siempre Viva', $dom->calle);
        $this->assertSame('742', $dom->numero);
        $this->assertNull($dom->piso);
        $this->assertNull($dom->departamento);
        $this->assertSame('C1414BBD', $dom->codigoPostal);
        $this->assertSame('CABA', $dom->localidad);
        // idProvincia (int) se mapea a string para mantener compat con
        // la firma del DTO.
        $this->assertSame('0', $dom->provincia);
        $this->assertSame('CAPITAL FEDERAL', $dom->descripcionProvincia);

        $this->assertSame(1, $soap->callCount);
    }

    public function test_obtener_persona_fisica_concatena_apellido_y_nombre(): void
    {
        // Persona fisica: el padron A13 trae <apellido> y <nombre>
        // como campos directos de <persona> (NO dentro de un
        // datosGenerales). Emisor::fromArray concatena en apellidoNombre
        // con el formato "apellido, nombre".
        $body = '<getPersonaResponse xmlns="http://a13.soap.ws.server.puc.sr/">'
            . '<personaReturn>'
            . '<persona>'
            . '<idPersona>20999999999</idPersona>'
            . '<tipoPersona>FISICA</tipoPersona>'
            . '<estadoClave>ACTIVO</estadoClave>'
            . '<apellido>PEREZ</apellido>'
            . '<nombre>JUAN</nombre>'
            . '</persona>'
            . '</personaReturn>'
            . '</getPersonaResponse>';

        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $e = $client->obtener(20999999999);

        $this->assertSame('FISICA', $e->tipoPersona);
        $this->assertSame('PEREZ, JUAN', $e->apellidoNombre);
        $this->assertNull($e->razonSocial);
        $this->assertNull($e->fechaInscripcion);
        $this->assertSame([], $e->actividades);
        $this->assertSame([], $e->impuestos);
    }

    public function test_obtener_sin_domicilio_devuelve_domicilio_con_campos_null(): void
    {
        $body = '<getPersonaResponse xmlns="http://a13.soap.ws.server.puc.sr/">'
            . '<personaReturn>'
            . '<persona>'
            . '<idPersona>20123456786</idPersona>'
            . '<tipoPersona>JURIDICA</tipoPersona>'
            . '<estadoClave>ACTIVO</estadoClave>'
            . '<razonSocial>ACME S.A.</razonSocial>'
            . '</persona>'
            . '</personaReturn>'
            . '</getPersonaResponse>';

        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $e = $client->obtener(20123456786);

        $this->assertSame('ACME S.A.', $e->razonSocial);
        $this->assertNull($e->domicilioFiscal->calle);
        $this->assertNull($e->domicilioFiscal->numero);
        $this->assertNull($e->domicilioFiscal->codigoPostal);
        $this->assertNull($e->domicilioFiscal->localidad);
        $this->assertNull($e->domicilioFiscal->provincia);
        $this->assertNull($e->domicilioFiscal->descripcionProvincia);
    }

    public function test_obtener_con_multiples_domicilios_toma_el_primero(): void
    {
        // El WSDL A13 declara <domicilio> como maxOccurs="unbounded".
        // PadronClient toma el primero de la lista; los siguientes se
        // ignoran (limitation documentada del WSDL actual).
        $body = '<getPersonaResponse xmlns="http://a13.soap.ws.server.puc.sr/">'
            . '<personaReturn>'
            . '<persona>'
            . '<idPersona>20123456786</idPersona>'
            . '<tipoPersona>JURIDICA</tipoPersona>'
            . '<estadoClave>ACTIVO</estadoClave>'
            . '<razonSocial>ACME S.A.</razonSocial>'
            . '<domicilio>'
            . '<calle>Domicilio Fiscal</calle>'
            . '<numero>100</numero>'
            . '</domicilio>'
            . '<domicilio>'
            . '<calle>Domicilio Comercial</calle>'
            . '<numero>200</numero>'
            . '</domicilio>'
            . '</persona>'
            . '</personaReturn>'
            . '</getPersonaResponse>';

        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $e = $client->obtener(20123456786);

        $this->assertSame('Domicilio Fiscal', $e->domicilioFiscal->calle);
        $this->assertSame('100', $e->domicilioFiscal->numero);
    }

    public function test_obtener_sin_persona_lanza_PadronProtocolException_kind_structural(): void
    {
        // personaReturn existe pero no trae <persona>: la respuesta es
        // estructural (el WSDL A13 garantiza <persona> en una respuesta
        // exitosa; sin <persona> es malformed).
        $body = '<getPersonaResponse xmlns="http://a13.soap.ws.server.puc.sr/">'
            . '<personaReturn>'
            . '<metadata><fechaHora>2026-08-04T12:34:56-03:00</fechaHora></metadata>'
            . '</personaReturn>'
            . '</getPersonaResponse>';

        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        try {
            $client->obtener(20123456786);
            $this->fail('Debio lanzar PadronProtocolException');
        } catch (PadronProtocolException $e) {
            $this->assertSame(PadronProtocolException::KIND_STRUCTURAL, $e->kind,
                'personaReturn sin <persona> debe ser structural');
        }
    }

    // -------------------------------------------------------------------
    // SoapFault / structural
    // -------------------------------------------------------------------

    public function test_obtener_con_SoapFault_http_5xx_lanza_PadronProtocolException_kind_http_5xx(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        // SoapClient dispara SoapFault con faultcode 'HTTP' y el
        // SoapClientDouble overridea __getLastResponse() para devolver
        // el body encolado. Asi el PadronClient puede clasificar.
        $raw = "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/html\r\n\r\n<html>oops</html>";
        $soap->enqueueResponse($raw);

        try {
            $client->obtener(20123456786);
            $this->fail('Debio lanzar PadronProtocolException');
        } catch (PadronProtocolException $e) {
            $this->assertSame(PadronProtocolException::KIND_HTTP_5XX, $e->kind,
                '5xx en el body crudo debe clasificarse como http_5xx, no html_gateway');
        }
    }

    public function test_obtener_con_SoapFault_html_gateway_lanza_PadronProtocolException_kind_html_gateway(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse('<!DOCTYPE html><html><body>502 Bad Gateway</body></html>');

        try {
            $client->obtener(20123456786);
            $this->fail('Debio lanzar PadronProtocolException');
        } catch (PadronProtocolException $e) {
            $this->assertSame(PadronProtocolException::KIND_HTML_GATEWAY, $e->kind);
        }
    }

    public function test_obtener_con_body_vacio_lanza_PadronProtocolException_kind_empty_body(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse('');

        try {
            $client->obtener(20123456786);
            $this->fail('Debio lanzar PadronProtocolException');
        } catch (PadronProtocolException $e) {
            $this->assertSame(PadronProtocolException::KIND_EMPTY_BODY, $e->kind);
        }
    }

    public function test_obtener_con_envelope_body_vacio_lanza_kind_empty_body(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        $raw = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body/>'
            . '</SOAP-ENV:Envelope>';
        $soap->enqueueResponse($raw);

        try {
            $client->obtener(20123456786);
            $this->fail('Debio lanzar PadronProtocolException');
        } catch (PadronProtocolException $e) {
            $this->assertSame(PadronProtocolException::KIND_EMPTY_BODY, $e->kind,
                'SOAP-ENV:Body vacio debe ser empty_body');
        }
    }

    public function test_obtener_con_estructura_invalida_lanza_PadronProtocolException_kind_structural(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        // SOAP envelope valido pero con un root que NO es A13: debe
        // ser structural (definitivo, NO se reintenta).
        $raw = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body><Foo/></SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
        $soap->enqueueResponse($raw);

        try {
            $client->obtener(20123456786);
            $this->fail('Debio lanzar PadronProtocolException');
        } catch (PadronProtocolException $e) {
            $this->assertSame(PadronProtocolException::KIND_STRUCTURAL, $e->kind,
                'Body con root no-A13 debe ser structural');
        }
    }

    public function test_obtener_con_estructura_sin_persona_return_lanza_kind_structural(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        // Envelope valido, root A13 OK, pero sin <personaReturn>.
        $raw = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . '<getPersonaResponse xmlns="http://a13.soap.ws.server.puc.sr/"/>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
        $soap->enqueueResponse($raw);

        $this->expectException(PadronProtocolException::class);
        $client->obtener(20123456786);
    }

    public function test_obtener_con_soap_fault_se_traduce_a_PadronException(): void
    {
        // El WSDL A13 define el fault SRValidationException para
        // rechazos funcionales (ej. CUIT no encontrado, sin permisos).
        // Independientemente de la subclase especifica a la que
        // PadronClient traduce el fault (structural, empty_body o
        // PadronException generico, segun el body crudo que el
        // SoapClient exponga via __getLastResponse), el contrato
        // del cliente es: TODO SoapFault de ARCA se traduce a una
        // subclase de PadronException, NUNCA se propaga como
        // SoapFault crudo. Este test valida esa invariante.
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        $soap = new PadronSoapClientDouble();
        $wsaa = new PadronWsaaClientDouble();
        // Encolamos SOLO un fault (sin body crudo asociado): el doble
        // deja lastRawResponse en null cuando se procesa un fault de
        // la cola, lo que lleva a classifySoapFault a clasificar como
        // empty_body (sin marcadores de red, sin 5xx, sin body
        // parseable). Eso cae en PadronProtocolException kind=empty_body.
        $soap->enqueueFault(new SoapFault('SRValidationException', 'No se encontro la persona solicitada'));

        $client = new PadronClient(
            $config,
            $wsaa->asTokenProvider(),
            null,
            new RetryPolicy(),
            $soap,
        );

        try {
            $client->obtener(20123456786);
            $this->fail('Debio lanzar PadronException');
        } catch (PadronException $e) {
            // Cualquier subclase de PadronException (PadronProtocolException,
            // PadronArcaTransientException) es valida. Lo que NO es
            // valido es un SoapFault crudo propagado.
            $this->assertNotInstanceOf(SoapFault::class, $e);
            // Cuando el body crudo esta disponible (caso real con
            // __doRequest directo, ver tests A y B arriba), el Fault se
            // traduce a PadronException simple. Cuando NO esta
            // disponible (caso SoapFault nativo, este test), cae a
            // empty_body, que es una subclase de PadronProtocolException.
            // Ambos son validos: lo que NO es valido es structural, que
            // etiquetaria un Fault como problema de protocolo del SDK.
            $this->assertNotEquals(PadronProtocolException::KIND_STRUCTURAL, $e->kind ?? null,
                'Un Fault de ARCA nunca debe clasificarse como structural (kind=structural)');
        }
    }

    public function test_obtener_con_soap_fault_con_SRValidationException_se_traduce_a_PadronException_simple(): void
    {
        // ARCA responde con <soap:Fault> funcional cuando el CUIT no
        // existe en el padron A13 (caso tipico: monotributistas u
        // otros regimens que no exponen datos en A13). El wrapper
        // debe traducirlo a PadronException (NO a
        // PadronProtocolException::structural) y propagar el
        // faultstring accionable para que el operador sepa que ARCA
        // rechazo la operacion.
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        $soap = new PadronSoapClientDouble();
        $wsaa = new PadronWsaaClientDouble();

        // Body crudo exacto capturado de ARCA homo.
        $raw = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<soap:Fault>'
            . '<faultcode>soap:Server</faultcode>'
            . '<faultstring>La Clave (CUIT/CUIL) consultada es inexistente</faultstring>'
            . '<detail>'
            . '<ns1:SRValidationException xmlns:ns1="http://a13.soap.ws.server.puc.sr/"/>'
            . '</detail>'
            . '</soap:Fault>'
            . '</soap:Body>'
            . '</soap:Envelope>';
        $soap->enqueueResponse($raw);

        $client = new PadronClient(
            $config,
            $wsaa->asTokenProvider(),
            null,
            new RetryPolicy(),
            $soap,
        );

        try {
            $client->obtener(20123456786);
            $this->fail('Debio lanzar PadronException');
        } catch (PadronException $e) {
            // Traduccion correcta: PadronException generico, NO
            // PadronProtocolException::structural (eso seria etiquetar
            // un rechazo funcional de ARCA como problema de protocolo).
            $this->assertNotInstanceOf(PadronProtocolException::class, $e,
                'Fault funcional de ARCA NO debe ser PadronProtocolException::structural');
            // El mensaje debe incluir el faultstring accionable.
            $this->assertStringContainsString('La Clave (CUIT/CUIL) consultada es inexistente', $e->getMessage(),
                'El mensaje debe incluir el faultstring de ARCA para que el operador sepa que rechazo');
            $this->assertStringContainsString('soap:Server', $e->getMessage(),
                'El mensaje debe incluir el faultcode para diagnostico');
            $this->assertStringContainsString('PADRON getPersona', $e->getMessage(),
                'El mensaje debe identificar la operacion');
        }
    }

    public function test_obtener_con_soap_fault_sin_detail_se_traduce_a_PadronException_simple(): void
    {
        // ARCA a veces devuelve Fault sin <detail> (ej. cuando el
        // error es a nivel de transporte/wsaa y no llega a la
        // operacion). El wrapper debe traducirlo igual a
        // PadronException (NO a PadronProtocolException::structural).
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        $soap = new PadronSoapClientDouble();
        $wsaa = new PadronWsaaClientDouble();

        $raw = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<soap:Fault>'
            . '<faultcode>soap:Client</faultcode>'
            . '<faultstring>Token expirado</faultstring>'
            . '</soap:Fault>'
            . '</soap:Body>'
            . '</soap:Envelope>';
        $soap->enqueueResponse($raw);

        $client = new PadronClient(
            $config,
            $wsaa->asTokenProvider(),
            null,
            new RetryPolicy(),
            $soap,
        );

        try {
            $client->obtener(20123456786);
            $this->fail('Debio lanzar PadronException');
        } catch (PadronException $e) {
            $this->assertNotInstanceOf(PadronProtocolException::class, $e,
                'Fault sin detail tampoco debe ser structural');
            $this->assertStringContainsString('Token expirado', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------
    // WSN / payload
    // -------------------------------------------------------------------

    public function test_obtener_usa_tokenProvider_y_pide_wsn_ws_sr_padron_a13(): void
    {
        $config = $this->makeConfig();
        [$client, $soap, $wsaa] = $this->makeClient($config);
        $soap->enqueueResponse($this->personaReturnValido());

        $client->obtener(20123456786);

        $this->assertSame(1, $wsaa->callCount, 'se pidio un TA al WsaaClient');
        $this->assertSame(['ws_sr_padron_a13'], $wsaa->wsnRequests,
            'WSN pedido debe ser ws_sr_padron_a13, no wsfe');

        $body = $soap->lastRequest();
        // TA del doble va en el body (no en un header Auth).
        $this->assertStringContainsString('<token>TKN_PADRON_TEST</token>', $body);
        $this->assertStringContainsString('<sign>SGN_PADRON_TEST</sign>', $body);
        // cuitRepresentada = CUIT emisor (del Config); idPersona = CUIT
        // que se esta consultando.
        $this->assertStringContainsString('<cuitRepresentada>' . self::CUIT . '</cuitRepresentada>', $body);
        $this->assertStringContainsString('<idPersona>20123456786</idPersona>', $body);
        // Namespace A13 en el body.
        $this->assertStringContainsString('http://a13.soap.ws.server.puc.sr/', $body);
        $this->assertStringContainsString('<a13:getPersona ', $body);
    }

    public function test_obtener_usa_wsaaclient_real_si_se_pasa_directo(): void
    {
        // Variante del constructor que recibe un WsaaClient en lugar
        // de un Closure tokenProvider. WsaaClient es final, asi que
        // usamos un WsaaClient real con un SoapClientDouble + un
        // NullTicketCache pre-sembrado (mismo patron que el test
        // homólogo en WsfeClientTest).
        $cache = new \Rbbsoft\ArcaSdk\Wsaa\NullTicketCache(
            expiryMarginSeconds: 0,
        );
        $cache->save(new \Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso(
            cuit: self::CUIT,
            wsn: 'ws_sr_padron_a13',
            token: 'TKN_FROM_WSAA',
            sign: 'SGN_FROM_WSAA',
            expirationTimeUtc: new \DateTimeImmutable('2099-01-01T00:00:00+00:00'),
        ));
        $wsaaReal = new \Rbbsoft\ArcaSdk\Wsaa\WsaaClient(
            $this->makeConfig(),
            new PadronSoapClientDouble(),
            $cache,
        );

        $soap = new PadronSoapClientDouble();
        $soap->enqueueResponse($this->personaReturnValido());
        $client = new PadronClient(
            $this->makeConfig(),
            null,
            $wsaaReal,
            new RetryPolicy(),
            $soap,
        );

        $e = $client->obtener(20123456786);
        $this->assertSame('ACME S.A.', $e->razonSocial);

        $body = $soap->lastRequest();
        $this->assertStringContainsString('<token>TKN_FROM_WSAA</token>', $body);
        $this->assertStringContainsString('<sign>SGN_FROM_WSAA</sign>', $body);
    }

    // -------------------------------------------------------------------
    // Body shape (contrato A13)
    // -------------------------------------------------------------------

    public function test_payload_incluye_4_campos_en_orden_correcto(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->personaReturnValido());

        $client->obtener(20123456786);

        $body = $soap->lastRequest();
        // El WSDL A13 declara los 4 elementos en este orden dentro
        // del <getPersona>: token, sign, cuitRepresentada, idPersona.
        $pattern = '#<a13:getPersona [^>]*>'
            . '\s*<token>[^<]*</token>'
            . '\s*<sign>[^<]*</sign>'
            . '\s*<cuitRepresentada>[^<]*</cuitRepresentada>'
            . '\s*<idPersona>[^<]*</idPersona>'
            . '\s*</a13:getPersona>#s';
        $this->assertMatchesRegularExpression(
            $pattern,
            $body,
            'el body debe tener los 4 elementos en el orden exacto: token, sign, cuitRepresentada, idPersona',
        );
    }

    public function test_payload_namespace_es_a13_soap_ws_server_puc_sr(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->personaReturnValido());

        $client->obtener(20123456786);

        $body = $soap->lastRequest();
        $this->assertStringContainsString(
            '<a13:getPersona xmlns:a13="http://a13.soap.ws.server.puc.sr/">',
            $body,
            'el <getPersona> debe llevar el targetNamespace del WSDL A13 real'
        );
        // Y NO debe llevar el namespace viejo (defensivo contra
        // regresiones: el namespace viejo a14.srv.djweb.afip.gov.ar
        // fue el bug original).
        $this->assertStringNotContainsString(
            'a14.srv.djweb.afip.gov.ar',
            $body,
            'el <getPersona> NO debe llevar el namespace viejo a14.srv.djweb.afip.gov.ar'
        );
    }

    public function test_payload_sin_header_auth(): void
    {
        // El WSDL A13 NO define un header Auth: los 4 campos van en
        // el body directo. PadronClient no debe emitir un <SOAP-ENV:Header>
        // con un sub-elemento Auth/Token/Sign/Cuit (eso era el bug
        // original contra el WSDL A5 viejo).
        //
        // Verificamos el envelope que PadronClient construye
        // directamente via reflection sobre buildObtenerBody(), que es
        // el unico punto donde la forma del envelope queda bajo
        // control del PadronClient. El SoapClient PHP en non-WSDL
        // mode luego envuelve ese body en un nuevo envelope (sin
        // <SOAP-ENV:Header/>, ya que el A13 no lo requiere), pero eso
        // es decision del SoapClient, no del PadronClient.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->personaReturnValido());

        $client->obtener(20123456786);

        $r = new \ReflectionClass(PadronClient::class);
        $m = $r->getMethod('buildObtenerBody');
        $m->setAccessible(true);
        $envelope = (string) $m->invoke($client, 20123456786);

        // El envelope lleva <SOAP-ENV:Header/> vacio (no con un
        // sub-elemento Auth).
        $this->assertMatchesRegularExpression(
            '#<SOAP-ENV:Header\s*/>#s',
            $envelope,
            'el envelope construido por PadronClient debe tener <SOAP-ENV:Header/> vacio'
        );

        // Y NO debe haber un bloque <Auth> ni elementos Auth/Token/Cuit
        // con mayuscula. Esos eran los marcadores del bug original.
        $this->assertStringNotContainsString('<Auth>', $envelope,
            'no debe haber un bloque <Auth> (el WSDL A13 no lo define)');
        $this->assertStringNotContainsString('<Token>', $envelope,
            'no debe haber un <Token> con mayuscula (forma vieja del bloque Auth)');
        $this->assertStringNotContainsString('<Cuit>', $envelope,
            'no debe haber un <Cuit> con mayuscula (forma vieja del bloque Auth)');

        // Y los 4 campos viven DENTRO del <getPersona>, no en el
        // Header. El <getPersona> debe contener los 4 elementos en
        // este orden.
        $this->assertMatchesRegularExpression(
            '#<a13:getPersona [^>]*>\s*<token>[^<]*</token>\s*<sign>[^<]*</sign>'
            . '\s*<cuitRepresentada>[^<]*</cuitRepresentada>\s*<idPersona>[^<]*</idPersona>\s*</a13:getPersona>#s',
            $envelope,
            'los 4 campos del WSDL A13 deben vivir dentro de <getPersona>, no en un header Auth'
        );
    }

    public function test_payload_cuitRepresentada_es_CUIT_emayor_idPersona_es_CUIT_consultado(): void
    {
        // cuitRepresentada = CUIT emisor (Config::cuit).
        // idPersona       = CUIT que se esta consultando (param de obtener()).
        $config = $this->makeConfig(['cuit' => '20123456786']);
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->personaReturnValido());

        $client->obtener(self::REF_CUIT_RECEPTOR);

        $body = $soap->lastRequest();
        $this->assertStringContainsString('<cuitRepresentada>20123456786</cuitRepresentada>', $body,
            'cuitRepresentada = CUIT emisor del Config');
        $this->assertStringContainsString('<idPersona>' . self::REF_CUIT_RECEPTOR . '</idPersona>', $body,
            'idPersona = CUIT consultado via obtener()');
    }

    // -------------------------------------------------------------------
    // Constructor guards
    // -------------------------------------------------------------------

    public function test_obtener_sin_SoapClient_lanza_LogicException(): void
    {
        $wsaa = new PadronWsaaClientDouble();
        $client = new PadronClient(
            $this->makeConfig(),
            $wsaa->asTokenProvider(),
            null,
            new RetryPolicy(),
            null, // <- sin SoapClient
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SoapClient inyectado');
        $client->obtener(20123456786);
    }

    public function test_constructor_sin_token_provider_ni_wsaaclient_lanza_InvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PadronClient(
            $this->makeConfig(),
            null,
            null,
            new RetryPolicy(),
            new PadronSoapClientDouble(),
        );
    }

    // -------------------------------------------------------------------
    // Retry integration
    // -------------------------------------------------------------------

    public function test_retry_transitorio_soapfault_luego_aprobado(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        // 1ra: SoapFault transitorio (red).
        $soap->enqueueFault(new SoapFault('HTTP', 'Could not connect to host'));
        // 2da: respuesta valida.
        $soap->enqueueResponse($this->personaReturnValido());

        $e = $client->obtener(20123456786);
        $this->assertSame('ACME S.A.', $e->razonSocial);
        $this->assertSame(2, $soap->callCount, 'segunda llamada tras retry');
    }

    public function test_no_retry_en_structural(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $raw = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body><Foo/></SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
        $soap->enqueueResponse($raw);

        $this->expectException(PadronProtocolException::class);
        $client->obtener(20123456786);
        $this->assertSame(1, $soap->callCount, 'structural no retry');
    }
}
